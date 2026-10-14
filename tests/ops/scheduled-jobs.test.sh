#!/usr/bin/env bash
#
# Tests for the scheduled-job tooling: bin/scheduled-job.sh, bin/scheduled-job-alert.sh, bin/check-scheduled-jobs.sh
# and the systemd units rendered by deploy.sh. No root, no systemd changes, no real Telegram calls.
#
#   bash tests/ops/scheduled-jobs.test.sh
#
# Sections are skipped (not failed) when their tools are missing: flock (wrapper), php + curl (alert),
# systemd-analyze (unit validation), logrotate. Run it on Linux/WSL and once where php is available.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"; [[ -n "${SERVER_PID:-}" ]] && kill "$SERVER_PID" 2>/dev/null' EXIT
PASS=0; FAIL=0; SKIP=0
pass() { PASS=$((PASS + 1)); echo "PASS: $*"; }
fail() { FAIL=$((FAIL + 1)); echo "FAIL: $*"; }
skip() { SKIP=$((SKIP + 1)); echo "SKIP: $*"; }
check() { local name="$1"; shift; if "$@"; then pass "$name"; else fail "$name"; fi; }
have() { command -v "$1" > /dev/null 2>&1; }

export AMOOBOT_JOBS_LOG_DIR="$WORK/log" AMOOBOT_JOBS_STATE_DIR="$WORK/state" AMOOBOT_JOBS_LOCK_DIR="$WORK/lock"

echo "== syntax"
for f in bin/scheduled-job.sh bin/scheduled-job-alert.sh bin/check-scheduled-jobs.sh deploy.sh; do
  [[ -e "$ROOT/$f" ]] || { skip "$f not present"; continue; }
  check "bash -n $f" bash -n "$ROOT/$f"
done

echo "== wrapper (bin/scheduled-job.sh)"
if have flock; then
  JOB="$ROOT/bin/scheduled-job.sh"
  printf '#!/usr/bin/env bash\necho "$@" >> "%s/alert-calls"\n' "$WORK" > "$WORK/alert-stub.sh"
  export AMOOBOT_JOBS_ALERT_SCRIPT="$WORK/alert-stub.sh"

  bash "$JOB" ok grp -- bash -c 'echo hello-from-job'; rc=$?
  check "success exits 0" test "$rc" -eq 0
  check "success writes last-success stamp" test -e "$WORK/state/ok.last-success"
  check "output goes to the job log" grep -q hello-from-job "$WORK/log/ok.log"
  check "log has START/END markers with rc" grep -q 'END ok rc=0' "$WORK/log/ok.log"
  check "last-run records rc" grep -q 'rc=0' "$WORK/state/ok.last-run"

  bash "$JOB" bad grp -- bash -c 'echo boom >&2; exit 3' > /dev/null; rc=$?
  check "failure propagates the exit code (failed unit)" test "$rc" -eq 3
  check "failure writes no last-success stamp" test ! -e "$WORK/state/bad.last-success"
  check "stderr is captured in the log" grep -q boom "$WORK/log/bad.log"

  # Lock: a second job in the same group waits, then is skipped (exit 0, nothing run, no stamp).
  bash "$JOB" long grp -- sleep 3 > /dev/null &
  sleep 0.5
  AMOOBOT_JOBS_LOCK_WAIT=1 bash "$JOB" blocked grp -- bash -c 'touch "$0"' "$WORK/blocked-ran" > "$WORK/blocked.out"; rc=$?
  check "busy lock: run skipped with exit 0" test "$rc" -eq 0
  check "busy lock: command did not run" test ! -e "$WORK/blocked-ran"
  check "busy lock: skip is logged" grep -q SKIPPED "$WORK/log/blocked.log"
  check "busy lock: no last-success stamp" test ! -e "$WORK/state/blocked.last-success"
  bash "$JOB" other-group grp2 -- true > /dev/null; rc=$?
  check "other lock group runs in parallel" test "$rc" -eq 0
  wait
  AMOOBOT_JOBS_LOCK_WAIT=5 bash "$JOB" waited grp -- true > /dev/null; rc=$?
  check "lock released after the job ends" test "$rc" -eq 0 -a -e "$WORK/state/waited.last-success"

  touch "$WORK/state/flaky.alerted"
  bash "$JOB" flaky grp -- true > /dev/null
  check "recovery notice after a previously alerted failure" grep -qx 'recovered flaky' "$WORK/alert-calls"

  bash "$JOB" 'Bad Name' grp -- true > /dev/null 2>&1; rc=$?
  check "invalid job name rejected" test "$rc" -eq 2
  bash "$JOB" x grp true > /dev/null 2>&1; rc=$?
  check "missing -- separator rejected" test "$rc" -eq 2
  unset AMOOBOT_JOBS_ALERT_SCRIPT
