#!/usr/bin/env bash
#
# Read-only check of a deployed Amoobot server: one PASS / FAIL / SKIP line per check, never a secret value.
# INFO lines describe what amoobot does not manage (SSH_HARDENING=no / FIREWALL=no) and the wallet ledger check
# (app:wallet:reconcile, a WARNING when it finds mismatches); they do not change the result.
#
#   sudo /usr/local/lib/amoobot/post-deploy-check.sh [--domain bot.example.com]     # full check (root)
#   bin/post-deploy-check.sh [--domain ...]                                         # as the deploy user: root-only checks SKIP
#
# Exit code: 0 only if every check PASSED; 1 if any check FAILED or was SKIPPED (not run as root, tool missing).
# It changes nothing: no restarts, no writes outside a private temp dir, no Telegram messages (only getWebhookInfo).
# As root, app code (bin/console, .env.local.php) runs only as the web user, never as root.
#
# Settings (environment, all optional): APP_DIR, AMOOBOT_ENV_FILE, AMOOBOT_ETC_DIR, AMOOBOT_DB_URL_FILE, BACKUP_DIR,
# AMOOBOT_MIN_CERT_DAYS (14), AMOOBOT_MAX_PENDING_UPDATES (10), plus the AMOOBOT_* paths below (used by the tests).

set -uo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-/var/www/amoobot}"
WEB_USER="${AMOOBOT_WEB_USER:-www-data}"
ETC_DIR="${AMOOBOT_ETC_DIR:-/etc/amoobot}"
ENV_FILE="${AMOOBOT_ENV_FILE:-$ETC_DIR/.env.prod.local}"
DB_URL_FILE="${AMOOBOT_DB_URL_FILE:-$ETC_DIR/database.env}"
NGINX_SITE="${AMOOBOT_NGINX_SITE:-/etc/nginx/sites-available/amoobot}"
NGINX_LOG_DIR="${AMOOBOT_NGINX_LOG_DIR:-/var/log/nginx}"
MAINTENANCE_FILE="${AMOOBOT_MAINTENANCE_FILE:-/etc/nginx/snippets/amoobot-maintenance.conf}"
SSHD_DROPIN="${AMOOBOT_SSHD_DROPIN:-/etc/ssh/sshd_config.d/00-amoobot-hardening.conf}"
DEPLOY_MARKER="${AMOOBOT_DEPLOY_MARKER:-/var/lib/amoobot/deploy-in-progress}"
PHP_ETC_DIR="${AMOOBOT_PHP_ETC_DIR:-/etc/php}"
CHECK_JOBS="${AMOOBOT_CHECK_JOBS:-$SCRIPT_DIR/check-scheduled-jobs.sh}"
LOCAL_IP="${AMOOBOT_LOCAL_IP:-127.0.0.1}"
MIN_CERT_DAYS="${AMOOBOT_MIN_CERT_DAYS:-14}"
MAX_PENDING="${AMOOBOT_MAX_PENDING_UPDATES:-10}"
REQUIRED_SQL_MODE="STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"
DOMAIN=""

