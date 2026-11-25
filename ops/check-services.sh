#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

export PAYFLOW_QA_URL="${PAYFLOW_QA_URL:-http://127.0.0.1:8080}"
docker compose exec -T web php artisan test
docker compose exec -T web php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$reader = $app->make(App\PayFlow\Mcp\Evidence::class)->database();
try {
    $reader->statement('UPDATE payment_operations SET state = state WHERE 1 = 0');
    fwrite(STDERR, "The reader unexpectedly has write privileges.\n");
    exit(1);
} catch (Illuminate\Database\QueryException $e) {
    if (!in_array($e->errorInfo[1] ?? null, [1142, 1143], true)) {
        throw $e;
    }
}
echo "MySQL rejected writes from the MCP account.\n";
PHP

docker compose --profile s3 up -d s3mock
docker compose exec -T web php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = [
    'driver' => 's3', 'key' => 'local-test', 'secret' => 'local-test', 'region' => 'us-east-1',
    'bucket' => 'payflow-reports', 'endpoint' => 'http://s3mock:9090', 'use_path_style_endpoint' => true,
    'throw' => true,
];
$store = Illuminate\Support\Facades\Storage::build($config);
for ($i = 0; $i < 20; $i++) {
    try {
        $store->exists('smoke/ready');
        break;
    } catch (Throwable $error) {
        if ($i === 19) {
            throw $error;
        }
        sleep(1);
    }
}
$key = 'smoke/'.bin2hex(random_bytes(8)).'.json';
$content = json_encode(['currency' => 'KWD', 'amount' => 1250]);
if (!$store->put($key, $content) || $store->get($key) !== $content || !$store->delete($key)) {
    throw new RuntimeException('S3 report round trip failed.');
}
echo "S3 report object round trip passed.\n";
PHP
