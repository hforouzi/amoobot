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
# dry_run ENV_FILE [deploy.sh]  ->  OUT, RC. A dry run needs no root; as root (the Linux container) it runs as nobody,
# because root may only read --env-file from /etc/amoobot (tested separately below).
dry_run() {
  if [[ "$EUID" -eq 0 ]] && have setpriv; then
    chmod 755 "$WORK"; chmod 644 "$1"
    OUT="$(cd / && setpriv --reuid=65534 --regid=65534 --clear-groups env HOME=/nonexistent bash "${2:-$ROOT/deploy.sh}" --dry-run --env-file "$1" 2>&1)"; RC=$?
  else
    OUT="$(bash "${2:-$ROOT/deploy.sh}" --dry-run --env-file "$1" 2>&1)"; RC=$?
  fi
}
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
  REPO_URL=git@github.com:hforouzi/amoobot.git MIRROR_DIR=/var/lib/amoobot/mirror.git STATE_DIR=/var/lib/amoobot
  ALLOWED_SIGNERS_FILE=/etc/amoobot/allowed_signers GIT_ENV_PATH="$PATH" MIRROR_PROTOCOL=ssh HSTS_STATE_FILE="$WORK/hsts-enabled"
  DEPLOY_MARKER_SET=0 JOBS_PAUSED=0 DEPLOY_MARKER=/var/lib/amoobot/deploy-in-progress DEPLOY_WATCHDOG_UNIT=amoobot-deploy-watchdog
  APP_DOWNLOADS_MAX_MB=200 UPLOAD_BODY_MARGIN_MB=16 PHP_UPLOADS_INI=98-amoobot-uploads.ini
}
die() { printf 'DIE: %s\n' "$*" >&2; return 1; }
log() { printf '%s\n' "$*"; }

