#!/usr/bin/env bash
#
# Amoobot server provisioning + deploy for Ubuntu 24.04. Safe to run repeatedly (first deploy and updates).
#
#   sudo ./deploy.sh [--dry-run] [--app-only] [--ref <branch|tag|commit>] [--env-file <path>]
#
#   --dry-run    print what would be done; changes nothing (does not need root)
#   --app-only   skip server provisioning (steps 1-6); only update code, env, DB, cache, proxy, webhook
#   --ref        what to deploy (default: DEPLOY_REF from the env file, else "main"); also used for rollback
#   --env-file   server-only settings/secrets file (default: /etc/amoobot/.env.prod.local, root-owned, chmod 600)
#
# This file contains NO secrets. Every secret comes from the environment or the env file; see .env.prod.example
# and docs/fa/deployment.md. Secret values are never printed, not even in --dry-run.

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

# Keys used only by this script; never written into the application's env.
DEPLOY_ONLY_KEYS=" DOMAIN LETSENCRYPT_EMAIL REPO_URL APP_DIR PHP_VERSION DEPLOY_USER DEPLOY_REF DEPLOY_SSH_PUBLIC_KEY "
DEPLOY_ONLY_KEYS+="SSH_PERMIT_ROOT_LOGIN DB_NAME DB_USER DB_PASSWORD BACKUP_DIR BACKUP_RETENTION_DAYS BACKUP_CRON "
# Application keys that may also come from the process environment instead of the env file.
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

# write_file PATH MODE OWNER:GROUP   content on stdin; only replaces the file when the content changed.
# Sets FILE_CHANGED=1/0. In --dry-run only reports changed/unchanged, never the content.
FILE_CHANGED=0
write_file() {
  local path="$1" mode="$2" owner="$3" tmp state
  tmp="$(mktemp)"
  cat > "$tmp"
  if [[ -f "$path" ]] && cmp -s "$tmp" "$path" 2>/dev/null; then
    FILE_CHANGED=0; state="unchanged"
  else
    FILE_CHANGED=1; state="changed"
  fi
  if (( DRY_RUN )); then
    printf '[dry-run] write %s (%s %s) - %s\n' "$path" "$mode" "$owner" "$state"
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
    -h|--help)  sed -n '2,13p' "$0"; exit 0 ;;
    *)          die "Unknown option: $1 (see --help)" ;;
  esac
  shift
done

########################################
# ROOT CHECK
########################################

if (( ! DRY_RUN )) && [[ "$EUID" -ne 0 ]]; then
  die "Please run as root (sudo ./deploy.sh). Use --dry-run to preview without root."
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
  printf 'Missing required settings (set them in %s or the environment):\n' "$ENV_FILE" >&2
  printf '  - %s\n' "${MISSING[@]}" >&2
  die "${#MISSING[@]} required setting(s) missing."
fi

[[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ && "$DOMAIN" == *.* ]] || die "DOMAIN is not a valid hostname."
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "DB_NAME / DB_USER may only contain A-Z a-z 0-9 _."
[[ "$DEPLOY_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$DEPLOY_USER" != "root" ]] || die "DEPLOY_USER must be a non-root user name."
[[ "$SSH_PERMIT_ROOT_LOGIN" =~ ^(no|prohibit-password)$ ]] || die "SSH_PERMIT_ROOT_LOGIN must be 'no' or 'prohibit-password'."
[[ "$BACKUP_RETENTION_DAYS" =~ ^[0-9]+$ ]] || die "BACKUP_RETENTION_DAYS must be a number."
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

step "1/17" "Updating system..."
run apt-get update
run apt-get upgrade -y

########################################
# INSTALL PACKAGES
########################################

step "2/17" "Installing packages..."
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
  gzip \
  certbot \
  python3-certbot-nginx

########################################
# INSTALL PHP
########################################

step "3/17" "Installing PHP ${PHP_VERSION}..."
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

step "4/17" "Installing Composer..."
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

