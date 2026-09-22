<?php

use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\ClientRepository;

function client(): Client
{
    return clientWith(['https://client.example/callback']);
}

/**
 * @param  list<string>  $redirects
 */
function clientWith(array $redirects): Client
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $private);
    config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);

    return app(ClientRepository::class)->createAuthorizationCodeGrantClient('Test MCP client', $redirects, false);
}

function ticket(Client $client, string $redirect = 'https://client.example/callback'): string
{
    $response = test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->id, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'client-state',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
    ]))->assertOk();
    test()->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $response->assertSee('Open Sendae', false)->assertSee('window.location.href', false)->assertSee('sendae://authorize?ticket=', false);
    preg_match('/sendae:\/\/authorize\?ticket=([A-Za-z0-9]+)/', $response->getContent(), $parameters);
    test()->assertNotEmpty($parameters[1] ?? null);

    return $parameters[1];
}

test('desktop consent completes pkce and is single use', function (): void {
    $client = client();
    $ticket = ticket($client);
    $this->getJson('/api/authorizations/'.$ticket)->assertUnauthorized();
    $user = User::factory()->unverified()->create();
    Passport::actingAs($user, ['mcp:use']);
    $this->postJson('/api/authorizations/'.$ticket, ['approved' => true])->assertForbidden();
    $this->getJson('/api/authorizations/'.$ticket)->assertOk()->assertJsonPath('client', 'Test MCP client')->assertJsonPath('scopes', ['mcp:use']);
    $url = $this->postJson('/api/authorizations/'.$ticket, ['approved' => true])->assertOk()->json('redirect_url');
    parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
    $this->assertSame('client-state', $parameters['state']);
    $this->assertDatabaseHas('oauth_auth_codes', ['user_id' => $user->id, 'client_id' => $client->id]);
    $this->postJson('/api/authorizations/'.$ticket, ['approved' => true])->assertNotFound();
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'redirect_uri' => 'https://client.example/callback', 'code' => $parameters['code'], 'code_verifier' => str_repeat('v', 64)])->assertOk()->assertJsonPath('token_type', 'Bearer');
});

test('consent is bound to the reviewing user and denial issues no code', function (): void {
    $ticket = ticket(client());
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);
    $this->getJson('/api/authorizations/'.$ticket)->assertOk();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->getJson('/api/authorizations/'.$ticket)->assertNotFound();
    $this->postJson('/api/authorizations/'.$ticket, ['approved' => true])->assertNotFound();
    Passport::actingAs($user, ['mcp:use']);
    $url = $this->postJson('/api/authorizations/'.$ticket, ['approved' => false])->assertOk()->json('redirect_url');
    parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
    $this->assertSame('access_denied', $parameters['error']);
    $this->assertSame('client-state', $parameters['state']);
    $this->assertDatabaseCount('oauth_auth_codes', 0);
});

test('revoked client cannot be approved after review', function (): void {
    $client = client();
    $ticket = ticket($client);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->getJson('/api/authorizations/'.$ticket)->assertOk();
    $client->update(['revoked' => true]);
    $this->postJson('/api/authorizations/'.$ticket, ['approved' => true])->assertNotFound();
    $this->assertDatabaseCount('oauth_auth_codes', 0);
});

test('invalid and expired authorizations fail', function (): void {
    $client = client();
    $this->get('/oauth/authorize?'.http_build_query(['client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => 'https://evil.example/callback']))->assertUnauthorized();
    $ticket = ticket($client);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->travel(11)->minutes();
    $this->getJson('/api/authorizations/'.$ticket)->assertNotFound();
    $this->assertDatabaseCount('oauth_auth_codes', 0);
});

test('localhost callback ignores the port and returns an authorization code', function (): void {
    $redirect = 'http://localhost:4312/callback';
    $client = clientWith(['http://localhost/callback']);
    $ticket = ticket($client, $redirect);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->getJson('/api/authorizations/'.$ticket)->assertOk();
    $url = $this->postJson('/api/authorizations/'.$ticket, ['approved' => true])->assertOk()->json('redirect_url');
    parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
    $this->assertSame('client-state', $parameters['state']);
    $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'redirect_uri' => $redirect, 'code' => $parameters['code'], 'code_verifier' => str_repeat('v', 64)])->assertOk()->assertJsonPath('token_type', 'Bearer');
});

test('localhost callback rejects a different path', function (): void {
    $client = clientWith(['http://localhost/callback']);
    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->id, 'redirect_uri' => 'http://localhost:4312/other', 'response_type' => 'code', 'scope' => 'mcp:use',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
    ]))->assertUnauthorized();
    $this->assertDatabaseCount('oauth_auth_codes', 0);
});