echo "== syntax"
for f in deploy.sh bin/*.sh bin/lib/*.sh; do
  check "bash -n $f" bash -n "$ROOT/$f"
done

can_dry_run && { make_env "$WORK/prod.env"; dry_run "$WORK/prod.env"; BASE_OUT="$OUT"; BASE_RC="$RC"; }
# The opt-in hardening (SSH_HARDENING=yes, FIREWALL=yes): the behaviour before hardening became opt-in.
can_dry_run && { { cat "$WORK/prod.env"; printf 'SSH_HARDENING=yes\nFIREWALL=yes\n'; } > "$WORK/hard.env"; dry_run "$WORK/hard.env"; HARD_OUT="$OUT"; HARD_RC="$RC"; }

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
check "amoobot-deploy wrapper runs deploy.sh with a clean environment (env -i)" \
  grep -q '^exec env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LANG=C.UTF-8 /bin/bash /usr/local/lib/amoobot/deploy.sh "\$@"$' "$WORK/amoobot-deploy"
mkdir -p "$WORK/envprobe/usr/local/lib/amoobot"
printf '#!/bin/bash\nenv | sort\n' > "$WORK/envprobe/deploy.sh"
sed "s#/usr/local/lib/amoobot/deploy.sh#$WORK/envprobe/deploy.sh#" "$WORK/amoobot-deploy" > "$WORK/amoobot-deploy-probe"
out="$(BASH_ENV=/tmp/x LD_PRELOAD=/tmp/evil.so GIT_DIR=/tmp/g AMOOBOT_DEPLOY_REEXEC=1 bash "$WORK/amoobot-deploy-probe" --dry-run 2>&1)"
check "  ...nothing from the caller's environment reaches it" bash -c \
  '! grep -qE "^(BASH_ENV|LD_PRELOAD|GIT_DIR|AMOOBOT_DEPLOY_REEXEC)=" <<< "$1" && grep -q "^PATH=/usr/local/sbin:" <<< "$1"' _ "$out"
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
check "sudoers comment states the real trust model (any origin ref; GitHub write access = root)" bash -c \
  'grep -q "can deploy ANY branch, tag or commit that exists on origin" "$1" && grep -q "protect GitHub write access" "$1" && ! grep -q "^#include" "$1"' _ "$WORK/sudoers"
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

echo "== SSH lockout: effective sshd config, second admin, real listening port (group 7, 2nd review)"
load_fn sshd_values sshd_pattern_match ssh_access_problems other_admin_problems ssh_listen_ports
ME="$(id -un)"; MYGROUP="$(id -gn)"
base_cfg="port 22
pubkeyauthentication yes
passwordauthentication no
authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2"
acc() { printf '%s\n%s\n' "$base_cfg" "${2:-}" | sed "s/^pubkeyauthentication yes$/pubkeyauthentication ${3:-yes}/" | ssh_access_problems "$ME" "/home/$ME" "$1"; }
check "default config: no problem" test -z "$(acc 1)"
check "AllowUsers without the deploy user -> problem" grep -q "AllowUsers (someone-else ) does not include $ME" <<< "$(acc 0 'allowusers someone-else')"
check "AllowUsers with the deploy user (one line per entry) -> OK" test -z "$(acc 0 "$(printf 'allowusers someone-else\nallowusers %s' "$ME")")"
check "AllowUsers user@host for the deploy user -> OK (operator's host restriction)" test -z "$(acc 0 "allowusers ${ME}@10.0.0.1")"
check "AllowUsers wildcard matching the deploy user -> OK" test -z "$(acc 0 "allowusers ${ME:0:1}*")"
check "DenyUsers matching the deploy user -> problem" grep -q "DenyUsers ${ME:0:1}\* excludes" <<< "$(acc 0 "denyusers ${ME:0:1}*")"
check "AllowGroups without a group of the deploy user -> problem" grep -q 'AllowGroups (admins ) does not include a group' <<< "$(acc 0 'allowgroups admins')"
check "AllowGroups with the deploy user's group -> OK" test -z "$(acc 0 "allowgroups ${MYGROUP}")"
check "DenyGroups with the deploy user's group -> problem" grep -q "DenyGroups ${MYGROUP} excludes" <<< "$(acc 0 "denygroups ${MYGROUP}")"
check "AuthorizedKeysFile elsewhere -> problem" grep -q 'AuthorizedKeysFile (/etc/ssh/keys/%u ) does not include' <<< "$(printf 'pubkeyauthentication yes\nauthorizedkeysfile /etc/ssh/keys/%%u\n' | ssh_access_problems "$ME" "/home/$ME" 0)"
check "AuthorizedKeysFile %h/.ssh/authorized_keys -> OK" test -z "$(printf 'pubkeyauthentication yes\nauthorizedkeysfile %%h/.ssh/authorized_keys\n' | ssh_access_problems "$ME" "/home/$ME" 1)"
check "pubkeyauthentication no with the drop-in in place -> problem" grep -q 'pubkeyauthentication is not' <<< "$(acc 1 '' no)"
check "  ...but not before the drop-in (it sets PubkeyAuthentication yes first)" test -z "$(acc 0 '' no)"
check "no sshd -T output -> problem" grep -q 'printed nothing' <<< "$(ssh_access_problems "$ME" "/home/$ME" 0 < /dev/null)"

if have sshd && have ssh-keygen && [[ "$EUID" -eq 0 ]]; then
  ssh-keygen -q -t ed25519 -N '' -f "$WORK/hostkey" > /dev/null
  [[ -d /run/sshd ]] || install -d -m 755 /run/sshd   # sshd -T needs its privilege separation dir (present on a server)
  real_acc() {  # real_acc "<sshd_config lines>"  -> problems from the REAL sshd -T -C output for this user
    printf 'HostKey %s\n%s\n' "$WORK/hostkey" "$1" > "$WORK/sshd_cfg"   # sshd's default AuthorizedKeysFile applies
    sshd -T -f "$WORK/sshd_cfg" -C "user=$ME,host=localhost,addr=127.0.0.1" 2>&1 | ssh_access_problems "$ME" "/home/$ME" 1
  }
  check "real sshd: plain config -> OK" test -z "$(real_acc '')"
  check "real sshd: AllowUsers other1 other2 -> problem" grep -q 'AllowUsers (other1 other2 ) does not include' <<< "$(real_acc 'AllowUsers other1 other2')"
  check "real sshd: AllowUsers other1 <deploy user> -> OK" test -z "$(real_acc "AllowUsers other1 $ME")"
  check "real sshd: DenyGroups with the deploy user's group -> problem" grep -q 'DenyGroups' <<< "$(real_acc "DenyGroups $MYGROUP")"
  check "real sshd: Match User <deploy user> with PubkeyAuthentication no -> problem" grep -q 'pubkeyauthentication is not' <<< "$(real_acc "Match User $ME
    PubkeyAuthentication no")"
  check "real sshd: Match User someone-else does not affect the deploy user" test -z "$(real_acc "Match User someone-else
    PubkeyAuthentication no")"
  check "real sshd: AuthorizedKeysFile only in /etc/ssh/keys -> problem" grep -q 'AuthorizedKeysFile' <<< "$(real_acc 'AuthorizedKeysFile /etc/ssh/keys/%u')"
else
  skip "real sshd -T -C checks (need sshd, ssh-keygen and root: Linux container)"
fi

admin_case() {  # admin_case "<passwd lines>" "<sudo -l output>" <passwd -S status> <login problems> [console]
  ( getent() { printf '%s\n' "$PASSWD_LINES"; }
    sudo() { printf '%s\n' "$SUDO_OUT"; }
    passwd() { echo "admin $PW_STATUS 2026-01-01 0 99999 7 -1"; }
    ssh_login_problems() { [[ -n "$LOGIN_PROBLEM" ]] && echo "$LOGIN_PROBLEM"; return 0; }
    sshd_effective() { printf '%s\n' "$base_cfg"; }
    DEPLOY_USER=deploy PASSWD_LINES="$1" SUDO_OUT="$2" PW_STATUS="$3" LOGIN_PROBLEM="$4" I_HAVE_CONSOLE_ACCESS="${5:-no}"
    other_admin_problems; echo "rc=$?" )
}
ADMIN_LINE='admin:x:1000:1000::/home/admin:/bin/bash'
DEPLOY_LINE='deploy:x:1001:1001::/home/deploy:/bin/bash'
check "PermitRootLogin no: another admin with (ALL : ALL) ALL, a password and a key -> OK" test "$(admin_case "$ADMIN_LINE" '    (ALL : ALL) ALL' P '')" = "rc=0"
check "  ...NOPASSWD: ALL with a locked password -> OK" test "$(admin_case "$ADMIN_LINE" '    (ALL) NOPASSWD: ALL' L '')" = "rc=0"
check "  ...(ALL) ALL but a locked password (sudo would ask for it) -> problem" grep -q 'no account other than deploy' <<< "$(admin_case "$ADMIN_LINE" '    (ALL) ALL' L '')"
check "  ...only limited sudo rights -> problem" grep -q 'no account other than deploy' <<< "$(admin_case "$ADMIN_LINE" '    (root) /usr/bin/apt' P '')"
check "  ...admin cannot log in with a key -> problem" grep -q 'no account other than deploy' <<< "$(admin_case "$ADMIN_LINE" '    (ALL : ALL) ALL' P 'no valid key')"
check "  ...only the deploy user itself -> problem" grep -q 'no account other than deploy' <<< "$(admin_case "$DEPLOY_LINE" '    (ALL : ALL) ALL' P '')"
check "  ...nobody, but I_HAVE_CONSOLE_ACCESS=yes -> OK" test "$(admin_case "$DEPLOY_LINE" '' P '' yes)" = "rc=0"
ports_case() {  # ports_case <socket active 0|1> "<Listen value>" "<sshd -T port lines>"
  ( systemctl() { case "$1" in is-active) [[ "$SOCK" == 1 ]] ;; show) printf '%s\n' "$LISTEN" ;; esac; }
    sshd() { printf '%s\n' "$PORTS"; }
    SOCK="$1" LISTEN="$2" PORTS="$3"; ssh_listen_ports | tr '\n' ' ' )
}
check "ssh.socket active: its ListenStream decides (22)" test "$(ports_case 1 $'0.0.0.0:22 (Stream)\n[::]:22 (Stream)' 'port 2222')" = "22 "
check "ssh.socket on 2222 while sshd_config says 22 -> 2222 (would be locked out by ufw)" test "$(ports_case 1 '[::]:2222 (Stream)' 'port 22')" = "2222 "
check "no socket activation: sshd -T port(s)" test "$(ports_case 0 '' $'port 22\nport 2200')" = "22 2200 "
check "step 5: access, second-admin and port checks run before the drop-in is written" bash -c \
  'a=$(grep -n "ssh_access_problems \"\$DEPLOY_USER\" \"\$DEPLOY_HOME\" 0)" "$1" | head -1 | cut -d: -f1); o=$(grep -n "< <(other_admin_problems)" "$1" | cut -d: -f1)
   p=$(grep -n "ssh_ports=\"\$(ssh_listen_ports" "$1" | cut -d: -f1); d=$(grep -n "^  install_sshd_dropin \"\$SSHD_DROPIN\" dropin_login_check" "$1" | cut -d: -f1)
   [[ -n $a && -n $o && -n $p && -n $d && $a -lt $d && $o -lt $d && $p -lt $d ]]' _ "$ROOT/deploy.sh"
verify_dropin_case() {  # verify_dropin_case "<problems printed by the verify function>" -> final file content + rc
  ( die() { printf 'DIE: %s\n' "$*" >> "$WORK/vd.err"; echo "rc=1 changed=${SSHD_CHANGED:-}"; exit 1; }
    sshd() { return 0; }; verify_fn() { printf '%s' "$VERIFY_OUT"; }
    DRY_RUN=0 VERIFY_OUT="$1"; f="$WORK/vd.d/00.conf"; rm -rf "$WORK/vd.d"; mkdir -p "$WORK/vd.d"; printf 'PasswordAuthentication yes\n' > "$f"; : > "$WORK/vd.err"
    ( install_sshd_dropin "$f" verify_fn <<< 'PasswordAuthentication no'; echo "rc=$? changed=${SSHD_CHANGED:-}" ) > "$WORK/vd.status" 2> /dev/null
    cat "$f"; cat "$WORK/vd.status" )
}
load_fn install_sshd_dropin
check "drop-in that would lock the deploy user out -> previous file restored, ssh not reloaded" bash -c \
  '[[ "$1" == $'"'"'PasswordAuthentication yes\nrc=1 changed=0'"'"' ]] && grep -q "could not log in" "$2"' _ "$(verify_dropin_case 'sshd: AllowUsers (root ) does not include deploy')" "$WORK/vd.err"
check "drop-in that keeps the deploy login -> installed" test "$(verify_dropin_case '')" = $'PasswordAuthentication no\nrc=0 changed=1'

if can_dry_run; then
  OUT="$HARD_OUT"
  check "SSH_HARDENING=yes: dry run exits 0" test "$HARD_RC" -eq 0
  check "default PermitRootLogin is prohibit-password" grep -q '^    + PermitRootLogin prohibit-password$' <<< "$OUT"
  check "drop-in is tested with sshd -t and restored on failure" grep -q 'check with sshd -t (on failure restore the previous file)' <<< "$OUT"
  check "dry-run previews the sudoers file" grep -q '^    + deploy ALL=(root) NOPASSWD: AMOOBOT_OPS$' <<< "$OUT"
  check "no verified key -> a real run would refuse to disable passwords" grep -q 'a real run would stop here. Refusing to disable SSH password login' <<< "$OUT"
  if have ssh-keygen; then
    { cat "$WORK/hard.env"; echo "DEPLOY_SSH_PUBLIC_KEY=ssh-ed25519 AAAAnot-a-real-key x"; } > "$WORK/badkey.env"
    dry_run "$WORK/badkey.env"
    check "invalid DEPLOY_SSH_PUBLIC_KEY stops the preflight" bash -c '[[ $1 -ne 0 ]] && grep -q "DEPLOY_SSH_PUBLIC_KEY is not one valid SSH public key" <<< "$2"' _ "$RC" "$OUT"
    { cat "$WORK/hard.env"; echo "DEPLOY_SSH_PUBLIC_KEY=$K1"; } > "$WORK/goodkey.env"
    dry_run "$WORK/goodkey.env"
    check "valid DEPLOY_SSH_PUBLIC_KEY would be added" bash -c '[[ $1 -eq 0 ]] && grep -q "\[dry-run\] add DEPLOY_SSH_PUBLIC_KEY" <<< "$2"' _ "$RC" "$OUT"
    check "  ...and then nothing would be refused" not grep -q 'a real run would stop here' <<< "$OUT"
    { cat "$WORK/hard.env"; echo "SSH_PERMIT_ROOT_LOGIN=no"; } > "$WORK/noroot.env"
    dry_run "$WORK/noroot.env"
    check "PermitRootLogin no without a verified deploy login is refused" grep -q 'Refusing to disable SSH password login (and to set PermitRootLogin no)' <<< "$OUT"
  fi
else
  skip "$DRY_RUN_SKIP"
fi

echo "== SSH hardening and firewall are opt-in (default: sshd and ufw untouched)"
if can_dry_run; then
  OUT="$BASE_OUT"
  check "defaults: dry run exits 0" test "$BASE_RC" -eq 0
  check "  ...no sshd drop-in would be written" not grep -qE '00-amoobot-hardening\.conf, check with sshd -t|^    \+ (PasswordAuthentication|PermitRootLogin|KbdInteractiveAuthentication) ' <<< "$OUT"
  check "  ...no lockout refusal (nothing about logins changes)" not grep -q 'Refusing to disable SSH password login' <<< "$OUT"
  check "  ...no ufw command at all" not grep -qE '^\[dry-run\] ufw ' <<< "$OUT"
  for line in "sshd drop-in /etc/ssh/sshd_config.d/00-amoobot-hardening.conf: skipped (SSH_HARDENING=no)" \
              "disable SSH password login (PasswordAuthentication/KbdInteractiveAuthentication no): skipped (SSH_HARDENING=no)" \
              "set PermitRootLogin: skipped (SSH_HARDENING=no)" "ssh reload: skipped (SSH_HARDENING=no)" \
              "ufw default deny incoming / allow outgoing: skipped (FIREWALL=no)" "ufw allow 22/tcp, 80/tcp, 443/tcp: skipped (FIREWALL=no)" \
              "ufw enable: skipped (FIREWALL=no)"; do
    check "  ...says: ${line}" grep -qxF "  - ${line}" <<< "$OUT"
  done
  check "  ...prints the ports the app needs (80 and 443)" grep -q "the app needs inbound TCP 80 (HTTP, Let's Encrypt) and 443 (HTTPS) open" <<< "$OUT"
  check "  ...reports whether an earlier amoobot drop-in exists" grep -qE '^  - earlier amoobot sshd drop-in: none|sshd_config.d/00-amoobot-hardening.conf from an earlier deploy' <<< "$OUT"
  check "  ...the step title names both switches" grep -q 'Server setup (deploy user, SSH hardening: no, firewall: no' <<< "$OUT"
  check "  ...the deploy user and its sudoers rule are still set up" grep -q '^    + deploy ALL=(root) NOPASSWD: AMOOBOT_OPS$' <<< "$OUT"
  check "  ...no secret printed" no_secret_printed "$WORK/prod.env"
  OUT="$HARD_OUT"
  check "SSH_HARDENING=yes FIREWALL=yes: the old ufw steps run" bash -c \
    '[[ $(grep -cE "^\[dry-run\] ufw (default deny incoming|default allow outgoing|allow 22/tcp|allow 80/tcp|allow 443/tcp|--force enable)$" <<< "$1") -eq 6 ]]' _ "$OUT"
  check "  ...and nothing is reported as skipped" not grep -q 'skipped (SSH_HARDENING=no)\|skipped (FIREWALL=no)' <<< "$OUT"
  { cat "$WORK/prod.env"; echo "FIREWALL=yes"; } > "$WORK/fwonly.env"; dry_run "$WORK/fwonly.env"
  check "FIREWALL=yes alone: ufw managed, SSH still untouched" bash -c \
    'grep -q "^\[dry-run\] ufw --force enable$" <<< "$1" && grep -qxF "  - set PermitRootLogin: skipped (SSH_HARDENING=no)" <<< "$1"' _ "$OUT"
  for bad in "SSH_HARDENING=true" "FIREWALL=on" "SSH_HARDENING_REVERT=1"; do
    { cat "$WORK/prod.env"; echo "$bad"; } > "$WORK/badflag.env"; dry_run "$WORK/badflag.env"
    check "${bad} is refused (yes/no only)" bash -c '[[ $1 -ne 0 ]] && grep -q "must be '"'"'yes'"'"' or '"'"'no'"'"'" <<< "$2"' _ "$RC" "$OUT"
  done
  { cat "$WORK/prod.env"; printf 'SSH_HARDENING=yes\nSSH_HARDENING_REVERT=yes\n'; } > "$WORK/contra.env"; dry_run "$WORK/contra.env"
  check "SSH_HARDENING=yes with SSH_HARDENING_REVERT=yes is refused" bash -c '[[ $1 -ne 0 ]] && grep -q "contradict each other" <<< "$2"' _ "$RC" "$OUT"
else
  skip "$DRY_RUN_SKIP"
fi
check "the only sshd drop-in install and the ufw commands are inside the opt-in blocks" bash -c '
  yes_block=$(sed -n "/^if \[\[ \"\$SSH_HARDENING\" == yes \]\]; then$/,/^else$/p" "$1")
  code() { grep -vE "^ *(log|warn|skipped) "; }   # commands only, not the text the lines print
  no_block=$(sed -n "/^if \[\[ \"\$SSH_HARDENING\" == yes \]\]; then$/,/^fi$/p" "$1" | sed -n "/^else$/,/^fi$/p" | code)
  fw_no=$(sed -n "/^if \[\[ \"\$FIREWALL\" == yes \]\]; then$/,/^fi$/p" "$1" | sed -n "/^else$/,/^fi$/p" | code)
  [[ $(grep -c "install_sshd_dropin \"" "$1") -eq 1 ]] && grep -q "install_sshd_dropin \"\$SSHD_DROPIN\"" <<< "$yes_block" &&
  ! grep -qE "install_sshd_dropin|reload_ssh|sshd_config" <<< "$no_block" && ! grep -qE "run ufw|ufw (allow|default|enable|reset|--force)" <<< "$fw_no" &&
  [[ $(grep -cE "^ *run ufw " "$1") -eq 6 ]]' _ "$ROOT/deploy.sh"
check "report_firewall_needs only reads ufw (ufw status)" bash -c \
  '[[ "$(sed -n "/^report_firewall_needs() {/,/^}/p" "$1" | grep -vE "^ *(log|warn) " | grep -oE "ufw [a-z-]+" | sort -u)" == "ufw status" ]]' _ "$ROOT/deploy.sh"

set_globals
load_fn ssh_dropin_revert firewall_port_problem report_firewall_needs
revert_case() {  # revert_case <REVERT yes|no> <file content or "" for none> <sshd -t rc> [DRY_RUN] -> file, then log
  ( warn() { printf 'WARN: %s\n' "$*"; }
    die() { printf 'DIE: %s\n' "$*"; exit 1; }
    sshd() { [[ "$1" == -t ]] && { echo "line 3: Bad configuration option" >&2; return "$SSHD_RC"; }; }
    reload_ssh() { echo "RELOADED"; }
    SSHD_DROPIN="$WORK/rv/00-amoobot-hardening.conf" SSHD_DROPIN_MARKER="# Managed by amoobot deploy.sh"
    SSH_HARDENING_REVERT="$1" SSHD_RC="$3" DRY_RUN="${4:-0}"
    rm -rf "$WORK/rv"; mkdir -p "$WORK/rv"; [[ -n "$2" ]] && printf '%s\n' "$2" > "$SSHD_DROPIN"
    ( ssh_dropin_revert ) > "$WORK/rv.log" 2>&1; echo "rc=$?"
    if [[ -e "$SSHD_DROPIN" ]]; then echo "file: present"; else echo "file: absent"; fi
    cat "$WORK/rv.log" )
}
OURS=$'# Managed by amoobot deploy.sh\nPasswordAuthentication no\nPermitRootLogin prohibit-password'
out="$(revert_case no '' 0)"
check "no earlier drop-in -> reported as none" bash -c 'grep -q "^rc=0$" <<< "$1" && grep -q "earlier amoobot sshd drop-in: none" <<< "$1"' _ "$out"
out="$(revert_case no "$OURS" 0)"
check "earlier drop-in, SSH_HARDENING_REVERT=no -> reported, left in place, ssh not reloaded" bash -c \
  'grep -q "file: present" <<< "$1" && grep -q "WARN: .*still installed .*PasswordAuthentication no PermitRootLogin prohibit-password.*SSH_HARDENING_REVERT=yes" <<< "$1" && ! grep -q RELOADED <<< "$1"' _ "$out"
out="$(revert_case yes "$OURS" 0)"
check "SSH_HARDENING_REVERT=yes, sshd -t OK -> removed, then ssh reloaded" bash -c \
  'grep -q "^rc=0$" <<< "$1" && grep -q "file: absent" <<< "$1" && grep -q "^RELOADED$" <<< "$1" && grep -q "^Removed .*00-amoobot-hardening.conf" <<< "$1"' _ "$out"
out="$(revert_case yes "$OURS" 1)"
check "SSH_HARDENING_REVERT=yes, sshd -t fails -> file restored, ssh NOT reloaded, deploy stops" bash -c \
  'grep -q "^rc=1$" <<< "$1" && grep -q "file: present" <<< "$1" && ! grep -q RELOADED <<< "$1" && grep -q "DIE: sshd -t rejected .*restored.*Bad configuration option" <<< "$1"' _ "$out"
check "  ...the restored file is byte-identical" bash -c '[[ "$(cat "$1")" == "$2" ]]' _ "$WORK/rv/00-amoobot-hardening.conf" "$OURS"
out="$(revert_case yes "$OURS" 0 1)"
check "SSH_HARDENING_REVERT=yes in a dry run -> only announced" bash -c \
  'grep -q "file: present" <<< "$1" && grep -q "\[dry-run\] remove .*check with sshd -t (on failure restore it), then reload ssh" <<< "$1" && ! grep -q RELOADED <<< "$1"' _ "$out"
out="$(revert_case yes $'PasswordAuthentication no\n# written by someone else' 0)"
check "a file without our marker is never removed" bash -c 'grep -q "file: present" <<< "$1" && grep -q "WARN: .*not the file amoobot wrote" <<< "$1"' _ "$out"

port_case() { ( ssh_listen_ports() { printf '%s\n' $PORTS; }; PORTS="$1"; firewall_port_problem ) }
check "FIREWALL=yes with SSH on 22 -> no problem" test -z "$(port_case 22)"
check "FIREWALL=yes with SSH on 2222 -> refused (would be locked out)" grep -q "SSH listens on port(s) 2222 but FIREWALL=yes allows only 22" <<< "$(port_case 2222)"
check "FIREWALL=yes is refused before any ufw command in a real run" bash -c \
  'b=$(sed -n "/^if \[\[ \"\$FIREWALL\" == yes \]\]; then$/,/^else$/p" "$1"); p=$(grep -n "firewall_port_problem" <<< "$b" | head -1 | cut -d: -f1); u=$(grep -n "run ufw" <<< "$b" | head -1 | cut -d: -f1); [[ -n $p && -n $u && $p -lt $u ]]' _ "$ROOT/deploy.sh"
fw_case() {  # fw_case "<ufw status output>" -> log + every ufw call
  ( ufw() { echo "UFW $*" >> "$WORK/ufw.calls"; [[ "$1" == status ]] && printf '%s\n' "$UFW_STATUS"; }
    warn() { printf 'WARN: %s\n' "$*"; }
    DRY_RUN=0 UFW_STATUS="$1"; : > "$WORK/ufw.calls"; report_firewall_needs; cat "$WORK/ufw.calls" )
}
out="$(fw_case 'Status: inactive')"
check "FIREWALL=no, ufw inactive -> reported, left inactive (only 'ufw status' called)" bash -c \
  'grep -q "ufw is not active (left as it is)" <<< "$1" && [[ $(grep -c "^UFW " <<< "$1") -eq 1 ]] && grep -q "^UFW status$" <<< "$1"' _ "$out"
out="$(fw_case $'Status: active\n\nTo Action From\n-- ------ ----\n22/tcp ALLOW Anywhere')"
check "FIREWALL=no, active ufw without 80/443 -> warned, rules unchanged" bash -c \
  'grep -q "WARN: ufw is active and does not allow 80 443/tcp" <<< "$1" && ! grep -qE "^UFW (allow|deny|enable|reset|default|delete)" <<< "$1"' _ "$out"
out="$(fw_case $'Status: active\n\nTo Action From\n-- ------ ----\n22/tcp ALLOW Anywhere\n80/tcp ALLOW Anywhere\n443 ALLOW Anywhere')"
check "FIREWALL=no, active ufw allowing 80 and 443 -> fine" grep -q "ufw is active and allows 80 and 443" <<< "$out"

echo "== root never follows deploy- or www-data-owned symlinks (group 2, 2nd review)"
set_globals
load_fn refuse_symlink read_as_user write_user_file write_file merge_authorized_keys update_deploy_keys set_app_permissions
# Run "sudo -u X cmd" / "as X cmd" as the current user (the test cannot switch users); record every call.
symlink_env() {
  sudo() { while (( $# )); do case "$1" in -H|-n) shift ;; -u) shift 2 ;; --) shift; break ;; *) break ;; esac; done; "$@"; }
  as() { shift; echo "as $*" >> "$WORK/sl.calls"; "$@"; }
  run() { echo "run $*" >> "$WORK/sl.calls"; "$@"; }
  chown() { echo "chown $*" >> "$WORK/sl.calls"; }
  die() { printf 'DIE: %s\n' "$*"; exit 1; }
}
SL="$WORK/sl"; mkdir -p "$SL"; echo "precious" > "$SL/victim"; chmod 644 "$SL/victim"
out="$( (symlink_env; ln -sfn "$SL/victim" "$SL/link"; refuse_symlink "$SL/ok" "$SL/link"; echo REACHED) )"
check "refuse_symlink stops on a link" bash -c 'grep -q "DIE: .*link is a symbolic link" <<< "$1" && ! grep -q REACHED <<< "$1"' _ "$out"
out="$( (symlink_env; DRY_RUN=0; ln -sfn "$SL/victim" "$SL/env-file"; printf 'SECRET=x\n' | write_user_file "$SL/env-file" 600 "$(id -un)"; echo rc=$?) 2>&1)"
check "write_user_file replaces a planted link instead of writing through it" bash -c \
  '[[ ! -L "$1/env-file" && -f "$1/env-file" && $(cat "$1/env-file") == "SECRET=x" && $(cat "$1/victim") == precious && $(stat -c %a "$1/victim") == 644 ]]' _ "$SL"
check "  ...with the requested mode" test "$(stat -c %a "$SL/env-file")" = 600
: > "$WORK/sl.calls"
( symlink_env; DRY_RUN=0; printf 'X=1\n' | write_user_file "$SL/env-local" 640 "$(id -un):www-data" ) > /dev/null 2>&1
check "write_user_file sets another group only with chown -h" grep -qx "chown -h $(id -un):www-data $SL/env-local" "$WORK/sl.calls"
out="$( (symlink_env; DRY_RUN=0; ln -sfn "$SL/victim" "$SL/rootfile"; printf 'x\n' | write_file "$SL/rootfile" 644 root:root; echo REACHED) 2>&1)"
check "write_file refuses to write through a link" bash -c 'grep -q "DIE: .*symbolic link" <<< "$1" && [[ $(cat "$2") == precious ]]' _ "$out" "$SL/victim"

if have ssh-keygen; then
  ssh-keygen -q -t ed25519 -N '' -C sl@example -f "$WORK/slkey" > /dev/null
  keys_case() {  # keys_case <setup: none|link-keys|link-dir> -> output; HOME dir in $SL/home
    rm -rf "$SL/home"; mkdir -p "$SL/home"
    case "$1" in
      link-keys) mkdir -p "$SL/home/.ssh"; ln -s "$SL/victim" "$SL/home/.ssh/authorized_keys" ;;
      link-dir)  mkdir -p "$SL/elsewhere"; ln -s "$SL/elsewhere" "$SL/home/.ssh" ;;
    esac
    ( symlink_env; DRY_RUN=0 DEPLOY_USER="$(id -un)" DEPLOY_SSH_PUBLIC_KEY="$(cat "$WORK/slkey.pub")"
      update_deploy_keys "$SL/home/.ssh/authorized_keys"; echo "REACHED preview=$(wc -l < "$KEYS_PREVIEW")" ) 2>&1
  }
  out="$(keys_case none)"
  check "authorized_keys written as the deploy user, mode 600, ~/.ssh 700" bash -c \
    'grep -q REACHED <<< "$1" && grep -qxF -- "$(cat "$3")" "$2/home/.ssh/authorized_keys" && [[ $(stat -c %a "$2/home/.ssh/authorized_keys") == 600 && $(stat -c %a "$2/home/.ssh") == 700 ]]' _ "$out" "$SL" "$WORK/slkey.pub"
  out="$(keys_case link-keys)"
  check "symlinked ~/.ssh/authorized_keys -> refused, link target untouched" bash -c \
    'grep -q "DIE: .*authorized_keys is a symbolic link" <<< "$1" && ! grep -q REACHED <<< "$1" && [[ $(cat "$2/victim") == precious && $(stat -c %a "$2/victim") == 644 ]]' _ "$out" "$SL"
  out="$(keys_case link-dir)"
  check "symlinked ~/.ssh -> refused, nothing written" bash -c \
    'grep -q "DIE: .*\.ssh is a symbolic link" <<< "$1" && [[ -z $(ls -A "$2/elsewhere") ]]' _ "$out" "$SL"
else
  skip "authorized_keys symlink tests (ssh-keygen not installed)"
fi

perm_case() {  # perm_case <path to replace with a link: var|public/bundles|public|none> -> output; calls in sl.calls
  local A="$SL/app"; rm -rf "$A"; mkdir -p "$A/public" "$SL/target"; : > "$A/.env.prod.local"; : > "$WORK/sl.calls"
  [[ "$1" != none ]] && { [[ "$1" == public ]] && rm -rf "$A/public"; ln -s "$SL/target" "$A/$1"; }
  ( symlink_env; DRY_RUN=0 APP_DIR="$A" DEPLOY_USER="$(id -un)" WEB_USER="$(id -un)"; set_app_permissions; echo REACHED ) 2>&1
}
out="$(perm_case none)"
check "step 10 without links: completes, root only uses chown -h / chown -R -h -P" bash -c \
  'grep -q REACHED <<< "$1" && ! grep -E "^(run )?chown " "$2" | grep -vqE "^(run )?chown (-h|-R -h -P) "' _ "$out" "$WORK/sl.calls"
check "  ...and every chmod runs as the owning user" bash -c '! grep -E "^run chmod" "$1" && grep -q "^as .*chmod -R ug+rwX,o-rwx" "$1"' _ "$WORK/sl.calls"
for l in var public/bundles public; do
  out="$(perm_case "$l")"
  check "step 10: a link at ${l} -> refused before any chown or chmod" bash -c \
    'grep -q "DIE: .*is a symbolic link" <<< "$1" && ! grep -qE "chown|chmod" "$2" && ! grep -q REACHED <<< "$1"' _ "$out" "$WORK/sl.calls"
done
check "no root 'install -d -o' / touch / >> on deploy-owned paths" bash -c \
  '! grep -nE "install -d [^#]*-o \"\\\$(WEB_USER|DEPLOY_USER)\"[^#]*(DEPLOY_HOME|APP_DIR/)" "$1" && ! grep -nE "touch \"\\\$auth_keys\"|>> \"\\\$auth_keys\"" "$1"' _ "$ROOT/deploy.sh"
check "every recursive chown uses -h -P (never follows links)" bash -c '! grep -nE "chown -R" "$1" | grep -v -- "-R -h"' _ "$ROOT/deploy.sh"
check "no root chmod on the checkout, var/ or the env files (only as the owner)" bash -c \
  '! grep -nE "^[[:space:]]*(run )?chmod .*APP_DIR" "$1"' _ "$ROOT/deploy.sh"
check "the app env files are written by the deploy user, not root" bash -c \
  'grep -q "write_user_file \"\$APP_DIR/.env.prod.local\" 600 \"\$DEPLOY_USER\"" "$1" && grep -q "write_user_file \"\$APP_DIR/.env.local\" 640" "$1"' _ "$ROOT/deploy.sh"
check "job lock files are created as www-data and links refused" bash -c \
  'sed -n "/^pause_scheduled_jobs() {/,/^}/p" "$1" | grep -q "sudo -H -u \"\$WEB_USER\"" && sed -n "/^pause_scheduled_jobs() {/,/^}/p" "$1" | grep -q "refuse_symlink \"\$lock\""' _ "$ROOT/deploy.sh"
if can_dry_run; then
  OUT="$BASE_OUT"
  check "dry-run: .env.prod.local written as the deploy user via temp file + rename" grep -qF '[dry-run] write /var/www/amoobot/.env.prod.local (600 deploy, written as deploy via temp file + rename)' <<< "$OUT"
else
  skip "$DRY_RUN_SKIP"
fi

echo "== --env-file: opened once, checked via the fd, only from /etc/amoobot as root (group 3, 2nd review)"
set_globals
load_fn open_env_file load_env_file refuse_symlink
check "load_env_file reads only from the already-opened fd 3" bash -c \
  'sed -n "/^load_env_file() {/,/^}/p" "$1" | grep -q "done <&3" && ! sed -n "/^load_env_file() {/,/^}/p" "$1" | grep -q "done < \"\$file\""' _ "$ROOT/deploy.sh"
check "the opened file is checked through /proc/self/fd/3 (stat -L)" grep -q "stat -L -c '%a %U %F' /proc/self/fd/3" "$ROOT/deploy.sh"
if [[ "$EUID" -eq 0 ]] && have setpriv; then
  ED="$WORK/etc-amoobot"; install -d -m 700 "$ED"; printf 'DOMAIN=first.example.test\n' > "$ED/.env.prod.local"; chmod 600 "$ED/.env.prod.local"
  env_case() { ( die() { printf 'DIE: %s\n' "$*"; exit 1; }; warn() { :; }; log() { :; }
                 ENV_FILE_DIR="$ED" ENV_FILE_KEYS=(); unset DOMAIN
                 if open_env_file "$1"; then eval "${2:-:}"; load_env_file "$1"; echo "DOMAIN=${DOMAIN:-}"; else echo MISSING; fi ) 2>&1; }
  check "root: a 600 root-owned file in the directory is read" test "$(env_case "$ED/.env.prod.local")" = "DOMAIN=first.example.test"
  out="$(env_case "$ED/.env.prod.local" 'printf "DOMAIN=swapped.example.test\n" > "$ED/new"; chmod 600 "$ED/new"; mv -f "$ED/new" "$ED/.env.prod.local"')"
  check "root: swapping the file after the check does not change what is read (same fd)" test "$out" = "DOMAIN=first.example.test"
  printf 'DOMAIN=first.example.test\n' > "$ED/.env.prod.local"; chmod 600 "$ED/.env.prod.local"
  check "root: a path outside the directory is refused" grep -q 'DIE: --env-file must be a file directly in' <<< "$(env_case "$WORK/prod.env")"
  check "root: '..' out of the directory is refused" grep -q 'DIE: --env-file must be a file directly in' <<< "$(env_case "$ED/../prod.env")"
  check "root: a subdirectory path is refused" grep -q 'DIE: --env-file must be a file directly in' <<< "$(env_case "$ED/sub/x.env")"
  ln -sfn "$WORK/prod.env" "$ED/link.env"
  check "root: a symlink in the directory is refused" grep -q 'DIE: .*link.env is a symbolic link' <<< "$(env_case "$ED/link.env")"
  cp "$ED/.env.prod.local" "$ED/wide.env"; chmod 644 "$ED/wide.env"
  check "root: mode 644 is refused" grep -q 'DIE: .*must be owned by root with mode 600 (is: 644 root)' <<< "$(env_case "$ED/wide.env")"
  cp "$ED/.env.prod.local" "$ED/nobody.env"; chmod 600 "$ED/nobody.env"; chown 65534 "$ED/nobody.env"
  check "root: a file not owned by root is refused" grep -q 'DIE: .*must be owned by root with mode 600' <<< "$(env_case "$ED/nobody.env")"
  mkdir -p "$ED/dir.env"
  check "root: a directory is refused" grep -q 'DIE: .*not a regular file' <<< "$(env_case "$ED/dir.env")"
  chmod 770 "$ED"
  check "root: a group-writable parent directory is refused" grep -q 'DIE: .*must be a root-owned directory not writable' <<< "$(env_case "$ED/.env.prod.local")"
  chmod 700 "$ED"
  check "root: a missing default file is reported as missing (not an error)" test "$(env_case "$ED/none.env")" = MISSING
  if can_dry_run; then
    out="$(bash "$ROOT/deploy.sh" --dry-run --env-file "$WORK/prod.env" 2>&1)"; rc=$?
    check "root: even --dry-run refuses an --env-file outside /etc/amoobot (no reading root-only files)" bash -c \
      '[[ $1 -ne 0 ]] && grep -q "env-file must be a file directly in /etc/amoobot/" <<< "$2"' _ "$rc" "$out"
  fi
else
  skip "--env-file root checks (need root and setpriv: run in the Linux/WSL container as root)"
fi

echo "== DEPLOY_REF validation (group 4, 2nd review)"
if can_dry_run; then
  for ref in main release/v1 v1.2.3 feature_x-1 0123456789abcdef0123456789abcdef01234567; do
    { cat "$WORK/prod.env"; echo "DEPLOY_REF=$ref"; } > "$WORK/ref.env"; dry_run "$WORK/ref.env"
    check "DEPLOY_REF '$ref' accepted" test "$RC" -eq 0
  done
  for ref in -main --upload-pack=x 'main;id' 'a b' 'v1..v2' '$(id)' 'main^{tree}' 'x:y' '~1'; do
    { cat "$WORK/prod.env"; printf 'DEPLOY_REF=%s\n' "$ref"; } > "$WORK/ref.env"; dry_run "$WORK/ref.env"
    check "DEPLOY_REF '$ref' refused in the preflight" bash -c '[[ $1 -ne 0 ]] && grep -q "DEPLOY_REF / --ref must match" <<< "$2" && ! grep -q "^\[1/18\]" <<< "$2"' _ "$RC" "$OUT"
  done
else
  skip "$DRY_RUN_SKIP"
fi

echo "== input validation: BACKUP_CRON, PHP_VERSION, REPO_URL, option-file values (group 6, 2nd review)"
if can_dry_run; then
  val_case() {  # val_case KEY VALUE -> RC, OUT
    { grep -v "^$1=" "$WORK/prod.env"; printf '%s=%s\n' "$1" "$2"; } > "$WORK/val.env"; dry_run "$WORK/val.env"
  }
  for v in '30 3 * * *' '*/15 * * * *' '0 1,13 * * 1-5' '@daily' '@hourly' '@weekly'; do
    val_case BACKUP_CRON "$v"; check "BACKUP_CRON '$v' accepted" test "$RC" -eq 0
  done
  for v in '30 3 * *' '30 3 * * * root touch /tmp/x #' '@reboot' '* * * * * ;id' "30 3 * * *\ty" 'a b c d e'; do
    val_case BACKUP_CRON "$v"
    check "BACKUP_CRON '$v' refused" bash -c '[[ $1 -ne 0 ]] && grep -q "BACKUP_CRON must be five cron fields" <<< "$2"' _ "$RC" "$OUT"
  done
  val_case BACKUP_CRON '15 4 * * 0'
  check "accepted BACKUP_CRON is what the root cron line gets" grep -qF '+ 15 4 * * 0 root /usr/local/sbin/amoobot-backup nightly' <<< "$OUT"
  for v in 8.4 8.3 10.0; do val_case PHP_VERSION "$v"; check "PHP_VERSION '$v' accepted" test "$RC" -eq 0; done
  for v in '8' '8.4;' '8.4 nginx' '../8.4' '8.4.1' 'x.y'; do
    val_case PHP_VERSION "$v"
    check "PHP_VERSION '$v' refused" bash -c '[[ $1 -ne 0 ]] && grep -q "PHP_VERSION must look like 8.4" <<< "$2"' _ "$RC" "$OUT"
  done
  for v in git@github.com:hforouzi/amoobot.git ssh://git@github.com/hforouzi/amoobot.git ssh://git@git.example.com:2222/a/b.git; do
    val_case REPO_URL "$v"; check "REPO_URL '$v' accepted" test "$RC" -eq 0
  done
  for v in '-oProxyCommand=x' 'https://github.com/hforouzi/amoobot.git' 'file:///tmp/repo' 'ext::sh -c x' '/srv/repo.git' 'git@github.com:a/b c.git' "git@github.com:a/b'.git" 'ssh://git@host/a"b'; do
    val_case REPO_URL "$v"
    check "REPO_URL '$v' refused" bash -c '[[ $1 -ne 0 ]] && grep -q "REPO_URL must be an SSH URL" <<< "$2"' _ "$RC" "$OUT"
  done
