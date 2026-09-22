<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Account;
use App\Models\Publication;
use App\Services\Publisher;
use App\Services\Workspace;
use Illuminate\Support\Str;
use App\Jobs\PublishAccount;
use App\Mcp\Tools\SaveDraft;
use App\Services\Attachments;
use Laravel\Passport\Passport;
use App\Mcp\Tools\WorkspaceTool;
use App\Mcp\Servers\SendaeServer;
use App\Services\ProviderFailure;
use App\Services\SocialProviders;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\ClientRepository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function workspaceAccount(string $provider = 'x'): Account
{
    return Account::create(['provider' => $provider, 'provider_id' => (string) random_int(1000, 9999), 'name' => 'Test account', 'status' => 'connected', 'timezone' => 'Europe/London', 'slots' => [['day' => 1, 'time' => '09:00']], 'credentials' => ['access_token' => 'test-secret']]);
}

function draft(?Account $a = null, array $items = []): Draft
{
    return Draft::create(['title' => 'Test draft', 'content' => ['items' => $items ?: [['text' => 'Hello world', 'media_ids' => []]], 'overrides' => [], 'account_ids' => $a ? [$a->id] : []]]);
}

function scheduled(Account $a, array $items = []): Publication
{
    $d = draft($a, $items);

    $publication = app(Workspace::class)->schedule(['draft_id' => $d->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addMinute()->toIso8601String()])[0];
    $publication->update(['scheduled_at' => now()]);

    return $publication;
}

beforeEach(function (): void {
    config(['sendae.mode' => 'server']);
    $this->actingAs(User::factory()->create());
    Http::preventStrayRequests();
});

test('scheduled updates replace network content and destinations without moving existing times', function (): void {
    $this->freezeTime();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $first = workspaceAccount();
    $removed = workspaceAccount('threads');
    $added = workspaceAccount('bluesky');
    $draft = draft($first);
    $draft->update(['content' => [...$draft->content, 'account_ids' => [$first->id, $removed->id]]]);
    $original = app(Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String()]);
    $firstPost = collect($original)->firstWhere('account_id', $first->id);
    $removedPost = collect($original)->firstWhere('account_id', $removed->id);
    $draft->update(['title' => 'Updated title', 'content' => ['items' => [['text' => 'Updated shared', 'media_ids' => []]], 'overrides' => ['x' => [['text' => 'Updated X', 'media_ids' => []]]], 'account_ids' => [$first->id, $added->id]]]);
    $payload = ['draft_id' => $draft->id, 'version' => 1, 'mode' => 'preserve', 'update' => true, 'request_id' => (string) Str::uuid()];

    $this->postJson('/api/schedule', $payload)->assertOk();
    $this->postJson('/api/schedule', $payload)->assertOk();

    $this->assertSame('Updated X', $firstPost->fresh()->snapshot['items'][0]['text']);
    $this->assertSame('Updated title', $firstPost->fresh()->snapshot['title']);
    $this->assertTrue($firstPost->fresh()->scheduled_at->equalTo(now()->addDay()->startOfSecond()));
    $this->assertSame('cancelled', $removedPost->fresh()->status);
    $newPost = Publication::where('account_id', $added->id)->firstOrFail();
    $this->assertSame('Updated shared', $newPost->snapshot['items'][0]['text']);
    $this->assertTrue($newPost->scheduled_at->equalTo(now()->addDay()->startOfSecond()));
    $this->assertDatabaseCount('publications', 3);
});

test('updating can move the schedule and invalid content rolls back every destination', function (): void {
    $this->freezeTime();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $account = workspaceAccount();
    $post = scheduled($account);
    $payload = ['draft_id' => $post->draft_id, 'version' => 1, 'update' => true, 'mode' => 'exact', 'scheduled_at' => now()->addDays(2)->toIso8601String()];

    $this->postJson('/api/schedule', $payload)->assertOk();
    $this->assertTrue($post->fresh()->scheduled_at->equalTo(now()->addDays(2)->startOfSecond()));
    $this->assertDatabaseCount('publications', 1);
    $original = $post->fresh()->getAttributes();
    $draft = Draft::findOrFail($post->draft_id);
    $draft->update(['content' => [...$draft->content, 'items' => [['text' => str_repeat('a', 500), 'media_ids' => []]]]]);
    $payload['scheduled_at'] = now()->addDays(3)->toIso8601String();

    $this->postJson('/api/schedule', $payload)->assertUnprocessable();
    $this->assertSame($original, $post->fresh()->getAttributes());
});

