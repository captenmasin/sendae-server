<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Publication;
use App\Models\User;
use App\Models\Workspace as WorkspaceModel;
use App\Services\Publisher;
use App\Services\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BlueskyTest extends TestCase
{
    use RefreshDatabase;

    private const Base = 'https://bsky.social/xrpc/';

    private const Did = 'did:plc:abc123';

    private const Uri = 'at://did:plc:abc123/app.bsky.feed.post/first';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function blueskySession(): array
    {
        return ['did' => self::Did, 'handle' => 'sendae.bsky.social', 'accessJwt' => 'header.'.rtrim(strtr(base64_encode(json_encode(['exp' => now()->addHours(2)->timestamp])), '+/', '-_'), '=').'.signature', 'refreshJwt' => 'refresh-secret'];
    }

    private function account(): Account
    {
        $this->actingAs(User::factory()->create());

        return Account::create(['provider' => 'bluesky', 'provider_id' => self::Did, 'name' => 'sendae.bsky.social', 'status' => 'connected', 'timezone' => 'UTC', 'slots' => [], 'credentials' => ['access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'expires_at' => now()->addHour()->toIso8601String()]]);
    }

    private function publication(Account $account, array $items): Publication
    {
        $draft = Draft::create(['title' => 'Bluesky post', 'content' => ['items' => $items, 'overrides' => [], 'account_ids' => [$account->id]]]);
        $publication = app(Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addMinute()->toIso8601String()])[0];
        $publication->update(['scheduled_at' => now()]);

        return $publication;
    }

    public function test_connection_requires_authentication_scope_and_valid_credentials(): void
    {
        $this->postJson('/api/connectBluesky')->assertUnauthorized();
        $user = User::factory()->create();
        Passport::actingAs($user, []);
        $this->postJson('/api/connectBluesky')->assertForbidden();
        Passport::actingAs($user, ['mcp:use']);
        $this->postJson('/api/connectBluesky')->assertUnprocessable()->assertJsonValidationErrors(['identifier', 'password', 'timezone']);
        Http::assertNothingSent();

        Http::fake([self::Base.'com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired'], 401)]);
        $this->postJson('/api/connectBluesky', ['identifier' => 'sendae.bsky.social', 'password' => 'bad-password', 'timezone' => 'UTC'])->assertUnprocessable()->assertJsonPath('message', 'Bluesky could not sign in. Check your handle and app password.');
        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_connection_encrypts_only_session_tokens_and_isolates_workspaces(): void
    {
        $user = User::factory()->create();
        $other = WorkspaceModel::create(['user_id' => $user->id, 'name' => 'Work', 'icon' => 'W']);
        Passport::actingAs($user, ['mcp:use']);
        Http::fake([
            self::Base.'com.atproto.server.createSession' => Http::response($this->blueskySession()),
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
        Http::assertSent(fn ($request) => $request->url() === self::Base.'com.atproto.server.createSession' && $request['identifier'] === 'sendae.bsky.social' && $request['password'] === 'app-password-secret');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://public.api.bsky.app/') && ! $request->hasHeader('Authorization'));
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->getJson('/api/state')->assertNotFound();
    }

    public function test_scheduled_thread_publishes_images_links_and_correct_reply_references(): void
    {
        $account = $this->account();
        Storage::fake('local');
        Storage::disk('local')->put('image.png', 'image-bytes');
        $media = Media::create(['name' => 'photo.png', 'mime' => 'image/png', 'size' => 11, 'path' => 'image.png']);
        $publication = $this->publication($account, [['text' => 'Hello 🌍 https://example.com', 'media_ids' => [$media->id]], ['text' => 'Second', 'media_ids' => []], ['text' => 'Third', 'media_ids' => []]]);
        $second = str_replace('first', 'second', self::Uri);
        $third = str_replace('first', 'third', self::Uri);
        $root = ['uri' => self::Uri, 'cid' => 'first-cid'];
        Http::fake([
            self::Base.'com.atproto.repo.uploadBlob' => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'image-cid'], 'mimeType' => 'image/png', 'size' => 11]]),
            self::Base.'com.atproto.repo.createRecord' => Http::sequence()->push($root)->push(['uri' => $second, 'cid' => 'second-cid'])->push(['uri' => $third, 'cid' => 'third-cid']),
            self::Base.'com.atproto.repo.getRecord*' => fn ($request) => Http::response($request['rkey'] === 'first' ? $root + ['value' => ['text' => 'Hello 🌍 https://example.com']] : ['uri' => $second, 'cid' => 'second-cid', 'value' => ['reply' => ['root' => $root]]]),
        ]);

        app(Publisher::class)->publish($publication->id);

        $this->assertSame('published', $publication->fresh()->status);
        $this->assertSame([self::Uri, $second, $third], $publication->fresh()->receipts);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'uploadBlob') && $request->body() === 'image-bytes' && $request->hasHeader('Content-Type', 'image/png'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'createRecord') && $request['record']['text'] === 'Hello 🌍 https://example.com' && $request['record']['facets'][0]['index'] === ['byteStart' => 11, 'byteEnd' => 30] && $request['record']['embed']['images'][0]['image']['ref']['$link'] === 'image-cid');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'createRecord') && $request['record']['text'] === 'Third' && $request['record']['reply'] === ['root' => $root, 'parent' => ['uri' => $second, 'cid' => 'second-cid']]);
        Http::assertSentCount(6);
    }

    #[TestWith(['at://did:plc:someoneelse/app.bsky.feed.post/first', 'Hello'])]
    #[TestWith(['at://did:plc:abc123/app.bsky.feed.post/first', 'Different text'])]
    public function test_recovery_rejects_another_account_or_mismatched_text(string $uri, string $text): void
    {
        $account = $this->account();
        Passport::actingAs(User::findOrFail($account->user_id), ['mcp:use']);
        $publication = $this->publication($account, [['text' => 'Hello', 'media_ids' => []]]);
        $publication->update(['status' => 'uncertain']);
        Http::fake([self::Base.'com.atproto.repo.getRecord*' => Http::response(['uri' => self::Uri, 'cid' => 'cid', 'value' => ['text' => $text]])]);

        $this->postJson('/api/recover', ['id' => $publication->id, 'action' => 'confirmed', 'post_id' => $uri])->assertUnprocessable();

        $this->assertSame('uncertain', $publication->fresh()->status);
        $this->assertSame([], $publication->fresh()->receipts);
        Http::assertSentCount($uri === self::Uri ? 1 : 0);
    }

    public function test_invalid_session_does_not_create_an_account(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        Http::fake([self::Base.'com.atproto.server.createSession' => Http::response(['accessJwt' => 'malformed'])]);

        $this->postJson('/api/connectBluesky', ['identifier' => 'sendae.bsky.social', 'password' => 'app-secret', 'timezone' => 'UTC'])->assertUnprocessable()->assertJsonPath('message', 'Bluesky returned an invalid or inactive session. Reconnect your account.');

        $this->assertDatabaseCount('accounts', 0);
        Http::assertSentCount(1);
    }

    public function test_expired_access_token_is_refreshed_before_publishing(): void
    {
        $this->freezeTime();
        $account = $this->account();
        $account->update(['credentials' => [...$account->credentials, 'expires_at' => now()->subMinute()->toIso8601String()]]);
        $publication = $this->publication($account, [['text' => 'Hello', 'media_ids' => []]]);
        Http::fake([self::Base.'com.atproto.server.refreshSession' => Http::response($this->blueskySession()), self::Base.'com.atproto.repo.createRecord' => Http::response(['uri' => self::Uri, 'cid' => 'cid'])]);

        app(Publisher::class)->publish($publication->id);

        $this->assertSame('published', $publication->fresh()->status);
        $this->assertSame($this->blueskySession()['accessJwt'], $account->fresh()->credentials['access_token']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'refreshSession') && $request->hasHeader('Authorization', 'Bearer refresh-secret'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), 'createRecord') && $request->hasHeader('Authorization', 'Bearer '.$this->blueskySession()['accessJwt']));
    }

    #[TestWith([429, 'retry'])]
    #[TestWith([500, 'uncertain'])]
    #[TestWith([200, 'uncertain'])]
    #[TestWith([401, 'failed'])]
    public function test_provider_failures_preserve_unconfirmed_outcomes(int $status, string $outcome): void
    {
        $account = $this->account();
        $publication = $this->publication($account, [['text' => 'Hello', 'media_ids' => []]]);
        Http::fake([self::Base.'com.atproto.repo.createRecord' => Http::response([], $status, ['Retry-After' => '120'])]);

        app(Publisher::class)->publish($publication->id);

        $this->assertSame($outcome, $publication->fresh()->status);
        $this->assertSame([], $publication->fresh()->receipts);
        Http::assertSentCount(1);
    }

    public function test_connection_loss_during_publication_is_uncertain(): void
    {
        $account = $this->account();
        $publication = $this->publication($account, [['text' => 'Hello', 'media_ids' => []]]);
        Http::fake([self::Base.'com.atproto.repo.createRecord' => Http::failedConnection()]);

        app(Publisher::class)->publish($publication->id);

        $this->assertSame('uncertain', $publication->fresh()->status);
        $this->assertSame([], $publication->fresh()->receipts);
    }

    public function test_revoked_refresh_token_requires_reconnection_without_publishing(): void
    {
        $account = $this->account();
        $account->update(['credentials' => [...$account->credentials, 'expires_at' => now()->subMinute()->toIso8601String()]]);
        $publication = $this->publication($account, [['text' => 'Hello', 'media_ids' => []]]);
        Http::fake([self::Base.'com.atproto.server.refreshSession' => Http::response([], 401)]);

        app(Publisher::class)->publish($publication->id);

        $this->assertSame('failed', $publication->fresh()->status);
        $this->assertSame('expired', $account->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_recovery_verifies_the_account_and_text_and_loads_metrics(): void
    {
        $account = $this->account();
        $publication = $this->publication($account, [['text' => 'Hello', 'media_ids' => []]]);
        $publication->update(['status' => 'uncertain']);
        Http::fake([
            self::Base.'com.atproto.repo.getRecord*' => Http::response(['uri' => self::Uri, 'cid' => 'cid', 'value' => ['text' => 'Hello']]),
            self::Base.'app.bsky.feed.getPosts*' => Http::response(['posts' => [['uri' => self::Uri, 'likeCount' => 3, 'replyCount' => 2, 'repostCount' => 1, 'quoteCount' => 0]]]),
        ]);

        app(Workspace::class)->recover(['id' => $publication->id, 'action' => 'confirmed', 'post_id' => self::Uri]);
        app(Publisher::class)->analytics($publication->fresh());

        $this->assertSame('published', $publication->fresh()->status);
        $this->assertSame(['likes' => 3, 'replies' => 2, 'reposts' => 1, 'quotes' => 0], $publication->fresh()->metrics);
        Http::assertSentCount(2);
    }

    #[TestWith([300, true])]
    #[TestWith([301, false])]
    public function test_scheduling_counts_graphemes_and_preserves_bluesky_overrides(int $length, bool $valid): void
    {
        $account = $this->account();
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
    }

    #[TestWith(['video/mp4', 100, 1])]
    #[TestWith(['image/png', 2000001, 1])]
    #[TestWith(['image/png', 100, 5])]
    public function test_unsupported_attachments_are_rejected_before_scheduling(string $mime, int $size, int $count): void
    {
        $account = $this->account();
        Passport::actingAs(User::findOrFail($account->user_id), ['mcp:use']);
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = Media::create(['name' => 'attachment', 'mime' => $mime, 'size' => $size, 'path' => 'attachment'])->id;
        }
        $draft = Draft::create(['title' => 'Media', 'content' => ['items' => [['text' => 'Hello', 'media_ids' => $ids]], 'overrides' => [], 'account_ids' => [$account->id]]]);

        $this->postJson('/api/schedule', ['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addMinute()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('media');

        $this->assertDatabaseCount('publications', 0);
        Http::assertNothingSent();
    }
}
