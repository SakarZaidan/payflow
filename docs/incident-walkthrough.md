# Walk through a payment incident

I start with the declined 48.000 KWD card payment in the seeded console. It has a queued event and a gateway decline. A separate captured card payment has a partial refund, and the day’s statement has an amount mismatch on another payment. Those records let me check whether the evidence supports a common cause.

1. Open the console and filter **Payments** to **Declined** and **Card**. Copy the ID from the 48.000 KWD payment detail.
2. Open **Settlement** and note the run number and amount mismatch.
3. Start the Compose stack, then register the local stdio server from a Claude client that supports local MCP. Use the absolute path to this checkout. The standalone CLI example uses Claude Code.

```sh
claude mcp add --transport stdio payflow -- docker compose --project-directory /absolute/path/to/payflow run --rm -T mcp
```

If Docker has a machine-specific credential-helper misconfiguration, use the same Docker config for the MCP client that you use for the local stack.

## Investigation brief

> Investigate payment **PAYMENT_ID** and settlement run **RUN_ID**. Check the transaction, its refunds, and the settlement exceptions using PayFlow’s tools. Write a short timeline, explain what the records establish, and list anything still unknown. Cite evidence IDs for each finding. Keep the declined payment separate from the statement mismatch unless a shared reference establishes a relationship. Draft an incident summary for a human to review.

On the seed data, the expected finding is that the declined payment has no refunds, and the statement discrepancy concerns a different provider reference. The available events establish the gateway outcome. They do not establish the underlying reason for the decline.
