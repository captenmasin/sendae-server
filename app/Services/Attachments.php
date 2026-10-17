<?php

namespace App\Services;

use App\Models\User;
use App\Models\Draft;
use App\Models\Media;
use App\Models\Publication;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class Attachments
{
    public function store(UploadedFile $file, ?string $id = null): Media
    {
        Validator::make(['file' => $file, 'id' => $id], ['file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime|max:102400', 'id' => 'nullable|uuid'])->validate();

        return DB::transaction(function () use ($file, $id) {
            User::lockForUpdate()->findOrFail(app(WorkspaceOwner::class)->requireId());
            $id ??= (string) Str::uuid();
            $existing = Media::withTrashed()->find($id);
            if ($existing && ! $existing->trashed()) {
                return $existing;
            }
            if ($this->usage()['used_bytes'] - ($existing?->path ? $existing->size : 0) + $file->getSize() > config('sendae.storage_limit_bytes')) {
                app(Workspace::class)->invalid('file', 'Your workspace attachment storage limit has been reached.');
            }
            abort_if(! $existing && Media::withTrashed()->withoutGlobalScope('owner')->whereKey($id)->exists(), 404);
            if ($existing?->path) {
                abort_unless(Storage::disk('local')->delete($existing->path), 503, 'The attachment could not be replaced. Try again.');
            }
            $path = $file->storeAs('media/'.app(WorkspaceOwner::class)->workspaceId(), $id.'.'.$file->guessExtension(), 'local');
            try {
                if ($existing) {
                    $existing->fill(['name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'path' => $path, 'deleted_at' => null])->save();

                    return $existing;
                }

                return Media::create(['id' => $id, 'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'path' => $path]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });
    }

    /** @return array{used_bytes: int, limit_bytes: int} */
    public function usage(): array
    {
        return ['used_bytes' => (int) Media::withTrashed()->whereNotNull('path')->sum('size'), 'limit_bytes' => (int) config('sendae.storage_limit_bytes')];
    }

    /** @return list<string> */
    public function mediaIds(array $content): array
    {
        $ids = [];
        foreach ($content as $key => $value) {
            if ($key === 'media_ids' && is_array($value)) {
                $ids = array_merge($ids, $value);
            } elseif (is_array($value)) {
                $ids = array_merge($ids, $this->mediaIds($value));
            }
        }

        return array_values(array_unique($ids));
    }

    public function validateContent(array $content): void
    {
        $ids = $this->mediaIds(['items' => $content['items'] ?? [], 'overrides' => $content['overrides'] ?? []]);
        $media = Media::whereIn('id', $ids)->get();
        if ($media->count() !== count($ids) || $media->contains(fn (Media $file): bool => ! $file->path || ! Storage::disk('local')->exists($file->path))) {
            app(Workspace::class)->invalid('media', 'Some attachments have not synchronized. Sync and try again.');
        }
    }

    /** @return list<string> */
    private function referencedIds(array $candidates): array
    {
        $ids = [];
        foreach (Draft::withoutGlobalScope('owner')->withTrashed()->select('content')->cursor() as $draft) {
            $ids = array_merge($ids, array_intersect($this->mediaIds($draft->content), $candidates));
        }
        foreach (Publication::withoutGlobalScope('owner')->select('snapshot')->cursor() as $publication) {
            $ids = array_merge($ids, array_intersect($this->mediaIds($publication->snapshot), $candidates));
        }

        return array_values(array_unique($ids));
    }

    public function reclaim(array $data): array
    {
        $data = Validator::make($data, ['ids' => 'present|array|max:100', 'ids.*' => 'required|uuid|distinct'])->validate();
        DB::transaction(function () use ($data): void {
            User::lockForUpdate()->findOrFail(app(WorkspaceOwner::class)->requireId());
            Media::whereIn('id', $data['ids'])->whereNotIn('id', $this->referencedIds($data['ids']))->delete();
        });

        // Commit deletion markers before touching files; retries finish interrupted physical deletion.
        return DB::transaction(function () use ($data): array {
            User::lockForUpdate()->findOrFail(app(WorkspaceOwner::class)->requireId());
            $deleted = [];
            foreach (Media::onlyTrashed()->where(fn ($query) => $query->whereIn('id', $data['ids'])->orWhereNotNull('path'))->get() as $media) {
                if ($media->path) {
                    abort_unless(Storage::disk('local')->delete($media->path), 503, 'Unused attachments could not be removed. Try again.');
                    $media->update(['path' => null]);
                }
                $deleted[] = $media->id;
            }

            return ['deleted_ids' => $deleted, 'storage' => $this->usage()];
        });
    }

    public function base64(array $data): Media
    {
        return $this->withBase64File($data, fn (UploadedFile $file): Media => $this->store($file));
    }

    public function withBase64File(array $data, callable $operation, int $maxKilobytes = 102400): mixed
    {
        $maxEncodedLength = (int) ceil($maxKilobytes * 1024 / 3) * 4;
        Validator::make($data, ['name' => 'required|string|max:200', 'base64' => 'required|string|max:'.$maxEncodedLength])->validate();
        $bytes = base64_decode($data['base64'], true);
        abort_if($bytes === false, 422, 'Invalid base64 attachment.');
        $path = tempnam(sys_get_temp_dir(), 'sendae-');
        abort_if($path === false, 503, 'Temporary storage is unavailable.');
        try {
            abort_if(file_put_contents($path, $bytes) === false, 503, 'The attachment could not be stored.');

            return $operation(new UploadedFile($path, $data['name'], null, null, true));
        } finally {
            @unlink($path);
        }
    }
}
