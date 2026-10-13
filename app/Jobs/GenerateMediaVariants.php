<?php

namespace App\Jobs;

use App\Enums\ContentType;
use App\Models\Media;
use finfo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;
use Throwable;

/**
 * Generate the variant matrix for a stored original (§9.5).
 *
 * The job runs on the `media` queue. The queue is set through `onQueue()` in
 * the constructor rather than a `$queue` property: `Illuminate\Bus\Queueable`
 * already declares `$queue`, and redeclaring it in the composing class is a
 * fatal error ("define the same property … considered incompatible").
 *
 * Steps, in order (§9.5): (1) copy the original to a local temp file; (2)
 * magic-byte re-check against the collection allow-list; (3) re-orient and
 * apply each variant's fit; (4) encode each format with an explicit encoder,
 * skipping a format whose encoder is unavailable; (5) upload each variant to
 * the row's disk; (6) write the `variants` JSON and `status = processed`.
 *
 * The original is the master and is never modified or served. Variants are
 * re-encoded from decoded pixels and every encoder is asked to strip metadata
 * (`strip: true`), so no variant carries EXIF, IPTC or XMP. The flag is not
 * cosmetic: the GD encoders serialise pixels only, but the Imagick ones copy
 * the source's profiles through unless asked otherwise, because
 * `Intervention\Image\Config::$strip` defaults to `false`.
 */
