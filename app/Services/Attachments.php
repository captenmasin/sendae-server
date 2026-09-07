<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class Attachments
{
    public function store(UploadedFile $file, ?string $id = null): Media
    {
        Validator::make(['file' => $file, 'id' => $id], ['file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime|max:102400', 'id' => 'nullable|uuid'])->validate();

        return DB::transaction(function () use ($file, $id) {
            User::lockForUpdate()->findOrFail(app(WorkspaceOwner::class)->requireId());
            $id ??= (string) Str::uuid();
            if ($existing = Media::find($id)) {
                return $existing;
            }
            if (Media::sum('size') + $file->getSize() > config('sendae.storage_limit_bytes')) {
                app(Workspace::class)->invalid('file', 'Your workspace attachment storage limit has been reached.');
            }
            abort_if(Media::withoutGlobalScope('owner')->whereKey($id)->exists(), 404);
            $path = $file->storeAs('media/'.app(WorkspaceOwner::class)->workspaceId(), $id.'.'.$file->guessExtension(), 'local');
            try {
                return Media::create(['id' => $id, 'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'path' => $path]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });
    }

    public function base64(array $data): Media
    {
        Validator::make($data, ['name' => 'required|string|max:200', 'base64' => 'required|string|max:140000000'])->validate();
        $bytes = base64_decode($data['base64'], true);
        abort_if($bytes === false, 422, 'Invalid base64 attachment.');
        $path = tempnam(sys_get_temp_dir(), 'sendae-');
        try {
            file_put_contents($path, $bytes);

            return $this->store(new UploadedFile($path, $data['name'], null, null, true));
        } finally {
            @unlink($path);
        }
    }
}
