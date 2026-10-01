<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Account;
use App\Models\Publication;
use App\Services\Publisher;
use App\Services\Workspace;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Workspace as WorkspaceModel;

const BlueskyTestBase = 'https://bsky.social/xrpc/';
const BlueskyTestDid = 'did:plc:abc123';
const BlueskyTestUri = 'at://did:plc:abc123/app.bsky.feed.post/first';

function blueskySession(): array
{
    return ['did' => BlueskyTestDid, 'handle' => 'sendae.bsky.social', 'accessJwt' => 'header.'.rtrim(strtr(base64_encode(json_encode(['exp' => now()->addHours(2)->timestamp])), '+/', '-_'), '=').'.signature', 'refreshJwt' => 'refresh-secret'];
}

function blueskyAccount(): Account
{
    test()->actingAs(User::factory()->create());

    return Account::create(['provider' => 'bluesky', 'provider_id' => BlueskyTestDid, 'name' => 'sendae.bsky.social', 'status' => 'connected', 'timezone' => 'UTC', 'slots' => [], 'credentials' => ['access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'expires_at' => now()->addHour()->toIso8601String()]]);
}

function blueskyPublication(Account $account, array $items): Publication
{
    $draft = Draft::create(['title' => 'Bluesky post', 'content' => ['items' => $items, 'overrides' => [], 'account_ids' => [$account->id]]]);
    $publication = app(Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addMinute()->toIso8601String()])[0];
    $publication->update(['scheduled_at' => now()]);

    return $publication;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('connection requires authentication scope and valid credentials', function (): void {
    $this->postJson('/api/connectBluesky')->assertUnauthorized();
    $user = User::factory()->create();
    Passport::actingAs($user, []);
    $this->postJson('/api/connectBluesky')->assertForbidden();
    Passport::actingAs($user, ['mcp:use']);
    $this->postJson('/api/connectBluesky')->assertUnprocessable()->assertJsonValidationErrors(['identifier', 'password', 'timezone']);
    Http::assertNothingSent();

    Http::fake([BlueskyTestBase.'com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired'], 401)]);
    $this->postJson('/api/connectBluesky', ['identifier' => 'sendae.bsky.social', 'password' => 'bad-password', 'timezone' => 'UTC'])->assertUnprocessable()->assertJsonPath('message', 'Bluesky could not sign in. Check your handle and app password.');
    $this->assertDatabaseCount('accounts', 0);
});

test('connection encrypts only session tokens and isolates workspaces', function (): void {
    $user = User::factory()->create();
    $other = WorkspaceModel::create(['user_id' => $user->id, 'name' => 'Work', 'icon' => 'W']);
    Passport::actingAs($user, ['mcp:use']);
    Http::fake([
        BlueskyTestBase.'com.atproto.server.createSession' => Http::response(blueskySession()),
        'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile*' => Http::response(['avatar' => 'https://cdn.bsky.app/avatar.jpg']),
    ]);
    $data = ['identifier' => '@sendae.bsky.social', 'password' => 'app-password-secret', 'timezone' => 'Europe/London'];
    foreach ([$user->workspace_id, $user->workspace_id, $other->id] as $workspaceId) {
        $this->withHeader('X-Workspace-Id', $workspaceId)->postJson('/api/connectBluesky', $data)->assertOk()->assertExactJson(['connected' => true, 'workspace_id' => $workspaceId]);
    }
    $this->assertDatabaseCount('accounts', 2);
    $account = Account::firstOrFail();
    $this->assertSame($other->id, $account->workspace_id);
    $this->assertSame('sendae.bsky.social', $account->name);
    $this->assertSame('refresh-secret', $account->credentials['refresh_token']);
    $this->assertStringNotContainsString('refresh-secret', $account->getRawOriginal('credentials'));
    $this->assertArrayNotHasKey('password', $account->credentials);
    $this->getJson('/api/state')->assertOk()->assertJsonCount(1, 'accounts')->assertJsonPath('accounts.0.avatar_url', 'https://cdn.bsky.app/avatar.jpg')->assertJsonMissingPath('accounts.0.credentials')->assertJsonPath('settings.providers.bluesky.configured', true);
    Http::assertSent(fn ($request) => $request->url() === BlueskyTestBase.'com.atproto.server.createSession' && $request['identifier'] === 'sendae.bsky.social' && $request['password'] === 'app-password-secret');
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://public.api.bsky.app/') && ! $request->hasHeader('Authorization'));
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->getJson('/api/state')->assertNotFound();
});

test('scheduled thread publishes images links and correct reply references', function (): void {
    $account = blueskyAccount();
    Storage::fake('local');
    Storage::disk('local')->put('image.png', 'image-bytes');
    $media = Media::create(['name' => 'photo.png', 'mime' => 'image/png', 'size' => 11, 'path' => 'image.png']);
    $publication = blueskyPublication($account, [['text' => 'Hello 🌍 https://example.com', 'media_ids' => [$media->id]], ['text' => 'Second', 'media_ids' => []], ['text' => 'Third', 'media_ids' => []]]);
    $second = str_replace('first', 'second', BlueskyTestUri);
    $third = str_replace('first', 'third', BlueskyTestUri);
    $root = ['uri' => BlueskyTestUri, 'cid' => 'first-cid'];
    Http::fake([
        BlueskyTestBase.'com.atproto.repo.uploadBlob' => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'image-cid'], 'mimeType' => 'image/png', 'size' => 11]]),
        BlueskyTestBase.'com.atproto.repo.createRecord' => Http::sequence()->push($root)->push(['uri' => $second, 'cid' => 'second-cid'])->push(['uri' => $third, 'cid' => 'third-cid']),
        BlueskyTestBase.'com.atproto.repo.getRecord*' => fn ($request) => Http::response($request['rkey'] === 'first' ? $root + ['value' => ['text' => 'Hello 🌍 https://example.com']] : ['uri' => $second, 'cid' => 'second-cid', 'value' => ['reply' => ['root' => $root]]]),
    ]);

    app(Publisher::class)->publish($publication->id);

    $this->assertSame('published', $publication->fresh()->status);
    $this->assertSame([BlueskyTestUri, $second, $third], $publication->fresh()->receipts);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'uploadBlob') && $request->body() === 'image-bytes' && $request->hasHeader('Content-Type', 'image/png'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'createRecord') && $request['record']['text'] === 'Hello 🌍 https://example.com' && $request['record']['facets'][0]['index'] === ['byteStart' => 11, 'byteEnd' => 30] && $request['record']['embed']['images'][0]['image']['ref']['$link'] === 'image-cid');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'createRecord') && $request['record']['text'] === 'Third' && $request['record']['reply'] === ['root' => $root, 'parent' => ['uri' => $second, 'cid' => 'second-cid']]);
    Http::assertSentCount(6);
});

