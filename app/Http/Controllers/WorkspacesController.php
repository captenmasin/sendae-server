<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkspacesController extends Controller
{
    public function index(Request $request): array
    {
        return Workspace::where('user_id', $request->user()->id)->orderBy('created_at')->get()->map($this->present(...))->all();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json($this->present(Workspace::create($data + ['user_id' => $request->user()->id])), 201);
    }

    public function update(Request $request, string $workspace): array
    {
        $workspace = Workspace::where('user_id', $request->user()->id)->findOrFail($workspace);
        $workspace->update($this->validated($request));

        return $this->present($workspace);
    }

    public function destroy(Request $request, string $workspace): array
    {
        $files = DB::transaction(function () use ($request, $workspace): array {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
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

        return ['deleted' => true, 'workspace_id' => $request->user()->fresh()->workspace_id, 'workspaces' => $this->index($request)];
    }

    public function image(Request $request, string $workspace): BinaryFileResponse
    {
        $workspace = Workspace::where('user_id', $request->user()->id)->findOrFail($workspace);
        abort_unless($workspace->image && Storage::disk('local')->exists($workspace->image), 404);

        return response()->file(Storage::disk('local')->path($workspace->image), ['Content-Type' => Storage::disk('local')->mimeType($workspace->image) ?: 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function uploadImage(Request $request, string $workspace): array
    {
        $workspace = Workspace::where('user_id', $request->user()->id)->findOrFail($workspace);
        $file = $request->validate(['file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp|max:2048'])['file'];
        $path = $file->storeAs('workspace-images/'.$request->user()->id, $workspace->id.'.'.$file->guessExtension(), 'local');
        if ($workspace->image && $workspace->image !== $path) {
            Storage::disk('local')->delete($workspace->image);
        }
        $workspace->update(['image' => $path]);

        return $this->present($workspace->fresh());
    }

    public function destroyImage(Request $request, string $workspace): Response
    {
        $workspace = Workspace::where('user_id', $request->user()->id)->findOrFail($workspace);
        if ($workspace->image) {
            Storage::disk('local')->delete($workspace->image);
            $workspace->update(['image' => null]);
        }

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'icon' => 'nullable|string|max:32']);
        $data['icon'] = trim((string) ($data['icon'] ?? '')) ?: mb_strtoupper(Str::substr($data['name'], 0, 1));

        return $data;
    }

    private function present(Workspace $workspace): array
    {
        return ['id' => $workspace->id, 'name' => $workspace->name, 'icon' => $workspace->icon, 'has_image' => filled($workspace->image), 'created_at' => $workspace->created_at, 'updated_at' => $workspace->updated_at];
    }
}
