<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Media;
use App\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SocialProviders
{
    public function postUrl(Account $account, string $id): ?string
    {
        if ($account->provider !== 'threads') {
            return null;
        }

        return Cache::remember('post-url:'.$account->id.':'.$id, now()->addHour(), function () use ($account, $id): array {
            try {
                $response = $this->client($account)->connectTimeout(2)->timeout(3)->withoutRedirecting()
                    ->get('https://graph.threads.net/v1.0/'.rawurlencode($id), ['fields' => 'permalink']);
                $url = $response->successful() ? $response->json('permalink') : null;

                return ['url' => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(parse_url($url, PHP_URL_HOST), ['threads.net', 'www.threads.net', 'threads.com', 'www.threads.com'], true) && ! parse_url($url, PHP_URL_USER) ? $url : null];
            } catch (ConnectionException|ProviderFailure) {
                return ['url' => null];
            }
        })['url'];
    }

    public function avatarUrl(Account $account): ?string
    {
        return $this->profile($account)['avatar_url'];
    }

    public function verified(Account $account): bool
    {
        return $this->profile($account)['verified'];
    }

    /**
     * @return array{avatar_url: ?string, verified: bool}
     */
    public function profile(Account $account): array
    {
        if ($account->status !== 'connected' || ! in_array($account->provider, ['threads', 'facebook', 'linkedin', 'x', 'bluesky'])) {
            return ['avatar_url' => null, 'verified' => false];
        }

        return Cache::remember('account-profile:v2:'.$account->id.':'.$account->updated_at?->getTimestamp(), now()->addHour(), function () use ($account): array {
            try {
                $client = ($account->provider === 'bluesky' ? Http::acceptJson() : $this->client($account))->connectTimeout(2)->timeout(3)->withoutRedirecting();
                [$endpoint, $query, $field] = match ($account->provider) {
                    'bluesky' => ['https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile', ['actor' => $account->provider_id], 'avatar'],
                    'threads' => ['https://graph.threads.net/v1.0/me', ['fields' => 'threads_profile_picture_url,is_verified'], 'threads_profile_picture_url'],
                    'facebook' => ['https://graph.facebook.com/'.config('sendae.meta_version').'/me', ['fields' => 'picture.width(96).height(96),verification_status'], 'picture.data.url'],
                    'linkedin' => ['https://api.linkedin.com/v2/userinfo', [], 'picture'],
                    'x' => ['https://api.x.com/2/users/me', ['user.fields' => 'profile_image_url,verified,verified_type'], 'data.profile_image_url'],
                };
                $response = $client->get($endpoint, $query);
                $url = $response->successful() ? $response->json($field) : null;
                $verified = $response->successful() && match ($account->provider) {
                    'x' => $response->json('data.verified') === true || in_array($response->json('data.verified_type'), ['blue', 'business', 'government'], true),
                    'bluesky' => $response->json('verification.verifiedStatus') === 'valid',
                    'facebook' => in_array($response->json('verification_status'), ['blue_verified', 'gray_verified'], true),
                    'threads' => (bool) $response->json('is_verified'),
                    default => false,
                };

                return ['avatar_url' => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https' && ! parse_url($url, PHP_URL_USER) ? $url : null, 'verified' => $verified];
            } catch (ConnectionException|ProviderFailure) {
                return ['avatar_url' => null, 'verified' => false];
            }
        });
    }

    public function client(Account $a)
    {
        $credentials = $a->credentials;
        if (! $credentials || $a->status !== 'connected') {
            throw new ProviderFailure('Reconnect this account before publishing.');
        }
        if (! empty($credentials['expires_at']) && CarbonImmutable::parse($credentials['expires_at'])->subDay()->isPast()) {
            $response = null;
            if ($a->provider === 'x' && ! empty($credentials['refresh_token'])) {
                $response = Http::asForm()->withBasicAuth(config('sendae.providers.x.client_id'), config('sendae.providers.x.client_secret'))->timeout(30)->post('https://api.x.com/2/oauth2/token', ['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token'], 'client_id' => config('sendae.providers.x.client_id')]);
            } elseif ($a->provider === 'threads' && CarbonImmutable::parse($credentials['expires_at'])->isFuture()) {
                $response = Http::timeout(30)->get('https://graph.threads.net/refresh_access_token', ['grant_type' => 'th_refresh_token', 'access_token' => $credentials['access_token']]);
            }
            if ($response && $response->successful() && $response->json('access_token')) {
                $credentials = array_replace($credentials, $response->json());
                $credentials['expires_at'] = now()->addSeconds($response->json('expires_in', 3600))->toIso8601String();
                $a->update(['credentials' => $credentials]);
            } elseif (CarbonImmutable::parse($credentials['expires_at'])->isPast()) {
                $a->update(['status' => 'expired']);
                throw new ProviderFailure('Account authorization expired. Reconnect it in Accounts.');
            }
        }
        $client = Http::withToken($credentials['access_token'])->acceptJson()->connectTimeout(10)->timeout(60);
        if (str_starts_with($a->provider, 'linkedin')) {
            $client = $client->withHeaders(['LinkedIn-Version' => config('sendae.linkedin_version'), 'X-Restli-Protocol-Version' => '2.0.0']);
        }

        return $client;
    }

    public function send(Account $a, string $method, string $url, array $data = [], bool $publishes = false)
    {
        try {
            $client = $this->client($a);
            $response = $method === 'GET' ? $client->get($url, $data) : $client->post($url, $data);
        } catch (ConnectionException $e) {
            throw new ProviderFailure($publishes ? 'The provider may have accepted the post. Verify its outcome before retrying.' : 'The provider could not be reached.', $publishes ? 'uncertain' : 'retry');
        }
        if (! $response->successful()) {
            $code = $response->status();
            $outcome = $code === 429 ? 'retry' : ($code >= 500 ? ($publishes ? 'uncertain' : 'retry') : 'failed');
            throw new ProviderFailure("Provider returned HTTP $code. ".($outcome === 'uncertain' ? 'Verify the post before retrying.' : ($code === 401 || $code === 403 ? 'Check account permissions or reconnect.' : 'Review the post and provider limits.')), $outcome, min(86400, max(60, (int) $response->header('Retry-After'))));
        }

        return $response;
    }

    private function requiredId(mixed $id, bool $published = false): string
    {
        if (! $id || ! is_string($id) && ! is_int($id)) {
            throw new ProviderFailure('The provider returned no confirmed identifier.', $published ? 'uncertain' : 'retry');
        }

        return (string) $id;
    }

    public function publish(Account $a, array $item, ?string $reply): string
    {
        $media = collect($item['media_ids'])->map(fn ($id) => Media::findOrFail($id));
        foreach ($media as $m) {
            if (! $m->path || ! Storage::disk('local')->exists($m->path)) {
                throw new ProviderFailure('An attachment is missing from server storage.');
            }
        }

        return match ($a->provider) {
            'bluesky' => app(Bluesky::class)->publish($a, $item['text'], $media, $reply),
            'x' => $this->x($a, $item['text'], $media, $reply),
            'threads' => $this->threads($a, $item['text'], $media, $reply),
            'facebook' => $this->facebook($a, $item['text'], $media),
            'linkedin','linkedin_page' => $this->linkedin($a, $item['text'], $media),
            default => throw new ProviderFailure('Unsupported destination.'),
        };
    }

    public function mediaUrl(Media $m): string
    {
        return URL::temporarySignedRoute('media.public', now()->addDays(2), ['media' => $m->id]);
    }

    private function x(Account $a, string $text, $media, ?string $reply): string
    {
        $payload = ['text' => $text];
        $ids = [];
        foreach ($media as $m) {
            $init = $this->send($a, 'POST', 'https://api.x.com/2/media/upload/initialize', ['total_bytes' => $m->size, 'media_type' => $m->mime, 'media_category' => str_starts_with($m->mime, 'video/') ? 'tweet_video' : 'tweet_image']);
            $id = $this->requiredId($init->json('data.id'));
            $stream = Storage::disk('local')->readStream($m->path);
            $segment = 0;
            try {
                while (! feof($stream)) {
                    $bytes = fread($stream, 4 * 1024 * 1024);
                    if ($bytes === '') {
                        break;
                    }$this->send($a, 'POST', "https://api.x.com/2/media/upload/$id/append", ['media' => base64_encode($bytes), 'segment_index' => $segment++]);
                }
            } finally {
                fclose($stream);
            }
            $final = $this->send($a, 'POST', "https://api.x.com/2/media/upload/$id/finalize");
            $info = $final->json('data.processing_info');
            for ($i = 0; $info && in_array($info['state'], ['pending', 'in_progress']) && $i < 12; $i++) {
                sleep(min(5, max(1, $info['check_after_secs'] ?? 1)));
                $info = $this->send($a, 'GET', 'https://api.x.com/2/media/upload', ['media_id' => $id, 'command' => 'STATUS'])->json('data.processing_info');
            }
            if ($info && ($info['state'] ?? '') !== 'succeeded') {
                throw new ProviderFailure('X media processing has not completed.', 'retry');
            }
            $ids[] = $id;
        }
        if ($ids) {
            $payload['media'] = ['media_ids' => $ids];
        }if ($reply) {
            $payload['reply'] = ['in_reply_to_tweet_id' => $reply];
        }

        return $this->requiredId($this->send($a, 'POST', 'https://api.x.com/2/tweets', $payload, true)->json('data.id'), true);
    }

    private function threads(Account $a, string $text, $media, ?string $reply): string
    {
        $base = 'https://graph.threads.net/v1.0/';
        $endpoint = $base.$a->provider_id.'/threads';
        $payload = ['text' => $text, 'media_type' => 'TEXT'];
        if ($reply) {
            $payload['reply_to_id'] = $reply;
        }
        if ($media->count() === 1) {
            $m = $media->first();
            $video = str_starts_with($m->mime, 'video/');
            $payload += [$video ? 'video_url' : 'image_url' => $this->mediaUrl($m)];
            $payload['media_type'] = $video ? 'VIDEO' : 'IMAGE';
        } elseif ($media->count() > 1) {
            $children = [];
            foreach ($media as $m) {
                $children[] = $this->requiredId($this->send($a, 'POST', $endpoint, ['media_type' => 'IMAGE', 'image_url' => $this->mediaUrl($m), 'is_carousel_item' => true])->json('id'));
            }
            $payload['media_type'] = 'CAROUSEL';
            $payload['children'] = implode(',', $children);
        }
        $container = $this->requiredId($this->send($a, 'POST', $endpoint, $payload)->json('id'));
        if ($media->isNotEmpty()) {
            for ($i = 0; $i < 12; $i++) {
                $status = $this->send($a, 'GET', $base.$container, ['fields' => 'status'])->json('status');
                if ($status === 'FINISHED') {
                    break;
                }if (in_array($status, ['ERROR', 'EXPIRED'])) {
                    throw new ProviderFailure('Threads could not process the attached media.');
                }sleep(3);
            }
            if ($status !== 'FINISHED') {
                throw new ProviderFailure('Threads media is still processing.', 'retry');
            }
        }

        return $this->requiredId($this->send($a, 'POST', $base.$a->provider_id.'/threads_publish', ['creation_id' => $container], true)->json('id'), true);
    }

    private function facebook(Account $a, string $text, $media): string
    {
        $base = 'https://graph.facebook.com/'.config('sendae.meta_version').'/'.$a->provider_id;
        if ($media->count() === 1 && str_starts_with($media->first()->mime, 'video/')) {
            return $this->requiredId($this->send($a, 'POST', str_replace('graph.facebook.com', 'graph-video.facebook.com', $base).'/videos', ['description' => $text, 'file_url' => $this->mediaUrl($media->first())], true)->json('id'), true);
        }
        $payload = ['message' => $text];
        $attached = [];
        foreach ($media as $m) {
            $attached[] = ['media_fbid' => $this->requiredId($this->send($a, 'POST', $base.'/photos', ['url' => $this->mediaUrl($m), 'published' => false])->json('id'))];
        }
        if ($attached) {
            $payload['attached_media'] = $attached;
        }

        return $this->requiredId($this->send($a, 'POST', $base.'/feed', $payload, true)->json('id'), true);
    }

    private function linkedin(Account $a, string $text, $media): string
    {
        $payload = ['author' => $a->provider_id, 'commentary' => $text, 'visibility' => 'PUBLIC', 'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []], 'lifecycleState' => 'PUBLISHED', 'isReshareDisabledByAuthor' => false];
        $assets = [];
        foreach ($media as $m) {
            $video = str_starts_with($m->mime, 'video/');
            $type = $video ? 'videos' : 'images';
            $init = ['owner' => $a->provider_id];
            if ($video) {
                $init += ['fileSizeBytes' => $m->size, 'uploadCaptions' => false, 'uploadThumbnail' => false];
            }
            $data = $this->send($a, 'POST', "https://api.linkedin.com/rest/$type?action=initializeUpload", ['initializeUploadRequest' => $init])->json('value');
            $id = $this->requiredId($data[$video ? 'video' : 'image'] ?? null);
            $parts = [];
            $instructions = $video ? $data['uploadInstructions'] : [['uploadUrl' => $data['uploadUrl'], 'firstByte' => 0, 'lastByte' => $m->size - 1]];
            $file = Storage::disk('local')->readStream($m->path);
            try {
                foreach ($instructions as $instruction) {
                    $url = $instruction['uploadUrl'];
                    $host = parse_url($url, PHP_URL_HOST);
                    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! ($host === 'linkedin.com' || str_ends_with($host, '.linkedin.com'))) {
                        throw new ProviderFailure('LinkedIn returned an unexpected upload host.');
                    }
                    fseek($file, $instruction['firstByte']);
                    $bytes = fread($file, $instruction['lastByte'] - $instruction['firstByte'] + 1);
                    $upload = $this->client($a)->withBody($bytes, 'application/octet-stream')->put($url);
                    if (! $upload->successful()) {
                        throw new ProviderFailure('LinkedIn media upload failed.', 'retry');
                    }
                    $parts[] = trim($upload->header('ETag'), '"');
                }
            } finally {
                fclose($file);
            }
            if ($video) {
                $this->send($a, 'POST', 'https://api.linkedin.com/rest/videos?action=finalizeUpload', ['finalizeUploadRequest' => ['video' => $id, 'uploadToken' => $data['uploadToken'], 'uploadedPartIds' => $parts]]);
            }
            for ($i = 0; $i < 12; $i++) {
                $status = $this->send($a, 'GET', "https://api.linkedin.com/rest/$type/".rawurlencode($id))->json('status');
                if ($status === 'AVAILABLE') {
                    break;
                }if ($status === 'PROCESSING_FAILED') {
                    throw new ProviderFailure('LinkedIn could not process this media.');
                }sleep(3);
            }
            if ($status !== 'AVAILABLE') {
                throw new ProviderFailure('LinkedIn media is still processing.', 'retry');
            }
            $assets[] = ['id' => $id, 'altText' => $m->name];
        }
        if (count($assets) === 1) {
            $payload['content'] = ['media' => ['id' => $assets[0]['id']]];
        } elseif (count($assets) > 1) {
            $payload['content'] = ['multiImage' => ['images' => $assets]];
        }

        return $this->requiredId($this->send($a, 'POST', 'https://api.linkedin.com/rest/posts', $payload, true)->header('x-restli-id'), true);
    }

    public function verify(Publication $p, string $id): void
    {
        $a = $p->account;
        $expected = $p->snapshot['items'][count($p->receipts ?? [])]['text'] ?? null;
        if (! $a || $expected === null) {
            throw new ProviderFailure('No unfinished thread item to verify.');
        }
        if ($a->provider === 'bluesky') {
            $d = app(Bluesky::class)->record($a, $id);
            $owner = $a->provider_id;
            $text = $d['value']['text'] ?? null;
        } elseif ($a->provider === 'x') {
            $d = $this->send($a, 'GET', 'https://api.x.com/2/tweets/'.rawurlencode($id), ['tweet.fields' => 'author_id'])->json('data');
            $owner = $d['author_id'] ?? null;
            $text = $d['text'] ?? null;
        } elseif ($a->provider === 'threads') {
            $d = $this->send($a, 'GET', 'https://graph.threads.net/v1.0/'.rawurlencode($id), ['fields' => 'owner,text'])->json();
            $owner = $d['owner']['id'] ?? null;
            $text = $d['text'] ?? null;
        } elseif ($a->provider === 'facebook') {
            $d = $this->send($a, 'GET', 'https://graph.facebook.com/'.config('sendae.meta_version').'/'.rawurlencode($id), ['fields' => 'from,message'])->json();
            $owner = $d['from']['id'] ?? null;
            $text = $d['message'] ?? '';
        } else {
            $d = $this->send($a, 'GET', 'https://api.linkedin.com/rest/posts/'.rawurlencode($id))->json();
            $owner = $d['author'] ?? null;
            $text = $d['commentary'] ?? null;
        }
        if ((string) $owner !== $a->provider_id || $text !== $expected) {
            throw new ProviderFailure('The provider post does not exactly match this account and unfinished text. Keep it unresolved for manual review.');
        }
    }

    public function metrics(Account $a, string $id): array
    {
        if ($a->provider === 'bluesky') {
            return app(Bluesky::class)->metrics($a, $id);
        }
        if ($a->provider === 'x') {
            $m = $this->send($a, 'GET', 'https://api.x.com/2/tweets/'.rawurlencode($id), ['tweet.fields' => 'public_metrics'])->json('data.public_metrics', []);

            return ['impressions' => $m['impression_count'] ?? null, 'likes' => $m['like_count'] ?? null, 'replies' => $m['reply_count'] ?? null, 'reposts' => $m['retweet_count'] ?? null, 'quotes' => $m['quote_count'] ?? null];
        }
        if ($a->provider === 'threads') {
            $values = $this->send($a, 'GET', 'https://graph.threads.net/v1.0/'.rawurlencode($id).'/insights', ['metric' => 'views,likes,replies,reposts,quotes'])->json('data', []);
            $metrics = array_fill_keys(['views', 'likes', 'replies', 'reposts', 'quotes'], null);
            foreach ($values as $value) {
                $metrics[$value['name']] = $value['values'][0]['value'] ?? $value['total_value']['value'] ?? null;
            }

            return $metrics;
        }
        if ($a->provider === 'facebook') {
            $data = $this->send($a, 'GET', 'https://graph.facebook.com/'.config('sendae.meta_version').'/'.rawurlencode($id), ['fields' => 'reactions.summary(true).limit(0),comments.summary(true).limit(0),shares'])->json();

            return ['views' => null, 'reactions' => $data['reactions']['summary']['total_count'] ?? null, 'comments' => $data['comments']['summary']['total_count'] ?? null, 'shares' => $data['shares']['count'] ?? null];
        }
        if ($a->provider === 'linkedin_page') {
            $data = $this->send($a, 'GET', 'https://api.linkedin.com/rest/organizationalEntityShareStatistics', ['q' => 'organizationalEntity', 'organizationalEntity' => $a->provider_id, 'shares[0]' => $id])->json('elements.0.totalShareStatistics', []);

            return ['impressions' => $data['impressionCount'] ?? null, 'likes' => $data['likeCount'] ?? null, 'comments' => $data['commentCount'] ?? null, 'shares' => $data['shareCount'] ?? null];
        }
        if (! config('sendae.linkedin_personal_analytics')) {
            throw new ProviderFailure('LinkedIn personal analytics requires r_member_postAnalytics permission.', 'unavailable');
        }
        $result = [];
        $kind = str_contains($id, ':ugcPost:') ? 'ugc' : 'share';
        foreach (['impressions' => 'IMPRESSION', 'reactions' => 'REACTION', 'comments' => 'COMMENT', 'reshares' => 'RESHARE'] as $key => $type) {
            $result[$key] = $this->send($a, 'GET', 'https://api.linkedin.com/rest/memberCreatorPostAnalytics', ['q' => 'entity', 'entity' => "($kind:$id)", 'queryType' => $type, 'aggregation' => 'TOTAL'])->json('elements.0.count');
        }

        return $result;
    }
}