else
  skip "$DRY_RUN_SKIP"
fi
if have php; then
  opt_case() {  # opt_case DATABASE_URL -> output, rc; the options file is printed when written
    env -u DATABASE_URL bash -c 'source "$1"; DATABASE_URL="$2" load_database_url /nonexistent; write_db_options_file; cat "$DB_OPTS_FILE"; rm -f "$DB_OPTS_FILE"' \
      _ "$ROOT/bin/lib/database-url.sh" "$1" 2>&1
  }
  out="$(opt_case 'mysql://app:p%22w%5Cd@db.internal:3307/amoobot')"
  check "option file: normal user/host, password quote and backslash escaped" bash -c \
    'grep -qx "user=\"app\"" <<< "$1" && grep -qx "host=\"db.internal\"" <<< "$1" && grep -qxF "password=\"p\\\"w\\\\d\"" <<< "$1" && grep -qx "port=3307" <<< "$1"' _ "$out"
  for u in 'a%0Ahost%3Devil' 'a%22b' 'a%5Cb' 'a%09b'; do
    out="$(opt_case "mysql://${u}:pw@127.0.0.1:3306/amoobot")"; rc=$?
    check "option file: user '${u}' (control char/quote/backslash) refused, nothing written" bash -c \
      '[[ $1 -ne 0 ]] && grep -q "contains a control character, quote or backslash" <<< "$2" && ! grep -q "^\[client\]" <<< "$2"' _ "$rc" "$out"
  done
  out="$(opt_case 'mysql://app:pw%0Aplugin-dir%3D%2Ftmp@127.0.0.1:3306/amoobot')"; rc=$?
  check "option file: a newline in the password is refused" bash -c '[[ $1 -ne 0 ]] && grep -q "password contains a control character" <<< "$2"' _ "$rc" "$out"
