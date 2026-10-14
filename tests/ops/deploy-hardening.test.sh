#!/usr/bin/env bash
#
# Tests for the deploy-tooling hardening in deploy.sh and the root-run scripts: offline only (dry-run output,
# validation functions, rendered config text). No root, no server, no network, nothing outside a scratch dir.
#
#   bash tests/ops/deploy-hardening.test.sh
#
# Sections are skipped (not failed) when their tools are missing: php (DATABASE_URL parsing), unshare -r (fake root),
# visudo, ssh-keygen. Run it on Linux/WSL and once where php is available.

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
# deploy.sh validates secrets with {32,256}; MSYS/Git Bash regex caps repetition at 255, so its dry-run cannot pass there.
can_dry_run() { have openssl && [[ x =~ ^x{1,256}$ ]]; }
DRY_RUN_SKIP="dry-run tests (needs openssl and a regex engine allowing {32,256}: run on Linux/WSL, not Git Bash)"
# load_fn NAME...  eval deploy.sh's function definitions (they start at column 0 and end with "}" at column 0)
load_fn() { local f; for f in "$@"; do eval "$(sed -n "/^${f}() {/,/^}/p" "$ROOT/deploy.sh")"; done; }
rnd() { openssl rand -hex "$1" | tr -d '\r'; }
# Minimal valid settings for --dry-run (random throwaway secrets; checked below that none is printed).
make_env() {
  cat > "$1" <<EOF
DOMAIN=bot.example.test
DB_PASSWORD=$(rnd 24)
APP_SECRET=$(rnd 32)
TELEGRAM_BOT_TOKEN=123456789:$(rnd 20)
TELEGRAM_WEBHOOK_SECRET=$(rnd 32)
TELEGRAM_WEBHOOK_SECRET_TOKEN=$(rnd 32)
TELEGRAM_ADMIN_CHAT_ID=-1001234567
EOF
}
# dry_run ENV_FILE [deploy.sh]  ->  OUT, RC
dry_run() { OUT="$(bash "${2:-$ROOT/deploy.sh}" --dry-run --env-file "$1" 2>&1)"; RC=$?; }
no_secret_printed() {
  local k v leak=0
  while IFS='=' read -r k v; do
    [[ "$k" =~ (SECRET|TOKEN|PASSWORD) ]] && grep -qF -- "$v" <<< "$OUT" && { leak=1; echo "  leaked: $k"; }
  done < "$1"
  (( leak == 0 ))
}

# shellcheck disable=SC2034 # globals read by the functions loaded from deploy.sh
set_globals() {
  APP_DIR=/var/www/amoobot BACKUP_DIR=/var/backups/amoobot BACKUP_RETENTION_DAYS=14 WEB_USER=www-data DEPLOY_USER=deploy
  ROOT_LIB_DIR=/usr/local/lib/amoobot ROOT_SBIN_DIR=/usr/local/sbin DB_URL_FILE=/etc/amoobot/database.env DRY_RUN=1
}
die() { printf 'DIE: %s\n' "$*" >&2; return 1; }
log() { printf '%s\n' "$*"; }

echo "== syntax"
for f in deploy.sh bin/*.sh bin/lib/*.sh; do
  check "bash -n $f" bash -n "$ROOT/$f"
done

can_dry_run && { make_env "$WORK/prod.env"; dry_run "$WORK/prod.env"; BASE_OUT="$OUT"; BASE_RC="$RC"; }

echo "== root never runs deploy-writable files"
set_globals
load_fn root_ops_dest render_root_wrapper
check "deploy.sh installs to the root lib" test "$(root_ops_dest deploy.sh)" = /usr/local/lib/amoobot/deploy.sh
check "bin scripts install to the root lib" test "$(root_ops_dest bin/backup-database.sh)" = /usr/local/lib/amoobot/backup-database.sh
check "bin/lib installs to the root lib's lib/" test "$(root_ops_dest bin/lib/database-url.sh)" = /usr/local/lib/amoobot/lib/database-url.sh
for w in deploy backup restore check-jobs; do
  render_root_wrapper "$w" > "$WORK/amoobot-$w"
  check "wrapper amoobot-$w execs only the root lib" bash -c \
    'grep -q "^exec .*/usr/local/lib/amoobot/" "$1" && ! grep -q "/var/www/amoobot/bin" "$1"' _ "$WORK/amoobot-$w"
  check "wrapper amoobot-$w is valid bash" bash -n "$WORK/amoobot-$w"
