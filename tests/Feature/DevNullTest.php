<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Account;
use phpseclib4\Crypt\EC;
use App\Services\DevNull;
use App\Models\Publication;
use App\Services\Publisher;
use App\Services\Workspace;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use App\Services\ProviderFailure;
use App\Services\SocialProviders;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use phpseclib4\Common\Functions\Strings;
use App\Models\Workspace as WorkspaceModel;
use Illuminate\Validation\ValidationException;

function devNullAccount(): Account
{
    test()->actingAs(User::factory()->create());

    return Account::create(['provider' => 'devnull', 'provider_id' => '42', 'name' => '@sendae', 'status' => 'connected', 'timezone' => 'UTC', 'slots' => [], 'credentials' => ['private_key' => EC::createKey('Ed25519')->toString('PKCS8'), 'handle' => 'sendae']]);
}

function devNullPublication(Account $account, array $items): Publication
{
    $draft = Draft::create(['title' => 'Dev null post', 'content' => ['items' => $items, 'overrides' => [], 'account_ids' => [$account->id]]]);
    $publication = app(Workspace::class)->schedule(['draft_id' => $draft->id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addMinute()->toIso8601String()])[0];
    $publication->update(['scheduled_at' => now()]);

    return $publication;
}

function assertDevNullSignature(Request $request, string $body, array $images = []): string
{
    $blob = base64_decode($request->header('X-SSH-Signature')[0], true);
    expect(substr($blob, 0, 6))->toBe('SSHSIG');
    $fields = substr($blob, 6);
    [$version, $public, $namespace, $reserved, $algorithm, $signature] = Strings::unpackSSH2('Nsssss', $fields);
    expect([$version, $namespace, $reserved, $algorithm, $fields])->toBe([1, $images ? 'devnull-api-images' : 'devnull-api', '', 'sha512', '']);
    $timestamp = $request->header('X-Devnull-Timestamp')[0];
    $nonce = $request->header('X-Devnull-Nonce')[0];
    expect($timestamp)->toBe((string) now()->timestamp);
    expect($nonce)->toMatch('/^[a-f0-9]{32}$/');
    $message = implode("\n", [$timestamp, $nonce, $request->method(), parse_url($request->url(), PHP_URL_PATH), ...($images ? [implode(',', array_map(fn (string $image): string => hash('sha256', $image), $images))] : []), $body]);
    $signed = 'SSHSIG'.Strings::packSSH2('ssss', $namespace, '', 'sha512', hash('sha512', $message, true));
    $key = EC::loadPublicKey('ssh-ed25519 '.base64_encode($public))->withSignatureFormat('SSH2');
    expect($key->verify($signed, $signature))->toBeTrue();
    expect($key->verify($signed.'tampered', $signature))->toBeFalse();

    return 'SHA256:'.rtrim(base64_encode(hash('sha256', $public, true)), '=');
}

beforeEach(function (): void {
    config(['sendae.mode' => 'server']);
    Http::preventStrayRequests();
});

test('devnull connection requires authentication scope and a loopback callback', function (): void {
    $this->postJson('/api/connect', ['provider' => 'devnull'])->assertUnauthorized();
    $this->postJson('/api/connectDevNull')->assertUnauthorized();
    $user = User::factory()->create();
    Passport::actingAs($user, []);
    $this->postJson('/api/connectDevNull')->assertForbidden();
    Passport::actingAs($user, ['mcp:use']);
    foreach (['https://evil.example/callback', 'http://127.0.0.1.evil.example:5000/devnull/callback', 'http://127.0.0.1:99999/devnull/callback', 'http://user@127.0.0.1:5000/devnull/callback'] as $uri) {
        $this->postJson('/api/connect', ['provider' => 'devnull', 'redirect_uri' => $uri])->assertUnprocessable();
    }
    $this->postJson('/api/connectDevNull')->assertUnprocessable()->assertJsonValidationErrors(['ticket', 'code']);
    Http::assertNothingSent();
});