test('started or inactive publications cannot be overwritten', function (string $status, array $receipts): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $post = scheduled(workspaceAccount());
    $post->update(['status' => $status, 'receipts' => $receipts]);
    $original = $post->fresh()->getAttributes();

    $this->postJson('/api/schedule', ['draft_id' => $post->draft_id, 'version' => 1, 'mode' => 'preserve', 'update' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->assertSame($original, $post->fresh()->getAttributes());
    $this->assertDatabaseCount('publications', 1);
})->with([
    ['publishing', []],
    ['published', []],
    ['uncertain', []],
    ['retry', ['live-item']],
    ['cancelled', []],
]);

test('schedule updates require the current version and workspace', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $post = scheduled(workspaceAccount());
    $payload = ['draft_id' => $post->draft_id, 'version' => 2, 'mode' => 'preserve', 'update' => true];
    $this->postJson('/api/schedule', $payload)->assertUnprocessable()->assertJsonValidationErrors('version');
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->postJson('/api/schedule', $payload)->assertUnprocessable()->assertJsonValidationErrors('draft_id');
});

test('credentials are encrypted and never returned in state', function (): void {
    Http::fake(['https://api.x.com/2/users/me*' => Http::response(['data' => []])]);
    $a = workspaceAccount();
    $this->assertStringNotContainsString('test-secret', $a->getRawOriginal('credentials'));
    $this->assertArrayNotHasKey('credentials', app(Workspace::class)->state()['accounts'][0]);
    $this->assertSame('test-secret', $a->fresh()->credentials['access_token']);
});

test('hosted routes require authentication', function (): void {
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/state')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->get('/api/state')->assertUnauthorized();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->getJson('/api/state')->assertOk();
});

test('upload is durable and rejects executable media', function (): void {
    Storage::fake('local');
    $m = app(Attachments::class)->store(UploadedFile::fake()->image('picture.png'));
    Storage::disk('local')->assertExists($m->path);
    $this->expectException(ValidationException::class);
    app(Attachments::class)->store(UploadedFile::fake()->createWithContent('bad.php', '<?php echo "bad";'));
});

test('schedule keeps snapshot and is idempotent', function (): void {
    $a = workspaceAccount();
    $d = draft($a);
    Queue::fake([PublishAccount::class]);
    $input = ['draft_id' => $d->id, 'version' => 1, 'mode' => 'now'];
    $p = app(Workspace::class)->schedule($input)[0];
    app(Workspace::class)->schedule($input);
    $this->assertDatabaseCount('publications', 1);
    $d->update(['content' => ['items' => [['text' => 'Changed', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$a->id]]]);
    $this->assertSame('Hello world', $p->fresh()->snapshot['items'][0]['text']);
    Queue::assertPushed(PublishAccount::class, fn (PublishAccount $job): bool => $job->accountId === $a->id);
    Queue::assertPushed(PublishAccount::class, 1);
});

test('future publications wait for the scheduler', function (): void {
    $draft = draft(workspaceAccount());
    Queue::fake([PublishAccount::class]);

    $publication = app(Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String()])[0];

    $this->assertSame('scheduled', $publication->fresh()->status);
    Queue::assertNothingPushed();
});

