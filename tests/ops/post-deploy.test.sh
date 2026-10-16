#!/usr/bin/env bash
#
# Offline tests for bin/post-deploy-check.sh and bin/smoke-webhook.sh: every external command (curl, openssl, php's
# console, sudo, ufw, sshd, nginx, mariadb) is a stub on PATH, all files live in a scratch dir. No network, no server.
#
#   bash tests/ops/post-deploy.test.sh
#
# The full matrix needs root (the scripts only read the root-owned env file as root): run it in the Linux/WSL container.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
PASS=0; FAIL=0; SKIP=0
pass() { PASS=$((PASS + 1)); echo "PASS: $*"; }
fail() { FAIL=$((FAIL + 1)); echo "FAIL: $*"; }
skip() { SKIP=$((SKIP + 1)); echo "SKIP: $*"; }
check() { local name="$1"; shift; if "$@"; then pass "$name"; else fail "$name"; fi; }
not() { ! "$@"; }
have() { command -v "$1" > /dev/null 2>&1; }

echo "== syntax"
for f in bin/post-deploy-check.sh bin/smoke-webhook.sh; do
  [[ -e "$ROOT/$f" ]] && check "bash -n $f" bash -n "$ROOT/$f"
done

if [[ "$EUID" -ne 0 ]] || ! have php; then
  skip "post-deploy tests (need root and php: run in the Linux/WSL container)"
  echo; echo "passed: $PASS  failed: $FAIL  skipped: $SKIP"; (( FAIL == 0 )); exit
fi

REAL_PHP="$(command -v php)"
DOMAIN=bot.example.test
SECRET="whsecret$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"
TOKEN_HDR="hdrtoken$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"
BOT_TOKEN="123456789:AAbotTOKEN$(od -An -N12 -tx1 /dev/urandom | tr -d ' \n')"
SECRETS=("$SECRET" "$TOKEN_HDR" "$BOT_TOKEN")

ETC="$WORK/etc"; install -d -m 700 "$ETC"
cat > "$ETC/.env.prod.local" <<EOF
DOMAIN=$DOMAIN
TELEGRAM_WEBHOOK_SECRET=$SECRET
TELEGRAM_WEBHOOK_SECRET_TOKEN='$TOKEN_HDR'
TELEGRAM_BOT_TOKEN="$BOT_TOKEN"
TELEGRAM_ADMIN_CHAT_ID=-1001234567
EOF
chmod 600 "$ETC/.env.prod.local"
printf 'DATABASE_URL=mysql://app:dbpass@127.0.0.1:3306/amoobot\n' > "$ETC/database.env"; chmod 600 "$ETC/database.env"
printf 'AMOOBOT_MONITOR_PING_URL=https://hc-ping.com/x/{job}\n' > "$ETC/monitor.env"; chmod 600 "$ETC/monitor.env"

