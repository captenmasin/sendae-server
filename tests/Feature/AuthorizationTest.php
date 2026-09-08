<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);

        return app(ClientRepository::class)->createAuthorizationCodeGrantClient('Test MCP client', ['https://client.example/callback'], false);
    }

    private function ticket(Client $client): string
    {
        $response = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'https://client.example/callback', 'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'client-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]))->assertRedirect()->assertContent('');
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('sendae://authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);

        return $parameters['ticket'];
    }

    public function test_desktop_consent_completes_pkce_and_is_single_use(): void
    {
        $client = $this->client();
        $ticket = $this->ticket($client);
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
    }

    public function test_consent_is_bound_to_the_reviewing_user_and_denial_issues_no_code(): void
    {
        $ticket = $this->ticket($this->client());
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
    }

    public function test_revoked_client_cannot_be_approved_after_review(): void
    {
        $client = $this->client();
        $ticket = $this->ticket($client);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->getJson('/api/authorizations/'.$ticket)->assertOk();
        $client->update(['revoked' => true]);
        $this->postJson('/api/authorizations/'.$ticket, ['approved' => true])->assertNotFound();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_invalid_and_expired_authorizations_fail(): void
    {
        $client = $this->client();
        $this->get('/oauth/authorize?'.http_build_query(['client_id' => $client->id, 'response_type' => 'code', 'redirect_uri' => 'https://evil.example/callback']))->assertUnauthorized();
        $ticket = $this->ticket($client);
        Passport::actingAs(User::factory()->create(), ['mcp:use']);
        $this->travel(11)->minutes();
        $this->getJson('/api/authorizations/'.$ticket)->assertNotFound();
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }
}