done
check "backup/restore wrappers use a clean environment and the root-owned DATABASE_URL file" bash -c \
  'for f in "$@"; do grep -q "exec env -i .*AMOOBOT_DB_URL_FILE=/etc/amoobot/database.env" "$f" || exit 1; done' _ \
  "$WORK/amoobot-backup" "$WORK/amoobot-restore"
bash "$WORK/amoobot-backup" 'bad label;rm' > /dev/null 2>&1; rc=$?
check "amoobot-backup rejects an invalid label (exit 2, nothing run)" test "$rc" -eq 2
bash "$WORK/amoobot-backup" one two > /dev/null 2>&1; rc=$?
check "amoobot-backup rejects extra arguments" test "$rc" -eq 2
# Matches e.g. 'bash "$APP_DIR/bin/x.sh"' and the old cron line '... bash ${APP_DIR}/bin/backup-database.sh'.
checkout_exec_re='(^|[;&|[:space:]])(bash|sh|source)[[:space:]]+"?\$\{?APP_DIR\}?/'
check "deploy.sh never executes a checkout script with bash" bash -c '! grep -nE "$2" "$1"' _ "$ROOT/deploy.sh" "$checkout_exec_re"
git -C "$ROOT" show 250ff44:deploy.sh > "$WORK/old-deploy.sh" 2> /dev/null \
  && check "  (self-test: the same check flags the pre-hardening deploy.sh)" grep -qE "$checkout_exec_re" "$WORK/old-deploy.sh"
check "root never requires .env.local.php (only as the web user)" bash -c \
  '! grep -n "require \$argv" "$1" | grep -v "sudo -H -u \"\$WEB_USER\""' _ "$ROOT/deploy.sh"

if can_dry_run; then
  OUT="$BASE_OUT"
  check "dry-run exits 0" test "$BASE_RC" -eq 0
  for f in deploy.sh bin/backup-database.sh bin/restore-database.sh bin/scheduled-job.sh bin/scheduled-job-alert.sh \
           bin/check-scheduled-jobs.sh bin/lib/database-url.sh; do
    check "dry-run installs ${f} from git objects into the root lib" \
      grep -qE "^\[dry-run\] install ${f} from git objects of .* -> /usr/local/lib/amoobot/.* root:root\)$" <<< "$OUT"
  done
  check "dry-run installs the four root wrappers" test "$(grep -oE '^\[dry-run\] write /usr/local/sbin/amoobot-(deploy|backup|restore|check-jobs) \(755 root:root\)' <<< "$OUT" | sort -u | wc -l)" -eq 4
  check "cron runs the root-owned backup wrapper" grep -qE '^    \+ 30 3 \* \* \* root /usr/local/sbin/amoobot-backup nightly ' <<< "$OUT"
  check "DATABASE_URL goes to a root-owned 600 file" grep -q '^\[dry-run\] write /etc/amoobot/database.env (600 root:root)' <<< "$OUT"
  check "job units run the root-owned wrapper script" grep -q '+ ExecStart=/bin/bash /usr/local/lib/amoobot/scheduled-job.sh sync ' <<< "$OUT"
  check "alert unit runs the root-owned alert script" grep -q '+ ExecStart=/bin/bash /usr/local/lib/amoobot/scheduled-job-alert.sh failed %i' <<< "$OUT"
  check "no unit or cron line executes a script from the checkout" bash -c \
    '! grep -E "^ +\+ .*(ExecStart=|root ).*/var/www/amoobot/bin/[a-z-]+\.sh" <<< "$1"' _ "$OUT"
  check "dry-run prints no secret value" no_secret_printed "$WORK/prod.env"
else
  skip "$DRY_RUN_SKIP"
fi

