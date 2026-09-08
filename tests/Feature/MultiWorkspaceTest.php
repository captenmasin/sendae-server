<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Draft;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Publisher;
use App\Services\WorkspaceOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class MultiWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_creation_editing_and_data_are_private_even_under_the_same_account(): void
    {
        $this->getJson('/api/workspaces')->assertUnauthorized();
        $user = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        $this->getJson('/api/workspaces')->assertJsonCount(1)->assertJsonPath('0.id', $user->workspace_id);
        $this->postJson('/api/workspaces', ['name' => '', 'icon' => ''])->assertUnprocessable();
        $second = $this->postJson('/api/workspaces', ['name' => 'Novogamer', 'icon' => '★'])->assertCreated()->json('id');
        $this->patchJson('/api/workspaces/'.$user->workspace_id, ['name' => 'Sitepulse', 'icon' => 'SP'])->assertJsonPath('name', 'Sitepulse');
        $account = Account::create(['name' => 'Sitepulse X', 'provider' => 'x', 'provider_id' => '123']);
        $payload = ['id' => (string) Str::uuid(), 'title' => 'Private Sitepulse draft', 'version' => 0, 'content' => ['items' => [['text' => 'Sitepulse update', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]];
        $this->postJson('/api/drafts', $payload)->assertOk();
        $publication = $this->postJson('/api/schedule', ['draft_id' => $payload['id'], 'version' => 1, 'mode' => 'now'])->assertOk()->json('0.id');

        $this->withHeader('X-Workspace-Id', $second)->getJson('/api/state')->assertJsonCount(0, 'drafts')->assertJsonCount(0, 'accounts')->assertJsonCount(0, 'publications');
        $this->postJson('/api/drafts', $payload)->assertNotFound();
        $this->postJson('/api/schedule', ['draft_id' => $payload['id'], 'version' => 1, 'mode' => 'now'])->assertUnprocessable();
        $this->postJson('/api/cancel', ['id' => $publication])->assertNotFound();
        $this->postJson('/api/disconnect', ['id' => $account->id])->assertUnprocessable();
        $this->postJson('/api/analytics', ['id' => $publication])->assertUnprocessable();
        $this->postJson('/api/deleteDraft', ['id' => $payload['id'], 'version' => 1])->assertOk();
        $this->assertNotSoftDeleted('drafts', ['id' => $payload['id']]);
        $this->withHeader('X-Workspace-Id', $user->workspace_id)->getJson('/api/state')->assertJsonPath('drafts.0.title', 'Private Sitepulse draft');

        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->withHeader('X-Workspace-Id', $second)->getJson('/api/state')->assertNotFound();
        $this->patchJson('/api/workspaces/'.$second, ['name' => 'Stolen', 'icon' => 'X'])->assertNotFound();
        $this->assertDatabaseHas('workspaces', ['id' => $second, 'name' => 'Novogamer', 'icon' => '★']);
    }

    public function test_background_publishing_uses_the_accounts_workspace_and_restores_context(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $second = Workspace::create(['user_id' => $user->id, 'name' => 'Novogamer', 'icon' => '★']);
        $context = app(WorkspaceOwner::class);
        $account = $context->run($user->id, function () {
            $account = Account::create(['name' => 'Novogamer X', 'provider' => 'x', 'provider_id' => '123', 'credentials' => ['access_token' => 'novogamer-token']]);
            $draft = Draft::create(['content' => ['items' => [['text' => 'Novogamer update', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]]);
            app(\App\Services\Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'now']);

            return $account;
        }, $second->id);
        Http::preventStrayRequests();
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => 'published']])]);

        app(Publisher::class)->publishAccount($account->id);
        $this->assertSame($user->workspace_id, $context->workspaceId());
        $this->assertDatabaseHas('publications', ['workspace_id' => $second->id, 'status' => 'published']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer novogamer-token') && $request['text'] === 'Novogamer update');
    }

    public function test_social_connection_selection_keeps_the_original_workspace(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $second = Workspace::create(['user_id' => $user->id, 'name' => 'Novogamer', 'icon' => '★']);
        Account::create(['name' => 'Sitepulse', 'provider' => 'x', 'provider_id' => '123']);
        $selection = ['workspace_id' => $second->id, 'user_id' => $user->id, 'provider' => 'x', 'accounts' => [['provider_id' => '123', 'name' => 'Novogamer', 'credentials' => ['access_token' => 'token']]], 'expires' => time() + 600];
        Passport::actingAs($user, ['mcp:use']);
        $ticket = str_repeat('a', 64);
        Cache::put('social_choices:'.$ticket, $selection, now()->addMinutes(10));
        $this->postJson('/api/connections/'.$ticket, ['accounts' => [0], 'timezone' => 'Europe/London'])->assertOk();
        $this->assertDatabaseHas('accounts', ['workspace_id' => $second->id, 'name' => 'Novogamer']);
        $this->assertDatabaseHas('accounts', ['workspace_id' => $user->workspace_id, 'name' => 'Sitepulse']);
    }
}
