<?php

use App\Models\User;
use App\Models\Account;
use App\Models\Workspace;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config([
        'sendae.mode' => 'server',
        'sendae.providers.x' => ['label' => 'X', 'client_id' => 'x-id', 'client_secret' => 'x-secret'],
        'sendae.providers.threads' => ['label' => 'Threads', 'client_id' => 'threads-id', 'client_secret' => 'threads-secret'],
        'sendae.providers.facebook' => ['label' => 'Facebook Page', 'client_id' => 'fb-id', 'client_secret' => 'fb-secret'],
        'sendae.providers.linkedin' => ['label' => 'LinkedIn profile', 'client_id' => 'li-id', 'client_secret' => 'li-secret'],
        'sendae.providers.linkedin_page' => ['label' => 'LinkedIn Company Page', 'client_id' => 'li-page-id', 'client_secret' => 'li-page-secret', 'approved' => false],
    ]);
    Http::preventStrayRequests();
});

test('connect ticket returns 401 when no token is provided', function (): void {
    $this->postJson('/api/connect', ['provider' => 'x'])->assertUnauthorized();
});

test('connect ticket rejects unknown and unconfigured providers', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->postJson('/api/connect', ['provider' => 'tiktok'])->assertUnprocessable();
    config(['sendae.providers.x' => ['label' => 'X', 'client_id' => null, 'client_secret' => null]]);
    $this->postJson('/api/connect', ['provider' => 'x'])->assertStatus(422)->assertJsonPath('message', 'Add this provider’s developer app credentials to the hosted server first. See the deployment guide.');
    $this->postJson('/api/connect', ['provider' => 'linkedin_page'])->assertStatus(422)->assertJsonPath('message', 'LinkedIn Company Page access is awaiting approval.');
});

test('unverified account can start oauth for the original workspace', function (): void {
    $user = User::factory()->unverified()->create();
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
});

test('connection ticket forbids a different signed in account', function (): void {
    $owner = User::factory()->create();
    Passport::actingAs($owner, ['mcp:use']);
    $url = $this->postJson('/api/connect', ['provider' => 'x'])->assertOk()->json('url');

    $this->actingAs(User::factory()->create(), 'web')->flushHeaders()->get(parse_url($url, PHP_URL_PATH))->assertForbidden();
    $this->assertSame(0, Account::withoutGlobalScopes()->count());
});

test('facebook connection requests only the reviewed page permissions', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $url = $this->postJson('/api/connect', ['provider' => 'facebook'])->assertOk()->json('url');
    $this->app['auth']->forgetGuards();

    $response = $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect();

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
    $this->assertSame('www.facebook.com', parse_url($response->headers->get('Location'), PHP_URL_HOST));
    $this->assertEqualsCanonicalizing(['pages_show_list', 'pages_read_engagement', 'pages_read_user_content', 'pages_manage_posts'], explode(',', $parameters['scope']));
});

test('expired connection ticket returns 403', function (): void {
    $this->get('/connections/'.str_repeat('a', 64))->assertForbidden();
});

test('linkedin connections use their own app and scopes', function (string $provider, string $clientId, string $scope): void {
    config(['sendae.providers.linkedin_page.approved' => true]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $url = $this->postJson('/api/connect', ['provider' => $provider])->assertOk()->json('url');
    $this->app['auth']->forgetGuards();

    $response = $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect();

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
    $this->assertSame('www.linkedin.com', parse_url($response->headers->get('Location'), PHP_URL_HOST));
    $this->assertSame($clientId, $parameters['client_id']);
    $this->assertSame($scope, $parameters['scope']);
    $this->assertSame('/oauth/'.$provider.'/callback', parse_url($parameters['redirect_uri'], PHP_URL_PATH));
})->with([
    ['linkedin', 'li-id', 'openid profile w_member_social'],
    ['linkedin_page', 'li-page-id', 'w_organization_social rw_organization_admin r_organization_social'],
]);

test('linkedin page callback uses page credentials and lists publishable organizations', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'page-access', 'expires_in' => 5184000]),
        'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [
            ['organization' => 'urn:li:organization:42', 'role' => 'ADMINISTRATOR'],
            ['organization' => 'urn:li:organization:43', 'role' => 'ANALYST'],
        ]]),
        'api.linkedin.com/rest/organizations/42' => Http::response(['localizedName' => 'Sendae']),
    ]);

    $response = $this->withSession([
        'social_oauth' => ['workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'state' => 'oauth-state', 'provider' => 'linkedin_page', 'started' => time()],
    ])->get('/oauth/linkedin_page/callback?state=oauth-state&code=auth-code')->assertRedirect();

    Http::assertSent(fn ($request) => $request->url() === 'https://www.linkedin.com/oauth/v2/accessToken'
        && $request['client_id'] === 'li-page-id' && $request['client_secret'] === 'li-page-secret');
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $link);
    Passport::actingAs($user, ['mcp:use']);
    $this->getJson('/api/connections/'.$link['ticket'])->assertOk()->assertExactJson([
        'provider' => 'linkedin_page', 'workspace_id' => $user->workspace_id,
        'accounts' => [['provider_id' => 'urn:li:organization:42', 'name' => 'Sendae']],
    ]);
});

test('website cannot start provider oauth', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'web')->get('/connect/x')->assertNotFound();
    $this->actingAs($user, 'web')->get('/connect/tiktok')->assertNotFound();
});

test('oauth callback returns to desktop and api keeps credentials private', function (): void {
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
});

test('selection saves to the original workspace and is single use', function (): void {
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
});

test('selection forbids other accounts and expired tickets', function (): void {
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
});