while (( $# )); do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
    *) printf 'Unknown option: %s\n' "$1" >&2; exit 2 ;;
  esac
  shift
done

PASSED=0 FAILED=0 SKIPPED=0
pass() { PASSED=$((PASSED + 1)); printf 'PASS  %-22s %s\n' "$1" "${2:-}"; }
fail() { FAILED=$((FAILED + 1)); printf 'FAIL  %-22s %s\n' "$1" "${2:-}"; }
skip() { SKIPPED=$((SKIPPED + 1)); printf 'SKIP  %-22s %s\n' "$1" "${2:-}"; }
info() { printf 'INFO  %-22s %s\n' "$1" "${2:-}"; }
have() { command -v "$1" > /dev/null 2>&1; }
is_root() { [[ "$EUID" -eq 0 ]]; }
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# env_value KEY  ->  KEY's value from the root-owned env file (quotes stripped). Only ever used in memory or in 0600
# temp files / stdin configs; never printed, never on a command line.
ENV_OK=0
if is_root && [[ -f "$ENV_FILE" && ! -L "$ENV_FILE" && "$(stat -c '%U %a' "$ENV_FILE" 2> /dev/null)" == "root 600" ]]; then
  ENV_OK=1
fi
env_value() {
  local line key value
  (( ENV_OK )) || return 1
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ "$line" =~ ^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || continue
    key="${BASH_REMATCH[2]}"; value="${BASH_REMATCH[3]}"
    [[ "$key" == "$1" ]] || continue
    if [[ "$value" =~ ^\"(.*)\"$ || "$value" =~ ^\'(.*)\'$ ]]; then value="${BASH_REMATCH[1]}"; fi
    printf '%s' "$value"
    return 0
  done < "$ENV_FILE"
  return 1
}

# The domain: --domain, else DOMAIN from the env file (root), else server_name from the nginx site (world-readable).
if [[ -z "$DOMAIN" ]]; then DOMAIN="$(env_value DOMAIN || true)"; fi
if [[ -z "$DOMAIN" && -r "$NGINX_SITE" ]]; then
  DOMAIN="$(awk '$1 == "server_name" { gsub(";", "", $2); print $2; exit }' "$NGINX_SITE")"
fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || DOMAIN=""

# curl_cfg  ->  curl reading its whole configuration (URL, headers) from stdin, so secrets are never on argv.
curl_cfg() { curl -K - "$@"; }
cfg_quote() { local v="${1//\\/\\\\}"; printf '"%s"' "${v//\"/\\\"}"; }

as_web() {
  if is_root; then sudo -H -u "$WEB_USER" -- "$@"; elif [[ "$(id -un)" == "$WEB_USER" ]]; then "$@"; else return 126; fi
}

echo "Amoobot post-deploy check: ${DOMAIN:-<domain unknown>} ($(is_root && echo root || echo "as $(id -un): root-only checks are skipped"))"

# 1. APP_ENV / APP_DEBUG as the app really runs (bin/console about, as the web user)
check_app_env() {
  local about
  if ! is_root && [[ "$(id -un)" != "$WEB_USER" ]]; then skip app-env "needs root (runs bin/console as ${WEB_USER})"; return; fi
  about="$(as_web php "$APP_DIR/bin/console" about --env=prod 2> /dev/null)"
  if grep -qE '^[[:space:]]*Environment[[:space:]]+prod[[:space:]]*$' <<< "$about" \
     && grep -qE '^[[:space:]]*Debug[[:space:]]+false[[:space:]]*$' <<< "$about"; then
    pass app-env "kernel environment prod, debug false"
  else
    fail app-env "bin/console about does not show Environment prod / Debug false"
  fi
}

# 2-4. HTTPS health, HTTP -> HTTPS redirect, certificate lifetime (through the local nginx, certificate verified)
check_https() {
  local body code days end
  if [[ -z "$DOMAIN" ]]; then
    skip healthz "domain unknown (pass --domain)"; skip http-redirect "domain unknown"; skip certificate "domain unknown"; return
  fi
  body="$(curl -sS --max-time 10 --resolve "${DOMAIN}:443:${LOCAL_IP}" "https://${DOMAIN}/healthz" 2> /dev/null)"
  if [[ "$body" == *'"status":"ok"'* ]]; then pass healthz "https://${DOMAIN}/healthz -> ok (certificate valid)"
  else fail healthz "https://${DOMAIN}/healthz did not answer {\"status\":\"ok\"} with a valid certificate"; fi
  code="$(curl -s -o /dev/null --max-time 10 -w '%{http_code} %{redirect_url}' --resolve "${DOMAIN}:80:${LOCAL_IP}" "http://${DOMAIN}/healthz" 2> /dev/null)"
  if [[ "$code" == "301 https://${DOMAIN}/healthz" ]]; then pass http-redirect "http -> 301 https://${DOMAIN}/healthz"
  else fail http-redirect "http://${DOMAIN}/healthz answered '${code}', expected 301 to https"; fi
  if ! have openssl; then skip certificate "openssl not installed"; return; fi
  end="$(openssl s_client -connect "${LOCAL_IP}:443" -servername "$DOMAIN" < /dev/null 2> /dev/null | openssl x509 -noout -enddate 2> /dev/null | cut -d= -f2)"
  if [[ -z "$end" ]]; then fail certificate "could not read the certificate served for ${DOMAIN}"; return; fi
  days=$(( ($(date -d "$end" +%s) - $(date +%s)) / 86400 ))
  if (( days >= MIN_CERT_DAYS )); then pass certificate "${days} days left (minimum ${MIN_CERT_DAYS})"
  else fail certificate "only ${days} days left (minimum ${MIN_CERT_DAYS}); check certbot.timer / certbot renew"; fi
}

# 4a. Admin templates compile with the prod kernel's Twig (lint:twig, as the web user, no login): templates/ (with
#     admin/dashboard.html.twig) and every EasyAdmin template the dashboard extends or includes. A Twig function from a
#     bundle not enabled in prod (EasyAdmin's html_classes() once) is a compile error here, as it is a 500 on /admin.
#     EasyAdmin's inspector/ is its web profiler panel (WebProfilerBundle is dev-only), so it is excluded.
check_admin_templates() {
  local json result
  if ! is_root && [[ "$(id -un)" != "$WEB_USER" ]]; then skip admin-templates "needs root (runs bin/console as ${WEB_USER})"; return; fi
  json="$(as_web php "$APP_DIR/bin/console" lint:twig "$APP_DIR/templates" @EasyAdminBundle --excludes=inspector --format=json --env=prod 2> /dev/null)"
  result="$(printf '%s' "$json" | php -r '
    $files = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($files) || [] === $files) { echo "FAIL bin/console lint:twig gave no usable answer"; exit; }
    $invalid = array_values(array_filter($files, static fn ($f) => !($f["valid"] ?? false)));
    $dashboard = array_filter($files, static fn ($f) => str_ends_with((string) ($f["file"] ?? ""), "templates/admin/dashboard.html.twig"));
    if ($invalid) {
      $first = str_replace($argv[1]."/", "", (string) ($invalid[0]["message"] ?? "invalid"));
      echo "FAIL ", count($invalid), " of ", count($files), " templates do not compile in prod, first: ", $first;
    } elseif (!$dashboard) {
      echo "FAIL admin/dashboard.html.twig was not among the ", count($files), " templates compiled";
    } else {
      echo "PASS ", count($files), " templates compile with the prod kernel (admin/dashboard.html.twig, @EasyAdmin)";
    }
  ' "$APP_DIR" 2> /dev/null)"
  case "$result" in
    PASS*) pass admin-templates "${result#PASS }" ;;
    FAIL*) fail admin-templates "${result#FAIL }" ;;
    *) fail admin-templates "bin/console lint:twig gave no usable answer" ;;
  esac
}

