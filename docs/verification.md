# Verification

I use a synthetic statement and a local gateway so I can run the same payment, refund, and reconciliation checks from a fresh checkout.

| Check | Command |
| --- | --- |
| Feature suite, isolated in-memory database | `docker compose exec web php artisan test` |
| Worker restart, concurrent refund reservations and signed callback replay on MySQL and Redis | `python3 ops/local-smoke.py` |
| MySQL read-only grant and S3Mock report storage | `bash ops/check-services.sh` |
| MCP discovery and evidence queries over stdio | `python3 ops/mcp-smoke.py` |
| Idempotency burst and sustained write workload | `bash ops/benchmark.sh` |

The feature suite includes a lost Tap response with a bounded retry window, webhook deduplication, stale status delivery, and settlement reruns. The MySQL smoke sends two refunds whose sum exceeds a capture and expects one acceptance and one conflict.

## Console

I checked operator sign-in, keyboard entry, payment filters, empty results, settlement evidence links, sign-out, and the narrow payments layout in Chromium. The automated axe checks found zero WCAG A/AA violations in the tested views.

```sh
npm install --prefix artifacts/browser playwright@1.65.1 @axe-core/playwright@4.14.1
artifacts/browser/node_modules/.bin/playwright install chromium
node ops/browser-check.cjs
```

Set `PAYFLOW_CAPTURE=1` on the last command to refresh the screenshots from a seeded database.

## Load methodology

Run the benchmark on a quiet simulator stack. It warms the app workers and sends 50 requests concurrently with one idempotency key. The sustained phase runs for five minutes at 50 requests per second and mixes card and KNET submissions with a shared-key replay every fifth iteration.

The API target is p95 under 250 ms and under 0.5% unexpected HTTP errors. The five-minute run has a separate load-generator budget of fewer than 75 dropped iterations (0.5% of 15,000 planned requests). The harness checks distinct operation, gateway and journal counts after the queues drain. The load generator does not call Tap.

### Local run results

Measured on Docker Desktop under WSL with 20 vCPUs and about 7.5 GiB available to Docker. The app used MySQL 8.4, Redis 7.4, PHP 8.4 and four queue workers.

| Scenario | Completed requests | p95 API acceptance | Unexpected HTTP errors | Verified operations / provider records / journals |
| --- | ---: | ---: | ---: | ---: |
| Same-key burst | 50 | 55.02 ms | 0 | 1 / 1 / 1 |
| Five-minute mixed run | 14,973 | 212.18 ms | 0 | 11,979 / 11,979 / 11,979 |

The load generator dropped 28 scheduled iterations during the mixed run, about 0.19%. The run used a zero-drop threshold and flagged those drops. The final harness budgets load-generator drops separately from API failures and verifies the database against the completed workload. After the queues drained, there were no unbalanced journals or orphaned provider records. The longest observed submission-to-confirmation time was one second in the burst and 15 seconds in the mixed run.

On the loaded MySQL dataset, the payment history query examined 23,355 payment rows and sorted them in 68.9 ms when I disabled the composite index. With the index enabled, the reverse range scan returned the first ten rows in 0.28 ms. The two `EXPLAIN ANALYZE` queries are in [ops/explain.sql](../ops/explain.sql).

## External smoke tests

The credentialed [Tap smoke test](tap-sandbox.md) is pending sandbox credentials. All three MCP tools passed their local contract check and MySQL denied writes from the reader account. The optional Claude walkthrough reached the account’s usage limit before it could produce a draft.