else
  skip "wrapper tests (flock not installed)"
fi

echo "== alert (bin/scheduled-job-alert.sh)"
if have php && have curl; then
  ALERT="$ROOT/bin/scheduled-job-alert.sh"
  FAKE_TOKEN="123456:FAKE-test-token-$RANDOM$RANDOM"
  mkdir -p "$WORK/app"
  cat > "$WORK/app/.env.local.php" <<EOF
<?php return ['TELEGRAM_BOT_TOKEN' => '${FAKE_TOKEN}', 'TELEGRAM_ADMIN_CHAT_ID' => '-100777', 'TELEGRAM_PROXY' => ''];
EOF
  # Local fake Telegram API: records the request path and the decoded form fields.
  cat > "$WORK/router.php" <<'EOF'
<?php
file_put_contents(getenv('CAPTURE'), json_encode(['path' => $_SERVER['REQUEST_URI'], 'post' => $_POST])."\n", FILE_APPEND);
header('Content-Type: application/json');
echo '{"ok":true}';
EOF
  PORT=$((20000 + RANDOM % 20000))
  CAPTURE="$WORK/capture.jsonl" php -S "127.0.0.1:$PORT" "$WORK/router.php" > /dev/null 2>&1 &
  SERVER_PID=$!
  sleep 1
  printf '#!/usr/bin/env bash\necho exit-code\n' > "$WORK/systemctl-stub"; chmod +x "$WORK/systemctl-stub"
  export APP_DIR="$WORK/app" AMOOBOT_TELEGRAM_API="http://127.0.0.1:$PORT" AMOOBOT_SYSTEMCTL="$WORK/systemctl-stub"

  # Catch the token on any process command line while the alert runs.
  ( for _ in $(seq 1 40); do ps -eo args 2>/dev/null | grep -F -- "$FAKE_TOKEN" | grep -v grep; sleep 0.05; done ) > "$WORK/argv-scan" &
  scan=$!
  bash "$ALERT" failed amoobot-job-sync > "$WORK/alert.out" 2>&1; rc=$?
  wait "$scan"
  check "failure alert sent" test "$rc" -eq 0
  check "message goes to the admin chat" php -r '$l = json_decode(file($argv[1])[0], true); exit($l["post"]["chat_id"] === "-100777" ? 0 : 1);' "$WORK/capture.jsonl"
  check "message names the job and the systemd result" php -r '$t = json_decode(file($argv[1])[0], true)["post"]["text"]; exit(str_contains($t, "FAILED: sync") && str_contains($t, "result: exit-code") ? 0 : 1);' "$WORK/capture.jsonl"
  check "token used only in the API path" php -r '$l = json_decode(file($argv[1])[0], true); exit(str_starts_with($l["path"], "/bot".$argv[2]."/sendMessage") ? 0 : 1);' "$WORK/capture.jsonl" "$FAKE_TOKEN"
  check "token never on a process command line" test ! -s "$WORK/argv-scan"
  check "token not printed" bash -c '! grep -qF -- "$1" "$2"' _ "$FAKE_TOKEN" "$WORK/alert.out"
  check "alerted marker written" test -e "$WORK/state/sync.alerted"

  bash "$ALERT" failed sync > /dev/null 2>&1
  check "repeat failure within the window is throttled" test "$(wc -l < "$WORK/capture.jsonl")" -eq 1
  ALERT_REPEAT_MINUTES=0 bash "$ALERT" failed sync > /dev/null 2>&1
  check "repeat failure after the window is sent again" test "$(wc -l < "$WORK/capture.jsonl")" -eq 2

  bash "$ALERT" recovered sync > /dev/null 2>&1
  check "recovery notice sent" php -r '$t = json_decode(file($argv[1])[2], true)["post"]["text"]; exit(str_contains($t, "recovered: sync") ? 0 : 1);' "$WORK/capture.jsonl"
  check "recovery clears the alerted marker" test ! -e "$WORK/state/sync.alerted"

  # The hourly freshness check (amoobot-check-jobs.service) uses the same OnFailure alert with its own unit name.
  printf '#!/usr/bin/env bash\necho "$*" >> "%s/systemctl.args"\necho exit-code\n' "$WORK" > "$WORK/systemctl-log"; chmod +x "$WORK/systemctl-log"
  AMOOBOT_SYSTEMCTL="$WORK/systemctl-log" bash "$ALERT" failed amoobot-check-jobs > /dev/null 2>&1; rc=$?
  check "alert for a non-job unit (amoobot-check-jobs) is sent" test "$rc" -eq 0
  check "  ...and asks systemd about that unit, not amoobot-job-amoobot-check-jobs" grep -q '^show amoobot-check-jobs.service ' "$WORK/systemctl.args"
  check "  ...and names it with the right journalctl hint" php -r '$t = json_decode(file($argv[1])[3], true)["post"]["text"]; exit(str_contains($t, "FAILED: amoobot-check-jobs") && str_contains($t, "journalctl -u amoobot-check-jobs -n 50") ? 0 : 1);' "$WORK/capture.jsonl"

  printf '<?php return [];\n' > "$WORK/app/.env.local.php"
  bash "$ALERT" failed sync > /dev/null 2>&1; rc=$?
  check "missing Telegram config fails visibly" test "$rc" -ne 0
  unset APP_DIR AMOOBOT_TELEGRAM_API AMOOBOT_SYSTEMCTL
