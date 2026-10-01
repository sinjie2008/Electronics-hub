<?php

use App\Mcp\Servers\SystemServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('system', SystemServer::class);
