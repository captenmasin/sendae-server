<?php

namespace App\Http\Controllers;

use App\Services\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkspacesController extends Controller
{
    public function index(Workspaces $workspaces): array
    {
        return $workspaces->all();
    }

    public function store(Request $request, Workspaces $workspaces): JsonResponse
    {
        return response()->json($workspaces->save($request->only(['name', 'icon'])), 201);
    }

    public function update(Request $request, string $workspace, Workspaces $workspaces): array
    {
        return $workspaces->save([...$request->only(['name', 'icon']), 'id' => $workspace]);
    }

    public function destroy(string $workspace, Workspaces $workspaces): array
    {
        return $workspaces->delete($workspace);
    }

    public function image(string $workspace, Workspaces $workspaces): BinaryFileResponse
    {
        return $workspaces->image($workspace);
    }

    public function uploadImage(Request $request, string $workspace, Workspaces $workspaces): array
    {
        $request->validate(['file' => 'required|file']);

        return $workspaces->saveImage($workspace, $request->file('file'));
    }

    public function destroyImage(string $workspace, Workspaces $workspaces): Response
    {
        $workspaces->deleteImage($workspace);

        return response()->noContent();
    }
}
