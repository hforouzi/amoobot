# shellcheck shell=bash
# Shared by bin/scheduled-job.sh and bin/backup-database.sh (sourced, not executed).
#
# monitor_ping NAME [fail]   pings MONITOR_PING_URL after a successful run (with "fail": its /fail URL, used by the
#                     hourly check when a deploy has left the jobs paused), for a dead-man's-switch monitor (healthchecks.io,
#                     Uptime Kuma push, Cronitor, ...) that alerts when pings STOP. "{job}" in the URL is replaced by NAME,
#                     so each job can have its own check. Optional: without a URL nothing happens. A failed ping is
#                     reported and ignored; it never fails the job.
#
# The URL comes from AMOOBOT_MONITOR_PING_URL (systemd passes it to the job units via EnvironmentFile=), else from
# AMOOBOT_MONITOR_FILE (default /etc/amoobot/monitor.env, root-owned, written by deploy.sh). It is handed to curl
# through a config on stdin, never on the command line: whoever knows the URL can fake check-ins.

monitor_ping() {
  local name="$1" fail="${2:-}" url="${AMOOBOT_MONITOR_PING_URL:-}" file="${AMOOBOT_MONITOR_FILE:-/etc/amoobot/monitor.env}" line
  if [[ -z "$url" && -r "$file" ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
      [[ "$line" == AMOOBOT_MONITOR_PING_URL=* ]] && url="${line#AMOOBOT_MONITOR_PING_URL=}"
    done < "$file"
  fi
  [[ -n "$url" ]] || return 0
  command -v curl > /dev/null 2>&1 || { printf 'monitor ping skipped: curl not installed\n'; return 0; }
  url="${url//\{job\}/$name}"
  # Failure signal (healthchecks.io convention: <check URL>/fail, before any query string).
  if [[ "$fail" == fail ]]; then
    if [[ "$url" == *\?* ]]; then url="${url%%\?*}/fail?${url#*\?}"; else url="${url%/}/fail"; fi
  fi
  url="${url//\\/\\\\}"
  url="${url//\"/\\\"}"
  if printf 'url = "%s"\nmax-time = 10\nretry = 2\nsilent\nshow-error\nfail\noutput = "/dev/null"\n' "$url" | curl -K - 2> /dev/null; then
    printf 'monitor ping%s sent for %s\n' "${fail:+ ($fail)}" "$name"
  else
    printf 'monitor ping%s for %s failed (ignored)\n' "${fail:+ ($fail)}" "$name"
  fi
  return 0
}
