<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Account;
use App\Models\Workspace;
use App\Services\Publisher;
use Illuminate\Support\Str;
use App\Jobs\PublishAccount;
use Laravel\Passport\Passport;
use App\Services\WorkspaceOwner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

test('workspace creation editing and data are private even under the same account', function (): void {
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
    $publication = $this->postJson('/api/schedule', ['draft_id' => $payload['id'], 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String()])->assertOk()->json('0.id');

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
});

test('background publishing uses the accounts workspace and restores context', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $second = Workspace::create(['user_id' => $user->id, 'name' => 'Novogamer', 'icon' => '★']);
    $context = app(WorkspaceOwner::class);
    Queue::fake([PublishAccount::class]);
    $account = $context->run($user->id, function () {
        $account = Account::create(['name' => 'Novogamer X', 'provider' => 'x', 'provider_id' => '123', 'credentials' => ['access_token' => 'novogamer-token']]);
        $draft = Draft::create(['content' => ['items' => [['text' => 'Novogamer update', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]]);
        app(App\Services\Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'now']);

        return $account;
    }, $second->id);
    Http::preventStrayRequests();
    Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => 'published']])]);

    app(Publisher::class)->publishAccount($account->id);
    $this->assertSame($user->workspace_id, $context->workspaceId());
    $this->assertDatabaseHas('publications', ['workspace_id' => $second->id, 'status' => 'published']);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer novogamer-token') && $request['text'] === 'Novogamer update');
    Queue::assertPushed(PublishAccount::class, fn (PublishAccount $job): bool => $job->accountId === $account->id);
});

test('social connection selection keeps the original workspace', function (): void {
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
});