# 4b. Admin login page through the local nginx: 200 with the login form (PHP-FPM, Symfony and Twig answer, no login).
#     With ADMIN_ALLOW_IPS nginx denies every other address, the server's own included unless it is listed.
check_admin_login() {
  local code
  [[ -n "$DOMAIN" ]] || { skip admin-login "domain unknown (pass --domain)"; return; }
  code="$(curl -s -o "$TMP/admin-login" --max-time 10 -w '%{http_code}' --resolve "${DOMAIN}:443:${LOCAL_IP}" "https://${DOMAIN}/admin/login" 2> /dev/null)"
  if [[ "$code" == 200 ]] && grep -q 'name="password"' "$TMP/admin-login"; then
    pass admin-login "https://${DOMAIN}/admin/login -> 200 with the login form"
  elif [[ "$code" == 403 && -r "$NGINX_SITE" ]] \
       && awk '/location = \/admin\/login/ { on = 1 } on && /deny all;/ { found = 1 } on && /^[[:space:]]*}/ { on = 0 } END { exit !found }' "$NGINX_SITE"; then
    skip admin-login "nginx ADMIN_ALLOW_IPS denies ${LOCAL_IP} (403): add ${LOCAL_IP} to ADMIN_ALLOW_IPS to run this check"
  else
    fail admin-login "https://${DOMAIN}/admin/login answered ${code:-no response}, expected 200 with the login form"
  fi
  rm -f "$TMP/admin-login"
}

