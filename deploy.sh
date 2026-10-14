#!/usr/bin/env bash
#
# Amoobot server provisioning + deploy for Ubuntu 24.04. Safe to run repeatedly (first deploy and updates).
#
#   sudo amoobot-deploy [--dry-run] [--app-only] [--ref <branch|tag|commit>] [--env-file <path>]
#   (first deploy: sudo bash /root/deploy.sh ... from a root-owned copy; never run the checkout's deploy.sh as root)
#
#   --dry-run    print what would be done; changes nothing (does not need root)
#   --app-only   skip server provisioning (steps 1-6); only update code, env, DB, cache, proxy, webhook
#   --ref        what to deploy (default: DEPLOY_REF from the env file, else "main"); also used for rollback
#   --env-file   server-only settings/secrets file (default: /etc/amoobot/.env.prod.local, root-owned, chmod 600)
#
# This file contains NO secrets. Secrets belong ONLY in the root-owned env file (/etc/amoobot/.env.prod.local); see
# .env.prod.example and docs/fa/deployment.md. Passing them as "sudo VAR=... amoobot-deploy" is not supported: they
# end up in shell history, sudo logs and the environment of every child process. Secret values are never printed.

set -Eeuo pipefail
umask 027

########################################
# CONFIG (non-secret defaults; override in the env file or environment)
########################################

DEFAULT_ENV_FILE="/etc/amoobot/.env.prod.local"
DEFAULT_REPO_URL="git@github.com:hforouzi/amoobot.git"
DEFAULT_APP_DIR="/var/www/amoobot"
DEFAULT_PHP_VERSION="8.4"
DEFAULT_DEPLOY_USER="deploy"
DEFAULT_DEPLOY_REF="main"
DEFAULT_BACKUP_DIR="/var/backups/amoobot"
DEFAULT_BACKUP_RETENTION_DAYS="14"
DEFAULT_BACKUP_CRON="30 3 * * *"

REQUIRED_SQL_MODE="STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION"
WEB_USER="www-data"
STATE_DIR="/var/lib/amoobot"

# Everything root (or systemd) executes is a root-owned copy taken from the deployed commit's git objects, never a file
# in the checkout (the deploy user can edit those). Entry points: /usr/local/sbin/amoobot-{deploy,backup,restore,check-jobs}.
ROOT_LIB_DIR="/usr/local/lib/amoobot"
ROOT_SBIN_DIR="/usr/local/sbin"
ROOT_OPS_FILES=(deploy.sh bin/backup-database.sh bin/restore-database.sh bin/scheduled-job.sh
  bin/scheduled-job-alert.sh bin/check-scheduled-jobs.sh bin/lib/database-url.sh bin/lib/monitor-ping.sh)
# GitHub's published SSH host keys (from https://api.github.com/meta over HTTPS); git uses StrictHostKeyChecking=yes.
# In /etc/ssh (root-owned, readable by the deploy user's ssh; /etc/amoobot is root-only).
GITHUB_KNOWN_HOSTS="/etc/ssh/amoobot_github_known_hosts"
GITHUB_META_URL="https://api.github.com/meta"
# Optional MONITOR_PING_URL for a dead-man's-switch monitor (root:root 600; systemd hands it to the job units).
MONITOR_ENV_FILE="/etc/amoobot/monitor.env"
# Root-owned timer that runs bin/check-scheduled-jobs.sh; a problem makes it a failed unit (and sends an alert).
CHECK_JOBS_UNIT="amoobot-check-jobs"
ROOT_WRAPPERS=(deploy backup restore check-jobs)
# DATABASE_URL for the root-run backup/restore scripts (root:root 600); they never load the app's .env.local.php.
DB_URL_FILE="/etc/amoobot/database.env"
# Included by the /telegram/webhook/ and /payment/ locations: "return 503" while a deploy changes code, cache and schema.
NGINX_SITE="/etc/nginx/sites-available/amoobot"
NGINX_MAINTENANCE_FILE="/etc/nginx/snippets/amoobot-maintenance.conf"
# ACME http-01 webroot (the port-80 server serves nothing else before the certificate exists).
ACME_WEBROOT="/var/www/letsencrypt"
# HSTS is sent only after this deploy (or an earlier one) has verified https://DOMAIN/healthz end to end.
HSTS_STATE_FILE="${STATE_DIR}/hsts-enabled"

# Scheduled jobs, installed as systemd timers in step 16 (intervals and reasons: docs/fa/automation.md).
#   name | lock group | OnCalendar | timeout (s) | interval (s) | console command
# Jobs in the same lock group never run concurrently. Only app:automation:run tasks: they honour the admin toggles.
# Never add app:payments:repair-stuck or other destructive commands here.
SCHEDULED_JOBS=(
  "broadcasts|broadcasts|*:0/2|600|120|app:automation:run --only=broadcasts"
  "orders|orders|*:0/10|300|600|app:automation:run --only=orders"
  "sync|lifecycle|*:0/15|1500|900|app:automation:run --only=sync"
  "expiry|lifecycle|*:5/15|600|900|app:automation:run --only=expiry"
  "suspend|lifecycle|*:8/15|600|900|app:automation:run --only=suspend"
  "notifications|lifecycle|*:10/30|900|1800|app:automation:run --only=notifications"
)
JOBS_LOG_DIR="/var/log/amoobot"
JOBS_STATE_DIR="${STATE_DIR}/jobs"
JOBS_LOCK_DIR="/run/amoobot"
JOBS_MANIFEST="${STATE_DIR}/scheduled-jobs.conf"

# Keys used only by this script; never written into the application's env.
DEPLOY_ONLY_KEYS=" DOMAIN LETSENCRYPT_EMAIL REPO_URL APP_DIR PHP_VERSION DEPLOY_USER DEPLOY_REF DEPLOY_SSH_PUBLIC_KEY "
DEPLOY_ONLY_KEYS+="SSH_PERMIT_ROOT_LOGIN DB_NAME DB_USER DB_PASSWORD BACKUP_DIR BACKUP_RETENTION_DAYS BACKUP_CRON "
DEPLOY_ONLY_KEYS+="ADMIN_ALLOW_IPS MONITOR_PING_URL "
# Application keys that may also come from the process environment (for non-secret overrides; secrets: env file only).
APP_KEYS="APP_SECRET APP_DEFAULT_LOCALE APP_SHARE_DIR DEFAULT_URI DATABASE_URL MESSENGER_TRANSPORT_DSN
 TELEGRAM_BOT_TOKEN TELEGRAM_WEBHOOK_SECRET TELEGRAM_WEBHOOK_SECRET_TOKEN TELEGRAM_ADMIN_CHAT_ID TELEGRAM_PROXY
 PAYMENT_CARD_NUMBER PAYMENT_CARD_HOLDER PAYMENT_DESCRIPTION
 PANEL_PROXY_ENABLED PANEL_PROXY_TYPE PANEL_PROXY_HOST PANEL_PROXY_PORT PANEL_PROXY_USERNAME PANEL_PROXY_PASSWORD PANEL_PROXY_TIMEOUT
 SERVICE_NOTIFY_EXPIRY_DAYS SERVICE_NOTIFY_TRAFFIC_THRESHOLDS
 RENEWAL_CARRY_REMAINING_TRAFFIC RENEWAL_CARRY_REMAINING_DAYS RENEWAL_EXPIRED_START_FROM_NOW
 PRICING_GLOBAL_DISCOUNT_PERCENT TRAFFIC_ADDON_ENABLED TRAFFIC_ADDON_MIN_GB TRAFFIC_ADDON_MAX_GB TRAFFIC_ADDON_PRICE_PER_GB
 SYMFONY_TRUSTED_PROXIES"

########################################
# HELPERS
########################################

DRY_RUN=0
APP_ONLY=0
CLI_REF=""
ENV_FILE="${AMOOBOT_ENV_FILE:-$DEFAULT_ENV_FILE}"
STEP="preflight"
ENV_FILE_KEYS=()

