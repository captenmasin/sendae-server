<?php

use App\Models\User;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Account;
use App\Models\Workspace;
use App\Models\Publication;
use Illuminate\Support\Str;
use App\Services\Attachments;
use Laravel\Passport\Passport;
use App\Services\WorkspaceOwner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function storedAttachment(): Media
{
    $id = (string) Str::uuid();
    $path = 'media/'.$id;
    Storage::disk('local')->put($path, '1234567890');

    return Media::create(['id' => $id, 'name' => 'image.png', 'mime' => 'image/png', 'size' => 10, 'path' => $path]);
}

test('storage endpoints require authentication and validate explicit candidates', function (): void {
    $this->getJson('/api/storage')->assertUnauthorized();
    $this->postJson('/api/reclaimStorage', ['ids' => []])->assertUnauthorized();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->postJson('/api/reclaimStorage', [])->assertUnprocessable()->assertJsonValidationErrors('ids');
    $this->postJson('/api/reclaimStorage', ['ids' => ['bad-id']])->assertUnprocessable()->assertJsonValidationErrors('ids.0');
});

test('cleanup protects every retained content reference', function (string $source): void {
    Storage::set('local', Storage::fake('storage-management-'.getmypid()));
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $media = storedAttachment();
    $item = ['text' => '', 'media_ids' => [$media->id]];
    $content = ['items' => [$item], 'overrides' => [], 'account_ids' => []];
    if ($source === 'override') {
        $content = ['items' => [['text' => 'Shared', 'media_ids' => []]], 'overrides' => ['x' => [$item]], 'account_ids' => []];
    }
    $draft = Draft::create(['title' => 'Retained', 'content' => $content]);
    if ($source === 'deleted draft') {
        $draft->delete();
    } elseif (! in_array($source, ['draft', 'override'])) {
        $draft->update(['content' => ['items' => [['text' => 'Changed', 'media_ids' => []]], 'overrides' => [], 'account_ids' => []]]);
        $account = Account::create(['name' => 'X', 'provider' => 'x', 'provider_id' => '1', 'timezone' => 'UTC', 'slots' => [], 'status' => 'connected']);
        Publication::create(['draft_id' => $draft->id, 'account_id' => $account->id, 'snapshot' => ['items' => [$item]], 'status' => $source, 'scheduled_at' => now()]);
    }

    $this->postJson('/api/reclaimStorage', ['ids' => [$media->id]])->assertOk()->assertJsonPath('deleted_ids', []);

    $this->assertModelExists($media);
    expect($media->fresh()->trashed())->toBeFalse();
    Storage::disk('local')->assertExists($media->path);
})->with(['draft', 'override', 'deleted draft', 'scheduled', 'retry', 'publishing', 'uncertain', 'published', 'cancelled', 'failed', 'missed']);

test('usage and deletion stay within the owner and selected workspace', function (): void {
    Storage::set('local', Storage::fake('storage-management-'.getmypid()));
    $owner = User::factory()->create();
    Passport::actingAs($owner, ['mcp:use']);
    $mine = storedAttachment();
    $otherWorkspace = Workspace::create(['user_id' => $owner->id, 'name' => 'Other']);
    $elsewhere = app(WorkspaceOwner::class)->run($owner->id, fn () => storedAttachment(), $otherWorkspace->id);
    $otherOwner = User::factory()->create();
    $theirs = app(WorkspaceOwner::class)->run($otherOwner->id, fn () => storedAttachment());

    $this->getJson('/api/storage')->assertExactJson(['used_bytes' => 10, 'limit_bytes' => config('sendae.storage_limit_bytes')]);
    $this->postJson('/api/reclaimStorage', ['ids' => [$mine->id, $elsewhere->id, $theirs->id]])->assertOk()->assertJsonPath('deleted_ids', [$mine->id])->assertJsonPath('storage.used_bytes', 0);

    Storage::disk('local')->assertMissing($mine->path);
    Storage::disk('local')->assertExists([$elsewhere->path, $theirs->path]);
});

test('legacy references outside the selected workspace still protect a file', function (): void {
    Storage::set('local', Storage::fake('storage-management-'.getmypid()));
    $owner = User::factory()->create();
    Passport::actingAs($owner, ['mcp:use']);
    $media = storedAttachment();
    $otherOwner = User::factory()->create();
    app(WorkspaceOwner::class)->run($otherOwner->id, fn () => Draft::create(['title' => 'Legacy reference', 'content' => ['items' => [['text' => '', 'media_ids' => [$media->id]]]]]));

    $this->postJson('/api/reclaimStorage', ['ids' => [$media->id]])->assertOk()->assertJsonPath('deleted_ids', []);
    Storage::disk('local')->assertExists($media->path);
});