echo "== DATABASE_URL for root-run scripts (bin/lib/database-url.sh)"
LIB="$ROOT/bin/lib/database-url.sh"
mkdir -p "$WORK/app"
# A .env.local.php that leaves a marker when executed: proves whether it was loaded.
printf '<?php touch(__DIR__."/LOADED"); return ["DATABASE_URL" => "mysql://u:p@127.0.0.1:3306/fromapp"];\n' > "$WORK/app/.env.local.php"
printf 'DATABASE_URL=mysql://dbuser:s3cr%%2Fet@db.internal:3307/fromfile?serverVersion=10.11\n' > "$WORK/database.env"
chmod 600 "$WORK/database.env"
if have php; then
  out="$(env -u DATABASE_URL AMOOBOT_DB_URL_FILE="$WORK/database.env" bash -c 'source "$1"; load_database_url "$2"; echo "$DB_USER|$DB_PASS|$DB_HOST|$DB_PORT|$DB_NAME"' _ "$LIB" "$WORK/app" 2>&1)"
  check "reads DATABASE_URL from the root-owned file (percent-decoded)" test "$out" = "dbuser|s3cr/et|db.internal|3307|fromfile"
  check "the app's .env.local.php was not loaded" test ! -e "$WORK/app/LOADED"
else
  skip "DATABASE_URL file parsing (php not installed)"
fi
cp "$WORK/database.env" "$WORK/database-gw.env"; chmod 620 "$WORK/database-gw.env"
if [[ "$(stat -c %a "$WORK/database-gw.env")" == 620 ]]; then
  out="$(env -u DATABASE_URL AMOOBOT_DB_URL_FILE="$WORK/database-gw.env" bash -c 'source "$1"; load_database_url "$2"' _ "$LIB" "$WORK/app" 2>&1)"; rc=$?
  check "group-writable DATABASE_URL file is refused" bash -c '[[ $1 -ne 0 ]] && grep -q "not writable by group/others" <<< "$2"' _ "$rc" "$out"
else
  skip "group-writable file test (filesystem ignores chmod)"
fi
if have unshare && unshare -r true 2> /dev/null; then
  out="$(env -u DATABASE_URL AMOOBOT_DB_URL_FILE="$WORK/missing.env" unshare -r bash -c 'source "$1"; load_database_url "$2"' _ "$LIB" "$WORK/app" 2>&1)"; rc=$?
  check "as root without the file: refuses instead of loading .env.local.php" \
    bash -c '[[ $1 -ne 0 ]] && grep -q "never loaded" <<< "$2"' _ "$rc" "$out"
  check "as root: .env.local.php was not executed" test ! -e "$WORK/app/LOADED"
else
  skip "fake-root test (unshare -r not available)"
fi

