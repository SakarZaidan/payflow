<?php

namespace App\PayFlow\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetTransaction extends Tool
{
    protected string $name = 'get_transaction';

    protected string $description = 'Retrieve a payment and its most recent status events.';

    public function schema(JsonSchema $schema): array
    {
        return ['payment_id' => $schema->string()->required()];
    }

    public function handle(Request $request, Evidence $evidence): Response|ResponseFactory
    {
        $input = $request->validate(['payment_id' => 'required|uuid']);
        $record = $evidence->transaction($input['payment_id']);

        return $record === null ? Response::error('Record not found.') : Response::structured($record);
    }
}