test('new request can publish an unchanged cancelled draft without duplicating retries', function (): void {
    Queue::fake([PublishAccount::class]);
    $draft = draft(workspaceAccount());
    $input = ['draft_id' => $draft->id, 'version' => 1, 'mode' => 'now', 'request_id' => (string) Str::uuid()];
    $first = app(Workspace::class)->schedule($input)[0];
    $first->update(['status' => 'cancelled']);
    $input['request_id'] = (string) Str::uuid();

    $second = app(Workspace::class)->schedule($input)[0];
    app(Workspace::class)->schedule($input);

    $this->assertNotSame($first->id, data_get($second, 'id'));
    $this->assertDatabaseCount('publications', 2);
    $this->assertSame('scheduled', $second->fresh()->status);
    Queue::assertPushed(PublishAccount::class, 1);
});

test('meta attachments on a local server are rejected before scheduling', function (): void {
    config(['app.url' => 'https://sendae-server.test']);
    Passport::actingAs(auth()->user(), ['*']);
    Queue::fake([PublishAccount::class]);
    $media = Media::create(['name' => 'test.png', 'mime' => 'image/png', 'size' => 16, 'path' => 'media/image']);
    foreach (['threads', 'facebook'] as $provider) {
        $draft = draft(workspaceAccount($provider), [['text' => 'Test', 'media_ids' => [$media->id]]]);
        $this->postJson('/api/schedule', ['draft_id' => $draft->id, 'version' => 1, 'mode' => 'now'])
            ->assertUnprocessable()->assertJsonValidationErrors(['media']);
    }
    $this->assertDatabaseCount('publications', 0);
    Queue::assertNothingPushed();
});

test('schedule is atomic when one network is invalid', function (): void {
    $x = workspaceAccount();
    $fb = workspaceAccount('facebook');
    $d = draft($fb, [['text' => str_repeat('a', 281), 'media_ids' => []]]);
    $content = $d->content;
    $content['account_ids'] = [$fb->id, $x->id];
    $d->update(['content' => $content]);
    Queue::fake([PublishAccount::class]);
    try {
        app(Workspace::class)->schedule(['draft_id' => $d->id, 'version' => 1, 'mode' => 'now']);
        $this->fail('Expected validation failure');
    } catch (ValidationException $e) {
    }
    $this->assertDatabaseCount('publications', 0);
    Queue::assertNothingPushed();
});

test('threads combine without silently discarding media or text', function (): void {
    $a = workspaceAccount('linkedin');
    $d = draft($a, [['text' => 'One', 'media_ids' => []], ['text' => 'Two', 'media_ids' => []]]);
    $this->assertSame([['text' => "One\n\nTwo", 'media_ids' => []]], app(Workspace::class)->items($d, $a));
    $content = $d->content;
    $content['overrides']['linkedin'] = [['text' => 'Edited combined post', 'media_ids' => []]];
    $d->update(['content' => $content]);
    $this->assertSame('Edited combined post', app(Workspace::class)->items($d, $a)[0]['text']);
});

test('slots follow timezone and skip occupied slot', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    $a = workspaceAccount();
    $a->update(['slots' => [['day' => 1, 'time' => '09:00']]]);
    $first = app(Workspace::class)->nextSlot($a);
    $this->assertSame('2026-10-26 09:00:00', $first->format('Y-m-d H:i:s'));
    $p = scheduled($a);
    $p->update(['scheduled_at' => $first]);
    $this->assertSame('2026-11-02 09:00:00', app(Workspace::class)->nextSlot($a)->format('Y-m-d H:i:s'));
    $this->travelBack();
});

test('schedule preview returns the next available slot without creating a publication', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    Passport::actingAs(auth()->user(), ['mcp:use']);
    $account = workspaceAccount();
    $draft = draft($account);

    $this->postJson('/api/schedulePreview', ['draft_id' => $draft->id, 'version' => 1])
        ->assertOk()
        ->assertJsonPath('0.account_id', $account->id)
        ->assertJsonPath('0.name', 'Test account')
        ->assertJsonPath('0.timezone', 'Europe/London')
        ->assertJsonPath('0.scheduled_at', '2026-10-26T09:00:00+00:00');

    $this->assertDatabaseCount('publications', 0);
    $this->travelBack();
});

