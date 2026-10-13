<?php

use App\Models\Media;
use App\Models\Project;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Serving route: GET /media/{media}/{variant} (§9.7, §3.2, §4.10)
|--------------------------------------------------------------------------
|
| Every public media object is streamed through the app. Garage offers no
| anonymous S3 read and no ACLs, so there is no direct bucket URL and no
| presigned URL to fall back on (§9.7, §15.9). The route is public,
| long-cacheable because the paths are content-addressed, and limited by the
| `media-download` limiter.
|
| Variant resolution is pinned: `{variant}` is the full key *including* the
| extension (`cover_800x450.webp`). When that exact key is absent the route
| falls back through the formats for the same size in the fixed order
| avif → webp → jpg → png, and answers 404 only when no variant of that size
| exists at all.
|
| These assertions are kernel-only. `Storage::fake('s3')` stands in for Garage,
| so byte-for-byte delivery, real object permissions and the bucket's own
| caching behaviour are not exercised here — only the app's contract is.
|
*/

beforeEach(function () {
    Storage::fake('s3');

    /*
     * `ThrottleRequests` keys a *named* limiter as `md5($limiterName.$limit->key)`
     * (§4.10), so this clears exactly the `media-download` bucket for the test
     * client address. Without it the assertions would depend on the `array` cache
     * store happening to be fresh per test.
     */
    RateLimiter::clear(md5('media-download127.0.0.1'));
});

/**
 * The MIME type a variant extension stands for.
 */
function mediaServingMime(string $extension): string
{
    return match ($extension) {
        'avif' => 'image/avif',
        'webp' => 'image/webp',
        'png' => 'image/png',
        default => 'image/jpeg',
    };
}

/**
 * Bytes whose magic number makes `finfo` report the variant's real type.
 *
 * The route may take `Content-Type` from the stored variant's `mime` or from
 * the object itself; giving the fixture real magic bytes keeps the assertion
 * valid whichever the implementation reads.
 */
function mediaServingBytes(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00",
        'image/png' => "\x89PNG\r\n\x1a\n"
            ."\x00\x00\x00\rIHDR".pack('N', 1).pack('N', 1)."\x08\x02\x00\x00\x00",
        'image/webp' => 'RIFF'.pack('V', 16).'WEBPVP8 '.pack('V', 4)."\x00\x00\x00\x00",
        'image/avif' => "\x00\x00\x00\x1Cftypavif\x00\x00\x00\x00avifmif1",
        default => 'bytes',
    };
}

/**
 * A processed `cover` row whose variants map and whose objects are on the fake disk.
 *
 * The variant keys are given in full so a test can build any combination of
 * size and format — including the fallback chains §9.7 pins.
 *
 * @param  list<string>  $keys  e.g. ['cover_800x450.webp', 'cover_800x450.jpg']
 */
function mediaServingRow(array $keys): Media
{
    $project = Project::factory()->create();
    $sha256 = str_repeat('a', 64);
    $variants = [];

    foreach ($keys as $key) {
        $extension = pathinfo($key, PATHINFO_EXTENSION);
        $size = substr(pathinfo($key, PATHINFO_FILENAME), strlen('cover_'));
        $mime = mediaServingMime($extension);
        $bytes = mediaServingBytes($mime);
        [$width, $height] = array_map('intval', explode('x', $size));
        $path = "projects/{$sha256}/{$key}";

        Storage::disk('s3')->put($path, $bytes);

        $variants[$key] = [
            'path' => $path,
            'width' => $width,
            'height' => $height,
            'mime' => $mime,
            'bytes' => strlen($bytes),
        ];
    }

    return Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'collection' => 'cover',
        'disk' => 's3',
        'path' => "projects/{$sha256}/original.jpg",
        'original_name' => 'cover.jpg',
        'mime_type' => 'image/jpeg',
        'sha256' => $sha256,
        'status' => 'processed',
        'variants' => $variants,
    ]);
}

test('the serving route is reachable without authentication', function () {
    $media = mediaServingRow(['cover_800x450.webp']);

    $this->get("/media/{$media->getKey()}/cover_800x450.webp")->assertOk();
});

test('the exact variant key is streamed with its content type, inline disposition and immutable caching', function () {
    $media = mediaServingRow(['cover_800x450.webp']);

    $response = $this->get("/media/{$media->getKey()}/cover_800x450.webp");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/webp');

    expect($response->headers->get('Content-Disposition'))->toContain('inline');

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public')
        ->toContain('max-age=31536000')
        ->toContain('immutable');

    expect($response->streamedContent())->toBe(mediaServingBytes('image/webp'));
});

test('an exact key is served as itself even when another format of the same size exists', function () {
    $media = mediaServingRow(['cover_800x450.jpg', 'cover_800x450.png']);

    $this->get("/media/{$media->getKey()}/cover_800x450.png")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

test('a request for an absent format falls back to the next format of the same size', function () {
    $media = mediaServingRow(['cover_800x450.webp', 'cover_800x450.jpg']);

    $this->get("/media/{$media->getKey()}/cover_800x450.avif")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});

test('the format fallback reaches jpg when no earlier format exists', function () {
    $media = mediaServingRow(['cover_800x450.jpg']);

    $this->get("/media/{$media->getKey()}/cover_800x450.avif")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

test('the format fallback prefers jpg over png', function () {
    $media = mediaServingRow(['cover_800x450.jpg', 'cover_800x450.png']);

    $this->get("/media/{$media->getKey()}/cover_800x450.avif")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

test('a size with no variant of any format answers 404', function () {
    $media = mediaServingRow(['cover_800x450.webp']);

    $this->get("/media/{$media->getKey()}/cover_1600x900.webp")->assertNotFound();
});

test('a variant key that names no size answers 404', function () {
    $media = mediaServingRow(['cover_800x450.webp']);

    $this->get("/media/{$media->getKey()}/nonsense.webp")->assertNotFound();
});

test('an unknown media row answers 404', function () {
    $this->get('/media/01ARZ3NDEKTSV4RRFFQ69G5FAV/cover_800x450.webp')->assertNotFound();
});

test('a disallowed verb answers 405 with an Allow header', function () {
    $media = mediaServingRow(['cover_800x450.webp']);

    $response = $this->post("/media/{$media->getKey()}/cover_800x450.webp");

    $response->assertStatus(405);

    expect((string) $response->headers->get('Allow'))->toContain('GET');
});

test('the media-download limiter answers 429 after thirty requests in a minute', function () {
    $media = mediaServingRow(['cover_800x450.webp']);
    $url = "/media/{$media->getKey()}/cover_800x450.webp";

    foreach (range(1, 30) as $ignored) {
        $this->get($url)->assertOk();
    }

    $response = $this->get($url);

    $response->assertStatus(429);

    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

test('media is not exposed as an /api/v1 resource', function () {
    $mediaRoutes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/media'))
        ->values()
        ->all();

    expect($mediaRoutes)->toBe([]);
});
