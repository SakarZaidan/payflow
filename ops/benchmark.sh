#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p artifacts/benchmark

run() {
    local mode="$1" start completed expected status=0
    start="$(date -u '+%F %T')"
    docker compose run --rm -T -e "MODE=$mode" -e "RUN_ID=$mode-$(date +%s%N)" k6 run /scripts/payments.js \
        | tee "artifacts/benchmark/$mode-k6.txt" || status=$?
    completed="$(awk '/^[[:space:]]+iterations[.]+:/ {print $2}' "artifacts/benchmark/$mode-k6.txt" | tail -1)"
    [[ "$completed" =~ ^[1-9][0-9]*$ ]] || return 1
    expected=1
    if [[ "$mode" == mixed ]]; then
        expected=$((completed - (completed + 4) / 5 + 1))
    fi
    docker compose exec -T web php ops/verify-db.php "$start" "$expected" \
        | tee "artifacts/benchmark/$mode-db.json" || return 1
    # Keep the next run outside this run's last timestamp bucket.
    sleep 2
    return "$status"
}

burst_status=0
mixed_status=0
run burst || burst_status=$?
run mixed || mixed_status=$?
(( burst_status == 0 && mixed_status == 0 ))
