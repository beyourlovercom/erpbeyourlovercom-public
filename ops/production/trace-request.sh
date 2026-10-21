#!/bin/zsh
set -euo pipefail

ROOT="${ERP_PRODUCTION_ROOT:-/Users/a/AI/production/erp.beyourlover.com}"
REQUEST_ID="${1:-}"

if [[ ! "$REQUEST_ID" =~ '^[A-Za-z0-9._:-]{8,128}$' ]]; then
  echo "usage: $0 <X-ERP-Request-ID>" >&2
  exit 2
fi

FILES=(
  "$ROOT/logs/nginx-origin-access.log"
  "$ROOT/logs/nginx-origin-error.log"
  "$ROOT/logs/php-fpm-error.log"
  "$ROOT/logs/php-worker-error.log"
  "$ROOT/shared/storage/logs/laravel.log"
)

found=0
for file in "${FILES[@]}"; do
  [[ -f "$file" ]] || continue
  matches=$(grep -F -- "$REQUEST_ID" "$file" | tail -50 || true)
  [[ -n "$matches" ]] || continue
  found=1
  echo "===== $file ====="
  print -r -- "$matches"
done

if [[ "$found" -eq 0 ]]; then
  echo "No log entries found for request_id=$REQUEST_ID" >&2
  exit 1
fi
