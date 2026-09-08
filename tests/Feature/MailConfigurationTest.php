<?php

namespace Tests\Feature;

use Illuminate\Mail\Transport\CloudflareTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailConfigurationTest extends TestCase
{
    public function test_cloudflare_configuration_resolves_the_builtin_mail_transport(): void
    {
        config([
            'mail.default' => 'cloudflare',
            'services.cloudflare.account_id' => 'test-account',
            'services.cloudflare.key' => 'test-token',
        ]);

        $this->assertInstanceOf(CloudflareTransport::class, Mail::mailer()->getSymfonyTransport());
    }
}
