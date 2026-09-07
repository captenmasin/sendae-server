<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AttachMedia;
use App\Mcp\Tools\CancelPublication;
use App\Mcp\Tools\RecoverPublication;
use App\Mcp\Tools\RefreshAnalytics;
use App\Mcp\Tools\SaveDraft;
use App\Mcp\Tools\SchedulePost;
use App\Mcp\Tools\WorkspaceTool;
use Laravel\Mcp\Server;

class SendaeServer extends Server
{
    protected string $name = 'Sendae';

    protected string $version = '0.1.3';

    protected string $instructions = 'Manage the authenticated user’s private social publishing workspace. Read workspace first for account and draft IDs. Draft content has items [{text,media_ids}], overrides keyed by provider (x,threads,facebook,linkedin,linkedin_page) with arrays of items, and account_ids. Use the current version when editing. Conflicting edits preserve both versions. Schedule/publish require an authenticated hosted server and publish without another in-app approval. Never retry uncertain publications without verifying the provider outcome. Media upload accepts base64 bytes; provider tokens are never returned.';

    protected array $tools = [RecoverPublication::class, WorkspaceTool::class, SaveDraft::class, AttachMedia::class, SchedulePost::class, CancelPublication::class, RefreshAnalytics::class];
}
