<?php

use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Variant matrix (§9.5)
|--------------------------------------------------------------------------
|
| Every raster variant is produced in up to three formats — AVIF (q80), WebP
| (q82) and a fallback (JPEG q85, or PNG when the source has transparency) —
| and the keys embed the size and the format: `cover_1600x900.avif`. The names
| are contractual with the frontend, so the keys and the pixel dimensions are
| asserted here, not just "some variants exist".
|
| EXIF is the privacy invariant (§9.5): variants are re-encoded from decoded
| pixels and every encoder is asked to strip metadata, so no variant carries an
| EXIF, IPTC or XMP segment. `exif_read_data()` cannot express that assertion —
| PHP's exif extension reads only JPEG and TIFF, and for a JPEG it synthesises
| the FILE and COMPUTED sections for *any* decodable file, so it never returns
| `false` for a valid JPEG. The variant bytes are inspected directly instead.
|
| The pipeline is exercised through the real admin upload route with
| `QUEUE_CONNECTION=sync`, so the job runs inline and the post-conditions are
| asserted synchronously. `Storage::fake('s3')` stands in for Garage: this
| proves the app produced the right bytes at the right keys, not that a real
| S3 server stored them. These tests need GD.
|
*/

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * The administrator this test acts as, created once and reused.
 *
 * `UserFactory::administrator()` pins the email to
 * `config('portfolio.admin.email')`, which carries a unique index, so a second
 * `administrator()->create()` in the same test — or a first one when
 * `AdministratorSeeder` has already inserted that address — is a duplicate-key
 * error. Looking the row up first makes the uploader the single administrator
 * this test owns, however it came to exist.
 */
function mediaAdministrator(): User
{
    return User::query()->where('email', config('portfolio.admin.email'))->first()
        ?? User::factory()->administrator()->create();
}

/**
 * Upload a file through the admin route and return the row the pipeline wrote.
 *
 * `$as` lets a test act as a specific user, which the `avatar` and `cv`
 * collections require: §9.6 accepts those only for the requesting user's own
 * record.
 *
 * @param  array<string, mixed>  $extra
 */
function mediaVariantUpload(
    string $collection,
    string $mediableType,
    int $mediableId,
    UploadedFile $file,
    ?User $as = null,
    array $extra = [],
): Media {
    test()->actingAs($as ?? mediaAdministrator());

    test()->post(route('admin.media.store'), array_merge([
        'mediable_type' => $mediableType,
        'mediable_id' => $mediableId,
        'collection' => $collection,
        'file' => $file,
        'alt' => $collection === 'cover' ? 'A cover image' : null,
    ], $extra))->assertRedirect();

    return Media::query()->where('collection', $collection)->latest('id')->firstOrFail();
}

/**
 * Splice a minimal EXIF APP1 segment (Orientation = 6) behind a JPEG's SOI marker.
 *
 * GD writes no EXIF, so a generated fixture would have nothing to strip and the
 * "variants carry no EXIF" assertion would pass vacuously. This makes the
 * source genuinely carry metadata, so the test can tell a stripping encoder
 * from a no-op.
 */
function mediaJpegWithExif(string $jpeg): string
{
    if (! str_starts_with($jpeg, "\xFF\xD8")) {
        throw new RuntimeException('The fixture is not a JPEG.');
    }

    $tiff = "II\x2A\x00".pack('V', 8)                                  // little-endian TIFF, IFD0 at offset 8
        .pack('v', 1)                                                  // one directory entry
        .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('V', 6)      // Orientation (SHORT) = 6
        .pack('V', 0);                                                 // no next IFD

    $payload = "Exif\x00\x00".$tiff;

    return "\xFF\xD8"."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

/**
 * The metadata segments a raster's own bytes carry.
 *
 * `exif_read_data()` cannot answer this for the whole matrix: PHP's exif
 * extension reads only JPEG and TIFF, and for a JPEG it synthesises the FILE
 * and COMPUTED sections for *any* decodable file, so it never returns `false`
 * for a valid JPEG. Every container names its metadata segments in its own
 * header, so the bytes are parsed instead — a segment that is absent from this
 * list is genuinely absent from the file.
 *
 * @return array<int, string>
 */
function metadataSegments(string $binary): array
{
    return match (true) {
        str_starts_with($binary, "\xFF\xD8") => jpegMetadataSegments($binary),
        str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP' => webpMetadataChunks($binary),
        str_starts_with($binary, "\x89PNG\r\n\x1A\n") => pngMetadataChunks($binary),
        substr($binary, 4, 4) === 'ftyp' => avifMetadataItems($binary),
        default => ['an unrecognised container'],
    };
}

/**
 * The EXIF, XMP and IPTC segments in a JPEG, in marker order.
 *
 * APP1 carries EXIF (`Exif\0\0`) or XMP and APP13 carries IPTC. APP0 is the
 * JFIF header and COM is the encoder's own CREATOR line; neither is image
 * metadata. The walk stops at the start of scan, because the entropy-coded data
 * that follows may contain any byte sequence — including `0xFF 0xE1`.
 *
 * @return array<int, string>
 */
function jpegMetadataSegments(string $binary): array
{
    $segments = [];
    $length = strlen($binary);
    $offset = 2;

    while ($offset + 4 <= $length) {
        if (ord($binary[$offset]) !== 0xFF) {
            break;
        }

        $marker = ord($binary[$offset + 1]);

        if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
            $offset += 2;

            continue;
        }

        $size = (int) unpack('n', substr($binary, $offset + 2, 2))[1];
        $payload = substr($binary, $offset + 4, max(0, $size - 2));

        if ($marker === 0xE1) {
            $segments[] = str_starts_with($payload, "Exif\x00\x00") ? 'EXIF' : 'XMP';
        }

        if ($marker === 0xED) {
            $segments[] = 'IPTC';
        }

        if ($marker === 0xDA) {
            break;
        }

        $offset += 2 + $size;
    }

    return $segments;
}