test('schedule preview rejects an account without posting slots', function (): void {
    Passport::actingAs(auth()->user(), ['mcp:use']);
    $account = workspaceAccount();
    $account->update(['slots' => []]);
    $draft = draft($account);

    $this->postJson('/api/schedulePreview', ['draft_id' => $draft->id, 'version' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slots');

    $this->assertDatabaseCount('publications', 0);
});

test('nonexistent spring slot is skipped', function (): void {
    $this->travelTo(now()->setDate(2027, 3, 27)->setTime(12, 0));
    $a = workspaceAccount();
    $a->update(['slots' => [['day' => 0, 'time' => '01:30']]]);
    $this->assertSame('2027-04-04 00:30:00', app(Workspace::class)->nextSlot($a)->format('Y-m-d H:i:s'));
});

test('missed posts are not published', function (): void {
    $p = scheduled(workspaceAccount());
    $p->update(['scheduled_at' => now()->subHours(25)]);
    app(Publisher::class)->tick();
    $this->assertSame('missed', $p->fresh()->status);
    Http::assertNothingSent();
});

test('thread retry resumes from last confirmed item', function (): void {
    $p = scheduled(workspaceAccount(), [['text' => 'One', 'media_ids' => []], ['text' => 'Two', 'media_ids' => []]]);
    Http::fake(['api.x.com/2/tweets' => Http::sequence()->push(['data' => ['id' => '123']])->push([], 429, ['Retry-After' => '60'])->push(['data' => ['id' => '124']])]);
    app(Publisher::class)->tick();
    $this->assertSame(['123'], $p->fresh()->receipts);
    $this->assertSame('retry', $p->fresh()->status);
    $this->travel(61)->seconds();
    app(Publisher::class)->tick();
    $this->assertSame(['123', '124'], $p->fresh()->receipts);
    $this->assertSame('published', $p->fresh()->status);
    Http::assertSent(fn ($r) => ($r['reply']['in_reply_to_tweet_id'] ?? null) === '123');
    Http::assertSentCount(3);
    app(Publisher::class)->tick();
    Http::assertSentCount(3);
});

test('uncertain response never automatically retries', function (): void {
    $p = scheduled(workspaceAccount());
    Http::fake(['api.x.com/2/tweets' => Http::response([], 503)]);
    app(Publisher::class)->tick();
    $this->assertSame('uncertain', $p->fresh()->status);
    $this->travel(1)->hours();
    app(Publisher::class)->tick();
    Http::assertSentCount(1);
});

test('cancelled work stays cancelled', function (): void {
    $p = scheduled(workspaceAccount());
    app(Workspace::class)->cancel($p->id);
    app(Publisher::class)->tick();
    Http::assertNothingSent();
    $this->assertSame('cancelled', $p->fresh()->status);
});

test('successful destinations are not reposted', function (): void {
    $a = workspaceAccount();
    $b = workspaceAccount();
    $p = scheduled($a);
    $q = scheduled($b);
    Http::fake(['api.x.com/2/tweets' => Http::sequence()->push(['data' => ['id' => '111']])->push([], 429)->push(['data' => ['id' => '222']])]);
    app(Publisher::class)->tick();
    $this->travel(6)->minutes();
    app(Publisher::class)->tick();
    $this->assertSame('published', $p->fresh()->status);
    $this->assertSame('published', $q->fresh()->status);
    Http::assertSentCount(3);
});

test('recovery retains confirmed thread items', function (): void {
    $a = workspaceAccount();
    $p = scheduled($a, [['text' => 'One', 'media_ids' => []], ['text' => 'Two', 'media_ids' => []]]);
    $p->update(['status' => 'uncertain', 'receipts' => ['111']]);
    Http::fake(['api.x.com/2/tweets/222*' => Http::response(['data' => ['id' => '222', 'author_id' => $a->provider_id, 'text' => 'Two']])]);
    app(Workspace::class)->recover(['id' => $p->id, 'action' => 'confirmed', 'post_id' => '222']);
    $this->assertSame('published', $p->fresh()->status);
    $this->assertSame(['111', '222'], $p->fresh()->receipts);
});

test('analytics distinguishes zero from unavailable and stops refreshing', function (): void {
    $p = scheduled(workspaceAccount());
    $p->update(['status' => 'published', 'published_at' => now(), 'receipts' => ['123']]);
    Http::fake(['api.x.com/2/tweets/123*' => Http::response(['data' => ['public_metrics' => ['like_count' => 0, 'reply_count' => 2, 'retweet_count' => 0]]])]);
    app(Publisher::class)->refreshDueAnalytics();
    $this->assertSame(0, $p->fresh()->metrics['likes']);
    $this->assertNull($p->fresh()->metrics['impressions']);
    $this->travel(8)->days();
    app(Publisher::class)->refreshDueAnalytics();
    Http::assertSentCount(1);
    $this->travel(23)->days();
    app(Publisher::class)->refreshDueAnalytics();
    Http::assertSentCount(2);
    $this->travel(1)->days();
    app(Publisher::class)->refreshDueAnalytics();
    Http::assertSentCount(2);
});

test('stale edits overwrite the existing draft', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    config(['sendae.draft_limit' => 1]);
    $payload = ['id' => (string) Str::uuid(), 'title' => 'Shared draft', 'version' => 0, 'content' => ['items' => [['text' => 'First', 'media_ids' => []]], 'overrides' => [], 'account_ids' => []]];
    $this->postJson('/api/drafts', $payload)->assertOk()->assertJsonPath('draft.version', 1);
    $payload['content']['items'][0]['text'] = 'Stale client';
    $this->postJson('/api/drafts', $payload)->assertOk()->assertJsonPath('draft.version', 2)->assertJsonPath('draft.content.items.0.text', 'Stale client')->assertJsonPath('conflict', null);
    $this->assertDatabaseCount('drafts', 1);
});

test('mcp tools share draft operations', function (): void {
    SendaeServer::tool(SaveDraft::class, ['workspace_id' => auth()->user()->workspace_id, 'id' => (string) Str::uuid(), 'title' => 'From an agent', 'version' => 0, 'content' => ['items' => [['text' => 'Agent-created draft', 'media_ids' => []]], 'overrides' => [], 'account_ids' => []]])->assertOk();
    $this->assertDatabaseHas('drafts', ['title' => 'From an agent']);
    SendaeServer::tool(WorkspaceTool::class, [])->assertOk();
});

test('provider text adapters use real endpoints and confirm ids', function (): void {
    $cases = [
        ['threads', 'https://graph.threads.net/v1.0/*', ['id' => 'container'], ['id' => 'thread']],
        ['facebook', 'https://graph.facebook.com/*', ['id' => 'page_post'], null],
        ['linkedin', 'https://api.linkedin.com/rest/posts', null, null],
    ];
    foreach ($cases as [$provider,$url,$first,$second]) {
        $account = workspaceAccount($provider);
        if ($provider === 'threads') {
            Http::fake([$url => Http::sequence()->push($first)->push($second)]);
        } elseif ($provider === 'linkedin') {
            Http::fake([$url => Http::response([], 201, ['x-restli-id' => 'urn:li:share:123'])]);
        } else {
            Http::fake([$url => Http::response($first)]);
        }
        $id = app(SocialProviders::class)->publish($account, ['text' => 'Adapter test', 'media_ids' => []], null);
        $this->assertNotEmpty($id);
    }
});

test('x failures preserve provider details and recovery state', function (array|string $body, int $status, string $outcome, ?string $detail): void {
    $this->freezeTime();
    $publication = scheduled(workspaceAccount());
    Http::fake(['api.x.com/2/tweets' => Http::response($body, $status, ['Retry-After' => '120'])]);

    app(Publisher::class)->publish($publication->id);

    $publication->refresh();
    $this->assertSame($outcome, $publication->status);
    $guidance = $outcome === 'uncertain' ? 'Verify the post before retrying.' : (in_array($status, [401, 403]) ? 'Check account permissions or reconnect.' : 'Review the post and provider limits.');
    $this->assertSame("Provider returned HTTP $status. ".$guidance.($detail === null ? '' : ' X: '.$detail), $publication->error);
    $this->assertSame($outcome === 'retry' ? now()->addSeconds(120)->toIso8601String() : null, $publication->next_attempt_at?->toIso8601String());
    $this->assertEmpty($publication->receipts);
    Http::assertSentCount(1);
})->with([
    [['detail' => 'Invalid media.'], 400, 'failed', 'Invalid media.'],
    [['errors' => [['detail' => 'Invalid segment.']]], 400, 'failed', 'Invalid segment.'],
    [['errors' => [['message' => 'Duplicate post.']]], 403, 'failed', 'Duplicate post.'],
    [['message' => 'Upload failed.'], 400, 'failed', 'Upload failed.'],
    [['title' => 'Invalid Request'], 400, 'failed', 'Invalid Request'],
    [['detail' => ['unexpected'], 'message' => 'Bad media.'], 400, 'failed', 'Bad media.'],
    [['detail' => '<b>Invalid test-secret</b>'], 401, 'failed', 'Invalid [redacted]'],
    [['detail' => 'Rate limited.'], 429, 'retry', 'Rate limited.'],
    [['detail' => 'Unavailable.'], 503, 'uncertain', 'Unavailable.'],
    ['<html>Proxy error</html>', 400, 'failed', null],
]);

test('image uploads reach x and linkedin without discarding media', function (): void {
    Storage::fake('local');
    $m = app(Attachments::class)->store(UploadedFile::fake()->image('photo.png'));
    $x = workspaceAccount();
    Http::fake([
        'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'media1']]),
        'api.x.com/2/media/upload/media1/append' => Http::response(['data' => []]),
        'api.x.com/2/media/upload/media1/finalize' => Http::response(['data' => []]),
        'api.x.com/2/tweets' => Http::response(['data' => ['id' => 'post1']]),
    ]);
    $this->assertSame('post1', app(SocialProviders::class)->publish($x, ['text' => 'Photo', 'media_ids' => [$m->id]], null));
    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r['media']['media_ids'] === ['media1']);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/media/upload/media1/finalize' && $r->method() === 'POST' && $r->body() === '');
    $li = workspaceAccount('linkedin');
    Http::fake([
        'api.linkedin.com/rest/images?action=initializeUpload' => Http::response(['value' => ['image' => 'urn:li:image:123', 'uploadUrl' => 'https://www.linkedin.com/upload-test']]),
        'www.linkedin.com/upload-test' => Http::response([], 201),
        'api.linkedin.com/rest/images/urn%3Ali%3Aimage%3A123' => Http::response(['status' => 'AVAILABLE']),
        'api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:456']),
    ]);
    $this->assertSame('urn:li:share:456', app(SocialProviders::class)->publish($li, ['text' => 'Photo', 'media_ids' => [$m->id]], null));
    Http::assertSent(fn ($r) => $r->url() === 'https://api.linkedin.com/rest/posts' && $r['content']['media']['id'] === 'urn:li:image:123');
});