test('devnull authorization binds the signing key and verifier to the original owner and workspace', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $other = WorkspaceModel::create(['user_id' => $user->id, 'name' => 'Work', 'icon' => 'W']);
    Passport::actingAs($user, ['mcp:use']);
    $start = $this->withHeader('X-Workspace-Id', $other->id)->postJson('/api/connect', ['provider' => 'devnull', 'redirect_uri' => 'http://127.0.0.1:54321/devnull/callback'])->assertOk()->json();
    parse_str(parse_url($start['url'], PHP_URL_QUERY), $parameters);
    expect(parse_url($start['url'], PHP_URL_HOST))->toBe('dev-null.sh');
    expect($parameters)->toMatchArray(['state' => $start['ticket'], 'device_name' => 'Sendae', 'code_challenge_method' => 'S256', 'redirect_uri' => 'http://127.0.0.1:54321/devnull/callback']);
    $stored = Cache::get('devnull_connect:'.$start['ticket']);
    expect($stored)->not->toContain('PRIVATE KEY');
    $pending = json_decode(Crypt::decryptString($stored), true);
    expect($parameters['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='));
    Http::fake(['dev-null.sh/api/v1/devices' => function ($request) use ($parameters, $pending) {
        expect(assertDevNullSignature($request, $request->body()))->toBe($parameters['fingerprint']);
        expect(json_decode($request->body(), true))->toBe(['code' => 'approval-code', 'code_verifier' => $pending['verifier']]);

        return Http::response(['data' => ['id' => 42, 'handle' => 'sendae', 'avatar_url' => 'https://images.example/avatar.png']]);
    }]);
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->flushHeaders()->postJson('/api/connectDevNull', ['ticket' => $start['ticket'], 'code' => 'stolen-code'])->assertNotFound();
    Http::assertNothingSent();
    Passport::actingAs($user, ['mcp:use']);
    $ticket = $this->postJson('/api/connectDevNull', ['ticket' => $start['ticket'], 'code' => 'approval-code'])->assertOk()->json('ticket');
    expect(Cache::get('social_choices:'.$ticket)['accounts'][0]['credentials'])->not->toContain('PRIVATE KEY');
    $this->getJson('/api/connections/'.$ticket)->assertExactJson(['provider' => 'devnull', 'workspace_id' => $other->id, 'accounts' => [['name' => '@sendae', 'provider_id' => '42']]]);
    $this->postJson('/api/connections/'.$ticket, ['accounts' => [0], 'timezone' => 'Europe/London'])->assertOk()->assertJsonPath('workspace_id', $other->id);
    $this->assertDatabaseHas('accounts', ['provider' => 'devnull', 'provider_id' => '42', 'user_id' => $user->id, 'workspace_id' => $other->id]);
    $account = Account::withoutGlobalScopes()->sole();
    expect($account->credentials['private_key'])->toBe($pending['private_key']);
    expect($account->getRawOriginal('credentials'))->not->toContain('PRIVATE KEY');
    $this->withHeader('X-Workspace-Id', $other->id)->getJson('/api/state')->assertJsonPath('settings.providers.devnull.configured', true)->assertJsonPath('accounts.0.avatar_url', 'https://images.example/avatar.png')->assertJsonMissingPath('accounts.0.credentials');
    $this->postJson('/api/connectDevNull', ['ticket' => $start['ticket'], 'code' => 'approval-code'])->assertNotFound();
    Http::assertSentCount(1);
});

test('expired devnull connections never redeem an approval code', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $ticket = app(DevNull::class)->start('http://127.0.0.1:5000/devnull/callback')['ticket'];
    $this->travel(11)->minutes();
    $this->postJson('/api/connectDevNull', ['ticket' => $ticket, 'code' => 'approval-code'])->assertNotFound();
    Http::assertNothingSent();
});

