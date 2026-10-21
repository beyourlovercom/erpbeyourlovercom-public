#!/usr/bin/env bash
set -euo pipefail

readonly mysql_root="${BYL_MYSQL_8_ROOT:-/opt/homebrew/opt/mysql@8.4}"
readonly mysqld="$mysql_root/bin/mysqld"
readonly mysql="$mysql_root/bin/mysql"
readonly mysqladmin="$mysql_root/bin/mysqladmin"

for binary in "$mysqld" "$mysql" "$mysqladmin"; do
  [[ -x "$binary" ]] || { echo "Missing MySQL 8 binary: $binary" >&2; exit 1; }
done
[[ "$($mysqld --version)" =~ Ver\ 8\. ]] || { echo 'MySQL 8.x is required.' >&2; exit 1; }

run_root="$(mktemp -d /tmp/byl-p4-mysql.XXXXXX)"
datadir="$run_root/data"
socket="$run_root/mysql.sock"
pid_file="$run_root/mysql.pid"
log_file="$run_root/mysql.log"
database="byl_p4_$(date +%s)_$RANDOM"
server_started=false

cleanup() {
  if [[ "$server_started" == true && -f "$pid_file" ]]; then
    "$mysqladmin" --no-defaults --socket="$socket" -u root shutdown >/dev/null 2>&1 || true
  fi
  rm -rf "$run_root"
}
trap cleanup EXIT HUP INT TERM

mkdir "$datadir"
"$mysqld" --no-defaults --initialize-insecure --basedir="$mysql_root" --datadir="$datadir" >/dev/null 2>&1
"$mysqld" --no-defaults --basedir="$mysql_root" --datadir="$datadir" --socket="$socket" --pid-file="$pid_file" --log-error="$log_file" --skip-networking=1 --skip-name-resolve --daemonize
server_started=true

for attempt in {1..40}; do
  "$mysqladmin" --no-defaults --socket="$socket" -u root ping >/dev/null 2>&1 && break
  sleep 0.25
done
"$mysqladmin" --no-defaults --socket="$socket" -u root ping >/dev/null
"$mysql" --no-defaults --socket="$socket" -u root -e "CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

BYL_MYSQL_STABILITY_ENABLED=1 \
BYL_MYSQL_STABILITY_DATABASE="$database" \
DB_CONNECTION=mysql \
DB_HOST=localhost \
DB_PORT=0 \
DB_SOCKET="$socket" \
DB_DATABASE="$database" \
DB_USERNAME=root \
DB_PASSWORD='' \
ERP_LOCAL_ADMIN_BYPASS=false \
php artisan test tests/Feature/ProductionStability/MySql8ProductionCompatibilityTest.php

version="$($mysql --no-defaults --socket="$socket" -u root -N -e 'select version()')"
echo "MYSQL8_PRODUCTION_COMPATIBILITY=PASS version=$version database=$database"
