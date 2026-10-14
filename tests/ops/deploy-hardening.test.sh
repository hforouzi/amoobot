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
# load_fn NAME...  eval deploy.sh's function definitions: from "NAME() {" to the first column-0 "}" that is not inside a
# here-doc (render_nginx's here-docs contain column-0 "}" lines).
load_fn() {
  local f line on hd body
  for f in "$@"; do
    body="" on=0 hd=""
    while IFS= read -r line; do
      if (( ! on )); then [[ "$line" == "$f() {" ]] || continue; on=1; fi
      body+="$line"$'\n'
      if [[ -n "$hd" ]]; then [[ "$line" == "$hd" ]] && hd=""; continue; fi
      if [[ "$line" =~ \<\<-?\'?([A-Za-z_]+)\'? ]]; then hd="${BASH_REMATCH[1]}"; continue; fi
      [[ "$line" == "}" ]] && break
    done < "$ROOT/deploy.sh"
    [[ -n "$body" ]] || { echo "load_fn: ${f} not found in deploy.sh" >&2; FAIL=$((FAIL + 1)); continue; }
    eval "$body"
  done
}
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

echo "== webhook/payment maintenance during the deploy (group 4)"
set_globals
NGINX_SITE=/etc/nginx/sites-available/amoobot NGINX_MAINTENANCE_FILE=/etc/nginx/snippets/amoobot-maintenance.conf
PHP_VERSION=8.4 DOMAIN=bot.example.test CERT_DIR="$WORK/no-cert"
ADMIN_ALLOW_LIST=() NGINX_IPV6=0   # deploy.sh's preflight always sets these
load_fn render_maintenance set_maintenance maintenance_instructions on_exit app_locations render_nginx admin_access_rules listen_lines acme_location
check "maintenance on = 503 with Retry-After" bash -c '[[ "$1" == *"add_header Retry-After 120 always;"*"return 503;"* ]]' _ "$(render_maintenance on)"
check "maintenance off = no directives" not grep -qE 'return|add_header' <<< "$(render_maintenance off)"
APP_LOC="$(app_locations)"
block() { sed -n "/location ^~ $1 {/,/^    }/p" <<< "$2"; }
WEBHOOK_BLOCK="$(block '\/telegram\/webhook\/' "$APP_LOC")"; PAYMENT_BLOCK="$(block '\/payment\/' "$APP_LOC")"
check "webhook location includes the maintenance switch" grep -qF 'include /etc/nginx/snippets/amoobot-maintenance.conf;' <<< "$WEBHOOK_BLOCK"
check "payment location includes the maintenance switch" grep -qF 'include /etc/nginx/snippets/amoobot-maintenance.conf;' <<< "$PAYMENT_BLOCK"
check "webhook location goes to PHP directly (no try_files redirect)" bash -c \
  '! grep -q try_files <<< "$1" && grep -q "fastcgi_pass unix:/run/php/php8.4-fpm.sock;" <<< "$1" && grep -q "SCRIPT_FILENAME \$realpath_root/index.php;" <<< "$1"' _ "$WEBHOOK_BLOCK"

maint_case() {  # maint_case <state> <site includes 0|1> <nginx -t rc> -> MAINTENANCE_ON, file content, calls
  ( write_file() { cat > "$1"; }; warn() { echo "WARN: $*"; }; die() { echo "DIE: $*"; exit 1; }
    nginx() { echo "nginx $*" >> "$WORK/m.calls"; return "$NGX_RC"; }
    systemctl() { echo "systemctl $*" >> "$WORK/m.calls"; }
    DRY_RUN=0 NGX_RC="$3" NGINX_SITE="$WORK/site" NGINX_MAINTENANCE_FILE="$WORK/maint.conf" MAINTENANCE_ON=0
    : > "$WORK/m.calls"; if [[ "$2" == 1 ]]; then echo "include $WORK/maint.conf;" > "$WORK/site"; else echo "server {}" > "$WORK/site"; fi
    set_maintenance "$1"; echo "ON=$MAINTENANCE_ON"; grep -c 'return 503' "$WORK/maint.conf"; cat "$WORK/m.calls" ) 2>&1
}
out="$(maint_case on 1 0)"
check "on: file written, nginx tested and reloaded, flagged ON" bash -c \
  'grep -q "^ON=1$" <<< "$1" && grep -qx 1 <<< "$1" && grep -q "nginx -t" <<< "$1" && grep -q "systemctl reload nginx" <<< "$1"' _ "$out"
out="$(maint_case off 1 0)"
check "off: return 503 removed and reloaded, flagged OFF" bash -c 'grep -q "^ON=0$" <<< "$1" && grep -qx 0 <<< "$1" && grep -q "reload nginx" <<< "$1"' _ "$out"
out="$(maint_case on 0 0)"
check "site without the include (first deploy): not reported as active" bash -c 'grep -q "^ON=0$" <<< "$1" && grep -q "not active for this deploy" <<< "$1" && ! grep -q reload <<< "$1"' _ "$out"
out="$(maint_case on 1 1)"
check "nginx rejects it when switching on: reverted to off, deploy continues" bash -c 'grep -q "^ON=0$" <<< "$1" && grep -qx 0 <<< "$1" && grep -q "WARN: .*WITHOUT maintenance" <<< "$1"' _ "$out"
out="$(maint_case off 1 1)"
check "lifting fails: deploy stops (instructions follow from on_exit)" grep -q 'DIE: .*lifting maintenance' <<< "$out"

exit_case() { ( JOBS_PAUSED=0 MAINTENANCE_ON="$2"; on_exit "$1" ) 2>&1; }
check "failed deploy with maintenance ON prints how to lift it" bash -c \
  'grep -q "still answer 503" <<< "$1" && grep -q "sudo amoobot-deploy --app-only" <<< "$1" && grep -qF "printf '"''"' > /etc/nginx/snippets/amoobot-maintenance.conf && nginx -t && systemctl reload nginx" <<< "$1"' _ "$(exit_case 1 1)"
check "successful exit prints nothing" test -z "$(exit_case 0 1)"
check "failure without maintenance prints no maintenance text" not grep -q 503 <<< "$(exit_case 1 0)"

if can_dry_run; then
  OUT="$BASE_OUT"
  check "maintenance ON after pausing jobs and before the checkout" bash -c \
    'p=$(grep -n "pause scheduled jobs" <<< "$1" | cut -d: -f1); m=$(grep -n "webhook/payment maintenance on" <<< "$1" | cut -d: -f1); c=$(grep -n "\[dry-run\] check out" <<< "$1" | cut -d: -f1)
     [[ -n $p && -n $m && -n $c && $p -lt $m && $m -lt $c ]]' _ "$OUT"
  check "maintenance OFF after migrations + lint:container, before step 12" bash -c \
    'l=$(grep -n "bin/console lint:container" <<< "$1" | head -1 | cut -d: -f1); m=$(grep -n "webhook/payment maintenance off" <<< "$1" | cut -d: -f1); s=$(grep -n "^\[12/18\]" <<< "$1" | cut -d: -f1)
     [[ -n $l && -n $m && -n $s && $l -lt $m && $m -lt $s ]]' _ "$OUT"
else
  skip "$DRY_RUN_SKIP"
fi

# Real nginx (only where an nginx binary runs, e.g. Laragon on Windows): render the site, point it at a scratch prefix
# on 127.0.0.1, and check the actual responses. PHP-FPM is not running, so passing through to PHP shows up as 502.
NGX_MASTER="" NGX_N=0
ngx_ok() { have nginx && have curl && nginx -v > /dev/null 2>&1; }
ngx_path() { if have cygpath; then cygpath -m "$1"; else printf '%s' "$1"; fi; }
# Windows nginx PIDs are Windows PIDs: check/kill them with tasklist/taskkill there, kill elsewhere.
ngx_alive() { if have taskkill; then tasklist //FI "PID eq $1" 2> /dev/null | grep -qi nginx; else kill -0 "$1" 2> /dev/null; fi; }
ngx_kill() { if have taskkill; then taskkill //F //T //PID "$1" > /dev/null 2>&1; else kill "$1" 2> /dev/null; fi; }
# ngx_prepare  -> a NEW scratch prefix NGX per instance (NGXW = the path as nginx sees it) and a free-ish port NGX_PORT
ngx_prepare() {
  NGX_N=$((NGX_N + 1)); NGX="$WORK/ngx$NGX_N"; mkdir -p "$NGX/logs" "$NGX/temp" "$NGX/conf" "$NGX/public" "$NGX/acme"
  NGXW="$(ngx_path "$NGX")"; NGX_PORT=$((20000 + RANDOM % 20000))
  render_maintenance off > "$NGX/conf/maint.conf"
}
ngx_reload() { nginx -p "${NGXW}/" -c "${NGXW}/conf/nginx.conf" -s reload > /dev/null 2>&1; sleep 0.8; }
# ngx_start SERVER_BLOCKS  -> starts nginx on NGX_PORT with the given server blocks (paths already substituted)
ngx_start() {
  printf 'fastcgi_param QUERY_STRING $query_string;\nfastcgi_param REQUEST_METHOD $request_method;\nfastcgi_param SCRIPT_NAME $fastcgi_script_name;\nfastcgi_param REQUEST_URI $request_uri;\n' > "$NGX/conf/fastcgi_params"
  cat > "$NGX/conf/nginx.conf" <<EOF
worker_processes 1; error_log ${NGXW}/logs/error.log; pid ${NGXW}/logs/nginx.pid;
events { worker_connections 64; }
http {
  client_body_temp_path ${NGXW}/temp; proxy_temp_path ${NGXW}/temp; fastcgi_temp_path ${NGXW}/temp; uwsgi_temp_path ${NGXW}/temp; scgi_temp_path ${NGXW}/temp;
$1
}
EOF
  nginx -p "${NGXW}/" -c "${NGXW}/conf/nginx.conf" -t > "$NGX/t.out" 2>&1 || return 1
  rm -f "$NGX/logs/nginx.pid"
  ( cd "$NGX" && nginx -p "${NGXW}/" -c "${NGXW}/conf/nginx.conf" > /dev/null 2>&1 & )
  for _ in $(seq 1 20); do [[ -s "$NGX/logs/nginx.pid" ]] && break; sleep 0.2; done
  NGX_MASTER="$(tr -dc 0-9 < "$NGX/logs/nginx.pid" 2> /dev/null)"
  for _ in $(seq 1 15); do curl -s -m 2 -o /dev/null "http://127.0.0.1:${NGX_PORT}/ready-probe.php" && return 0; sleep 0.3; done
  ngx_stop
  return 1
}
# ngx_stop  graceful quit, then force-kill the master's process tree if it is still there after ~5 s
ngx_stop() {
  [[ -n "${NGX_MASTER:-}" ]] || return 0
  nginx -p "${NGXW}/" -c "${NGXW}/conf/nginx.conf" -s quit > /dev/null 2>&1
  for _ in $(seq 1 25); do ngx_alive "$NGX_MASTER" || break; sleep 0.2; done
  ngx_alive "$NGX_MASTER" && ngx_kill "$NGX_MASTER"
  NGX_MASTER=""
}
trap 'ngx_stop; if [[ -n "${KEEP_WORK:-}" ]]; then echo "kept $WORK"; else rm -rf "$WORK"; fi' EXIT
# ngx_site TEXT  ->  TEXT with server paths replaced by the scratch prefix and listen on 127.0.0.1:NGX_PORT
ngx_site() {
  sed -e "s#/etc/nginx/snippets/amoobot-maintenance.conf#${NGXW}/conf/maint.conf#g" \
      -e "s#unix:/run/php/php[0-9.]*-fpm.sock#127.0.0.1:9#g" -e "s#/var/log/nginx/#${NGXW}/logs/#g" \
      -e "s#/var/www/amoobot/public#${NGXW}/public#g" -e "s#/var/www/letsencrypt#${NGXW}/acme#g" \
      -e "/listen \[::\]/d" -e "s#listen 80;#listen 127.0.0.1:${NGX_PORT};#" <<< "$1"
}
code() { curl -s -m 5 -o /dev/null -w '%{http_code}' "$@"; }
if ngx_ok; then
  ngx_prepare
  site="limit_req_zone \$binary_remote_addr zone=amoobot_admin_login:10m rate=10r/m;
server {
    listen 80;
    server_name localhost;
$(app_locations)
}"
  if ngx_start "$(ngx_site "$site")"; then
    render_maintenance on > "$NGX/conf/maint.conf"; ngx_reload
    check "nginx: webhook answers 503 during maintenance" test "$(code -X POST "http://127.0.0.1:${NGX_PORT}/telegram/webhook/abc")" = 503
    check "nginx: ...with Retry-After" grep -qi '^Retry-After: 120' <<< "$(curl -s -m 5 -D - -o /dev/null -X POST "http://127.0.0.1:${NGX_PORT}/telegram/webhook/abc")"
    check "nginx: payment callback answers 503 during maintenance" test "$(code "http://127.0.0.1:${NGX_PORT}/payment/callback/zibal")" = 503
    check "nginx: other pages are not affected" test "$(code "http://127.0.0.1:${NGX_PORT}/admin/login")" != 503
    render_maintenance off > "$NGX/conf/maint.conf"; ngx_reload
    check "nginx: after lifting, the webhook reaches PHP again (502: no PHP-FPM here)" test "$(code -X POST "http://127.0.0.1:${NGX_PORT}/telegram/webhook/abc")" = 502
    check "nginx: after lifting, payment reaches PHP again" test "$(code "http://127.0.0.1:${NGX_PORT}/payment/callback/zibal")" = 502
    ngx_stop
  else
    fail "nginx could not start the rendered site"; cat "$WORK/ngx/t.out" 2> /dev/null | head -5
  fi
else
  skip "real nginx checks (no runnable nginx binary)"
fi

echo "== nginx, HTTPS and admin protection (group 5)"
set_globals
NGINX_MAINTENANCE_FILE=/etc/nginx/snippets/amoobot-maintenance.conf ACME_WEBROOT=/var/www/letsencrypt PHP_VERSION=8.4 DOMAIN=bot.example.test
HSTS_STATE_FILE="$WORK/hsts-enabled" CERT_DIR="$WORK/cert"; mkdir -p "$CERT_DIR"
ADMIN_ALLOW_LIST=() NGINX_IPV6=0
load_fn valid_ip_or_cidr admin_access_rules listen_lines acme_location app_locations render_nginx render_maintenance
for ip in 203.0.113.7 10.0.0.0/8 192.168.1.0/24 0.0.0.0/0 2001:db8::1 2001:db8::/32 ::1; do check "ADMIN_ALLOW_IPS entry $ip accepted" valid_ip_or_cidr "$ip"; done
for ip in 256.1.1.1 1.2.3 1.2.3.4/33 1.2.3.4/ 2001:db8::/129 example.com 'all;' '1.2.3.4;deny' '*' ''; do check "ADMIN_ALLOW_IPS entry '$ip' rejected" not valid_ip_or_cidr "$ip"; done

BOOT="$(render_nginx)"   # no certificate yet
check "bootstrap (before certbot): only the ACME challenge location and a 503 catch-all" bash -c \
  '[[ $(grep -c "location " <<< "$1") -eq 2 ]] && grep -q "location ^~ /.well-known/acme-challenge/ {" <<< "$1" &&
   grep -q "return 503;" <<< "$1" && ! grep -qE "fastcgi_pass|try_files|/telegram|/payment" <<< "$1"' _ "$BOOT"
touch "$CERT_DIR/fullchain.pem"
HTTPS="$(render_nginx)"
WEBHOOK_BLOCK="$(block '\/telegram\/webhook\/' "$HTTPS")"
check "webhook location: access_log off (the URL holds the secret)" grep -q '^        access_log off;$' <<< "$WEBHOOK_BLOCK"
check "port 80 keeps ACME challenges reachable and redirects the rest" bash -c \
  'sed -n "/^server {/,/^}/p" <<< "$1" | head -20 | grep -q "acme-challenge" && grep -q "return 301 https://\$host\$request_uri;" <<< "$1"' _ "$HTTPS"
check "no IPv6 on the host -> no [::] listeners" not grep -q 'listen \[::\]' <<< "$HTTPS"
NGINX_IPV6=1
check "IPv6 on the host -> [::]:80 and [::]:443 ssl listeners" bash -c \
  'grep -q "^    listen \[::\]:80;$" <<< "$1" && grep -q "^    listen \[::\]:443 ssl;$" <<< "$1" && grep -q "^    listen 443 ssl;$" <<< "$1"' _ "$(render_nginx)"
check "  ...also on the bootstrap config" grep -q '^    listen \[::\]:80;$' <<< "$(rm -f "$CERT_DIR/fullchain.pem"; render_nginx)"
touch "$CERT_DIR/fullchain.pem"; NGINX_IPV6=0
check "no HSTS before HTTPS was verified" not grep -q Strict-Transport-Security <<< "$HTTPS"
touch "$HSTS_STATE_FILE"
check "HSTS once HTTPS was verified (443 server only)" bash -c \
  '[[ $(grep -c "Strict-Transport-Security \"max-age=31536000\" always;" <<< "$1") -eq 1 ]] && ! sed -n "1,/listen 443/p" <<< "$1" | grep -q Strict' _ "$(render_nginx)"
check "HSTS is switched on only after the healthz check in step 18" bash -c \
  'awk "/^step \"18\/18\"/{s=1} s" "$1" | grep -q "touch \"\$HSTS_STATE_FILE\"" && ! awk "/^step \"18\/18\"/{exit} 1" "$1" | grep -q "touch \"\$HSTS_STATE_FILE\""' _ "$ROOT/deploy.sh"
rm -f "$HSTS_STATE_FILE"
LOGIN_BLOCK="$(sed -n '/location = \/admin\/login {/,/^    }/p' <<< "$HTTPS")"
check "nginx rate-limits = /admin/login (429)" bash -c \
  'grep -q "limit_req zone=amoobot_admin_login burst=10 nodelay;" <<< "$1" && grep -q "limit_req_status 429;" <<< "$1"' _ "$LOGIN_BLOCK"
check "limit_req_zone defined once, outside the server blocks" bash -c \
  '[[ $(grep -c "^limit_req_zone \$binary_remote_addr zone=amoobot_admin_login:10m rate=10r/m;$" <<< "$1") -eq 1 ]]' _ "$HTTPS"
check "ADMIN_ALLOW_IPS empty -> no allow rules, no /admin block (only the dotfile deny)" bash -c '! grep -q "allow " <<< "$1" && ! grep -qF "location ^~ /admin {" <<< "$1" && [[ $(grep -c "deny all;" <<< "$1") -eq 1 ]]' _ "$HTTPS"
ADMIN_ALLOW_LIST=(203.0.113.7 10.0.0.0/8)
RESTRICTED="$(render_nginx)"
check "ADMIN_ALLOW_IPS set -> allow each entry, deny all, on /admin and /admin/login" bash -c \
  '[[ $(grep -c "^        allow 203.0.113.7;$" <<< "$1") -eq 2 && $(grep -c "^        allow 10.0.0.0/8;$" <<< "$1") -eq 2 && $(grep -c "^        deny all;$" <<< "$1") -eq 3 ]] &&
   grep -q "location ^~ /admin {" <<< "$1"' _ "$RESTRICTED"
ADMIN_ALLOW_LIST=()
check "Symfony login_throttling: 5 attempts / 15 minutes on the admin firewall" bash -c \
  'grep -A2 "^            login_throttling:" "$1" | grep -q "max_attempts: 5" && grep -A2 "^            login_throttling:" "$1" | grep -q "interval: .15 minutes."' _ "$ROOT/config/packages/security.yaml"

if can_dry_run; then
  { cat "$WORK/prod.env"; echo "ADMIN_ALLOW_IPS=203.0.113.7, 999.1.1.1"; } > "$WORK/badip.env"
  dry_run "$WORK/badip.env"
  check "invalid ADMIN_ALLOW_IPS stops the preflight" bash -c '[[ $1 -ne 0 ]] && grep -q "ADMIN_ALLOW_IPS: .999.1.1.1. is not" <<< "$2"' _ "$RC" "$OUT"
  { cat "$WORK/prod.env"; echo "ADMIN_ALLOW_IPS=203.0.113.7, 10.0.0.0/8"; } > "$WORK/goodip.env"
  dry_run "$WORK/goodip.env"
  check "valid ADMIN_ALLOW_IPS accepted and not written into the app env" bash -c '[[ $1 -eq 0 ]] && ! grep -q "ADMIN_ALLOW_IPS=" <<< "$2"' _ "$RC" "$OUT"
  OUT="$BASE_OUT"
  check "dry-run shows the bootstrap site (503 + ACME only)" grep -q '^    + # Managed by amoobot deploy.sh (no certificate yet: only ACME challenges are served' <<< "$OUT"
  check "dry-run announces HSTS only after the healthz check" grep -q 'after https://bot.example.test/healthz returns 200: enable HSTS' <<< "$OUT"
else
  skip "$DRY_RUN_SKIP"
fi

if ngx_ok; then
  ngx_prepare; mkdir -p "$NGX/acme/.well-known/acme-challenge"; echo "token-content" > "$NGX/acme/.well-known/acme-challenge/tok123"
  rm -f "$CERT_DIR/fullchain.pem"
  if ngx_start "$(ngx_site "$(render_nginx)")"; then
    check "nginx bootstrap: ACME challenge file is served" test "$(curl -s -m 5 "http://127.0.0.1:${NGX_PORT}/.well-known/acme-challenge/tok123")" = token-content
    check "nginx bootstrap: unknown challenge -> 404" test "$(code "http://127.0.0.1:${NGX_PORT}/.well-known/acme-challenge/nope")" = 404
    check "nginx bootstrap: everything else -> 503 (app not served over HTTP)" bash -c \
      '[[ $(curl -s -m 5 -o /dev/null -w "%{http_code}" "$1/") == 503 && $(curl -s -m 5 -o /dev/null -w "%{http_code}" "$1/admin/login") == 503 && $(curl -s -m 5 -o /dev/null -w "%{http_code}" -X POST "$1/telegram/webhook/x") == 503 ]]' _ "http://127.0.0.1:${NGX_PORT}"
    ngx_stop
  else
    fail "nginx could not start the bootstrap site"; head -5 "$NGX/t.out"
  fi

  # The HTTPS site, served over plain HTTP on 127.0.0.1 (no certificate needed for these checks).
  ngx_prepare
  plain="$(sed -n '/^server {/,$p' <<< "$HTTPS" | awk 'BEGIN{n=0} /^server \{/{n++} n==2' | sed -e 's#^    listen 443 ssl;#    listen 80;#' -e '/ssl_/d')"
  site="limit_req_zone \$binary_remote_addr zone=amoobot_admin_login:10m rate=10r/m;
$plain"
  if ngx_start "$(ngx_site "$site")"; then
    SECRET="websecret$RANDOM$RANDOM"
    curl -s -m 5 -o /dev/null -X POST "http://127.0.0.1:${NGX_PORT}/telegram/webhook/${SECRET}"
    curl -s -m 5 -o /dev/null "http://127.0.0.1:${NGX_PORT}/payment/callback/zibal?marker=logged"
    sleep 0.5
    check "nginx: webhook URL secret is not in the access log" not grep -qF "$SECRET" "$NGX/logs/amoobot_access.log"
    check "nginx: other requests are still logged" grep -q 'marker=logged' "$NGX/logs/amoobot_access.log"
    codes="$(for _ in $(seq 1 25); do code "http://127.0.0.1:${NGX_PORT}/admin/login"; echo; done | sort | uniq -c | tr '\n' ' ')"
    check "nginx: rapid /admin/login requests get 429 (codes: ${codes})" grep -q ' 429' <<< " $codes"
    ngx_stop
  else
    fail "nginx could not start the HTTPS site (as HTTP)"; head -5 "$NGX/t.out"
  fi

  ngx_prepare
  ADMIN_ALLOW_LIST=(203.0.113.7)
  touch "$CERT_DIR/fullchain.pem"   # render the HTTPS variant again (the bootstrap check removed it)
  plain="$(render_nginx | awk 'BEGIN{n=0} /^server \{/{n++} n==2' | sed -e 's#^    listen 443 ssl;#    listen 80;#' -e '/ssl_/d')"
  if ngx_start "$(ngx_site "limit_req_zone \$binary_remote_addr zone=amoobot_admin_login:10m rate=10r/m;
$plain")"; then
    check "nginx: ADMIN_ALLOW_IPS blocks other clients from /admin/login (403)" test "$(code "http://127.0.0.1:${NGX_PORT}/admin/login")" = 403
    check "nginx: ...and from the rest of /admin (403)" test "$(code "http://127.0.0.1:${NGX_PORT}/admin/payment")" = 403
    check "nginx: ...but not from public pages" test "$(code "http://127.0.0.1:${NGX_PORT}/payment/success")" != 403
    ngx_stop
  else
    fail "nginx could not start the restricted site"; head -5 "$NGX/t.out"
  fi
  ADMIN_ALLOW_LIST=(127.0.0.1)
  ngx_prepare
  plain="$(render_nginx | awk 'BEGIN{n=0} /^server \{/{n++} n==2' | sed -e 's#^    listen 443 ssl;#    listen 80;#' -e '/ssl_/d')"
  if ngx_start "$(ngx_site "limit_req_zone \$binary_remote_addr zone=amoobot_admin_login:10m rate=10r/m;
$plain")"; then
    check "nginx: an allowed client reaches /admin (502 = passed to PHP)" test "$(code "http://127.0.0.1:${NGX_PORT}/admin/payment")" = 502
    ngx_stop
  fi
  ADMIN_ALLOW_LIST=()

  # Real TLS with a throwaway self-signed certificate: HSTS only after verification, 301 on port 80.
  if have openssl; then
    ngx_prepare
    # MSYS_NO_PATHCONV: Git Bash would otherwise turn "/CN=..." into a Windows path (so the files get ngx_path'd paths).
    MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=bot.example.test" \
      -keyout "$(ngx_path "$NGX/privkey.pem")" -out "$(ngx_path "$NGX/fullchain.pem")" > /dev/null 2>&1
    [[ -s "$NGX/fullchain.pem" ]] || fail "openssl could not create the throwaway test certificate"
    TLS_PORT=$((NGX_PORT + 1))
    tls_site() { render_nginx | sed -e "s#${CERT_DIR}#${NGXW}#g" -e "s#listen 443 ssl;#listen 127.0.0.1:${TLS_PORT} ssl;#"; }
    cp "$NGX/fullchain.pem" "$CERT_DIR/fullchain.pem"
    if ngx_start "$(ngx_site "$(tls_site)")"; then
      check "nginx TLS: port 80 redirects to https" test "$(code "http://127.0.0.1:${NGX_PORT}/admin/login")" = 301
      check "nginx TLS: answers over TLS, without Strict-Transport-Security before verification" bash -c \
        'grep -q "^HTTP/" <<< "$1" && ! grep -qi "^strict-transport-security" <<< "$1"' _ "$(curl -sk -m 5 -D - -o /dev/null --resolve "bot.example.test:${TLS_PORT}:127.0.0.1" "https://bot.example.test:${TLS_PORT}/healthz")"
      ngx_stop
      touch "$HSTS_STATE_FILE"   # what step 18 does after a verified https://DOMAIN/healthz
      if ngx_start "$(ngx_site "$(tls_site)")"; then
        check "nginx TLS: Strict-Transport-Security after verification" grep -qi '^strict-transport-security: max-age=31536000' \
          <<< "$(curl -sk -m 5 -D - -o /dev/null --resolve "bot.example.test:${TLS_PORT}:127.0.0.1" "https://bot.example.test:${TLS_PORT}/healthz")"
        ngx_stop
      else
        fail "nginx could not restart the TLS site with HSTS"
      fi
      rm -f "$HSTS_STATE_FILE"
    else
      skip "nginx TLS checks (this nginx could not load the TLS site)"; head -3 "$NGX/t.out"
    fi
    rm -f "$CERT_DIR/fullchain.pem"
  fi
else
  skip "real nginx checks for group 5 (no runnable nginx binary)"
fi

echo "== alerting without Telegram: dead-man's switch and hourly check (group 6)"
# A fake curl: records its arguments and the config it gets on stdin; exit code from FAKE_CURL_RC.
mkdir -p "$WORK/curlbin"
printf '#!/usr/bin/env bash\necho "$*" >> "%s/curl.args"\ncat >> "%s/curl.cfg"\nexit "${FAKE_CURL_RC:-0}"\n' "$WORK" "$WORK" > "$WORK/curlbin/curl"
chmod +x "$WORK/curlbin/curl"
PING_URL="https://hc-ping.com/test-$RANDOM$RANDOM/amoobot-{job}"
ping_case() {  # ping_case <env url> <file url> <curl rc> -> output; curl.args / curl.cfg left for checks
  rm -f "$WORK/curl.args" "$WORK/curl.cfg"
  [[ -n "$2" ]] && printf 'AMOOBOT_MONITOR_PING_URL=%s\n' "$2" > "$WORK/monitor.env" || rm -f "$WORK/monitor.env"
  PATH="$WORK/curlbin:$PATH" AMOOBOT_MONITOR_PING_URL="$1" AMOOBOT_MONITOR_FILE="$WORK/monitor.env" FAKE_CURL_RC="$3" \
    bash -c 'source "$1"; monitor_ping sync; echo "rc=$?"' _ "$ROOT/bin/lib/monitor-ping.sh" 2>&1
}
out="$(ping_case "" "" 0)"
check "no MONITOR_PING_URL -> no ping at all" bash -c '[[ "$1" == "rc=0" && ! -e "$2" ]]' _ "$out" "$WORK/curl.args"
out="$(ping_case "$PING_URL" "" 0)"
check "ping goes to the URL with {job} replaced" grep -qxF "url = \"${PING_URL//\{job\}/sync}\"" "$WORK/curl.cfg"
check "the URL is never on curl's command line" bash -c '[[ "$(cat "$1")" == "-K -" ]]' _ "$WORK/curl.args"
check "ping has a short timeout" grep -qx 'max-time = 10' "$WORK/curl.cfg"
out="$(ping_case "" "$PING_URL" 0)"
check "URL read from the root-owned monitor.env when not in the environment" grep -qF "url = \"${PING_URL//\{job\}/sync}\"" "$WORK/curl.cfg"
out="$(ping_case "$PING_URL" "" 7)"
check "failed ping is ignored (rc 0, reported)" bash -c 'grep -q "failed (ignored)" <<< "$1" && grep -q "^rc=0$" <<< "$1"' _ "$out"

if have flock; then
  export AMOOBOT_JOBS_LOG_DIR="$WORK/jlog" AMOOBOT_JOBS_STATE_DIR="$WORK/jstate" AMOOBOT_JOBS_LOCK_DIR="$WORK/jlock"
  JOB="$ROOT/bin/scheduled-job.sh"
  rm -f "$WORK/curl.args" "$WORK/curl.cfg"
  PATH="$WORK/curlbin:$PATH" AMOOBOT_MONITOR_PING_URL="$PING_URL" bash "$JOB" orders grp -- true > /dev/null 2>&1
  check "successful job pings its own check" grep -qF "url = \"${PING_URL//\{job\}/orders}\"" "$WORK/curl.cfg" 2> /dev/null
  rm -f "$WORK/curl.args" "$WORK/curl.cfg"
  PATH="$WORK/curlbin:$PATH" AMOOBOT_MONITOR_PING_URL="$PING_URL" bash "$JOB" sync grp -- false > /dev/null 2>&1
  check "failed job does not ping (the monitor alerts when pings stop)" test ! -e "$WORK/curl.args"
  ( exec 9> "$WORK/jlock/grp.lock"; flock 9; sleep 3 ) &
  sleep 0.5
  PATH="$WORK/curlbin:$PATH" AMOOBOT_MONITOR_PING_URL="$PING_URL" AMOOBOT_JOBS_LOCK_WAIT=1 bash "$JOB" sync grp -- true > /dev/null 2>&1
  check "skipped job (lock busy) does not ping" test ! -e "$WORK/curl.args"
  wait
  unset AMOOBOT_JOBS_LOG_DIR AMOOBOT_JOBS_STATE_DIR AMOOBOT_JOBS_LOCK_DIR
else
  skip "job ping tests (flock not installed)"
fi
if have php && have gzip; then
  for label in nightly manual; do
    rm -f "$WORK/curl.args" "$WORK/curl.cfg"
    PATH="$WORK/curlbin:$WORK/fakebin:$PATH" DATABASE_URL='mysql://u:p@127.0.0.1:3306/amoobot' BACKUP_DIR="$WORK/backups" \
      BACKUP_LABEL="$label" AMOOBOT_MONITOR_PING_URL="$PING_URL" bash "$ROOT/bin/backup-database.sh" > /dev/null 2>&1
    if [[ "$label" == nightly ]]; then
      check "nightly backup pings the 'backup' check" grep -qF "url = \"${PING_URL//\{job\}/backup}\"" "$WORK/curl.cfg" 2> /dev/null
    else
      check "manual backup does not ping" test ! -e "$WORK/curl.args"
    fi
  done
else
  skip "backup ping tests (needs php and gzip)"
fi

set_globals
CHECK_JOBS_UNIT=amoobot-check-jobs MONITOR_ENV_FILE=/etc/amoobot/monitor.env JOBS_PAUSED=1 MAINTENANCE_ON=0
load_fn render_check_service render_check_timer paused_jobs_warning on_exit
eval "$(sed -n '/^parse_job()/,/^}/p; /^render_job_service()/,/^}/p' "$ROOT/deploy.sh")"
JOB_NAME=sync JOB_GROUP=lifecycle JOB_COMMAND="app:automation:run --only=sync" JOB_TIMEOUT=1500
check "job units read MONITOR_PING_URL from the root-only EnvironmentFile" grep -qx 'EnvironmentFile=-/etc/amoobot/monitor.env' <<< "$(render_job_service)"
CHECK_SVC="$(render_check_service)"; CHECK_TMR="$(render_check_timer)"
check "check unit runs the root-owned amoobot-check-jobs as root" bash -c \
  'grep -qx "ExecStart=/usr/local/sbin/amoobot-check-jobs" <<< "$1" && ! grep -q "^User=" <<< "$1"' _ "$CHECK_SVC"
check "check unit failure triggers the alert unit" grep -qx 'OnFailure=amoobot-job-alert@%N.service' <<< "$CHECK_SVC"
check "check timer: first run 1 h after start, then hourly" bash -c 'grep -qx "OnActiveSec=1h" <<< "$1" && grep -qx "OnUnitActiveSec=1h" <<< "$1"' _ "$CHECK_TMR"
check "check timer is not an amoobot-job-* unit (not deleted as a removed job)" not grep -q 'amoobot-job-' <<< "$CHECK_JOBS_UNIT"
if have systemd-analyze; then
  mkdir -p "$WORK/cunits"; printf '%s\n' "$CHECK_SVC" > "$WORK/cunits/amoobot-check-jobs.service"; printf '%s\n' "$CHECK_TMR" > "$WORK/cunits/amoobot-check-jobs.timer"
  v="$(cd "$WORK/cunits" && systemd-analyze verify --man=no ./amoobot-check-jobs.timer ./amoobot-check-jobs.service 2>&1 | grep amoobot-check-jobs \
      | grep -vE 'is not executable|No such file or directory|Failed to prepare filename|unit file .* does not exist|amoobot-job-alert@' || true)"
  check "systemd-analyze verify: check units have no errors" test -z "$v"; [[ -n "$v" ]] && echo "$v"
else
  skip "check unit validation (systemd-analyze not installed)"
fi
out="$( (on_exit 1) 2>&1 )"
check "a deploy that leaves jobs paused prints a prominent warning" grep -q '^!!! SCHEDULED JOBS ARE PAUSED' <<< "$out"
check "  ...with a one-line resume command" grep -qxF "      systemctl start 'amoobot-job-*.timer' amoobot-check-jobs.timer" <<< "$out"
check "  ...and the preferred fix (re-run the deploy)" grep -q 'sudo amoobot-deploy --app-only' <<< "$out"

if can_dry_run; then
  { cat "$WORK/prod.env"; echo "MONITOR_PING_URL=https://hc-ping.com/a b"; } > "$WORK/badmon.env"
  dry_run "$WORK/badmon.env"
  check "invalid MONITOR_PING_URL stops the preflight (value not printed)" bash -c '[[ $1 -ne 0 ]] && grep -q "MONITOR_PING_URL must be" <<< "$2" && ! grep -q "hc-ping.com/a b" <<< "$2"' _ "$RC" "$OUT"
  { cat "$WORK/prod.env"; echo "MONITOR_PING_URL=$PING_URL"; } > "$WORK/goodmon.env"
  dry_run "$WORK/goodmon.env"
  check "valid MONITOR_PING_URL: accepted, never printed" bash -c '[[ $1 -eq 0 ]] && ! grep -qF "$3" <<< "$2" && ! grep -qF "${3%%/amoobot-*}" <<< "$2"' _ "$RC" "$OUT" "$PING_URL"
  check "monitor.env written root-only, content not shown" bash -c \
    'grep -q "^\[dry-run\] write /etc/amoobot/monitor.env (600 root:root)" <<< "$1" && ! grep -q "AMOOBOT_MONITOR_PING_URL=" <<< "$1"' _ "$OUT"
  check "MONITOR_PING_URL is not written into the app env" not grep -q 'MONITOR_PING_URL=' <<< "$OUT"
  OUT="$BASE_OUT"
  check "dry-run installs and enables the hourly check timer" bash -c \
    'grep -q "write /etc/systemd/system/amoobot-check-jobs.timer" <<< "$1" && grep -q "systemctl enable --now amoobot-check-jobs.timer" <<< "$1"' _ "$OUT"
  check "dry-run takes a first nightly backup when none exists" grep -q 'no amoobot_nightly_\*.sql.gz yet: /usr/local/sbin/amoobot-backup nightly' <<< "$OUT"
  check "monitor-ping.sh is installed into the root lib" grep -q '^\[dry-run\] install bin/lib/monitor-ping.sh from git objects' <<< "$OUT"
else
  skip "$DRY_RUN_SKIP"
fi

echo
echo "passed: $PASS  failed: $FAIL  skipped: $SKIP"
(( FAIL == 0 ))