else
  skip "option-file value tests (php not installed)"
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

# --ref rollback: a target commit must contain every root-owned ops file (checked with git cat-file -e in the mirror).
if have git; then
  load_fn mirror_git git_blob verify_target_ops
  eval "$(sed -n '/^ROOT_OPS_FILES=(/,/)$/p' "$ROOT/deploy.sh")"
  R="$WORK/repo"; git init -q "$R"; git -C "$R" config user.email t@t; git -C "$R" config user.name t
  for f in "${ROOT_OPS_FILES[@]}" bin/console; do mkdir -p "$R/$(dirname "$f")"; echo "# $f" > "$R/$f"; done
  printf '%s\n' 'ROOT_LIB_DIR="/usr/local/lib/amoobot"' 'MIRROR_DIR="/var/lib/amoobot/mirror.git"' >> "$R/deploy.sh"
  git -C "$R" add -A; git -C "$R" commit -qm good; GOOD="$(git -C "$R" rev-parse HEAD)"
  sed -i 's#^MIRROR_DIR=.*##' "$R/deploy.sh"; git -C "$R" commit -qam pre-mirror; PREMIRROR="$(git -C "$R" rev-parse HEAD)"
  sed -i 's#^ROOT_LIB_DIR=.*##' "$R/deploy.sh"; git -C "$R" commit -qam old-layout; OLD="$(git -C "$R" rev-parse HEAD)"
  git -C "$R" rm -q bin/check-scheduled-jobs.sh; git -C "$R" commit -qm missing; MISSING="$(git -C "$R" rev-parse HEAD)"
  verify_case() { ( die() { printf 'DIE: %s\n' "$*"; exit 1; }
                    MIRROR_DIR="$R/.git"; verify_target_ops "$1" && echo OK ) 2>&1; }
  check "target with every ops file and the root layout -> accepted" test "$(verify_case "$GOOD")" = OK
  check "target missing an ops file -> refused, nothing paused" grep -q 'DIE: Refusing to deploy .* lacks bin/check-scheduled-jobs.sh. Nothing was paused' <<< "$(verify_case "$MISSING")"
  check "target with a pre-hardening deploy.sh -> refused" grep -q 'DIE: Refusing to deploy .* predates the root-owned mirror' <<< "$(verify_case "$OLD")"
  check "target whose deploy.sh still installs from the checkout (no mirror) -> refused" grep -q 'DIE: Refusing to deploy .* predates the root-owned mirror' <<< "$(verify_case "$PREMIRROR")"
  check "the real pre-hardening commit 250ff44 would be refused" bash -c \
    '! git -C "$1" show 250ff44:deploy.sh 2>/dev/null | grep -qx "ROOT_LIB_DIR=\"/usr/local/lib/amoobot\""' _ "$ROOT"
  check "this checkout's deploy.sh carries both layout markers verify_target_ops looks for" bash -c \
    'grep -qx "ROOT_LIB_DIR=\"/usr/local/lib/amoobot\"" "$1" && grep -qx "MIRROR_DIR=\"/var/lib/amoobot/mirror.git\"" "$1"' _ "$ROOT/deploy.sh"
else
  skip "rollback verification (git not installed)"
fi

echo "== root-owned mirror: what root installs never comes from the deploy user's repository (group 1, 2nd review)"
check "git_blob / verify_target_ops read only the mirror (no git -C \$APP_DIR, no sudo -u deploy)" bash -c \
  'for f in git_blob verify_target_ops resolve_target_ref; do sed -n "/^$f() {/,/^}/p" "$1" | grep -qE "APP_DIR|DEPLOY_USER" && exit 1; done; exit 0' _ "$ROOT/deploy.sh"
check "TARGET_COMMIT is resolved in the mirror, never with git -C \$APP_DIR rev-parse" bash -c \
  '! grep -nE "git -C \"\\\$APP_DIR\" rev-parse --verify" "$1" && grep -q "TARGET_COMMIT=\"\$(resolve_target_ref \"\$DEPLOY_REF\")\"" "$1"' _ "$ROOT/deploy.sh"
check "mirror git runs with a clean environment (no global/system config, no replace refs)" bash -c \
  'sed -n "/^mirror_git() {/,/^}/p" "$1" | grep -q "env -i .*GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_NO_REPLACE_OBJECTS=1"' _ "$ROOT/deploy.sh"
check "mirror ssh: pinned known_hosts, StrictHostKeyChecking=yes, no user ssh config" bash -c \
  'grep -q "^MIRROR_SSH=\"ssh -F /dev/null -i \${ROOT_REPO_KEY} -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=\${repo_known_hosts} -o GlobalKnownHostsFile=/dev/null\"" "$1"' _ "$ROOT/deploy.sh"
