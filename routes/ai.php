<?php

use App\Mcp\Servers\OpsAssistantServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/ops-assistant', OpsAssistantServer::class)
    ->middleware(['auth', 'verified', 'backend', 'admin', 'throttle:60,1']);