echo "== SSH lockout protection (step 5)"
chmod_works() { local f="$WORK/chmod-probe"; : > "$f"; chmod 620 "$f"; [[ "$(stat -c %a "$f")" == 620 ]]; }
if have ssh-keygen; then
  set_globals
  load_fn valid_ssh_public_key merge_authorized_keys usable_key_count ssh_login_problems
  ssh-keygen -q -t ed25519 -N '' -C test@example -f "$WORK/k1" > /dev/null
  ssh-keygen -q -t ed25519 -N '' -C root@example -f "$WORK/k2" > /dev/null
  K1="$(cat "$WORK/k1.pub")"; K2="$(cat "$WORK/k2.pub")"
  check "a public key line is valid" valid_ssh_public_key "$K1"
  check "garbage is rejected" not valid_ssh_public_key "ssh-ed25519 not-a-key"
  check "two key lines are rejected" not valid_ssh_public_key "$K1"$'\n'"$K2"
  check "a private key is rejected" not valid_ssh_public_key "$(cat "$WORK/k1")"

  # Root's keys are merged even when the deploy user already has keys (the old code copied only into an empty file).
  printf '# root keys\n%s\n\n%s\n' "$K1" "$K2" > "$WORK/root_keys"
  printf '%s\n' "$K1" > "$WORK/deploy_keys"
  merge_authorized_keys "$WORK/root_keys" "$WORK/deploy_keys"
  check "root's missing key appended to a non-empty file" bash -c '[[ $1 -eq 1 ]] && grep -qxF -- "$2" "$3" && grep -qxF -- "$4" "$3"' _ "$MERGED_KEYS" "$K2" "$WORK/deploy_keys" "$K1"
  merge_authorized_keys "$WORK/root_keys" "$WORK/deploy_keys"
  check "merge is idempotent (no duplicates)" bash -c '[[ $1 -eq 0 && $(wc -l < "$2") -eq 2 ]]' _ "$MERGED_KEYS" "$WORK/deploy_keys"
  merge_authorized_keys "$WORK/missing" "$WORK/deploy_keys"
  check "missing root file adds nothing" test "$MERGED_KEYS" -eq 0
  check "old copy-only-when-empty logic is gone" bash -c '! grep -q "cp /root/.ssh/authorized_keys" "$1"' _ "$ROOT/deploy.sh"

  printf 'command="echo Please login as ubuntu",no-pty %s\n' "$K1" > "$WORK/forced_keys"
  check "a forced-command key does not count as a login" test "$(usable_key_count "$WORK/forced_keys")" -eq 0
  printf 'from="10.0.0.0/8" %s\n%s\n' "$K1" "$K2" > "$WORK/opt_keys"
  check "keys with other options count" test "$(usable_key_count "$WORK/opt_keys")" -eq 2

  if have getent && chmod_works; then
    me="$(id -un)"; H="$WORK/home"; mkdir -p "$H/.ssh"; chmod 755 "$H"; chmod 700 "$H/.ssh"
    : > "$H/.ssh/authorized_keys"; chmod 600 "$H/.ssh/authorized_keys"
    check "no key -> login problem reported" bash -c '[[ -n "$1" ]]' _ "$(ssh_login_problems "$me" "$H")"
    printf '%s\n' "$K1" > "$H/.ssh/authorized_keys"
    out="$(ssh_login_problems "$me" "$H")"
    check "valid key, correct modes -> no problem" test -z "$out"
    chmod 770 "$H/.ssh"
    check "group-writable ~/.ssh -> problem (StrictModes)" grep -q 'writable by group/others' <<< "$(ssh_login_problems "$me" "$H")"
    chmod 700 "$H/.ssh"
    check "a dry run's preview file is what gets counted" test -z "$(: > "$H/.ssh/authorized_keys"; ssh_login_problems "$me" "$H" "$WORK/opt_keys")"
    check "unknown user -> problem" grep -q 'does not exist' <<< "$(ssh_login_problems no-such-user-amoobot "$H")"
  else
    skip "login-problem checks (needs getent and a filesystem honouring chmod)"
  fi
else
  skip "SSH key tests (ssh-keygen not installed)"
fi

set_globals
load_fn render_sudoers
render_sudoers > "$WORK/sudoers"
check "sudoers grants exactly the four wrappers, no ALL command" bash -c \
  'grep -q "^deploy ALL=(root) NOPASSWD: AMOOBOT_OPS$" "$1" && ! grep -qE "NOPASSWD: *ALL" "$1" &&
   [[ $(grep -o "/usr/local/sbin/amoobot-[a-z-]*" "$1" | sort -u | tr "\n" " ") == "/usr/local/sbin/amoobot-backup /usr/local/sbin/amoobot-check-jobs /usr/local/sbin/amoobot-deploy /usr/local/sbin/amoobot-restore " ]]' _ "$WORK/sudoers"
if have visudo; then
  check "visudo -cf accepts the rendered sudoers" visudo -cf "$WORK/sudoers"
else
  skip "visudo -cf (visudo not installed)"
fi
check "sudoers is validated with visudo -cf before install" bash -c \
  'sed -n "/^install_sudoers() {/,/^}/p" "$1" | grep -q "visudo -cf \"\$tmp\".* || .*die"' _ "$ROOT/deploy.sh"