step "5/17" "Hardening server (deploy user, SSH key-only, firewall 22/80/443, restart policies)..."

if ! id "$DEPLOY_USER" > /dev/null 2>&1; then
  run useradd --create-home --shell /bin/bash "$DEPLOY_USER"
fi
DEPLOY_HOME="$(getent passwd "$DEPLOY_USER" | cut -d: -f6 || true)"
DEPLOY_HOME="${DEPLOY_HOME:-/home/$DEPLOY_USER}"
run install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$DEPLOY_HOME/.ssh"
auth_keys="$DEPLOY_HOME/.ssh/authorized_keys"
if [[ -n "${DEPLOY_SSH_PUBLIC_KEY:-}" ]] && ! grep -qxF "$DEPLOY_SSH_PUBLIC_KEY" "$auth_keys" 2>/dev/null; then
  if (( DRY_RUN )); then log "[dry-run] add DEPLOY_SSH_PUBLIC_KEY to ${auth_keys}"; else printf '%s\n' "$DEPLOY_SSH_PUBLIC_KEY" >> "$auth_keys"; fi
fi
if [[ ! -s "$auth_keys" && -s /root/.ssh/authorized_keys ]]; then
  log "Copying root's authorized SSH keys to ${DEPLOY_USER}."
  run cp /root/.ssh/authorized_keys "$auth_keys"
fi
[[ -e "$auth_keys" ]] && run chown "$DEPLOY_USER:$DEPLOY_USER" "$auth_keys" && run chmod 600 "$auth_keys"

# Key-only SSH. Refuse if that would lock everybody out.
if [[ ! -s "$auth_keys" && ! -s /root/.ssh/authorized_keys ]] && (( ! DRY_RUN )); then
  die "No SSH public key for ${DEPLOY_USER} or root. Set DEPLOY_SSH_PUBLIC_KEY first; disabling password login now would lock you out."
fi
# 00- so it is read before cloud-init's 50-cloud-init.conf (first value wins in sshd).
write_file /etc/ssh/sshd_config.d/00-amoobot-hardening.conf 644 root:root <<EOF
# Managed by amoobot deploy.sh
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitEmptyPasswords no
PermitRootLogin ${SSH_PERMIT_ROOT_LOGIN}
EOF
if (( FILE_CHANGED && ! DRY_RUN )); then
  sshd -t || die "sshd config test failed; fix /etc/ssh/sshd_config.d before reconnecting."
  systemctl try-reload-or-restart ssh
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

step "6/17" "Configuring MariaDB and database..."

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

step "7/17" "Fetching code (${DEPLOY_REF})..."

id "$DEPLOY_USER" > /dev/null 2>&1 || (( DRY_RUN )) || die "Deploy user ${DEPLOY_USER} does not exist; run once without --app-only."
DEPLOY_HOME="$(getent passwd "$DEPLOY_USER" | cut -d: -f6 || true)"
DEPLOY_HOME="${DEPLOY_HOME:-/home/$DEPLOY_USER}"
DEPLOY_KEY="$DEPLOY_HOME/.ssh/amoobot_deploy_ed25519"
GIT_SSH="ssh -i ${DEPLOY_KEY} -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"

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
  log "[dry-run] check out ${DEPLOY_REF} (origin/${DEPLOY_REF}, a tag, or a commit)"
else
  TARGET_COMMIT="$(sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" rev-parse --verify -q "origin/${DEPLOY_REF}^{commit}" \
    || sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" rev-parse --verify -q "${DEPLOY_REF}^{commit}" \
    || true)"
  [[ -n "$TARGET_COMMIT" ]] || die "Ref '${DEPLOY_REF}' not found on origin (branch, tag or commit)."
  sudo -H -u "$DEPLOY_USER" git -C "$APP_DIR" checkout -q --detach "$TARGET_COMMIT"
  install -d -m 755 "$STATE_DIR"
  printf '%s %s -> %s (%s)\n' "$(date -u +%FT%TZ)" "${PREVIOUS_COMMIT:-none}" "$TARGET_COMMIT" "$DEPLOY_REF" >> "$STATE_DIR/releases.log"
  log "Deploying ${TARGET_COMMIT} (previous: ${PREVIOUS_COMMIT:-none}). History: ${STATE_DIR}/releases.log"
