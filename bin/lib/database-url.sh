# shellcheck shell=bash
# Shared by bin/backup-database.sh and bin/restore-database.sh (sourced, not executed).
#
# load_database_url APP_DIR   sets DB_USER DB_PASS DB_HOST DB_PORT DB_NAME from DATABASE_URL
#                             (environment first, else APP_DIR/.env.local.php). Values are never printed.
# write_db_options_file       writes a 0600 [client] option file and sets DB_OPTS_FILE (caller removes it).

load_database_url() {
  local app_dir="$1" line key value
  if [[ -z "${DATABASE_URL:-}" ]]; then
    [[ -r "$app_dir/.env.local.php" ]] \
      || { printf 'ERROR: DATABASE_URL is not set and %s/.env.local.php is not readable.\n' "$app_dir" >&2; exit 1; }
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
  done < <(APP_DIR="$app_dir" php -r '
    $url = getenv("DATABASE_URL");
    if (false === $url || "" === $url) {
        $env = require getenv("APP_DIR")."/.env.local.php";
        $url = $env["DATABASE_URL"] ?? "";
    }
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