# install_sshd_dropin with a stub sshd: a rejected config must never stay in place.
load_fn install_sshd_dropin reload_ssh
dropin_case() {  # dropin_case <sshd exit code> <previous content or ""> -> final content (or "<absent>"), then rc/changed
  ( die() { printf 'DIE: %s\n' "$*" >> "$WORK/dropin.err"; echo "rc=1 changed=${SSHD_CHANGED:-}"; exit 1; }
    sshd() { echo "line 1: Bad configuration option: Nope" >&2; return "$SSHD_RC"; }
    DRY_RUN=0 SSHD_RC="$1"; f="$WORK/sshd.d/00-amoobot.conf"; rm -rf "$WORK/sshd.d"; mkdir -p "$WORK/sshd.d"
    [[ -n "$2" ]] && printf '%s\n' "$2" > "$f"
    : > "$WORK/dropin.err"
    ( install_sshd_dropin "$f" <<< 'PasswordAuthentication no'; echo "rc=$? changed=${SSHD_CHANGED:-}" ) > "$WORK/dropin.status"
    if [[ -e "$f" ]]; then cat "$f"; else echo "<absent>"; fi
    cat "$WORK/dropin.status" )
}
out="$(dropin_case 1 "")"
check "sshd -t fails, no previous file -> new file removed" bash -c '[[ "$1" == $'"'"'<absent>\nrc=1 changed=0'"'"' ]]' _ "$out"
check "  ...and the error names the restore and sshd's message" grep -q 'restored to its previous state.*Bad configuration option' "$WORK/dropin.err"
out="$(dropin_case 1 "PasswordAuthentication yes")"
check "sshd -t fails -> previous file restored before stopping" bash -c '[[ "$1" == $'"'"'PasswordAuthentication yes\nrc=1 changed=0'"'"' ]]' _ "$out"
out="$(dropin_case 0 "PasswordAuthentication yes")"
check "sshd -t passes -> new file installed, changed=1" bash -c '[[ "$1" == $'"'"'PasswordAuthentication no\nrc=0 changed=1'"'"' ]]' _ "$out"
out="$(dropin_case 1 "PasswordAuthentication no")"
check "unchanged file -> sshd not even tested, changed=0" bash -c '[[ "$1" == $'"'"'PasswordAuthentication no\nrc=0 changed=0'"'"' ]]' _ "$out"

reload_case() {  # reload_case "<active units>" -> systemctl calls + result
  ( die() { echo "DIE" >&2; exit 1; }
    systemctl() { echo "systemctl $*" >> "$WORK/systemctl.calls"; [[ "$1" == is-active ]] && [[ " $ACTIVE " == *" $3 "* ]]; }
    ACTIVE="$1"; : > "$WORK/systemctl.calls"; reload_ssh > /dev/null; echo "rc=$?"; grep -c 'systemctl reload' "$WORK/systemctl.calls" )
}
check "running ssh.service is reloaded" test "$(reload_case "ssh.service ssh.socket" | tr '\n' ' ')" = "rc=0 1 "
check "socket-activated only (Ubuntu 24.04) -> no reload needed" test "$(reload_case "ssh.socket" | tr '\n' ' ')" = "rc=0 0 "
check "no ssh daemon at all -> stop" grep -q DIE <<< "$(reload_case "" 2>&1)"

if can_dry_run; then
  OUT="$BASE_OUT"
  check "default PermitRootLogin is prohibit-password" grep -q '^    + PermitRootLogin prohibit-password$' <<< "$OUT"
  check "drop-in is tested with sshd -t and restored on failure" grep -q 'check with sshd -t (on failure restore the previous file)' <<< "$OUT"
  check "dry-run previews the sudoers file" grep -q '^    + deploy ALL=(root) NOPASSWD: AMOOBOT_OPS$' <<< "$OUT"
  check "no verified key -> a real run would refuse to disable passwords" grep -q 'a real run would stop here. Refusing to disable SSH password login' <<< "$OUT"
  if have ssh-keygen; then
    { cat "$WORK/prod.env"; echo "DEPLOY_SSH_PUBLIC_KEY=ssh-ed25519 AAAAnot-a-real-key x"; } > "$WORK/badkey.env"
    dry_run "$WORK/badkey.env"
    check "invalid DEPLOY_SSH_PUBLIC_KEY stops the preflight" bash -c '[[ $1 -ne 0 ]] && grep -q "DEPLOY_SSH_PUBLIC_KEY is not one valid SSH public key" <<< "$2"' _ "$RC" "$OUT"
    { cat "$WORK/prod.env"; echo "DEPLOY_SSH_PUBLIC_KEY=$K1"; } > "$WORK/goodkey.env"
    dry_run "$WORK/goodkey.env"
    check "valid DEPLOY_SSH_PUBLIC_KEY would be added" bash -c '[[ $1 -eq 0 ]] && grep -q "\[dry-run\] add DEPLOY_SSH_PUBLIC_KEY" <<< "$2"' _ "$RC" "$OUT"
    check "  ...and then nothing would be refused" not grep -q 'a real run would stop here' <<< "$OUT"
    { cat "$WORK/prod.env"; echo "SSH_PERMIT_ROOT_LOGIN=no"; } > "$WORK/noroot.env"
    dry_run "$WORK/noroot.env"
    check "PermitRootLogin no without a verified deploy login is refused" grep -q 'Refusing to disable SSH password login (and to set PermitRootLogin no)' <<< "$OUT"
  fi