# 5. Webhook protection: the real URL secret without the header -> 403; a wrong URL secret -> 403/404
check_webhook_auth() {
  local secret code wrong
  [[ -n "$DOMAIN" ]] || { skip webhook-no-header "domain unknown"; skip webhook-wrong-secret "domain unknown"; return; }
  wrong="wrong$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
  code="$(printf 'url = %s\nrequest = "POST"\ndata = "{}"\nsilent\nmax-time = 10\noutput = "/dev/null"\nwrite-out = "%%{http_code}"\nresolve = "%s:443:%s"\n' \
    "$(cfg_quote "https://${DOMAIN}/telegram/webhook/${wrong}")" "$DOMAIN" "$LOCAL_IP" | curl_cfg 2> /dev/null)"
  if [[ "$code" == 403 || "$code" == 404 ]]; then pass webhook-wrong-secret "wrong URL secret -> ${code}"
  else fail webhook-wrong-secret "wrong URL secret answered ${code:-no response}, expected 403/404"; fi
  if ! secret="$(env_value TELEGRAM_WEBHOOK_SECRET)" || [[ -z "$secret" ]]; then
    skip webhook-no-header "needs root (URL secret from ${ENV_FILE})"; return
  fi
  code="$(printf 'url = %s\nrequest = "POST"\ndata = "{}"\nsilent\nmax-time = 10\noutput = "/dev/null"\nwrite-out = "%%{http_code}"\nresolve = "%s:443:%s"\n' \
    "$(cfg_quote "https://${DOMAIN}/telegram/webhook/${secret}")" "$DOMAIN" "$LOCAL_IP" | curl_cfg 2> /dev/null)"
  if [[ "$code" == 403 ]]; then pass webhook-no-header "real URL secret without the secret_token header -> 403"
  else fail webhook-no-header "real URL secret without the header answered ${code:-no response}, expected 403"; fi
}

# 6. getWebhookInfo (through the app, as the web user): expected URL (compared, never printed), no error, small backlog
check_webhook_info() {
  local json secret result
  if ! secret="$(env_value TELEGRAM_WEBHOOK_SECRET)" || [[ -z "$secret" || -z "$DOMAIN" ]]; then
    skip telegram-webhook "needs root (expected URL from ${ENV_FILE})"; return
  fi
  json="$(as_web php "$APP_DIR/bin/console" app:telegram:webhook-info --env=prod 2> /dev/null)"
  printf '%s' "https://${DOMAIN}/telegram/webhook/${secret}" > "$TMP/expected-url"
  result="$(printf '%s' "$json" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($d)) { echo "FAIL could not read getWebhookInfo"; exit; }
    $r = is_array($d["result"] ?? null) ? $d["result"] : $d;
    $url = (string) ($r["url"] ?? "");
    $masked = preg_replace("#/telegram/webhook/.*$#", "/telegram/webhook/***", $url);
    $problems = [];
    if (!hash_equals((string) file_get_contents($argv[1]), $url)) { $problems[] = "URL is ".($url === "" ? "empty" : $masked).", not the expected one"; }
    if ("" !== (string) ($r["last_error_message"] ?? "")) { $problems[] = "last_error_message is set (".date("c", (int) ($r["last_error_date"] ?? 0)).")"; }
    $pending = (int) ($r["pending_update_count"] ?? 0);
    if ($pending > (int) $argv[2]) { $problems[] = "pending_update_count ".$pending." > ".$argv[2]; }
    echo $problems ? "FAIL ".implode("; ", $problems) : "PASS ".$masked.", no last_error_message, pending_update_count ".$pending;
  ' "$TMP/expected-url" "$MAX_PENDING" 2> /dev/null)"
  rm -f "$TMP/expected-url"
  case "$result" in
    PASS*) pass telegram-webhook "${result#PASS }" ;;
    FAIL*) fail telegram-webhook "${result#FAIL }" ;;
    *) fail telegram-webhook "app:telegram:webhook-info gave no usable answer" ;;
  esac
}

