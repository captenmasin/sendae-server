<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class WorkspaceImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_images_are_stored_served_and_removed_for_the_owner_only(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $other = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);
        $file = UploadedFile::fake()->image('mark.png', 80, 80);

        $this->post('/api/workspaces/'.$user->workspace_id.'/image', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('id', $user->workspace_id)
            ->assertJsonPath('has_image', true)
            ->assertJsonMissingPath('image');
        $this->assertNotNull(Workspace::find($user->workspace_id)->image);
        $this->get('/api/workspaces/'.$user->workspace_id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/workspaces')->assertJsonPath('0.has_image', true);
        $this->getJson('/api/state')->assertOk()->assertJsonPath('settings.workspaces.0.has_image', true)->assertJsonMissingPath('settings.workspaces.0.image');

        Passport::actingAs($other, ['mcp:use']);
        $this->get('/api/workspaces/'.$user->workspace_id.'/image')->assertNotFound();
        $this->post('/api/workspaces/'.$user->workspace_id.'/image', ['file' => UploadedFile::fake()->image('stolen.jpg')])->assertNotFound();
        $this->deleteJson('/api/workspaces/'.$user->workspace_id.'/image')->assertNotFound();

        Passport::actingAs($user, ['mcp:use']);
        $this->deleteJson('/api/workspaces/'.$user->workspace_id.'/image')->assertNoContent();
        $this->assertNull(Workspace::find($user->workspace_id)->image);
        $this->getJson('/api/workspaces/'.$user->workspace_id.'/image')->assertNotFound();
        $this->getJson('/api/workspaces')->assertJsonPath('0.has_image', false);
        $this->getJson('/api/state')->assertJsonPath('settings.workspaces.0.has_image', false);
    }

    public function test_new_workspaces_default_the_icon_to_the_first_letter_and_reject_non_images(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Passport::actingAs($user, ['mcp:use']);

        $this->postJson('/api/workspaces', ['name' => 'Novogamer'])->assertCreated()->assertJsonPath('icon', 'N')->assertJsonPath('has_image', false);
        $this->assertDatabaseHas('workspaces', ['name' => 'Personal', 'icon' => 'P']);
        $this->post('/api/workspaces/'.$user->workspace_id.'/image', ['file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertNull(Workspace::find($user->workspace_id)->image);
    }
}
