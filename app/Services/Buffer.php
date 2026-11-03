<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Account;
use App\Models\Publication;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Client\ConnectionException;

class Buffer
{
    public function connect(string $apiKey): array
    {
        $data = $this->request($apiKey, 'query { account { organizations { id } } }');
        $choices = [];
        foreach ($data['account']['organizations'] ?? [] as $organization) {
            $data = $this->request($apiKey, 'query($input: ChannelsInput!) { channels(input: $input) { id name displayName service type avatar isDisconnected isLocked allowedActions } }', ['input' => ['organizationId' => $organization['id']]]);
            foreach ($data['channels'] ?? [] as $channel) {
                if (($channel['service'] ?? null) !== 'linkedin' || ($channel['type'] ?? null) !== 'page'
                    || ($channel['isDisconnected'] ?? true) || ($channel['isLocked'] ?? true)
                    || ! in_array('scheduleUpdates', $channel['allowedActions'] ?? [], true)) {
                    continue;
                }
                $choices[] = [
                    'provider_id' => 'buffer:'.$channel['id'],
                    'name' => $channel['displayName'] ?: $channel['name'],
                    'credentials' => Crypt::encryptString(json_encode([
                        'transport' => 'buffer', 'access_token' => $apiKey, 'channel_id' => $channel['id'],
                        'avatar_url' => $this->httpsUrl($channel['avatar'] ?? null),
                    ], JSON_THROW_ON_ERROR)),
                ];
            }
        }
        abort_unless($choices, 422, 'No publishable LinkedIn Pages were found. Connect your Page in Buffer as a Super Admin, then try again.');
        $ticket = Str::random(64);
        Cache::put('social_choices:'.$ticket, [
            'provider' => 'linkedin_page', 'user_id' => app(WorkspaceOwner::class)->requireId(),
            'workspace_id' => app(WorkspaceOwner::class)->workspaceId(), 'accounts' => $choices,
        ], now()->addMinutes(10));

        return ['ticket' => $ticket];
    }

    public function publish(Publication $publication): ?string
    {
        $account = $publication->account;
        $apiKey = $this->key($account);
        $id = $publication->snapshot['buffer_post_id'] ?? null;
        if ($id) {
            $post = $this->post($account, $id);
        } else {
            $item = $publication->snapshot['items'][0];
            $assets = [];
            foreach ($item['media_ids'] as $mediaId) {
                $media = Media::findOrFail($mediaId);
                if (! $media->path || ! Storage::disk('local')->exists($media->path)) {
                    throw new ProviderFailure('An attachment is missing from server storage.');
                }
                $asset = ['url' => app(SocialProviders::class)->mediaUrl($media)];
                if (str_starts_with($media->mime, 'image/')) {
                    $asset['metadata'] = ['altText' => $item['media_alt'][$mediaId] ?? ''];
                    $assets[] = ['image' => $asset];
                } else {
                    $assets[] = ['video' => $asset];
                }
            }
            $data = $this->request($apiKey, 'mutation($input: CreatePostInput!) { createPost(input: $input) { __typename ... on PostActionSuccess { post { id channelId status text externalLink error { message } } } ... on MutationError { message } } }', [
                'input' => ['channelId' => $account->credentials['channel_id'], 'text' => $item['text'], 'assets' => $assets, 'mode' => 'shareNow', 'schedulingType' => 'automatic'],
            ], true);
            $result = $data['createPost'] ?? [];
            if (($result['__typename'] ?? null) !== 'PostActionSuccess') {
                $outcome = in_array($result['__typename'] ?? '', ['InvalidInputError', 'UnauthorizedError', 'NotFoundError', 'LimitReachedError'], true) ? 'failed' : 'uncertain';
                throw new ProviderFailure($this->message($result['message'] ?? 'Buffer did not confirm acceptance. Check Buffer before retrying.', $apiKey), $outcome);
            }
            $post = $result['post'] ?? [];
            $id = $post['id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new ProviderFailure('Buffer returned no post ID. Check Buffer before retrying.', 'uncertain');
            }
            $publication->update(['snapshot' => [...$publication->snapshot, 'buffer_post_id' => $id]]);
        }
        if (($post['channelId'] ?? null) !== $account->credentials['channel_id']) {
            throw new ProviderFailure('Buffer returned a post for a different Page. Review it in Buffer.', 'uncertain');
        }
        if (($post['status'] ?? null) === 'sent') {
            $this->savePostUrl($publication, $post);

            return 'buffer:'.$id;
        }
        if (($post['status'] ?? null) === 'error') {
            $snapshot = $publication->snapshot;
            unset($snapshot['buffer_post_id']);
            $publication->update(['snapshot' => $snapshot]);
            throw new ProviderFailure($this->message($post['error']['message'] ?? 'Buffer could not publish this post. Review it in Buffer and reschedule.', $apiKey));
        }
        if (! in_array($post['status'] ?? null, ['scheduled', 'sending'], true)) {
            throw new ProviderFailure('Buffer has not confirmed delivery. Review this post in Buffer before recovering.', 'uncertain');
        }

        return null;
    }

