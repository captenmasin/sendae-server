<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_in_user_can_update_name_without_current_password(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'person@example.com']);
        Passport::actingAs($user, ['mcp:use']);

        $this->patchJson('/api/profile', ['name' => 'New Name', 'email' => 'person@example.com'])
            ->assertOk()
            ->assertJsonPath('name', 'New Name')
            ->assertJsonPath('email', 'person@example.com');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name', 'email' => 'person@example.com']);
        $this->assertTrue(Hash::check('password', $user->fresh()->getAuthPassword()));
    }

    public function test_email_and_password_updates_require_the_current_password_and_persist(): void
    {
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
    }

    public function test_profile_updates_are_validated_and_stay_on_the_signed_in_account(): void
    {
        $user = User::factory()->create(['email' => 'person@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);
        Passport::actingAs($user, ['mcp:use']);

        $this->patchJson('/api/profile', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
        $this->patchJson('/api/profile', ['name' => 'Person', 'email' => 'taken@example.com', 'current_password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson('/api/profile', ['name' => 'Person', 'email' => 'person@example.com', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/profile', ['name' => 'Person', 'email' => 'person@example.com'])->assertUnauthorized();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'person@example.com']);
    }
}