else
  skip "$DRY_RUN_SKIP"
fi

echo "== path validation, migrations, backups, rollback (group 3)"
set_globals
load_fn valid_dir_path resolve_deploy_home
for p in /var/www/amoobot /srv/amoobot /var/www/bot.example-2_x; do
  check "APP_DIR $p accepted" valid_dir_path "$p" 'var/www|srv'
done
for p in / /var/www /var/www/ /var/www/.. /var/www/. /var/www/a/b /etc/amoobot "/var/www/a b" var/www/amoobot /var/www/amoobot/ '/var/www/$(id)'; do
  check "APP_DIR '$p' rejected" not valid_dir_path "$p" 'var/www|srv'
done
check "BACKUP_DIR /var/backups/amoobot accepted" valid_dir_path /var/backups/amoobot 'var/backups|srv'
check "BACKUP_DIR /var/backups/.. rejected" not valid_dir_path /var/backups/.. 'var/backups|srv'
home_case() { ( getent() { printf 'deploy:x:1001:1001::%s:/bin/bash\n' "$HOME_FIELD"; }; die() { echo DIE; exit 1; }
                HOME_FIELD="$1"; resolve_deploy_home; echo "$DEPLOY_HOME" ) }
check "deploy home /home/deploy accepted" test "$(home_case /home/deploy)" = /home/deploy
check "deploy home '/' refused (it is chowned)" test "$(home_case /)" = DIE
check "deploy home /var/www refused" test "$(home_case /var/www)" = DIE

if can_dry_run; then
  for bad in /var/www/.. / /etc; do
    { grep -v '^APP_DIR=' "$WORK/prod.env"; echo "APP_DIR=$bad"; } > "$WORK/badpath.env"
    dry_run "$WORK/badpath.env"
    check "dry-run with APP_DIR=$bad stops in the preflight, before any chown" bash -c \
      '[[ $1 -ne 0 ]] && grep -q "APP_DIR must be" <<< "$2" && ! grep -q "chown" <<< "$2"' _ "$RC" "$OUT"
  done
  { cat "$WORK/prod.env"; echo "BACKUP_DIR=/var/backups/.."; } > "$WORK/badbackup.env"
  dry_run "$WORK/badbackup.env"
  check "BACKUP_DIR=/var/backups/.. is refused" bash -c '[[ $1 -ne 0 ]] && grep -q "BACKUP_DIR must be" <<< "$2"' _ "$RC" "$OUT"
  OUT="$BASE_OUT"
  check "apt-get upgrade is non-interactive and keeps local config files" grep -qF \
    '[dry-run] env DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade -y' <<< "$OUT"
  check "old prod cache is removed as the web user, not root" grep -qF '[dry-run] sudo -H -u www-data -- rm -rf /var/www/amoobot/var/cache/prod' <<< "$OUT"
  check "target ops files are verified before the jobs are paused" bash -c \
    'v=$(grep -n "refuse it unless it has every root-owned ops file" <<< "$1" | cut -d: -f1); p=$(grep -n "pause scheduled jobs" <<< "$1" | cut -d: -f1); [[ -n $v && -n $p && $v -lt $p ]]' _ "$OUT"
else
  skip "$DRY_RUN_SKIP"
fi