test('recovery rejects another account or mismatched text', function (string $uri, string $text): void {
    $account = blueskyAccount();
    Passport::actingAs(User::findOrFail($account->user_id), ['mcp:use']);
    $publication = blueskyPublication($account, [['text' => 'Hello', 'media_ids' => []]]);
    $publication->update(['status' => 'uncertain']);
    Http::fake([BlueskyTestBase.'com.atproto.repo.getRecord*' => Http::response(['uri' => BlueskyTestUri, 'cid' => 'cid', 'value' => ['text' => $text]])]);

    $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'confirmed', 'post_id' => $uri])->assertUnprocessable();

    $this->assertSame('uncertain', $publication->fresh()->status);
    $this->assertSame([], $publication->fresh()->receipts);
    Http::assertSentCount($uri === BlueskyTestUri ? 1 : 0);
})->with([
    ['at://did:plc:someoneelse/app.bsky.feed.post/first', 'Hello'],
    ['at://did:plc:abc123/app.bsky.feed.post/first', 'Different text'],
]);

test('invalid session does not create an account', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Http::fake([BlueskyTestBase.'com.atproto.server.createSession' => Http::response(['accessJwt' => 'malformed'])]);

    $this->postJson('/api/connectBluesky', ['identifier' => 'sendae.bsky.social', 'password' => 'app-secret', 'timezone' => 'UTC'])->assertUnprocessable()->assertJsonPath('message', 'Bluesky returned an invalid or inactive session. Reconnect your account.');

    $this->assertDatabaseCount('accounts', 0);
    Http::assertSentCount(1);
});

test('expired access token is refreshed before publishing', function (): void {
    $this->freezeTime();
    $account = blueskyAccount();
    $account->update(['credentials' => [...$account->credentials, 'expires_at' => now()->subMinute()->toIso8601String()]]);
    $publication = blueskyPublication($account, [['text' => 'Hello', 'media_ids' => []]]);
    Http::fake([BlueskyTestBase.'com.atproto.server.refreshSession' => Http::response(blueskySession()), BlueskyTestBase.'com.atproto.repo.createRecord' => Http::response(['uri' => BlueskyTestUri, 'cid' => 'cid'])]);

    app(Publisher::class)->publish($publication->id);

    $this->assertSame('published', $publication->fresh()->status);
    $this->assertSame(blueskySession()['accessJwt'], $account->fresh()->credentials['access_token']);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'refreshSession') && $request->hasHeader('Authorization', 'Bearer refresh-secret') && $request->body() === '');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'createRecord') && $request->hasHeader('Authorization', 'Bearer '.blueskySession()['accessJwt']));
});

test('provider failures preserve unconfirmed outcomes', function (int $status, string $outcome): void {
    $account = blueskyAccount();
    $publication = blueskyPublication($account, [['text' => 'Hello', 'media_ids' => []]]);
    Http::fake([BlueskyTestBase.'com.atproto.repo.createRecord' => Http::response([], $status, ['Retry-After' => '120'])]);

    app(Publisher::class)->publish($publication->id);

    $this->assertSame($outcome, $publication->fresh()->status);
    $this->assertSame([], $publication->fresh()->receipts);
    Http::assertSentCount(1);
})->with([
    [429, 'retry'],
    [500, 'uncertain'],
    [200, 'uncertain'],
    [401, 'failed'],
]);

