# Tap sandbox smoke test

The simulator runs by default. To check Tap itself, set `PAYFLOW_GATEWAY=tap`, `TAP_TEST_SECRET` and `TAP_TEST_CUSTOMER_EMAIL` in the ignored `.env`. Use a test key and a synthetic email. Apply the environment change with `docker compose up -d --force-recreate web worker relay scheduler`. Keep the app bound to loopback.

1. Submit a small KNET payment through the merchant API and poll its status URL until `checkout_url` is available.
2. Open that URL in a browser. Use the current [Tap test cards](https://developers.tap.company/reference/testing-cards). Poll until PayFlow reports capture. Repeat with a decline outcome.
3. Repeat both cases for a hosted card payment. When the test account supports refunds, request a partial refund and check that the gateway reports a final refund status and PayFlow posts one reversal.
4. Record the test date, rail, outcome, and redacted evidence. The app polls Tap for authoritative state. Incoming callback and statement behavior are covered by the synthetic local fixtures.

If an operation is ambiguous after the safe retry window, inspect its stable operation reference in the provider dashboard before taking another financial action. Restore `PAYFLOW_GATEWAY=simulator` and recreate the services when finished.
