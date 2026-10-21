#!/usr/bin/env bash
#
# Send ONE synthetic Telegram update (a "/start" from a FAKE user id) to the local nginx, the way Telegram would, and
# report the HTTP status and whether the app logged it. Proves nginx -> PHP-FPM -> Symfony routing -> the webhook
# controller (URL secret + secret_token header) -> the update handler. It does NOT prove that Telegram reaches the
# server or that the bot's replies arrive: the final test is a real /start from the admin's own Telegram account.
#
#   sudo /usr/local/lib/amoobot/smoke-webhook.sh <fake-telegram-user-id> [--dry-run]
#
# - Root only (the URL secret and the header token are read from the root-owned env file; never printed, never on a
#   command line). --dry-run prints what would be sent (secret masked) and sends nothing.
# - The fake id must be digits only and must not be TELEGRAM_ADMIN_CHAT_ID. The app treats it like a new user: it creates
#   a bot account for that id, and its reply to the fake chat fails at Telegram ("chat not found"), which is expected.
#   Use an id no real person has, e.g. 999000001.
#
# Exit code: 0 when nginx answered 200 and the update was logged in bot_message_log; 1 otherwise; 2 for usage errors.

set -uo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-/var/www/amoobot}"
ETC_DIR="${AMOOBOT_ETC_DIR:-/etc/amoobot}"
ENV_FILE="${AMOOBOT_ENV_FILE:-$ETC_DIR/.env.prod.local}"
DB_URL_FILE="${AMOOBOT_DB_URL_FILE:-$ETC_DIR/database.env}"
LOCAL_IP="${AMOOBOT_LOCAL_IP:-127.0.0.1}"
LOG_WAIT="${AMOOBOT_SMOKE_LOG_WAIT:-5}"

usage() { sed -n '2,19p' "$0" >&2; exit 2; }
FAKE_ID="" DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help) usage ;;
    -*) printf 'Unknown option: %s\n' "$arg" >&2; exit 2 ;;
    *) [[ -z "$FAKE_ID" ]] || usage; FAKE_ID="$arg" ;;
  esac
done
[[ "$FAKE_ID" =~ ^[1-9][0-9]{4,14}$ ]] || { echo "The fake Telegram user id must be 5-15 digits (e.g. 999000001)." >&2; exit 2; }

[[ "$EUID" -eq 0 ]] || { echo "Run as root: the URL secret and the header token come from ${ENV_FILE} (root-only)." >&2; exit 2; }
[[ -f "$ENV_FILE" && ! -L "$ENV_FILE" && "$(stat -c '%U %a' "$ENV_FILE")" == "root 600" ]] \
  || { echo "${ENV_FILE} must be a regular file owned by root with mode 600." >&2; exit 2; }

