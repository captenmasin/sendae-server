<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Transport\CloudflareTransport;

test('cloudflare configuration resolves the builtin mail transport', function (): void {
    config([
        'mail.default' => 'cloudflare',
        'services.cloudflare.account_id' => 'test-account',
        'services.cloudflare.key' => 'test-token',
    ]);

    $this->assertInstanceOf(CloudflareTransport::class, Mail::mailer()->getSymfonyTransport());
});
