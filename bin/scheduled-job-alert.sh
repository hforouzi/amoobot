#!/usr/bin/env bash
#
# Telegram alert for scheduled Amoobot jobs (bin/scheduled-job.sh).
#
#   bin/scheduled-job-alert.sh failed <job | amoobot-job-<job> | amoobot-<unit>>   # amoobot-job-alert@.service (OnFailure=)
#   bin/scheduled-job-alert.sh recovered <job>                      # from scheduled-job.sh after a successful run
#
# Sends a short plain-text message to TELEGRAM_ADMIN_CHAT_ID: job name, host, systemd result, last success and where
# to look. Job output is never included (it may contain customer data). A failing job is reported at most once per
# ALERT_REPEAT_MINUTES (default 60) until it succeeds again.
# The bot token is read from APP_DIR/.env.local.php and handed to curl through a config on stdin, never on argv.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(dirname "$SCRIPT_DIR")}"
STATE_DIR="${AMOOBOT_JOBS_STATE_DIR:-/var/lib/amoobot/jobs}"
LOG_DIR="${AMOOBOT_JOBS_LOG_DIR:-/var/log/amoobot}"
REPEAT_MINUTES="${ALERT_REPEAT_MINUTES:-60}"
TELEGRAM_API="${AMOOBOT_TELEGRAM_API:-https://api.telegram.org}"
SYSTEMCTL="${AMOOBOT_SYSTEMCTL:-systemctl}"

die() { printf 'scheduled-job-alert: %s\n' "$*" >&2; exit 1; }

kind="${1:-}"; arg="${2:-}"
case "$arg" in
  amoobot-job-*) job="${arg#amoobot-job-}"; unit="$arg" ;;
  amoobot-*)     job="$arg"; unit="$arg" ;;   # other units, e.g. amoobot-check-jobs (bin/check-scheduled-jobs.sh timer)
  *)             job="$arg"; unit="amoobot-job-$arg" ;;
esac
[[ "$kind" == "failed" || "$kind" == "recovered" ]] || die "usage: $0 failed|recovered <job>"
[[ "$job" =~ ^[a-z0-9-]+$ ]] || die "invalid job name"
[[ "$REPEAT_MINUTES" =~ ^[0-9]+$ ]] || die "ALERT_REPEAT_MINUTES must be a number"
mkdir -p "$STATE_DIR"
alerted="$STATE_DIR/$job.alerted"

if [[ "$kind" == "failed" && -e "$alerted" ]] && [[ -n "$(find "$alerted" -mmin -"$REPEAT_MINUTES" 2>/dev/null)" ]]; then
  echo "[$job] failure already alerted within ${REPEAT_MINUTES} min; not sending again."
  exit 0
fi

host="$(hostname)"
now="$(date -u '+%Y-%m-%d %H:%M UTC')"
last_success="never"
if [[ -e "$STATE_DIR/$job.last-success" ]]; then
  last_success="$(date -u -r "$STATE_DIR/$job.last-success" '+%Y-%m-%d %H:%M UTC')"
fi

if [[ "$kind" == "failed" ]]; then
  result="$("$SYSTEMCTL" show "${unit}.service" -p Result --value 2>/dev/null || true)"
  text="⚠️ Amoobot scheduled job FAILED: ${job}
host: ${host}
time: ${now}
result: ${result:-unknown}
last success: ${last_success}

journalctl -u ${unit} -n 50
${LOG_DIR}/${job}.log"
else
  text="✅ Amoobot scheduled job recovered: ${job}
host: ${host}
time: ${now}"
fi

# Settings come back base64-encoded, one per line, so no value is ever interpreted by the shell.
token=""; chat=""; proxy=""
while IFS= read -r line; do
  value="$(printf '%s' "${line#*=}" | base64 --decode)"
  case "${line%%=*}" in
    token) token="$value" ;;
    chat)  chat="$value" ;;
    proxy) proxy="$value" ;;
  esac
done < <(php -r '
  $f = $argv[1]."/.env.local.php";
  $e = is_readable($f) ? require $f : [];
  foreach (["token" => "TELEGRAM_BOT_TOKEN", "chat" => "TELEGRAM_ADMIN_CHAT_ID", "proxy" => "TELEGRAM_PROXY"] as $k => $v) {
      echo $k, "=", base64_encode((string) ($e[$v] ?? "")), "\n";
  }
' "$APP_DIR")

[[ -n "$token" && -n "$chat" ]] || die "TELEGRAM_BOT_TOKEN / TELEGRAM_ADMIN_CHAT_ID not configured in ${APP_DIR}/.env.local.php; alert not sent."

# curl config strings: double-quoted, with \ " and newlines escaped.
cfg_quote() {
  local v="${1//\\/\\\\}"
  v="${v//\"/\\\"}"
  v="${v//$'\n'/\\n}"
  printf '"%s"' "$v"
}
{
  printf 'url = %s\n' "$(cfg_quote "${TELEGRAM_API}/bot${token}/sendMessage")"
  printf 'data-urlencode = %s\n' "$(cfg_quote "chat_id=${chat}")"
  printf 'data-urlencode = %s\n' "$(cfg_quote "text=${text}")"
  printf 'data-urlencode = %s\n' "$(cfg_quote "disable_web_page_preview=true")"
  [[ -n "$proxy" ]] && printf 'proxy = %s\n' "$(cfg_quote "$proxy")"
  printf 'max-time = 20\nsilent\nshow-error\nfail\n'
} | curl -K - > /dev/null || die "Telegram API request failed; alert for ${job} not delivered."

if [[ "$kind" == "failed" ]]; then
  touch "$alerted"
else
  rm -f "$alerted"
fi
echo "[$job] ${kind} alert sent to the admin chat."
