#!/usr/bin/env bash
set -euo pipefail

readonly redis_server="${BYL_REDIS_ROOT:-/opt/homebrew/opt/redis}/bin/redis-server"
readonly redis_cli="${BYL_REDIS_ROOT:-/opt/homebrew/opt/redis}/bin/redis-cli"

[[ -x "$redis_server" && -x "$redis_cli" ]] || { echo 'Redis binaries are required at BYL_REDIS_ROOT.' >&2; exit 1; }

run_root="$(mktemp -d /tmp/byl-001fd-redis.XXXXXX)"
port="$(php -r '$socket = stream_socket_server("tcp://127.0.0.1:0"); echo (int) substr(strrchr(stream_socket_get_name($socket, false), ":"), 1); fclose($socket);')"
prefix="byl_001fd_$(date +%s)_${RANDOM}_"
pid_file="$run_root/redis.pid"
log_file="$run_root/redis.log"
server_started=false

cleanup() {
  if [[ "$server_started" == true ]]; then
    "$redis_cli" -h 127.0.0.1 -p "$port" shutdown nosave >/dev/null 2>&1 || [[ ! -f "$pid_file" ]] || kill "$(cat "$pid_file")" >/dev/null 2>&1 || true
  fi
  rm -rf "$run_root"
}
trap cleanup EXIT HUP INT TERM

"$redis_server" --bind 127.0.0.1 --port "$port" --protected-mode yes --save '' --appendonly no --dir "$run_root" --pidfile "$pid_file" --logfile "$log_file" --daemonize yes
server_started=true
for attempt in {1..40}; do
  "$redis_cli" -h 127.0.0.1 -p "$port" ping >/dev/null 2>&1 && break
  sleep 0.25
done
[[ "$($redis_cli -h 127.0.0.1 -p "$port" ping)" == PONG ]] || { echo 'Disposable Redis did not become reachable.' >&2; exit 1; }

BYL_REDIS_RUNTIME_ENABLED=1 \
BYL_REDIS_RUNTIME_HOST=127.0.0.1 \
BYL_REDIS_RUNTIME_PORT="$port" \
BYL_REDIS_RUNTIME_PREFIX="$prefix" \
REDIS_CLIENT=predis \
REDIS_HOST=127.0.0.1 \
REDIS_PORT="$port" \
REDIS_CACHE_CONNECTION=cache \
CACHE_STORE=redis \
QUEUE_CONNECTION=redis \
REDIS_QUEUE=runtime \
REDIS_QUEUE_BLOCK_FOR=1 \
ERP_LOCAL_ADMIN_BYPASS=false \
php artisan test tests/Feature/Runtime/RedisQueueSchedulerRuntimeTest.php

echo "REDIS_QUEUE_SCHEDULER_RUNTIME=PASS version=$($redis_server --version | awk '{print $3}') port=$port"
