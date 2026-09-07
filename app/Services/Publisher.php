<?php

namespace App\Services;

use App\Jobs\PublishAccount;
use App\Models\Account;
use App\Models\Publication;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Publisher
{
    public function __construct(private SocialProviders $providers) {}

    public function tick(): void
    {
        if (config('sendae.mode') !== 'server') {
            return;
        }
        Publication::withoutGlobalScope('owner')->where('status', 'publishing')->where('updated_at', '<', now()->subMinutes(15))->update(['status' => 'uncertain', 'error' => 'Worker stopped before confirming the outcome. Verify on the provider before recovering.']);
        foreach (Publication::withoutGlobalScope('owner')->whereIn('status', ['scheduled', 'retry'])->where('scheduled_at', '<=', now())->select('account_id')->distinct()->pluck('account_id') as $accountId) {
            PublishAccount::dispatch($accountId);
        }
    }

    public function publishAccount(string $accountId): void
    {
        if (config('sendae.mode') !== 'server') {
            return;
        }
        $account = Account::withoutGlobalScope('owner')->find($accountId);
        if (! $account) {
            return;
        }
        app(WorkspaceOwner::class)->run($account->user_id, fn () => $this->publishOwnedAccount($accountId), $account->workspace_id);
    }

    private function publishOwnedAccount(string $accountId): void
    {
        $lock = Cache::lock('publish-account-'.$accountId, 900);
        if (! $lock->get()) {
            return;
        }
        try {
            foreach (Publication::where('account_id', $accountId)->whereIn('status', ['scheduled', 'retry'])->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->get() as $p) {
                $this->publish($p->id);
            }
        } finally {
            $lock->release();
        }
    }

    public function publish(string $id): void
    {
        $p = DB::transaction(function () use ($id) {
            $p = Publication::lockForUpdate()->findOrFail($id);
            if (! in_array($p->status, ['scheduled', 'retry']) || $p->scheduled_at->isFuture()) {
                return null;
            }
            if ($p->scheduled_at->addHours(24)->isPast()) {
                $p->update(['status' => 'missed', 'error' => 'The original scheduled time is more than 24 hours ago. Reschedule manually.']);

                return null;
            }
            if ($p->next_attempt_at?->isFuture()) {
                return null;
            }
            if (Publication::where('account_id', $p->account_id)->where('scheduled_at', '<', $p->scheduled_at)->whereIn('status', ['scheduled', 'retry', 'publishing', 'uncertain'])->exists()) {
                return null;
            }
            $p->update(['status' => 'publishing', 'attempts' => $p->attempts + 1, 'error' => null]);

            return $p;
        });
        if (! $p) {
            return;
        }
        try {
            if (! $p->account) {
                throw new ProviderFailure('The destination account no longer exists.');
            }
            $receipts = $p->receipts ?? [];
            foreach (array_slice($p->snapshot['items'], count($receipts)) as $item) {
                $id = $this->providers->publish($p->account, $item, $receipts ? end($receipts) : null);
                $receipts[] = $id;
                $p->update(['receipts' => $receipts]);
            }
            $p->update(['status' => 'published', 'published_at' => now(), 'next_attempt_at' => null]);
        } catch (ProviderFailure $e) {
            $p->update(['status' => $e->outcome, 'error' => $e->getMessage(), 'next_attempt_at' => $e->outcome === 'retry' ? now()->addSeconds($e->retryAfter) : null]);
        } catch (\Throwable $e) {
            $p->update(['status' => 'uncertain', 'error' => 'Publishing stopped without a confirmed outcome. Review this publication before retrying.']);
            report($e);
        }
    }

    public function analytics(Publication $p): Publication
    {
        if ($p->status !== 'published') {
            app(Workspace::class)->invalid('status', 'Analytics are only available for published posts.');
        }
        try {
            $totals = [];
            foreach ($p->receipts ?? [] as $id) {
                $metrics = $this->providers->metrics($p->account, $id);
                foreach ($metrics as $name => $value) {
                    $totals[$name] = ($value === null || (array_key_exists($name, $totals) && $totals[$name] === null)) ? null : ($totals[$name] ?? 0) + $value;
                }
            }
            $p->update(['metrics' => $totals, 'metrics_status' => count(array_filter($totals, fn ($value) => $value !== null)) ? 'available' : 'unavailable', 'metrics_refreshed_at' => now(), 'metrics_checked_at' => now()]);
        } catch (\Throwable $e) {
            $p->update(['metrics_status' => $e instanceof ProviderFailure && $e->outcome === 'unavailable' ? 'permission_required' : 'refresh_failed', 'metrics_checked_at' => now()]);
        }

        return $p;
    }

    public function refreshDueAnalytics(): void
    {
        if (config('sendae.mode') !== 'server') {
            return;
        }
        foreach (Publication::withoutGlobalScope('owner')->where('status', 'published')->get() as $p) {
            $age = $p->published_at->diffInDays(now());
            if (($age <= 7 && (! $p->metrics_checked_at || $p->metrics_checked_at->lte(now()->subDay()))) || ($age >= 30 && ! $p->day30_refreshed)) {
                app(WorkspaceOwner::class)->run($p->user_id, fn () => $this->analytics($p), $p->workspace_id);
                if ($age >= 30) {
                    $p->update(['day30_refreshed' => true]);
                }
            }
        }
    }
}