if have git && [[ "$EUID" -eq 0 ]]; then
  load_fn mirror_git git_blob update_mirror resolve_target_ref verify_allowed_signers_file fill_checkout
  O="$WORK/origin.git"; git init -q --bare "$O"
  W="$WORK/upstream"; git init -q -b main "$W"; git -C "$W" config user.email t@t; git -C "$W" config user.name t
  echo 'echo v1' > "$W/deploy.sh"; git -C "$W" add -A; git -C "$W" commit -qm one; C1="$(git -C "$W" rev-parse HEAD)"
  echo 'echo v2' > "$W/deploy.sh"; git -C "$W" commit -qam two; C2="$(git -C "$W" rev-parse HEAD)"
  git -C "$W" tag -a v1 -m "annotated" "$C1"; git -C "$W" tag light "$C1"
  git -C "$W" branch gone "$C1"
  git -C "$W" push -q "$O" main gone v1 light
  mirror_case() { ( die() { printf 'DIE: %s\n' "$*"; exit 1; }; MIRROR_DIR="$WORK/mirror.git" MIRROR_PROTOCOL=file STATE_DIR="$WORK/state"
                    REPO_URL="file://$O"; "$@" ) 2>&1; }
  out="$(mirror_case bash -c 'true')"  # (subshell sanity)
  out="$(mirror_case update_mirror; echo "ok=$?")"
  check "mirror created and fetched as root" bash -c '[[ -f "$1/HEAD" && $(stat -c "%U %a" "$1") == "root 700" ]]' _ "$WORK/mirror.git"
  res() { mirror_case resolve_target_ref "$1"; }
  check "--ref main resolves to origin's main" test "$(res main)" = "$C2"
  check "--ref v1 (annotated tag) resolves to the tagged commit" test "$(res v1)" = "$C1"
  check "--ref <full hash contained in an origin ref> is accepted" test "$(res "$C1")" = "$C1"
  check "--ref <short hash> is refused" grep -q 'is not a branch or tag on origin' <<< "$(res "${C1:0:12}")"
  # A commit created only in the deploy user's checkout is not in the mirror at all.
  D="$WORK/deploy-checkout"; git clone -q "$O" "$D"; git -C "$D" config user.email x@x; git -C "$D" config user.name x
  echo 'echo forged' > "$D/deploy.sh"; git -C "$D" commit -qam forged; FORGED="$(git -C "$D" rev-parse HEAD)"
  check "a commit that exists only in the deploy user's checkout is refused" grep -q 'is not a branch or tag on origin' <<< "$(res "$FORGED")"
  # An object that is in the mirror but reachable from no origin ref (e.g. after a force-push) is refused too.
  DANGLING="$(mirror_case mirror_git commit-tree -m dangling "$(mirror_case mirror_git rev-parse "$C1^{tree}")")"
  check "a commit in the mirror that no origin ref contains is refused" grep -q 'is not a branch or tag on origin' <<< "$(res "$DANGLING")"
  git -C "$W" push -q "$O" :gone; mirror_case update_mirror > /dev/null
  check "a branch deleted on origin is pruned from the mirror and refused" grep -q 'is not a branch or tag on origin' <<< "$(res gone)"
  check "git_blob reads the commit from the mirror, not the checkout" test "$(mirror_case git_blob "$C2" deploy.sh)" = "echo v2"

  if have ssh-keygen; then
    ssh-keygen -q -t ed25519 -N '' -C signer -f "$WORK/signer" > /dev/null; ssh-keygen -q -t ed25519 -N '' -C other -f "$WORK/other" > /dev/null
    git -C "$W" -c gpg.format=ssh -c user.signingkey="$WORK/signer" tag -s v2 -m signed "$C2"
    git -C "$W" -c gpg.format=ssh -c user.signingkey="$WORK/other" tag -s v2-other -m signed-by-other "$C2"
    git -C "$W" push -q "$O" v2 v2-other; mirror_case update_mirror > /dev/null
    printf 't@t namespaces="git" %s\n' "$(cut -d' ' -f1,2 < "$WORK/signer.pub")" > "$WORK/allowed_signers"; chmod 644 "$WORK/allowed_signers"
    sres() { ( ALLOWED_SIGNERS_FILE="$WORK/allowed_signers" REQUIRE_SIGNED_TAG=yes; mirror_case resolve_target_ref "$1" ); }
    check "REQUIRE_SIGNED_TAG: tag signed by an allowed key -> accepted" test "$(sres v2)" = "$C2"
    check "REQUIRE_SIGNED_TAG: tag signed by another key -> refused" grep -q 'no valid signature' <<< "$(sres v2-other)"
    check "REQUIRE_SIGNED_TAG: unsigned annotated tag -> refused" grep -q 'no valid signature' <<< "$(sres v1)"
    check "REQUIRE_SIGNED_TAG: lightweight tag -> refused" grep -q 'lightweight tag' <<< "$(sres light)"
    check "REQUIRE_SIGNED_TAG: a branch -> refused" grep -q 'is not a tag on origin' <<< "$(sres main)"
    chmod 664 "$WORK/allowed_signers"
    check "REQUIRE_SIGNED_TAG: group-writable allowed-signers file -> refused" grep -q 'not writable by group/others' <<< "$(sres v2)"
    chmod 644 "$WORK/allowed_signers"
    check "REQUIRE_SIGNED_TAG: missing allowed-signers file -> refused" grep -q 'needs' <<< "$( (ALLOWED_SIGNERS_FILE="$WORK/none" REQUIRE_SIGNED_TAG=yes; mirror_case resolve_target_ref v2) )"
  else
    skip "signed tag tests (ssh-keygen not installed)"
  fi

  # fill_checkout: clone (first deploy) and later fetch, both from a bundle; the deploy user needs no GitHub access.
  fill_case() { ( die() { printf 'DIE: %s\n' "$*"; exit 1; }
                  sudo() { while (( $# )); do case "$1" in -H|-n) shift ;; -u) shift 2 ;; --) shift; break ;; *) break ;; esac; done; "$@"; }
                  MIRROR_DIR="$WORK/mirror.git" MIRROR_PROTOCOL=file BUNDLE_DIR="$WORK/bundle" DEPLOY_USER=root APP_DIR="$WORK/app-checkout"
                  REPO_URL="file://$O"; fill_checkout "$1"; git -C "$APP_DIR" rev-parse HEAD ) 2>&1; }
  rm -rf "$WORK/app-checkout"; mkdir -p "$WORK/app-checkout"
  check "first deploy: checkout cloned from the bundle at the resolved commit" test "$(fill_case "$C1" | tail -1)" = "$C1"
  check "next deploy: checkout fetched from the bundle and moved to the new commit" test "$(fill_case "$C2" | tail -1)" = "$C2"
  check "the bundle is removed after the fetch" test ! -e "$WORK/bundle/release.bundle"
  check "the checkout's origin is REPO_URL (for people), never fetched by root" test "$(git -C "$WORK/app-checkout" remote get-url origin)" = "file://$O"
else
  skip "mirror tests (need git and root: run in the Linux/WSL container as root)"
fi
if can_dry_run; then
  OUT="$BASE_OUT"
  check "dry-run fetches the repository as root into the root-owned mirror" grep -qF '[dry-run] fetch git@github.com:hforouzi/amoobot.git AS ROOT into /var/lib/amoobot/mirror.git (root:root 700) with /etc/amoobot/repo_deploy_ed25519' <<< "$OUT"
  check "dry-run resolves the ref in the mirror before pausing anything" bash -c \
    'v=$(grep -n "resolve main in the root-owned mirror" <<< "$1" | cut -d: -f1); p=$(grep -n "pause scheduled jobs" <<< "$1" | cut -d: -f1); [[ -n $v && -n $p && $v -lt $p ]]' _ "$OUT"
  check "dry-run fills the checkout from a bundle, not from GitHub" grep -q 'from a bundle of the root-owned mirror (no GitHub access for deploy)' <<< "$OUT"
  check "dry-run never configures a deploy-user git key any more" not grep -q 'core.sshCommand' <<< "$OUT"
else
  skip "$DRY_RUN_SKIP"
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

echo "== restore safety and schema-vs-code before the checkout (group 9, 2nd review)"
if have gzip && have php; then
  RB="$WORK/rb"; mkdir -p "$RB/backups" "$RB/elsewhere" "$RB/bin"
  printf 'CREATE TABLE t (id int);\n-- Dump completed on 2026-10-14\n' | gzip > "$RB/backups/amoobot_manual_20261014T000000Z.sql.gz"
  cp "$RB/backups/amoobot_manual_20261014T000000Z.sql.gz" "$RB/elsewhere/outside.sql.gz"
  mkdir -p "$RB/backups/sub"; cp "$RB/backups/amoobot_manual_20261014T000000Z.sql.gz" "$RB/backups/sub/nested.sql.gz"
  ln -s "$RB/elsewhere/outside.sql.gz" "$RB/backups/link.sql.gz"
  # Stub client: records what it is asked, answers counts with 0.
  printf '#!/usr/bin/env bash\necho "client $*" >> "%s/client.calls"; cat > /dev/null; echo 0\n' "$RB" > "$RB/bin/mariadb"; chmod +x "$RB/bin/mariadb"
  printf '#!/usr/bin/env bash\n[[ "$1" == list-units ]] && printf "%%s" "$ACTIVE_UNITS"\nexit 0\n' > "$RB/bin/systemctl"; chmod +x "$RB/bin/systemctl"
  restore_case() {  # restore_case <file> "<active units>" -> output, rc
    : > "$RB/client.calls"
    PATH="$RB/bin:$PATH" AMOOBOT_SYSTEMCTL="$RB/bin/systemctl" ACTIVE_UNITS="$2" BACKUP_DIR="$RB/backups" \
      DATABASE_URL='mysql://u:p@127.0.0.1:3306/amoobot' bash "$ROOT/bin/restore-database.sh" "$1" --yes --no-safety-backup 2>&1
  }
  out="$(restore_case "$RB/backups/amoobot_manual_20261014T000000Z.sql.gz" "")"; rc=$?
  check "restore: a dump in BACKUP_DIR with nothing live -> restored" bash -c '[[ $1 -eq 0 ]] && grep -q "Restore finished" <<< "$2" && grep -q -- "--binary-mode" "$3"' _ "$rc" "$out" "$RB/client.calls"
  for f in "$RB/elsewhere/outside.sql.gz" "$RB/backups/sub/nested.sql.gz" "$RB/backups/../elsewhere/outside.sql.gz"; do
    out="$(restore_case "$f" "")"; rc=$?
    check "restore: $(basename "$f") outside BACKUP_DIR (or nested) -> refused, database untouched" bash -c \
      '[[ $1 -ne 0 ]] && grep -q "must be a regular file directly in" <<< "$2" && [[ ! -s "$3" ]]' _ "$rc" "$out" "$RB/client.calls"
  done
  out="$(restore_case "$RB/backups/link.sql.gz" "")"; rc=$?
  check "restore: a symlink in BACKUP_DIR -> refused" bash -c '[[ $1 -ne 0 ]] && grep -q "Refusing a symbolic link" <<< "$2" && [[ ! -s "$3" ]]' _ "$rc" "$out" "$RB/client.calls"
  for units in 'php8.4-fpm.service loaded active running PHP' 'amoobot-job-sync.timer loaded active waiting x' 'amoobot-job-orders.service loaded activating start x'; do
    out="$(restore_case "$RB/backups/amoobot_manual_20261014T000000Z.sql.gz" "$units")"; rc=$?
    check "restore: refused while ${units%% *} is active, database untouched" bash -c \
      '[[ $1 -ne 0 ]] && grep -q "Still active: $3" <<< "$2" && grep -q "systemctl stop php8.4-fpm" <<< "$2" && [[ ! -s "$4" ]]' _ "$rc" "$out" "${units%% *}" "$RB/client.calls"
  done
  check "restore: the live check asks for running oneshot jobs too (activating)" grep -q -- "--state=active,activating,reloading" "$ROOT/bin/restore-database.sh"
else
  skip "restore tests (need gzip and php)"
fi

if have git && [[ "$EUID" -eq 0 ]]; then
  set_globals
  load_fn mirror_git migration_ids target_migrations count_lines check_schema_matches restore_instructions
  SR="$WORK/schema-repo"; git init -q "$SR"; git -C "$SR" config user.email t@t; git -C "$SR" config user.name t
  mkdir -p "$SR/migrations"; touch "$SR/migrations/Version20260101000000.php" "$SR/migrations/Version20260201000000.php" "$SR/migrations/README.md"
  git -C "$SR" add -A; git -C "$SR" commit -qm old; OLDC="$(git -C "$SR" rev-parse HEAD)"
  check "target migrations are read from the commit (mirror), as bare ids" test \
    "$( (MIRROR_DIR="$SR/.git" MIRROR_PROTOCOL=file; target_migrations "$OLDC") | tr '\n' ' ')" = 'Version20260101000000 Version20260201000000 '
  : > "$WORK/db.env"; mkdir -p "$WORK/rlib/lib"; : > "$WORK/rlib/lib/database-url.sh"
  schema_case() {  # schema_case "<executed versions>" [db readable 0|1] -> output
    ( die() { printf 'DIE: %s\n' "$*"; exit 1; }; log() { echo "LOG: $*"; }
      executed_migrations() { [[ "${DB_OK:-1}" == 1 ]] || return 1; printf '%s' "$EXECUTED"; }
      MIRROR_DIR="$SR/.git" MIRROR_PROTOCOL=file DB_URL_FILE="$WORK/db.env" ROOT_LIB_DIR="$WORK/rlib" EXECUTED="$1" DB_OK="${2:-1}"
      DEPLOY_REF=v1.0 TARGET_COMMIT="$OLDC" PHP_VERSION=8.4 DB_NAME=amoobot BACKUP_DIR=/var/backups/amoobot
      check_schema_matches "$OLDC"; echo REACHED ) 2>&1
  }
  out="$(schema_case $'DoctrineMigrations\\Version20260101000000\nDoctrineMigrations\\Version20260201000000')"
  check "database at the target's schema -> deploy continues" grep -q REACHED <<< "$out"
  out="$(schema_case $'DoctrineMigrations\\Version20260101000000')"
  check "database behind the target (pending migrations) -> deploy continues (step 11 migrates)" grep -q REACHED <<< "$out"
  out="$(schema_case $'DoctrineMigrations\\Version20260101000000\nDoctrineMigrations\\Version20260201000000\nDoctrineMigrations\\Version20260301000000')"
  check "database AHEAD of the target -> refused before anything is paused, with the unknown version listed" bash -c \
    'grep -q "DIE: The database has 1 executed migration(s) that .* does not contain" <<< "$1" && grep -q "Version20260301000000" <<< "$1" && ! grep -q REACHED <<< "$1"' _ "$out"
  check "  ...and restore instructions that say nothing was paused and how to get a matching schema" bash -c \
    'grep -q "Nothing was paused or checked out; the running release is untouched" <<< "$1" && grep -q "sudo amoobot-restore .* --yes" <<< "$1" && grep -q -- "--ref $2" <<< "$1" && ! grep -q "stay PAUSED" <<< "$1"' _ "$out" "$OLDC"
  check "database unreadable -> stop before pausing (not silently skipped)" grep -q 'DIE: Cannot read the executed migrations' <<< "$(schema_case 'x' 0)"
  out="$( (die() { printf 'DIE: %s\n' "$*"; exit 1; }; log() { echo "LOG: $*"; }; DB_URL_FILE="$WORK/none" ROOT_LIB_DIR="$WORK/rlib"
           check_schema_matches "$OLDC"; echo REACHED) 2>&1 )"
  check "first deploy (no database.env yet) -> check skipped with a note" bash -c 'grep -q "LOG: No .* yet (first deploy)" <<< "$1" && grep -q REACHED <<< "$1"' _ "$out"
  check "schema check runs right after the ops check, before the jobs are paused" bash -c \
    'c=$(grep -n "^  check_schema_matches \"\$TARGET_COMMIT\"" "$1" | cut -d: -f1); p=$(grep -n "^pause_scheduled_jobs$" "$1" | cut -d: -f1); [[ -n $c && -n $p && $c -lt $p ]]' _ "$ROOT/deploy.sh"
  check "up-to-date --fail-on-unregistered stays as the second line of defence" grep -q 'console doctrine:migrations:up-to-date --fail-on-unregistered' "$ROOT/deploy.sh"
else
  skip "schema-vs-code tests (need git and root: Linux container)"
fi

# Schema-vs-code check against realistic data (the first update of a populated database refused with ALL 30 executed
# migrations listed, printed as DoctrineMigrations\\Version...). Fixtures: fixtures/migrations.ls-tree is a real
# `git ls-tree --name-only <commit> migrations/` (32 migrations + .gitignore); fixtures/doctrine_migration_versions.rows is
# what that server's doctrine_migration_versions held: the first 30, stored with ONE backslash. The fake mariadb client
# behaves like mariadb 10.11 (checked against the real client): -B escapes that backslash as \\ unless -r is given.
FIX="$ROOT/tests/ops/fixtures"
SC="$WORK/schema-real"; mkdir -p "$SC/bin" "$SC/rlib/lib"
cat > "$SC/bin/mariadb" <<'FAKE'
#!/usr/bin/env bash
raw=0 sql=""
while (( $# )); do case "$1" in -r|--raw) raw=1 ;; -e) sql="$2"; shift ;; esac; shift; done
case "$sql" in
  *"table_name = 'doctrine_migration_versions'"*) echo "${FAKE_HAS_TABLE:-1}" ;;
  *"FROM information_schema.tables"*) echo "${FAKE_TABLES:-0}" ;;
  "SELECT version FROM doctrine_migration_versions")
    (( raw )) && cat "$FAKE_ROWS" || sed 's/\\/\\\\/g' "$FAKE_ROWS" ;;
  *) echo "fake mariadb: unexpected query: $sql" >&2; exit 1 ;;