env_value() {
  local line key value
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
DOMAIN="$(env_value DOMAIN || true)"
SECRET="$(env_value TELEGRAM_WEBHOOK_SECRET || true)"
TOKEN="$(env_value TELEGRAM_WEBHOOK_SECRET_TOKEN || true)"
ADMIN_ID="$(env_value TELEGRAM_ADMIN_CHAT_ID || true)"
[[ -n "$TOKEN" ]] || TOKEN="$SECRET"   # the app falls back to the URL secret (TelegramWebhookController::effectiveSecretToken)
[[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ && -n "$SECRET" ]] \
  || { echo "DOMAIN or TELEGRAM_WEBHOOK_SECRET missing in ${ENV_FILE}." >&2; exit 2; }
[[ "$FAKE_ID" != "$ADMIN_ID" ]] || { echo "Use a FAKE id, not TELEGRAM_ADMIN_CHAT_ID." >&2; exit 2; }

UPDATE_ID="$(( $(date +%s) * 1000 + RANDOM % 1000 ))"
PAYLOAD="$(printf '{"update_id":%s,"message":{"message_id":1,"date":%s,"chat":{"id":%s,"type":"private"},"from":{"id":%s,"is_bot":false,"first_name":"amoobot-smoke-test"},"text":"/start"}}' \
  "$UPDATE_ID" "$(date +%s)" "$FAKE_ID" "$FAKE_ID")"

echo "Smoke test: one synthetic /start from fake user ${FAKE_ID} (update_id ${UPDATE_ID})"
echo "  POST https://${DOMAIN}/telegram/webhook/*** via ${LOCAL_IP}:443 with the X-Telegram-Bot-Api-Secret-Token header (values not shown)"
if (( DRY_RUN )); then
  echo "  payload: ${PAYLOAD}"
  echo "DRY RUN: nothing sent."
  exit 0
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
cfg_quote() { local v="${1//\\/\\\\}"; printf '"%s"' "${v//\"/\\\"}"; }
started="$(date -u '+%Y-%m-%d %H:%M:%S')"
# URL (with the secret) and header (with the token) reach curl only through its config on stdin.
code="$(printf 'url = %s\nrequest = "POST"\nheader = %s\nheader = "Content-Type: application/json"\ndata = %s\nsilent\nmax-time = 20\noutput = %s\nwrite-out = "%%{http_code}"\nresolve = "%s:443:%s"\n' \
  "$(cfg_quote "https://${DOMAIN}/telegram/webhook/${SECRET}")" "$(cfg_quote "X-Telegram-Bot-Api-Secret-Token: ${TOKEN}")" \
  "$(cfg_quote "$PAYLOAD")" "$(cfg_quote "$TMP/body")" "$DOMAIN" "$LOCAL_IP" | curl -K - 2> /dev/null)"
unset SECRET TOKEN
body="$(head -c 200 "$TMP/body" 2> /dev/null)"

# Did the controller accept and log it? bot_message_log gets one "incoming" row per accepted update.
logged=0
if [[ -r "$SCRIPT_DIR/lib/database-url.sh" && -r "$DB_URL_FILE" ]]; then
  for _ in $(seq 1 "$LOG_WAIT"); do
    n="$( (
      AMOOBOT_DB_URL_FILE="$DB_URL_FILE"; export AMOOBOT_DB_URL_FILE; unset DATABASE_URL
      # shellcheck source=bin/lib/database-url.sh
      source "$SCRIPT_DIR/lib/database-url.sh"
      load_database_url "$APP_DIR" || exit 1
      trap 'rm -f "${DB_OPTS_FILE:-}"' EXIT
      write_db_options_file
      client="$(command -v mariadb || command -v mysql)" || exit 1
      "$client" --defaults-extra-file="$DB_OPTS_FILE" -N -B -e "SELECT COUNT(*) FROM bot_message_log WHERE direction = 'incoming' AND telegram_id = '${FAKE_ID}' AND created_at >= '${started}' - INTERVAL 5 MINUTE AND JSON_EXTRACT(payload, '\$.update_id') = ${UPDATE_ID}" "$DB_NAME"
    ) 2> /dev/null )"
    [[ "$n" =~ ^[0-9]+$ ]] && (( n > 0 )) && { logged=1; break; }
    sleep 1
  done
  db_note=""
else
  db_note=" (cannot check: ${DB_URL_FILE} or lib/database-url.sh missing)"
fi

echo "  HTTP status: ${code:-no response}$([[ "$body" == '{"ok":true}' ]] && echo ' {"ok":true}')"
echo "  logged by the app (bot_message_log, incoming): $( (( logged )) && echo yes || echo "no${db_note}")"
if [[ "$code" == 200 ]] && (( logged )); then
  echo "RESULT: OK - nginx -> PHP-FPM -> Symfony -> webhook controller -> update handler works."
  echo "The bot's reply to the fake chat cannot be delivered (expected). Final test: send /start from the admin's own Telegram account."
  exit 0
fi
case "$code" in
  403) hint="the URL secret or the secret_token header does not match the deployed app (re-run sudo amoobot-deploy --app-only)" ;;
  404) hint="no route: is the nginx site in place and the app deployed?" ;;
  503) hint="maintenance mode is on (or the app cannot reach the database)" ;;
  502|504) hint="nginx cannot reach PHP-FPM (systemctl status php8.4-fpm)" ;;
  200) hint="the app answered but nothing was logged: check ${APP_DIR}/var/log and the database" ;;
  *) hint="no usable answer from nginx on ${LOCAL_IP}:443 (is nginx running, is the certificate valid?)" ;;
esac
echo "RESULT: FAILED - ${hint}"
exit 1
