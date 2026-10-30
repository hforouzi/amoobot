#!/usr/bin/env bash
#
# Confirm that every scheduled Amoobot job ran successfully recently. For people and for monitoring.
#
#   bin/check-scheduled-jobs.sh          (run as root to include the nightly backup check)
#
# Per job (list and max ages from /var/lib/amoobot/scheduled-jobs.conf, written by deploy.sh):
#   OK        last success within the allowed age (3 x interval + timeout)
#   STALE     last success too long ago (repeated failures, skips or a stopped timer)
#   NEVER     no successful run recorded yet
#   INACTIVE  the timer is not active (e.g. a failed deploy left the jobs paused)
#   FAILED    the latest run failed (systemd unit in "failed" state), even if an older success is still recent
# Plus the nightly database backup (newest *_nightly_*.sql.gz younger than 26 h) when the backup dir is readable.
# Deploys: while /var/lib/amoobot/deploy-in-progress is younger than 30 min the paused jobs are reported as PAUSED (not a
# problem); older means a deploy failed or was interrupted (or the server rebooted mid-deploy) and the jobs stay paused:
# a DEPLOY problem (exit 2 -> failed unit -> alert) and a MONITOR_PING_URL /fail ping for "{job}" = deploy.
#
# Exit codes (Nagios/Icinga style): 0 all OK, 2 at least one problem, 3 cannot check (no manifest).

set -uo pipefail

MANIFEST="${AMOOBOT_JOBS_MANIFEST:-/var/lib/amoobot/scheduled-jobs.conf}"
STATE_DIR="${AMOOBOT_JOBS_STATE_DIR:-/var/lib/amoobot/jobs}"
SYSTEMCTL="${AMOOBOT_SYSTEMCTL:-systemctl}"
BACKUP_DIR="${AMOOBOT_BACKUP_DIR:-/var/backups/amoobot}"
BACKUP_MAX_AGE="${AMOOBOT_BACKUP_MAX_AGE:-93600}"
DEPLOY_MARKER="${AMOOBOT_DEPLOY_MARKER:-/var/lib/amoobot/deploy-in-progress}"
DEPLOY_MAX_AGE="${AMOOBOT_DEPLOY_MAX_AGE:-1800}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ ! -r "$MANIFEST" ]]; then
  echo "UNKNOWN: ${MANIFEST} not found or not readable (has deploy.sh installed the scheduled jobs?)"
  exit 3
fi

now="$(date +%s)"
deploy_state=""
if [[ -e "$DEPLOY_MARKER" ]]; then
  deploy_age=$((now - $(stat -c %Y "$DEPLOY_MARKER")))
  if (( deploy_age > DEPLOY_MAX_AGE )); then deploy_state="stuck"; else deploy_state="running"; fi
fi

human() {
  local s="$1"
  if (( s < 120 )); then printf '%ss' "$s"
  elif (( s < 7200 )); then printf '%sm' "$((s / 60))"
  elif (( s < 172800 )); then printf '%sh%sm' "$((s / 3600))" "$((s % 3600 / 60))"
  else printf '%sd%sh' "$((s / 86400))" "$((s % 86400 / 3600))"
  fi
}

row() { printf '%-9s %-16s %-13s %-9s %s\n' "$@"; }

