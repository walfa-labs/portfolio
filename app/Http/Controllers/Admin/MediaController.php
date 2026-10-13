<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMediaRequest;
use App\Jobs\GenerateMediaVariants;
use App\Models\Media;
use enshrined\svgSanitize\Sanitizer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The admin media surface (§9.10).
 *
 * Uploads are standard Inertia form posts or fetch calls against `web` routes
 * — never `/api/v1` — guarded by the `media-upload` limiter and the
 * `MediaPolicy` check. The response is the §9.10 payload as JSON for fetch
 * uploads and a flash plus redirect for form posts.
 *
 * Validation lives in {@see StoreMediaRequest}, the same seam every other
 * admin resource uses: the request builds its rules from `config/media.php`,
 * so the allow-lists, size ceilings and the alt/decorative contract have
 * exactly one source of truth (§9.6), and it authorises through `MediaPolicy`
 * before the controller body runs.
 */
class MediaController extends Controller
{
    use AuthorizesRequests;

    /**
     * §9.5's format compatibility order, most compatible first.
     *
     * @var array<string, int>
     */
    private const FORMAT_RANK = [
        'image/jpeg' => 0,
        'image/jpg' => 0,
        'image/png' => 1,
        'image/webp' => 2,
        'image/avif' => 3,
    ];

    /**
     * `POST /admin/media` — store an upload and queue its variants (§9.10).
     *
     * The original is written to its content-addressed path and the row is
     * created inside one database transaction. For a single-cardinality
     * collection the previous row is deleted inside that same transaction, so
     * its `deleting` hook removes its objects while the new row is being
     * written (§9.1, pinned decision 13); for a `many` collection a re-upload
     * of the same bytes hits the unique index and returns the existing row
     * (§9.4).
     */
    public function store(StoreMediaRequest $request): JsonResponse|RedirectResponse
    {
        $collection = $request->collection();
        $mediableType = (string) $request->input('mediable_type');
        $mediableId = (int) $request->input('mediable_id');

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $diskName = $collection === 'cv' ? 's3-private' : 's3';
        $sha256 = hash_file('sha256', $file->getRealPath());
        $extension = $file->guessExtension() ?: 'bin';
        $path = $mediableType.'s/'.$sha256.'/original.'.$extension;

        $data = [
            'collection' => $collection,
            'mediable_type' => $mediableType,
            'mediable_id' => $mediableId,
            'alt' => $request->alt(),
        ];

        $media = DB::transaction(function () use ($data, $file, $diskName, $sha256, $path, $extension): Media {
            if ($this->isSingleCardinality($data['collection'])) {
                Media::query()
                    ->where('mediable_type', $data['mediable_type'])
                    ->where('mediable_id', $data['mediable_id'])
                    ->where('collection', $data['collection'])
                    ->get()
                    ->each(fn (Media $previous): bool => $previous->delete());
            } else {
                $existing = Media::query()
                    ->where('mediable_type', $data['mediable_type'])
                    ->where('mediable_id', $data['mediable_id'])
                    ->where('collection', $data['collection'])
                    ->where('sha256', $sha256)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $contents = (string) file_get_contents($file->getRealPath());

            /*
             * §9.6: SVG is accepted only for `logo`, and the uploaded bytes are
             * sanitised before storage — the unsanitised upload is discarded
             * and what is served is the sanitised copy. The original is the
             * master and is never modified (§9.5), so the job cannot do this;
             * it happens here, before the bytes reach the disk. Bytes that
             * cannot be sanitised are a rejection, not a stored object.
             */
            if ($file->getMimeType() === 'image/svg+xml') {
                $sanitised = (new Sanitizer)->sanitize($contents);

                if ($sanitised === false) {
                    throw ValidationException::withMessages([
                        'file' => 'The uploaded SVG could not be sanitised.',
                    ]);
                }

                $contents = $sanitised;
            }

            Storage::disk($diskName)->put($path, $contents);

            $dimensions = @getimagesize($file->getRealPath());

            return Media::create([
                'mediable_type' => $data['mediable_type'],
                'mediable_id' => $data['mediable_id'],
                'collection' => $data['collection'],
                'disk' => $diskName,
                'path' => $path,
                'original_name' => $file->getClientOriginalName() ?: 'original.'.$extension,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'alt' => $data['alt'],
                'size' => $file->getSize() ?: 0,
                'width' => $dimensions === false ? null : (int) $dimensions[0],
                'height' => $dimensions === false ? null : (int) $dimensions[1],
                'sha256' => $sha256,
                'variants' => null,
                'status' => 'pending',
                'sort_order' => 0,
            ]);
        });

        GenerateMediaVariants::dispatch($media);

        return $this->respond($media->refresh(), 'uploaded');
    }

    /**
     * `POST /admin/media/{media}/regenerate` — re-run the variant job (§9.10).
     *
     * The job re-reads the stored original, so a matrix change in
     * `config/media.php` is picked up without a new upload.
     */
    public function regenerate(Media $media): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $media);

