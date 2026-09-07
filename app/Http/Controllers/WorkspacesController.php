<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class WorkspacesController extends Controller
{
    public function index(Request $request): Collection
    {
        return Workspace::where('user_id', $request->user()->id)->orderBy('created_at')->get();
    }

    public function store(Request $request): Workspace
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'icon' => 'required|string|max:32']);

        return Workspace::create($data + ['user_id' => $request->user()->id]);
    }

    public function update(Request $request, string $workspace): Workspace
    {
        $workspace = Workspace::where('user_id', $request->user()->id)->findOrFail($workspace);
        $workspace->update($request->validate(['name' => 'required|string|max:100', 'icon' => 'required|string|max:32']));

        return $workspace;
    }
}
