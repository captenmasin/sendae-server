<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Media;
use App\Models\Publication;
use App\Services\Attachments;
use App\Services\Publisher;
use App\Services\Workspace;
use App\Services\WorkspaceOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

    public function cancel(Request $r, Workspace $w)
    {
        $r->validate(['id' => 'required|uuid']);

        return $w->cancel($r->id);
    }

    public function recover(Request $r, Workspace $w)
    {
        return $w->recover($r->all());
    }

    public function account(Request $r)
    {
        $data = $r->validate(['id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('user_id', app(WorkspaceOwner::class)->requireId())->where('workspace_id', app(WorkspaceOwner::class)->workspaceId())], 'timezone' => 'required|timezone', 'slots' => 'present|array|max:100', 'slots.*.day' => 'required|integer|between:0,6', 'slots.*.time' => 'required|date_format:H:i']);
        $account = Account::findOrFail($data['id']);
        $account->update(collect($data)->except('id')->all());

        return $account;
    }

    public function disconnect(Request $r)
    {
        $r->validate(['id' => ['required', 'uuid', Rule::exists('accounts', 'id')->where('user_id', app(WorkspaceOwner::class)->requireId())->where('workspace_id', app(WorkspaceOwner::class)->workspaceId())]]);
        $account = Account::findOrFail($r->id);
        $account->update(['status' => 'disconnected', 'credentials' => null]);
        Publication::where('account_id', $account->id)->whereIn('status', ['scheduled', 'retry'])->update(['status' => 'cancelled', 'error' => 'Account disconnected.']);

        return ['disconnected' => true];
    }

    public function analytics(Request $r)
    {
        $r->validate(['id' => ['required', 'uuid', Rule::exists('publications', 'id')->where('user_id', app(WorkspaceOwner::class)->requireId())->where('workspace_id', app(WorkspaceOwner::class)->workspaceId())]]);

        return app(Publisher::class)->analytics(Publication::findOrFail($r->id));
    }
}
