<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiBoundaryTest extends TestCase
{
    public function test_server_returns_json_and_has_no_application_pages(): void
    {
        $this->get('/')->assertOk()->assertExactJson(['service' => 'Sendae API']);
        $this->get('/up')->assertOk()->assertExactJson(['status' => 'ok']);
        foreach (['/login', '/register', '/forgot-password', '/connected', '/local/csrf', '/local/state', '/connect/x'] as $path) {
            $this->get($path)->assertNotFound()->assertHeader('Content-Type', 'application/json');
        }
        foreach (['/login', '/logout', '/register', '/forgot-password', '/reset-password', '/connections/select', '/local/drafts', '/local/media', '/local/schedule', '/local/cancel', '/local/recover', '/local/account', '/local/disconnect', '/local/analytics'] as $path) {
            $this->post($path)->assertNotFound()->assertHeader('Content-Type', 'application/json');
        }
    }

    public function test_api_authentication_errors_are_json_without_accept_header(): void
    {
        $this->get('/api/state')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }
}
