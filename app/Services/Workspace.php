<?php

namespace App\Services;

use App\Jobs\PublishAccount;
use App\Models\Account;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Publication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Workspace
{
    public function state(): array
    {
        $accounts = Account::orderBy('name')->get();
        $publications = Publication::orderByDesc('scheduled_at')->get();
        foreach ($publications as $publication) {
            $account = $accounts->firstWhere('id', $publication->account_id);
            if ($account?->provider === 'threads' && $publication->receipts) {
                $urls = [];
                // ponytail: fetch cache misses per receipt; batch lookups if history makes sync slow.
                foreach ($publication->receipts as $id) {
                    $urls[$id] = app(SocialProviders::class)->postUrl($account, $id);
                }
                $publication->snapshot = [...$publication->snapshot, 'post_urls' => $urls];
            }
        }

        return ['deleted_draft_ids' => Draft::onlyTrashed()->pluck('id'), 'drafts' => Draft::orderByDesc('updated_at')->get(), 'accounts' => $accounts->map(function (Account $account): array {
            $profile = app(SocialProviders::class)->profile($account);

            return $account->toArray() + $profile;
        }),
            'media' => Media::latest()->get(), 'publications' => $publications,
            'settings' => ['workspace_id' => app(WorkspaceOwner::class)->workspaceId(), 'workspaces' => \App\Models\Workspace::where('user_id', app(WorkspaceOwner::class)->requireId())->orderBy('created_at')->get(), 'mode' => config('sendae.mode'), 'mcp_url' => url('/mcp'), 'connections_url' => url('/'), 'paired' => false, 'providers' => collect(config('sendae.providers'))->map(fn ($p) => ['label' => $p['label'], 'configured' => $p['configured'] ?? (bool) ($p['client_id'] ?? null), 'approved' => $p['approved'] ?? true])]];
    }

    public function save(array $data, bool $sync = false): array
    {
        $data = Validator::make($data, [
            'id' => 'required|uuid', 'title' => 'nullable|string|max:200', 'version' => 'required|integer|min:0',
            'content' => 'required|array:items,overrides,account_ids', 'content.items' => 'required|array|min:1|max:30',
            'content.items.*' => 'required|array:text,media_ids', 'content.items.*.text' => 'present|nullable|string|max:65000',
            'content.items.*.media_ids' => 'present|array|max:20', 'content.items.*.media_ids.*' => 'uuid',
            'content.overrides' => 'present|array', 'content.overrides.*' => 'array|min:1|max:30',
            'content.overrides.*.*' => 'array:text,media_ids', 'content.overrides.*.*.text' => 'present|nullable|string|max:65000',
            'content.overrides.*.*.media_ids' => 'present|array|max:20', 'content.overrides.*.*.media_ids.*' => 'uuid',
            'content.account_ids' => 'present|array|max:30', 'content.account_ids.*' => 'uuid',
        ])->validate();
        $data['title'] = ($data['title'] ?? '') ?: 'Untitled draft';
        foreach ($data['content']['items'] as &$item) {
            $item['text'] ??= '';
        }
        unset($item);
        foreach ($data['content']['overrides'] as &$items) {
            foreach ($items as &$item) {
                $item['text'] ??= '';
            } unset($item);
        }
        unset($items);
        foreach (array_keys($data['content']['overrides']) as $network) {
            if (! array_key_exists($network, config('sendae.providers'))) {
                $this->invalid('content.overrides', 'Unknown network override.');
            }
        }

        abort_if(Draft::onlyTrashed()->whereKey($data['id'])->exists(), 410, 'This draft was deleted.');

        return $this->once('save', $data, function () use ($data, $sync) {
            $draft = Draft::withTrashed()->lockForUpdate()->find($data['id']);
            abort_if($draft?->trashed(), 410, 'This draft was deleted.');
            if (! $draft && Draft::count() >= config('sendae.draft_limit')) {
                $this->invalid('draft', 'Your workspace draft limit has been reached.');
            }
            $draft ??= new Draft(['id' => $data['id'], 'version' => 0]);
            $draft->fill(['title' => $data['title'], 'content' => $data['content'], 'version' => $draft->version + 1, 'dirty' => ! $sync])->save();

            return ['draft' => $draft, 'conflict' => null];
        });
    }

    public function deleteDraft(array $data): array
    {
        $data = Validator::make($data, ['id' => 'required|uuid', 'version' => 'required|integer|min:0'])->validate();

        return DB::transaction(function () use ($data) {
            User::whereKey(app(WorkspaceOwner::class)->requireId())->lockForUpdate()->firstOrFail();
            $draft = Draft::withTrashed()->lockForUpdate()->find($data['id']);
            if ($draft && ! $draft->trashed()) {
                abort_if($draft->version !== $data['version'], 409, 'This draft changed on another device. Sync and review it before deleting.');
                $draft->delete();
            }

            return ['deleted' => true];
        });
    }

    public function items(Draft $draft, Account $account): array
    {
        $items = $draft->content['overrides'][$account->provider] ?? $draft->content['items'];
        if (in_array($account->provider, ['facebook', 'linkedin', 'linkedin_page']) && count($items) > 1) {
            $items = [['text' => implode("\n\n", array_column($items, 'text')), 'media_ids' => array_merge(...array_column($items, 'media_ids'))]];
        }
        $limit = ['bluesky' => 300, 'x' => 280, 'threads' => 500, 'facebook' => 63206, 'linkedin' => 3000, 'linkedin_page' => 3000][$account->provider];
        foreach ($items as $i => $item) {
            if (! trim($item['text']) && ! $item['media_ids']) {
                $this->invalid('content', "{$account->name}: post ".($i + 1).' is empty.');
            }
            $length = $account->provider === 'x' ? $this->xLength($item['text']) : ($account->provider === 'bluesky' ? preg_match_all('/\X/u', $item['text']) : mb_strlen($item['text']));
            if ($length > $limit) {
                $this->invalid('content', "{$account->name}: post ".($i + 1)." is $length characters; limit is $limit. Edit the network override.");
            }
            $media = Media::whereIn('id', $item['media_ids'])->get();
            if ($media->count() !== count($item['media_ids'])) {
                $this->invalid('media', 'Some attachments have not synchronized.');
            }
            $mediaHost = strtolower(parse_url(config('app.url'), PHP_URL_HOST) ?? '');
            if ($media->isNotEmpty() && in_array($account->provider, ['threads', 'facebook']) && ($mediaHost === 'localhost' || str_ends_with($mediaHost, '.test') || str_ends_with($mediaHost, '.localhost'))) {
                $this->invalid('media', 'Facebook and Threads cannot download attachments from this local server. The publishing backend needs a public HTTPS address before image or video posts can be sent.');
            }
            if ($account->provider === 'bluesky') {
                if (strlen($item['text']) > 3000) {
                    $this->invalid('content', 'Bluesky post text must not exceed 3,000 UTF-8 bytes.');
                }
                if ($media->contains(fn ($attachment) => ! in_array($attachment->mime, ['image/jpeg', 'image/png', 'image/webp']) || $attachment->size > 2000000)) {
                    $this->invalid('media', 'Bluesky supports JPEG, PNG or WebP images up to 2 MB each. Video publishing is not supported yet.');
                }
            }
            $video = $media->contains(fn ($m) => str_starts_with($m->mime, 'video/'));
            $max = ['bluesky' => 4, 'x' => 4, 'threads' => 20, 'facebook' => 10, 'linkedin' => 20, 'linkedin_page' => 20][$account->provider];
            if ($media->count() > $max || ($video && $media->count() > 1)) {
                $this->invalid('media', "{$account->name}: use up to $max images".($account->provider === 'bluesky' ? ' per post.' : ' or one video per post.'));
            }
        }

        return $items;
    }

    public function xLength(string $text): int
    {
        $text = preg_replace('~https?://[^\s]+~u', str_repeat('a', 23), $text);

        // ponytail: conservative Unicode weighting; use twitter-text conformance if exact emoji/URL boundary parity is needed.
        return array_sum(array_map(fn ($c) => mb_ord($c) <= 0x10FF || (mb_ord($c) >= 0x2000 && mb_ord($c) <= 0x200D) || (mb_ord($c) >= 0x2010 && mb_ord($c) <= 0x201F) || (mb_ord($c) >= 0x2032 && mb_ord($c) <= 0x2037) ? 1 : 2, mb_str_split($text)));
    }

    public function schedule(array $data): array
    {
        if (config('sendae.mode') !== 'server') {
            $this->invalid('server', 'Connect a hosted server before scheduling. Local drafts are safe.');
        }
        $data = Validator::make($data, ['draft_id' => ['required', 'uuid', Rule::exists('drafts', 'id')->where('user_id', app(WorkspaceOwner::class)->requireId())->where('workspace_id', app(WorkspaceOwner::class)->workspaceId())], 'version' => 'required|integer', 'mode' => 'required|in:exact,queue,now', 'scheduled_at' => 'required_if:mode,exact|date', 'request_id' => 'sometimes|required|uuid'])->validate();
        if ($data['mode'] === 'exact' && ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $data['scheduled_at'])) {
            $this->invalid('scheduled_at', 'Include a timezone offset or Z in the scheduled time.');
        }

        return $this->once('schedule', $data, function () use ($data) {
            $draft = Draft::lockForUpdate()->findOrFail($data['draft_id']);
            if ($draft->version !== $data['version']) {
                $this->invalid('version', 'This draft changed. Reload before scheduling.');
            }
            $ids = $draft->content['account_ids'];
            $accounts = Account::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if (! $ids || $accounts->count() !== count($ids)) {
                $this->invalid('accounts', 'Select connected destination accounts.');
            }
            if (Publication::where('draft_id', $draft->id)->whereIn('status', ['scheduled', 'retry', 'publishing', 'uncertain'])->exists()) {
                $this->invalid('draft', 'Cancel existing pending publications before scheduling again.');
            }
            $result = [];
            foreach ($accounts as $account) {
                if ($account->status !== 'connected') {
                    $this->invalid('account', "{$account->name} needs reconnecting or approval.");
                }
                $items = $this->items($draft, $account);
                $at = match ($data['mode']) {
                    'now' => CarbonImmutable::now(), 'queue' => $this->nextSlot($account), default => CarbonImmutable::parse($data['scheduled_at'])->utc()
                };
                if ($data['mode'] === 'exact' && $at->isPast()) {
                    $this->invalid('scheduled_at', 'Choose a future time.');
                }
                $result[] = Publication::create(['draft_id' => $draft->id, 'account_id' => $account->id, 'snapshot' => ['title' => $draft->title, 'items' => $items], 'scheduled_at' => $at, 'receipts' => []]);
            }
            if ($data['mode'] === 'now') {
                foreach ($accounts as $account) {
                    PublishAccount::dispatch($account->id)->afterCommit();
                }
            }

            return $result;
        });
    }

    public function nextSlot(Account $account): CarbonImmutable
    {
        $now = CarbonImmutable::now($account->timezone);
        if (! $account->slots) {
            $this->invalid('slots', "Add weekly posting slots for {$account->name} first.");
        }
        for ($day = 0; $day < 370; $day++) {
            $date = $now->startOfDay()->addDays($day);
            $candidates = [];
            foreach ($account->slots as $slot) {
                if ((int) $slot['day'] !== $date->dayOfWeek) {
                    continue;
                }
                $candidate = CarbonImmutable::parse($date->format('Y-m-d').' '.$slot['time'], $account->timezone);
                // Skip nonexistent spring-forward times; repeated autumn times use Carbon's first occurrence.
                if ($candidate->format('H:i') !== $slot['time'] || $candidate <= $now) {
                    continue;
                }
                $candidates[] = $candidate->utc();
            }
            sort($candidates);
            foreach ($candidates as $at) {
                if (! Publication::where('account_id', $account->id)->where('scheduled_at', $at)->whereNotIn('status', ['cancelled', 'missed'])->exists()) {
                    return $at;
                }
            }
        }
        $this->invalid('slots', 'No free posting slot in the next year.');
    }

    public function cancel(string $id): Publication
    {
        return DB::transaction(function () use ($id) {
            $p = Publication::lockForUpdate()->findOrFail($id);
            if (! in_array($p->status, ['scheduled', 'retry', 'failed', 'missed'])) {
                $this->invalid('status', 'An in-flight, uncertain, or published post cannot be cancelled.');
            }
            $p->update(['status' => 'cancelled']);

            return $p;
        });
    }

    public function deletePublication(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $publication = Publication::lockForUpdate()->find($id);
            if ($publication) {
                if ($publication->status !== 'cancelled') {
                    $this->invalid('status', 'Only cancelled publications can be deleted.');
                }
                $publication->delete();
            }

            return ['deleted' => true];
        });
    }

    public function recover(array $data): Publication
    {
        $data = Validator::make($data, ['id' => ['required', 'uuid', Rule::exists('publications', 'id')->where('user_id', app(WorkspaceOwner::class)->requireId())->where('workspace_id', app(WorkspaceOwner::class)->workspaceId())], 'action' => 'required|in:confirmed,not_published,reschedule', 'post_id' => 'required_if:action,confirmed|string|max:200', 'scheduled_at' => 'required_unless:action,confirmed|date|after:now'])->validate();

        return DB::transaction(function () use ($data) {
            $p = Publication::lockForUpdate()->findOrFail($data['id']);
            if ($data['action'] === 'confirmed') {
                if ($p->status !== 'uncertain') {
                    $this->invalid('status', 'Only uncertain publications need confirmation.');
                }
                app(SocialProviders::class)->verify($p, $data['post_id']);
                $receipts = $p->receipts ?? [];
                if (in_array($data['post_id'], $receipts)) {
                    $this->invalid('post_id', 'This post is already confirmed.');
                }
                $receipts[] = $data['post_id'];
                $complete = count($receipts) === count($p->snapshot['items']);
                $p->update(['receipts' => $receipts, 'status' => $complete ? 'published' : ($p->scheduled_at->addHours(24)->isPast() ? 'missed' : 'retry'), 'published_at' => $complete ? now() : null, 'next_attempt_at' => null, 'error' => null]);
            } else {
                $allowed = $data['action'] === 'not_published' ? ['uncertain'] : ['scheduled', 'retry', 'failed', 'missed', 'cancelled'];
                if (! in_array($p->status, $allowed)) {
                    $this->invalid('status', 'This publication cannot be rescheduled in its current state.');
                }
                $p->update(['scheduled_at' => CarbonImmutable::parse($data['scheduled_at'])->utc(), 'status' => 'scheduled', 'next_attempt_at' => null, 'error' => null]);
            }

            return $p;
        });
    }

    private function once(string $operation, array $data, callable $action): array
    {
        $key = hash('sha256', app(WorkspaceOwner::class)->workspaceId().'|'.$operation.json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($key, $action) {
            User::lockForUpdate()->findOrFail(app(WorkspaceOwner::class)->requireId());
            if ($receipt = DB::table('operation_receipts')->where('id', $key)->first()) {
                return json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $action();
            DB::table('operation_receipts')->insert(['id' => $key, 'response' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        });
    }

    public function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
