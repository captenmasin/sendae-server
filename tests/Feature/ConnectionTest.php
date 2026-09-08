<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'sendae.mode' => 'server',
            'sendae.providers.x' => ['label' => 'X', 'client_id' => 'x-id', 'client_secret' => 'x-secret'],
            'sendae.providers.threads' => ['label' => 'Threads', 'client_id' => 'threads-id', 'client_secret' => 'threads-secret'],
            'sendae.providers.facebook' => ['label' => 'Facebook Page', 'client_id' => 'fb-id', 'client_secret' => 'fb-secret'],
            'sendae.providers.linkedin' => ['label' => 'LinkedIn profile', 'client_id' => 'li-id', 'client_secret' => 'li-secret'],
            'sendae.providers.linkedin_page' => ['label' => 'LinkedIn Company Page', 'client_id' => 'li-id', 'client_secret' => 'li-secret', 'approved' => false],
        ]);
        Http::preventStrayRequests();
    }

    public function test_connect_ticket_returns_401_when_no_token_is_provided(): void
    {
        $this->postJson('/api/connect', ['provider' => 'x'])->assertUnauthorized();
    }

    public function test_connect_ticket_rejects_unknown_and_unconfigured_providers(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->postJson('/api/connect', ['provider' => 'tiktok'])->assertUnprocessable();
        config(['sendae.providers.x' => ['label' => 'X', 'client_id' => null, 'client_secret' => null]]);
        $this->postJson('/api/connect', ['provider' => 'x'])->assertStatus(422)->assertJsonPath('message', 'Add this provider’s developer app credentials to the hosted server first. See the deployment guide.');
        $this->postJson('/api/connect', ['provider' => 'linkedin_page'])->assertStatus(422)->assertJsonPath('message', 'LinkedIn Company Page access is awaiting approval.');
    }

    public function test_desktop_ticket_starts_oauth_for_the_original_workspace(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        $second = Workspace::create(['user_id' => $user->id, 'name' => 'Novogamer', 'icon' => '★']);

        $url = $this->withHeader('X-Workspace-Id', $second->id)->postJson('/api/connect', ['provider' => 'x'])->assertOk()->json('url');
        $this->assertMatchesRegularExpression('#/connections/[A-Za-z0-9]{64}$#', $url);

        $this->app['auth']->forgetGuards();
        $response = $this->flushHeaders()->get(parse_url($url, PHP_URL_PATH).'?workspace_id='.$user->workspace_id);
        $response->assertRedirect();
        $this->assertStringStartsWith('https://x.com/i/oauth2/authorize?', $response->headers->get('Location'));
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
        $this->assertSame($second->id, session('social_oauth.workspace_id'));
        $this->assertSame($user->id, session('social_oauth.user_id'));
        $this->get(parse_url($url, PHP_URL_PATH))->assertForbidden();
    }

    public function test_connection_ticket_forbids_a_different_signed_in_account(): void
    {
        $owner = User::factory()->create();
        Passport::actingAs($owner, ['mcp:use']);
        $url = $this->postJson('/api/connect', ['provider' => 'x'])->assertOk()->json('url');

        $this->actingAs(User::factory()->create(), 'web')->flushHeaders()->get(parse_url($url, PHP_URL_PATH))->assertForbidden();
        $this->assertSame(0, Account::withoutGlobalScopes()->count());
    }

    public function test_facebook_connection_requests_access_to_business_owned_pages(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $url = $this->postJson('/api/connect', ['provider' => 'facebook'])->assertOk()->json('url');
        $this->app['auth']->forgetGuards();

        $response = $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('www.facebook.com', parse_url($response->headers->get('Location'), PHP_URL_HOST));
        $this->assertEqualsCanonicalizing(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights', 'business_management'], explode(',', $parameters['scope']));
    }

    public function test_expired_connection_ticket_returns_403(): void
    {
        $this->get('/connections/'.str_repeat('a', 64))->assertForbidden();
    }

    public function test_website_cannot_start_provider_oauth(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->get('/connect/x')->assertNotFound();
        $this->actingAs($user, 'web')->get('/connect/tiktok')->assertNotFound();
    }

    public function test_oauth_callback_returns_to_desktop_and_api_keeps_credentials_private(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 7200]),
            'api.x.com/2/users/me' => Http::response(['data' => ['id' => '42', 'name' => "<script>alert('xss')</script>"]]),
        ]);

        $response = $this->withSession([
            'social_oauth' => ['workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'state' => 'oauth-state', 'verifier' => 'pkce-verifier', 'provider' => 'x', 'started' => time()],
        ])->get('/oauth/x/callback?state=oauth-state&code=auth-code')->assertRedirect()->assertContent('');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $link);
        $this->assertStringStartsWith('sendae://connection?', $response->headers->get('Location'));
        Passport::actingAs($user, ['mcp:use']);
        $this->getJson('/api/connections/'.$link['ticket'])->assertOk()->assertJsonPath('accounts.0.name', "<script>alert('xss')</script>")->assertJsonMissingPath('accounts.0.credentials');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.x.com/2/oauth2/token' && $request['code'] === 'auth-code');
    }

    public function test_selection_saves_to_the_original_workspace_and_is_single_use(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        $second = Workspace::create(['user_id' => $user->id, 'name' => 'Novogamer', 'icon' => '★']);
        $ticket = str_repeat('a', 64);
        Cache::put('social_choices:'.$ticket, ['workspace_id' => $second->id, 'user_id' => $user->id, 'provider' => 'x', 'accounts' => [['provider_id' => '42', 'name' => 'Novogamer', 'credentials' => ['access_token' => 'token']]]], now()->addMinutes(10));

        $this->postJson('/api/connections/'.$ticket, ['accounts' => [99], 'timezone' => 'UTC'])->assertUnprocessable();
        $this->assertDatabaseCount('accounts', 0);
        $this->postJson('/api/connections/'.$ticket, ['accounts' => [0], 'timezone' => 'Europe/London'])->assertOk()->assertJsonPath('connected', true);
        $this->assertDatabaseHas('accounts', ['workspace_id' => $second->id, 'name' => 'Novogamer', 'provider_id' => '42']);
        $this->postJson('/api/connections/'.$ticket, ['accounts' => [0], 'timezone' => 'UTC'])->assertNotFound();
    }

    public function test_selection_forbids_other_accounts_and_expired_tickets(): void
    {
        $user = User::factory()->create();
        $ticket = str_repeat('b', 64);
        Cache::put('social_choices:'.$ticket, ['workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'provider' => 'x', 'accounts' => [['provider_id' => '42', 'name' => 'Sitepulse', 'credentials' => ['access_token' => 'token']]]], now()->addMinutes(10));
        $this->getJson('/api/connections/'.$ticket)->assertUnauthorized();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->getJson('/api/connections/'.$ticket)->assertNotFound();
        $this->postJson('/api/connections/'.$ticket, ['accounts' => [0], 'timezone' => 'UTC'])->assertNotFound();
        Passport::actingAs($user, ['mcp:use']);
        $this->travel(11)->minutes();
        $this->getJson('/api/connections/'.$ticket)->assertNotFound();
        $this->assertDatabaseCount('accounts', 0);
    }
}
