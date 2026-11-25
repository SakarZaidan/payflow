<?php

use App\PayFlow\Mcp\OperationsServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('payflow-operations', OperationsServer::class);
