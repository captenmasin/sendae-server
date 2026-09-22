<?php

namespace App\Jobs;

use App\Services\Publisher;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class PublishAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    public function __construct(public string $accountId) {}

    public function uniqueId(): string
    {
        return $this->accountId;
    }

    public function handle(Publisher $publisher): void
    {
        $publisher->publishAccount($this->accountId);
    }
}