else
  skip "alert tests (php or curl not installed)"
fi

echo "== check script (bin/check-scheduled-jobs.sh)"
if [[ -e "$ROOT/bin/check-scheduled-jobs.sh" ]]; then
  CHECK="$ROOT/bin/check-scheduled-jobs.sh"
  rm -rf "$WORK/state"; mkdir -p "$WORK/state"
  printf '# comment\n\nfresh 600 *:0/2\nstale 600 *:0/2\nnever 600 *:0/2\nbroken 600 *:0/2\n' > "$WORK/manifest"
  touch "$WORK/state/fresh.last-success" "$WORK/state/broken.last-success"
  printf '2026-01-01T00:00:00Z rc=1 duration=3s\n' > "$WORK/state/broken.last-run"
  touch -d '20 minutes ago' "$WORK/state/stale.last-success"
  printf '#!/usr/bin/env bash\ncase "$1 $2" in\n  "is-active --quiet") exit 0 ;;\n  "is-failed --quiet") [[ "$3" == amoobot-job-broken.service ]] && exit 0; exit 1 ;;\nesac\nexit 0\n' > "$WORK/systemctl-ok"; chmod +x "$WORK/systemctl-ok"
  export AMOOBOT_JOBS_MANIFEST="$WORK/manifest" AMOOBOT_SYSTEMCTL="$WORK/systemctl-ok" AMOOBOT_BACKUP_DIR="$WORK/no-backups-here"
  out="$(bash "$CHECK" 2>&1)"; rc=$?
  check "problems -> exit code 2" test "$rc" -eq 2
  check "fresh job reported OK" grep -qE '^OK +fresh' <<< "$out"
  check "stale job reported STALE" grep -qE '^STALE +stale' <<< "$out"
  check "job that never succeeded reported NEVER" grep -qE '^NEVER +never' <<< "$out"
  check "failed latest run reported FAILED although an older success is fresh" grep -qE '^FAILED +broken.*unit failed' <<< "$out"
  check "last run's exit code shown" grep -qE '^FAILED +broken.*last run rc=1' <<< "$out"
  check "summary line counts problems" grep -q '3 of 4 jobs need attention' <<< "$out"

  printf '#!/usr/bin/env bash\ncase "$1" in\n  is-active) exit 0 ;;\n  is-failed) exit 1 ;;\nesac\n' > "$WORK/systemctl-ok"
  out="$(bash "$CHECK" 2>&1)"; rc=$?
  check "reset-failed cannot hide the last failed command" bash -c '[[ $1 -eq 2 ]] && grep -qE "^FAILED +broken.*last run rc=1" <<< "$2"' _ "$rc" "$out"
  printf '# empty manifest\n' > "$WORK/manifest"
  bash "$CHECK" > /dev/null 2>&1; rc=$?
  check "empty manifest -> exit code 3 (unknown)" test "$rc" -eq 3

  # Backups (nightly cron) are checked too when the directory is readable.
  mkdir -p "$WORK/backups"; touch -d '30 hours ago' "$WORK/backups/amoobot_nightly_old.sql.gz"
  printf 'fresh 600 *:0/2\n' > "$WORK/manifest"
  printf '#!/usr/bin/env bash\ncase "$1 $2" in\n  "is-active --quiet") exit 0 ;;\n  "is-failed --quiet") exit 1 ;;\nesac\nexit 0\n' > "$WORK/systemctl-ok"; chmod +x "$WORK/systemctl-ok"
  out="$(AMOOBOT_BACKUP_DIR="$WORK/backups" bash "$CHECK" 2>&1)"; rc=$?
  check "backup older than 26h -> STALE, exit 2" bash -c '[[ $1 -eq 2 ]] && grep -qE "^STALE +backup" <<< "$2"' _ "$rc" "$out"
  touch "$WORK/backups/amoobot_nightly_new.sql.gz"
  out="$(AMOOBOT_BACKUP_DIR="$WORK/backups" bash "$CHECK" 2>&1)"; rc=$?
  check "fresh backup -> OK, exit 0" bash -c '[[ $1 -eq 0 ]] && grep -qE "^OK +backup" <<< "$2"' _ "$rc" "$out"

  printf 'fresh 600 *:0/2\n' > "$WORK/manifest"
  printf '#!/usr/bin/env bash\ncase "$1 $2" in\n  "is-active --quiet") exit 0 ;;\n  "is-failed --quiet") exit 1 ;;\nesac\nexit 0\n' > "$WORK/systemctl-ok"; chmod +x "$WORK/systemctl-ok"
  bash "$CHECK" > /dev/null 2>&1; rc=$?
  check "all fresh -> exit code 0" test "$rc" -eq 0
  printf '#!/usr/bin/env bash\ncase "$1 $2" in\n  "is-active --quiet") exit 3 ;;\n  "is-failed --quiet") exit 1 ;;\nesac\nexit 0\n' > "$WORK/systemctl-ok"; chmod +x "$WORK/systemctl-ok"
  out="$(bash "$CHECK" 2>&1)"; rc=$?
  check "inactive timer -> exit code 2" test "$rc" -eq 2 -a -n "$(grep 'timer not active' <<< "$out")"
  AMOOBOT_JOBS_MANIFEST="$WORK/missing" bash "$CHECK" > /dev/null 2>&1; rc=$?
  check "missing manifest -> exit code 3 (unknown)" test "$rc" -eq 3
  unset AMOOBOT_JOBS_MANIFEST AMOOBOT_SYSTEMCTL AMOOBOT_BACKUP_DIR
