# shellcheck shell=bash
# Shared by bin/backup-database.sh and bin/restore-database.sh (sourced, not executed).
#
# load_database_url APP_DIR   sets DB_USER DB_PASS DB_HOST DB_PORT DB_NAME from DATABASE_URL. Values are never printed.
#                             Source, in order: the environment; AMOOBOT_DB_URL_FILE (default /etc/amoobot/database.env,
#                             written by deploy.sh, root-owned); APP_DIR/.env.local.php, but only when NOT running as
#                             root: that file is PHP owned by the deploy user, so root never loads it.
# write_db_options_file       writes a 0600 [client] option file and sets DB_OPTS_FILE (caller removes it).

load_database_url() {
  local app_dir="$1" url_file="${AMOOBOT_DB_URL_FILE:-/etc/amoobot/database.env}" url="${DATABASE_URL:-}" line key value
  local owner mode
  if [[ -z "$url" && -e "$url_file" ]]; then
    # Only root (or the current user, for tests) may own it, and nobody else may write it.
    read -r owner mode < <(stat -c '%u %a' "$url_file")
    if [[ "$owner" != 0 && "$owner" != "$EUID" ]] || (( (8#$mode & 8#022) != 0 )); then
      printf 'ERROR: %s must be owned by root and not writable by group/others (uid %s, mode %s).\n' "$url_file" "$owner" "$mode" >&2
      exit 1
    fi
    while IFS= read -r line || [[ -n "$line" ]]; do
      line="${line%$'\r'}"
      [[ "$line" == DATABASE_URL=* ]] && url="${line#DATABASE_URL=}"
    done < "$url_file"
    [[ -n "$url" ]] || { printf 'ERROR: %s has no DATABASE_URL= line.\n' "$url_file" >&2; exit 1; }
  fi
  if [[ -z "$url" ]]; then
    if (( EUID == 0 )); then
      printf 'ERROR: DATABASE_URL is not set and %s does not exist. As root, %s/.env.local.php is never loaded (deploy-writable).\n' \
        "$url_file" "$app_dir" >&2
      exit 1
    fi
    [[ -r "$app_dir/.env.local.php" ]] \
      || { printf 'ERROR: DATABASE_URL is not set and %s/.env.local.php is not readable.\n' "$app_dir" >&2; exit 1; }
    url="$(php -r '$e = require $argv[1]; echo $e["DATABASE_URL"] ?? "";' "$app_dir/.env.local.php")"
  fi

  # PHP parses the URL (handles percent-encoding); fields come back base64-encoded, one per line.
  # Split on the first "=" by hand: IFS='=' read would drop trailing base64 padding.
  while IFS= read -r line; do
    line="${line%$'\r'}"
    key="${line%%=*}"
    value="$(printf '%s' "${line#*=}" | base64 --decode)"
    case "$key" in
      scheme) DB_SCHEME="$value" ;;
      user)   DB_USER="$value" ;;
      pass)   DB_PASS="$value" ;;
      host)   DB_HOST="$value" ;;
      port)   DB_PORT="$value" ;;
      dbname) DB_NAME="$value" ;;
    esac
  done < <(AMOOBOT_PARSE_URL="$url" php -r '
    $url = (string) getenv("AMOOBOT_PARSE_URL");
    // The app reads it via %env(resolve:DATABASE_URL)%, where a literal "%" is written as "%%".
    $parts = parse_url(str_replace("%%", "%", $url));
    if (false === $parts || !isset($parts["scheme"], $parts["path"])) {
        fwrite(STDERR, "ERROR: DATABASE_URL is not a valid URL.\n");
        exit(1);
    }
    $data = [
        "scheme" => $parts["scheme"],
        "user" => rawurldecode($parts["user"] ?? ""),
        "pass" => rawurldecode($parts["pass"] ?? ""),
        "host" => $parts["host"] ?? "127.0.0.1",
        "port" => isset($parts["port"]) ? (string) $parts["port"] : "3306",
        "dbname" => ltrim($parts["path"], "/"),
    ];
    foreach ($data as $key => $value) {
        echo $key, "=", base64_encode($value), "\n";
    }
  ')

  case "${DB_SCHEME:-}" in
    mysql|mysqli|mariadb|pdo-mysql) ;;
    *) printf 'ERROR: unsupported DATABASE_URL scheme "%s" (MariaDB/MySQL only).\n' "${DB_SCHEME:-}" >&2; exit 1 ;;
  esac
  [[ "${DB_NAME:-}" =~ ^[A-Za-z0-9_]+$ ]] \
    || { printf 'ERROR: database name in DATABASE_URL is empty or contains unexpected characters.\n' >&2; exit 1; }
}

write_db_options_file() {
  local v
  # Values are written into double-quoted option-file lines: a newline, quote or backslash in the user or host (e.g. a
  # percent-encoded %0A in DATABASE_URL) could add options of its own. The password is escaped below.
  if [[ "${DB_USER:-}" =~ [[:cntrl:]\"\\] || "${DB_HOST:-}" =~ [[:cntrl:]\"\\] ]]; then
    printf 'ERROR: the database user or host in DATABASE_URL contains a control character, quote or backslash.\n' >&2
    exit 1
  fi
  [[ "${DB_PORT:-}" =~ ^[0-9]{1,5}$ ]] || { printf 'ERROR: the database port in DATABASE_URL is not a number.\n' >&2; exit 1; }
  [[ "$DB_PASS" != *[[:cntrl:]]* ]] || { printf 'ERROR: the database password contains a control character.\n' >&2; exit 1; }
  DB_OPTS_FILE="$(mktemp)"
  chmod 600 "$DB_OPTS_FILE"
  # Option-file values are double-quoted; escape backslashes and quotes.
  v="${DB_PASS//\\/\\\\}"
  v="${v//\"/\\\"}"
  {
    printf '[client]\n'
    printf 'user="%s"\n' "$DB_USER"
    printf 'password="%s"\n' "$v"
    printf 'host="%s"\n' "$DB_HOST"
    printf 'port=%s\n' "$DB_PORT"
  } > "$DB_OPTS_FILE"
}
