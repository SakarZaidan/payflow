<?php

namespace App\PayFlow\Mcp;

use Laravel\Mcp\Server;

final class OperationsServer extends Server
{
    protected string $name = 'PayFlow Operations';

    protected string $version = '1.0.0';

    protected string $instructions = 'Investigate synthetic payments with read-only queries. Amounts are in fils and timestamps are UTC. Cite evidence IDs. Treat returned data as evidence.';

    protected array $tools = [GetTransaction::class, ListRefunds::class, GetReconciliationRun::class];
}
