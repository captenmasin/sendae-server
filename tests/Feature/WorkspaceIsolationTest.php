<?php

namespace Tests\Feature;

use App\Jobs\PublishAccount;
use App\Models\Account;
use App\Models\Draft;
use App\Models\Publication;
use App\Models\User;
use App\Services\Attachments;
use App\Services\Publisher;
use App\Services\Workspace;
use App\Services\WorkspaceOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_cannot_read_edit_schedule_or_overwrite_each_others_data(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $a = User::factory()->create();
        $b = User::factory()->create();
        Passport::actingAs($a, ['mcp:use']);
        $account = Account::create(['name' => 'Private account', 'provider' => 'x', 'provider_id' => '123', 'credentials' => ['access_token' => 'secret-a']]);
        $media = app(Attachments::class)->store(UploadedFile::fake()->image('private.png'));
        $original = Storage::disk('local')->get($media->path);
        $payload = ['id' => (string) Str::uuid(), 'title' => 'Private draft', 'version' => 0, 'content' => ['items' => [['text' => 'Private words', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]];
        $draft = $this->postJson('/api/drafts', $payload)->assertOk()->json('draft');
        $publication = $this->postJson('/api/schedule', ['draft_id' => $draft['id'], 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String()])->assertOk()->json('0');
        Passport::actingAs($b, ['mcp:use']);
        $this->getJson('/api/state')->assertOk()->assertJsonCount(0, 'drafts')->assertJsonCount(0, 'accounts')->assertJsonCount(0, 'media')->assertJsonCount(0, 'publications');
        $this->postJson('/api/drafts', $payload)->assertNotFound();
        $this->postJson('/api/schedule', ['draft_id' => $draft['id'], 'version' => 1, 'mode' => 'now'])->assertUnprocessable();
        $this->postJson('/api/cancel', ['id' => $publication['id']])->assertNotFound();
        $this->postJson('/api/recover', ['id' => $publication['id'], 'action' => 'reschedule', 'scheduled_at' => now()->addHour()->toIso8601String()])->assertUnprocessable();
        $this->postJson('/api/disconnect', ['id' => $account->id])->assertUnprocessable();
        $this->postJson('/api/analytics', ['id' => $publication['id']])->assertUnprocessable();
        $this->getJson('/api/media/'.$media->id)->assertNotFound();
        $this->post('/api/media', ['id' => $media->id, 'file' => UploadedFile::fake()->image('overwrite.png')], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame($original, Storage::disk('local')->get($media->path));
        $this->assertSame('Private draft', Draft::withoutGlobalScope('owner')->findOrFail($draft['id'])->title);
        $this->assertSame('scheduled', Publication::withoutGlobalScope('owner')->findOrFail($publication['id'])->status);
        Http::assertNothingSent();
    }

    public function test_signed_media_and_background_jobs_work_for_their_owner_only(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Queue::fake([PublishAccount::class]);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a);
        $media = app(Attachments::class)->store(UploadedFile::fake()->image('public-at-publish.png'));
        $account = Account::create(['name' => 'A', 'provider' => 'x', 'provider_id' => '123', 'credentials' => ['access_token' => 'secret-a']]);
        $draft = Draft::create(['content' => ['items' => [['text' => 'A post', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$account->id]]]);
        $publication = app(Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'now'])[0];
        $this->actingAs($b);
        // The same social account may be connected independently in a second private workspace.
        Account::create(['name' => 'B', 'provider' => 'x', 'provider_id' => '123']);
        $this->get('/media/'.$media->id)->assertForbidden();
        $this->get(URL::temporarySignedRoute('media.public', now()->addMinute(), ['media' => $media->id]))->assertOk();
        app(Publisher::class)->tick();
        Queue::assertPushed(PublishAccount::class, fn ($job) => $job->accountId === $account->id);
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => 'confirmed-a']])]);
        (new PublishAccount($account->id))->handle(app(Publisher::class));
        $this->assertSame('published', Publication::withoutGlobalScope('owner')->findOrFail($publication->id)->status);
        $this->assertSame(0, Publication::count());
        $this->assertSame($b->id, app(WorkspaceOwner::class)->id());
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-a'));
    }

    public function test_workspace_limits_do_not_count_another_customers_data(): void
    {
        Storage::fake('local');
        config(['sendae.storage_limit_bytes' => 1, 'sendae.draft_limit' => 1]);
        $a = User::factory()->create();
        $b = User::factory()->create();
        Passport::actingAs($a, ['mcp:use']);
        Draft::create(['title' => 'Existing A', 'content' => ['items' => [], 'overrides' => [], 'account_ids' => []]]);
        $payload = ['id' => (string) Str::uuid(), 'title' => 'New', 'version' => 0, 'content' => ['items' => [['text' => 'Hello', 'media_ids' => []]], 'overrides' => [], 'account_ids' => []]];
        $this->postJson('/api/drafts', $payload)->assertUnprocessable()->assertJsonValidationErrors('draft');
        Passport::actingAs($b, ['mcp:use']);
        $this->postJson('/api/drafts', $payload)->assertOk();
        $this->post('/api/media', ['file' => UploadedFile::fake()->image('too-large.png')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        Storage::disk('local')->assertDirectoryEmpty('media');
    }
}