test('failed metric refresh keeps counts and success timestamp', function (): void {
    $p = scheduled(workspaceAccount());
    $p->update(['status' => 'published', 'published_at' => now()->subDay(), 'receipts' => ['123'], 'metrics' => ['likes' => 9], 'metrics_refreshed_at' => now()->subDay()]);
    $previous = $p->metrics_refreshed_at;
    Http::fake(['api.x.com/*' => Http::response([], 403)]);
    app(Publisher::class)->analytics($p);
    $this->assertSame(9, $p->fresh()->metrics['likes']);
    $this->assertTrue($previous->equalTo($p->fresh()->metrics_refreshed_at));
    $this->assertSame('refresh_failed', $p->fresh()->metrics_status);
});

test('server mcp discovery and authorization', function (): void {
    $this->app['auth']->forgetGuards();
    require base_path('routes/ai.php');
    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('scopes_supported.0', 'mcp:use');
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->assertUnauthorized()->assertHeader('WWW-Authenticate');
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->assertOk();
});

test('real bearer tokens require the workspace scope', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private);
    config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
    app(ClientRepository::class)->createPersonalAccessGrantClient('Test desktop', 'users');
    $user = User::factory()->create();
    $token = $user->createToken('Test', ['mcp:use'])->accessToken;
    $this->withToken($token)->getJson('/api/state')->assertOk();
    $this->app['auth']->forgetGuards();
    $unscoped = $user->createToken('No access', [])->accessToken;
    $this->withToken($unscoped)->getJson('/api/state')->assertForbidden();
});

