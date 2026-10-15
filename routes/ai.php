<?php

declare(strict_types=1);

use App\Mcp\Servers\DevNullServer;
use Laravel\Mcp\Facades\Mcp;

// Clients register and sign in through Passport's OAuth endpoints...
Mcp::oauthRoutes();

Mcp::web('/mcp', DevNullServer::class)->middleware(['auth:api', 'throttle:mcp']);