else
  skip "check script not present yet"
fi

echo "== units rendered by deploy.sh"
if have systemd-analyze; then
  # Pull the job table and the render functions out of deploy.sh and render every unit into a scratch dir.
  eval "$(sed -n '/^SCHEDULED_JOBS=(/,/^)/p' "$ROOT/deploy.sh")"
  eval "$(sed -n '/^parse_job()/,/^}/p; /^render_job_service()/,/^}/p; /^render_job_timer()/,/^}/p' "$ROOT/deploy.sh")"
  # shellcheck disable=SC2034 # read by the render functions eval'd above
  APP_DIR=/var/www/amoobot WEB_USER=www-data ROOT_LIB_DIR=/usr/local/lib/amoobot MONITOR_ENV_FILE=/etc/amoobot/monitor.env
  units="$WORK/units"; mkdir -p "$units"
  for entry in "${SCHEDULED_JOBS[@]}"; do
    parse_job "$entry"
    render_job_service > "$units/amoobot-job-${JOB_NAME}.service"
    render_job_timer > "$units/amoobot-job-${JOB_NAME}.timer"
    out="$(systemd-analyze calendar "$JOB_CALENDAR" 2>&1)"
    check "OnCalendar '${JOB_CALENDAR}' (${JOB_NAME}) is valid" grep -q 'Next elapse' <<< "$out"
  done
  check "6 jobs rendered" test "$(ls "$units"/*.timer | wc -l)" -eq 6
  check "no job schedules repair-stuck" bash -c '! grep -l repair-stuck "$1"/*.service' _ "$units"
  check "every job runs as www-data" bash -c '[[ $(grep -l "^User=www-data$" "$1"/*.service | wc -l) -eq 6 ]]' _ "$units"
  check "every job has OnFailure alert" bash -c '[[ $(grep -l "^OnFailure=amoobot-job-alert@%N.service$" "$1"/*.service | wc -l) -eq 6 ]]' _ "$units"
  # verify: only diagnostics about our units count (the host's own units may warn), and complaints about
  # binaries/paths that exist on the server but not here are ignored.
  verify="$(cd "$units" && systemd-analyze verify --man=no ./*.timer ./*.service 2>&1 | grep 'amoobot-job' \
    | grep -vE 'is not executable|No such file or directory|Failed to prepare filename|unit file .* does not exist' || true)"
  if [[ -z "$verify" ]]; then pass "systemd-analyze verify: no unit errors"; else fail "systemd-analyze verify"; echo "$verify"; fi