test('linkedin video is uploaded finalized and attached', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('media/video', 'test-video-bytes');
    $m = Media::create(['name' => 'test.mp4', 'mime' => 'video/mp4', 'size' => 16, 'path' => 'media/video']);
    $a = workspaceAccount('linkedin');
    Http::fake([
        'api.linkedin.com/rest/videos?action=initializeUpload' => Http::response(['value' => ['video' => 'urn:li:video:123', 'uploadToken' => 'upload-token', 'uploadInstructions' => [['uploadUrl' => 'https://www.linkedin.com/video-upload', 'firstByte' => 0, 'lastByte' => 15]]]]),
        'www.linkedin.com/video-upload' => Http::response([], 200, ['ETag' => 'part1']),
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response([], 200),
        'api.linkedin.com/rest/videos/urn%3Ali%3Avideo%3A123' => Http::response(['status' => 'AVAILABLE']),
        'api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:789']),
    ]);
    $this->assertSame('urn:li:share:789', app(SocialProviders::class)->publish($a, ['text' => 'A video', 'media_ids' => [$m->id]], null));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'finalizeUpload') && $r['finalizeUploadRequest']['uploadedPartIds'] === ['part1']);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.linkedin.com/rest/posts' && $r['content']['media']['id'] === 'urn:li:video:123');
});