test('connection loss during publication is uncertain', function (): void {
    $account = blueskyAccount();
    $publication = blueskyPublication($account, [['text' => 'Hello', 'media_ids' => []]]);
    Http::fake([BlueskyTestBase.'com.atproto.repo.createRecord' => Http::failedConnection()]);

    app(Publisher::class)->publish($publication->id);

    $this->assertSame('uncertain', $publication->fresh()->status);
    $this->assertSame([], $publication->fresh()->receipts);
});

test('refresh failures expire only rejected authorization without publishing', function (int $status, string $error, string $accountStatus): void {
    $this->freezeTime();
    $account = blueskyAccount();
    $account->update(['credentials' => [...$account->credentials, 'expires_at' => now()->subMinute()->toIso8601String()]]);
    $publication = blueskyPublication($account, [['text' => 'Hello', 'media_ids' => []]]);
    Http::fake([BlueskyTestBase.'com.atproto.server.refreshSession' => Http::response(['error' => $error], $status)]);

    app(Publisher::class)->publish($publication->id);

    $this->assertSame('failed', $publication->fresh()->status);
    $this->assertSame($accountStatus, $account->fresh()->status);
    Http::assertSentCount(1);
})->with([
    'expired token' => [400, 'ExpiredToken', 'expired'],
    'invalid token' => [400, 'InvalidToken', 'expired'],
    'unauthorized' => [401, 'AuthenticationRequired', 'expired'],
    'forbidden' => [403, 'AccountTakedown', 'expired'],
    'invalid request' => [400, 'InvalidRequest', 'connected'],
]);

test('recovery verifies the account and text and loads metrics', function (): void {
    $account = blueskyAccount();
    $publication = blueskyPublication($account, [['text' => 'Hello', 'media_ids' => []]]);
    $publication->update(['status' => 'uncertain']);
    Http::fake([
        BlueskyTestBase.'com.atproto.repo.getRecord*' => Http::response(['uri' => BlueskyTestUri, 'cid' => 'cid', 'value' => ['text' => 'Hello']]),
        BlueskyTestBase.'app.bsky.feed.getPosts*' => Http::response(['posts' => [['uri' => BlueskyTestUri, 'likeCount' => 3, 'replyCount' => 2, 'repostCount' => 1, 'quoteCount' => 0]]]),
    ]);

    app(Workspace::class)->recover(['id' => $publication->id, 'action' => 'confirmed', 'post_id' => BlueskyTestUri]);
    app(Publisher::class)->analytics($publication->fresh());

    $this->assertSame('published', $publication->fresh()->status);
    $this->assertSame(['likes' => 3, 'replies' => 2, 'reposts' => 1, 'quotes' => 0], $publication->fresh()->metrics);
    Http::assertSentCount(2);
});

test('scheduling counts graphemes and preserves bluesky overrides', function (int $length, bool $valid): void {
    $account = blueskyAccount();
    Passport::actingAs(User::findOrFail($account->user_id), ['mcp:use']);
    $draft = Draft::create(['title' => 'Override', 'content' => ['items' => [['text' => 'Shared', 'media_ids' => []]], 'overrides' => ['bluesky' => [['text' => str_repeat('é', $length - 1)."e\u{0301}", 'media_ids' => []]]], 'account_ids' => [$account->id]]]);

    $response = $this->postJson('/api/schedule', ['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addMinute()->toIso8601String()]);

    if ($valid) {
        $response->assertOk()->assertJsonPath('0.snapshot.items.0.text', $draft->content['overrides']['bluesky'][0]['text']);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->assertDatabaseCount('publications', 0);
    }
    Http::assertNothingSent();
})->with([
    [300, true],
    [301, false],
]);

test('unsupported attachments are rejected before scheduling', function (string $mime, int $size, int $count): void {
    $account = blueskyAccount();
    Passport::actingAs(User::findOrFail($account->user_id), ['mcp:use']);
    $ids = [];
    for ($index = 0; $index < $count; $index++) {
        $ids[] = Media::create(['name' => 'attachment', 'mime' => $mime, 'size' => $size, 'path' => 'attachment'])->id;
    }
    $draft = Draft::create(['title' => 'Media', 'content' => ['items' => [['text' => 'Hello', 'media_ids' => $ids]], 'overrides' => [], 'account_ids' => [$account->id]]]);

    $this->postJson('/api/schedule', ['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addMinute()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('media');

    $this->assertDatabaseCount('publications', 0);
    Http::assertNothingSent();
})->with([
    ['video/mp4', 100, 1],
    ['image/png', 2000001, 1],
    ['image/png', 100, 5],
]);
