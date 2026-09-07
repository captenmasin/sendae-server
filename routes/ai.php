<?php

use App\Http\Middleware\LocalOrOwner;
use App\Mcp\Servers\SendaeServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

Mcp::oauthRoutes();
Mcp::web('/mcp', SendaeServer::class)->middleware(['auth:api', LocalOrOwner::class, CheckToken::using('mcp:use'), 'throttle:120,1']);