test('partial publication updates only queued destinations', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $live = workspaceAccount('x');
    $queued = workspaceAccount('threads');
    $failed = workspaceAccount('bluesky');
    $draft = draft($live);
    $draft->update(['content' => [...$draft->content, 'account_ids' => [$live->id, $queued->id, $failed->id]]]);
    $posts = collect(app(Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String()]));
    $livePost = $posts->firstWhere('account_id', $live->id);
    $failedPost = $posts->firstWhere('account_id', $failed->id);
    $queuedPost = $posts->firstWhere('account_id', $queued->id);
    $livePost->update(['status' => 'published', 'receipts' => ['123']]);
    $failedPost->update(['status' => 'failed']);
    $originalLive = $livePost->fresh()->getAttributes();
    $originalFailed = $failedPost->fresh()->getAttributes();
    $draft->update(['content' => [...$draft->content, 'items' => [['text' => 'New queued text', 'media_ids' => []]]]]);
    $live->update(['status' => 'expired']);

    $this->postJson('/api/schedule', ['draft_id' => $draft->id, 'version' => 1, 'mode' => 'preserve', 'update' => true])->assertOk();

    $this->assertSame('New queued text', $queuedPost->fresh()->snapshot['items'][0]['text']);
    $this->assertSame($originalLive, $livePost->fresh()->getAttributes());
    $this->assertSame($originalFailed, $failedPost->fresh()->getAttributes());
    $queuedPost->update(['status' => 'failed']);
    $this->postJson('/api/schedule', ['draft_id' => $draft->id, 'version' => 1, 'mode' => 'now'])->assertUnprocessable()->assertJsonValidationErrors('draft');
    $this->assertSame($originalLive, $livePost->fresh()->getAttributes());
    $this->assertDatabaseCount('publications', 3);
});

test('recovery verifies links and records the canonical id', function (string $link, string $id): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $account = workspaceAccount();
    $post = scheduled($account);
    $post->update(['status' => 'uncertain']);
    Http::fake(['api.x.com/2/tweets/222*' => Http::response(['data' => ['author_id' => $account->provider_id, 'text' => 'Hello world']])]);

    $this->postJson('/api/recover', ['id' => $post->id, 'action' => 'confirmed', 'post_id' => $link])->assertOk();

    $this->assertSame([$id], $post->fresh()->receipts);
    $this->assertSame('published', $post->fresh()->status);
    Http::assertSentCount(1);
})->with([
    ['https://x.com/account/status/222?s=20', '222'],
    ['https://twitter.com/i/web/status/222', '222'],
]);