test('devnull scheduled threads sign image bytes descriptions and reply references', function (): void {
    $this->freezeTime();
    $account = devNullAccount();
    Storage::fake('local');
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    Storage::disk('local')->put('image.png', $image);
    $media = Media::create(['name' => 'image.png', 'mime' => 'image/png', 'size' => strlen($image), 'path' => 'image.png']);
    $publication = devNullPublication($account, [['text' => 'Hello 🌍', 'media_ids' => [$media->id], 'media_alt' => [$media->id => 'A small image']], ['text' => 'Reply', 'media_ids' => []]]);
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    $calls = [];
    Http::fake(['dev-null.sh/api/v1/commits' => function ($request) use (&$calls, $image, $first, $second) {
        if (! $calls) {
            $parts = $request->data();
            expect(array_column($parts, 'name'))->toBe(['payload', 'images[]']);
            $body = $parts[0]['contents'];
            expect($parts[1]['contents'])->toBe($image);
            expect(json_decode($body, true))->toBe(['body' => 'Hello 🌍', 'image_descriptions' => ['A small image']]);
            assertDevNullSignature($request, $body, [$image]);
        } else {
            expect(json_decode($request->body(), true))->toBe(['body' => 'Reply', 'parent_id' => $first]);
            assertDevNullSignature($request, $request->body());
        }
        $calls[] = $request->header('X-Devnull-Nonce')[0];

        return Http::response(['data' => ['id' => count($calls) === 1 ? $first : $second, 'status' => 'pending']], 201);
    }]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe('published');
    expect($publication->fresh()->receipts)->toBe([$first, $second]);
    expect(array_unique($calls))->toHaveCount(2);
    expect(app(Workspace::class)->state()['publications'][0]->snapshot['post_urls'][$first])->toBe('https://dev-null.sh/@sendae/commits/'.$first);
    app(Workspace::class)->disconnectAccount(['id' => $account->id]);
    expect(app(Workspace::class)->state()['publications'][0]->snapshot['post_urls'][$first])->toBe('https://dev-null.sh/@sendae/commits/'.$first);
    Http::assertSentCount(2);
});

test('devnull publication failures preserve safe retry and recovery states', function (int $status, array $body, string $expected): void {
    $account = devNullAccount();
    $publication = devNullPublication($account, [['text' => 'Hello', 'media_ids' => []]]);
    Http::fake(['dev-null.sh/api/v1/commits' => $status === 0 ? Http::failedConnection() : Http::response($body, $status, ['Retry-After' => '120'])]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe($expected);
    expect($publication->fresh()->receipts ?? [])->toBe([]);
    if ($status === 401) {
        expect($account->fresh()->status)->toBe('expired');
    }
    if ($expected === 'uncertain') {
        app(Publisher::class)->publish($publication->id);
    }
    Http::assertSentCount(1);
})->with([
    'revoked device' => [401, [], 'failed'],
    'commit rules or permissions' => [403, [], 'failed'],
    'invalid content' => [422, [], 'failed'],
    'rate limit' => [429, [], 'retry'],
    'provider failure' => [500, [], 'uncertain'],
    'lost connection' => [0, [], 'uncertain'],
    'missing receipt' => [201, ['data' => ['status' => 'pending']], 'uncertain'],
]);

test('devnull recovery verifies ownership and exposes only available metrics', function (): void {
    $account = devNullAccount();
    $publication = devNullPublication($account, [['text' => 'Hello', 'media_ids' => []]]);
    $id = (string) Str::uuid();
    Http::fake(['dev-null.sh/api/v1/commits/'.$id => function ($request) use ($id) {
        assertDevNullSignature($request, '');

        return Http::response(['data' => ['id' => $id, 'body' => 'Hello', 'author' => ['id' => 42], 'stars_count' => 4, 'replies_count' => 2]]);
    }]);
    $providers = app(SocialProviders::class);
    expect($providers->recoveryId($publication, 'https://dev-null.sh/@sendae/commits/'.$id))->toBe($id);
    $providers->verify($publication, $id);
    expect($providers->metrics($account, $id))->toBe(['likes' => 4, 'replies' => 2]);
    $account->update(['provider_id' => 'another-user']);
    expect(fn () => $providers->verify($publication->fresh(), $id))->toThrow(ProviderFailure::class, 'does not exactly match');
    Http::assertSentCount(3);
});

test('devnull scheduling rejects unsupported media and excessive text before sending', function (string $text, ?string $mime, int $size, int $count): void {
    $account = devNullAccount();
    Storage::fake('local');
    Storage::disk('local')->put('attachment', 'bytes');
    $ids = [];
    for ($index = 0; $index < $count; $index++) {
        $ids[] = Media::create(['name' => 'attachment', 'mime' => $mime, 'size' => $size, 'path' => 'attachment'])->id;
    }

    expect(fn () => devNullPublication($account, [['text' => $text, 'media_ids' => $ids]]))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('publications', 0);
    Http::assertNothingSent();
})->with([
    'long text' => [str_repeat('a', 4001), null, 0, 0],
    'video' => ['Hello', 'video/mp4', 100, 1],
    'large image' => ['Hello', 'image/png', 10485761, 1],
    'four images' => ['Hello', 'image/png', 100, 4],
]);

test('invalid and oversized devnull image dimensions fail before publishing', function (bool $oversized): void {
    $account = devNullAccount();
    Storage::fake('local');
    $bytes = $oversized ? substr_replace(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII='), pack('N', 6001), 16, 4) : 'invalid-image';
    Storage::disk('local')->put('image.png', $bytes);
    $media = Media::create(['name' => 'image.png', 'mime' => 'image/png', 'size' => strlen($bytes), 'path' => 'image.png']);
    $publication = devNullPublication($account, [['text' => 'Hello', 'media_ids' => [$media->id]]]);

    app(Publisher::class)->publish($publication->id);

    expect($publication->fresh()->status)->toBe('failed');
    expect($publication->fresh()->error)->toContain('6,000 pixels');
    Http::assertNothingSent();
})->with(['invalid image' => false, 'oversized dimensions' => true]);
