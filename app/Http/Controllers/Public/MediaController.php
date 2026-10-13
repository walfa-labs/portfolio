<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The app-mediated media byte route (§9.7).
 *
 * Every public object is streamed through this one route — no direct bucket
 * URL, no presigned URL — so the disk abstraction stays in the application and
 * the response carries the `immutable` cache header that is only safe because
 * the paths are content-addressed (§9.4, §9.7).
 *
 * `{variant}` is the full variant key including its extension, e.g.
 * `cover_800x450.webp` — the same keys `config/media.php` generates and the
 * API resources expose. An exact miss falls back through the formats of the
 * same size (avif → webp → jpg → png) and answers 404 only when no variant of
 * that size exists (pinned decision 3).
 *
 * Statuses: 404 for an unknown row or an unresolvable variant, 405 with
 * `Allow` for a disallowed verb (the router's own answer), 429 past the
 * `media-download` limiter, and 500 only for an unexpected failure — nothing
 * here catches broadly enough to turn one into a 404.
 */
class MediaController extends Controller
{
    /**
     * The format fallback order for a missing variant key (§9.5).
     *
     * @var array<int, string>
     */
    private const FORMAT_PREFERENCE = ['avif', 'webp', 'jpg', 'png'];

    /**
     * `GET /media/{media}/{variant}` — stream one variant (§9.7).
     */
    public function show(Media $media, string $variant): StreamedResponse
    {
        $resolved = $this->resolveVariant($media, $variant);

        abort_if($resolved === null, 404);

        $disk = Storage::disk($media->disk);

        abort_unless($disk->exists($resolved['path']), 404);

        $stream = $disk->readStream($resolved['path']);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $resolved['mime'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Resolve a requested variant key to its stored object.
     *
     * The exact key wins; otherwise the formats of the same size are tried in
     * §9.5's preference order. A collection with no variant matrix — `pdf`,
     * `cv`, an SVG `logo` — is addressed by its own basename, so
     * `original.{ext}` resolves against the row's `path` (§9.5); originals of
     * rows that have not reached `processed` are never served.
     *
     * @return array{path: string, mime: string}|null
     */
    private function resolveVariant(Media $media, string $variant): ?array
    {
        /** @var array<string, array<string, mixed>> $stored */
        $stored = is_array($media->variants) ? $media->variants : [];

        if (isset($stored[$variant])) {
            return $this->variantObject($stored[$variant]);
        }

        if ($media->status === 'processed' && $this->isOriginalKey($variant)) {
            return [
                'path' => $media->path,
                'mime' => $media->mime_type,
            ];
        }

        $stem = $this->variantStem($variant);

        foreach (self::FORMAT_PREFERENCE as $format) {
            $candidate = $stem.'.'.$format;

            if (isset($stored[$candidate])) {
                return $this->variantObject($stored[$candidate]);
            }
        }

        return null;
    }

    /**
     * The servable object one stored variant entry describes.
     *
     * @param  array<string, mixed>  $variant
     * @return array{path: string, mime: string}
     */
    private function variantObject(array $variant): array
    {
        return [
            'path' => (string) ($variant['path'] ?? ''),
            'mime' => (string) ($variant['mime'] ?? ''),
        ];
    }

    /**
     * Whether a variant key addresses the row's own original object (§9.5).
     */
    private function isOriginalKey(string $variant): bool
    {
        return $variant === 'original' || str_starts_with($variant, 'original.');
    }

    /**
     * The variant key without its extension, e.g. `cover_800x450`.
     */
    private function variantStem(string $variant): string
    {
        $position = strrpos($variant, '.');

        return $position === false ? $variant : substr($variant, 0, $position);
    }
}