# Step 11 (backup decision + post-migrate check), extracted from deploy.sh and run against stubs.
STEP11="$(sed -n '/^PRE_DEPLOY_BACKUP_FILE=""$/,/^console lint:container$/p' "$ROOT/deploy.sh")"
check "step 11 block found in deploy.sh" test -n "$STEP11"
check "backup decision no longer depends on PREVIOUS_COMMIT" not grep -q 'PREVIOUS_COMMIT' <<< "$STEP11"
load_fn restore_instructions
mkdir -p "$WORK/sbin"
printf '#!/usr/bin/env bash\necho "backup $*" >> "%s/calls"\necho "Backup written to /var/backups/amoobot/amoobot_pre-deploy_20261014T120000Z.sql.gz (12K)"\n' "$WORK" > "$WORK/sbin/amoobot-backup"
chmod +x "$WORK/sbin/amoobot-backup"
step11_case() {  # step11_case <pending 0|1> <tables> <migrate rc> <up-to-date --fail-on-unregistered rc>
  ( set -e
    die() { printf 'DIE: %s\n' "$*"; exit 1; }
    console() {
      echo "console $*" >> "$WORK/calls"
      case "$*" in
        "doctrine:migrations:up-to-date") return "$PENDING" ;;
        "doctrine:migrations:migrate --allow-no-migration") return "$MIGRATE_RC" ;;
        "doctrine:migrations:up-to-date --fail-on-unregistered") return "$UTD_RC" ;;
      esac
    }
    database_table_count() { echo "table-count" >> "$WORK/calls"; echo "$TABLES"; }
    DRY_RUN=0 PENDING="$1" TABLES="$2" MIGRATE_RC="$3" UTD_RC="$4" ROOT_SBIN_DIR="$WORK/sbin" DB_NAME=amoobot PHP_VERSION=8.4
    PREVIOUS_COMMIT=abc123 TARGET_COMMIT=def456 BACKUP_DIR=/var/backups/amoobot
    : > "$WORK/calls"
    eval "$STEP11"
    echo "REACHED-END backup=${PRE_DEPLOY_BACKUP_FILE}" ) 2>&1
}
out="$(step11_case 1 5 0 0)"
check "pending + existing tables -> pre-deploy backup, then migrate" bash -c \
  'grep -q "^backup pre-deploy$" "$1" && grep -q "REACHED-END backup=/var/backups/amoobot/amoobot_pre-deploy_20261014T120000Z.sql.gz" <<< "$2" &&
   [[ $(grep -n "^backup" "$1" | cut -d: -f1) -lt $(grep -n "migrate --allow" "$1" | cut -d: -f1) ]]' _ "$WORK/calls" "$out"
out="$(step11_case 1 0 0 0)"
check "pending + empty database (first install) -> no backup" bash -c '! grep -q "^backup" "$1" && grep -q "REACHED-END" <<< "$2"' _ "$WORK/calls" "$out"
out="$(step11_case 0 5 0 0)"
check "nothing pending -> no backup, tables not even counted" bash -c '! grep -qE "^(backup|table-count)" "$1" && grep -q "REACHED-END" <<< "$2"' _ "$WORK/calls" "$out"
out="$(step11_case 1 5 0 2)"
check "up-to-date exit 2 (unregistered migrations) -> stop with restore instructions" bash -c \
  'grep -q "DIE: The database has executed migrations that def456 does not contain" <<< "$1" &&
   grep -q "sudo amoobot-restore /var/backups/amoobot/amoobot_pre-deploy_20261014T120000Z.sql.gz --yes" <<< "$1" &&
   grep -q "Scheduled jobs stay PAUSED" <<< "$1" && ! grep -q REACHED-END <<< "$1"' _ "$out"
check "  ...and it is checked with --fail-on-unregistered" grep -q 'up-to-date --fail-on-unregistered' "$WORK/calls"
out="$(step11_case 0 5 0 2)"
check "exit 2 without a backup this run -> instructions point at the latest pre-deploy dumps" bash -c \
  'grep -q "ls -lt /var/backups/amoobot/amoobot_pre-deploy_\*.sql.gz" <<< "$1" && grep -q "amoobot-restore /var/backups/amoobot/amoobot_pre-deploy_<time>.sql.gz --yes" <<< "$1" &&
   grep -q -- "--ref abc123" <<< "$1" && ! grep -qi "bad substitution" <<< "$1"' _ "$out"
out="$(step11_case 0 5 0 1)"
check "still pending after migrate (exit 1) -> stop" bash -c 'grep -q "DIE: Migrations are not up to date" <<< "$1" && ! grep -q REACHED-END <<< "$1"' _ "$out"
out="$(step11_case 1 5 3 0)"
check "failed migrate -> stop with restore instructions" bash -c 'grep -q "DIE: doctrine:migrations:migrate failed" <<< "$1" && grep -q amoobot-restore <<< "$1"' _ "$out"

