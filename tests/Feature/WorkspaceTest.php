<?php

namespace Tests\Feature;

use App\Mcp\Servers\SendaeServer;
use App\Mcp\Tools\SaveDraft;
use App\Mcp\Tools\WorkspaceTool;
use App\Models\Account;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Publication;
use App\Models\User;
use App\Services\Attachments;
use App\Services\Publisher;
use App\Services\SocialProviders;
use App\Services\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sendae.mode' => 'server']);
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    private function account(string $provider = 'x'): Account
    {
        return Account::create(['provider' => $provider, 'provider_id' => (string) random_int(1000, 9999), 'name' => 'Test account', 'status' => 'connected', 'timezone' => 'Europe/London', 'slots' => [['day' => 1, 'time' => '09:00']], 'credentials' => ['access_token' => 'test-secret']]);
    }

    private function draft(?Account $a = null, array $items = []): Draft
    {
        return Draft::create(['title' => 'Test draft', 'content' => ['items' => $items ?: [['text' => 'Hello world', 'media_ids' => []]], 'overrides' => [], 'account_ids' => $a ? [$a->id] : []]]);
    }

    private function scheduled(Account $a, array $items = []): Publication
    {
        $d = $this->draft($a, $items);

        return app(Workspace::class)->schedule(['draft_id' => $d->id, 'version' => 1, 'mode' => 'now'])[0];
    }

    public function test_credentials_are_encrypted_and_never_returned_in_state(): void
    {
        $a = $this->account();
        $this->assertStringNotContainsString('test-secret', $a->getRawOriginal('credentials'));
        $this->assertArrayNotHasKey('credentials', app(Workspace::class)->state()['accounts'][0]->toArray());
        $this->assertSame('test-secret', $a->fresh()->credentials['access_token']);
    }

    public function test_hosted_routes_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/state')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->get('/api/state')->assertUnauthorized();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->getJson('/api/state')->assertOk();
    }

    public function test_upload_is_durable_and_rejects_executable_media(): void
    {
        Storage::fake('local');
        $m = app(Attachments::class)->store(UploadedFile::fake()->image('picture.png'));
        Storage::disk('local')->assertExists($m->path);
        $this->expectException(ValidationException::class);
        app(Attachments::class)->store(UploadedFile::fake()->createWithContent('bad.php', '<?php echo "bad";'));
    }

    public function test_schedule_keeps_snapshot_and_is_idempotent(): void
    {
        $a = $this->account();
        $d = $this->draft($a);
        $input = ['draft_id' => $d->id, 'version' => 1, 'mode' => 'now'];
        $p = app(Workspace::class)->schedule($input)[0];
        app(Workspace::class)->schedule($input);
        $this->assertDatabaseCount('publications', 1);
        $d->update(['content' => ['items' => [['text' => 'Changed', 'media_ids' => []]], 'overrides' => [], 'account_ids' => [$a->id]]]);
        $this->assertSame('Hello world', $p->fresh()->snapshot['items'][0]['text']);
    }

    public function test_schedule_is_atomic_when_one_network_is_invalid(): void
    {
        $x = $this->account();
        $fb = $this->account('facebook');
        $d = $this->draft($fb, [['text' => str_repeat('a', 281), 'media_ids' => []]]);
        $content = $d->content;
        $content['account_ids'] = [$fb->id, $x->id];
        $d->update(['content' => $content]);
        try {
            app(Workspace::class)->schedule(['draft_id' => $d->id, 'version' => 1, 'mode' => 'now']);
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
        }
        $this->assertDatabaseCount('publications', 0);
    }

    public function test_threads_combine_without_silently_discarding_media_or_text(): void
    {
        $a = $this->account('linkedin');
        $d = $this->draft($a, [['text' => 'One', 'media_ids' => []], ['text' => 'Two', 'media_ids' => []]]);
        $this->assertSame([['text' => "One\n\nTwo", 'media_ids' => []]], app(Workspace::class)->items($d, $a));
        $content = $d->content;
        $content['overrides']['linkedin'] = [['text' => 'Edited combined post', 'media_ids' => []]];
        $d->update(['content' => $content]);
        $this->assertSame('Edited combined post', app(Workspace::class)->items($d, $a)[0]['text']);
    }

    public function test_slots_follow_timezone_and_skip_occupied_slot(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
        $a = $this->account();
        $a->update(['slots' => [['day' => 1, 'time' => '09:00']]]);
        $first = app(Workspace::class)->nextSlot($a);
        $this->assertSame('2026-10-26 09:00:00', $first->format('Y-m-d H:i:s'));
        $p = $this->scheduled($a);
        $p->update(['scheduled_at' => $first]);
        $this->assertSame('2026-11-02 09:00:00', app(Workspace::class)->nextSlot($a)->format('Y-m-d H:i:s'));
        $this->travelBack();
    }

    public function test_nonexistent_spring_slot_is_skipped(): void
    {
        $this->travelTo(now()->setDate(2027, 3, 27)->setTime(12, 0));
        $a = $this->account();
        $a->update(['slots' => [['day' => 0, 'time' => '01:30']]]);
        $this->assertSame('2027-04-04 00:30:00', app(Workspace::class)->nextSlot($a)->format('Y-m-d H:i:s'));
    }

    public function test_missed_posts_are_not_published(): void
    {
        $p = $this->scheduled($this->account());
        $p->update(['scheduled_at' => now()->subHours(25)]);
        app(Publisher::class)->tick();
        $this->assertSame('missed', $p->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_thread_retry_resumes_from_last_confirmed_item(): void
    {
        $p = $this->scheduled($this->account(), [['text' => 'One', 'media_ids' => []], ['text' => 'Two', 'media_ids' => []]]);
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
    }

    public function test_uncertain_response_never_automatically_retries(): void
    {
        $p = $this->scheduled($this->account());
        Http::fake(['api.x.com/2/tweets' => Http::response([], 503)]);
        app(Publisher::class)->tick();
        $this->assertSame('uncertain', $p->fresh()->status);
        $this->travel(1)->hours();
        app(Publisher::class)->tick();
        Http::assertSentCount(1);
    }

    public function test_cancelled_work_stays_cancelled(): void
    {
        $p = $this->scheduled($this->account());
        app(Workspace::class)->cancel($p->id);
        app(Publisher::class)->tick();
        Http::assertNothingSent();
        $this->assertSame('cancelled', $p->fresh()->status);
    }

    public function test_successful_destinations_are_not_reposted(): void
    {
        $a = $this->account();
        $b = $this->account();
        $p = $this->scheduled($a);
        $q = $this->scheduled($b);
        Http::fake(['api.x.com/2/tweets' => Http::sequence()->push(['data' => ['id' => '111']])->push([], 429)->push(['data' => ['id' => '222']])]);
        app(Publisher::class)->tick();
        $this->travel(6)->minutes();
        app(Publisher::class)->tick();
        $this->assertSame('published', $p->fresh()->status);
        $this->assertSame('published', $q->fresh()->status);
        Http::assertSentCount(3);
    }

    public function test_recovery_retains_confirmed_thread_items(): void
    {
        $a = $this->account();
        $p = $this->scheduled($a, [['text' => 'One', 'media_ids' => []], ['text' => 'Two', 'media_ids' => []]]);
        $p->update(['status' => 'uncertain', 'receipts' => ['111']]);
        Http::fake(['api.x.com/2/tweets/222*' => Http::response(['data' => ['id' => '222', 'author_id' => $a->provider_id, 'text' => 'Two']])]);
        app(Workspace::class)->recover(['id' => $p->id, 'action' => 'confirmed', 'post_id' => '222']);
        $this->assertSame('published', $p->fresh()->status);
        $this->assertSame(['111', '222'], $p->fresh()->receipts);
    }

    public function test_analytics_distinguishes_zero_from_unavailable_and_stops_refreshing(): void
    {
        $p = $this->scheduled($this->account());
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
    }

    public function test_mcp_tools_share_draft_operations(): void
    {
        SendaeServer::tool(SaveDraft::class, ['id' => (string) Str::uuid(), 'title' => 'From an agent', 'version' => 0, 'content' => ['items' => [['text' => 'Agent-created draft', 'media_ids' => []]], 'overrides' => [], 'account_ids' => []]])->assertOk();
        $this->assertDatabaseHas('drafts', ['title' => 'From an agent']);
        SendaeServer::tool(WorkspaceTool::class, [])->assertOk();
    }

    public function test_provider_text_adapters_use_real_endpoints_and_confirm_ids(): void
    {
        $cases = [
            ['threads', 'https://graph.threads.net/v1.0/*', ['id' => 'container'], ['id' => 'thread']],
            ['facebook', 'https://graph.facebook.com/*', ['id' => 'page_post'], null],
            ['linkedin', 'https://api.linkedin.com/rest/posts', null, null],
        ];
        foreach ($cases as [$provider,$url,$first,$second]) {
            $account = $this->account($provider);
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
    }

    public function test_image_uploads_reach_x_and_linkedin_without_discarding_media(): void
    {
        Storage::fake('local');
        $m = app(Attachments::class)->store(UploadedFile::fake()->image('photo.png'));
        $x = $this->account();
        Http::fake([
            'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'media1']]),
            'api.x.com/2/media/upload/media1/append' => Http::response(['data' => []]),
            'api.x.com/2/media/upload/media1/finalize' => Http::response(['data' => []]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => 'post1']]),
        ]);
        $this->assertSame('post1', app(SocialProviders::class)->publish($x, ['text' => 'Photo', 'media_ids' => [$m->id]], null));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r['media']['media_ids'] === ['media1']);
        $li = $this->account('linkedin');
        Http::fake([
            'api.linkedin.com/rest/images?action=initializeUpload' => Http::response(['value' => ['image' => 'urn:li:image:123', 'uploadUrl' => 'https://www.linkedin.com/upload-test']]),
            'www.linkedin.com/upload-test' => Http::response([], 201),
            'api.linkedin.com/rest/images/urn%3Ali%3Aimage%3A123' => Http::response(['status' => 'AVAILABLE']),
            'api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:456']),
        ]);
        $this->assertSame('urn:li:share:456', app(SocialProviders::class)->publish($li, ['text' => 'Photo', 'media_ids' => [$m->id]], null));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.linkedin.com/rest/posts' && $r['content']['media']['id'] === 'urn:li:image:123');
    }

    public function test_failed_metric_refresh_keeps_counts_and_success_timestamp(): void
    {
        $p = $this->scheduled($this->account());
        $p->update(['status' => 'published', 'published_at' => now()->subDay(), 'receipts' => ['123'], 'metrics' => ['likes' => 9], 'metrics_refreshed_at' => now()->subDay()]);
        $previous = $p->metrics_refreshed_at;
        Http::fake(['api.x.com/*' => Http::response([], 403)]);
        app(Publisher::class)->analytics($p);
        $this->assertSame(9, $p->fresh()->metrics['likes']);
        $this->assertTrue($previous->equalTo($p->fresh()->metrics_refreshed_at));
        $this->assertSame('refresh_failed', $p->fresh()->metrics_status);
    }

    public function test_server_mcp_discovery_and_authorization(): void
    {
        $this->app['auth']->forgetGuards();
        require base_path('routes/ai.php');
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('scopes_supported.0', 'mcp:use');
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->assertUnauthorized()->assertHeader('WWW-Authenticate');
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]])->assertOk();
    }

    public function test_real_bearer_tokens_require_the_workspace_scope(): void
    {
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
    }

    public function test_linkedin_video_is_uploaded_finalized_and_attached(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/video', 'test-video-bytes');
        $m = Media::create(['name' => 'test.mp4', 'mime' => 'video/mp4', 'size' => 16, 'path' => 'media/video']);
        $a = $this->account('linkedin');
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
    }
}