fi

cd "$APP_DIR" 2>/dev/null || (( DRY_RUN )) || die "Cannot enter ${APP_DIR}."

########################################
# INSTALL DEPENDENCIES
########################################

step "8/17" "Installing Composer dependencies (production)..."
# Scripts run later as the web user (cache:clear / assets:install need var/ and public/bundles).
as "$DEPLOY_USER" composer install --working-dir="$APP_DIR" --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts

########################################
# ENV FILE
########################################

step "9/17" "Writing production env (.env.prod.local -> .env.local.php)..."

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
# Replaces the old .env.local (which held secrets); now only the non-secret environment switch.
printf 'APP_ENV=prod\nAPP_DEBUG=0\n' | write_file "$APP_DIR/.env.local" 640 "$DEPLOY_USER:$WEB_USER"
as "$DEPLOY_USER" composer dump-env prod --working-dir="$APP_DIR" --no-interaction
run chown "$DEPLOY_USER:$WEB_USER" "$APP_DIR/.env.local.php"
run chmod 640 "$APP_DIR/.env.local.php"

########################################
# PERMISSIONS
########################################

step "10/17" "Setting permissions..."

run install -d -o "$WEB_USER" -g "$WEB_USER" "$APP_DIR/var" "$APP_DIR/var/plugins" "$APP_DIR/var/plugin_uploads" "$APP_DIR/var/log" "$APP_DIR/public/bundles"
run chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/var" "$APP_DIR/public/bundles"
run chmod -R 775 "$APP_DIR/var"
# Code: owned by the deploy user, readable (not writable) by PHP-FPM, closed to others.
run chmod -R g+rX,o-rwx "$APP_DIR"
run chmod 600 "$APP_DIR/.env.prod.local"

########################################
# CACHE & MIGRATIONS
########################################

step "11/17" "Clearing cache, running migrations..."

# Fresh cache first: a prod container left over from the previous release must not run the new migrations.
run rm -rf "$APP_DIR/var/cache/prod"
console cache:warmup
console assets:install public

if (( ! DRY_RUN )) && [[ -n "${PREVIOUS_COMMIT:-}" ]] && ! console doctrine:migrations:up-to-date > /dev/null 2>&1; then
  log "Pending migrations: taking a pre-migration backup first..."
  # The backup script finds the app from its own location and reads DATABASE_URL from .env.local.php.
  BACKUP_DIR="$BACKUP_DIR" BACKUP_RETENTION_DAYS="$BACKUP_RETENTION_DAYS" BACKUP_LABEL="pre-deploy" \
    bash "$APP_DIR/bin/backup-database.sh"
fi
# No "|| true": a failed migration stops the deploy.
console doctrine:migrations:migrate --allow-no-migration
console doctrine:migrations:up-to-date
console lint:container

########################################
# NGINX CONFIG
########################################

step "12/17" "Configuring Nginx..."

CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"

# The application locations; shared by the HTTP-only bootstrap config and the HTTPS config.
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

    location ~ ^/index\\.php(/|\$) {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_split_path_info ^(.+\\.php)(/.*)\$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        internal;
    }

    location ~ \\.php\$ {
        return 404;
    }

    location ~ /\\. {
        deny all;
    }

    access_log /var/log/nginx/amoobot_access.log;
    error_log /var/log/nginx/amoobot_error.log;
EOF
}