class GenerateMediaVariants implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job may run before timing out.
     */
    public int $timeout = 300;

    /**
     * The backoff, in seconds, between attempts.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(public Media $media)
    {
        $this->onQueue('media');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $disk = Storage::disk($this->media->disk);

        /*
         * A missing original is a `failed` row, never a deletion: the row can
         * race `media:prune`, whose 24 h window the [10, 60, 300] backoff can
         * exceed, and deleting it here would drop the row a prune sweep is
         * already tracking.
         */
        if (! $disk->exists($this->media->path)) {
            $this->markFailed();

            report(new RuntimeException(
                "Media [{$this->media->id}] original [{$this->media->path}] is missing from disk [{$this->media->disk}].",
            ));

            return;
        }

        $temporaryPath = (string) tempnam(sys_get_temp_dir(), 'media-variants-');

        try {
            file_put_contents($temporaryPath, (string) $disk->get($this->media->path));

            $this->media->update(['status' => 'processing']);

            $this->media->update([
                'variants' => $this->buildVariants($temporaryPath),
                'status' => 'processed',
            ]);
        } catch (Throwable $exception) {
            $this->markFailed();

            report($exception);

            throw $exception;
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * Build the `variant_key => metadata` map and upload every object.
     *
     * @return array<string, array{path: string, width: int, height: int, mime: string, bytes: int}>
     */
    protected function buildVariants(string $temporaryPath): array
    {
        $mime = $this->assertMagicBytes($temporaryPath);

        /*
         * SVG logos are stored sanitised as-is and are never rasterised
         * (§9.5); a PDF or CV collection declares no variants at all. Both
         * still reach `processed` with an empty map.
         */
        if ($mime === 'image/svg+xml') {
            return [];
        }

        $keys = $this->variantKeys();

        if ($keys === []) {
            return [];
        }

        $disk = Storage::disk($this->media->disk);
        $directory = $this->variantDirectory();
        $formats = $this->formats();

        $manager = new ImageManager(Driver::class);
        $oriented = $manager->decodePath($temporaryPath)->orient();

        $variants = [];

        foreach ($keys as $key) {
            $image = clone $oriented;
            $this->applyFit($image, $key);

            foreach ($formats as $format) {
                $binary = $this->encode($image, $format);

                if ($binary === null) {
                    continue;
                }

                $path = $directory.'/'.$key.'.'.$format;

                $disk->put($path, $binary);

                $variants[$key.'.'.$format] = [
                    'path' => $path,
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'mime' => $this->mimeFor($format),
                    'bytes' => strlen($binary),
                ];
            }
        }

        if ($variants === []) {
            throw new RuntimeException(
                "Media [{$this->media->id}] produced no variants; every encoder failed for collection [{$this->media->collection}].",
            );
        }

        $lqip = $this->buildLqip($oriented, $directory);

        if ($lqip !== null) {
            $variants['lqip'] = $lqip;
        }

        return $variants;
    }

    /**
     * Build the low-quality image placeholder (§9.5).
     *
     * A 32 px-wide (16 px for the square collections) WebP q60 of the oriented
     * source, stored under the bare `lqip` key the frontend reads. It is
     * progressive enhancement, so a failure here never fails the row.
     *
     * @return array{path: string, width: int, height: int, mime: string, bytes: int}|null
     */
    protected function buildLqip(ImageInterface $oriented, string $directory): ?array
    {
        $width = $this->lqipWidth();

        if ($width === null) {
            return null;
        }

        try {
            $image = clone $oriented;
            $image->scaleDown(width: $width);

            $binary = $image->encode(new WebpEncoder(quality: 60, strip: true))->toString();
        } catch (Throwable) {
            return null;
        }

        $path = $directory.'/lqip.webp';

        Storage::disk($this->media->disk)->put($path, $binary);

        return [
            'path' => $path,
            'width' => $image->width(),
            'height' => $image->height(),
            'mime' => 'image/webp',
            'bytes' => strlen($binary),
        ];
    }

    /**
     * The LQIP's target width, or `null` for a collection that has none.
     *
     * §9.5 gives `cover`, `screenshot` and `inline` a 32 px placeholder and the
     * square collections 16 px; `logo` is rasterised to PNG/WebP without one,
     * and `pdf`/`cv` have no variants at all.
     */
    protected function lqipWidth(): ?int
    {
        return match ($this->media->collection) {
            'cover', 'screenshot', 'inline' => 32,
            'avatar', 'badge' => 16,
            default => null,
        };
    }

    /**
     * Re-check the stored bytes against the collection allow-list with finfo.
     *
     * This is the second gate (§9.6): the form request's `mimetypes` rule is
     * the first, and neither the client's declared type nor the file extension
     * is ever consulted. Images must additionally decode, and PDFs must open
     * with `%PDF-`.
     */
    protected function assertMagicBytes(string $temporaryPath): string
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);

        /** @var array<int, string> $allowed */
        $allowed = (array) config("media.collections.{$this->media->collection}.mimetypes", []);

        if (! in_array($mime, $allowed, true)) {
            throw new RuntimeException(
                "Media [{$this->media->id}] failed the magic-byte re-check: detected [{$mime}], allowed [".implode(', ', $allowed).'].',
            );
        }

        if ($mime === 'application/pdf') {
            if (! str_starts_with((string) file_get_contents($temporaryPath), '%PDF-')) {
                throw new RuntimeException(
                    "Media [{$this->media->id}] claims to be a PDF but its magic bytes do not start with %PDF-.",
                );
            }

            return $mime;
        }

        if ($mime !== 'image/svg+xml' && @getimagesize($temporaryPath) === false) {
            throw new RuntimeException(
                "Media [{$this->media->id}] claims to be [{$mime}] but does not decode as an image.",
            );
        }

        return $mime;
    }

    /**
     * The variant keys to generate for this row.
     *
     * A writing's cover additionally produces the `1200×630` `og_image` size
     * that feeds `writings.og_image_path` (§9.5); a project cover does not.
     *
     * @return array<int, string>
     */
    protected function variantKeys(): array
    {
        $groups = [$this->media->collection];

        if ($this->media->collection === 'cover' && $this->media->mediable_type === ContentType::Writing->value) {
            $groups[] = 'og_image';
        }

        $keys = [];

        foreach ($groups as $group) {
            foreach ((array) config("media.variants.{$group}", []) as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The encoders to produce for each variant.
     *
     * Every raster variant is produced in AVIF, WebP and a fallback; a logo is
     * PNG + WebP only (§9.5).
     *
     * @return array<int, string>
     */
    protected function formats(): array
    {
        if ($this->media->collection === 'logo') {
            return ['png', 'webp'];
        }

        return ['avif', 'webp', $this->fallbackFormat()];
    }

    /**
     * The fallback encoder's format.
     *
     * §9.5 asks for PNG "when the source has transparency". PNG is the only
     * source format whose declared type implies an alpha channel that must
     * survive, so the declared MIME type is the signal; every other source
     * falls back to JPEG.
     */
    protected function fallbackFormat(): string
    {
        return $this->media->mime_type === 'image/png' ? 'png' : 'jpg';
    }

    /**
     * Apply a variant key's fit to the image.
     *
     * `{name}_{w}x{h}` is an exact center-crop (`cover`); `{name}_{w}w` is a
     * scale-down that preserves aspect ratio and never upscales.
     */
    protected function applyFit(ImageInterface $image, string $key): void
    {
        if (preg_match('/_(\d+)x(\d+)$/', $key, $matches) === 1) {
            $image->cover((int) $matches[1], (int) $matches[2]);

            return;
        }

        if (preg_match('/_(\d+)w$/', $key, $matches) === 1) {
            $image->scaleDown(width: (int) $matches[1]);
        }
    }

    /**
     * Encode the image in one format, or `null` when the format is unavailable.
     *
     * Every encoder is asked to strip metadata explicitly: the GD ones serialise
     * pixels only, but the Imagick ones copy the source's EXIF through unless
     * told otherwise (§9.5), so relying on the driver's default would make the
     * privacy invariant a property of the driver rather than of this job.
     *
     * The per-format catch is what keeps the matrix from failing because one
     * encoder is missing: GD built without AVIF support cannot call
     * `imageavif()`, so AVIF is recorded as absent and the rest proceed.
     */
    protected function encode(ImageInterface $image, string $format): ?string
    {
        if ($format === 'avif' && ! $this->avifSupported()) {
            return null;
        }

        try {
            $encoded = $image->encode(match ($format) {
                'avif' => new AvifEncoder(quality: 80, strip: true),
                'webp' => new WebpEncoder(quality: 82, strip: true),
                'png' => new PngEncoder(strip: true),
                default => new JpegEncoder(quality: 85, strip: true),
            });

            return $encoded->toString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Determine whether this GD build can encode AVIF.
     */
    protected function avifSupported(): bool
    {
        return function_exists('imageavif') && (bool) (gd_info()['AVIF Support'] ?? false);
    }

    /**
     * The MIME type a variant's format is served as.
     */
    protected function mimeFor(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }

    /**
     * The directory variants are written into.
     *
     * Derived from the original's path, so it inherits the `{alias}/{sha256}`
     * layout the row was stored with rather than re-deriving the alias.
     */
    protected function variantDirectory(): string
    {
        $path = $this->media->path;

        return str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
    }

    /**
     * Mark the row failed without letting a missing row mask the real failure.
     */
    protected function markFailed(): void
    {
        try {
            $this->media->update(['status' => 'failed']);
        } catch (Throwable) {
            // The row can already be gone; that is not this job's failure.
        }
    }
}