# 6a. Wallet ledger: app:wallet:reconcile (read only, as the web user; it also checks the claim-link ownership
#     invariants). An INFO line only, never a FAIL: a mismatch needs a person (an admin adjustment, a payment decision,
#     an ownership fix), not a redeploy, and the command never changes anything. The claims timer (app:automation:run
#     --only=claims, lock group lifecycle) is checked with the other scheduled jobs (7.), as is the attach-inbounds
#     timer of multi-inbound plans (--only=attach-inbounds, lifecycle; installed by a full deploy, not --app-only).
check_wallet_reconcile() {
  local rc
  if ! is_root && [[ "$(id -un)" != "$WEB_USER" ]]; then info wallet-reconcile "not run: needs root (runs bin/console as ${WEB_USER})"; return; fi
  as_web php "$APP_DIR/bin/console" app:wallet:reconcile --env=prod > /dev/null 2>&1; rc=$?
  case "$rc" in
    0) info wallet-reconcile "every wallet balance and payment matches the ledger" ;;
    1) info wallet-reconcile "WARNING: mismatches found; run 'sudo -u ${WEB_USER} php bin/console app:wallet:reconcile --env=prod' and check each one" ;;
    *) info wallet-reconcile "WARNING: app:wallet:reconcile could not run (exit ${rc})" ;;
  esac
}

# 7. Scheduled jobs (and, as root, the nightly backup's age): bin/check-scheduled-jobs.sh
check_jobs() {
  local out rc
  [[ -r "$CHECK_JOBS" ]] || { fail scheduled-jobs "${CHECK_JOBS} not found"; return; }
  out="$(bash "$CHECK_JOBS" 2>&1)"; rc=$?
  if (( rc == 0 )); then pass scheduled-jobs "all timers active, last runs succeeded ($(tail -1 <<< "$out"))"
  else fail scheduled-jobs "check-scheduled-jobs exit ${rc}: $(grep -E '^(STALE|NEVER|FAILED|INACTIVE|DEPLOY|PAUSED|UNKNOWN)' <<< "$out" | awk '{print $1" "$2}' | tr '\n' ' ')"; fi
}

# 8. Nightly backup: present, younger than 26 h, mode 600
check_backup() {
  local dir newest age mode
  is_root || { skip backup "needs root (backup dir is root-only)"; return; }
  dir="${BACKUP_DIR:-$(env_value BACKUP_DIR || true)}"; dir="${dir:-/var/backups/amoobot}"
  newest="$(find "$dir" -maxdepth 1 -type f -name '*_nightly_*.sql.gz' -printf '%T@ %p\n' 2> /dev/null | sort -rn | head -1 | cut -d' ' -f2-)"
  [[ -n "$newest" ]] || { fail backup "no *_nightly_*.sql.gz in ${dir}"; return; }
  age=$(( $(date +%s) - $(stat -c %Y "$newest") ))
  mode="$(stat -c %a "$newest")"
  if (( age <= 93600 )) && [[ "$mode" == 600 ]]; then pass backup "$(basename "$newest"): $((age / 3600)) h old, mode 600"
  else fail backup "$(basename "$newest"): $((age / 3600)) h old (max 26), mode ${mode} (expected 600)"; fi
}

