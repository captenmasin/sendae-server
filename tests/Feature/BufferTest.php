<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Account;
use App\Models\Publication;
use App\Services\Publisher;
use App\Services\Workspace;
use App\Jobs\PublishAccount;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use App\Models\Workspace as WorkspaceModel;
use Illuminate\Validation\ValidationException;

function bufferAccount(): Account
{
    test()->actingAs(User::factory()->create());

    return Account::create(['provider' => 'linkedin_page', 'provider_id' => 'buffer:page-a', 'name' => 'Page A', 'status' => 'connected', 'timezone' => 'UTC', 'slots' => [], 'credentials' => ['transport' => 'buffer', 'channel_id' => 'page-a', 'access_token' => 'buffer-secret']]);
}

function bufferPublication(Account $account, array $media = []): Publication
{
    $draft = Draft::create(['title' => 'Page post', 'content' => ['items' => [['text' => 'Hello', 'media_ids' => $media, 'media_alt' => array_fill_keys($media, 'Image description')]], 'overrides' => [], 'account_ids' => [$account->id]]]);
    $publication = app(Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addMinute()->toIso8601String()])[0];
    $publication->update(['scheduled_at' => now()]);

    return $publication;
}

function bufferPost(string $status = 'sent'): array
{
    return ['id' => 'post-1', 'channelId' => 'page-a', 'status' => $status, 'text' => 'Hello', 'externalLink' => $status === 'sent' ? 'https://www.linkedin.com/feed/update/urn:li:activity:123' : null];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['sendae.mode' => 'server', 'app.url' => 'https://service.example']);
});

test('buffer connection requires authentication scope and a key', function (): void {
    $this->postJson('/api/connectBuffer')->assertUnauthorized();
    $user = User::factory()->create();
    Passport::actingAs($user, []);
    $this->postJson('/api/connectBuffer')->assertForbidden();
    Passport::actingAs($user, ['mcp:use']);
    $this->postJson('/api/connectBuffer')->assertUnprocessable()->assertJsonValidationErrors(['api_key' => 'The api key field is required.']);
    Http::assertNothingSent();
});

test('one buffer key connects different Pages to their original workspaces without exposing credentials', function (): void {
    $user = User::factory()->create();
    $other = WorkspaceModel::create(['user_id' => $user->id, 'name' => 'Business B', 'icon' => 'B']);
    Passport::actingAs($user, ['mcp:use']);
    Http::fake(['api.buffer.com' => function ($request) {
        expect($request->hasHeader('Authorization', 'Bearer buffer-secret'))->toBeTrue();
        if (str_contains($request['query'], 'organizations')) {
            return Http::response(['data' => ['account' => ['organizations' => [['id' => 'org-1']]]]]);
        }
        expect($request['variables']['input']['organizationId'])->toBe('org-1');
        $channel = ['service' => 'linkedin', 'type' => 'page', 'name' => 'Page', 'displayName' => null, 'avatar' => 'https://images.example/avatar.jpg', 'isDisconnected' => false, 'isLocked' => false, 'allowedActions' => ['scheduleUpdates']];

        return Http::response(['data' => ['channels' => [
            ['id' => 'page-a', 'displayName' => 'Page A'] + $channel,
            ['id' => 'page-b', 'displayName' => 'Page B'] + $channel,
            ['id' => 'profile', 'type' => 'profile'] + $channel,
            ['id' => 'facebook', 'service' => 'facebook'] + $channel,
            ['id' => 'locked', 'isLocked' => true] + $channel,
            ['id' => 'expired', 'isDisconnected' => true] + $channel,
            ['id' => 'reader', 'allowedActions' => []] + $channel,
        ]]]);
    }]);
    $first = $this->postJson('/api/connectBuffer', ['api_key' => 'buffer-secret'])->assertOk()->json('ticket');
    $second = $this->withHeader('X-Workspace-Id', $other->id)->postJson('/api/connectBuffer', ['api_key' => 'buffer-secret'])->assertOk()->json('ticket');
    expect(json_encode(Cache::get('social_choices:'.$first)))->not->toContain('buffer-secret');
    $this->getJson('/api/connections/'.$first)->assertJsonCount(2, 'accounts')->assertJsonPath('workspace_id', $user->workspace_id)->assertDontSee('buffer-secret');
    $this->postJson('/api/connections/'.$first, ['accounts' => [9], 'timezone' => 'UTC'])->assertUnprocessable();
    $this->postJson('/api/connections/'.$first, ['accounts' => [0], 'timezone' => 'UTC'])->assertOk()->assertJsonPath('workspace_id', $user->workspace_id);
    $this->postJson('/api/connections/'.$second, ['accounts' => [1], 'timezone' => 'Europe/London'])->assertOk()->assertJsonPath('workspace_id', $other->id);
    $this->getJson('/api/state')->assertJsonCount(1, 'accounts')->assertJsonPath('accounts.0.name', 'Page B')->assertJsonMissingPath('accounts.0.credentials')->assertJsonPath('settings.providers.linkedin_page.connection', 'buffer')->assertJsonPath('settings.providers.linkedin_page.approved', true);
    $this->withHeader('X-Workspace-Id', $user->workspace_id)->getJson('/api/state')->assertJsonCount(1, 'accounts')->assertJsonPath('accounts.0.name', 'Page A');
    $account = Account::withoutGlobalScopes()->where('provider_id', 'buffer:page-a')->sole();
    expect($account->credentials['access_token'])->toBe('buffer-secret');
    expect($account->getRawOriginal('credentials'))->not->toContain('buffer-secret');
    $this->getJson('/api/connections/'.$first)->assertNotFound();
    Http::assertSentCount(4);
});