    public function verify(Publication $publication, string $id): void
    {
        if (isset($publication->snapshot['buffer_post_id']) && $id !== 'buffer:'.$publication->snapshot['buffer_post_id']) {
            throw new ProviderFailure('Use the Buffer post ID already associated with this publication.');
        }
        $post = $this->post($publication->account, Str::after($id, 'buffer:'));
        if (($post['status'] ?? null) !== 'sent' || ($post['channelId'] ?? null) !== $publication->account->credentials['channel_id']
            || ($post['text'] ?? null) !== ($publication->snapshot['items'][0]['text'] ?? null)) {
            throw new ProviderFailure('The Buffer post does not match this Page and text, or has not finished publishing.');
        }
        $this->savePostUrl($publication, $post);
    }

    public function post(Account $account, string $id): array
    {
        $data = $this->request($this->key($account), 'query($input: PostInput!) { post(input: $input) { id channelId status text externalLink error { message } } }', ['input' => ['id' => $id]]);
        if (! is_array($data['post'] ?? null)) {
            throw new ProviderFailure('Buffer could not find this post. Review it in Buffer.', 'uncertain');
        }

        return $data['post'];
    }

    private function savePostUrl(Publication $publication, array $post): void
    {
        $url = $this->httpsUrl($post['externalLink'] ?? null);
        if ($url && in_array(parse_url($url, PHP_URL_HOST), ['linkedin.com', 'www.linkedin.com'], true)) {
            $publication->update(['snapshot' => [...$publication->snapshot, 'post_urls' => ['buffer:'.$post['id'] => $url]]]);
        }
    }

    private function key(Account $account): string
    {
        if ($account->status !== 'connected' || empty($account->credentials['access_token'])) {
            throw new ProviderFailure('Reconnect this LinkedIn Page through Buffer before publishing.');
        }

        return $account->credentials['access_token'];
    }

    private function request(string $apiKey, string $query, array $variables = [], bool $publishes = false): array
    {
        try {
            $response = Http::withToken($apiKey)->acceptJson()->connectTimeout(10)->timeout(30)->withoutRedirecting()
                ->post('https://api.buffer.com', ['query' => $query] + ($variables ? ['variables' => $variables] : []));
        } catch (ConnectionException) {
            throw new ProviderFailure($publishes ? 'Buffer may have accepted the post. Check Buffer before retrying.' : 'Buffer could not be reached. Try again.', $publishes ? 'uncertain' : 'retry');
        }
        if (! $response->successful()) {
            $status = $response->status();
            $outcome = $status === 429 ? 'retry' : ($status >= 500 ? ($publishes ? 'uncertain' : 'retry') : 'failed');
            throw new ProviderFailure("Buffer returned HTTP $status. ".(in_array($status, [401, 403]) ? 'Check your Buffer API key and Page permissions.' : 'Check Buffer before retrying.'), $outcome, min(86400, max(60, (int) $response->header('Retry-After'))));
        }
        if ($response->json('errors') || ! is_array($response->json('data'))) {
            throw new ProviderFailure('Buffer could not confirm the request. Check your API key, permissions and Buffer account.', $publishes ? 'uncertain' : 'retry');
        }

        return $response->json('data');
    }

    private function message(string $message, string $apiKey): string
    {
        return 'Buffer: '.mb_substr(strip_tags(str_replace($apiKey, '[redacted]', $message)), 0, 500);
    }

    private function httpsUrl(mixed $url): ?string
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' && ! parse_url($url, PHP_URL_USER) ? $url : null;
    }
}
