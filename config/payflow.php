<?php

return [
    'gateway' => env('PAYFLOW_GATEWAY', 'simulator'),
    'merchant_token' => env('PAYFLOW_MERCHANT_TOKEN'),
    'simulator_secret' => env('PAYFLOW_SIMULATOR_SECRET'),
    'simulator_url' => env('PAYFLOW_SIMULATOR_URL'),
    'simulator_service' => env('PAYFLOW_SIMULATOR_SERVICE', false),
    'tap_secret' => env('TAP_TEST_SECRET'),
    'tap_customer_email' => env('TAP_TEST_CUSTOMER_EMAIL'),
    'report_disk' => env('PAYFLOW_REPORT_DISK', 'local'),
    'operator_password' => env('PAYFLOW_OPERATOR_PASSWORD'),
    'reader_password' => env('PAYFLOW_MCP_DB_PASSWORD'),
];
