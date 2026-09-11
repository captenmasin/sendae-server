<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class Bluesky
{
    private const Base = 'https://bsky.social/xrpc/';

    public function connect(string $identifier, #[\SensitiveParameter] string $password, string $timezone): Account
    {
        $response = $this->http()->post(self::Base.'com.atproto.server.createSession', ['identifier' => ltrim(trim($identifier), '@'), 'password' => $password]);
        if (! $response->successful()) {
            throw new ProviderFailure('Bluesky could not sign in. Check your handle and app password.');
        }
        $session = $response->json();
        $credentials = $this->credentials($session);

        return Account::updateOrCreate(['provider' => 'bluesky', 'provider_id' => $session['did']], ['name' => $session['handle'], 'credentials' => $credentials, 'status' => 'connected', 'timezone' => $timezone]);
    }

    /** @return array{access_token: string, refresh_token: string, expires_at: string} */
    private function credentials(mixed $session, ?string $did = null): array
    {
        if (! is_array($session) || ! is_string($session['did'] ?? null) || ! str_starts_with($session['did'], 'did:') || ($did !== null && $session['did'] !== $did) || ! is_string($session['handle'] ?? null) || ! is_string($session['accessJwt'] ?? null) || ! is_string($session['refreshJwt'] ?? null) || $session['refreshJwt'] === '' || ($session['active'] ?? true) !== true) {
            throw new ProviderFailure('Bluesky returned an invalid or inactive session. Reconnect your account.');
        }
        $parts = explode('.', $session['accessJwt']);
        $claims = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        if (! is_numeric($claims['exp'] ?? null) || $claims['exp'] <= time()) {
            throw new ProviderFailure('Bluesky returned an expired session. Reconnect your account.');
        }

        return ['access_token' => $session['accessJwt'], 'refresh_token' => $session['refreshJwt'], 'expires_at' => CarbonImmutable::createFromTimestamp($claims['exp'])->toIso8601String()];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(10)->timeout(60);
    }

    private function token(Account $account): string
    {
        return Cache::lock('bluesky-session:'.$account->id, 90)->block(5, function () use ($account): string {
            $account->refresh();
            $credentials = $account->credentials;
            if ($account->status !== 'connected' || empty($credentials['refresh_token'])) {
                throw new ProviderFailure('Reconnect your Bluesky account before publishing.');
            }
            if (empty($credentials['expires_at']) || CarbonImmutable::parse($credentials['expires_at'])->subMinutes(5)->isPast()) {
                $response = $this->http()->withToken($credentials['refresh_token'])->post(self::Base.'com.atproto.server.refreshSession');
                if (in_array($response->status(), [400, 401, 403])) {
                    $account->update(['status' => 'expired']);
                    throw new ProviderFailure('Bluesky authorization expired. Reconnect it in Accounts.');
                }
                $this->check($response);
                $credentials = $this->credentials($response->json(), $account->provider_id);
                $account->update(['credentials' => $credentials]);
            }

            return $credentials['access_token'];
        });
    }

    private function request(Account $account, string $method, string $endpoint, array $data = [], ?Media $media = null, bool $publishes = false): Response
    {
        try {
            $client = $this->http()->withToken($this->token($account));
        } catch (ConnectionException) {
            throw new ProviderFailure('Bluesky could not refresh the session. Try again later.', 'retry');
        }
        try {
            if ($media) {
                $client = $client->withBody(Storage::disk('local')->get($media->path), $media->mime);
            }
            $response = $method === 'GET' ? $client->get(self::Base.$endpoint, $data) : $client->post(self::Base.$endpoint, $data);
        } catch (ConnectionException) {
            throw new ProviderFailure($publishes ? 'Bluesky may have accepted the post. Verify its outcome before retrying.' : 'Bluesky could not be reached.', $publishes ? 'uncertain' : 'retry');
        }
        $this->check($response, $publishes);

        return $response;
    }

    private function check(Response $response, bool $publishes = false): void
    {
        if (! $response->successful()) {
            $status = $response->status();
            $outcome = $status === 429 ? 'retry' : ($status >= 500 ? ($publishes ? 'uncertain' : 'retry') : 'failed');
            throw new ProviderFailure('Bluesky returned HTTP '.$status.'. '.($outcome === 'uncertain' ? 'Verify the post before retrying.' : 'Check the account connection and post limits.'), $outcome, min(86400, max(60, (int) $response->header('Retry-After'))));
        }
    }

    /** @param Collection<int, Media> $media */
    public function publish(Account $account, string $text, Collection $media, ?string $reply): string
    {
        $record = ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => now()->toIso8601String()];
        preg_match_all('~https?://[^\s<>]+~u', $text, $links, PREG_OFFSET_CAPTURE);
        foreach ($links[0] as [$url, $offset]) {
            $url = rtrim($url, '.,;:!?');
            $record['facets'][] = ['index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($url)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]];
        }
        if ($reply) {
            $parent = $this->record($account, $reply);
            $reference = ['uri' => $reply, 'cid' => $parent['cid']];
            $record['reply'] = ['root' => $parent['value']['reply']['root'] ?? $reference, 'parent' => $reference];
        }
        if ($media->isNotEmpty()) {
            $images = [];
            foreach ($media as $image) {
                $blob = $this->request($account, 'POST', 'com.atproto.repo.uploadBlob', media: $image)->json('blob');
                if (! is_array($blob) || empty($blob['ref']['$link'])) {
                    throw new ProviderFailure('Bluesky did not confirm the image upload.', 'retry');
                }
                $images[] = ['alt' => '', 'image' => $blob];
            }
            $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
        }
        $result = $this->request($account, 'POST', 'com.atproto.repo.createRecord', ['repo' => $account->provider_id, 'collection' => 'app.bsky.feed.post', 'record' => $record], publishes: true)->json();
        if (! is_string($result['uri'] ?? null) || ! preg_match('~^at://'.preg_quote($account->provider_id, '~').'/app\.bsky\.feed\.post/[A-Za-z0-9._:-]+$~', $result['uri']) || empty($result['cid'])) {
            throw new ProviderFailure('Bluesky returned no confirmed post identifier. Verify its outcome before retrying.', 'uncertain');
        }

        return $result['uri'];
    }

    /** @return array{uri: string, cid: string, value: array} */
    public function record(Account $account, string $uri): array
    {
        if (! preg_match('~^at://'.preg_quote($account->provider_id, '~').'/app\.bsky\.feed\.post/([A-Za-z0-9._:-]+)$~', $uri, $match)) {
            throw new ProviderFailure('Enter the Bluesky post AT URI belonging to this account.');
        }
        $record = $this->request($account, 'GET', 'com.atproto.repo.getRecord', ['repo' => $account->provider_id, 'collection' => 'app.bsky.feed.post', 'rkey' => $match[1]])->json();
        if (($record['uri'] ?? null) !== $uri || empty($record['cid']) || ! is_array($record['value'] ?? null)) {
            throw new ProviderFailure('Bluesky did not return the requested post.');
        }

        return $record;
    }

    public function metrics(Account $account, string $uri): array
    {
        $post = $this->request($account, 'GET', 'app.bsky.feed.getPosts', ['uris' => $uri])->json('posts.0');
        if (($post['uri'] ?? null) !== $uri) {
            throw new ProviderFailure('Bluesky could not find this post.');
        }

        return ['likes' => $post['likeCount'] ?? null, 'replies' => $post['replyCount'] ?? null, 'reposts' => $post['repostCount'] ?? null, 'quotes' => $post['quoteCount'] ?? null];
    }
}