row STATUS JOB LAST-SUCCESS MAX-AGE NOTES
total=0
problems=0
while read -r job max_age schedule _; do
  [[ -z "${job:-}" || "$job" == \#* ]] && continue
  if [[ ! "$job" =~ ^[a-z0-9-]+$ || ! "${max_age:-}" =~ ^[0-9]+$ ]]; then
    echo "UNKNOWN: malformed line in ${MANIFEST}: ${job}"
    exit 3
  fi
  total=$((total + 1))
  status="OK"
  notes=("schedule ${schedule:-?}")

  stamp="$STATE_DIR/$job.last-success"
  if [[ -e "$stamp" ]]; then
    age=$((now - $(stat -c %Y "$stamp")))
    last="$(human "$age") ago"
    (( age > max_age )) && status="STALE"
  else
    last="-"
    status="NEVER"
  fi
  if [[ -r "$STATE_DIR/$job.last-run" ]]; then
    rc_last="$(grep -oE 'rc=[0-9]+' "$STATE_DIR/$job.last-run" | head -1)"
    [[ -n "$rc_last" ]] && notes+=("last run ${rc_last}")
    # reset-failed (or a subsequent lock skip) must not hide a failed command recorded on disk.
    if [[ -n "$rc_last" && "$rc_last" != 'rc=0' ]]; then
      status="FAILED"
    fi
  fi
  if ! "$SYSTEMCTL" is-active --quiet "amoobot-job-${job}.timer"; then
    status="INACTIVE"; notes+=("timer not active")
  fi
  if "$SYSTEMCTL" is-failed --quiet "amoobot-job-${job}.service"; then
    status="FAILED"; notes+=("unit failed: journalctl -u amoobot-job-${job}")
  fi

  # A deploy that is still within its time pauses the jobs on purpose; a failed latest run still counts.
  if [[ "$deploy_state" == running && "$status" =~ ^(STALE|NEVER|INACTIVE)$ ]]; then
    status="PAUSED"; notes+=("deploy in progress")
  fi
  [[ "$status" == "OK" || "$status" == "PAUSED" ]] || problems=$((problems + 1))
  row "$status" "$job" "$last" "$(human "$max_age")" "$(IFS=';'; echo "${notes[*]}" | sed 's/;/; /g')"
done < "$MANIFEST"

if (( total == 0 )); then
  echo "UNKNOWN: ${MANIFEST} contains no scheduled jobs."
  exit 3
fi

if [[ -d "$BACKUP_DIR" ]]; then
  if [[ -r "$BACKUP_DIR" && -x "$BACKUP_DIR" ]]; then
    total=$((total + 1))
    newest="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name '*_nightly_*.sql.gz' -printf '%T@ %f\n' 2>/dev/null | sort -rn | head -1)"
    if [[ -z "$newest" ]]; then
      problems=$((problems + 1))
      row NEVER backup - "$(human "$BACKUP_MAX_AGE")" "no nightly dump in ${BACKUP_DIR}; see /var/log/amoobot-backup.log"
    else
      age=$((now - ${newest%%.*}))
      if (( age > BACKUP_MAX_AGE )); then
        problems=$((problems + 1))
        row STALE backup "$(human "$age") ago" "$(human "$BACKUP_MAX_AGE")" "newest ${newest#* }; see /var/log/amoobot-backup.log"
      else
        row OK backup "$(human "$age") ago" "$(human "$BACKUP_MAX_AGE")" "newest ${newest#* }"
      fi
    fi
  else
    row SKIPPED backup - - "${BACKUP_DIR} not readable; run as root to check backups"
  fi
fi

if [[ -n "$deploy_state" ]]; then
  if [[ "$deploy_state" == stuck ]]; then
    total=$((total + 1)); problems=$((problems + 1))
    row DEPLOY deploy "started $(human "$deploy_age") ago" "$(human "$DEPLOY_MAX_AGE")" \
      "a deploy failed or was interrupted: jobs stay paused, webhook/payment may answer 503; re-run sudo amoobot-deploy --app-only"
    if [[ -r "$SCRIPT_DIR/lib/monitor-ping.sh" ]]; then
      # shellcheck source=bin/lib/monitor-ping.sh
      source "$SCRIPT_DIR/lib/monitor-ping.sh"
      monitor_ping deploy fail > /dev/null
    fi
  else
    row PAUSED deploy "started $(human "$deploy_age") ago" "$(human "$DEPLOY_MAX_AGE")" "deploy in progress (${DEPLOY_MARKER})"
  fi
fi

echo
if (( problems )); then
  echo "CRITICAL: ${problems} of ${total} jobs need attention."
  exit 2
fi
echo "OK: all ${total} jobs ran successfully within their allowed age."
exit 0
