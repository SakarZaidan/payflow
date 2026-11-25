<?php

namespace App\PayFlow\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetReconciliationRun extends Tool
{
    protected string $name = 'get_reconciliation_run';

    protected string $description = 'Retrieve a settlement reconciliation run and up to 25 exceptions.';

    public function schema(JsonSchema $schema): array
    {
        return ['run_id' => $schema->integer()->required()];
    }

    public function handle(Request $request, Evidence $evidence): Response|ResponseFactory
    {
        $input = $request->validate(['run_id' => 'required|integer|min:1']);
        $record = $evidence->reconciliation((int) $input['run_id']);

        return $record === null ? Response::error('Record not found.') : Response::structured($record);
    }
}
