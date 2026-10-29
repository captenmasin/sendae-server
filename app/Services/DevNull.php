<?php

namespace App\Services;

use App\Models\Account;
use phpseclib4\Crypt\EC;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use phpseclib4\Common\Functions\Strings;
use Illuminate\Http\Client\ConnectionException;

class DevNull
{
    /** @return array{url: string, ticket: string} */
    public function start(string $redirectUri): array
    {
        $key = EC::createKey('Ed25519');
        $public = base64_decode(explode(' ', $key->getPublicKey()->toString('OpenSSH'))[1]);
        $ticket = Str::random(64);
        $verifier = Str::random(64);
        Cache::put('devnull_connect:'.$ticket, Crypt::encryptString(json_encode([
            'user_id' => app(WorkspaceOwner::class)->requireId(),
            'workspace_id' => app(WorkspaceOwner::class)->workspaceId(),
            'private_key' => $key->toString('PKCS8'), 'verifier' => $verifier,
        ], JSON_THROW_ON_ERROR)), now()->addMinutes(10));

        return ['ticket' => $ticket, 'url' => 'https://dev-null.sh/mobile/authorize?'.http_build_query([
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $public, true)), '='),
            'device_name' => 'Sendae',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'state' => $ticket, 'redirect_uri' => $redirectUri,
        ])];
    }

    /** @return array{ticket: string} */
    public function finish(string $ticket, string $code): array
    {
        return Cache::lock('devnull_finish:'.$ticket, 90)->block(2, function () use ($ticket, $code): array {
            $stored = Cache::get('devnull_connect:'.$ticket);
            abort_unless(is_string($stored), 404, 'This /dev/null connection expired. Start again from Accounts.');
            $pending = json_decode(Crypt::decryptString($stored), true, flags: JSON_THROW_ON_ERROR);
            abort_unless($pending['user_id'] === app(WorkspaceOwner::class)->requireId(), 404);
            $profile = $this->request($pending['private_key'], 'POST', '/api/v1/devices', ['code' => $code, 'code_verifier' => $pending['verifier']])->json('data');
            abort_unless(is_array($profile) && (is_string($profile['id'] ?? null) || is_int($profile['id'] ?? null)) && is_string($profile['handle'] ?? null) && $profile['handle'] !== '', 502, '/dev/null returned an invalid account.');
            $avatar = $profile['avatar_url'] ?? null;
            $avatar = is_string($avatar) && filter_var($avatar, FILTER_VALIDATE_URL) && parse_url($avatar, PHP_URL_SCHEME) === 'https' && ! parse_url($avatar, PHP_URL_USER) ? $avatar : null;
            $selectionTicket = Str::random(64);
            Cache::put('social_choices:'.$selectionTicket, [
                'user_id' => $pending['user_id'], 'workspace_id' => $pending['workspace_id'], 'provider' => 'devnull',
                'accounts' => [[
                    'provider_id' => (string) $profile['id'], 'name' => '@'.$profile['handle'],
                    'credentials' => Crypt::encryptString(json_encode(['private_key' => $pending['private_key'], 'handle' => $profile['handle'], 'avatar_url' => $avatar], JSON_THROW_ON_ERROR)),
                ]],
            ], now()->addMinutes(10));
            Cache::forget('devnull_connect:'.$ticket);

            return ['ticket' => $selectionTicket];
        });
    }

    public function publish(Account $account, string $text, Collection $media, ?string $reply, array $descriptions): string
    {
        $payload = ['body' => $text];
        if ($reply) {
            $payload['parent_id'] = $reply;
        }
        $images = [];
        foreach ($media as $attachment) {
            if (! in_array($attachment->mime, ['image/jpeg', 'image/png', 'image/webp']) || $attachment->size > 10 * 1024 * 1024 || count($images) >= 3) {
                throw new ProviderFailure('/dev/null supports up to three JPEG, PNG or WebP images, up to 10 MB each.');
            }
            $images[] = ['bytes' => Storage::disk('local')->get($attachment->path), 'name' => $attachment->name, 'mime' => $attachment->mime];
            $dimensions = @getimagesizefromstring(end($images)['bytes']);
            if (! $dimensions || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
                throw new ProviderFailure('/dev/null images must be valid images no larger than 6,000 pixels per side.');
            }
            $payload['image_descriptions'][] = $descriptions[$attachment->id] ?? null;
        }
        $data = $this->accountRequest($account, 'POST', '/api/v1/commits', $payload, $images, true)->json('data');
        if (! is_array($data) || ! Str::isUuid($data['id'] ?? '') || ! in_array($data['status'] ?? null, ['approved', 'pending'])) {
            throw new ProviderFailure('/dev/null did not confirm the post. Check its outcome before retrying.', 'uncertain');
        }

        return $data['id'];
    }

    public function record(Account $account, string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new ProviderFailure('Use the /dev/null commit ID or a direct commit link.');
        }

        return $this->accountRequest($account, 'GET', '/api/v1/commits/'.$id)->json('data', []);
    }

    private function accountRequest(Account $account, string $method, string $path, array $payload = [], array $images = [], bool $publishes = false): Response
    {
        if ($account->status !== 'connected' || empty($account->credentials['private_key'])) {
            throw new ProviderFailure('Reconnect your /dev/null account before publishing.');
        }

        return $this->request($account->credentials['private_key'], $method, $path, $payload, $images, $publishes, $account);
    }

    /**
     * Signed API protocol: https://github.com/dev-null-sh/dev-null.sh/blob/production/app/Services/SignedRequests.php
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{bytes: string, name: string, mime: string}>  $images
     */
    private function request(#[\SensitiveParameter] string $privateKey, string $method, string $path, array $payload = [], array $images = [], bool $publishes = false, ?Account $account = null): Response
    {
        $body = $method === 'GET' ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $nonce = bin2hex(random_bytes(16));
        $namespace = $images ? 'devnull-api-images' : 'devnull-api';
        $message = implode("\n", [$timestamp, $nonce, $method, $path, ...($images ? [implode(',', array_map(fn (array $image): string => hash('sha256', $image['bytes']), $images))] : []), $body]);
        $key = EC::loadPrivateKey($privateKey)->withSignatureFormat('SSH2');
        $public = base64_decode(explode(' ', $key->getPublicKey()->toString('OpenSSH'))[1]);
        $signed = 'SSHSIG'.Strings::packSSH2('ssss', $namespace, '', 'sha512', hash('sha512', $message, true));
        $signature = base64_encode('SSHSIG'.Strings::packSSH2('Nsssss', 1, $public, $namespace, '', 'sha512', $key->sign($signed)));
        $client = Http::acceptJson()->withoutRedirecting()->connectTimeout(10)->timeout(60)->withHeaders([
            'X-Devnull-Timestamp' => $timestamp, 'X-Devnull-Nonce' => $nonce, 'X-SSH-Signature' => $signature,
        ]);
        try {
            if ($images) {
                $client = $client->attach('payload', $body, 'payload.json', ['Content-Type' => 'application/json']);
                foreach ($images as $image) {
                    $client = $client->attach('images[]', $image['bytes'], $image['name'], ['Content-Type' => $image['mime']]);
                }
                $response = $client->post('https://dev-null.sh'.$path);
            } else {
                $response = $client->withBody($body, 'application/json')->send($method, 'https://dev-null.sh'.$path);
            }
        } catch (ConnectionException) {
            throw new ProviderFailure($publishes ? '/dev/null may have accepted the post. Verify its outcome before retrying.' : '/dev/null could not be reached.', $publishes ? 'uncertain' : 'retry');
        }
        if (! $response->successful()) {
            $status = $response->status();
            if ($status === 401 && $account) {
                $account->update(['status' => 'expired']);
            }
            $outcome = $status === 429 ? 'retry' : ($status >= 500 && $publishes ? 'uncertain' : ($status >= 500 ? 'retry' : 'failed'));
            $message = $status === 401 ? '/dev/null authorization expired. Reconnect it in Accounts.' : '/dev/null returned HTTP '.$status.'. '.($outcome === 'uncertain' ? 'Verify the post before retrying.' : 'Check your account permissions, accept the commit rules on dev-null.sh, and review the post limits.');
            throw new ProviderFailure($message, $outcome, min(86400, max(60, (int) $response->header('Retry-After'))));
        }

        return $response;
    }
}
