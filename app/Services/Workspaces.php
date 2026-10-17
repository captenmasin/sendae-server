<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Workspaces
{
    public function all(): array
    {
        return Workspace::where('user_id', app(WorkspaceOwner::class)->requireId())->orderBy('created_at')->get()->map($this->present(...))->all();
    }

    public function save(array $data): array
    {
        $data = Validator::make($data, ['id' => 'sometimes|required|string|size:64', 'name' => 'required|string|max:100', 'icon' => 'nullable|string|max:32'])->validate();
        $data['icon'] = trim((string) ($data['icon'] ?? '')) ?: mb_strtoupper(Str::substr($data['name'], 0, 1));
        $owner = app(WorkspaceOwner::class)->requireId();
        if (isset($data['id'])) {
            $workspace = Workspace::where('user_id', $owner)->findOrFail($data['id']);
            $workspace->update(collect($data)->except('id')->all());
        } else {
            $workspace = Workspace::create($data + ['user_id' => $owner]);
        }

        return $this->present($workspace);
    }

    public function delete(string $workspace): array
    {
        $files = DB::transaction(function () use ($workspace): array {
            $user = User::lockForUpdate()->findOrFail(app(WorkspaceOwner::class)->requireId());
            $workspace = Workspace::where('user_id', $user->id)->lockForUpdate()->findOrFail($workspace);
            $remaining = Workspace::where('user_id', $user->id)->whereKeyNot($workspace->id)->orderBy('created_at')->first();
            abort_unless($remaining, 422, 'Create another workspace before deleting your only workspace.');
            $publications = DB::table('publications')->where('workspace_id', $workspace->id)->lockForUpdate()->get(['status']);
            abort_if($publications->contains(fn (object $publication): bool => in_array($publication->status, ['publishing', 'uncertain'], true)), 409, 'Wait for publishing to finish and resolve uncertain posts before deleting this workspace.');
            $files = DB::table('media')->where('workspace_id', $workspace->id)->whereNotNull('path')->pluck('path')->all();
            if ($workspace->image) {
                $files[] = $workspace->image;
            }
            foreach (['publications', 'drafts', 'accounts', 'media'] as $table) {
                DB::table($table)->where('workspace_id', $workspace->id)->delete();
            }
            if ($user->workspace_id === $workspace->id) {
                $user->workspace_id = $remaining->id;
                $user->save();
            }
            $workspace->delete();

            return $files;
        });
        Storage::disk('local')->delete($files);

        return ['deleted' => true, 'workspace_id' => User::findOrFail(app(WorkspaceOwner::class)->requireId())->workspace_id, 'workspaces' => $this->all()];
    }

    public function image(string $workspace): BinaryFileResponse
    {
        $workspace = Workspace::where('user_id', app(WorkspaceOwner::class)->requireId())->findOrFail($workspace);
        abort_unless($workspace->image && Storage::disk('local')->exists($workspace->image), 404);

        return response()->file(Storage::disk('local')->path($workspace->image), ['Content-Type' => Storage::disk('local')->mimeType($workspace->image) ?: 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function saveImage(string $workspace, UploadedFile $file): array
    {
        $workspace = Workspace::where('user_id', app(WorkspaceOwner::class)->requireId())->findOrFail($workspace);
        Validator::make(['file' => $file], ['file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp|max:2048'])->validate();
        $path = $file->storeAs('workspace-images/'.app(WorkspaceOwner::class)->requireId(), $workspace->id.'.'.$file->guessExtension(), 'local');
        if ($workspace->image && $workspace->image !== $path) {
            Storage::disk('local')->delete($workspace->image);
        }
        $workspace->update(['image' => $path]);

        return $this->present($workspace->fresh());
    }

    public function deleteImage(string $workspace): array
    {
        $workspace = Workspace::where('user_id', app(WorkspaceOwner::class)->requireId())->findOrFail($workspace);
        if ($workspace->image) {
            Storage::disk('local')->delete($workspace->image);
            $workspace->update(['image' => null]);
        }

        return ['deleted' => true];
    }

    private function present(Workspace $workspace): array
    {
        return ['id' => $workspace->id, 'name' => $workspace->name, 'icon' => $workspace->icon, 'has_image' => filled($workspace->image), 'created_at' => $workspace->created_at, 'updated_at' => $workspace->updated_at];
    }
}