test('buffer selection cannot be used by another user or after expiry', function (): void {
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);
    Http::fake(['api.buffer.com' => Http::sequence()->push(['data' => ['account' => ['organizations' => [['id' => 'org']]]]])->push(['data' => ['channels' => [['id' => 'page-a', 'service' => 'linkedin', 'type' => 'page', 'name' => 'Page A', 'displayName' => null, 'isDisconnected' => false, 'isLocked' => false, 'allowedActions' => ['scheduleUpdates']]]]])]);
    $ticket = $this->postJson('/api/connectBuffer', ['api_key' => 'secret'])->assertOk()->json('ticket');
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->getJson('/api/connections/'.$ticket)->assertNotFound();
    $this->postJson('/api/connections/'.$ticket, ['accounts' => [0], 'timezone' => 'UTC'])->assertNotFound();
    Passport::actingAs($user, ['mcp:use']);
    $this->travel(11)->minutes();
    $this->getJson('/api/connections/'.$ticket)->assertNotFound();
    $this->assertDatabaseCount('accounts', 0);
    Http::assertSentCount(2);
});

test('buffer reports invalid keys and missing Pages without creating accounts', function (int $status, array $body): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Http::fake(['api.buffer.com' => Http::response($body, $status)]);

    $this->postJson('/api/connectBuffer', ['api_key' => 'secret'])->assertUnprocessable()->assertDontSee('Bearer secret');

    $this->assertDatabaseCount('accounts', 0);
    Http::assertSentCount(1);
})->with([
    'invalid key' => [401, []],
    'graphql error' => [200, ['errors' => [['message' => 'secret']]]],
    'no organizations' => [200, ['data' => ['account' => ['organizations' => []]]]],
]);

