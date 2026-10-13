<?php

use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| CV download: GET /cv/download (§9.7, §9.1, §4.10)
|--------------------------------------------------------------------------
|
| The CV is the one private object on the site. It lives on the `s3-private`
| disk (`portfolio-private`), is never linked directly, and is streamed through
| this route with a `Content-Disposition: attachment` so the browser downloads
| rather than renders it. `/cv` links to it.
|
| The controller is scoped — this is the privacy fix pinned for phase 8. The
| row must be `mediable_type = user` with `mediable_id` equal to the requesting
| user, and `status = processed`. The old `latest('id')` across every `cv` row
| would let one authenticated user download another user's CV, which the
| "another user's CV is never served" test below pins shut.
|
| TENSION WORTH KNOWING: §3.2 calls `/cv/download` "a public attachment" and
| §9.7 "a public route", while the phase-8 pin scopes it to the requesting user
| and §18.9 R-53 calls it "access-controlled". The pin wins here, so a request
| with no authenticated user has no CV to serve. If the route is meant to stay
| public, this file is where the change will show up.
|
| `Storage::fake('s3-private')` stands in for Garage: this proves the app
| streamed the right object with the right headers, not that a real S3 server
| stored it or that its own access controls are correct.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('s3-private');

    RateLimiter::clear(md5('media-download127.0.0.1'));
});

/**
 * A `cv` media row for a user, with its PDF object on the private disk.
 */
function mediaCvRow(User $owner, string $status = 'processed'): Media
{
    $sha256 = hash('sha256', 'cv-'.$owner->id.'-'.$status);
    $path = "user/{$sha256}/original.pdf";

    Storage::disk('s3-private')->put($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n%%EOF");

    return Media::factory()->create([
        'mediable_type' => 'user',
        'mediable_id' => $owner->id,
        'collection' => 'cv',
        'disk' => 's3-private',
        'path' => $path,
        'original_name' => 'curriculum-vitae.pdf',
        'mime_type' => 'application/pdf',
        'sha256' => $sha256,
        'status' => $status,
        'variants' => null,
    ]);
}

test('the owner streams their own processed CV from the private disk as an attachment', function () {
    $owner = User::factory()->administrator()->create();
    $media = mediaCvRow($owner);

    $this->actingAs($owner);

    $response = $this->get(route('cv.download'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->streamedContent())->toContain('%PDF-');

    // The object lives on the private disk, never on the public one.
    expect($media->disk)->toBe('s3-private');
});

test('a user with no CV gets a 404', function () {
    $this->actingAs(User::factory()->administrator()->create());

    $this->get(route('cv.download'))->assertNotFound();
});

test('a CV that has not finished processing is not served', function () {
    $owner = User::factory()->administrator()->create();
    mediaCvRow($owner, 'pending');

    $this->actingAs($owner);

    $this->get(route('cv.download'))->assertNotFound();
});

test('one user can never download another user\'s CV', function () {
    $owner = User::factory()->administrator()->create();
    $other = User::factory()->create();

    mediaCvRow($owner);

    $this->actingAs($other);

    $this->get(route('cv.download'))->assertNotFound();
});

test('a request with no authenticated user has no CV to serve', function () {
    $owner = User::factory()->administrator()->create();
    mediaCvRow($owner);

    $this->get(route('cv.download'))->assertNotFound();
});

test('the private CV object is not reachable by its raw path', function () {
    $owner = User::factory()->administrator()->create();
    $media = mediaCvRow($owner);

    $this->actingAs($owner);

    // No route serves an arbitrary storage key.
    $this->get('/'.$media->path)->assertNotFound();

    /*
     * `/storage/{path}` is the framework's own signed route for the `local`
     * disk (`config/filesystems.php` marks it `serve => true`) — not for
     * `s3-private`, and not for the CV. An unsigned request is refused there,
     * and the object is not on that disk in any case.
     */
    expect($this->get('/storage/'.$media->path)->status())->not->toBe(200);
});

test('the media-download limiter answers 429 after thirty requests in a minute', function () {
    $owner = User::factory()->administrator()->create();
    mediaCvRow($owner);

    $this->actingAs($owner);

    foreach (range(1, 30) as $ignored) {
        $this->get(route('cv.download'))->assertOk();
    }

    $response = $this->get(route('cv.download'));

    $response->assertStatus(429);

    expect($response->headers->get('Retry-After'))->not->toBeNull();
});