test('recovery rejects wrong hosts before contacting providers', function (string $link): void {
    $post = scheduled(workspaceAccount());
    $post->update(['status' => 'uncertain']);
    try {
        app(Workspace::class)->recover(['id' => $post->id, 'action' => 'confirmed', 'post_id' => $link]);
        $this->fail('Invalid link accepted');
    } catch (ProviderFailure $e) {
        $this->assertNotEmpty($e->getMessage());
    }
    Http::assertNothingSent();
    $this->assertSame([], $post->fresh()->receipts);
    $this->assertSame('uncertain', $post->fresh()->status);
})->with([
    ['https://x.com.evil.test/account/status/222'],
    ['https://user@x.com/account/status/222'],
    ['https://x.com:444/account/status/222'],
    ['https://bsky.app/profile/other/post/222'],
]);

test('provider links resolve without fetching untrusted urls', function (string $provider, string $link, string $expected): void {
    $account = workspaceAccount($provider);
    $account->update(['provider_id' => $provider === 'bluesky' ? 'did:plc:example' : '123']);
    $post = scheduled($account);

    $this->assertSame($expected, app(SocialProviders::class)->recoveryId($post, $link));
    Http::assertNothingSent();
})->with([
    ['facebook', 'https://www.facebook.com/page/posts/222', '123_222'],
    ['facebook', 'https://www.facebook.com/permalink.php?story_fbid=222&id=123', '123_222'],
    ['linkedin', 'https://www.linkedin.com/feed/update/urn%3Ali%3Ashare%3A222/', 'urn:li:share:222'],
    ['linkedin_page', 'https://www.linkedin.com/embed/feed/update/urn:li:ugcPost:222', 'urn:li:ugcPost:222'],
    ['linkedin', '<iframe src="https://www.linkedin.com/embed/feed/update/urn:li:share:222" title="Post"></iframe>', 'urn:li:share:222'],
    ['bluesky', 'https://bsky.app/profile/did:plc:example/post/abc', 'at://did:plc:example/app.bsky.feed.post/abc'],
]);

test('bluesky handle links must resolve to the publication account', function (): void {
    $account = workspaceAccount('bluesky');
    $account->update(['provider_id' => 'did:plc:example']);
    $post = scheduled($account);
    Http::fake(['public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle*' => Http::sequence()->push(['did' => 'did:plc:example'])->push(['did' => 'did:plc:other'])]);
    $link = 'https://bsky.app/profile/example.bsky.social/post/abc';
    $this->assertSame('at://did:plc:example/app.bsky.feed.post/abc', app(SocialProviders::class)->recoveryId($post, $link));
    $this->expectException(ProviderFailure::class);
    app(SocialProviders::class)->recoveryId($post, $link);
});

test('threads links search replies and verify before recording', function (): void {
    $account = workspaceAccount('threads');
    $post = scheduled($account);
    $post->update(['status' => 'uncertain']);
    Http::fake([
        'graph.threads.net/v1.0/me/threads*' => Http::response(['data' => []]),
        'graph.threads.net/v1.0/me/replies*' => Http::response(['data' => [['id' => '222', 'shortcode' => 'Ab_C']]]),
        'graph.threads.net/v1.0/222*' => Http::response(['owner' => ['id' => $account->provider_id], 'text' => 'Hello world']),
    ]);

    app(Workspace::class)->recover(['id' => $post->id, 'action' => 'confirmed', 'post_id' => 'https://www.threads.com/@example/post/Ab_C']);

    $this->assertSame(['222'], $post->fresh()->receipts);
    $this->assertSame('published', $post->fresh()->status);
    Http::assertSentCount(3);
});