esac
FAKE
chmod +x "$SC/bin/mariadb"
cat > "$SC/rlib/lib/database-url.sh" <<'LIB'
load_database_url() { DB_NAME=amoobot; }
write_db_options_file() { DB_OPTS_FILE="$(mktemp)"; }
LIB
: > "$SC/db.env"
fqcn() { sed -nE 's#^migrations/(Version[0-9]{14})\.php$#DoctrineMigrations\\\1#p' "$FIX/migrations.ls-tree"; }
fqcn > "$SC/all.rows"
{ fqcn; echo 'DoctrineMigrations\Version20261101000000'; } > "$SC/ahead.rows"
: > "$SC/empty.rows"
grep -v '^migrations/Version' "$FIX/migrations.ls-tree" > "$SC/nomig.ls-tree"
sed $'s/$/  \r/' "$FIX/doctrine_migration_versions.rows" > "$SC/crlf.rows"; printf '\r\n  \n' >> "$SC/crlf.rows"
sed $'s/$/\r/' "$FIX/migrations.ls-tree" > "$SC/crlf.ls-tree"
{ cat "$FIX/doctrine_migration_versions.rows"; echo 'App\Migrations\Initial'; } > "$SC/odd.rows"
set_globals
load_fn database_table_count executed_migrations migration_ids target_migrations count_lines check_schema_matches \
  restore_instructions dry_run_schema_counts
# real_case ROWS LS_TREE [has_table] [tables] [ls-tree rc]  ->  check_schema_matches output, then REACHED if it let the deploy on
real_case() {
  ( die() { printf 'DIE: %s\n' "$*"; exit 1; }; log() { echo "LOG: $*"; }
    mirror_git() { [[ "$1 $2 $3" == "ls-tree --name-only C0FFEE" ]] || return 9; cat "$LS"; return "$LS_RC"; }
    PATH="$SC/bin:$PATH" FAKE_ROWS="$1" LS="$2" FAKE_HAS_TABLE="${3:-1}" FAKE_TABLES="${4:-40}" LS_RC="${5:-0}"
    export FAKE_ROWS FAKE_HAS_TABLE FAKE_TABLES
    DB_URL_FILE="$SC/db.env" ROOT_LIB_DIR="$SC/rlib" DEPLOY_REF=release/v1 TARGET_COMMIT=C0FFEE PHP_VERSION=8.4 DB_NAME=amoobot
    check_schema_matches C0FFEE; echo REACHED ) 2>&1
}
check "fixtures: 33 ls-tree entries (32 migrations + .gitignore), 30 executed rows with ONE backslash" bash -c \
  '[[ $(wc -l < "$1") -eq 33 && $(grep -c "^migrations/Version[0-9]\{14\}\.php$" "$1") -eq 32 && $(wc -l < "$2") -eq 30 ]] && grep -q "^DoctrineMigrations\\\\Version20261013100000$" "$2"' \
  _ "$FIX/migrations.ls-tree" "$FIX/doctrine_migration_versions.rows"
check "executed_migrations reads raw (-r): one backslash, exactly as Doctrine stores it" test \
  "$(PATH="$SC/bin:$PATH" FAKE_ROWS="$FIX/doctrine_migration_versions.rows" DB_URL_FILE="$SC/db.env" ROOT_LIB_DIR="$SC/rlib" executed_migrations)" \
  = "$(cat "$FIX/doctrine_migration_versions.rows")"
check "migration_ids: one or two backslashes, CR and blanks -> the same bare ids" test \
  "$(printf 'DoctrineMigrations\\Version20260505073000\r\n  DoctrineMigrations\\\\Version20260507122000 \n\n' | migration_ids | tr '\n' ' ')" \
  = 'Version20260505073000 Version20260507122000 '
check "target_migrations: only migrations/VersionYYYYMMDDhhmmss.php, as bare ids (32 of the 33 entries)" test \
  "$( (mirror_git() { cat "$FIX/migrations.ls-tree"; }; target_migrations C0FFEE) | grep -c '^Version[0-9]\{14\}$')" = 32
out="$(real_case "$SC/all.rows" "$FIX/migrations.ls-tree")"
check "identical sets (32/32) -> deploy continues, counts logged" bash -c \
  'grep -q REACHED <<< "$1" && grep -q "LOG: Schema check: the database has 32 executed migration(s), C0FFEE contains 32" <<< "$1"' _ "$out"
out="$(real_case "$FIX/doctrine_migration_versions.rows" "$FIX/migrations.ls-tree")"
check "the production case: 30 executed, 32 in the commit (2 pending) -> deploy continues" bash -c \
  'grep -q REACHED <<< "$1" && grep -q "has 30 executed migration(s), C0FFEE contains 32" <<< "$1" && ! grep -q DIE <<< "$1"' _ "$out"
out="$(real_case "$SC/ahead.rows" "$FIX/migrations.ls-tree")"
check "database ahead (33 executed, 1 unknown) -> refused, listing ONLY the unknown one, with both counts" bash -c \
  '! grep -q REACHED <<< "$1" && [[ $(grep -c "^  - " <<< "$1") -eq 1 ]] && grep -q "^  - Version20261101000000$" <<< "$1" &&
   grep -q "DIE: The database has 1 executed migration(s) that C0FFEE does not contain (listed above; the database has 33 executed, C0FFEE contains 32)" <<< "$1"' _ "$out"
out="$(real_case "$FIX/doctrine_migration_versions.rows" "$SC/nomig.ls-tree")"
check "empty migration list in the commit -> loud error, not 'everything is unregistered'" bash -c \
  '! grep -q REACHED <<< "$1" && grep -q "DIE: C0FFEE has no migrations/VersionYYYYMMDDhhmmss.php in .*: refusing to compare the database.s 30 executed migration(s) with an empty list" <<< "$1" && ! grep -q "^  - " <<< "$1"' _ "$out"
out="$(real_case "$FIX/doctrine_migration_versions.rows" "$FIX/migrations.ls-tree" 1 40 128)"
check "mirror cannot list the commit -> loud error" bash -c '! grep -q REACHED <<< "$1" && grep -q "DIE: Cannot list migrations/ of C0FFEE" <<< "$1"' _ "$out"
out="$(real_case "$SC/empty.rows" "$FIX/migrations.ls-tree" 1 40)"
check "empty doctrine_migration_versions on a populated database (40 tables) -> loud error, not skipped" bash -c \
  '! grep -q REACHED <<< "$1" && grep -q "DIE: The database has 40 table(s) but no executed migrations" <<< "$1"' _ "$out"
out="$(real_case "$SC/empty.rows" "$FIX/migrations.ls-tree" 0 40)"
check "no doctrine_migration_versions table on a populated database -> loud error, not skipped" bash -c \
  '! grep -q REACHED <<< "$1" && grep -q "DIE: The database has 40 table(s) but no executed migrations" <<< "$1"' _ "$out"
out="$(real_case "$SC/empty.rows" "$FIX/migrations.ls-tree" 0 0)"
check "empty database (first install) -> check skipped with a note" bash -c \
  'grep -q REACHED <<< "$1" && grep -q "LOG: Empty database (first install)" <<< "$1"' _ "$out"
out="$(real_case "$SC/crlf.rows" "$SC/crlf.ls-tree")"
check "CRLF and whitespace noise on both sides -> same result as the clean production case" bash -c \
  'grep -q REACHED <<< "$1" && grep -q "has 30 executed migration(s), C0FFEE contains 32" <<< "$1"' _ "$out"
out="$(real_case "$SC/odd.rows" "$FIX/migrations.ls-tree")"
check "a row without a Version id -> loud error naming it" bash -c \
  '! grep -q REACHED <<< "$1" && grep -qF "  ? App\Migrations\Initial" <<< "$1" && grep -q "DIE: doctrine_migration_versions has 1 row(s) without" <<< "$1"' _ "$out"
out="$( (log() { echo "LOG: $*"; }; mirror_git() { cat "$FIX/migrations.ls-tree"; }; resolve_target_ref() { echo C0FFEE; }
         PATH="$SC/bin:$PATH" FAKE_ROWS="$SC/ahead.rows"; export FAKE_ROWS
         DB_URL_FILE="$SC/db.env" ROOT_LIB_DIR="$SC/rlib" MIRROR_DIR="$SC"; dry_run_schema_counts release/v1) 2>&1 )"
check "dry-run prints both counts and the unknown count" grep -q \
  "LOG: \[dry-run\] schema counts: the database has 33 executed migration(s), release/v1 (C0FFEE in .*) contains 32, 1 of them unknown to it" <<< "$out"
out="$( (log() { echo "LOG: $*"; }; DB_URL_FILE="$SC/none" ROOT_LIB_DIR="$SC/rlib"; dry_run_schema_counts release/v1; echo REACHED) 2>&1 )"
check "dry-run without a readable database.env -> says so and goes on" bash -c 'grep -q "schema counts: .* not readable here" <<< "$1" && grep -q REACHED <<< "$1"' _ "$out"
if can_dry_run; then
  check "dry-run reports the schema counts step" grep -q "\[dry-run\] schema counts:" <<< "$BASE_OUT"
else
  skip "$DRY_RUN_SKIP"
fi
check "doctrine/migrations exit codes documented from vendor (0 / 1 pending / 2 unregistered)" \
  grep -q 'vendor/doctrine/migrations 3.9.7 (UpToDateCommand): 0 up to date; 1 migrations pending; 2 executed migrations' "$ROOT/deploy.sh"
if [[ -r "$ROOT/vendor/doctrine/migrations/src/Tools/Console/Command/UpToDateCommand.php" ]]; then
  check "  ...and vendor still returns 2 only with --fail-on-unregistered" bash -c \
    'grep -q "if (\$input->getOption(.fail-on-unregistered.)) {" "$1" && grep -q "\$exitCode = 2;" "$1" && grep -q "\$exitCode = 1;" "$1"' _ "$ROOT/vendor/doctrine/migrations/src/Tools/Console/Command/UpToDateCommand.php"
else
  skip "doctrine/migrations exit code check (vendor/ not installed here)"
fi

