<?php

test('server returns json and has no application pages', function (): void {
    $this->get('/')->assertOk()->assertExactJson(['service' => 'Sendae API']);
    $this->get('/up')->assertOk()->assertExactJson(['status' => 'ok']);
    foreach (['/login', '/register', '/forgot-password', '/connected', '/local/csrf', '/local/state', '/connect/x'] as $path) {
        $this->get($path)->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }
    foreach (['/login', '/logout', '/register', '/forgot-password', '/reset-password', '/connections/select', '/local/drafts', '/local/media', '/local/schedule', '/local/cancel', '/local/recover', '/local/account', '/local/disconnect', '/local/analytics'] as $path) {
        $this->post($path)->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }
});

test('api authentication errors are json without accept header', function (): void {
    $this->get('/api/state')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
});