# --ref rollback: a target commit must contain every root-owned ops file (checked with git cat-file -e).
if have git; then
  load_fn git_blob verify_target_ops
  eval "$(sed -n '/^ROOT_OPS_FILES=(/,/)$/p' "$ROOT/deploy.sh")"
  R="$WORK/repo"; git init -q "$R"; git -C "$R" config user.email t@t; git -C "$R" config user.name t
  for f in "${ROOT_OPS_FILES[@]}" bin/console; do mkdir -p "$R/$(dirname "$f")"; echo "# $f" > "$R/$f"; done
  echo 'ROOT_LIB_DIR="/usr/local/lib/amoobot"' >> "$R/deploy.sh"
  git -C "$R" add -A; git -C "$R" commit -qm good; GOOD="$(git -C "$R" rev-parse HEAD)"
  sed -i 's#^ROOT_LIB_DIR=.*##' "$R/deploy.sh"; git -C "$R" commit -qam old-layout; OLD="$(git -C "$R" rev-parse HEAD)"
  git -C "$R" rm -q bin/check-scheduled-jobs.sh; git -C "$R" commit -qm missing; MISSING="$(git -C "$R" rev-parse HEAD)"
  verify_case() { ( sudo() { while (( $# )); do case "$1" in -H|-n) shift ;; -u) shift 2 ;; --) shift; break ;; *) break ;; esac; done; "$@"; }
                    die() { printf 'DIE: %s\n' "$*"; exit 1; }
                    APP_DIR="$R" DEPLOY_USER=deploy; verify_target_ops "$1" && echo OK ) 2>&1; }
  check "target with every ops file and the root layout -> accepted" test "$(verify_case "$GOOD")" = OK
  check "target missing an ops file -> refused, nothing paused" grep -q 'DIE: Refusing to deploy .* lacks bin/check-scheduled-jobs.sh. Nothing was paused' <<< "$(verify_case "$MISSING")"
  check "target with a pre-hardening deploy.sh -> refused" grep -q 'DIE: Refusing to deploy .* predates the root-owned ops layout' <<< "$(verify_case "$OLD")"
  check "the real pre-hardening commit 250ff44 would be refused" bash -c \
    '! git -C "$1" show 250ff44:deploy.sh 2>/dev/null | grep -qx "ROOT_LIB_DIR=\"/usr/local/lib/amoobot\""' _ "$ROOT"
  check "this checkout's deploy.sh carries the layout marker verify_target_ops looks for" \
    grep -qx 'ROOT_LIB_DIR="/usr/local/lib/amoobot"' "$ROOT/deploy.sh"
else
  skip "rollback verification (git not installed)"
fi

# Backup rotation: only <db>_nightly_* dumps expire; pre-deploy / pre-restore / manual dumps are kept.
if have php && have gzip; then
  B="$WORK/backups"; mkdir -p "$B" "$WORK/fakebin"
  printf '#!/usr/bin/env bash\necho "CREATE TABLE t (id int);"\necho "-- Dump completed on 2026-10-14"\n' > "$WORK/fakebin/mariadb-dump"
  chmod +x "$WORK/fakebin/mariadb-dump"
  for label in nightly pre-deploy pre-restore manual; do touch -d '30 days ago' "$B/amoobot_${label}_20260901T033000Z.sql.gz"; done
  touch -d '30 days ago' "$B/otherdb_nightly_20260901T033000Z.sql.gz"
  touch -d '2 days ago' "$B/amoobot_nightly_20261012T033000Z.sql.gz"
  out="$(PATH="$WORK/fakebin:$PATH" DATABASE_URL='mysql://u:p@127.0.0.1:3306/amoobot' BACKUP_DIR="$B" BACKUP_RETENTION_DAYS=14 \
    BACKUP_LABEL=nightly bash "$ROOT/bin/backup-database.sh" 2>&1)"; rc=$?
  check "backup run succeeds with a stub dump" test "$rc" -eq 0
  check "old nightly dump rotated out" test ! -e "$B/amoobot_nightly_20260901T033000Z.sql.gz"
  check "recent nightly dump kept" test -e "$B/amoobot_nightly_20261012T033000Z.sql.gz"
  for label in pre-deploy pre-restore manual; do
    check "old ${label} dump is never rotated" test -e "$B/amoobot_${label}_20260901T033000Z.sql.gz"
  done
  check "another database's dumps are left alone" test -e "$B/otherdb_nightly_20260901T033000Z.sql.gz"
else
  skip "backup rotation (needs php and gzip)"
fi

echo
echo "passed: $PASS  failed: $FAIL  skipped: $SKIP"
(( FAIL == 0 ))