echo "== webhook/payment maintenance during the deploy (group 4)"
set_globals
NGINX_SITE=/etc/nginx/sites-available/amoobot NGINX_MAINTENANCE_FILE=/etc/nginx/snippets/amoobot-maintenance.conf
PHP_VERSION=8.4 DOMAIN=bot.example.test CERT_DIR="$WORK/no-cert"
ADMIN_ALLOW_LIST=() NGINX_IPV6=0   # deploy.sh's preflight always sets these
load_fn render_maintenance set_maintenance maintenance_instructions on_exit app_locations render_upload_location render_nginx admin_access_rules listen_lines acme_location
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
# ngx_start SERVER_BLOCKS  -> starts nginx on NGX_PORT with the given server blocks (paths already substituted). As root
# (the Linux container) the workers run as root too: the scratch dir is private (mktemp -d, 700).
ngx_start() {
  printf 'fastcgi_param QUERY_STRING $query_string;\nfastcgi_param REQUEST_METHOD $request_method;\nfastcgi_param SCRIPT_NAME $fastcgi_script_name;\nfastcgi_param REQUEST_URI $request_uri;\n' > "$NGX/conf/fastcgi_params"
  cat > "$NGX/conf/nginx.conf" <<EOF
worker_processes 1; error_log ${NGXW}/logs/error.log; pid ${NGXW}/logs/nginx.pid;$( [[ "$EUID" -eq 0 ]] && printf ' user root;' )
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
load_fn valid_ip_or_cidr admin_access_rules listen_lines acme_location app_locations render_upload_location render_nginx render_maintenance
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
check "webhook location: its own error_log at crit (upstream errors would log the URL secret)" \
  grep -qx '        error_log /var/log/nginx/amoobot_webhook_error.log crit;' <<< "$WEBHOOK_BLOCK"
check "  ...declared before fastcgi_pass, inside the webhook location only" bash -c \
  '[[ $(grep -c "amoobot_webhook_error.log" <<< "$2") -eq 1 ]] && e=$(grep -n error_log <<< "$1" | cut -d: -f1) && f=$(grep -n fastcgi_pass <<< "$1" | cut -d: -f1) && [[ $e -lt $f ]]' _ "$WEBHOOK_BLOCK" "$HTTPS"
rm -f "$HSTS_STATE_FILE"
check "maintenance on, HSTS not yet verified -> no HSTS header in the include" not grep -q Strict-Transport-Security <<< "$(render_maintenance on)"
touch "$HSTS_STATE_FILE"
check "maintenance on, HSTS enabled -> the include repeats HSTS (location add_header replaces the server's)" \
  grep -qx 'add_header Strict-Transport-Security "max-age=31536000" always;' <<< "$(render_maintenance on)"
check "maintenance off -> no add_header at all (server-level HSTS is inherited)" not grep -q add_header <<< "$(render_maintenance off)"
rm -f "$HSTS_STATE_FILE"
load_fn nginx_log_files
out="$( (run() { echo "$*"; }; refuse_symlink() { :; }; WEB_USER=www-data; nginx_log_files) )"
check "nginx log files are kept at mode 640" bash -c \
  '[[ $(grep -cE "(chmod 640|install -m 640 -o www-data -g adm /dev/null) /var/log/nginx/amoobot_(access|error|webhook_error).log" <<< "$1") -eq 3 ]]' _ "$out"
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
check "ADMIN_ALLOW_IPS set -> allow each entry, deny all, on /admin, /admin/login and /admin/apps/downloads" bash -c \
  '[[ $(grep -c "^        allow 203.0.113.7;$" <<< "$1") -eq 3 && $(grep -c "^        allow 10.0.0.0/8;$" <<< "$1") -eq 3 && $(grep -c "^        deny all;$" <<< "$1") -eq 4 ]] &&
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
    check "nginx: webhook URL secret is not in any error log (PHP-FPM down = upstream error)" bash -c \
      '! grep -rqF "$1" "$2"/logs/*error*.log' _ "$SECRET" "$NGX"
    check "nginx: other requests are still logged" grep -q 'marker=logged' "$NGX/logs/amoobot_access.log"
    touch "$HSTS_STATE_FILE"; render_maintenance on > "$NGX/conf/maint.conf"; rm -f "$HSTS_STATE_FILE"; ngx_reload
    hdrs="$(curl -s -m 5 -D - -o /dev/null -X POST "http://127.0.0.1:${NGX_PORT}/telegram/webhook/${SECRET}")"
    check "nginx: during maintenance the 503 still carries HSTS (once enabled) and Retry-After" bash -c \
      'grep -q "^HTTP/1.1 503" <<< "$1" && grep -qi "^strict-transport-security: max-age=31536000" <<< "$1" && grep -qi "^retry-after: 120" <<< "$1"' _ "$hdrs"
    render_maintenance off > "$NGX/conf/maint.conf"; ngx_reload
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
CHECK_JOBS_UNIT=amoobot-check-jobs MONITOR_ENV_FILE=/etc/amoobot/monitor.env JOBS_PAUSED=1 MAINTENANCE_ON=0 DEPLOY_MARKER_SET=0
DEPLOY_MARKER=/var/lib/amoobot/deploy-in-progress DEPLOY_WATCHDOG_UNIT=amoobot-deploy-watchdog
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
check "  ...with a one-line resume command that also clears the deploy marker and the watchdog" \
  grep -qxF "      rm -f /var/lib/amoobot/deploy-in-progress && systemctl stop amoobot-deploy-watchdog.timer; systemctl start 'amoobot-job-*.timer' " <<< "$out"
check "  ...and says the hourly check keeps running and will alert" grep -q 'amoobot-check-jobs.timer and the amoobot-deploy-watchdog timer will raise an alert' <<< "$out"
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

echo "== maintenance: running check, deploy marker, watchdog (group 8, 2nd review)"
set_globals
CHECK_JOBS_UNIT=amoobot-check-jobs DEPLOY_WATCHDOG_UNIT=amoobot-deploy-watchdog JOBS_LOCK_DIR="$WORK/m8lock" DEPLOY_REF=main
eval "$(sed -n '/^SCHEDULED_JOBS=(/,/^)/p; /^job_lock_groups()/,/^}/p' "$ROOT/deploy.sh")"
load_fn pause_scheduled_jobs arm_deploy_watchdog resume_scheduled_jobs refuse_symlink parse_job
m8_env() {
  systemctl() { echo "systemctl $*" >> "$WORK/m8.calls"; [[ "$1" == list-unit-files ]] && printf 'amoobot-job-sync.timer enabled\namoobot-job-orders.timer enabled\n'; return 0; }
  systemd-run() { echo "systemd-run $*" >> "$WORK/m8.calls"; }
  sudo() { while (( $# )); do case "$1" in -H|-n) shift ;; -u) shift 2 ;; --) shift; break ;; *) break ;; esac; done; "$@"; }
  install() { command install "$@" 2> /dev/null || mkdir -p "${@: -1}"; }   # -o root only works as root
  flock() { return 0; }
  die() { printf 'DIE: %s\n' "$*"; exit 1; }
  run() { "$@"; }
}
mkdir -p "$WORK/m8sbin"; printf '#!/bin/sh\n' > "$WORK/m8sbin/amoobot-check-jobs"; chmod +x "$WORK/m8sbin/amoobot-check-jobs"
out="$( (m8_env; DRY_RUN=0 STATE_DIR="$WORK/m8state" DEPLOY_MARKER="$WORK/m8state/deploy-in-progress" ROOT_SBIN_DIR="$WORK/m8sbin" WEB_USER="$(id -un)" TARGET_COMMIT=abc
         : > "$WORK/m8.calls"; pause_scheduled_jobs; echo "PAUSED=$JOBS_PAUSED MARKER=$DEPLOY_MARKER_SET") 2>&1)"
check "pause: deploy marker written (root-owned state dir, survives a reboot)" bash -c 'grep -q "^started=.* target=abc pid=" "$1/m8state/deploy-in-progress" && grep -q "MARKER=1" <<< "$2"' _ "$WORK" "$out"
check "pause: only the job timers are stopped, the hourly check keeps running" bash -c \
  'grep -q "^systemctl stop amoobot-job-sync.timer amoobot-job-orders.timer$" "$1" && ! grep -q "stop.*amoobot-check-jobs.timer" "$1" && ! grep -q "list-unit-files.*amoobot-check-jobs" "$1"' _ "$WORK/m8.calls"
check "pause: a 45-minute watchdog is armed that runs the check with the alert as OnFailure" grep -qE \
  "^systemd-run --quiet --unit=amoobot-deploy-watchdog --on-active=45min --timer-property=AccuracySec=1min --description=.* --property=OnFailure=amoobot-job-alert@%N.service $WORK/m8sbin/amoobot-check-jobs$" "$WORK/m8.calls"
check "  ...after clearing a watchdog left over from an earlier failed deploy" bash -c \
  's=$(grep -n "stop amoobot-deploy-watchdog.timer amoobot-deploy-watchdog.service" "$1" | cut -d: -f1); r=$(grep -n "^systemd-run" "$1" | cut -d: -f1); [[ -n $s && -n $r && $s -lt $r ]]' _ "$WORK/m8.calls"
check "  ...the watchdog never resumes anything: its only command is the read-only check" bash -c \
  '[[ $(grep "^systemd-run" "$1" | awk "{print \$NF}") == "$2/m8sbin/amoobot-check-jobs" ]]' _ "$WORK/m8.calls" "$WORK"
out="$( (m8_env; DRY_RUN=0 DEPLOY_MARKER="$WORK/m8state/deploy-in-progress" changed_timers=() DEPLOY_MARKER_SET=1 JOB_LOCK_FDS=()
         : > "$WORK/m8.calls"; resume_scheduled_jobs; echo "PAUSED=$JOBS_PAUSED MARKER=$DEPLOY_MARKER_SET") 2>&1)"
check "resume: marker removed and watchdog cancelled before the timers start" bash -c \
  '[[ ! -e "$1/m8state/deploy-in-progress" ]] && grep -q "MARKER=0" <<< "$2" && w=$(grep -n "stop amoobot-deploy-watchdog.timer" "$3" | cut -d: -f1) && t=$(grep -n "enable --now amoobot-job-" "$3" | head -1 | cut -d: -f1) && [[ $w -lt $t ]]' _ "$WORK" "$out" "$WORK/m8.calls"
check "resume: every job timer enabled, the check timer restarted (first check 1 h later)" bash -c \
  '[[ $(grep -c "enable --now amoobot-job-" "$1") -eq 6 ]] && grep -q "^systemctl restart amoobot-check-jobs.timer$" "$1"' _ "$WORK/m8.calls"
out="$( (m8_env; DRY_RUN=0 DEPLOY_MARKER="$WORK/m8state/deploy-in-progress" changed_timers=() JOB_LOCK_FDS=(); resume_scheduled_jobs; resume_scheduled_jobs; echo OK) 2>&1)"
check "resume is safe to run twice" grep -qx OK <<< "$out"
out="$( (JOBS_PAUSED=0 DEPLOY_MARKER_SET=1 MAINTENANCE_ON=0; on_exit 1) 2>&1)"
check "a failure after the marker was written warns even if no timer existed yet" grep -q 'SCHEDULED JOBS ARE PAUSED' <<< "$out"
rm -f "$WORK/curl.args" "$WORK/curl.cfg"
PATH="$WORK/curlbin:$PATH" AMOOBOT_MONITOR_PING_URL="https://hc-ping.com/key/amoobot-{job}" bash -c 'source "$1"; monitor_ping deploy fail' _ "$ROOT/bin/lib/monitor-ping.sh" > /dev/null
check "monitor_ping NAME fail -> the check's /fail URL" grep -qxF 'url = "https://hc-ping.com/key/amoobot-deploy/fail"' "$WORK/curl.cfg"
rm -f "$WORK/curl.cfg"
PATH="$WORK/curlbin:$PATH" AMOOBOT_MONITOR_PING_URL="https://mon.example/push/{job}?token=t" bash -c 'source "$1"; monitor_ping deploy fail' _ "$ROOT/bin/lib/monitor-ping.sh" > /dev/null
check "  ...inserted before a query string" grep -qxF 'url = "https://mon.example/push/deploy/fail?token=t"' "$WORK/curl.cfg"

echo "== PHP timezone pinned to UTC (FPM + CLI)"
set_globals
PHP_VERSION=8.4 PHP_TIMEZONE_INI=99-amoobot-timezone.ini PHP_ETC_DIR=/etc/php
load_fn php_timezone_ini_paths render_php_timezone_ini install_php_timezone php_timezone_problems
check "ini paths: the FPM and CLI conf.d of PHP_VERSION" test "$(php_timezone_ini_paths | tr '\n' ' ')" = \
  "/etc/php/8.4/fpm/conf.d/99-amoobot-timezone.ini /etc/php/8.4/cli/conf.d/99-amoobot-timezone.ini "
check "ini content: date.timezone = UTC" grep -qx 'date.timezone = UTC' <<< "$(render_php_timezone_ini)"
out="$( (write_file() { echo "write $1 $2 $3"; cat > /dev/null; }; install_php_timezone) )"
check "install writes both files 644 root:root" test "$(grep -c '^write /etc/php/8.4/\(fpm\|cli\)/conf.d/99-amoobot-timezone.ini 644 root:root$' <<< "$out")" -eq 2
TZB="$WORK/tzbin"; mkdir -p "$TZB" "$WORK/tzpool"
printf '#!/bin/sh\nprintf "%%s" "$CLI_TZ"\n' > "$TZB/php"; printf '#!/bin/sh\nprintf "date.timezone => %%s => %%s\\n" "$FPM_TZ" "$FPM_TZ"\n' > "$TZB/php-fpm8.4"; chmod +x "$TZB/php" "$TZB/php-fpm8.4"
tz_case() {  # tz_case <cli output> <fpm value> [pool override] -> problems
  rm -rf "$WORK/tzetc"; mkdir -p "$WORK/tzetc/8.4/fpm/pool.d"; printf '[www]\nuser = www-data\n%s\n' "${3:-}" > "$WORK/tzetc/8.4/fpm/pool.d/www.conf"
  ( export PATH="$TZB:$PATH" CLI_TZ="$1" FPM_TZ="$2"; PHP_ETC_DIR="$WORK/tzetc"; php_timezone_problems )
}
check "CLI and FPM UTC, no pool override -> no problem" test -z "$(tz_case 'UTC UTC' UTC)"
check "CLI ini unset (default UTC but date.timezone empty) -> problem" grep -q "^CLI: " <<< "$(tz_case ' UTC' UTC)"
check "CLI Asia/Tehran -> problem" grep -q "^CLI: .*Asia/Tehran" <<< "$(tz_case 'Asia/Tehran Asia/Tehran' UTC)"
check "FPM Europe/Berlin -> problem" grep -q "^FPM: php-fpm8.4 -i shows date.timezone 'Europe/Berlin'" <<< "$(tz_case 'UTC UTC' Europe/Berlin)"
check "pool php_admin_value[date.timezone] override -> problem" grep -q "overrides date.timezone with: Asia/Tehran" <<< "$(tz_case 'UTC UTC' UTC 'php_admin_value[date.timezone] = Asia/Tehran')"
check "pool override set to UTC -> no problem" test -z "$(tz_case 'UTC UTC' UTC 'php_value[date.timezone] = UTC')"
check "step 18 installs the ini before the FPM restart and dies when the effective value is not UTC" bash -c \
  'b=$(sed -n "/^step \"18\/18\"/,/^fi$/p" "$1"); i=$(grep -n "^install_php_timezone$" <<< "$b" | cut -d: -f1); r=$(grep -n "systemctl restart \"php\${PHP_VERSION}-fpm\"" <<< "$b" | cut -d: -f1)
   [[ -n $i && -n $r && $i -lt $r ]] && grep -q "die \"PHP timezone is not UTC everywhere" <<< "$b"' _ "$ROOT/deploy.sh"