# Stubs. Each records its argv in $WORK/argv (to prove no secret is ever on a command line).
SB="$WORK/stubs"; mkdir -p "$SB"
stub() { printf '#!/usr/bin/env bash\necho "%s $*" >> "%s/argv"\n%s\n' "$1" "$WORK" "$2" > "$SB/$1"; chmod +x "$SB/$1"; }
stub curl '
cfg=""; url=""; i=0; args=("$@")
for a in "$@"; do [[ "$a" == -K ]] && cfg="$(cat)"; [[ "$a" == http*://* ]] && url="$a"; done
[[ -n "$cfg" ]] && url="$(sed -n "s/^url = \"\(.*\)\"$/\1/p" <<< "$cfg")" && printf "%s\n" "$cfg" >> "'"$WORK"'/curl.cfg"
case "$url" in
  https://*/healthz) printf "%s" "${HEALTH_BODY}" ;;
  http://*/healthz)  printf "%s" "${REDIRECT_OUT}" ;;
  */telegram/webhook/wrong*) printf "%s" "${WRONG_CODE}" ;;
  */telegram/webhook/*)
    if grep -q "X-Telegram-Bot-Api-Secret-Token" <<< "$cfg"; then printf "%s" "${SMOKE_CODE:-200}"; else printf "%s" "${REAL_CODE}"; fi ;;
esac'
stub openssl '
case "$1" in
  s_client) echo "-----BEGIN CERTIFICATE-----" ;;
  x509) cat > /dev/null; echo "notAfter=$(date -u -d "+${CERT_DAYS} days" "+%b %e %H:%M:%S %Y GMT")" ;;
esac'
stub php '
if [[ "$1" == -r ]]; then exec "'"$REAL_PHP"'" "$@"; fi
case "$*" in
  *"bin/console about"*) printf "%s\n" "${ABOUT_OUT}" ;;
  *"app:telegram:webhook-info"*) printf "%s\n" "${WEBHOOK_INFO}" ;;
  *) exec "'"$REAL_PHP"'" "$@" ;;
esac'
stub sudo 'while (( $# )); do case "$1" in -H|-n) shift ;; -u) shift 2 ;; --) shift; break ;; *) break ;; esac; done; exec "$@"'
stub ufw 'printf "%s\n" "${UFW_OUT}"'
stub sshd 'printf "%s\n" "${SSHD_OUT}"'
stub nginx 'exit "${NGINX_RC:-0}"'
stub mariadb 'case "$*" in *sql_mode*) printf "%s\n" "${SQL_MODES}" ;; *bot_message_log*) printf "%s\n" "${LOGGED_ROWS:-1}" ;; esac'
printf '#!/usr/bin/env bash\necho "OK: all 7 jobs ran successfully within their allowed age."\nexit "${JOBS_RC:-0}"\n' > "$WORK/check-jobs"; chmod +x "$WORK/check-jobs"

STRICT="STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"
webhook_info() {  # webhook_info <url> <last_error_message> <pending>
  "$REAL_PHP" -r 'echo json_encode(["ok" => true, "result" => ["url" => $argv[1], "last_error_message" => $argv[2], "last_error_date" => 1760000000, "pending_update_count" => (int) $argv[3]]], JSON_PRETTY_PRINT);' "$1" "$2" "$3"
}
good_env() {
  export HEALTH_BODY='{"status":"ok"}' REDIRECT_OUT="301 https://${DOMAIN}/healthz" WRONG_CODE=403 REAL_CODE=403 CERT_DAYS=60
  export ABOUT_OUT=$' -------------------- \n  Environment          prod\n  Debug                false\n'
  WEBHOOK_INFO="$(webhook_info "https://${DOMAIN}/telegram/webhook/${SECRET}" "" 0)"; export WEBHOOK_INFO
  export UFW_OUT=$'Status: active\n\nTo                         Action      From\n--                         ------      ----\n22/tcp                     ALLOW       Anywhere\n80/tcp                     ALLOW       Anywhere\n443/tcp                    ALLOW       Anywhere\n22/tcp (v6)                ALLOW       Anywhere (v6)\n80/tcp (v6)                ALLOW       Anywhere (v6)\n443/tcp (v6)               ALLOW       Anywhere (v6)'
  export SSHD_OUT=$'port 22\npubkeyauthentication yes\npasswordauthentication no\nkbdinteractiveauthentication no\npermitrootlogin without-password'
  export SQL_MODES="${STRICT}"$'\t'"${STRICT}" NGINX_RC=0 JOBS_RC=0
  rm -rf "$WORK/backups" "$WORK/nginxlog" "$WORK/marker"; mkdir -p "$WORK/backups" "$WORK/nginxlog"
  : > "$WORK/backups/amoobot_nightly_20261014T033000Z.sql.gz"; chmod 600 "$WORK/backups/amoobot_nightly_20261014T033000Z.sql.gz"
  printf '127.0.0.1 - - [14/Oct/2026:10:00:00 +0000] "GET /healthz HTTP/1.1" 200 15\n' > "$WORK/nginxlog/amoobot_access.log"
  : > "$WORK/nginxlog/amoobot_error.log"
  printf '# Managed by amoobot deploy.sh: maintenance OFF.\n' > "$WORK/maint.conf"
  chmod 600 "$ETC/.env.prod.local" "$ETC/database.env"; chmod 700 "$ETC"
}
mkdir -p "$WORK/lib/lib"; cp "$ROOT/bin/post-deploy-check.sh" "$WORK/lib/"; cp "$ROOT/bin/lib/database-url.sh" "$WORK/lib/lib/"
[[ -e "$ROOT/bin/smoke-webhook.sh" ]] && cp "$ROOT/bin/smoke-webhook.sh" "$WORK/lib/"
# run_check  ->  OUT, RC (the script copy sits in a root lib like /usr/local/lib/amoobot)
run_check() {
  : > "$WORK/argv"; : > "$WORK/curl.cfg"
  OUT="$(PATH="$SB:$PATH" APP_DIR="$WORK/app" AMOOBOT_ETC_DIR="$ETC" BACKUP_DIR="$WORK/backups" AMOOBOT_NGINX_LOG_DIR="$WORK/nginxlog" \
    AMOOBOT_MAINTENANCE_FILE="$WORK/maint.conf" AMOOBOT_DEPLOY_MARKER="$WORK/marker" AMOOBOT_CHECK_JOBS="$WORK/check-jobs" \
    AMOOBOT_NGINX_SITE="$WORK/none" bash "$WORK/lib/post-deploy-check.sh" "$@" 2>&1)"; RC=$?
}
no_secret_anywhere() {
  local s
  for s in "${SECRETS[@]}" dbpass; do
    grep -qF -- "$s" <<< "$OUT" && { echo "  in output: ${s:0:4}..."; return 1; }
    grep -qF -- "$s" "$WORK/argv" && { echo "  on a command line: ${s:0:4}..."; return 1; }
  done
  return 0
}
line() { grep -E "^(PASS|FAIL|SKIP) +$1 " <<< "$OUT"; }
is() { line "$2" | grep -q "^$1 "; }

echo "== post-deploy-check: all good"
good_env; run_check
check "everything in order -> exit 0, RESULT: OK" bash -c '[[ $1 -eq 0 ]] && grep -q "^RESULT: OK$" <<< "$2"' _ "$RC" "$OUT"
for c in app-env healthz http-redirect certificate webhook-wrong-secret webhook-no-header telegram-webhook scheduled-jobs backup \
         firewall sshd sql-mode etc-amoobot nginx-config log-secrets maintenance-off deploy-marker; do
  check "  check '$c' PASSes" is PASS "$c"
done
check "  ...exactly 17 checks, one line each" test "$(grep -cE '^(PASS|FAIL|SKIP) ' <<< "$OUT")" -eq 17
check "no secret value in the output or on any command line" no_secret_anywhere
check "the webhook URL with the secret went to curl only through its stdin config" bash -c 'grep -qF -- "/telegram/webhook/$1" "$2"' _ "$SECRET" "$WORK/curl.cfg"
check "getWebhookInfo URL is shown masked" bash -c 'line=$(grep "telegram-webhook" <<< "$1"); grep -qF "https://bot.example.test/telegram/webhook/***" <<< "$line"' _ "$OUT"
check "the check changes nothing (no write/restart command run)" not grep -qE '^(systemctl|ufw (allow|deny|enable|delete)|nginx -s|sshd [^-])' "$WORK/argv"
check "bin/console runs as the web user, never directly as root" bash -c \
  'grep -q "^sudo -H -u www-data -- php .*bin/console about" "$1" && grep -q "^sudo -H -u www-data -- php .*app:telegram:webhook-info" "$1"' _ "$WORK/argv"

echo "== post-deploy-check: each problem is a FAIL (exit 1)"
fail_case() {  # fail_case <check> <description> <setup command>
  good_env; eval "$3"; run_check
  check "$2 -> '$1' FAILs, exit 1, no secret printed" bash -c \
    '[[ $1 -eq 1 ]] && grep -qE "^FAIL +$2 " <<< "$3" && grep -q "^RESULT: FAILED$" <<< "$3"' _ "$RC" "$1" "$OUT"
  no_secret_anywhere || fail "  ($2: a secret leaked)"
}
fail_case app-env "Debug true" 'export ABOUT_OUT=$'"'"'  Environment prod\n  Debug true'"'"
fail_case healthz "healthz error" 'export HEALTH_BODY='"'"'{"status":"error"}'"'"
fail_case http-redirect "no HTTP redirect" 'export REDIRECT_OUT="200 "'
fail_case certificate "certificate expires in 5 days" 'export CERT_DAYS=5'
fail_case webhook-no-header "webhook without header accepted" 'export REAL_CODE=200'
fail_case webhook-wrong-secret "wrong URL secret accepted" 'export WRONG_CODE=200'
fail_case webhook-wrong-secret "webhook answers 503 (maintenance)" 'export WRONG_CODE=503'
fail_case telegram-webhook "Telegram has another URL" 'WEBHOOK_INFO="$(webhook_info "https://other.example/telegram/webhook/x" "" 0)"'
fail_case telegram-webhook "last_error_message set" 'WEBHOOK_INFO="$(webhook_info "https://${DOMAIN}/telegram/webhook/${SECRET}" "Wrong response from the webhook: 403" 0)"'
fail_case telegram-webhook "too many pending updates" 'WEBHOOK_INFO="$(webhook_info "https://${DOMAIN}/telegram/webhook/${SECRET}" "" 250)"'
fail_case scheduled-jobs "a job is stale" 'export JOBS_RC=2'
fail_case backup "no nightly backup" 'rm -f "$WORK"/backups/*'
fail_case backup "nightly backup 30 h old" 'touch -d "30 hours ago" "$WORK"/backups/*.sql.gz'
fail_case backup "nightly backup mode 644" 'chmod 644 "$WORK"/backups/*.sql.gz'
fail_case firewall "ufw allows 8080 too" 'export UFW_OUT="$UFW_OUT"$'"'"'\n8080/tcp                   ALLOW       Anywhere'"'"
fail_case firewall "ufw inactive" 'export UFW_OUT="Status: inactive"'
fail_case sshd "password login on" 'export SSHD_OUT="${SSHD_OUT/passwordauthentication no/passwordauthentication yes}"'
fail_case sshd "PermitRootLogin yes" 'export SSHD_OUT="${SSHD_OUT/permitrootlogin without-password/permitrootlogin yes}"'
fail_case sql-mode "sql_mode not strict" 'export SQL_MODES="NO_ENGINE_SUBSTITUTION"$'"'"'\t'"'"'"NO_ENGINE_SUBSTITUTION"'
fail_case etc-amoobot "env file 644" 'chmod 644 "$ETC/database.env"'
fail_case etc-amoobot "/etc/amoobot 755" 'chmod 755 "$ETC"'
fail_case nginx-config "nginx -t fails" 'export NGINX_RC=1'
fail_case log-secrets "the URL secret in the access log" 'printf "%s\n" "POST /telegram/webhook/${SECRET} 200" >> "$WORK/nginxlog/amoobot_access.log"'
fail_case log-secrets "a bot-token-shaped string in the error log" 'printf "%s\n" "upstream: /bot987654321:AAzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz/getMe" >> "$WORK/nginxlog/amoobot_error.log"'
fail_case maintenance-off "maintenance 503 left on" 'printf "return 503;\n" > "$WORK/maint.conf"'
fail_case deploy-marker "deploy marker present" 'echo started > "$WORK/marker"'

echo "== post-deploy-check: not root"
good_env; chmod 755 "$WORK" "$WORK/lib" "$WORK/stubs"; chmod 644 "$WORK/lib/post-deploy-check.sh"; chmod 666 "$WORK/argv" "$WORK/curl.cfg"
if have setpriv; then
  OUT="$(cd / && setpriv --reuid=65534 --regid=65534 --clear-groups env PATH="$SB:$PATH" APP_DIR="$WORK/app" AMOOBOT_ETC_DIR="$ETC" \
    AMOOBOT_MAINTENANCE_FILE="$WORK/maint.conf" AMOOBOT_DEPLOY_MARKER="$WORK/marker" AMOOBOT_CHECK_JOBS="$WORK/check-jobs" \
    bash "$WORK/lib/post-deploy-check.sh" --domain "$DOMAIN" 2>&1)"; RC=$?
  check "as a normal user: root-only checks SKIP, exit 1, RESULT INCOMPLETE" bash -c \
    '[[ $1 -eq 1 ]] && grep -q "^RESULT: INCOMPLETE" <<< "$2" && grep -qE "^SKIP +sshd +needs root" <<< "$2" && grep -qE "^SKIP +webhook-no-header" <<< "$2"' _ "$RC" "$OUT"
  check "  ...checks that need no root still run (HTTPS, wrong secret, leftovers)" bash -c \
    'grep -qE "^PASS +healthz" <<< "$1" && grep -qE "^PASS +webhook-wrong-secret" <<< "$1" && grep -qE "^PASS +deploy-marker" <<< "$1"' _ "$OUT"
  check "  ...and nothing from the root-only env file was read" not grep -qF "$SECRET" <<< "$OUT"
else
  skip "non-root run (setpriv not installed)"
fi
chmod 700 "$WORK"

echo "== smoke-webhook: one synthetic /start through nginx -> PHP-FPM -> Symfony"
run_smoke() {  # run_smoke ARGS... -> OUT, RC
  : > "$WORK/argv"; : > "$WORK/curl.cfg"
  OUT="$(PATH="$SB:$PATH" APP_DIR="$WORK/app" AMOOBOT_ETC_DIR="$ETC" AMOOBOT_SMOKE_LOG_WAIT=1 bash "$WORK/lib/smoke-webhook.sh" "$@" 2>&1)"; RC=$?
}
good_env; chmod 700 "$WORK"; export SMOKE_CODE=200 LOGGED_ROWS=1
run_smoke 999000001
check "accepted and logged -> exit 0, RESULT OK" bash -c '[[ $1 -eq 0 ]] && grep -q "HTTP status: 200" <<< "$2" && grep -q "logged by the app (bot_message_log, incoming): yes" <<< "$2" && grep -q "^RESULT: OK" <<< "$2"' _ "$RC" "$OUT"
check "  ...one POST with the header token and a /start from the fake id" bash -c \
  '[[ $(grep -c "^url = " "$1") -eq 1 ]] && grep -qF "header = \"X-Telegram-Bot-Api-Secret-Token: $2\"" "$1" && grep -q "\\\\\"text\\\\\":\\\\\"/start\\\\\"" "$1" && grep -q "\\\\\"id\\\\\":999000001" "$1"' _ "$WORK/curl.cfg" "$TOKEN_HDR"
check "  ...to the local nginx (resolve DOMAIN:443:127.0.0.1)" grep -qx 'resolve = "bot.example.test:443:127.0.0.1"' "$WORK/curl.cfg"
check "  ...secret and token only in curl's stdin config: not in the output, not on a command line" no_secret_anywhere
check "  ...the output says the real /start from the admin's account is the final test" grep -q "Final test: send /start from the admin's own Telegram account" <<< "$OUT"
export LOGGED_ROWS=0; run_smoke 999000001
check "200 but nothing logged -> FAILED" bash -c '[[ $1 -eq 1 ]] && grep -q "logged by the app (bot_message_log, incoming): no" <<< "$2" && grep -q "^RESULT: FAILED" <<< "$2"' _ "$RC" "$OUT"
export LOGGED_ROWS=1
for c in 403 404 503 502; do
  export SMOKE_CODE=$c; run_smoke 999000001
  check "HTTP ${c} -> FAILED with a hint" bash -c '[[ $1 -eq 1 ]] && grep -q "HTTP status: $3" <<< "$2" && grep -q "^RESULT: FAILED - " <<< "$2"' _ "$RC" "$OUT" "$c"
  no_secret_anywhere || fail "  (HTTP ${c}: a secret leaked)"
done
export SMOKE_CODE=200
run_smoke 999000001 --dry-run
check "--dry-run: nothing sent, masked URL and payload shown" bash -c \
  '[[ $1 -eq 0 ]] && [[ ! -s "$3" ]] && grep -q "telegram/webhook/\*\*\*" <<< "$2" && grep -q "DRY RUN: nothing sent" <<< "$2"' _ "$RC" "$OUT" "$WORK/curl.cfg"
check "  ...and no secret printed" no_secret_anywhere
sed 's/^TELEGRAM_ADMIN_CHAT_ID=.*/TELEGRAM_ADMIN_CHAT_ID=1001234567/' "$ETC/.env.prod.local" > "$ETC/admin.env"; chmod 600 "$ETC/admin.env"
: > "$WORK/curl.cfg"
OUT="$(PATH="$SB:$PATH" AMOOBOT_ENV_FILE="$ETC/admin.env" AMOOBOT_ETC_DIR="$ETC" bash "$WORK/lib/smoke-webhook.sh" 1001234567 2>&1)"; RC=$?
check "the admin id is not accepted as the fake id (exit 2, nothing sent)" bash -c \
  '[[ $1 -eq 2 && ! -s "$3" ]] && grep -q "not TELEGRAM_ADMIN_CHAT_ID" <<< "$2"' _ "$RC" "$OUT" "$WORK/curl.cfg"