test('buffer publishes images with descriptions and waits for delivery without resubmitting', function (): void {
    $this->freezeTime();
    $account = bufferAccount();
    Storage::fake('local');
    Storage::disk('local')->put('photo.png', 'image');
    $media = Media::create(['name' => 'photo.png', 'mime' => 'image/png', 'size' => 5, 'path' => 'photo.png']);
    $publication = bufferPublication($account, [$media->id]);
    Http::fake(['api.buffer.com' => Http::sequence()->push(['data' => ['createPost' => ['__typename' => 'PostActionSuccess', 'post' => bufferPost('scheduled')]]])->push(['data' => ['post' => bufferPost('sending')]])->push(['data' => ['post' => bufferPost()]])]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe('publishing');
    expect($publication->fresh()->receipts)->toBe([]);
    expect($publication->fresh()->snapshot['buffer_post_id'])->toBe('post-1');
    Http::assertSent(function ($request) use ($media): bool {
        $input = $request['variables']['input'];
        expect($input)->toMatchArray(['channelId' => 'page-a', 'mode' => 'shareNow', 'schedulingType' => 'automatic', 'text' => 'Hello']);
        expect($input['assets'][0]['image']['metadata']['altText'])->toBe('Image description');
        expect($input['assets'][0]['image']['url'])->toContain('/media/'.$media->id, 'signature=');

        return $request->hasHeader('Authorization', 'Bearer buffer-secret');
    });
    app(Publisher::class)->publish($publication->id);
    Http::assertSentCount(1);
    $this->travel(2)->minutes();
    app(Publisher::class)->publishAccount($account->id);
    expect($publication->fresh()->status)->toBe('publishing');
    $this->travel(2)->minutes();
    app(Publisher::class)->publishAccount($account->id);
    expect($publication->fresh()->status)->toBe('published');
    expect($publication->fresh()->receipts)->toBe(['buffer:post-1']);
    expect($publication->fresh()->snapshot['post_urls']['buffer:post-1'])->toBe(bufferPost()['externalLink']);
    expect(app(Publisher::class)->analytics($publication->fresh())->metrics_status)->toBe('unavailable');
    Http::assertSentCount(3);
});

test('pending buffer posts survive worker sweeps but cannot be cancelled or disconnected', function (): void {
    $account = bufferAccount();
    $publication = bufferPublication($account);
    $publication->update(['status' => 'publishing', 'snapshot' => [...$publication->snapshot, 'buffer_post_id' => 'post-1'], 'updated_at' => now()->subMinutes(20)]);
    Queue::fake([PublishAccount::class]);
    Passport::actingAs(User::findOrFail($account->user_id), ['mcp:use']);

    app(Publisher::class)->tick();

    expect($publication->fresh()->status)->toBe('publishing');
    Queue::assertPushed(PublishAccount::class, fn ($job) => $job->accountId === $account->id);
    $this->postJson('/api/cancel', ['id' => $publication->id])->assertUnprocessable();
    $this->postJson('/api/disconnect', ['id' => $account->id])->assertUnprocessable();
    expect($account->fresh()->status)->toBe('connected');
    Http::assertNothingSent();
});

test('buffer creation failures preserve safe retry and uncertain outcomes', function (int $status, array $body, string $expected): void {
    $publication = bufferPublication(bufferAccount());
    Http::fake(['api.buffer.com' => $status === 0 ? Http::failedConnection() : Http::response($body, $status, ['Retry-After' => '120'])]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe($expected);
    expect($publication->fresh()->receipts)->toBe([]);
    if ($expected === 'uncertain') {
        app(Publisher::class)->publish($publication->id);
    }
    Http::assertSentCount(1);
})->with([
    'revoked key' => [401, [], 'failed'],
    'rate limit' => [429, [], 'retry'],
    'server failure' => [503, [], 'uncertain'],
    'connection loss' => [0, [], 'uncertain'],
    'graphql errors' => [200, ['errors' => [['message' => 'unknown']]], 'uncertain'],
    'missing ID' => [200, ['data' => ['createPost' => ['__typename' => 'PostActionSuccess', 'post' => ['status' => 'sent']]]], 'uncertain'],
    'validation' => [200, ['data' => ['createPost' => ['__typename' => 'InvalidInputError', 'message' => 'Invalid image']]], 'failed'],
    'unexpected mutation failure' => [200, ['data' => ['createPost' => ['__typename' => 'UnexpectedError', 'message' => 'Unknown']]], 'uncertain'],
]);

test('temporary status lookup errors keep the accepted buffer post in flight', function (): void {
    $this->freezeTime();
    $publication = bufferPublication(bufferAccount());
    $publication->update(['status' => 'publishing', 'snapshot' => [...$publication->snapshot, 'buffer_post_id' => 'post-1']]);
    Http::fake(['api.buffer.com' => Http::response([], 503)]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe('publishing');
    expect($publication->fresh()->next_attempt_at->isFuture())->toBeTrue();
    Http::assertSent(fn ($request) => ! str_contains($request['query'], 'mutation') && $request['variables']['input']['id'] === 'post-1');
});

test('buffer delivery failures allow rescheduling and redact credentials', function (): void {
    $publication = bufferPublication(bufferAccount());
    $publication->update(['status' => 'publishing', 'snapshot' => [...$publication->snapshot, 'buffer_post_id' => 'post-1']]);
    Http::fake(['api.buffer.com' => Http::response(['data' => ['post' => [...bufferPost('error'), 'error' => ['message' => 'Token buffer-secret expired']]]])]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe('failed');
    expect($publication->fresh()->error)->toBe('Buffer: Token [redacted] expired');
    expect($publication->fresh()->snapshot)->not->toHaveKey('buffer_post_id');
    Http::assertSentCount(1);
});

test('buffer recovery verifies Page text and delivery and refuses requeueing an accepted post', function (): void {
    $account = bufferAccount();
    Passport::actingAs(User::findOrFail($account->user_id), ['mcp:use']);
    $publication = bufferPublication($account);
    $publication->update(['status' => 'uncertain', 'snapshot' => [...$publication->snapshot, 'buffer_post_id' => 'post-1']]);
    Http::fake(['api.buffer.com' => Http::sequence()->push(['data' => ['post' => bufferPost('sending')]])->push(['data' => ['post' => [...bufferPost(), 'channelId' => 'other-page']]])->push(['data' => ['post' => [...bufferPost(), 'text' => 'Wrong text']]])->push(['data' => ['post' => bufferPost()]])]);

    $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'not_published', 'scheduled_at' => now()->addHour()->toIso8601String()])->assertUnprocessable();
    $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'confirmed', 'post_id' => 'post-1'])->assertUnprocessable();
    $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'confirmed', 'post_id' => 'post-1'])->assertUnprocessable();
    $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'confirmed', 'post_id' => 'post-1'])->assertOk()->assertJsonPath('status', 'published');

    expect($publication->fresh()->receipts)->toBe(['buffer:post-1']);
    expect($publication->fresh()->snapshot['post_urls']['buffer:post-1'])->toBe(bufferPost()['externalLink']);
    Http::assertSentCount(4);
});