/**
 * The EXIF, XMP and ICC chunks in a WebP file.
 *
 * @return array<int, string>
 */
function webpMetadataChunks(string $binary): array
{
    $chunks = [];
    $length = strlen($binary);
    $offset = 12;

    while ($offset + 8 <= $length) {
        $fourcc = substr($binary, $offset, 4);
        $size = (int) unpack('V', substr($binary, $offset + 4, 4))[1];

        if (in_array($fourcc, ['EXIF', 'XMP ', 'ICCP'], true)) {
            $chunks[] = trim($fourcc);
        }

        $offset += 8 + $size + ($size % 2);
    }

    return $chunks;
}

/**
 * The EXIF, ICC and text chunks in a PNG file.
 *
 * @return array<int, string>
 */
function pngMetadataChunks(string $binary): array
{
    $chunks = [];
    $length = strlen($binary);
    $offset = 8;

    while ($offset + 8 <= $length) {
        $size = (int) unpack('N', substr($binary, $offset, 4))[1];
        $type = substr($binary, $offset + 4, 4);

        if (in_array($type, ['eXIf', 'iCCP', 'tEXt', 'iTXt', 'zTXt'], true)) {
            $chunks[] = $type;
        }

        if ($type === 'IEND') {
            break;
        }

        $offset += 12 + $size;
    }

    return $chunks;
}

/**
 * The EXIF and XMP items an AVIF file's `meta` box declares.
 *
 * AVIF is ISOBMFF: metadata lives in the `meta` box, whose payload names the
 * item types it holds — `Exif`, `xml ` for XMP, and `mime` for an XMP item
 * carried as a MIME payload.
 *
 * @return array<int, string>
 */
function avifMetadataItems(string $binary): array
{
    $items = [];
    $length = strlen($binary);
    $offset = 0;

    while ($offset + 8 <= $length) {
        $size = (int) unpack('N', substr($binary, $offset, 4))[1];
        $type = substr($binary, $offset + 4, 4);

        if ($type === 'meta') {
            $payload = substr($binary, $offset + 12, max(0, $size - 12));

            foreach (['Exif', 'xml ', 'mime'] as $item) {
                if (str_contains($payload, $item)) {
                    $items[] = trim($item);
                }
            }
        }

        if ($size < 8) {
            break;
        }

        $offset += $size;
    }

    return $items;
}

test('a project cover produces the contractual 16:9 keys at exact dimensions', function () {
    $project = Project::factory()->create();

    $media = mediaVariantUpload(
        'cover',
        'project',
        $project->id,
        UploadedFile::fake()->image('cover.jpg', 1700, 1275),
    );

    expect($media->status)->toBe('processed');

    $variants = $media->variants;

    expect($variants)->toBeArray()->not->toBeEmpty();

    foreach ([[1600, 900], [800, 450], [400, 225]] as [$width, $height]) {
        $key = "cover_{$width}x{$height}.webp";

        expect($variants)->toHaveKey($key);
        expect((int) $variants[$key]['width'])->toBe($width);
        expect((int) $variants[$key]['height'])->toBe($height);

        $size = getimagesize(Storage::disk('s3')->path($variants[$key]['path']));

        expect($size)->toBeArray();
        expect($size[0])->toBe($width);
        expect($size[1])->toBe($height);
    }

    // The source is an opaque JPEG, so the fallback is JPEG rather than PNG.
    expect($variants)->toHaveKey('cover_1600x900.jpg');
});

test('an avatar produces the contractual square keys at exact dimensions', function () {
    $owner = mediaAdministrator();

    $media = mediaVariantUpload(
        'avatar',
        'user',
        $owner->id,
        UploadedFile::fake()->image('avatar.jpg', 800, 800),
        $owner,
    );

    $variants = $media->variants;

    foreach ([[512, 512], [256, 256], [64, 64]] as [$width, $height]) {
        $key = "avatar_{$width}x{$height}.webp";

        expect($variants)->toHaveKey($key);
        expect((int) $variants[$key]['width'])->toBe($width);
        expect((int) $variants[$key]['height'])->toBe($height);

        $size = getimagesize(Storage::disk('s3')->path($variants[$key]['path']));

        expect($size[0])->toBe($width);
        expect($size[1])->toBe($height);
    }
});

