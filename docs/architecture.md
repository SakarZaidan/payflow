# How PayFlow fits together

[Open the editable architecture drawing](architecture.excalidraw) · [View the rendered diagram](architecture.svg)

![PayFlow architecture showing the merchant, gateway, worker, local storage and read-only operations tools](architecture.svg)

MySQL owns payment state and the ledger. Redis moves work between processes. If a queue message goes missing, the outbox becomes eligible again. The provider call happens outside the database transaction, so another request never holds a payment row open while waiting on the gateway.

## Payment and refund

```mermaid
sequenceDiagram
    actor Merchant
    participant API as Laravel API
    participant DB as MySQL
    participant Relay as Outbox relay
    participant Q as Redis
    participant W as Worker
    participant G as Simulator or Tap
    Merchant->>API: POST payment or refund with Idempotency-Key
    API->>DB: Insert operation and outbox in one transaction
    Note over API,DB: Refunds lock the parent and reserve remaining amount
    API-->>Merchant: 202 and status URL
    Relay->>DB: Claim due outbox rows
    Relay->>Q: Enqueue operation ID
    Q->>W: Deliver job
    W->>G: Submit with stable operation reference or retrieve existing result
    G-->>W: Provider ID, state, currency, and amount
    opt Hosted checkout
        Merchant->>API: Read checkout URL
        Merchant->>G: Complete provider-hosted checkout
        G-->>API: Signed notification when reachable
        API->>DB: Persist verified inbox event
        W->>G: Poll authoritative status
    end
    W->>DB: Lock operation and post journal on confirmed movement
    Merchant->>API: Read final status
```

A captured charge debits gateway clearing and credits merchant payable. A confirmed refund reverses the corresponding amount. The application only inserts ledger postings, and each movement maps to a unique source journal. The model records the gateway payout into platform cash and leaves the merchant liability on the books.

## Settlement and reconciliation

```mermaid
sequenceDiagram
    participant S as Kuwait scheduler
    participant F as Local disk or S3
    participant R as Reconciliation service
    participant D as MySQL / ledger
    actor Operator
    S->>R: Reconcile last closed business date at 01:00
    R->>F: Read normalized statements by gateway and date
    alt Statement missing
        R->>D: Record waiting for report
    else Statement present
        R->>D: Lock run and match confirmed movements to statement
        alt Every item and total match
            R->>D: Post processing fee and net payout
        else Exceptions found
            R->>D: Record exceptions and keep settlement entries pending
        end
        R->>D: Freeze source fingerprint
    end
    Operator->>D: Inspect run and linked payment evidence via console
```

Reports live under `reports/<gateway>/<YYYY-MM-DD>.json` on the configured disk. Fees, payout and signed movement amounts are all integer fils. Refunds are negative. Daily reconciliation uses the previous Kuwait calendar day. Import a delayed statement with `docker compose exec -T web php artisan payflow:import <date> --gateway=simulator < statement.json`. A frozen statement can be rerun with the same fingerprint. Amended statements require a separate accounting adjustment workflow.

```json
{
  "business_date": "2026-01-15",
  "currency": "KWD",
  "fee": 70,
  "payout": 1930,
  "items": [
    {"provider_id": "chg_example", "amount": 2500},
    {"provider_id": "ref_example", "amount": -500}
  ]
}
```

## Where the code lives

| Location | Responsibility |
| --- | --- |
| `routes/api.php`, `routes/web.php` | Merchant and webhook API, operator session and console |
| `app/PayFlow/` | Payment actions, provider adapters, outbox worker, ledger and reconciliation |
| `app/PayFlow/Mcp/`, `routes/ai.php` | Local stdio server and selected evidence queries |
| `database/migrations/`, `database/seeders/` | Constraints, composite indexes and synthetic incidents |
| `resources/views/`, `public/` | Console templates, brand assets, fonts and stylesheet |
| `ops/`, `tests/` | Local setup, smoke tests and performance verification |

For an AWS deployment, the web, worker and scheduler map to separate ECS task definitions, MySQL to RDS, Redis to ElastiCache and statement storage to S3. A real deployment would also need managed secrets, TLS, backup and restore drills, and a public callback ingress for Tap. This project tests the S3 storage interface with a local mock.