for bad in abc -5 12 '1; id' 0123456; do
  run_smoke "$bad"
  check "fake id '$bad' refused (exit 2, nothing sent)" bash -c '[[ $1 -eq 2 && ! -s "$2" ]]' _ "$RC" "$WORK/curl.cfg"
done
grep -v '^TELEGRAM_WEBHOOK_SECRET_TOKEN=' "$ETC/.env.prod.local" > "$ETC/notoken.env"; chmod 600 "$ETC/notoken.env"
: > "$WORK/curl.cfg"; PATH="$SB:$PATH" AMOOBOT_ENV_FILE="$ETC/notoken.env" AMOOBOT_ETC_DIR="$ETC" AMOOBOT_SMOKE_LOG_WAIT=1 bash "$WORK/lib/smoke-webhook.sh" 999000001 > /dev/null 2>&1
check "without TELEGRAM_WEBHOOK_SECRET_TOKEN the header carries the URL secret (as the app expects)" \
  grep -qF "header = \"X-Telegram-Bot-Api-Secret-Token: $SECRET\"" "$WORK/curl.cfg"
chmod 644 "$ETC/.env.prod.local"; run_smoke 999000001
check "env file not 600 -> refused" bash -c '[[ $1 -eq 2 ]] && grep -q "must be a regular file owned by root with mode 600" <<< "$2"' _ "$RC" "$OUT"
chmod 600 "$ETC/.env.prod.local"
if have setpriv; then
  chmod 755 "$WORK" "$WORK/lib"; chmod 644 "$WORK/lib/smoke-webhook.sh"
  OUT="$(cd / && setpriv --reuid=65534 --regid=65534 --clear-groups env PATH="$SB:$PATH" AMOOBOT_ETC_DIR="$ETC" bash "$WORK/lib/smoke-webhook.sh" 999000001 2>&1)"; RC=$?
  check "not root -> refused (the secrets are root-only)" bash -c '[[ $1 -eq 2 ]] && grep -q "Run as root" <<< "$2"' _ "$RC" "$OUT"
  chmod 700 "$WORK"
fi

echo "== deploy.sh installs the operator tools from the mirror"
check "post-deploy-check.sh and smoke-webhook.sh are optional root-ops files (installed when the commit has them)" \
  grep -q '^OPTIONAL_ROOT_OPS_FILES=(bin/post-deploy-check.sh bin/smoke-webhook.sh)$' "$ROOT/deploy.sh"
check "  ...read from the mirror (git_blob), never from the checkout" bash -c \
  'sed -n "/^install_root_ops() {/,/^}/p" "$1" | grep -q "for path in \"\${OPTIONAL_ROOT_OPS_FILES\[@\]}\"" && ! sed -n "/^install_root_ops() {/,/^}/p" "$1" | grep -q "APP_DIR"' _ "$ROOT/deploy.sh"

echo
echo "passed: $PASS  failed: $FAIL  skipped: $SKIP"
(( FAIL == 0 ))
