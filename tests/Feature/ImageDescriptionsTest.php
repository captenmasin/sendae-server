<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Account;
use App\Services\Workspace;
use Illuminate\Support\Str;
use App\Mcp\Tools\SaveDraft;
use Laravel\Passport\Passport;
use App\Mcp\Servers\SendaeServer;
use App\Services\ProviderFailure;
use App\Services\SocialProviders;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    Http::preventStrayRequests();
});

function descriptionImage(string $name = 'image.png'): Media
{
    Storage::disk('local')->put($name, 'image');

    return Media::create(['name' => $name, 'mime' => 'image/png', 'size' => 5, 'path' => $name]);
}

function descriptionAccount(string $provider): Account
{
    return Account::create(['provider' => $provider, 'provider_id' => $provider === 'bluesky' ? 'did:plc:author' : '123', 'name' => 'Author', 'status' => 'connected', 'timezone' => 'UTC', 'credentials' => ['access_token' => 'token', 'refresh_token' => 'refresh', 'expires_at' => now()->addDays(3)->toIso8601String()]]);
}

test('descriptions persist in overrides and immutable publication snapshots including collapsed posts', function (): void {
    Storage::fake('local');
    $first = descriptionImage();
    $second = descriptionImage('second.png');
    $linkedin = descriptionAccount('linkedin');
    $x = descriptionAccount('x');
    $id = (string) Str::uuid();
    $content = ['items' => [['text' => 'First', 'media_ids' => [$first->id], 'media_alt' => [$first->id => 'A grey cat']], ['text' => 'Second', 'media_ids' => [$second->id], 'media_alt' => [$second->id => 'A dog']]], 'overrides' => ['x' => [['text' => 'For X', 'media_ids' => [$first->id], 'media_alt' => [$first->id => 'A sleeping cat']]]], 'account_ids' => [$linkedin->id, $x->id]];
    $this->postJson('/api/drafts', ['id' => $id, 'title' => '', 'version' => 0, 'content' => $content])->assertOk()->assertJsonPath('draft.content', $content);

    $publications = collect(app(Workspace::class)->schedule(['draft_id' => $id, 'version' => 1, 'mode' => 'exact', 'scheduled_at' => now()->addDay()->toIso8601String()]));
    $content['items'][0]['media_alt'][$first->id] = 'Changed later';
    $content['overrides']['x'][0]['media_alt'][$first->id] = 'Changed X later';
    $this->postJson('/api/drafts', ['id' => $id, 'title' => '', 'version' => 1, 'content' => $content])->assertOk();

    expect(Draft::findOrFail($id)->content)->toBe($content);
    expect($publications->firstWhere('account_id', $linkedin->id)->fresh()->snapshot['items'][0]['media_alt'])->toBe([$first->id => 'A grey cat', $second->id => 'A dog']);
    expect($publications->firstWhere('account_id', $x->id)->fresh()->snapshot['items'][0]['media_alt'])->toBe([$first->id => 'A sleeping cat']);
});

test('invalid descriptions return 422 without saving a server draft', function (mixed $description): void {
    $image = (string) Str::uuid();
    $item = ['text' => '', 'media_ids' => [$image], 'media_alt' => [$image => $description]];
    $this->postJson('/api/drafts', ['id' => (string) Str::uuid(), 'title' => '', 'version' => 0, 'content' => ['items' => [$item], 'overrides' => ['x' => [$item]], 'account_ids' => []]])
        ->assertUnprocessable()->assertJsonValidationErrors(['content.items.0.media_alt.'.$image, 'content.overrides.x.0.media_alt.'.$image]);
    $this->assertDatabaseCount('drafts', 0);
})->with(['too long' => str_repeat('a', 1001), 'array' => [[]], 'number' => 123]);

test('server descriptions must refer to attachments on that item', function (): void {
    $this->postJson('/api/drafts', ['id' => (string) Str::uuid(), 'title' => '', 'version' => 0, 'content' => ['items' => [['text' => '', 'media_ids' => [], 'media_alt' => [(string) Str::uuid() => 'Detached description']]], 'overrides' => [], 'account_ids' => []]])
        ->assertUnprocessable()->assertJsonValidationErrors('content');
    $this->assertDatabaseCount('drafts', 0);
});

test('server agents can author image descriptions', function (): void {
    Storage::fake('local');
    $image = descriptionImage()->id;
    $id = (string) Str::uuid();

    SendaeServer::tool(SaveDraft::class, ['workspace_id' => auth()->user()->workspace_id, 'id' => $id, 'title' => '', 'version' => 0, 'content' => ['items' => [['text' => '', 'media_ids' => [$image], 'media_alt' => [$image => 'From an agent']]], 'overrides' => [], 'account_ids' => []]])->assertOk();

    expect(Draft::findOrFail($id)->content['items'][0]['media_alt'][$image])->toBe('From an agent');
});

