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
  check "dry-run installs the four root wrappers" test "$(grep -cE '^\[dry-run\] write /usr/local/sbin/amoobot-(deploy|backup|restore|check-jobs) \(755 root:root\)' <<< "$OUT")" -eq 4
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

echo
echo "passed: $PASS  failed: $FAIL  skipped: $SKIP"
(( FAIL == 0 ))