test('buffer media cannot be scheduled from a local publishing server', function (): void {
    $account = bufferAccount();
    $media = Media::create(['name' => 'photo.png', 'mime' => 'image/png', 'size' => 5, 'path' => 'photo.png']);
    config(['app.url' => 'https://sendae-server.test']);

    expect(fn () => bufferPublication($account, [$media->id]))->toThrow(ValidationException::class, 'Buffer needs a public HTTPS');

    $this->assertDatabaseCount('publications', 0);
    Http::assertNothingSent();
});

test('buffer sends text videos and GIFs to the selected Page', function (?string $mime, ?string $assetType): void {
    $account = bufferAccount();
    Storage::fake('local');
    $media = null;
    if ($mime) {
        Storage::disk('local')->put('attachment', 'content');
        $media = Media::create(['name' => 'attachment', 'mime' => $mime, 'size' => 7, 'path' => 'attachment']);
    }
    $publication = bufferPublication($account, $media ? [$media->id] : []);
    Http::fake(['api.buffer.com' => Http::response(['data' => ['createPost' => ['__typename' => 'PostActionSuccess', 'post' => bufferPost()]]])]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe('published');
    Http::assertSent(function ($request) use ($assetType): bool {
        $assets = $request['variables']['input']['assets'];
        expect($assetType ? array_keys($assets[0]) : $assets)->toBe($assetType ? [$assetType] : []);

        return $request['variables']['input']['channelId'] === 'page-a';
    });
    Http::assertSentCount(1);
})->with(['text' => [null, null], 'video' => ['video/mp4', 'video'], 'GIF' => ['image/gif', 'image']]);

test('buffer rejects oversized images and mixed GIF attachments before scheduling', function (int $size, bool $mixed): void {
    $account = bufferAccount();
    $media = Media::create(['name' => 'image.gif', 'mime' => 'image/gif', 'size' => $size, 'path' => 'image.gif']);
    $ids = [$media->id];
    if ($mixed) {
        $ids[] = Media::create(['name' => 'image.png', 'mime' => 'image/png', 'size' => 5, 'path' => 'image.png'])->id;
    }

    expect(fn () => bufferPublication($account, $ids))->toThrow(ValidationException::class, 'Buffer supports LinkedIn images up to 10 MB');

    $this->assertDatabaseCount('publications', 0);
    Http::assertNothingSent();
})->with([[10 * 1024 * 1024 + 1, false], [5, true]]);

test('buffer pending delivery becomes uncertain after 24 hours without creating another post', function (): void {
    $this->freezeTime();
    $publication = bufferPublication(bufferAccount());
    $publication->update(['status' => 'publishing', 'snapshot' => [...$publication->snapshot, 'buffer_post_id' => 'post-1'], 'scheduled_at' => now()->subHours(25)]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe('uncertain');
    Http::assertNothingSent();
});

test('buffer unexpected delivery states remain uncertain', function (array $post): void {
    $publication = bufferPublication(bufferAccount());
    $publication->update(['status' => 'publishing', 'snapshot' => [...$publication->snapshot, 'buffer_post_id' => 'post-1']]);
    Http::fake(['api.buffer.com' => Http::response(['data' => ['post' => $post]])]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe('uncertain');
    expect($publication->fresh()->receipts)->toBe([]);
    Http::assertSentCount(1);
})->with(['wrong Page' => [[...bufferPost(), 'channelId' => 'other-page']], 'draft' => [bufferPost('draft')]]);