test('providers receive authored descriptions for single and multiple images and accept legacy attachments', function (string $provider, int $count, bool $described): void {
    Storage::fake('local');
    $account = descriptionAccount($provider);
    $images = collect(range(1, $count))->map(fn ($i) => descriptionImage("image-$i.png"));
    $item = ['text' => 'Photo', 'media_ids' => $images->pluck('id')->all()];
    if ($described) {
        $item['media_alt'] = [$images->first()->id => 'A grey cat'];
    }
    $base = 'https://bsky.social/xrpc/';
    $facebook = 'https://graph.facebook.com/'.config('sendae.meta_version').'/123';
    Http::fake([
        'https://api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => '456']]),
        'https://api.x.com/2/media/upload/456/*' => Http::response(['data' => ['id' => '456']]),
        'https://api.x.com/2/media/metadata' => Http::response(['data' => ['id' => '456']]),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => '789']]),
        'https://graph.threads.net/v1.0/123/threads' => Http::response(['id' => '456']),
        'https://graph.threads.net/v1.0/456*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/123/threads_publish' => Http::response(['id' => '789']),
        $facebook.'/photos' => Http::response(['id' => '456']),
        $facebook.'/feed' => Http::response(['id' => '789']),
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response(['value' => ['image' => 'urn:li:image:456', 'uploadUrl' => 'https://www.linkedin.com/upload']]),
        'https://www.linkedin.com/upload' => Http::response('', 200),
        'https://api.linkedin.com/rest/images/*' => Http::response(['status' => 'AVAILABLE']),
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:789']),
        $base.'com.atproto.repo.uploadBlob' => Http::response(['blob' => ['ref' => ['$link' => 'image-cid']]]),
        $base.'com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:author/app.bsky.feed.post/789', 'cid' => 'record-cid']),
    ]);

    app(SocialProviders::class)->publish($account, $item, null);

    $expected = $described ? 'A grey cat' : null;
    if ($provider === 'x') {
        if ($described) {
            Http::assertSent(fn ($request) => $request->url() === 'https://api.x.com/2/media/metadata' && $request['id'] === '456' && $request['metadata']['alt_text']['text'] === $expected);
        } else {
            Http::assertNotSent(fn ($request) => $request->url() === 'https://api.x.com/2/media/metadata');
        }
    } elseif ($provider === 'bluesky') {
        Http::assertSent(fn ($request) => $request->url() === $base.'com.atproto.repo.createRecord' && $request['record']['embed']['images'][0]['alt'] === ($expected ?? '') && ($count === 1 || $request['record']['embed']['images'][1]['alt'] === ''));
    } elseif ($provider === 'threads') {
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.threads.net/v1.0/123/threads' && $request['media_type'] === 'IMAGE' && ($request['alt_text'] ?? null) === $expected);
        if ($count > 1) {
            Http::assertSent(fn ($request) => $request->url() === 'https://graph.threads.net/v1.0/123/threads' && $request['media_type'] === 'IMAGE' && ! isset($request['alt_text']));
        }
    } elseif ($provider === 'facebook') {
        Http::assertSent(fn ($request) => $request->url() === $facebook.'/photos' && ($request['alt_text_custom'] ?? null) === $expected);
    } else {
        Http::assertSent(function ($request) use ($count, $expected): bool {
            $assets = $request['content']['multiImage']['images'] ?? [$request['content']['media'] ?? []];

            return $request->url() === 'https://api.linkedin.com/rest/posts' && ($assets[0]['altText'] ?? null) === $expected && ($count === 1 || ! isset($assets[1]['altText']));
        });
    }
})->with(['x', 'bluesky', 'threads', 'facebook', 'linkedin', 'linkedin_page'])->with(['single image' => [1, true], 'multiple images' => [2, true], 'legacy image' => [1, false]]);

test('X never posts when its description metadata fails', function (array $response, int $status): void {
    Storage::fake('local');
    $image = descriptionImage();
    $account = descriptionAccount('x');
    Http::fake([
        'https://api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => '456']]),
        'https://api.x.com/2/media/upload/456/*' => Http::response([]),
        'https://api.x.com/2/media/metadata' => Http::response($response, $status),
    ]);

    expect(fn () => app(SocialProviders::class)->publish($account, ['text' => 'Photo', 'media_ids' => [$image->id], 'media_alt' => [$image->id => 'A cat']], null))->toThrow(ProviderFailure::class);

    Http::assertNotSent(fn ($request) => $request->url() === 'https://api.x.com/2/tweets');
})->with(['error response' => [[], 503], 'partial failure' => [['data' => ['id' => '456'], 'errors' => [['detail' => 'Rejected']]], 200], 'unconfirmed metadata' => [[], 200]]);