if can_dry_run; then
  OUT="$BASE_OUT"
  for sapi in fpm cli; do
    check "dry-run writes /etc/php/8.4/${sapi}/conf.d/99-amoobot-timezone.ini with date.timezone = UTC" bash -c \
      'grep -qF "[dry-run] write /etc/php/8.4/$2/conf.d/99-amoobot-timezone.ini (644 root:root)" <<< "$1" && grep -A3 "99-amoobot-timezone.ini (644" <<< "$1" | grep -q "+ date.timezone = UTC"' _ "$OUT" "$sapi"
  done
  check "dry-run announces the CLI + FPM timezone verification" grep -qF '[dry-run] verify the effective PHP timezone is UTC: CLI (php -r), FPM (php-fpm8.4 -i and pool.d overrides)' <<< "$OUT"
else
  skip "$DRY_RUN_SKIP"
fi

echo "== app downloads: upload limits scoped to the admin upload page"
set_globals
PHP_VERSION=8.4 PHP_ETC_DIR=/etc/php ADMIN_ALLOW_LIST=() NGINX_MAINTENANCE_FILE=/etc/nginx/snippets/amoobot-maintenance.conf
load_fn php_uploads_ini_path render_php_uploads_ini install_php_uploads php_uploads_problems render_upload_location admin_access_rules app_locations
check "ini path: the FPM conf.d only (the CLI never receives uploads)" test "$(php_uploads_ini_path)" = "/etc/php/8.4/fpm/conf.d/98-amoobot-uploads.ini"
INI="$(render_php_uploads_ini)"
check "ini: upload_max_filesize = APP_DOWNLOADS_MAX_MB" grep -qx 'upload_max_filesize = 200M' <<< "$INI"
check "ini: post_max_size = APP_DOWNLOADS_MAX_MB + margin" grep -qx 'post_max_size = 216M' <<< "$INI"
check "ini follows APP_DOWNLOADS_MAX_MB" grep -qx 'upload_max_filesize = 500M' <<< "$(APP_DOWNLOADS_MAX_MB=500; render_php_uploads_ini)"
out="$( (write_file() { echo "write $1 $2 $3"; cat > /dev/null; }; install_php_uploads) )"
check "install writes the FPM ini 644 root:root" grep -qx 'write /etc/php/8.4/fpm/conf.d/98-amoobot-uploads.ini 644 root:root' <<< "$out"
LOC="$(app_locations)"
check "nginx: one location for the upload page with the large body limit" test "$(grep -c '^    location ^~ /admin/apps/downloads {$' <<< "$LOC")" -eq 1
check "  ...client_max_body_size = max + margin, only there" bash -c 'grep -A2 "location ^~ /admin/apps/downloads" <<< "$1" | grep -q "client_max_body_size 216M;" && test "$(grep -c "client_max_body_size 216M" <<< "$1")" -eq 1' _ "$LOC"
check "  ...every other path keeps 50M" grep -q '^    client_max_body_size 50M;$' <<< "$LOC"
check "  ...fastcgi directly (no try_files: the 50M of /index.php would apply again)" bash -c 'sed -n "/location ^~ \/admin\/apps\/downloads/,/^    }/p" <<< "$1" | grep -q "fastcgi_pass" && ! sed -n "/location ^~ \/admin\/apps\/downloads/,/^    }/p" <<< "$1" | grep -q try_files' _ "$LOC"
LOC_IPS="$(ADMIN_ALLOW_LIST=(203.0.113.7); app_locations)"
check "  ...keeps the ADMIN_ALLOW_IPS rules" bash -c 'sed -n "/location ^~ \/admin\/apps\/downloads/,/^    }/p" <<< "$1" | grep -q "allow 203.0.113.7;"' _ "$LOC_IPS"
UPB="$WORK/upbin"; mkdir -p "$UPB"
printf '#!/bin/sh\nprintf "upload_max_filesize => %%s => %%s\\npost_max_size => %%s => %%s\\n" "$UP" "$UP" "$POST" "$POST"\n' > "$UPB/php-fpm8.4"; chmod +x "$UPB/php-fpm8.4"
up_case() {  # up_case <upload_max_filesize> <post_max_size> [pool override] -> problems
  rm -rf "$WORK/upetc"; mkdir -p "$WORK/upetc/8.4/fpm/pool.d"; printf '[www]\n%s\n' "${3:-}" > "$WORK/upetc/8.4/fpm/pool.d/www.conf"
  ( export PATH="$UPB:$PATH" UP="$1" POST="$2"; PHP_ETC_DIR="$WORK/upetc"; php_uploads_problems )
}
check "FPM limits as configured -> no problem" test -z "$(up_case 200M 216M)"
check "FPM still on the 2M default -> problem" grep -q "upload_max_filesize '2M', expected at least 200M" <<< "$(up_case 2M 8M)"
check "post_max_size too small -> problem" grep -q "post_max_size '200M', expected at least 216M" <<< "$(up_case 200M 200M)"
check "pool override -> problem" grep -q "overrides: upload_max_filesize" <<< "$(up_case 200M 216M 'php_admin_value[upload_max_filesize] = 8M')"
check "step 18 installs the uploads ini before the FPM restart and dies when the limits are too low" bash -c \
  'b=$(sed -n "/^step \"18\/18\"/,/^fi$/p" "$1"); i=$(grep -n "^install_php_uploads$" <<< "$b" | cut -d: -f1); r=$(grep -n "systemctl restart \"php\${PHP_VERSION}-fpm\"" <<< "$b" | cut -d: -f1)
   [[ -n $i && -n $r && $i -lt $r ]] && grep -q "die \"PHP upload limits are too low" <<< "$b"' _ "$ROOT/deploy.sh"
check "APP_DOWNLOADS_MAX_MB is validated (1..2048)" grep -q 'APP_DOWNLOADS_MAX_MB must be a whole number of megabytes from 1 to 2048' "$ROOT/deploy.sh"
check "var/downloads is created with the other var/ directories" grep -q 'mkdir -p .*"\$APP_DIR/var/downloads"' "$ROOT/deploy.sh"
if can_dry_run; then
  check "dry-run writes the FPM uploads ini" grep -qF '[dry-run] write /etc/php/8.4/fpm/conf.d/98-amoobot-uploads.ini (644 root:root)' <<< "$BASE_OUT"
else
  skip "$DRY_RUN_SKIP"
fi

echo "== supply chain: pinned GitHub host keys (group 7)"
check "StrictHostKeyChecking=accept-new is gone (no trust on first use)" not grep -q 'StrictHostKeyChecking=accept-new' "$ROOT/deploy.sh"
REPO_HOST_LINE="$(grep -m1 '^repo_host=' "$ROOT/deploy.sh")"
for case in "git@github.com:hforouzi/amoobot.git=github.com" "ssh://git@github.com/hforouzi/amoobot.git=github.com" \
            "ssh://git@github.com:22/x/y.git=github.com" "git@gitlab.example.com:x/y.git=gitlab.example.com"; do
  url="${case%=*}" want="${case##*=}"
  check "repo host of $url is $want" test "$(REPO_URL="$url"; eval "$REPO_HOST_LINE"; echo "$repo_host")" = "$want"
done
if have ssh-keygen; then
  set_globals
  GITHUB_KNOWN_HOSTS="$WORK/gh_known_hosts" GITHUB_META_URL="https://api.github.com/meta"
  load_fn valid_ssh_public_key render_github_known_hosts refresh_github_known_hosts
  for t in ed25519 ecdsa rsa; do ssh-keygen -q -t "$t" -N '' -C '' -f "$WORK/gh_$t" > /dev/null; done
  ssh-keygen -q -t ed25519 -N '' -C '' -f "$WORK/gh_decoy" > /dev/null
  key() { cut -d' ' -f1,2 < "$WORK/gh_$1.pub"; }
  # Shaped like the real response: pretty-printed, fingerprints, other arrays; plus one broken key and a decoy key
  # outside "ssh_keys" that must not be picked up.
  cat > "$WORK/meta.json" <<EOF
{
  "verifiable_password_authentication": false,
  "ssh_key_fingerprints": { "SHA256_ED25519": "+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU" },
  "decoy": [ "$(key decoy)" ],
  "ssh_keys": [
    "$(key ed25519)",
    "$(key ecdsa)",
    "$(key rsa)",
    "ssh-rsa AAAAbroken"
  ],
  "hooks": [ "192.30.252.0/22" ]
}
EOF
  out="$(render_github_known_hosts < "$WORK/meta.json")"; rc=$?
  check "known_hosts: the three valid ssh_keys, as github.com lines" bash -c \
    '[[ $1 -eq 0 && $(wc -l <<< "$2") -eq 3 ]] && grep -qxF "github.com $3" <<< "$2" && grep -qxF "github.com $4" <<< "$2" && grep -qxF "github.com $5" <<< "$2"' \
    _ "$rc" "$out" "$(key ed25519)" "$(key ecdsa)" "$(key rsa)"
  check "known_hosts: keys outside ssh_keys and broken keys are ignored" bash -c \
    '! grep -qF -- "$(cut -d" " -f2 < "$2")" <<< "$1" && ! grep -q AAAAbroken <<< "$1"' _ "$out" "$WORK/gh_decoy.pub"
  check "known_hosts: a response without ssh_keys is refused" not render_github_known_hosts <<< '{"hooks": ["192.30.252.0/22"]}'
  check "known_hosts: ssh -o accepts the generated file" bash -c 'printf "%s\n" "$1" > "$2" && ssh-keygen -F github.com -f "$2" > /dev/null' _ "$out" "$WORK/kh_probe"

  refresh_case() {  # refresh_case <curl rc> <existing file 0|1> -> output + whether the file has the keys
    ( write_file() { cat > "$1"; }; warn() { echo "WARN: $*"; }; die() { echo "DIE: $*"; exit 1; }
      curl() { echo "curl $*" >> "$WORK/gh_curl.args"; [[ "$CURL_RC" == 0 ]] && cat "$WORK/meta.json"; return "$CURL_RC"; }
      CURL_RC="$1"; rm -f "$GITHUB_KNOWN_HOSTS" "$WORK/gh_curl.args"; [[ "$2" == 1 ]] && echo "github.com ssh-ed25519 OLDKEY" > "$GITHUB_KNOWN_HOSTS"
      refresh_github_known_hosts; echo "lines=$(wc -l < "$GITHUB_KNOWN_HOSTS")" ) 2>&1
  }
  out="$(refresh_case 0 0)"
  check "fetch OK -> known_hosts written with the fetched keys" grep -q '^lines=3$' <<< "$out"
  check "  ...over HTTPS only (--proto =https, TLS 1.2+)" grep -qF -- "--proto =https --tlsv1.2" "$WORK/gh_curl.args"
  out="$(refresh_case 6 1)"
  check "fetch fails, keys known from before -> keep them, warn" bash -c 'grep -q "^WARN: Could not refresh" <<< "$1" && grep -q "^lines=1$" <<< "$1"' _ "$out"
  out="$(refresh_case 6 0)"
  check "fetch fails, nothing known -> stop (no unverified trust)" grep -q '^DIE: Could not fetch GitHub' <<< "$out"
else
  skip "GitHub host key tests (ssh-keygen not installed)"
fi
if can_dry_run; then
  OUT="$BASE_OUT"
  check "dry-run fetches GitHub's host keys over HTTPS" grep -q "fetch GitHub's SSH host keys from https://api.github.com/meta (HTTPS) -> /etc/ssh/amoobot_github_known_hosts" <<< "$OUT"
  check "root's mirror fetch uses the pinned GitHub file for github.com" grep -q 'repo_known_hosts="\$GITHUB_KNOWN_HOSTS"' "$ROOT/deploy.sh"
else
  skip "$DRY_RUN_SKIP"
fi
check "deploy.sh documents how to pin the Composer version" grep -q -- '--version=<2.x.y>' "$ROOT/deploy.sh"

echo
echo "passed: $PASS  failed: $FAIL  skipped: $SKIP"
(( FAIL == 0 ))