test('cleanup retries interrupted file deletion and frees quota only after removal', function (): void {
    Storage::set('local', Storage::fake('storage-management-'.getmypid()));
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $media = storedAttachment();
    $disk = Storage::disk('local');
    $proxy = Mockery::mock($disk);
    $attempt = 0;
    $proxy->shouldReceive('delete')->andReturnUsing(function ($path) use ($disk, &$attempt) {
        return ++$attempt === 1 ? false : $disk->delete($path);
    });
    Storage::partialMock()->shouldReceive('disk')->with('local')->andReturn($proxy);

    $this->postJson('/api/reclaimStorage', ['ids' => [$media->id]])->assertServiceUnavailable();
    $this->getJson('/api/storage')->assertJsonPath('used_bytes', 10);
    $this->assertSoftDeleted($media);
    $this->postJson('/api/reclaimStorage', ['ids' => []])->assertOk()->assertJsonPath('deleted_ids', [$media->id])->assertJsonPath('storage.used_bytes', 0);
    $this->postJson('/api/reclaimStorage', ['ids' => [$media->id]])->assertOk()->assertJsonPath('deleted_ids', [$media->id]);
    $disk->assertMissing($media->path);
    $this->getJson('/api/state')->assertJsonPath('media', [])->assertJsonPath('deleted_media_ids', [$media->id]);
});

test('a stale draft cannot retain deleted or foreign media and an offline upload can restore a marker', function (): void {
    Storage::set('local', Storage::fake('storage-management-'.getmypid()));
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $media = storedAttachment();
    $payload = ['id' => (string) Str::uuid(), 'title' => '', 'version' => 0, 'content' => ['items' => [['text' => '', 'media_ids' => [$media->id]]], 'overrides' => [], 'account_ids' => []]];
    $this->postJson('/api/reclaimStorage', ['ids' => [$media->id]])->assertOk();

    $this->postJson('/api/drafts', $payload)->assertUnprocessable()->assertJsonValidationErrors('media');
    $this->assertDatabaseMissing('drafts', ['id' => $payload['id']]);
    $this->getJson('/api/media/'.$media->id)->assertNotFound();
    $restored = app(Attachments::class)->store(UploadedFile::fake()->image('offline.png'), $media->id);
    expect($restored->trashed())->toBeFalse();
    $this->postJson('/api/drafts', $payload)->assertOk();
    $this->postJson('/api/reclaimStorage', ['ids' => [$media->id]])->assertJsonPath('deleted_ids', []);
    Storage::disk('local')->assertExists($restored->path);
});

test('removing unused attachments makes room for an upload at the enforced quota', function (): void {
    Storage::set('local', Storage::fake('storage-management-'.getmypid()));
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $unused = storedAttachment();
    $file = UploadedFile::fake()->image('next.png');
    config(['sendae.storage_limit_bytes' => $file->getSize()]);

    $this->postJson('/api/media', ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->postJson('/api/reclaimStorage', ['ids' => [$unused->id]])->assertOk();
    $this->postJson('/api/media', ['file' => $file])->assertCreated();
    $this->getJson('/api/storage')->assertJsonPath('used_bytes', $file->getSize());
});

test('save receipts cannot bring back references to removed media', function (): void {
    Storage::set('local', Storage::fake('storage-management-'.getmypid()));
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $media = storedAttachment();
    $payload = ['id' => (string) Str::uuid(), 'title' => '', 'version' => 0, 'content' => ['items' => [['text' => '', 'media_ids' => [$media->id]]], 'overrides' => [], 'account_ids' => []]];
    $this->postJson('/api/drafts', $payload)->assertOk();
    $next = $payload;
    $next['version'] = 1;
    $next['content']['items'][0] = ['text' => 'No attachment', 'media_ids' => []];
    $this->postJson('/api/drafts', $next)->assertOk();
    $this->postJson('/api/reclaimStorage', ['ids' => [$media->id]])->assertOk()->assertJsonPath('deleted_ids', [$media->id]);

    $this->postJson('/api/drafts', $payload)->assertUnprocessable()->assertJsonValidationErrors('media');
    expect(Draft::find($payload['id'])->content['items'][0]['media_ids'])->toBe([]);
});

test('saving a draft cannot retain attachments from another owner or workspace', function (): void {
    Storage::set('local', Storage::fake('storage-management-'.getmypid()));
    $owner = User::factory()->create();
    Passport::actingAs($owner, ['mcp:use']);
    $workspace = Workspace::create(['user_id' => $owner->id, 'name' => 'Other']);
    $otherWorkspaceMedia = app(WorkspaceOwner::class)->run($owner->id, fn () => storedAttachment(), $workspace->id);
    $otherOwner = User::factory()->create();
    $otherOwnerMedia = app(WorkspaceOwner::class)->run($otherOwner->id, fn () => storedAttachment());

    foreach ([$otherWorkspaceMedia, $otherOwnerMedia] as $media) {
        $this->postJson('/api/drafts', ['id' => (string) Str::uuid(), 'title' => '', 'version' => 0, 'content' => ['items' => [['text' => '', 'media_ids' => [$media->id]]], 'overrides' => [], 'account_ids' => []]])
            ->assertUnprocessable()->assertJsonValidationErrors('media');
        Storage::disk('local')->assertExists($media->path);
    }
    $this->assertDatabaseCount('drafts', 0);
});