render_nginx() {
  if [[ -f "$CERT_DIR/fullchain.pem" ]]; then
    cat <<EOF
# Managed by amoobot deploy.sh
server {
    listen 80;
    server_name ${DOMAIN};
    server_tokens off;

    # Inside a location (not at server level) so certbot's ACME challenge location still wins on renewal.
    location / {
        return 301 https://\$host\$request_uri;
    }
}

server {
    listen 443 ssl;
    server_name ${DOMAIN};

    ssl_certificate ${CERT_DIR}/fullchain.pem;
    ssl_certificate_key ${CERT_DIR}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:amoobot_ssl:10m;
    ssl_session_timeout 1d;

$(app_locations)
}
EOF
  else
    cat <<EOF
# Managed by amoobot deploy.sh (HTTP only until the certificate exists)
server {
    listen 80;
    server_name ${DOMAIN};

$(app_locations)
}
EOF
  fi
}

apply_nginx() {
  render_nginx | write_file /etc/nginx/sites-available/amoobot 644 root:root
  run ln -sf /etc/nginx/sites-available/amoobot /etc/nginx/sites-enabled/amoobot
  run rm -f /etc/nginx/sites-enabled/default
  run nginx -t
  run systemctl reload nginx
}
apply_nginx

########################################
# SSL
########################################

step "13/17" "Ensuring HTTPS certificate (Let's Encrypt, auto-renewing)..."

# certonly: this script owns the nginx config; certbot only obtains/renews the certificate (certbot.timer).
run certbot certonly --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LETSENCRYPT_EMAIL" \
  --keep-until-expiring --deploy-hook "systemctl reload nginx"
apply_nginx
run systemctl enable --now certbot.timer

########################################
# SUPERVISOR
########################################

step "14/17" "Configuring Supervisor..."

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

step "15/17" "Installing database backup cron (${BACKUP_CRON}, keep ${BACKUP_RETENTION_DAYS} days)..."

run install -d -m 700 -o root -g root "$BACKUP_DIR"
write_file /etc/cron.d/amoobot-backup 644 root:root <<EOF
# Managed by amoobot deploy.sh: nightly MariaDB dump of ${DB_NAME} with rotation
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
${BACKUP_CRON} root APP_DIR=${APP_DIR} BACKUP_DIR=${BACKUP_DIR} BACKUP_RETENTION_DAYS=${BACKUP_RETENTION_DAYS} bash ${APP_DIR}/bin/backup-database.sh >> /var/log/amoobot-backup.log 2>&1
EOF

########################################
# WEBHOOK
########################################

step "16/17" "Registering Telegram webhook (URL secret + secret_token header)..."

# The command reads the secrets from .env.local.php; nothing secret is passed on the command line.
console app:telegram:set-webhook "https://${DOMAIN}"

########################################
# FINAL CHECKS
########################################

step "17/17" "Running final checks..."

run systemctl restart "php${PHP_VERSION}-fpm"
run systemctl reload nginx

if (( ! DRY_RUN )); then
  env_check="$(php -r '$e = require $argv[1]; echo ($e["APP_ENV"] ?? "?"), " ", ($e["APP_DEBUG"] ?? "?");' "$APP_DIR/.env.local.php")"
  [[ "$env_check" == "prod 0" ]] || die ".env.local.php has APP_ENV/APP_DEBUG = '${env_check}', expected 'prod 0'."
  about="$(sudo -H -u "$WEB_USER" php "$APP_DIR/bin/console" about --env=prod 2>/dev/null)"
  grep -qE '^[[:space:]]*Environment[[:space:]]+prod[[:space:]]*$' <<< "$about" || die "Kernel environment is not prod."
  grep -qE '^[[:space:]]*Debug[[:space:]]+false[[:space:]]*$' <<< "$about" || die "Kernel debug is not off."
  log "APP_ENV=prod, APP_DEBUG=0 (plugin testing shortcuts such as demo auto-confirm are therefore disabled)."

  for i in 1 2 3 4 5; do
    if curl -fsS --max-time 10 --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/healthz" > /dev/null; then
      log "https://${DOMAIN}/healthz is OK."
      break
    fi
    (( i == 5 )) && die "https://${DOMAIN}/healthz did not return 200; see ${APP_DIR}/var/log and /var/log/nginx/amoobot_error.log."
    sleep 3
  done
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
