# Verification

The repeatable checks run on the Docker simulator stack. The Tap adapter has a credentialed smoke-test procedure in [Tap sandbox](tap-sandbox.md).

- `docker compose exec web php artisan test` covers the application contract, refund reservations, retry cutoff, signed inbox replay, ledger balancing and settlement reruns.
- `bash ops/benchmark.sh` runs a 50-client same-key burst and five minutes at 50 requests per second. It checks the end-to-end simulator and journal counts after the queues drain.
- `bash ops/check-services.sh` checks the regression suite, the MCP reader grant and the S3 report object adapter.
- `python3 ops/mcp-smoke.py` exercises the stdio discovery and tool calls against the seeded incident.
