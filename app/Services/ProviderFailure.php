<?php

namespace App\Services;

class ProviderFailure extends \RuntimeException
{
    public function __construct(string $message, public string $outcome = 'failed', public int $retryAfter = 300)
    {
        parent::__construct($message);
    }
}
