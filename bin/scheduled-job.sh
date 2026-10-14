#!/usr/bin/env bash
#
# Run one scheduled Amoobot job. Called by the systemd unit amoobot-job-<name>.service that deploy.sh installs.
#
#   bin/scheduled-job.sh <name> <lock-group> -- <command...>
#
# - Jobs sharing a lock group never run at the same time (flock). If the lock stays busy for LOCK_WAIT seconds the
#   run is skipped (exit 0, logged); bin/check-scheduled-jobs.sh flags a job whose last success is too old.
# - Full output is appended to LOG_DIR/<name>.log (rotated by /etc/logrotate.d/amoobot-jobs); one summary line goes
#   to the journal.
# - The exit code is the command's, so a failing job is a failed systemd unit (OnFailure= sends the Telegram alert).
# - On success STATE_DIR/<name>.last-success is touched; if a failure alert was sent earlier, a recovery notice follows,
#   and the optional MONITOR_PING_URL is pinged (bin/lib/monitor-ping.sh).
# - While DEPLOY_MARKER exists (a deploy is updating code/cache/schema, or one was interrupted, e.g. by a reboot) the
#   job does not run: exit 0, logged, no success stamp and no ping, so bin/check-scheduled-jobs.sh reports it.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="${AMOOBOT_JOBS_LOG_DIR:-/var/log/amoobot}"
STATE_DIR="${AMOOBOT_JOBS_STATE_DIR:-/var/lib/amoobot/jobs}"
LOCK_DIR="${AMOOBOT_JOBS_LOCK_DIR:-/run/amoobot}"
LOCK_WAIT="${AMOOBOT_JOBS_LOCK_WAIT:-120}"
ALERT_SCRIPT="${AMOOBOT_JOBS_ALERT_SCRIPT:-$SCRIPT_DIR/scheduled-job-alert.sh}"
DEPLOY_MARKER="${AMOOBOT_DEPLOY_MARKER:-/var/lib/amoobot/deploy-in-progress}"

die() { printf 'scheduled-job: %s\n' "$*" >&2; exit 2; }
# shellcheck source=bin/lib/monitor-ping.sh
source "$SCRIPT_DIR/lib/monitor-ping.sh" || die "cannot load $SCRIPT_DIR/lib/monitor-ping.sh"
ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }

(( $# >= 4 )) && [[ "$3" == "--" ]] || die "usage: $0 <name> <lock-group> -- <command...>"
name="$1"; group="$2"; shift 3
[[ "$name" =~ ^[a-z0-9-]+$ && "$group" =~ ^[a-z0-9-]+$ ]] || die "name and lock group may only contain a-z 0-9 -"
[[ "$LOCK_WAIT" =~ ^[0-9]+$ ]] || die "AMOOBOT_JOBS_LOCK_WAIT must be a number of seconds"

mkdir -p "$LOG_DIR" "$STATE_DIR" "$LOCK_DIR" || die "cannot create log/state/lock directories"
log="$LOG_DIR/$name.log"

exec 8>"$LOCK_DIR/$group.lock" || die "cannot open lock file $LOCK_DIR/$group.lock"
if ! flock -w "$LOCK_WAIT" 8; then
  printf '%s SKIPPED %s: lock "%s" still busy after %ss\n' "$(ts)" "$name" "$group" "$LOCK_WAIT" >> "$log"
  printf '[%s] skipped: lock "%s" busy after %ss (another job or a deploy is running)\n' "$name" "$group" "$LOCK_WAIT"
  exit 0
fi

# Checked with the lock held: a deploy writes the marker before it takes the locks and removes it only when the code,
# cache and schema match again. After a reboot in the middle of a deploy the timers start, but the jobs stay idle here.
if [[ -e "$DEPLOY_MARKER" ]]; then
  printf '%s SKIPPED %s: deploy in progress or interrupted (%s)\n' "$(ts)" "$name" "$DEPLOY_MARKER" >> "$log"
  printf '[%s] skipped: a deploy is in progress or was interrupted (%s); re-run sudo amoobot-deploy --app-only\n' "$name" "$DEPLOY_MARKER"
  exit 0
fi

start=$SECONDS
printf '===== %s START %s: %s\n' "$(ts)" "$name" "$*" >> "$log"
"$@" >> "$log" 2>&1
rc=$?
duration=$((SECONDS - start))
printf '===== %s END %s rc=%s duration=%ss\n' "$(ts)" "$name" "$rc" "$duration" >> "$log"
printf '%s rc=%s duration=%ss\n' "$(ts)" "$rc" "$duration" > "$STATE_DIR/$name.last-run"

if (( rc == 0 )); then
  touch "$STATE_DIR/$name.last-success"
  if [[ -e "$STATE_DIR/$name.alerted" ]]; then
    bash "$ALERT_SCRIPT" recovered "$name" || printf '[%s] recovery notice could not be sent\n' "$name"
  fi
  # Dead-man's switch (optional MONITOR_PING_URL): only successful runs ping, so failures AND skips go quiet.
  monitor_ping "$name"
fi

printf '[%s] rc=%s duration=%ss (output: %s)\n' "$name" "$rc" "$duration" "$log"
exit "$rc"
