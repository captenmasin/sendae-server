<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Account;
use App\Models\Publication;
use App\Services\Publisher;
use App\Services\Workspace;
use Illuminate\Http\Request;
use App\Services\Attachments;
use Illuminate\Validation\Rule;
use App\Services\WorkspaceOwner;
use Illuminate\Support\Facades\Storage;

class WorkspaceController extends Controller
{
    public function state(Workspace $workspace)
    {
        return $workspace->state();
    }

    public function save(Request $r, Workspace $w)
    {
        return $w->save($r->all(), true);
    }

    public function deleteDraft(Request $request, Workspace $workspace): array
    {
        return $workspace->deleteDraft($request->all());
    }

    public function storage(Attachments $attachments): array
    {
        return $attachments->usage();
    }

    public function reclaimStorage(Request $request, Attachments $attachments): array
    {
        return $attachments->reclaim($request->all());
    }

    public function upload(Request $r, Attachments $a)
    {
        $r->validate(['file' => 'required|file', 'id' => 'nullable|uuid']);

        return $a->store($r->file('file'), $r->input('id'));
    }

    public function media(Media $media)
    {
        abort_unless($media->path && Storage::disk('local')->exists($media->path), 404);

        return response()->file(Storage::disk('local')->path($media->path), ['Content-Type' => $media->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function publicMedia(string $media)
    {
        return $this->media(Media::withoutGlobalScope('owner')->findOrFail($media));
    }

    public function schedule(Request $r, Workspace $w)
    {
        return $w->schedule($r->all());
    }

    public function schedulePreview(Request $request, Workspace $workspace): array
    {
        return $workspace->previewSchedule($request->all());
    }

    public function cancel(Request $r, Workspace $w)
    {
        $r->validate(['id' => 'required|uuid', 'separate' => 'sometimes|boolean']);

        return $w->cancel($r->id, $r->boolean('separate'));
    }

    public function deletePublication(Request $request, Workspace $workspace): array
    {
        $data = $request->validate(['id' => 'required|uuid']);

        return $workspace->deletePublication($data['id']);
    }

    public function recover(Request $r, Workspace $w)
    {
        return $w->recover($r->all());
    }

    public function account(Request $request, Workspace $workspace): Account
    {
        return $workspace->savePostingSlots($request->all());
    }

    public function disconnect(Request $r)
    {
        return app(Workspace::class)->disconnectAccount($r->all());
    }

    public function analytics(Request $r)
    {
        $r->validate(['id' => ['required', 'uuid', Rule::exists('publications', 'id')->where('user_id', app(WorkspaceOwner::class)->requireId())->where('workspace_id', app(WorkspaceOwner::class)->workspaceId())]]);

        return app(Publisher::class)->analytics(Publication::findOrFail($r->id));
    }
}