# 9. Firewall: active, only 22/80/443 allowed (FIREWALL=yes). With FIREWALL=no (default) amoobot does not manage ufw:
#    INFO with its state, and FAIL only when an active ufw blocks 80/443 (the app would not be reachable).
check_ufw() {
  local status rules extra mode missing="" p
  is_root || { skip firewall "needs root (ufw status)"; return; }
  mode="$(env_value FIREWALL || true)"
  if [[ "$mode" != yes ]]; then
    have ufw || { info firewall "not managed (FIREWALL=no); ufw not installed"; return; }
    status="$(ufw status 2> /dev/null)"
    grep -q '^Status: active' <<< "$status" || { info firewall "not managed (FIREWALL=no); ufw inactive"; return; }
    for p in 80 443; do
      awk -v p="$p" '$1 == p || $1 == p"/tcp" { found = 1 } END { exit !found }' <<< "$status" || missing+="${p} "
    done
    if [[ -n "$missing" ]]; then fail firewall "FIREWALL=no but ufw is active and does not allow ${missing}(tcp): open them (ufw allow 80/tcp; ufw allow 443/tcp)"
    else info firewall "not managed (FIREWALL=no); ufw active, allows 80 and 443"; fi
    return
  fi
  have ufw || { fail firewall "ufw not installed"; return; }
  status="$(ufw status 2> /dev/null)"
  grep -q '^Status: active' <<< "$status" || { fail firewall "ufw is not active"; return; }
  rules="$(awk '/^--/ { on = 1; next } on && NF { print $1 }' <<< "$status" | sed 's#/tcp$##' | sort -u | tr '\n' ' ')"
  extra="$(tr ' ' '\n' <<< "$rules" | grep -vxE '(22|80|443)|' | tr '\n' ' ')"
  if [[ -z "${extra// /}" && "$rules" == *"22 "* && "$rules" == *"443 "* && "$rules" == *"80 "* ]]; then pass firewall "active; allows only 22, 80, 443"
  else fail firewall "allowed: ${rules:-nothing} (expected exactly 22 80 443; extra: ${extra:-none})"; fi
}