test('each raster collection stores a low-quality placeholder under the lqip key', function () {
    /*
     * §9.5 requires an LQIP for `cover`, `screenshot`, `avatar`, `badge` and
     * `inline` — a 32px-wide (16px for squares) WebP q60 of the oriented
     * source, stored as the `lqip` variant key and consumed by
     * `ResponsiveImage.vue` as a blurred background. It is deliberately absent
     * from the variant matrix in `config/media.php`: it is not one of the fixed
     * size keys, so `GenerateMediaVariants::lqipWidth()` carries the width per
     * collection and `buildLqip()` writes `lqip.webp` beside the variants.
     */
    $project = Project::factory()->create();

    $cover = mediaVariantUpload(
        'cover',
        'project',
        $project->id,
        UploadedFile::fake()->image('cover.jpg', 1700, 1275),
    );

    expect($cover->variants)->toHaveKey('lqip');

    $owner = mediaAdministrator();

    $avatar = mediaVariantUpload(
        'avatar',
        'user',
        $owner->id,
        UploadedFile::fake()->image('avatar.jpg', 800, 800),
        $owner,
    );

    expect($avatar->variants)->toHaveKey('lqip');
});

test('a screenshot is scaled down without being cropped, so its aspect ratio is preserved', function () {
    $project = Project::factory()->create();

    $media = mediaVariantUpload(
        'screenshot',
        'project',
        $project->id,
        UploadedFile::fake()->image('shot.jpg', 1700, 1275),
    );

    $variants = $media->variants;

    $widths = collect($variants)->map(fn (array $variant): int => (int) $variant['width']);

    expect($widths)->toContain(1600)->toContain(800)->toContain(400);

    $sourceRatio = 1700 / 1275;

    foreach ($variants as $key => $variant) {
        $ratio = $variant['width'] / $variant['height'];

        expect(abs($ratio - $sourceRatio))->toBeLessThan(0.02, "The {$key} variant changed the aspect ratio.");
    }
});

test('a screenshot smaller than the largest target is never upscaled', function () {
    $project = Project::factory()->create();

    $media = mediaVariantUpload(
        'screenshot',
        'project',
        $project->id,
        UploadedFile::fake()->image('small.jpg', 320, 240),
    );

    $oversized = collect($media->variants)
        ->filter(fn (array $variant): bool => (int) $variant['width'] > 320)
        ->keys()
        ->all();

    expect($oversized)->toBe([]);
});

test('AVIF variants are produced when the encoder supports it', function () {
    if (! function_exists('gd_info') || ! (gd_info()['AVIF Support'] ?? false)) {
        $this->markTestSkipped('GD was built without AVIF support; the matrix degrades to WebP plus the fallback.');
    }

    $project = Project::factory()->create();

    $media = mediaVariantUpload(
        'cover',
        'project',
        $project->id,
        UploadedFile::fake()->image('cover.jpg', 1700, 1275),
    );

    expect($media->variants)->toHaveKey('cover_1600x900.avif');
});

test('every generated variant is stripped of EXIF metadata', function () {
    if (! function_exists('exif_read_data')) {
        $this->markTestSkipped('The exif extension is not loaded.');
    }

    $project = Project::factory()->create();

    $source = mediaJpegWithExif(
        UploadedFile::fake()->image('cover.jpg', 1700, 1275)->getContent(),
    );

    $media = mediaVariantUpload(
        'cover',
        'project',
        $project->id,
        UploadedFile::fake()->createWithContent('cover.jpg', $source),
    );

    /*
     * Both detectors are shown to see EXIF before they are asked to find none:
     * the fixture carries an APP1 segment, and the master that reached the disk
     * still carries it, because the original is the master and keeps its EXIF.
     * Without this the loop below could pass on a broken detector.
     */
    expect(metadataSegments($source))->toContain('EXIF');

    $master = (string) Storage::disk('s3')->get($media->path);

    expect(metadataSegments($master))->toContain('EXIF');
    expect(array_keys((array) exif_read_data(Storage::disk('s3')->path($media->path), null, true)))
        ->toContain('IFD0');

    foreach ($media->variants as $key => $variant) {
        expect(metadataSegments((string) Storage::disk('s3')->get($variant['path'])))
            ->toBe([], "The {$key} variant still carries EXIF, IPTC or XMP.");

        /*
         * The exif extension agrees for the one container it reads: IFD0 only
         * exists when an APP1 EXIF segment does. Its FILE and COMPUTED sections
         * are synthesised for any decodable JPEG, which is why the assertion is
         * "no IFD0 section" rather than "no array".
         */
        $sections = @exif_read_data(Storage::disk('s3')->path($variant['path']), null, true);

        expect($sections === false ? [] : array_keys($sections))
            ->not->toContain('IFD0', "The {$key} variant still reports an EXIF section.");
    }
});
