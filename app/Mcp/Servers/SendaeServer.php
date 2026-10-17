<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use App\Mcp\Tools\SaveDraft;
use App\Mcp\Tools\AttachMedia;
use App\Mcp\Tools\DeleteDraft;
use App\Mcp\Tools\SchedulePost;
use App\Mcp\Tools\SaveWorkspace;
use App\Mcp\Tools\WorkspaceTool;
use App\Mcp\Tools\DeleteWorkspace;
use App\Mcp\Tools\PreviewSchedule;
use App\Mcp\Tools\RefreshAnalytics;
use App\Mcp\Tools\SavePostingSlots;
use App\Mcp\Tools\CancelPublication;
use App\Mcp\Tools\DeletePublication;
use App\Mcp\Tools\DisconnectAccount;
use App\Mcp\Tools\RecoverPublication;
use App\Mcp\Tools\SaveWorkspaceImage;
use App\Mcp\Tools\DeleteWorkspaceImage;

class SendaeServer extends Server
{
    protected string $name = 'Sendae';

    protected string $version = '0.1.3';

    protected string $instructions = 'Manage the authenticated user’s social publishing workspaces. Call workspace with no arguments to list workspaces as id, name, and default. Pass that workspace_id on every other call, including workspace when reading drafts, accounts, media, slots, publications, and analytics. A missing workspace_id is an error even when the account has one workspace. Draft content has items [{text,media_ids}], overrides keyed by provider (x,bluesky,threads,facebook,linkedin,linkedin_page) with arrays of items, and account_ids. Optional content.tags and content.notes are private draft metadata and are never published. Use save_posting_slots to replace an account’s weekly queue slots and timezone; an empty slots array clears them. Use preview_schedule before queueing. Update queued content with schedule_post update=true and mode=preserve to keep its times. Use the current version when editing. A stale version overwrites the existing draft. Schedule/publish require an authenticated hosted server and publish without another in-app approval. Never retry uncertain publications without verifying the provider outcome. Media upload accepts base64 bytes; provider tokens are never returned.';

    protected array $tools = [RecoverPublication::class, WorkspaceTool::class, SaveDraft::class, AttachMedia::class, SavePostingSlots::class, SchedulePost::class, CancelPublication::class, RefreshAnalytics::class, PreviewSchedule::class, DeleteDraft::class, DeletePublication::class, SaveWorkspace::class, DeleteWorkspace::class, SaveWorkspaceImage::class, DeleteWorkspaceImage::class, DisconnectAccount::class];
}