# 10. sshd effective settings: key-only login, expected PermitRootLogin (SSH_HARDENING=yes). With SSH_HARDENING=no
#     (default) amoobot does not manage sshd: INFO with the current settings, and a note if an earlier drop-in is left.
check_sshd() {
  local cfg want got problems=() mode note=""
  is_root || { skip sshd "needs root (sshd -T)"; return; }
  have sshd || { fail sshd "sshd not installed"; return; }
  cfg="$(sshd -T 2> /dev/null)"
  [[ -n "$cfg" ]] || { fail sshd "sshd -T printed nothing"; return; }
  val() { awk -v k="$1" '$1 == k { print $2; exit }' <<< "$cfg"; }
  mode="$(env_value SSH_HARDENING || true)"
  if [[ "$mode" != yes ]]; then
    [[ -e "$SSHD_DROPIN" ]] && note="; ${SSHD_DROPIN} from an earlier deploy is still installed (SSH_HARDENING_REVERT=yes removes it)"
    info sshd "not managed (SSH_HARDENING=no): passwordauthentication $(val passwordauthentication), permitrootlogin $(val permitrootlogin)${note}"
    return
  fi
  [[ "$(val passwordauthentication)" == no ]] || problems+=("passwordauthentication $(val passwordauthentication)")
  [[ "$(val kbdinteractiveauthentication)" == no ]] || problems+=("kbdinteractiveauthentication $(val kbdinteractiveauthentication)")
  [[ "$(val pubkeyauthentication)" == yes ]] || problems+=("pubkeyauthentication $(val pubkeyauthentication)")
  want="$(env_value SSH_PERMIT_ROOT_LOGIN || true)"; want="${want:-prohibit-password}"
  got="$(val permitrootlogin)"
  # sshd -T prints prohibit-password under its old name without-password.
  [[ "$got" == "$want" || ( "$want" == prohibit-password && "$got" == without-password ) ]] || problems+=("permitrootlogin ${got}, expected ${want}")
  if (( ${#problems[@]} == 0 )); then pass sshd "key-only (password/kbd-interactive off), permitrootlogin ${got}"
  else fail sshd "$(IFS=';'; echo "${problems[*]}")"; fi
}

# 11. sql_mode on the live database (DATABASE_URL from the root-owned database.env, root-owned lib)
check_sql_mode() {
  local modes
  is_root || { skip sql-mode "needs root (${DB_URL_FILE})"; return; }
  [[ -r "$SCRIPT_DIR/lib/database-url.sh" && -r "$DB_URL_FILE" ]] || { fail sql-mode "missing ${DB_URL_FILE} or lib/database-url.sh"; return; }
  modes="$( (
    AMOOBOT_DB_URL_FILE="$DB_URL_FILE"
    export AMOOBOT_DB_URL_FILE
    # shellcheck source=bin/lib/database-url.sh
    source "$SCRIPT_DIR/lib/database-url.sh"
    unset DATABASE_URL
    load_database_url "$APP_DIR" || exit 1
    trap 'rm -f "${DB_OPTS_FILE:-}"' EXIT
    write_db_options_file
    client="$(command -v mariadb || command -v mysql)" || exit 1
    "$client" --defaults-extra-file="$DB_OPTS_FILE" -N -B -e 'SELECT @@GLOBAL.sql_mode, @@SESSION.sql_mode' "$DB_NAME"
  ) 2> /dev/null )"
  if [[ "$modes" == "${REQUIRED_SQL_MODE}"$'\t'"${REQUIRED_SQL_MODE}" ]]; then pass sql-mode "global and session: ${REQUIRED_SQL_MODE}"
  elif [[ -z "$modes" ]]; then fail sql-mode "could not query the database"
  else fail sql-mode "got '${modes//$'\t'/ / }', expected ${REQUIRED_SQL_MODE}"; fi
}

# 12. /etc/amoobot: root-only directory and files
check_etc_modes() {
  local f got problems=()
  is_root || { skip etc-amoobot "needs root (${ETC_DIR} is root-only)"; return; }
  [[ "$(stat -c '%U %a' "$ETC_DIR" 2> /dev/null)" == "root 700" ]] || problems+=("${ETC_DIR} is $(stat -c '%U %a' "$ETC_DIR" 2> /dev/null || echo missing), expected root 700")
  for f in "$ENV_FILE" "$DB_URL_FILE" "$ETC_DIR/monitor.env" "$ETC_DIR/repo_deploy_ed25519"; do
    [[ -e "$f" ]] || { [[ "$f" == "$ENV_FILE" || "$f" == "$DB_URL_FILE" ]] && problems+=("${f} missing"); continue; }
    got="$(stat -c '%U %a' "$f")"
    [[ ! -L "$f" && "$got" == "root 600" ]] || problems+=("${f} is ${got}$([[ -L "$f" ]] && echo ' (symlink)'), expected root 600")
  done
  if [[ -e "$ETC_DIR/allowed_signers" ]]; then
    got="$(stat -c '%u %a' "$ETC_DIR/allowed_signers")"
    [[ "${got%% *}" == 0 ]] && (( (8#${got##* } & 8#022) == 0 )) || problems+=("${ETC_DIR}/allowed_signers writable by others or not root-owned")
  fi
  if (( ${#problems[@]} == 0 )); then pass etc-amoobot "${ETC_DIR} 700, env/database/monitor/key files root 600"
  else fail etc-amoobot "$(IFS=';'; echo "${problems[*]}")"; fi
}

# 13a. PHP timezone: UTC for the CLI and the FPM SAPI (php-fpmX -i reads the pool's conf.d; pool files can override it)
check_php_timezone() {
  local ver cli fpm pool problems=()
  ver="${AMOOBOT_PHP_VERSION:-$(env_value PHP_VERSION || true)}"; ver="${ver:-8.4}"
  [[ "$ver" =~ ^[0-9]+\.[0-9]+$ ]] || { fail php-timezone "PHP_VERSION '${ver}' is not like 8.4"; return; }
  cli="$(php -r 'echo ini_get("date.timezone"), " ", date_default_timezone_get();' 2> /dev/null)"
  [[ "$cli" == "UTC UTC" ]] || problems+=("CLI '${cli:-unknown}'")
  if have "php-fpm${ver}"; then
    fpm="$("php-fpm${ver}" -i 2> /dev/null | awk -F' => ' '$1 == "date.timezone" { print $2; exit }')"
    [[ "$fpm" == UTC ]] || problems+=("FPM '${fpm:-unknown}'")
  else
    problems+=("php-fpm${ver} not found")
  fi
  pool="$(grep -rhoE '^[[:space:]]*php(_admin)?_value\[date\.timezone\][[:space:]]*=[[:space:]]*[^[:space:];]+' \
    "${PHP_ETC_DIR}/${ver}/fpm/pool.d" 2> /dev/null | sed 's/.*=[[:space:]]*//' | grep -vx UTC | sort -u | tr '\n' ' ')"
  [[ -z "$pool" ]] || problems+=("pool override ${pool% }")
  if (( ${#problems[@]} == 0 )); then pass php-timezone "UTC for CLI and FPM (PHP ${ver})"
  else fail php-timezone "not UTC: $(IFS=';'; echo "${problems[*]}")"; fi
}

# 13. nginx -t
check_nginx() {
  is_root || { skip nginx-config "needs root (nginx -t reads the private key)"; return; }
  if nginx -t > /dev/null 2>&1; then pass nginx-config "nginx -t ok"; else fail nginx-config "nginx -t failed (run it to see why)"; fi
}

# 14. No secret-shaped strings in the site's nginx logs of the last day (current + .1); only counts are printed.
check_log_secrets() {
  local files=() f hits=0 k v
  is_root || { skip log-secrets "needs root (nginx logs are root/adm)"; return; }
  for f in "$NGINX_LOG_DIR"/amoobot_*.log "$NGINX_LOG_DIR"/amoobot_*.log.1; do [[ -f "$f" ]] && files+=("$f"); done
  (( ${#files[@]} )) || { pass log-secrets "no amoobot nginx logs yet"; return; }
  : > "$TMP/needles"
  for k in TELEGRAM_WEBHOOK_SECRET TELEGRAM_WEBHOOK_SECRET_TOKEN TELEGRAM_BOT_TOKEN APP_SECRET DB_PASSWORD; do
    v="$(env_value "$k" || true)"; [[ ${#v} -ge 8 ]] && printf '%s\n' "$v" >> "$TMP/needles"
  done
  [[ -s "$TMP/needles" ]] && hits=$((hits + $(cat "${files[@]}" | grep -cFf "$TMP/needles")))
  # Shapes: a Telegram bot token, a webhook URL with a long secret.
  hits=$((hits + $(cat "${files[@]}" | grep -cE '[0-9]{6,}:[A-Za-z0-9_-]{30,}|/telegram/webhook/[A-Za-z0-9_-]{16,}')))
  rm -f "$TMP/needles"
  if (( hits == 0 )); then pass log-secrets "no secret-shaped strings in ${#files[@]} log file(s)"
  else fail log-secrets "${hits} log line(s) contain a secret or a secret-shaped string (values not shown) in ${NGINX_LOG_DIR}/amoobot_*.log"; fi
}

# 15-16. No maintenance 503 left on, no deploy marker
check_leftovers() {
  if [[ ! -e "$MAINTENANCE_FILE" ]]; then skip maintenance-off "${MAINTENANCE_FILE} not found"
  elif grep -q 'return 503' "$MAINTENANCE_FILE"; then fail maintenance-off "${MAINTENANCE_FILE} still returns 503 for webhook/payment"
  else pass maintenance-off "webhook/payment maintenance include is off"; fi
  if [[ -e "$DEPLOY_MARKER" ]]; then fail deploy-marker "${DEPLOY_MARKER} exists: a deploy is running or failed (jobs paused)"
  else pass deploy-marker "no deploy in progress"; fi
}

check_app_env
check_https
check_admin_templates
check_admin_login
check_webhook_auth
check_webhook_info
check_wallet_reconcile
check_jobs
check_backup
check_ufw
check_sshd
check_sql_mode
check_etc_modes
check_php_timezone
check_nginx
check_log_secrets
check_leftovers

echo
printf 'passed: %s  failed: %s  skipped: %s\n' "$PASSED" "$FAILED" "$SKIPPED"
if (( FAILED == 0 && SKIPPED == 0 )); then echo "RESULT: OK"; exit 0; fi
(( FAILED )) && echo "RESULT: FAILED" || echo "RESULT: INCOMPLETE (skipped checks; run as root for the full check)"
exit 1
