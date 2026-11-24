#!/usr/bin/env bash
#
# Dump the Amoobot MariaDB/MySQL database to BACKUP_DIR/<db>_<label>_<UTC timestamp>.sql.gz and delete NIGHTLY dumps
# older than BACKUP_RETENTION_DAYS. Installed as a nightly cron job by deploy.sh; restore with bin/restore-database.sh.
#
#   DATABASE_URL  from the environment, else /etc/amoobot/database.env (root-owned, written by deploy.sh); see
#                 bin/lib/database-url.sh. As root, $APP_DIR/.env.local.php is never loaded.
#   APP_DIR                 default: the project this script lives in
#
# On the server root runs the installed copy through /usr/local/sbin/amoobot-backup [label], never this checkout.
#   BACKUP_DIR              default: /var/backups/amoobot
#   BACKUP_RETENTION_DAYS   default: 14 (0 = keep everything)
#   BACKUP_LABEL            default: nightly  (deploy.sh uses pre-deploy, restore-database.sh pre-restore)
#
# After a good nightly dump, /var/lib/amoobot/backup-last-success (root:root 0644) holds ONE line: the UTC time of that
# dump, nothing else (no file name, size or path). The admin's health block reads it, so BACKUP_DIR stays 0700 and PHP
# never looks inside. AMOOBOT_BACKUP_STATUS_FILE overrides the path for tests (the root wrapper passes a clean env).
#
# The password is passed to mysqldump through a temporary 0600 option file, never on the command line.

set -Eeuo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(dirname "$SCRIPT_DIR")}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/amoobot}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_LABEL="${BACKUP_LABEL:-nightly}"
BACKUP_STATUS_FILE="${AMOOBOT_BACKUP_STATUS_FILE:-/var/lib/amoobot/backup-last-success}"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# write_status_line FILE LINE: replace FILE atomically (rename, so a link at FILE is replaced, never followed) with one
# line, mode 0644, root:root when run as root. The directory must exist (deploy.sh creates /var/lib/amoobot).
# Returns non-zero instead of exiting.
write_status_line() {
  local dest="$1" tmp
  [[ -d "$(dirname "$dest")" ]] || return 1
  tmp="$(mktemp "${dest}.XXXXXX")" || return 1
  if printf '%s\n' "$2" > "$tmp" && chmod 0644 "$tmp" && { (( EUID != 0 )) || chown root:root "$tmp"; } && mv -f "$tmp" "$dest"; then
    return 0
  fi
  rm -f "$tmp"
  return 1
}

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

# Only nightly dumps rotate. pre-deploy / pre-restore / manual dumps are the way back from a bad release or restore
# and are never deleted automatically (remove them by hand once they are no longer needed).
if (( BACKUP_RETENTION_DAYS > 0 )); then
  find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_nightly_*.sql.gz" -mtime +"$BACKUP_RETENTION_DAYS" -print -delete \
    | sed 's/^/Rotated out: /'
fi

printf 'Backup written to %s (%s)\n' "$OUT" "$(du -h "$OUT" | cut -f1)"

# Dead-man's switch for the nightly backup (optional MONITOR_PING_URL; "{job}" becomes "backup").
if [[ "$BACKUP_LABEL" == nightly ]]; then
  # Never fails the backup (the dump is already safe): the admin then shows the last backup as old or unknown.
  write_status_line "$BACKUP_STATUS_FILE" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    || printf 'WARNING: could not record the backup time in %s\n' "$BACKUP_STATUS_FILE" >&2
  # shellcheck source=bin/lib/monitor-ping.sh
  source "$SCRIPT_DIR/lib/monitor-ping.sh"
  monitor_ping backup
fi
