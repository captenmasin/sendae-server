<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
        $response = $this->flushHeaders()->get(parse_url($url, PHP_URL_PATH));
        $response->assertRedirect();
        $this->assertStringStartsWith('https://x.com/i/oauth2/authorize?', $response->headers->get('Location'));
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame($user->id, Auth::guard('web')->id());
        $this->assertSame($second->id, session('social_oauth.workspace_id'));
        $this->assertSame($user->id, session('social_oauth.user_id'));
        $this->assertTrue(session('social_desktop'));
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

    public function test_expired_connection_ticket_returns_403(): void
    {
        $this->get('/connections/'.str_repeat('a', 64))->assertForbidden();
    }

    public function test_signed_in_website_starts_provider_oauth(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->get('/connect/x')->assertRedirect();
        $this->assertStringStartsWith('https://x.com/i/oauth2/authorize?', $this->actingAs($user, 'web')->get('/connect/x')->headers->get('Location'));
        $this->actingAs($user, 'web')->get('/connect/tiktok')->assertNotFound();
    }

    public function test_oauth_callback_lists_accounts_and_escapes_their_names(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 7200]),
            'api.x.com/2/users/me' => Http::response(['data' => ['id' => '42', 'name' => "<script>alert('xss')</script>"]]),
        ]);

        $this->withSession([
            'social_oauth' => ['workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'state' => 'oauth-state', 'verifier' => 'pkce-verifier', 'provider' => 'x', 'started' => time()],
        ])->get('/oauth/x/callback?state=oauth-state&code=auth-code')->assertOk()->assertSee('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', false)->assertDontSee("<script>alert('xss')</script>", false);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.x.com/2/oauth2/token' && $request['code'] === 'auth-code');
    }

    public function test_desktop_selection_redirects_home_after_saving_the_workspace_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $second = Workspace::create(['user_id' => $user->id, 'name' => 'Novogamer', 'icon' => '★']);
        $this->withSession([
            'social_desktop' => true,
            'social_choices' => ['workspace_id' => $second->id, 'user_id' => $user->id, 'provider' => 'x', 'accounts' => [['provider_id' => '42', 'name' => 'Novogamer', 'credentials' => ['access_token' => 'token']]], 'expires' => time() + 600],
        ])->post('/connections/select', ['accounts' => [0], 'timezone' => 'Europe/London'])->assertRedirect('/connected');
        $this->assertDatabaseHas('accounts', ['workspace_id' => $second->id, 'name' => 'Novogamer', 'provider_id' => '42']);
        $this->get('/connected')->assertOk()->assertSee('Account connected.');
    }

    public function test_website_selection_redirects_to_the_workspace(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->withSession([
            'social_choices' => ['workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'provider' => 'x', 'accounts' => [['provider_id' => '42', 'name' => 'Sitepulse', 'credentials' => ['access_token' => 'token']]], 'expires' => time() + 600],
        ])->post('/connections/select', ['accounts' => [0], 'timezone' => 'UTC'])->assertRedirect('/');
        $this->assertDatabaseHas('accounts', ['workspace_id' => $user->workspace_id, 'name' => 'Sitepulse']);
    }
}