        GenerateMediaVariants::dispatch($media);

        return $this->respond($media->refresh(), 'regenerated');
    }

    /**
     * `DELETE /admin/media/{media}` — delete one media row (§9.10).
     *
     * The row's `deleting` hook removes the original and every variant object,
     * honouring the shared-object rule (§9.4).
     */
    public function destroy(Media $media): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $media);

        $media->delete();

        if (request()->wantsJson()) {
            return response()->json(['id' => $media->getKey(), 'deleted' => true]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Media deleted.',
        ]);

        return redirect()->back();
    }

    /**
     * Whether a collection's upload replaces the previous row (§9.1).
     */
    private function isSingleCardinality(string $collection): bool
    {
        return (string) config("media.collections.{$collection}.cardinality", 'many') === 'single';
    }

    /**
     * The §9.10 dual response: JSON for fetch uploads, flash + redirect for
     * form posts.
     */
    private function respond(Media $media, string $action): JsonResponse|RedirectResponse
    {
        if (request()->wantsJson()) {
            return response()->json($this->payload($media));
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Media '.$action.'.',
        ]);

        return redirect()->back();
    }

    /**
     * The §9.10 admin payload for one media row.
     *
     * @return array<string, mixed>
     */
    private function payload(Media $media): array
    {
        return [
            'id' => $media->getKey(),
            'collection' => $media->collection,
            'disk' => $media->disk,
            'status' => $media->status,
            'url' => $this->primaryUrl($media),
            'alt' => $media->alt,
            'variants' => $this->variantPayloads($media),
        ];
    }

    /**
     * A media row's variants, keyed by variant name, with every URL resolved
     * through the app-mediated serving route (§9.7).
     *
     * @return array<string, array{url: string, width: int, height: int, mime: string}>
     */
    private function variantPayloads(Media $media): array
    {
        /** @var array<string, array<string, mixed>> $stored */
        $stored = is_array($media->variants) ? $media->variants : [];

        $variants = [];

        foreach ($stored as $key => $variant) {
            $variants[$key] = [
                'url' => route('media.show', ['media' => $media->getKey(), 'variant' => $key]),
                'width' => (int) ($variant['width'] ?? 0),
                'height' => (int) ($variant['height'] ?? 0),
                'mime' => (string) ($variant['mime'] ?? ''),
            ];
        }

        return $variants;
    }

    /**
     * The lone URL the admin payload carries (§9.10).
     *
     * The largest raster at the most broadly compatible format wins — the same
     * rule the API's `*_url` attributes use — and a collection with no
     * variant matrix falls back to the row's own object (§9.5).
     */
    private function primaryUrl(Media $media): string
    {
        $best = null;

        foreach ($this->variantPayloads($media) as $key => $variant) {
            if (str_starts_with($key, 'lqip')) {
                continue;
            }

            if ($best === null || $this->preferredOver($variant, $best)) {
                $best = $variant;
            }
        }

        return $best['url'] ?? $this->originalUrl($media);
    }

    /**
     * The served URL of a media row's own object (§9.5).
     */
    private function originalUrl(Media $media): string
    {
        $extension = pathinfo($media->path, PATHINFO_EXTENSION);

        return route('media.show', [
            'media' => $media->getKey(),
            'variant' => $extension === '' ? 'original' : 'original.'.$extension,
        ]);
    }

    /**
     * Whether one variant is the better lone URL than another.
     *
     * The larger raster wins; a tie in width goes to the more compatible
     * format, because two encodings of one size differ only in decodability.
     *
     * @param  array{url: string, width: int, height: int, mime: string}  $candidate
     * @param  array{url: string, width: int, height: int, mime: string}  $current
     */
    private function preferredOver(array $candidate, array $current): bool
    {
        if ($candidate['width'] !== $current['width']) {
            return $candidate['width'] > $current['width'];
        }

        return $this->formatRank($candidate['mime']) < $this->formatRank($current['mime']);
    }

    /**
     * §9.5's compatibility order: the fallback encoding first, then WebP,
     * then AVIF. Anything else ranks last.
     */
    private function formatRank(string $mime): int
    {
        return self::FORMAT_RANK[$mime] ?? 4;
    }
}
