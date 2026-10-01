<?php

use App\Models\User;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Hash;

test('signed in user can update name without current password', function (): void {
    $user = User::factory()->create(['name' => 'Old Name', 'email' => 'person@example.com']);
    Passport::actingAs($user, ['mcp:use']);

    $this->patchJson('/api/profile', ['name' => 'New Name', 'email' => 'person@example.com'])
        ->assertOk()
        ->assertJsonPath('name', 'New Name')
        ->assertJsonPath('email', 'person@example.com');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name', 'email' => 'person@example.com']);
    $this->assertTrue(Hash::check('password', $user->fresh()->getAuthPassword()));
});

test('email and password updates require the current password and persist', function (): void {
    $user = User::factory()->create(['name' => 'Person', 'email' => 'person@example.com']);
    Passport::actingAs($user, ['mcp:use']);

    $this->patchJson('/api/profile', ['name' => 'Person', 'email' => 'new@example.com'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->patchJson('/api/profile', [
        'name' => 'Person',
        'email' => 'person@example.com',
        'password' => 'new-secret',
        'password_confirmation' => 'new-secret',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->patchJson('/api/profile', [
        'name' => 'Person',
        'email' => 'NEW@example.com',
        'current_password' => 'wrong',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $this->patchJson('/api/profile', [
        'name' => 'Updated Person',
        'email' => 'NEW@example.com',
        'password' => 'new-secret',
        'password_confirmation' => 'new-secret',
        'current_password' => 'password',
    ])->assertOk()->assertJsonPath('name', 'Updated Person')->assertJsonPath('email', 'new@example.com');

    $user->refresh();
    $this->assertSame('Updated Person', $user->name);
    $this->assertSame('new@example.com', $user->email);
    $this->assertTrue(Hash::check('new-secret', $user->getAuthPassword()));
});

test('profile updates are validated and stay on the signed in account', function (): void {
    $user = User::factory()->create(['email' => 'person@example.com']);
    User::factory()->create(['email' => 'taken@example.com']);
    Passport::actingAs($user, ['mcp:use']);

    $this->patchJson('/api/profile', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
    $this->patchJson('/api/profile', ['name' => 'Person', 'email' => 'taken@example.com', 'current_password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->patchJson('/api/profile', ['name' => 'Person', 'email' => 'person@example.com', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->app['auth']->forgetGuards();
    $this->patchJson('/api/profile', ['name' => 'Person', 'email' => 'person@example.com'])->assertUnauthorized();
    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'person@example.com']);
});

test('social accounts cannot bypass password verification through profile updates', function (): void {
    $user = User::factory()->create(['has_password' => false]);
    Passport::actingAs($user, ['mcp:use']);

    $this->patchJson('/api/profile', ['name' => $user->name, 'email' => $user->email, 'password' => 'new-secret', 'password_confirmation' => 'new-secret'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->assertFalse($user->fresh()->has_password);
    $this->assertFalse(Hash::check('new-secret', $user->fresh()->password));
});
