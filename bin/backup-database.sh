#!/usr/bin/env bash
#
# Dump the Amoobot MariaDB/MySQL database to BACKUP_DIR/<db>_<label>_<UTC timestamp>.sql.gz and delete dumps
# older than BACKUP_RETENTION_DAYS. Installed as a nightly cron job by deploy.sh; restore with bin/restore-database.sh.
#
#   DATABASE_URL  read from the environment, else from $APP_DIR/.env.local.php (written by deploy.sh)
#   APP_DIR                 default: the project this script lives in
#   BACKUP_DIR              default: /var/backups/amoobot
#   BACKUP_RETENTION_DAYS   default: 14 (0 = keep everything)
#   BACKUP_LABEL            default: nightly  (deploy.sh uses pre-deploy, restore-database.sh pre-restore)
#
# The password is passed to mysqldump through a temporary 0600 option file, never on the command line.

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(dirname "$SCRIPT_DIR")}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/amoobot}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_LABEL="${BACKUP_LABEL:-nightly}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ "$BACKUP_RETENTION_DAYS" =~ ^[0-9]+$ ]] || die "BACKUP_RETENTION_DAYS must be a number."
[[ "$BACKUP_LABEL" =~ ^[A-Za-z0-9-]+$ ]] || die "BACKUP_LABEL may only contain A-Z a-z 0-9 -."

# shellcheck source=bin/lib/database-url.sh
source "$SCRIPT_DIR/lib/database-url.sh"
load_database_url "$APP_DIR"

DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"
[[ -n "$DUMP_BIN" ]] || die "Neither mariadb-dump nor mysqldump is installed."

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR" 2>/dev/null || true

TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$BACKUP_DIR/${DB_NAME}_${BACKUP_LABEL}_${TIMESTAMP}.sql.gz"
PARTIAL="$OUT.partial"
trap 'rm -f "$PARTIAL" "${DB_OPTS_FILE:-}"' EXIT

write_db_options_file

"$DUMP_BIN" --defaults-extra-file="$DB_OPTS_FILE" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  --events \
  --hex-blob \
  --no-tablespaces \
  --default-character-set=utf8mb4 \
  "$DB_NAME" | gzip -9 > "$PARTIAL"

gzip -t "$PARTIAL" || die "Backup archive failed its integrity check; previous backups were kept."
zcat "$PARTIAL" | tail -n 1 | grep -q 'Dump completed' || die "Dump is incomplete (no 'Dump completed' trailer)."
mv "$PARTIAL" "$OUT"

if (( BACKUP_RETENTION_DAYS > 0 )); then
  find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_*.sql.gz" -mtime +"$BACKUP_RETENTION_DAYS" -print -delete \
    | sed 's/^/Rotated out: /'
fi

printf 'Backup written to %s (%s)\n' "$OUT" "$(du -h "$OUT" | cut -f1)"
