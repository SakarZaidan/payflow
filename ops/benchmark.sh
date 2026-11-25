#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p artifacts/benchmark

run() {
    local mode="$1" expected="$2" start
    start="$(date -u '+%F %T')"
    docker compose run --rm -T -e "MODE=$mode" -e "RUN_ID=$mode-$(date +%s%N)" k6 run /scripts/payments.js \
        | tee "artifacts/benchmark/$mode-k6.txt"
    docker compose exec -T web php ops/verify-db.php "$start" "$expected" \
        | tee "artifacts/benchmark/$mode-db.json"
    # Keep the next run outside this run's last timestamp bucket.
    sleep 2
}

run burst 1
run mixed 12001
