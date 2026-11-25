#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ ! -f .env ]]; then
    umask 077
    cp .env.example .env
fi
php <<'PHP'
<?php
$contents = file_get_contents('.env');
$values = [
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'DB_PASSWORD' => bin2hex(random_bytes(24)),
    'MYSQL_ROOT_PASSWORD' => bin2hex(random_bytes(24)),
    'PAYFLOW_MCP_DB_PASSWORD' => bin2hex(random_bytes(24)),
    'PAYFLOW_MERCHANT_TOKEN' => bin2hex(random_bytes(24)),
    'PAYFLOW_SIMULATOR_SECRET' => bin2hex(random_bytes(24)),
    'PAYFLOW_OPERATOR_PASSWORD' => bin2hex(random_bytes(12)),
];
foreach ($values as $name => $value) {
    if (!preg_match('/^'.$name.'=/m', $contents)) {
        $contents .= "\n".$name.'='.$value."\n";
    } else {
        $contents = preg_replace('/^'.$name.'=[ \t]*$/m', $name.'='.$value, $contents);
    }
}
file_put_contents('.env', $contents);
PHP
docker compose build web
docker compose up -d mysql redis
docker compose run --rm web php artisan migrate --force
docker compose run --rm web php artisan db:seed --force
docker compose up -d --no-build