else
  skip "unit validation (systemd-analyze not installed)"
fi

echo "== deploy.sh --dry-run preview and idempotent writes"
# deploy.sh validates secrets with {32,256}; MSYS/Git Bash regex caps repetition at 255, so its dry-run cannot pass there.
if have openssl && [[ x =~ ^x{1,256}$ ]]; then
  rnd() { openssl rand -hex 32; }
  cat > "$WORK/prod.env" <<EOF
DOMAIN=bot.example.test
DB_PASSWORD=$(openssl rand -hex 24)
APP_SECRET=$(rnd)
TELEGRAM_BOT_TOKEN=123456789:$(openssl rand -hex 20)
TELEGRAM_WEBHOOK_SECRET=$(rnd)
TELEGRAM_WEBHOOK_SECRET_TOKEN=$(rnd)
TELEGRAM_ADMIN_CHAT_ID=-1001234567
EOF
  cp "$ROOT/deploy.sh" "$WORK/deploy.sh"
  out="$(bash "$WORK/deploy.sh" --dry-run --env-file "$WORK/prod.env" 2>&1)"; rc=$?
  jobs_part="$(sed -n '/^\[16\/18\]/,/^\[17\/18\]/p' <<< "$out")"
  check "dry-run exits 0" test "$rc" -eq 0
  check "dry-run lists the job table" grep -qE '^  sync +\*:0/15 +lifecycle +1500s +app:automation:run --only=sync' <<< "$jobs_part"
  check "dry-run shows each new service file" grep -q '+ ExecStart=/bin/bash /usr/local/lib/amoobot/scheduled-job.sh notifications lifecycle -- ' <<< "$jobs_part"
  check "dry-run shows each new timer file" grep -q '+ OnCalendar=\*:5/15' <<< "$jobs_part"
  check "dry-run shows the alert unit and logrotate" bash -c 'grep -q "amoobot-job-alert@.service" <<< "$1" && grep -q "+     rotate 14" <<< "$1"' _ "$jobs_part"
  check "dry-run shows the manifest" grep -q '+ sync 4200 \*:0/15' <<< "$jobs_part"
  check "dry-run would enable every timer" test "$(grep -c 'systemctl enable --now amoobot-job-' <<< "$jobs_part")" -eq 6
  check "dry-run pauses jobs before the checkout" grep -q 'pause scheduled jobs' <<< "$out"
  leak=0
  while IFS='=' read -r k v; do
    [[ "$k" =~ (SECRET|TOKEN|PASSWORD) ]] && grep -qF -- "$v" <<< "$out" && { leak=1; echo "  leaked: $k"; }
  done < "$WORK/prod.env"
  check "dry-run prints no secret value" test "$leak" -eq 0
  sed 's/^SCHEDULED_JOBS=(/SCHEDULED_JOBS=(\n  "bad|x|*:0\/5|60|300|app:payments:repair-stuck --apply"/' "$ROOT/deploy.sh" > "$WORK/deploy-bad.sh"
  out="$(bash "$WORK/deploy-bad.sh" --dry-run --env-file "$WORK/prod.env" 2>&1)"; rc=$?
  check "preflight refuses to schedule repair-stuck" bash -c '[[ $1 -ne 0 ]] && grep -q "must never be scheduled" <<< "$2"' _ "$rc" "$out"
  sed 's/app:payments:repair-stuck --apply/app:plans:adjust-prices --percent=50/' "$WORK/deploy-bad.sh" > "$WORK/deploy-destructive.sh"
  out="$(bash "$WORK/deploy-destructive.sh" --dry-run --env-file "$WORK/prod.env" 2>&1)"; rc=$?
  check "preflight refuses other unreviewed commands" bash -c '[[ $1 -ne 0 ]] && grep -q "only the six reviewed automation tasks" <<< "$2"' _ "$rc" "$out"

  # write_file for real (as the current user): new -> changed, same content -> unchanged, edit -> changed.
  eval "$(sed -n '/^FILE_CHANGED=0$/,/^}/p' "$ROOT/deploy.sh")"
  # shellcheck disable=SC2034 # read by the write_file function eval'd above
  DRY_RUN=0; me="$(id -un):$(id -gn)"; target="$WORK/etc/amoobot-job-x.timer"
  write_file "$target" 644 "$me" --show-diff <<< one; first=$FILE_CHANGED
  write_file "$target" 644 "$me" --show-diff <<< one; second=$FILE_CHANGED
  write_file "$target" 644 "$me" --show-diff <<< two; third=$FILE_CHANGED
  check "re-run: new file written, identical re-run unchanged, edit updates" test "$first$second$third" = "101"
  check "re-run: file holds the latest content" grep -qx two "$target"
  # "cmd | write_file" runs write_file in a subshell, so FILE_CHANGED (daemon-reload, timer restart) would be lost.
  check "jobs step never pipes into write_file" \
    bash -c '! sed -n "/^# SCHEDULED JOBS/,/^# WEBHOOK/p" "$1" | grep -q "| *write_file"' _ "$ROOT/deploy.sh"
else
  skip "dry-run tests (needs openssl and a regex engine allowing {32,256}: run on Linux/WSL, not Git Bash)"
fi

echo "== logrotate config"
if have logrotate; then
  cfg="$(sed -n '/^write_file \/etc\/logrotate.d\/amoobot-jobs/,/^EOF/p' "$ROOT/deploy.sh" | sed '1d;$d' \
    | sed -e "s#\${JOBS_LOG_DIR}#$WORK/rotlog#" -e "s#\${WEB_USER}#$(id -un)#g" -e "s#^    su .*##")"
  mkdir -p "$WORK/rotlog"; echo x > "$WORK/rotlog/sync.log"
  printf '%s\n' "$cfg" > "$WORK/logrotate.conf"
  if logrotate -d -s "$WORK/logrotate.state" "$WORK/logrotate.conf" > "$WORK/logrotate.out" 2>&1 && grep -q 'rotating pattern' "$WORK/logrotate.out"; then
    pass "logrotate parses the config"
  else
    fail "logrotate config"; tail -5 "$WORK/logrotate.out"
  fi
else
  skip "logrotate not installed"
fi

echo
echo "passed: $PASS  failed: $FAIL  skipped: $SKIP"
(( FAIL == 0 ))