log()  { printf '%s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die()  { printf '\nERROR: %s\n' "$*" >&2; exit 1; }
step() { STEP="$1"; log ""; log "[$1] $2"; }

on_error() {
  printf '\nERROR: deploy failed in step [%s] at line %s (exit %s). Nothing after this point ran.\n' "$STEP" "$2" "$1" >&2
}
trap 'on_error $? $LINENO' ERR

JOBS_PAUSED=0
JOB_LOCK_FDS=()
MAINTENANCE_ON=0
on_exit() {
  if (( $1 != 0 && JOBS_PAUSED )); then
    paused_jobs_warning
  fi
  if (( $1 != 0 && MAINTENANCE_ON )); then
    maintenance_instructions
  fi
}
trap 'on_exit $?' EXIT

# run CMD...  executes, or prints in --dry-run. Never pass secrets as arguments (they would show up here and in ps).
run() {
  if (( DRY_RUN )); then
    printf '[dry-run] %s\n' "$*"
  else
    "$@"
  fi
}

# as USER CMD...  run a command as another user, with HOME set (git/composer need it)
as() {
  local user="$1"; shift
  run sudo -H -u "$user" -- "$@"
}

# console CMD...  Symfony console as the web user (var/ belongs to it)
console() {
  as "$WEB_USER" php "$APP_DIR/bin/console" "$@" --env=prod --no-interaction
}

# write_file PATH MODE OWNER:GROUP [--show-diff]   content on stdin; only replaces the file when the content changed.
# Sets FILE_CHANGED=1/0. In --dry-run reports changed/unchanged; with --show-diff (only for files that never hold
# secrets) it also prints the diff against the installed file, or the whole new file.
FILE_CHANGED=0
write_file() {
  local path="$1" mode="$2" owner="$3" show_diff="${4:-}" tmp state
  tmp="$(mktemp)"
  cat > "$tmp"
  if [[ -f "$path" ]] && cmp -s "$tmp" "$path" 2>/dev/null; then
    FILE_CHANGED=0; state="unchanged"
  else
    FILE_CHANGED=1; state="changed"
  fi
  if (( DRY_RUN )); then
    printf '[dry-run] write %s (%s %s) - %s\n' "$path" "$mode" "$owner" "$state"
    if [[ "$show_diff" == "--show-diff" ]] && (( FILE_CHANGED )); then
      if [[ -f "$path" ]]; then
        diff -u --label "installed: $path" --label "new: $path" "$path" "$tmp" | sed 's/^/    /' || true
      else
        sed 's/^/    + /' "$tmp"
      fi
    fi
  elif (( FILE_CHANGED )); then
    install -D -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$tmp" "$path"
  else
    chmod "$mode" "$path"
    chown "$owner" "$path"
  fi
  rm -f "$tmp"
}

# sql_quote VALUE  ->  'value' with \ and ' escaped (for statements fed to mysql on stdin, never on argv)
sql_quote() {
  local v="${1//\\/\\\\}"
  printf "'%s'" "${v//\'/\\\'}"
}

# dotenv_quote VALUE  ->  a Symfony Dotenv literal (single quotes, or escaped double quotes if needed)
dotenv_quote() {
  local v="$1"
  if [[ "$v" != *"'"* ]]; then
    printf "'%s'" "$v"
  else
    v="${v//\\/\\\\}"; v="${v//\"/\\\"}"; v="${v//\$/\\\$}"
    printf '"%s"' "$v"
  fi
}

is_deploy_only() { [[ "$DEPLOY_ONLY_KEYS" == *" $1 "* ]]; }

# parse_job "<SCHEDULED_JOBS entry>"  ->  JOB_NAME JOB_GROUP JOB_CALENDAR JOB_TIMEOUT JOB_INTERVAL JOB_COMMAND
parse_job() {
  IFS='|' read -r JOB_NAME JOB_GROUP JOB_CALENDAR JOB_TIMEOUT JOB_INTERVAL JOB_COMMAND <<< "$1"
}

job_lock_groups() {
  local entry
  for entry in "${SCHEDULED_JOBS[@]}"; do parse_job "$entry"; printf '%s\n' "$JOB_GROUP"; done | sort -u
}

# Stop all job timers and take every job lock, so no job runs against half-updated code, cache or schema.
# Locks are held until resume_scheduled_jobs (or until this script exits).
pause_scheduled_jobs() {
  local timers=() group lock fd
  if (( DRY_RUN )); then
    log "[dry-run] pause scheduled jobs: stop amoobot-job-*.timer, wait for running jobs (locks in ${JOBS_LOCK_DIR})"
    return 0
  fi
  # The freshness check is paused too: it would report the stopped job timers as INACTIVE in the middle of a deploy.
  mapfile -t timers < <(systemctl list-unit-files 'amoobot-job-*.timer' "${CHECK_JOBS_UNIT}.timer" --no-legend 2>/dev/null | awk '{print $1}')
  (( ${#timers[@]} )) || return 0
  systemctl stop "${timers[@]}"
  JOBS_PAUSED=1
  install -d -m 750 -o "$WEB_USER" -g "$WEB_USER" "$JOBS_LOCK_DIR"
  for group in $(job_lock_groups); do
    lock="$JOBS_LOCK_DIR/$group.lock"
    [[ -e "$lock" ]] || install -m 660 -o "$WEB_USER" -g "$WEB_USER" /dev/null "$lock"
    exec {fd}< "$lock"
    flock -w 900 "$fd" || die "Scheduled job group '${group}' is still running after 15 minutes; see 'systemctl list-units amoobot-job-*'."
    JOB_LOCK_FDS+=("$fd")
  done
  log "Paused ${#timers[@]} scheduled job timer(s); running jobs have finished."
}

render_job_service() {
  cat <<EOF
# Managed by amoobot deploy.sh - edit SCHEDULED_JOBS in deploy.sh, not this file.
[Unit]
Description=Amoobot scheduled job: ${JOB_NAME} (${JOB_COMMAND})
Documentation=file://${APP_DIR}/docs/fa/automation.md
After=network-online.target mariadb.service
Wants=network-online.target
OnFailure=amoobot-job-alert@%N.service

[Service]
Type=oneshot
User=${WEB_USER}
Group=${WEB_USER}
WorkingDirectory=${APP_DIR}
Environment=APP_DIR=${APP_DIR}
# Optional MONITOR_PING_URL, read by systemd (as root) from a root-only file.
EnvironmentFile=-${MONITOR_ENV_FILE}
ExecStart=/bin/bash ${ROOT_LIB_DIR}/scheduled-job.sh ${JOB_NAME} ${JOB_GROUP} -- /usr/bin/php ${APP_DIR}/bin/console ${JOB_COMMAND} --env=prod --no-interaction
TimeoutStartSec=${JOB_TIMEOUT}
Nice=10
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=yes
EOF
}

render_job_timer() {
  cat <<EOF
# Managed by amoobot deploy.sh - edit SCHEDULED_JOBS in deploy.sh, not this file.
[Unit]
Description=Amoobot schedule for job ${JOB_NAME} (${JOB_CALENDAR})

[Timer]
OnCalendar=${JOB_CALENDAR}
Persistent=true
AccuracySec=15s
Unit=amoobot-job-${JOB_NAME}.service

[Install]
WantedBy=timers.target
EOF
}

# render_maintenance on|off  ->  the include file. Telegram and the payment gateways retry a 503, so updates and
# callbacks arrive after the deploy instead of hitting half-updated code.
render_maintenance() {
  if [[ "$1" == on ]]; then
    printf '# Managed by amoobot deploy.sh: maintenance ON while a deploy updates code, cache and schema.\n'
    printf 'add_header Retry-After 120 always;\nreturn 503;\n'
  else
    printf '# Managed by amoobot deploy.sh: maintenance OFF.\n'
  fi
}

# set_maintenance on|off  writes the include and reloads nginx. Sets MAINTENANCE_ON (1 only if nginx really serves it).
set_maintenance() {
  local state="$1"
  render_maintenance "$state" | write_file "$NGINX_MAINTENANCE_FILE" 644 root:root --show-diff
  if (( DRY_RUN )); then
    log "[dry-run] nginx -t && systemctl reload nginx   (webhook/payment maintenance ${state})"
    return 0
  fi
  if ! grep -qF "include ${NGINX_MAINTENANCE_FILE};" "$NGINX_SITE" 2> /dev/null; then
    # First deploy, or a site config from before this feature: it takes effect once step 12 has rewritten the config.
    [[ "$state" == on ]] && log "Maintenance mode not active for this deploy (${NGINX_SITE} does not include it yet)."
    MAINTENANCE_ON=0
    return 0
  fi
  if nginx -t > /dev/null 2>&1 && systemctl reload nginx; then
    MAINTENANCE_ON=$([[ "$state" == on ]] && echo 1 || echo 0)
    log "Webhook/payment maintenance ${state^^} (${NGINX_MAINTENANCE_FILE})."
  elif [[ "$state" == on ]]; then
    render_maintenance off | write_file "$NGINX_MAINTENANCE_FILE" 644 root:root
    warn "nginx did not accept the maintenance include; continuing WITHOUT maintenance mode."
  else
    die "nginx -t / reload failed while lifting maintenance mode; ${NGINX_MAINTENANCE_FILE} may still return 503."
  fi
}

maintenance_instructions() {
  cat >&2 <<EOF

!!! /telegram/webhook/ and /payment/ still answer 503 (maintenance ON) because the deploy failed.
!!! Telegram and the payment gateways retry, but users returning from a payment page see an error until it is lifted.
    Fix the cause and re-run:            sudo amoobot-deploy --app-only
    Or, if the running release is fine, lift it by hand (as root):
      printf '' > ${NGINX_MAINTENANCE_FILE} && nginx -t && systemctl reload nginx
EOF
}

# valid_ip_or_cidr VALUE  ->  0 for an IPv4/IPv6 address, optionally with a /prefix (used in nginx allow rules)
valid_ip_or_cidr() {
  local ip="${1%%/*}" bits="" o
  if [[ "$1" == */* ]]; then bits="${1#*/}"; [[ "$bits" =~ ^[0-9]{1,3}$ ]] || return 1; fi
  if [[ "$ip" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]]; then
    for o in "${BASH_REMATCH[@]:1}"; do (( 10#$o <= 255 )) || return 1; done
    [[ -z "$bits" ]] || (( 10#$bits <= 32 ))
  elif [[ "$ip" =~ ^[0-9A-Fa-f:]+$ && "$ip" == *:* ]]; then
    [[ -z "$bits" ]] || (( 10#$bits <= 128 ))
  else
    return 1
  fi
}

# admin_access_rules  ->  nginx allow/deny lines for ADMIN_ALLOW_LIST (nothing when empty = no IP restriction)
admin_access_rules() {
  local ip
  (( ${#ADMIN_ALLOW_LIST[@]} )) || return 0
  for ip in "${ADMIN_ALLOW_LIST[@]}"; do printf '        allow %s;\n' "$ip"; done
  printf '        deny all;\n'
}

# valid_dir_path PATH PARENTS_RE  ->  0 for exactly /<parent>/<one name>, never "/", ".." or a system directory
valid_dir_path() {
  [[ "$1" =~ ^/($2)/[A-Za-z0-9._-]+$ && "${1##*/}" != "." && "${1##*/}" != ".." ]]
}

# resolve_deploy_home  sets DEPLOY_HOME from passwd (default /home/USER) and refuses anything but /home/<name>
resolve_deploy_home() {
  DEPLOY_HOME="$(getent passwd "$DEPLOY_USER" | cut -d: -f6 || true)"
  DEPLOY_HOME="${DEPLOY_HOME:-/home/$DEPLOY_USER}"
  [[ "$DEPLOY_HOME" =~ ^/home/[a-z_][a-z0-9_-]*$ ]] \
    || die "Home of ${DEPLOY_USER} is '${DEPLOY_HOME}'; expected /home/<name> (it is chowned and gets SSH keys)."
}

# verify_target_ops COMMIT  refuses a target commit (before anything is paused or checked out) that lacks a file of the
# root-owned ops layout: installing it would leave root without its scripts, or put an older deploy.sh in place that
# runs checkout files as root again.
verify_target_ops() {
  local commit="$1" path missing=() target_deploy
  for path in "${ROOT_OPS_FILES[@]}" bin/console; do
    sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" cat-file -e "${commit}:${path}" 2> /dev/null || missing+=("$path")
  done
  (( ${#missing[@]} == 0 )) \
    || die "Refusing to deploy ${commit}: it lacks ${missing[*]}. Nothing was paused or checked out."
  # Into a variable, not "| grep -q": grep exiting early would make git fail with SIGPIPE under pipefail.
  target_deploy="$(git_blob "$commit" deploy.sh)" || die "Cannot read deploy.sh from ${commit}."
  grep -qx 'ROOT_LIB_DIR="/usr/local/lib/amoobot"' <<< "$target_deploy" \
    || die "Refusing to deploy ${commit}: its deploy.sh predates the root-owned ops layout (${ROOT_LIB_DIR}); it would run checkout files as root again. Nothing was paused or checked out."
}

# database_table_count  ->  number of tables in the app database (root-owned lib; DATABASE_URL from this script)
database_table_count() {
  (
    # shellcheck source=bin/lib/database-url.sh
    source "${ROOT_LIB_DIR}/lib/database-url.sh"
    load_database_url "$APP_DIR"
    trap 'rm -f "${DB_OPTS_FILE:-}"' EXIT
    write_db_options_file
    client="$(command -v mariadb || command -v mysql)"
    "$client" --defaults-extra-file="$DB_OPTS_FILE" -N -B \
      -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' "$DB_NAME"
  )
}

# restore_instructions  how to get back to the database state before this deploy
restore_instructions() {
  # Values are prepared outside the here-doc: quotes inside ${..:+..} in a here-doc break bash's parser.
  local dump="${PRE_DEPLOY_BACKUP_FILE:-${BACKUP_DIR}/${DB_NAME}_pre-deploy_<time>.sql.gz}" note="" ref
  ref="${PREVIOUS_COMMIT:-<commit that matches the backup>}"
  [[ -n "${PRE_DEPLOY_BACKUP_FILE:-}" ]] && note="      # taken by this deploy: ${PRE_DEPLOY_BACKUP_FILE}"
  cat >&2 <<EOF

Scheduled jobs stay PAUSED. To return to the state before this deploy:
  ls -lt ${BACKUP_DIR}/${DB_NAME}_pre-deploy_*.sql.gz | head -3${note}
  sudo systemctl stop php${PHP_VERSION}-fpm
  sudo amoobot-restore ${dump} --yes
  sudo amoobot-deploy --app-only --ref ${ref}
Or deploy the release that matches the current schema again.
EOF
}

# root_ops_dest PATH  ->  where the root-owned copy of a ROOT_OPS_FILES entry is installed
root_ops_dest() {
  case "$1" in
    deploy.sh) printf '%s/deploy.sh' "$ROOT_LIB_DIR" ;;
    bin/lib/*) printf '%s/lib/%s' "$ROOT_LIB_DIR" "${1#bin/lib/}" ;;
    bin/*)     printf '%s/%s' "$ROOT_LIB_DIR" "${1#bin/}" ;;
    *)         die "root_ops_dest: unexpected path $1" ;;
  esac
}

# render_root_wrapper NAME  ->  /usr/local/sbin/amoobot-NAME. Each runs a root-owned copy with a fixed, clean environment.
render_root_wrapper() {
  local env_common="PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin APP_DIR=${APP_DIR} AMOOBOT_DB_URL_FILE=${DB_URL_FILE}"
  printf '#!/bin/bash\n# Managed by amoobot deploy.sh: root-owned entry point for %s (installed from the deployed commit).\n' "$ROOT_LIB_DIR"
  case "$1" in
    deploy) cat <<EOF
exec /bin/bash ${ROOT_LIB_DIR}/deploy.sh "\$@"
EOF
      ;;
    backup) cat <<EOF
# amoobot-backup [label]   (default label: manual; the nightly cron uses "nightly", deploy.sh "pre-deploy")
label="\${1:-manual}"
[[ \$# -le 1 && "\$label" =~ ^[A-Za-z0-9-]+\$ ]] || { echo "usage: amoobot-backup [label]" >&2; exit 2; }
exec env -i ${env_common} BACKUP_DIR=${BACKUP_DIR} BACKUP_RETENTION_DAYS=${BACKUP_RETENTION_DAYS} BACKUP_LABEL="\$label" /bin/bash ${ROOT_LIB_DIR}/backup-database.sh
EOF
      ;;
    restore) cat <<EOF
# amoobot-restore <backup.sql.gz> --yes [--no-safety-backup]
exec env -i ${env_common} BACKUP_DIR=${BACKUP_DIR} /bin/bash ${ROOT_LIB_DIR}/restore-database.sh "\$@"
EOF
      ;;
    check-jobs) cat <<EOF
exec env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin AMOOBOT_BACKUP_DIR=${BACKUP_DIR} /bin/bash ${ROOT_LIB_DIR}/check-scheduled-jobs.sh
EOF
      ;;
    *) die "render_root_wrapper: unknown wrapper $1" ;;
  esac
}

# git_blob COMMIT PATH  ->  the committed content of PATH (read as the deploy user; replace refs ignored, no filters)
git_blob() {
  sudo -H -u "$DEPLOY_USER" env GIT_NO_REPLACE_OBJECTS=1 git -C "$APP_DIR" cat-file blob "$1:$2"
}

# install_root_ops COMMIT  installs ROOT_OPS_FILES from COMMIT's git objects into ROOT_LIB_DIR, plus the wrappers.
install_root_ops() {
  local commit="$1" path dest mode tmp
  run install -d -m 755 -o root -g root "$ROOT_LIB_DIR" "$ROOT_LIB_DIR/lib"
  for path in "${ROOT_OPS_FILES[@]}"; do
    dest="$(root_ops_dest "$path")"
    mode=755; [[ "$path" == bin/lib/* ]] && mode=644
    if (( DRY_RUN )); then
      log "[dry-run] install ${path} from git objects of ${commit} (not the checkout) -> ${dest} (${mode} root:root)"
      continue
    fi
    tmp="$(mktemp)"
    git_blob "$commit" "$path" > "$tmp" || { rm -f "$tmp"; die "Cannot read ${path} from commit ${commit}."; }
    write_file "$dest" "$mode" root:root < "$tmp"
    rm -f "$tmp"
  done
  install_root_wrappers
}

install_root_wrappers() {
  local name
  run install -d -m 755 -o root -g root "$ROOT_SBIN_DIR"
  for name in "${ROOT_WRAPPERS[@]}"; do
    write_file "${ROOT_SBIN_DIR}/amoobot-${name}" 755 root:root --show-diff < <(render_root_wrapper "$name")
  done
}

# valid_ssh_public_key LINE  ->  0 for exactly one line that ssh-keygen accepts as a public key (private keys are multi-line)
valid_ssh_public_key() {
  [[ -n "$1" && "$1" != *$'\n'* && "$1" != *PRIVATE* ]] || return 1
  ssh-keygen -l -f /dev/stdin <<< "$1" > /dev/null 2>&1
}

# merge_authorized_keys SRC DEST  appends every key line of SRC that DEST lacks (DEST may already have keys).
# Sets MERGED_KEYS to the number added.
merge_authorized_keys() {
  local src="$1" dest="$2" line
  MERGED_KEYS=0
  [[ -r "$src" ]] || return 0
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ -z "${line//[[:space:]]/}" || "$line" == \#* ]] && continue
    grep -qxF -- "$line" "$dest" 2> /dev/null && continue
    printf '%s\n' "$line" >> "$dest"
    MERGED_KEYS=$((MERGED_KEYS + 1))
  done < "$src"
}

# usable_key_count FILE  ->  key lines that ssh-keygen accepts and that allow a shell (no forced command=)
usable_key_count() {
  local line n=0
  [[ -r "$1" ]] || { echo 0; return 0; }
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ -z "${line//[[:space:]]/}" || "$line" == \#* ]] && continue
    # A line not starting with the key type has options; command="..." means no shell (cloud images do this for root).
    [[ ! "$line" =~ ^(ssh-|ecdsa-|sk-) && "$line" =~ (^|,)[[:space:]]*command= ]] && continue
    valid_ssh_public_key "$line" && n=$((n + 1))
  done < "$1"
  echo "$n"
}

# ssh_login_problems USER HOME [KEYS]  ->  one line per reason why USER could not log in with a key (empty = usable).
# KEYS (default HOME/.ssh/authorized_keys) is the file whose keys are counted; a dry run passes its preview copy.
# Mirrors sshd's StrictModes: the home, ~/.ssh and authorized_keys must not be writable by others.
ssh_login_problems() {
  local user="$1" home="$2" keys="${3:-$2/.ssh/authorized_keys}" shell uid
  shell="$(getent passwd "$user" | cut -d: -f7 || true)"
  uid="$(id -u "$user" 2> /dev/null || true)"
  [[ -n "$uid" ]] || { echo "user ${user} does not exist"; return 0; }
  [[ -n "$shell" && "$shell" != */nologin && "$shell" != */false ]] || echo "${user} has no login shell (${shell:-none})"
  (( $(usable_key_count "$keys") > 0 )) \
    || echo "${home}/.ssh/authorized_keys has no valid key that allows a shell (set DEPLOY_SSH_PUBLIC_KEY)"
  local path owner mode
  for path in "$home" "$home/.ssh" "$home/.ssh/authorized_keys"; do
    [[ -e "$path" ]] || continue
    read -r owner mode < <(stat -c '%u %a' "$path")
    [[ "$owner" == "$uid" || ( "$path" == "$home" && "$owner" == 0 ) ]] || echo "${path} is not owned by ${user}"
    (( (8#$mode & 8#022) == 0 )) || echo "${path} is writable by group/others (sshd StrictModes rejects the key)"
  done
}

render_sudoers() {
  cat <<EOF
# Managed by amoobot deploy.sh: ${DEPLOY_USER} may run ONLY these root-owned entry points without a password
# (installed from the deployed commit, see ${ROOT_LIB_DIR}). Anything else needs root. Arguments are allowed.
Cmnd_Alias AMOOBOT_OPS = ${ROOT_SBIN_DIR}/amoobot-deploy, ${ROOT_SBIN_DIR}/amoobot-backup, ${ROOT_SBIN_DIR}/amoobot-restore, ${ROOT_SBIN_DIR}/amoobot-check-jobs
${DEPLOY_USER} ALL=(root) NOPASSWD: AMOOBOT_OPS
EOF
}

# install_sudoers PATH  validates the rendered rules with visudo -cf before they are installed (a broken file in
# /etc/sudoers.d disables sudo for everybody).
install_sudoers() {
  local path="$1" tmp
  tmp="$(mktemp)"
  render_sudoers > "$tmp"
  if (( DRY_RUN )); then
    log "[dry-run] validate with visudo -cf, then install ${path} (440 root:root):"
    sed 's/^/    + /' "$tmp"
    rm -f "$tmp"
    return 0
  fi
  visudo -cf "$tmp" > /dev/null || { rm -f "$tmp"; die "visudo rejected the generated sudoers rules; ${path} not changed."; }
  write_file "$path" 440 root:root < "$tmp"
  rm -f "$tmp"
}

# install_sshd_dropin PATH  (content on stdin) installs it, runs sshd -t, and on failure puts the previous file back
# (or removes the new one) BEFORE stopping, so a bad file never stays in place. Sets SSHD_CHANGED.
install_sshd_dropin() {
  local path="$1" tmp backup="" test_out
  tmp="$(mktemp)"
  cat > "$tmp"
  SSHD_CHANGED=0
  if [[ -f "$path" ]] && cmp -s "$tmp" "$path"; then
    rm -f "$tmp"
    (( DRY_RUN )) && log "[dry-run] ${path} unchanged"
    return 0
  fi
  SSHD_CHANGED=1
  if (( DRY_RUN )); then
    log "[dry-run] install ${path}, check with sshd -t (on failure restore the previous file), then reload ssh:"
    sed 's/^/    + /' "$tmp"
    rm -f "$tmp"
    return 0
  fi
  if [[ -f "$path" ]]; then backup="$(mktemp)"; cp -p "$path" "$backup"; fi
  install -D -m 644 "$tmp" "$path"
  (( EUID == 0 )) && chown root:root "$path"
  rm -f "$tmp"
  if ! test_out="$(sshd -t 2>&1)"; then
    if [[ -n "$backup" ]]; then cp -p "$backup" "$path"; rm -f "$backup"; else rm -f "$path"; fi
    SSHD_CHANGED=0
    die "sshd -t rejected the new SSH config; ${path} was restored to its previous state and ssh was not reloaded. sshd said: ${test_out}"
  fi
  rm -f "$backup"
}

# reload_ssh: Ubuntu 24.04 starts sshd through ssh.socket. A running ssh.service is reloaded; if only the socket is
# active, sshd is not running and the next connection starts it with the new config.
reload_ssh() {
  if systemctl is-active --quiet ssh.service; then
    systemctl reload ssh.service
    log "Reloaded ssh.service."
  elif systemctl is-active --quiet ssh.socket; then
    log "ssh.service is not running (socket-activated via ssh.socket): new connections use the new config."
  else
    die "Neither ssh.service nor ssh.socket is active; refusing to continue without a working SSH daemon."
  fi
}

# The freshness check (bin/check-scheduled-jobs.sh) as a root-owned timer: any problem (STALE, NEVER, FAILED, INACTIVE,
# old backup) is exit 2 -> a failed unit -> OnFailure alert, visible in "systemctl --failed" even if Telegram is down.
render_check_service() {
  cat <<EOF
# Managed by amoobot deploy.sh: hourly freshness check of the scheduled jobs and the nightly backup.
[Unit]
Description=Amoobot: check that every scheduled job and the nightly backup ran recently
OnFailure=amoobot-job-alert@%N.service

[Service]
Type=oneshot
ExecStart=${ROOT_SBIN_DIR}/amoobot-check-jobs
TimeoutStartSec=120
NoNewPrivileges=yes
PrivateTmp=yes
EOF
}

# First run 1 h after the timer starts (boot or the end of a deploy), so every job (longest interval 30 min) had a chance.
render_check_timer() {
  cat <<EOF
# Managed by amoobot deploy.sh
[Unit]
Description=Amoobot: hourly scheduled-job freshness check

[Timer]
OnActiveSec=1h
OnUnitActiveSec=1h
AccuracySec=1min
Unit=${CHECK_JOBS_UNIT}.service

[Install]
WantedBy=timers.target
EOF
}

paused_jobs_warning() {
  cat >&2 <<EOF

!!! SCHEDULED JOBS ARE PAUSED: amoobot-job-*.timer and ${CHECK_JOBS_UNIT}.timer are stopped.
!!! Nothing runs until they are resumed: no broadcasts, order expiry, usage sync, expiry, suspension or notifications.
    Preferred - fix the cause and re-run:  sudo amoobot-deploy --app-only
    Resume as they are (only if the deployed code and the schema match), as root:
      systemctl start 'amoobot-job-*.timer' ${CHECK_JOBS_UNIT}.timer
EOF
}

# render_github_known_hosts  (GitHub meta JSON on stdin)  ->  "github.com <type> <key>" lines from its "ssh_keys" array,
# each one validated with ssh-keygen. Fails when no valid key is found.
render_github_known_hosts() {
  local keys key n=0
  keys="$(tr -d '\r\n' | grep -oE '"ssh_keys"[[:space:]]*:[[:space:]]*\[[^]]*\]' || true)"
  while IFS= read -r key; do
    key="${key#\"}"; key="${key%\"}"
    valid_ssh_public_key "$key" || continue
    printf 'github.com %s\n' "$key"
    n=$((n + 1))
  done < <(grep -oE '"(ssh-ed25519|ecdsa-sha2-nistp256|ssh-rsa) [A-Za-z0-9+/]+=*"' <<< "$keys")
  (( n > 0 ))
}

# refresh_github_known_hosts  fetches the keys over HTTPS (certificate-verified) instead of trusting whatever key the
# first SSH connection presents. If GitHub cannot be reached, a previously fetched file is kept; without one, stop.
refresh_github_known_hosts() {
  local meta tmp
  tmp="$(mktemp)"
  if meta="$(curl -fsS --proto '=https' --tlsv1.2 --max-time 20 "$GITHUB_META_URL")" \
     && render_github_known_hosts <<< "$meta" > "$tmp"; then
    write_file "$GITHUB_KNOWN_HOSTS" 644 root:root < "$tmp"
    log "GitHub SSH host keys: $(wc -l < "$tmp") key(s) from ${GITHUB_META_URL} -> ${GITHUB_KNOWN_HOSTS}"
  elif [[ -s "$GITHUB_KNOWN_HOSTS" ]]; then
    warn "Could not refresh GitHub's SSH host keys from ${GITHUB_META_URL}; keeping ${GITHUB_KNOWN_HOSTS}."
  else
    rm -f "$tmp"
    die "Could not fetch GitHub's SSH host keys from ${GITHUB_META_URL}; refusing to trust an unverified host key."
  fi
  rm -f "$tmp"
}

# load_env_file PATH: KEY=VALUE lines only (no shell code is executed). The process environment wins.
load_env_file() {
  local file="$1" line key value n=0 seen=" "
  while IFS= read -r line || [[ -n "$line" ]]; do
    n=$((n + 1))
    line="${line%$'\r'}"
    [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue
    # Values are not echoed on error: they may be secrets.
    [[ "$line" =~ ^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] \
      || die "${file}:${n}: not a KEY=VALUE line."
    key="${BASH_REMATCH[2]}"
    value="${BASH_REMATCH[3]}"
    if [[ "$value" =~ ^\"(.*)\"$ || "$value" =~ ^\'(.*)\'$ ]]; then
      value="${BASH_REMATCH[1]}"
    fi
    [[ "$seen" != *" $key "* ]] || die "${file}:${n}: ${key} is defined twice."
    seen+="$key "
    # Empty means "not set": the template's blank keys must not override the app's defaults in .env.
    [[ -n "$value" ]] || continue
    ENV_FILE_KEYS+=("$key")
    if [[ -z "${!key+x}" ]]; then
      printf -v "$key" '%s' "$value"
    fi
  done < "$file"
}

require_var() {
  local name="$1" hint="$2"
  [[ -n "${!name:-}" ]] || MISSING+=("$name - $hint")
}

########################################
# ARGUMENTS
########################################

while (( $# )); do
  case "$1" in
    --dry-run)  DRY_RUN=1 ;;
    --app-only) APP_ONLY=1 ;;
    --ref)      [[ -n "${2:-}" ]] || die "--ref needs a value"; CLI_REF="$2"; shift ;;
    --env-file) [[ -n "${2:-}" ]] || die "--env-file needs a value"; ENV_FILE="$2"; shift ;;
    -h|--help)  sed -n '2,15p' "$0"; exit 0 ;;
    *)          die "Unknown option: $1 (see --help)" ;;
  esac
  shift
done

########################################
# ROOT CHECK
########################################

if (( ! DRY_RUN )) && [[ "$EUID" -ne 0 ]]; then
  die "Please run as root (sudo amoobot-deploy, or sudo bash /root/deploy.sh for the first deploy). Use --dry-run to preview without root."
fi

# Re-run from a private copy: the checkout below may replace this very file while bash is still reading it.
if [[ -z "${AMOOBOT_DEPLOY_REEXEC:-}" ]]; then
  self_copy="$(mktemp)"
  cp "$0" "$self_copy"
  reexec_args=(--env-file "$ENV_FILE")
  (( DRY_RUN )) && reexec_args+=(--dry-run)
  (( APP_ONLY )) && reexec_args+=(--app-only)
  [[ -n "$CLI_REF" ]] && reexec_args+=(--ref "$CLI_REF")
  AMOOBOT_DEPLOY_REEXEC=1 AMOOBOT_DEPLOY_SELF="$self_copy" exec bash "$self_copy" "${reexec_args[@]}"
fi
[[ -n "${AMOOBOT_DEPLOY_SELF:-}" && "$0" == "$AMOOBOT_DEPLOY_SELF" ]] && rm -f "$0"

# One deploy at a time.
if (( ! DRY_RUN )); then
  exec 9>/run/amoobot-deploy.lock
  flock -n 9 || die "Another deploy is running (lock /run/amoobot-deploy.lock)."
fi

########################################
# SETTINGS & SECRETS
########################################

step "0" "Loading settings and secrets..."

if [[ -f "$ENV_FILE" ]]; then
  if (( ! DRY_RUN )); then
    perms="$(stat -c '%a %U' "$ENV_FILE")"
    [[ "$perms" == "600 root" ]] \
      || die "${ENV_FILE} must be owned by root with mode 600 (is: ${perms}). Fix: chown root:root ${ENV_FILE} && chmod 600 ${ENV_FILE}"
  fi
  load_env_file "$ENV_FILE"
  log "Read ${#ENV_FILE_KEYS[@]} settings from ${ENV_FILE} (values not shown)."
else
  warn "${ENV_FILE} not found; using only the process environment."
fi

DOMAIN="${DOMAIN:-}"
REPO_URL="${REPO_URL:-$DEFAULT_REPO_URL}"
APP_DIR="${APP_DIR:-$DEFAULT_APP_DIR}"
PHP_VERSION="${PHP_VERSION:-$DEFAULT_PHP_VERSION}"
DEPLOY_USER="${DEPLOY_USER:-$DEFAULT_DEPLOY_USER}"
DEPLOY_REF="${CLI_REF:-${DEPLOY_REF:-$DEFAULT_DEPLOY_REF}}"
DB_NAME="${DB_NAME:-amoobot}"
DB_USER="${DB_USER:-amoobot}"
BACKUP_DIR="${BACKUP_DIR:-$DEFAULT_BACKUP_DIR}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-$DEFAULT_BACKUP_RETENTION_DAYS}"
BACKUP_CRON="${BACKUP_CRON:-$DEFAULT_BACKUP_CRON}"
SSH_PERMIT_ROOT_LOGIN="${SSH_PERMIT_ROOT_LOGIN:-prohibit-password}"
ADMIN_ALLOW_IPS="${ADMIN_ALLOW_IPS:-}"
MONITOR_PING_URL="${MONITOR_PING_URL:-}"
LETSENCRYPT_EMAIL="${LETSENCRYPT_EMAIL:-admin@${DOMAIN}}"

# APP_ENV / APP_DEBUG are enforced: anything other than prod / 0 is a hard error, never silently accepted.
[[ -z "${APP_ENV:-}" || "${APP_ENV}" == "prod" ]] || die "APP_ENV must be 'prod' (got '${APP_ENV}')."
[[ -z "${APP_DEBUG:-}" || "${APP_DEBUG}" == "0" ]] || die "APP_DEBUG must be '0' in production (got '${APP_DEBUG}')."
APP_ENV="prod"
APP_DEBUG="0"
# This script registers a webhook, so the bot must not long-poll at the same time.
[[ -z "${TELEGRAM_MODE:-}" || "${TELEGRAM_MODE}" == "webhook" ]] \
  || die "TELEGRAM_MODE must be 'webhook' (this script registers the Telegram webhook)."
TELEGRAM_MODE="webhook"

MISSING=()
require_var DOMAIN "public hostname, e.g. bot.example.com (DNS A record must point here)"
require_var APP_SECRET "random, e.g. openssl rand -hex 32"
require_var TELEGRAM_BOT_TOKEN "from @BotFather"
require_var TELEGRAM_WEBHOOK_SECRET "random URL secret, e.g. openssl rand -hex 32"
require_var TELEGRAM_WEBHOOK_SECRET_TOKEN "random header secret (A-Z a-z 0-9 _ -), e.g. openssl rand -hex 32"
require_var TELEGRAM_ADMIN_CHAT_ID "numeric chat id that receives admin alerts"
[[ -n "${DATABASE_URL:-}" ]] || require_var DB_PASSWORD "password for the local MariaDB user ${DB_USER}"
if (( ${#MISSING[@]} )); then
  printf 'Missing required settings (set them in %s; never pass secrets on the command line):\n' "$ENV_FILE" >&2
  printf '  - %s\n' "${MISSING[@]}" >&2
  die "${#MISSING[@]} required setting(s) missing."
fi

[[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ && "$DOMAIN" == *.* ]] || die "DOMAIN is not a valid hostname."
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "DB_NAME / DB_USER may only contain A-Z a-z 0-9 _."
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$DEPLOY_USER" != "root" ]] || die "DEPLOY_USER must be a non-root user name."
[[ "$SSH_PERMIT_ROOT_LOGIN" =~ ^(no|prohibit-password)$ ]] || die "SSH_PERMIT_ROOT_LOGIN must be 'no' or 'prohibit-password'."
if [[ -n "${DEPLOY_SSH_PUBLIC_KEY:-}" ]]; then
  if command -v ssh-keygen > /dev/null 2>&1; then
    valid_ssh_public_key "$DEPLOY_SSH_PUBLIC_KEY" \
      || die "DEPLOY_SSH_PUBLIC_KEY is not one valid SSH public key line (ssh-keygen -l rejected it). Use the .pub file's content."
  else
    (( DRY_RUN )) || die "ssh-keygen is missing; cannot validate DEPLOY_SSH_PUBLIC_KEY."
    warn "ssh-keygen not installed here: DEPLOY_SSH_PUBLIC_KEY not validated in this dry run."
  fi
fi
[[ "$BACKUP_RETENTION_DAYS" =~ ^[0-9]+$ ]] || die "BACKUP_RETENTION_DAYS must be a number."
# Validated before anything runs: these are used with recursive chown/chmod, rm and in root-owned scripts.
valid_dir_path "$APP_DIR" 'var/www|srv' || die "APP_DIR must be /var/www/<name> or /srv/<name> (A-Z a-z 0-9 . _ -; got '${APP_DIR}')."
valid_dir_path "$BACKUP_DIR" 'var/backups|srv' \
  || die "BACKUP_DIR must be /var/backups/<name> or /srv/<name> (A-Z a-z 0-9 . _ -; got '${BACKUP_DIR}')."
# Optional dead-man's-switch URL ("{job}" = job name). No quotes, spaces, "$" or "\": it goes into a systemd EnvironmentFile.
monitor_url_re='^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/?&=%+,:@!*{}-]*)?$'
if [[ -n "$MONITOR_PING_URL" ]]; then
  [[ "$MONITOR_PING_URL" =~ $monitor_url_re ]] \
    || die "MONITOR_PING_URL must be an https:// URL without spaces, quotes, \$ or backslashes (value not shown)."
fi
# Optional: only these addresses/networks may reach /admin (comma or space separated). Empty = no IP restriction.
ADMIN_ALLOW_LIST=()
read -ra admin_ips <<< "${ADMIN_ALLOW_IPS//,/ }"
for ip in "${admin_ips[@]}"; do
  valid_ip_or_cidr "$ip" || die "ADMIN_ALLOW_IPS: '${ip}' is not an IPv4/IPv6 address or CIDR."
  ADMIN_ALLOW_LIST+=("$ip")
done
[[ "$TELEGRAM_BOT_TOKEN" =~ ^[0-9]+:[A-Za-z0-9_-]{30,}$ ]] || die "TELEGRAM_BOT_TOKEN does not look like a BotFather token."
[[ "$TELEGRAM_ADMIN_CHAT_ID" =~ ^-?[0-9]+$ ]] || die "TELEGRAM_ADMIN_CHAT_ID must be numeric."
(( ${#APP_SECRET} >= 32 )) || die "APP_SECRET must be at least 32 characters."
[[ "$TELEGRAM_WEBHOOK_SECRET" =~ ^[A-Za-z0-9_-]{32,256}$ ]] \
  || die "TELEGRAM_WEBHOOK_SECRET must be 32-256 characters of A-Z a-z 0-9 _ - (it is part of the URL)."
[[ "$TELEGRAM_WEBHOOK_SECRET_TOKEN" =~ ^[A-Za-z0-9_-]{32,256}$ ]] \
  || die "TELEGRAM_WEBHOOK_SECRET_TOKEN must be 32-256 characters of A-Z a-z 0-9 _ - (Telegram secret_token rules)."
[[ "$TELEGRAM_WEBHOOK_SECRET_TOKEN" != "$TELEGRAM_WEBHOOK_SECRET" ]] \
  || die "TELEGRAM_WEBHOOK_SECRET_TOKEN must differ from TELEGRAM_WEBHOOK_SECRET."
for k in APP_SECRET TELEGRAM_WEBHOOK_SECRET TELEGRAM_WEBHOOK_SECRET_TOKEN DB_PASSWORD; do
  [[ "${!k:-}" =~ ^(change_?this|changeme|secret|password|admin)([_-].*)?$ ]] && die "${k} is still a placeholder value."
done
if [[ -n "${DB_PASSWORD:-}" ]]; then
  # URL-safe only: DATABASE_URL is read through %env(resolve:...)%, where "%" (from URL-encoding) would be
  # taken as a container parameter; '@' ':' '/' would break the URL itself.
  [[ "$DB_PASSWORD" =~ ^[A-Za-z0-9._~-]{16,}$ ]] \
    || die "DB_PASSWORD must be 16+ characters of A-Z a-z 0-9 . _ ~ - (e.g. openssl rand -hex 24)."
fi

job_names=" "
for entry in "${SCHEDULED_JOBS[@]}"; do
  parse_job "$entry"
  [[ "$JOB_NAME" =~ ^[a-z0-9-]+$ && "$JOB_GROUP" =~ ^[a-z0-9-]+$ && "$job_names" != *" $JOB_NAME "* ]] \
    || die "SCHEDULED_JOBS: invalid or duplicate job name/group in '${entry}'."
  [[ "$JOB_TIMEOUT" =~ ^[0-9]+$ && "$JOB_INTERVAL" =~ ^[0-9]+$ && -n "$JOB_CALENDAR" && "$JOB_COMMAND" == app:* ]] \
    || die "SCHEDULED_JOBS: invalid entry '${entry}'."
  [[ "$JOB_COMMAND" != *repair-stuck* ]] || die "SCHEDULED_JOBS: app:payments:repair-stuck must never be scheduled."
  # An allowlist prevents maintenance/payment/test commands (or --only=all) being added accidentally.
  case "$JOB_COMMAND" in
    'app:automation:run --only=broadcasts'|'app:automation:run --only=orders'|\
    'app:automation:run --only=sync'|'app:automation:run --only=expiry'|\
    'app:automation:run --only=suspend'|'app:automation:run --only=notifications') ;;
    *) die "SCHEDULED_JOBS: only the six reviewed automation tasks may be scheduled." ;;
  esac
  job_names+="$JOB_NAME "
done

LOCAL_DB=1
[[ -n "${DATABASE_URL:-}" ]] && LOCAL_DB=0

log "Domain: ${DOMAIN}   App dir: ${APP_DIR}   Ref: ${DEPLOY_REF}   PHP: ${PHP_VERSION}   Deploy user: ${DEPLOY_USER}"
log "Database: $( (( LOCAL_DB )) && echo "local MariaDB ${DB_NAME} (user ${DB_USER})" || echo "external DATABASE_URL (not provisioned here)" )"
(( DRY_RUN )) && log "DRY RUN: nothing will be changed."
(( APP_ONLY )) && log "APP-ONLY: server provisioning (steps 1-6) is skipped."

export DEBIAN_FRONTEND=noninteractive

if (( ! APP_ONLY )); then

########################################
# UPDATE SYSTEM
########################################

step "1/18" "Updating system..."
run apt-get update
# Non-interactive, and keep locally modified config files (e.g. sshd_config) instead of prompting or overwriting.
run env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade -y

########################################
# INSTALL PACKAGES
########################################

step "2/18" "Installing packages..."
run apt-get install -y \
  nginx \
  git \
  unzip \
  curl \
  supervisor \
  mariadb-server \
  software-properties-common \
  ufw \
  cron \
  util-linux \
  logrotate \
  gzip \
  certbot \
  python3-certbot-nginx

########################################
# INSTALL PHP
########################################

step "3/18" "Installing PHP ${PHP_VERSION}..."
if ! compgen -G "/etc/apt/sources.list.d/ondrej-*php*" > /dev/null; then
  run add-apt-repository ppa:ondrej/php -y
  run apt-get update
fi

run apt-get install -y \
  php${PHP_VERSION}-fpm \
  php${PHP_VERSION}-cli \
  php${PHP_VERSION}-mbstring \
  php${PHP_VERSION}-xml \
  php${PHP_VERSION}-curl \
  php${PHP_VERSION}-zip \
  php${PHP_VERSION}-intl \
  php${PHP_VERSION}-mysql \
  php${PHP_VERSION}-bcmath \
  php${PHP_VERSION}-gd \
  php${PHP_VERSION}-sqlite3

########################################
# INSTALL COMPOSER
########################################

step "4/18" "Installing Composer..."
# Supply chain: the installer's SHA-384 is checked against composer.github.io, but it installs the LATEST stable
# Composer. To pin, add --version=<2.x.y> to the composer-setup.php call below (and bump it deliberately); an existing
# /usr/local/bin/composer is kept as is (update it with "composer self-update <2.x.y>").
if command -v composer > /dev/null 2>&1; then
  log "Composer already installed."
elif (( DRY_RUN )); then
  log "[dry-run] download Composer installer, verify its SHA-384 signature, install to /usr/local/bin/composer"
else
  tmpdir="$(mktemp -d)"
  expected="$(curl -fsS https://composer.github.io/installer.sig)"
  curl -fsS -o "$tmpdir/composer-setup.php" https://getcomposer.org/installer
  actual="$(php -r "echo hash_file('sha384', '$tmpdir/composer-setup.php');")"
  [[ -n "$expected" && "$expected" == "$actual" ]] || die "Composer installer signature mismatch."
  php "$tmpdir/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
  rm -rf "$tmpdir"
fi

########################################
# SERVER HARDENING: deploy user, SSH, firewall, restart policies
########################################

step "5/18" "Hardening server (deploy user, SSH key-only, firewall 22/80/443, restart policies)..."

if ! id "$DEPLOY_USER" > /dev/null 2>&1; then
  run useradd --create-home --shell /bin/bash "$DEPLOY_USER"
fi
resolve_deploy_home
run install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$DEPLOY_HOME/.ssh"
auth_keys="$DEPLOY_HOME/.ssh/authorized_keys"
# A dry run works on a copy, so it reports what would be added without touching the real file.
keys_target="$auth_keys"
if (( DRY_RUN )); then
  keys_target="$(mktemp)"
  [[ -r "$auth_keys" ]] && cat "$auth_keys" > "$keys_target"
else
  touch "$auth_keys"
fi
if [[ -n "${DEPLOY_SSH_PUBLIC_KEY:-}" ]] && ! grep -qxF -- "$DEPLOY_SSH_PUBLIC_KEY" "$keys_target"; then
  printf '%s\n' "$DEPLOY_SSH_PUBLIC_KEY" >> "$keys_target"
  log "$( (( DRY_RUN )) && echo '[dry-run] add' || echo 'Added') DEPLOY_SSH_PUBLIC_KEY to ${auth_keys}."
fi
# Always merge root's keys (not only into an empty file): whoever administers root keeps access as the deploy user.
merge_authorized_keys /root/.ssh/authorized_keys "$keys_target"
(( MERGED_KEYS )) && log "$( (( DRY_RUN )) && echo '[dry-run] add' || echo 'Added') ${MERGED_KEYS} key(s) from /root/.ssh/authorized_keys to ${auth_keys}."
run chown "$DEPLOY_USER:$DEPLOY_USER" "$auth_keys"
run chmod 600 "$auth_keys"

# Administration path for the deploy user: passwordless sudo for the root-owned entry points only (not ALL).
install_root_wrappers
install_sudoers /etc/sudoers.d/amoobot-deploy

# Key-only SSH (and PermitRootLogin no, if chosen) only once the deploy user verifiably has a key and the sudo path.
if (( DRY_RUN )) && ! id "$DEPLOY_USER" > /dev/null 2>&1; then
  # Not created yet (a real run creates it above); judge only the keys it would get.
  login_problems=()
  (( $(usable_key_count "$keys_target") > 0 )) \
    || login_problems=("${auth_keys} would have no valid key that allows a shell (set DEPLOY_SSH_PUBLIC_KEY)")
else
  mapfile -t login_problems < <(ssh_login_problems "$DEPLOY_USER" "$DEPLOY_HOME" "$keys_target")
fi
if (( ! DRY_RUN )) && ! sudo -n -l -U "$DEPLOY_USER" "${ROOT_SBIN_DIR}/amoobot-deploy" > /dev/null 2>&1; then
  login_problems+=("sudo -l -U ${DEPLOY_USER} does not allow ${ROOT_SBIN_DIR}/amoobot-deploy")
fi
(( DRY_RUN )) && rm -f "$keys_target"
if (( ${#login_problems[@]} )); then
  printf '  - %s\n' "${login_problems[@]}" >&2
  refusal="Refusing to disable SSH password login (and to set PermitRootLogin ${SSH_PERMIT_ROOT_LOGIN}): ${DEPLOY_USER} has no verified key login + sudo path (reasons above). That would risk locking you out. Set DEPLOY_SSH_PUBLIC_KEY (your .pub) and re-run."
  if (( DRY_RUN )); then warn "a real run would stop here. ${refusal}"; else die "$refusal"; fi
fi
# 00- so it is read before cloud-init's 50-cloud-init.conf (first value wins in sshd).
install_sshd_dropin /etc/ssh/sshd_config.d/00-amoobot-hardening.conf <<EOF
# Managed by amoobot deploy.sh
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitEmptyPasswords no
PermitRootLogin ${SSH_PERMIT_ROOT_LOGIN}
EOF
if (( SSHD_CHANGED && ! DRY_RUN )); then
  reload_ssh
fi
if (( ! DRY_RUN )); then
  [[ "$(sshd -T | awk '$1=="passwordauthentication"{print $2}')" == "no" ]] \
    || die "Password login is still enabled by another sshd config file; check /etc/ssh/sshd_config(.d)."
  ssh_ports="$(sshd -T | awk '$1=="port"{print $2}' | sort -u | tr '\n' ' ')"
  [[ "$ssh_ports" == "22 " ]] \
    || die "sshd listens on port(s) ${ssh_ports}but the firewall only allows 22. Move SSH to 22 first, or you will be locked out."
fi

run ufw default deny incoming
run ufw default allow outgoing
run ufw allow 22/tcp
run ufw allow 80/tcp
run ufw allow 443/tcp
run ufw --force enable
if (( ! DRY_RUN )); then
  extra_rules="$(ufw status | awk 'NR>4 && $1 !~ /^(22|80|443)(\/tcp)?$/ && $1 != "" {print $1}' | sort -u | tr '\n' ' ')"
  [[ -z "$extra_rules" ]] || warn "ufw also allows: ${extra_rules}- not removed automatically; delete with 'ufw delete allow <rule>' if unused."
fi

reload_systemd=0
for svc in nginx "php${PHP_VERSION}-fpm" mariadb supervisor cron; do
  write_file "/etc/systemd/system/${svc}.service.d/10-amoobot-restart.conf" 644 root:root <<'EOF'
# Managed by amoobot deploy.sh: restart crashed services automatically
[Unit]
StartLimitIntervalSec=300
StartLimitBurst=10

[Service]
Restart=on-failure
RestartSec=5s
EOF
  (( FILE_CHANGED )) && reload_systemd=1
done
(( reload_systemd )) && run systemctl daemon-reload
run systemctl enable --now nginx "php${PHP_VERSION}-fpm" mariadb supervisor cron

########################################
# CREATE DATABASE
########################################

step "6/18" "Configuring MariaDB and database..."

if (( LOCAL_DB )); then
  write_file /etc/mysql/mariadb.conf.d/60-amoobot.cnf 644 root:root <<EOF
# Managed by amoobot deploy.sh
[mysqld]
sql_mode = ${REQUIRED_SQL_MODE}
EOF
  if (( DRY_RUN )); then
    log "[dry-run] SET GLOBAL sql_mode; CREATE DATABASE IF NOT EXISTS ${DB_NAME}; CREATE USER IF NOT EXISTS / ALTER USER '${DB_USER}'@'localhost' (password from env, not shown); GRANT"
  else
    # Statements go through stdin so the password never appears in the process list.
    mysql <<EOF
SET GLOBAL sql_mode = '${REQUIRED_SQL_MODE}';
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY $(sql_quote "$DB_PASSWORD");
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY $(sql_quote "$DB_PASSWORD");
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
    actual_mode="$(mysql -N -e 'SELECT @@GLOBAL.sql_mode')"
    [[ "$actual_mode" == "$REQUIRED_SQL_MODE" ]] || die "MariaDB sql_mode is '${actual_mode}', expected '${REQUIRED_SQL_MODE}'."
    log "sql_mode = ${actual_mode}"
  fi
else
  warn "External database: make sure its sql_mode is ${REQUIRED_SQL_MODE}."
fi

fi # APP_ONLY

########################################
# CLONE / UPDATE PROJECT
########################################

step "7/18" "Fetching code (${DEPLOY_REF})..."

id "$DEPLOY_USER" > /dev/null 2>&1 || (( DRY_RUN )) || die "Deploy user ${DEPLOY_USER} does not exist; run once without --app-only."
resolve_deploy_home
DEPLOY_KEY="$DEPLOY_HOME/.ssh/amoobot_deploy_ed25519"
# Never accept-new: an unknown host key is refused. For github.com the keys come from GitHub's API over HTTPS.
repo_host="${REPO_URL#ssh://}"; repo_host="${repo_host#*@}"; repo_host="${repo_host%%[:/]*}"
GIT_SSH="ssh -i ${DEPLOY_KEY} -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes"
if [[ "$REPO_URL" == git@* || "$REPO_URL" == ssh://* ]] && [[ "$repo_host" == github.com ]]; then
  GIT_SSH+=" -o UserKnownHostsFile=${GITHUB_KNOWN_HOSTS}"
  if (( DRY_RUN )); then
    log "[dry-run] fetch GitHub's SSH host keys from ${GITHUB_META_URL} (HTTPS) -> ${GITHUB_KNOWN_HOSTS}; git uses StrictHostKeyChecking=yes"
  else
    refresh_github_known_hosts
  fi
fi

if [[ "$REPO_URL" == git@* || "$REPO_URL" == ssh://* ]]; then
  if [[ ! -f "$DEPLOY_KEY" ]]; then
    as "$DEPLOY_USER" ssh-keygen -q -t ed25519 -N '' -C "amoobot-deploy@$(hostname)" -f "$DEPLOY_KEY"
  fi
  if (( ! DRY_RUN )) && ! sudo -H -u "$DEPLOY_USER" GIT_SSH_COMMAND="$GIT_SSH" git ls-remote --heads "$REPO_URL" > /dev/null 2>&1; then
    log ""
    log "The server cannot read ${REPO_URL}. Add this PUBLIC key as a read-only Deploy key"
    log "(GitHub > repo > Settings > Deploy keys > Add, leave 'Allow write access' UNCHECKED):"
    log ""
    cat "${DEPLOY_KEY}.pub"
    log ""
    if [[ "$repo_host" != github.com ]]; then
      log "${repo_host} is not github.com: its SSH host key is not fetched automatically and unknown keys are refused."
      log "Get the fingerprint from your git provider's documentation, then add the verified key to ${DEPLOY_HOME}/.ssh/known_hosts"
      log "(e.g. ssh-keyscan ${repo_host} > /tmp/k; ssh-keygen -lf /tmp/k; compare; append only if it matches)."
      log ""
    fi
    die "Repository not readable with the deploy key. Add the key above, then re-run."
  fi
else
  warn "REPO_URL is not an SSH URL; the read-only deploy key is not used."
fi

run install -d -m 750 -o "$DEPLOY_USER" -g "$WEB_USER" "$APP_DIR"
if [[ -d "$APP_DIR/.git" ]]; then
  # Earlier versions of this script left the checkout owned by root/www-data.
  run chown -R "$DEPLOY_USER:$WEB_USER" "$APP_DIR"
  [[ -d "$APP_DIR/var" ]] && run chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/var"
  as "$DEPLOY_USER" git -C "$APP_DIR" remote set-url origin "$REPO_URL"
else
  as "$DEPLOY_USER" env GIT_SSH_COMMAND="$GIT_SSH" git clone "$REPO_URL" "$APP_DIR"
fi
[[ "$REPO_URL" == git@* || "$REPO_URL" == ssh://* ]] && as "$DEPLOY_USER" git -C "$APP_DIR" config core.sshCommand "$GIT_SSH"

if (( ! DRY_RUN )); then
  dirty="$(sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" status --porcelain --untracked-files=no)"
  [[ -z "$dirty" ]] || die "Tracked files were modified on the server in ${APP_DIR}; inspect with 'git status' and revert before deploying."
  PREVIOUS_COMMIT="$(sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" rev-parse --verify -q HEAD || true)"
fi
as "$DEPLOY_USER" git -C "$APP_DIR" fetch --prune --tags origin
if (( DRY_RUN )); then
  log "[dry-run] resolve ${DEPLOY_REF} (origin/${DEPLOY_REF}, a tag, or a commit); refuse it unless it has every root-owned ops file (git cat-file -e) - before pausing anything"
else
  TARGET_COMMIT="$(sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" rev-parse --verify -q "origin/${DEPLOY_REF}^{commit}" \
    || sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" rev-parse --verify -q "${DEPLOY_REF}^{commit}" \
    || true)"
  [[ -n "$TARGET_COMMIT" ]] || die "Ref '${DEPLOY_REF}' not found on origin (branch, tag or commit)."
  # Checked before the jobs are paused: a refused rollback leaves the running release untouched.
  verify_target_ops "$TARGET_COMMIT"
fi
# From here until step 16 the code, cache and schema change: no scheduled job may run meanwhile.
pause_scheduled_jobs
# ...and until the end of step 11 Telegram and the gateways get 503 (they retry) instead of half-updated code.
set_maintenance on
if (( DRY_RUN )); then
  log "[dry-run] check out ${DEPLOY_REF}"
else
  sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" checkout -q --detach "$TARGET_COMMIT"
  install -d -m 755 "$STATE_DIR"
  printf '%s %s -> %s (%s)\n' "$(date -u +%FT%TZ)" "${PREVIOUS_COMMIT:-none}" "$TARGET_COMMIT" "$DEPLOY_REF" >> "$STATE_DIR/releases.log"
  log "Deploying ${TARGET_COMMIT} (previous: ${PREVIOUS_COMMIT:-none}). History: ${STATE_DIR}/releases.log"
fi
# Root-run scripts come from the commit's git objects, so an edited file in the checkout never runs as root.
install_root_ops "${TARGET_COMMIT:-<target commit>}"

cd "$APP_DIR" 2>/dev/null || (( DRY_RUN )) || die "Cannot enter ${APP_DIR}."

########################################
# INSTALL DEPENDENCIES
########################################

step "8/18" "Installing Composer dependencies (production)..."
# Scripts run later as the web user (cache:clear / assets:install need var/ and public/bundles).
as "$DEPLOY_USER" composer install --working-dir="$APP_DIR" --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

########################################
# ENV FILE
########################################

step "9/18" "Writing production env (.env.prod.local -> .env.local.php)..."

if (( LOCAL_DB )); then
  # DB_PASSWORD was validated as URL-safe above, so it needs no encoding.
  DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@127.0.0.1:3306/${DB_NAME}?serverVersion=mariadb-10.11.0&charset=utf8mb4"
fi
DEFAULT_URI="${DEFAULT_URI:-https://${DOMAIN}}"

render_app_env() {
  local key seen=" "
  printf '# Generated by deploy.sh from %s - edit that file, not this one.\n' "$ENV_FILE"
  for key in APP_ENV APP_DEBUG TELEGRAM_MODE DATABASE_URL DEFAULT_URI "${ENV_FILE_KEYS[@]}" $APP_KEYS; do
    [[ "$seen" == *" $key "* ]] && continue
    seen+="$key "
    is_deploy_only "$key" && continue
    [[ -n "${!key:-}" ]] || continue
    [[ "${!key}" != *$'\n'* ]] || die "${key} must be a single line."
    printf '%s=%s\n' "$key" "$(dotenv_quote "${!key}")"
  done
}

render_app_env | write_file "$APP_DIR/.env.prod.local" 600 "$DEPLOY_USER:$DEPLOY_USER"
# The root-run backup/restore scripts read DATABASE_URL only from this root-owned file.
[[ "$DATABASE_URL" != *$'\n'* ]] || die "DATABASE_URL must be a single line."
run install -d -m 700 -o root -g root "${DB_URL_FILE%/*}"
printf 'DATABASE_URL=%s\n' "$DATABASE_URL" | write_file "$DB_URL_FILE" 600 root:root
# Replaces the old .env.local (which held secrets); now only the non-secret environment switch.
printf 'APP_ENV=prod\nAPP_DEBUG=0\n' | write_file "$APP_DIR/.env.local" 640 "$DEPLOY_USER:$WEB_USER"
as "$DEPLOY_USER" composer dump-env prod --working-dir="$APP_DIR" --no-interaction
run chown "$DEPLOY_USER:$WEB_USER" "$APP_DIR/.env.local.php"
run chmod 640 "$APP_DIR/.env.local.php"

########################################
# PERMISSIONS
########################################

step "10/18" "Setting permissions..."

run install -d -o "$WEB_USER" -g "$WEB_USER" "$APP_DIR/var" "$APP_DIR/var/plugins" "$APP_DIR/var/plugin_uploads" "$APP_DIR/var/log" "$APP_DIR/public/bundles"
run chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/var" "$APP_DIR/public/bundles"
run chmod -R 775 "$APP_DIR/var"
# Code: owned by the deploy user, readable (not writable) by PHP-FPM, closed to others.
run chmod -R g+rX,o-rwx "$APP_DIR"
run chmod 600 "$APP_DIR/.env.prod.local"

########################################
# CACHE & MIGRATIONS
########################################

step "11/18" "Clearing cache, running migrations..."

# Fresh cache first: a prod container left over from the previous release must not run the new migrations.
# As the web user (var/ is its directory): root never deletes inside the deploy-writable tree.
as "$WEB_USER" rm -rf "$APP_DIR/var/cache/prod"
console cache:warmup
console assets:install public

# Backup whenever migrations are pending and the database already holds data, whatever was deployed before (a server
# restored from a dump, or a first run of this script against an existing database, has no previous commit).
PRE_DEPLOY_BACKUP_FILE=""
if (( DRY_RUN )); then
  log "[dry-run] if migrations are pending AND the database already has tables: ${ROOT_SBIN_DIR}/amoobot-backup pre-deploy"
elif ! console doctrine:migrations:up-to-date > /dev/null 2>&1; then
  tables="$(database_table_count)"
  [[ "$tables" =~ ^[0-9]+$ ]] || die "Could not count the tables of the database (got '${tables}')."
  if (( tables > 0 )); then
    log "Pending migrations and ${tables} existing tables: taking a pre-migration backup first..."
    # Root-owned copy; it reads DATABASE_URL from ${DB_URL_FILE}, never from the checkout.
    backup_out="$("${ROOT_SBIN_DIR}/amoobot-backup" pre-deploy)"
    log "$backup_out"
    PRE_DEPLOY_BACKUP_FILE="$(sed -n 's/^Backup written to \(.*\) ([^)]*)$/\1/p' <<< "$backup_out")"
  else
    log "Pending migrations on an empty database (first install): no backup needed."
  fi
fi
# No "|| true": a failed migration stops the deploy (before step 16, so the scheduled jobs stay paused).
if ! console doctrine:migrations:migrate --allow-no-migration; then
  restore_instructions
  die "doctrine:migrations:migrate failed."
fi
# Exit 1: migrations still pending. Exit 2: the database has migrations this code does not know (e.g. a rollback to a
# release older than the schema) - the code and the schema do not match.
if console doctrine:migrations:up-to-date --fail-on-unregistered; then
  :
else
  rc=$?
  restore_instructions
  (( rc == 2 )) && die "The database has executed migrations that ${TARGET_COMMIT:-this release} does not contain (up-to-date exit 2): code and schema do not match."
  die "Migrations are not up to date after migrate (up-to-date exit ${rc})."
fi
console lint:container
# Code, cache and schema are consistent again.
set_maintenance off

########################################
# NGINX CONFIG
########################################

step "12/18" "Configuring Nginx..."

CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
# IPv6 listeners only where the kernel has IPv6 (nginx refuses to start on "listen [::]" otherwise).
NGINX_IPV6=0
[[ -e /proc/net/if_inet6 ]] && NGINX_IPV6=1

# listen_lines PORT [ssl]  ->  "listen PORT;" plus the IPv6 listener when available
listen_lines() {
  printf '    listen %s%s;\n' "$1" "${2:+ $2}"
  (( NGINX_IPV6 )) && printf '    listen [::]:%s%s;\n' "$1" "${2:+ $2}"
  return 0
}

# ACME http-01 challenges (certbot's nginx plugin adds exact-match locations for its tokens; the webroot serves files).
acme_location() {
  cat <<EOF
    location ^~ /.well-known/acme-challenge/ {
        root ${ACME_WEBROOT};
        default_type text/plain;
    }
EOF
}

# The application locations of the HTTPS server.
app_locations() {
  cat <<EOF
    root ${APP_DIR}/public;
    index index.php;

    client_max_body_size 50M;
    server_tokens off;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location = /healthz {
        access_log off;
        try_files \$uri /index.php\$is_args\$args;
    }

    # The URL contains TELEGRAM_WEBHOOK_SECRET, so these requests are never written to the access log.
    # fastcgi directly (no try_files): an internal redirect to /index.php would be logged by that location instead.
    # 503 + Retry-After while a deploy runs (${NGINX_MAINTENANCE_FILE}); Telegram retries the update.
    location ^~ /telegram/webhook/ {
        access_log off;
        include ${NGINX_MAINTENANCE_FILE};
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
    }

    # Payment callbacks and webhooks: same maintenance switch (gateways retry server-to-server notifications).
    location ^~ /payment/ {
        include ${NGINX_MAINTENANCE_FILE};
        try_files \$uri /index.php\$is_args\$args;
    }

    # Admin login: rate-limited here as well as by Symfony's login_throttling (5 failures / 15 min).
    location = /admin/login {
        limit_req zone=amoobot_admin_login burst=10 nodelay;
        limit_req_status 429;
$(admin_access_rules)
        try_files \$uri /index.php\$is_args\$args;
    }
EOF
  if (( ${#ADMIN_ALLOW_LIST[@]} )); then
    cat <<EOF

    # ADMIN_ALLOW_IPS: only these addresses may reach the admin panel.
    location ^~ /admin {
$(admin_access_rules)
        try_files \$uri /index.php\$is_args\$args;
    }
EOF
  fi
  cat <<EOF

    location ~ ^/index\.php(/|\$) {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)\$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        internal;
    }

    location ~ \.php\$ {
        return 404;
    }

    location ~ /\. {
        deny all;
    }

    access_log /var/log/nginx/amoobot_access.log;
    error_log /var/log/nginx/amoobot_error.log;
EOF
}

render_nginx() {
  local hsts=""
  # Only after HTTPS was verified (step 18); without includeSubDomains, other hosts of the domain are not affected.
  [[ -e "$HSTS_STATE_FILE" ]] && hsts='    add_header Strict-Transport-Security "max-age=31536000" always;'
  if [[ -f "$CERT_DIR/fullchain.pem" ]]; then
    cat <<EOF
# Managed by amoobot deploy.sh
limit_req_zone \$binary_remote_addr zone=amoobot_admin_login:10m rate=10r/m;

server {
$(listen_lines 80)
    server_name ${DOMAIN};
    server_tokens off;

$(acme_location)

    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
$(listen_lines 443 ssl)
    server_name ${DOMAIN};

    ssl_certificate ${CERT_DIR}/fullchain.pem;
    ssl_certificate_key ${CERT_DIR}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:amoobot_ssl:10m;
    ssl_session_timeout 1d;
${hsts}

$(app_locations)
}
EOF
  else
    # Before the certificate exists nothing is served over plain HTTP except the ACME challenge.
    cat <<EOF
# Managed by amoobot deploy.sh (no certificate yet: only ACME challenges are served, everything else is 503)
server {
$(listen_lines 80)
    server_name ${DOMAIN};
    server_tokens off;

$(acme_location)

    location / {
        add_header Retry-After 300 always;
        return 503;
    }
}
EOF
  fi
}

apply_nginx() {
  # The site includes the maintenance file, so it must exist (normally "off" here) before nginx -t.
  [[ -f "$NGINX_MAINTENANCE_FILE" ]] || render_maintenance off | write_file "$NGINX_MAINTENANCE_FILE" 644 root:root
  run install -d -m 755 -o root -g root "$ACME_WEBROOT"
  render_nginx | write_file "$NGINX_SITE" 644 root:root --show-diff
  run ln -sf "$NGINX_SITE" /etc/nginx/sites-enabled/amoobot
  run rm -f /etc/nginx/sites-enabled/default
  run nginx -t
  run systemctl reload nginx
}
apply_nginx

########################################
# SSL
########################################

step "13/18" "Ensuring HTTPS certificate (Let's Encrypt, auto-renewing)..."

# certonly: this script owns the nginx config; certbot only obtains/renews the certificate (certbot.timer).
run certbot certonly --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LETSENCRYPT_EMAIL" \
  --keep-until-expiring --deploy-hook "systemctl reload nginx"
apply_nginx
run systemctl enable --now certbot.timer

########################################
# SUPERVISOR
########################################

step "14/18" "Configuring Supervisor..."

if (( DRY_RUN )); then
  log "[dry-run] install amoobot-worker (messenger:consume async) only if an 'async' messenger transport is configured"
elif console debug:config framework messenger.transports 2>/dev/null | grep -E '^[[:space:]]*async:' > /dev/null; then
  write_file /etc/supervisor/conf.d/amoobot-worker.conf 644 root:root <<EOF
[program:amoobot-worker]
command=php ${APP_DIR}/bin/console messenger:consume async --time-limit=3600 --env=prod
directory=${APP_DIR}
user=${WEB_USER}
numprocs=1
autostart=true
autorestart=true
startretries=10
stopwaitsecs=30
stderr_logfile=${APP_DIR}/var/log/worker.err.log
stdout_logfile=${APP_DIR}/var/log/worker.out.log
EOF
  supervisorctl reread
  supervisorctl update
  supervisorctl restart amoobot-worker
else
  log "No 'async' messenger transport configured (messages are handled synchronously); no worker needed."
  if [[ -f /etc/supervisor/conf.d/amoobot-worker.conf ]]; then
    log "Removing the old amoobot-worker program: it consumed a non-existent 'async' transport and could never start."
    rm -f /etc/supervisor/conf.d/amoobot-worker.conf
    supervisorctl reread
    supervisorctl update
  fi
fi

########################################
# BACKUPS
########################################

step "15/18" "Installing database backup cron (${BACKUP_CRON}, keep ${BACKUP_RETENTION_DAYS} days)..."

run install -d -m 700 -o root -g root "$BACKUP_DIR"
write_file /etc/cron.d/amoobot-backup 644 root:root --show-diff <<EOF
# Managed by amoobot deploy.sh: nightly MariaDB dump of ${DB_NAME} with rotation (root-owned ${ROOT_SBIN_DIR}/amoobot-backup)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${BACKUP_CRON} root ${ROOT_SBIN_DIR}/amoobot-backup nightly >> /var/log/amoobot-backup.log 2>&1
EOF
# A first nightly dump right away: the hourly freshness check (step 16) would otherwise report "backup NEVER" until
# the first night, and a first backup is worth having anyway.
if (( DRY_RUN )); then
  log "[dry-run] if ${BACKUP_DIR} has no ${DB_NAME}_nightly_*.sql.gz yet: ${ROOT_SBIN_DIR}/amoobot-backup nightly"
elif ! compgen -G "${BACKUP_DIR}/${DB_NAME}_nightly_*.sql.gz" > /dev/null; then
  log "No nightly backup yet: taking the first one now."
  "${ROOT_SBIN_DIR}/amoobot-backup" nightly >> /var/log/amoobot-backup.log 2>&1 \
    || die "The first nightly backup failed; see /var/log/amoobot-backup.log."
fi

########################################
# SCHEDULED JOBS
########################################

step "16/18" "Installing scheduled jobs (systemd timers, run as ${WEB_USER})..."

printf '  %-14s %-12s %-10s %-8s %s\n' "JOB" "SCHEDULE" "LOCK" "TIMEOUT" "COMMAND"
for entry in "${SCHEDULED_JOBS[@]}"; do
  parse_job "$entry"
  printf '  %-14s %-12s %-10s %-8s %s\n' "$JOB_NAME" "$JOB_CALENDAR" "$JOB_GROUP" "${JOB_TIMEOUT}s" "$JOB_COMMAND"
done

run install -d -m 750 -o "$WEB_USER" -g "$WEB_USER" "$JOBS_LOG_DIR"
run install -d -m 755 -o root -g root "$STATE_DIR"
run install -d -m 755 -o "$WEB_USER" -g "$WEB_USER" "$JOBS_STATE_DIR"
units_changed=0
write_file /etc/tmpfiles.d/amoobot.conf 644 root:root --show-diff <<EOF
# Managed by amoobot deploy.sh: lock files of the scheduled jobs (bin/scheduled-job.sh)
d ${JOBS_LOCK_DIR} 0750 ${WEB_USER} ${WEB_USER} -
EOF
(( FILE_CHANGED )) && run systemd-tmpfiles --create /etc/tmpfiles.d/amoobot.conf

write_file /etc/logrotate.d/amoobot-jobs 644 root:root --show-diff <<EOF
# Managed by amoobot deploy.sh: output of the scheduled jobs
${JOBS_LOG_DIR}/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    su ${WEB_USER} ${WEB_USER}
    create 0640 ${WEB_USER} ${WEB_USER}
}
EOF

write_file /etc/systemd/system/amoobot-job-alert@.service 644 root:root --show-diff <<EOF
# Managed by amoobot deploy.sh: Telegram alert when a scheduled job unit fails (OnFailure=)
[Unit]
Description=Amoobot: Telegram alert for failed scheduled job %i

[Service]
Type=oneshot
User=${WEB_USER}
Group=${WEB_USER}
Environment=APP_DIR=${APP_DIR}
ExecStart=/bin/bash ${ROOT_LIB_DIR}/scheduled-job-alert.sh failed %i
TimeoutStartSec=60
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectHome=yes
EOF
(( FILE_CHANGED )) && units_changed=1

changed_timers=()
manifest="# Managed by amoobot deploy.sh: read by bin/check-scheduled-jobs.sh
# job max_age_seconds (= 3 x interval + timeout) schedule"
for entry in "${SCHEDULED_JOBS[@]}"; do
  parse_job "$entry"
  write_file "/etc/systemd/system/amoobot-job-${JOB_NAME}.service" 644 root:root --show-diff < <(render_job_service)
  (( FILE_CHANGED )) && units_changed=1
  write_file "/etc/systemd/system/amoobot-job-${JOB_NAME}.timer" 644 root:root --show-diff < <(render_job_timer)
  (( FILE_CHANGED )) && units_changed=1 && changed_timers+=("amoobot-job-${JOB_NAME}.timer")
  manifest+=$'\n'"${JOB_NAME} $((3 * JOB_INTERVAL + JOB_TIMEOUT)) ${JOB_CALENDAR}"
done
write_file "$JOBS_MANIFEST" 644 root:root --show-diff <<< "$manifest"

# Optional dead-man's switch: each successful job and the nightly backup ping MONITOR_PING_URL. Root-only file
# (anyone with the URL can fake check-ins); systemd reads it for the units, so www-data never needs to.
render_monitor_env() {
  if [[ -n "$MONITOR_PING_URL" ]]; then
    printf '# Managed by amoobot deploy.sh from MONITOR_PING_URL\nAMOOBOT_MONITOR_PING_URL=%s\n' "$MONITOR_PING_URL"
  else
    printf '# Managed by amoobot deploy.sh: MONITOR_PING_URL not set (no pings)\n'
  fi
}
# Not --show-diff: the URL is a credential. systemd re-reads EnvironmentFile on every run, no restart needed.
write_file "$MONITOR_ENV_FILE" 600 root:root < <(render_monitor_env)

# Hourly freshness check as a root-owned timer; its failure is a failed unit + alert.
write_file "/etc/systemd/system/${CHECK_JOBS_UNIT}.service" 644 root:root --show-diff < <(render_check_service)
(( FILE_CHANGED )) && units_changed=1
write_file "/etc/systemd/system/${CHECK_JOBS_UNIT}.timer" 644 root:root --show-diff < <(render_check_timer)
(( FILE_CHANGED )) && units_changed=1 && changed_timers+=("${CHECK_JOBS_UNIT}.timer")

# Jobs removed from SCHEDULED_JOBS: stop and delete their units.
for timer_file in /etc/systemd/system/amoobot-job-*.timer; do
  [[ -e "$timer_file" ]] || continue
  old="${timer_file##*/amoobot-job-}"
  old="${old%.timer}"
  [[ "$job_names" == *" $old "* ]] && continue
  log "Removing job '${old}' (no longer in SCHEDULED_JOBS)."
  run systemctl disable --now "amoobot-job-${old}.timer"
  run rm -f "$timer_file" "/etc/systemd/system/amoobot-job-${old}.service"
  units_changed=1
done

(( units_changed )) && run systemctl daemon-reload

# Resume: release the job locks taken before the checkout, then (re)start every timer.
for fd in "${JOB_LOCK_FDS[@]}"; do
  exec {fd}<&-
done
JOB_LOCK_FDS=()
for entry in "${SCHEDULED_JOBS[@]}"; do
  parse_job "$entry"
  run systemctl enable --now "amoobot-job-${JOB_NAME}.timer"
done
run systemctl enable --now "${CHECK_JOBS_UNIT}.timer"
(( ${#changed_timers[@]} )) && run systemctl restart "${changed_timers[@]}"
JOBS_PAUSED=0
log "Scheduled jobs active. Status: systemctl list-timers 'amoobot-*'   Check: sudo amoobot-check-jobs (also hourly: ${CHECK_JOBS_UNIT}.timer)"
[[ -n "$MONITOR_PING_URL" ]] && log "Dead-man's switch: successful jobs and the nightly backup ping MONITOR_PING_URL (value not shown)."

########################################
# WEBHOOK
########################################

step "17/18" "Registering Telegram webhook (URL secret + secret_token header)..."

# The command reads the secrets from .env.local.php; nothing secret is passed on the command line.
console app:telegram:set-webhook "https://${DOMAIN}"

########################################
# FINAL CHECKS
########################################

step "18/18" "Running final checks..."

run systemctl restart "php${PHP_VERSION}-fpm"
run systemctl reload nginx

if (( ! DRY_RUN )); then
  # As the web user: .env.local.php is PHP owned by the deploy user and must never be executed as root.
  env_check="$(sudo -H -u "$WEB_USER" php -r '$e = require $argv[1]; echo ($e["APP_ENV"] ?? "?"), " ", ($e["APP_DEBUG"] ?? "?");' "$APP_DIR/.env.local.php")"
  [[ "$env_check" == "prod 0" ]] || die ".env.local.php has APP_ENV/APP_DEBUG = '${env_check}', expected 'prod 0'."
  about="$(sudo -H -u "$WEB_USER" php "$APP_DIR/bin/console" about --env=prod 2>/dev/null)"
  grep -qE '^[[:space:]]*Environment[[:space:]]+prod[[:space:]]*$' <<< "$about" || die "Kernel environment is not prod."
  grep -qE '^[[:space:]]*Debug[[:space:]]+false[[:space:]]*$' <<< "$about" || die "Kernel debug is not off."
  log "APP_ENV=prod, APP_DEBUG=0 (plugin testing shortcuts such as demo auto-confirm are therefore disabled)."

  for entry in "${SCHEDULED_JOBS[@]}"; do
    parse_job "$entry"
    systemctl is-active --quiet "amoobot-job-${JOB_NAME}.timer" || die "Timer amoobot-job-${JOB_NAME}.timer is not active."
  done
  systemctl is-active --quiet "${CHECK_JOBS_UNIT}.timer" || die "Timer ${CHECK_JOBS_UNIT}.timer is not active."
  log "All ${#SCHEDULED_JOBS[@]} scheduled job timers and the hourly ${CHECK_JOBS_UNIT}.timer are active."

  for i in 1 2 3 4 5; do
    if curl -fsS --max-time 10 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/healthz" > /dev/null; then
      log "https://${DOMAIN}/healthz is OK."
      break
    fi
    (( i == 5 )) && die "https://${DOMAIN}/healthz did not return 200; see ${APP_DIR}/var/log and /var/log/nginx/amoobot_error.log."
    sleep 3
  done
  # HTTPS works end to end (certificate validated by curl): from now on browsers may be told to use HTTPS only.
  if [[ ! -e "$HSTS_STATE_FILE" ]]; then
    install -d -m 755 "$STATE_DIR"
    touch "$HSTS_STATE_FILE"
    apply_nginx
    log "HTTPS verified: HSTS enabled (Strict-Transport-Security max-age=31536000; state: ${HSTS_STATE_FILE})."
  fi
else
  log "[dry-run] after https://${DOMAIN}/healthz returns 200: enable HSTS (${HSTS_STATE_FILE}) and reload nginx"
fi

console app:plugin:list || true
console app:payment:list-modules || true
console app:payment:list-gateways || true
console app:payment:list-methods || true

########################################
# DONE
########################################

log ""
log "========================================="
log " Amoobot deployment completed$( (( DRY_RUN )) && echo ' (dry run - nothing changed)')"
log "========================================="
log "URL: https://${DOMAIN}"
log "App Dir: ${APP_DIR}"
log "Database: ${DB_NAME}"
log "Release log: ${STATE_DIR}/releases.log"
log "========================================="
