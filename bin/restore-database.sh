#!/usr/bin/env bash
#
# Restore a dump made by bin/backup-database.sh into the Amoobot database. DESTRUCTIVE: replaces every table.
#
#   bin/restore-database.sh <backup.sql.gz> --yes [--no-safety-backup]
#
# Steps: verify the archive -> take a "pre-restore" safety backup of the current database -> drop all tables
# and views -> import -> report table and migration counts. Stop PHP-FPM first (see docs/fa/deployment.md) so
# nobody writes while the restore runs. Same DATABASE_URL / APP_DIR / BACKUP_DIR settings as backup-database.sh.
# On the server root runs the installed copy: sudo amoobot-restore <backup.sql.gz> --yes

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(dirname "$SCRIPT_DIR")}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

FILE=""
CONFIRMED=0
SAFETY_BACKUP=1
for arg in "$@"; do
  case "$arg" in
    --yes) CONFIRMED=1 ;;
    --no-safety-backup) SAFETY_BACKUP=0 ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    -*) die "Unknown option: $arg" ;;
    *) [[ -z "$FILE" ]] || die "Only one backup file may be given."; FILE="$arg" ;;
  esac
done

[[ -n "$FILE" ]] || die "Usage: $0 <backup.sql.gz> --yes [--no-safety-backup]"
[[ -f "$FILE" ]] || die "Backup file not found: $FILE"
(( CONFIRMED )) || die "This replaces ALL tables of the database. Re-run with --yes to confirm."

# shellcheck source=bin/lib/database-url.sh
source "$SCRIPT_DIR/lib/database-url.sh"
load_database_url "$APP_DIR"

CLIENT_BIN="$(command -v mariadb || command -v mysql || true)"
[[ -n "$CLIENT_BIN" ]] || die "Neither mariadb nor mysql client is installed."

echo "Checking archive integrity..."
gzip -t "$FILE" || die "Archive is corrupt: $FILE"
zcat "$FILE" | tail -n 1 | grep -q 'Dump completed' || die "Archive has no 'Dump completed' trailer (truncated dump?)."

if (( SAFETY_BACKUP )); then
  echo "Taking a pre-restore safety backup of the current database..."
  BACKUP_LABEL="pre-restore" BACKUP_RETENTION_DAYS=0 APP_DIR="$APP_DIR" bash "$SCRIPT_DIR/backup-database.sh"
fi

trap 'rm -f "${DB_OPTS_FILE:-}"' EXIT
write_db_options_file
sql() { "$CLIENT_BIN" --defaults-extra-file="$DB_OPTS_FILE" --default-character-set=utf8mb4 "$@" "$DB_NAME"; }

echo "Dropping current tables and views of ${DB_NAME}..."
drop_sql="$(sql -N -B -e "
  SELECT CONCAT('DROP ', IF(table_type = 'VIEW', 'VIEW', 'TABLE'), ' IF EXISTS \`', table_name, '\`;')
  FROM information_schema.tables WHERE table_schema = DATABASE();")"
{
  echo 'SET FOREIGN_KEY_CHECKS = 0;'
  printf '%s\n' "$drop_sql"
  echo 'SET FOREIGN_KEY_CHECKS = 1;'
} | sql

echo "Importing $(basename "$FILE")..."
# --binary-mode: client commands such as \! (shell) inside the dump are not executed; this runs as root.
zcat "$FILE" | sql --binary-mode

tables="$(sql -N -B -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();')"
migrations="$(sql -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'doctrine_migration_versions';")"
if [[ "$migrations" == "1" ]]; then
  migrations="$(sql -N -B -e 'SELECT COUNT(*) FROM doctrine_migration_versions;')"
fi
echo "Restore finished: ${tables} tables, ${migrations} recorded migrations in ${DB_NAME}."
echo "Next: php bin/console doctrine:migrations:status --env=prod, then start PHP-FPM again."
