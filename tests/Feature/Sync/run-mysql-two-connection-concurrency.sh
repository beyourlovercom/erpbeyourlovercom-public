#!/usr/bin/env bash
set -euo pipefail

readonly mysql_root="${BYL_MYSQL_84_ROOT:-/opt/homebrew/opt/mysql@8.4}"
readonly mysqld="$mysql_root/bin/mysqld"
readonly mysql="$mysql_root/bin/mysql"
readonly mysqladmin="$mysql_root/bin/mysqladmin"

if [[ ! -x "$mysqld" || ! -x "$mysql" || ! -x "$mysqladmin" ]]; then
  echo 'MySQL 8.4.11 binaries are required at BYL_MYSQL_84_ROOT.' >&2
  exit 1
fi

if [[ "$($mysqld --version)" != *'8.4.11'* ]]; then
  echo 'MySQL server must be exactly 8.4.11.' >&2
  exit 1
fi

run_root="$(mktemp -d /tmp/byl-001fc-mysql.XXXXXX)"
datadir="$run_root/data"
socket="$run_root/mysql.sock"
pid_file="$run_root/mysql.pid"
log_file="$run_root/mysql.log"
database="byl_001fc_$(date +%s)_$RANDOM"
server_started=false

cleanup() {
  if [[ "$server_started" == true && -f "$pid_file" ]]; then
    "$mysqladmin" --no-defaults --socket="$socket" -u root shutdown >/dev/null 2>&1 || kill "$(cat "$pid_file")" >/dev/null 2>&1 || true
  fi
  rm -rf "$run_root"
}
trap cleanup EXIT HUP INT TERM

mkdir "$datadir"
"$mysqld" --no-defaults --initialize-insecure --basedir="$mysql_root" --datadir="$datadir"
"$mysqld" --no-defaults --basedir="$mysql_root" --datadir="$datadir" --socket="$socket" --pid-file="$pid_file" --log-error="$log_file" --skip-networking=1 --bind-address=127.0.0.1 --skip-name-resolve --daemonize
server_started=true

for attempt in {1..40}; do
  "$mysqladmin" --no-defaults --socket="$socket" -u root ping >/dev/null 2>&1 && break
  sleep 0.25
done
"$mysqladmin" --no-defaults --socket="$socket" -u root ping >/dev/null

read -r version comment port charset collation isolation < <("$mysql" --no-defaults --socket="$socket" -u root -N -e 'SELECT @@version, @@version_comment, @@port, @@character_set_server, @@collation_server, @@transaction_isolation')
[[ "$version" == '8.4.11' && "$comment" == 'Homebrew' && "$port" == '0' && "$charset" == 'utf8mb4' ]] || { echo 'MySQL isolation identity validation failed.' >&2; exit 1; }

"$mysql" --no-defaults --socket="$socket" -u root -e "CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

BYL_MYSQL_CONCURRENCY_ENABLED=1 \
BYL_MYSQL_CONCURRENCY_SOCKET="$socket" \
BYL_MYSQL_CONCURRENCY_DATABASE="$database" \
DB_CONNECTION=mysql \
DB_HOST=localhost \
DB_PORT=0 \
DB_SOCKET="$socket" \
DB_DATABASE="$database" \
DB_USERNAME=root \
DB_PASSWORD='' \
ERP_LOCAL_ADMIN_BYPASS=false \
php artisan test tests/Feature/Sync/MySqlTwoConnectionConcurrencyTest.php

echo "MYSQL_TWO_CONNECTION_CONCURRENCY=PASS version=$version charset=$charset collation=$collation isolation=$isolation"
