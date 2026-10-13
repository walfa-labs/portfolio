<?php

use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkExperience;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Media upload: authorisation, validation and the alt/decorative contract
| (§9.1, §9.6, §9.10)
|--------------------------------------------------------------------------
|
| Uploads are standard Inertia form posts to `POST /admin/media`, gated by
| `auth` + `verified` + `admin` and authorised through `MediaPolicy`. The file
| is validated twice: the form request's `mimetypes` rule (finfo-based) is the
| first gate, and the queued job re-checks the magic bytes — a PHP file named
| `.jpg` has to be stopped by the second gate even when the first passes.
|
| `alt`/`decorative` (§9.2, §9.6): `cover` and `inline` require either a
| non-empty `alt` or `decorative: true`, and `decorative: true` stores `''` (not
| `NULL`) so the decision is explicit in the database. `logo`, `badge` and
| `avatar` have a deterministic §7.8 fallback, so an omitted `alt` stays `NULL`.
|
| HONESTY ABOUT THE DISK — read this before trusting a green run.
|
| `Storage::fake('s3')` replaces the Garage-backed `s3` disk with a local temp
| directory. The upload path is therefore exercised against a **placeholder
| disk**, never against live Garage. That is enough to pin the app's own
| contract (validation, the row it writes, the job it dispatches, the objects
| it puts), but it cannot prove anything a real S3 server is responsible for:
| request signing, path/prefix handling, bucket permissions, `Content-Type`
| propagation on the object, and the caching headers Garage itself would serve.
| A green run here means "the application asked the disk to store the right
| bytes at the right key" — not "Garage stored them correctly". Round-tripping
| a real upload against Garage is the only way to prove the latter, and it is
| not what this file does.
|
| These tests need GD: `UploadedFile::fake()->image()` renders a real PNG/JPEG
| through GD, and the variant job decodes it with intervention/image. Without
| GD they cannot run at all.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('s3-private');
});

/**
 * A valid `cover` upload for a project, so a test can break exactly one thing.
 *
 * The default image is built lazily: a test that supplies its own `file` must
 * not pay for (or need) GD just to have the default discarded.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mediaCoverUploadPayload(Project $project, array $overrides = []): array
{
    if (! array_key_exists('file', $overrides)) {
        $overrides['file'] = UploadedFile::fake()->image('cover.jpg', 1600, 900);
    }

    return array_merge([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'collection' => 'cover',
        'alt' => 'A screenshot of the project dashboard',
    ], $overrides);
}

test('a guest cannot upload media', function () {
    $response = $this->post(route('admin.media.store'), []);

    $response->assertRedirect(route('login'));

    expect(Media::query()->count())->toBe(0);
});

test('an authenticated non-administrator cannot upload media', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->post(route('admin.media.store'), []);

    $response->assertForbidden();

    expect(Media::query()->count())->toBe(0);
});

test('a file above the collection maximum is rejected with a 422 on file', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $experience = WorkExperience::factory()->create();

    // `logo` allows 1 MB; 1100 KB is over it while still passing `mimetypes`,
    // so the size rule is the only one that can fail.
    $response = $this->post(route('admin.media.store'), [
        'mediable_type' => 'work_experience',
        'mediable_id' => $experience->id,
        'collection' => 'logo',
        'file' => UploadedFile::fake()->create('logo.png', 1100),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);

    expect(Media::query()->count())->toBe(0);
});

test('a file whose type the collection does not allow is rejected with a 422 on file', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    $response = $this->post(route('admin.media.store'), mediaCoverUploadPayload($project, [
        'file' => UploadedFile::fake()->create('report.pdf', 10),
    ]), ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);

    expect(Media::query()->count())->toBe(0);
});

test('an SVG uploaded into a raster-only collection is rejected', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    $response = $this->post(route('admin.media.store'), mediaCoverUploadPayload($project, [
        'file' => UploadedFile::fake()->createWithContent(
            'art.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>',
        ),
    ]), ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['file']);

    expect(Media::query()->count())->toBe(0);
});

test('bytes that are not an image are stopped by the second, magic-byte gate', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    /*
     * The declared type is `image/jpeg` (derived from the `.jpg` name), so the
     * form request's `mimetypes` rule passes. Only the job's magic-byte re-check
     * sees that the bytes are PHP source. The upload is accepted, queued and
     * then fails; it must never become a `processed` image with variants.
     */
    $response = $this->post(route('admin.media.store'), mediaCoverUploadPayload($project, [
        'file' => UploadedFile::fake()->createWithContent('payload.jpg', '<?php echo "pwned"; ?>'),
    ]));

    expect($response->status())->not->toBe(422);

    $row = Media::query()->where('collection', 'cover')->first();

    expect($row?->status)->not->toBe('processed');
    expect($row?->variants)->toBeNull();

    // The second gate stopped the job before any variant was written.
    $variantObjects = collect(Storage::disk('s3')->allFiles())
        ->filter(fn (string $path): bool => str_starts_with(basename($path), 'cover_'))
        ->values()
        ->all();

    expect($variantObjects)->toBe([]);
});

test('an SVG logo is sanitised before it is stored', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $experience = WorkExperience::factory()->create();

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
        .'<script>alert(1)</script>'
        .'<rect width="10" height="10" onload="alert(2)"/>'
        .'</svg>';

    $this->post(route('admin.media.store'), [
        'mediable_type' => 'work_experience',
        'mediable_id' => $experience->id,
        'collection' => 'logo',
        'file' => UploadedFile::fake()->createWithContent('logo.svg', $svg),
    ])->assertRedirect();

    $media = Media::query()->where('collection', 'logo')->firstOrFail();

    $stored = (string) Storage::disk('s3')->get($media->path);

    expect($stored)->toContain('<svg')
        ->and($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('onload');
});

test('a cover upload with neither alt text nor the decorative flag is rejected on alt', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    /*
     * The rejection is decided by the `alt` rule alone, so the fixture only has
     * to satisfy `mimetypes` and `max` — the declared type from the `.jpg` name
     * does that without a decodable image.
     */
    $response = $this->post(
        route('admin.media.store'),
        mediaCoverUploadPayload($project, [
            'alt' => null,
            'file' => UploadedFile::fake()->create('cover.jpg', 10),
        ]),
        ['Accept' => 'application/json'],
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['alt']);

    expect(Media::query()->count())->toBe(0);
});

test('a cover upload marked decorative stores an empty alt string', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    $this->post(route('admin.media.store'), mediaCoverUploadPayload($project, [
        'alt' => null,
        'decorative' => true,
    ]))->assertRedirect();

    $media = Media::query()->where('collection', 'cover')->firstOrFail();

    expect($media->alt)->toBe('');
});

test('a cover upload with authored alt text stores it verbatim', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    $this->post(route('admin.media.store'), mediaCoverUploadPayload($project, [
        'alt' => 'A screenshot of the project dashboard',
    ]))->assertRedirect();

    $media = Media::query()->where('collection', 'cover')->firstOrFail();

    expect($media->alt)->toBe('A screenshot of the project dashboard');
});

test('alt text longer than 255 characters is rejected', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    // Only the `alt` length rule is under test; the declared type satisfies
    // `mimetypes` and the 10 KB fixture is far under the ceiling.
    $response = $this->post(
        route('admin.media.store'),
        mediaCoverUploadPayload($project, [
            'alt' => str_repeat('a', 256),
            'file' => UploadedFile::fake()->create('cover.jpg', 10),
        ]),
        ['Accept' => 'application/json'],
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['alt']);
});

test('a collection with a deterministic alt fallback may omit alt entirely and stores null', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $experience = WorkExperience::factory()->create();

    $this->post(route('admin.media.store'), [
        'mediable_type' => 'work_experience',
        'mediable_id' => $experience->id,
        'collection' => 'logo',
        'file' => UploadedFile::fake()->image('logo.png', 512, 512),
    ])->assertRedirect();

    $media = Media::query()->where('collection', 'logo')->firstOrFail();

    expect($media->alt)->toBeNull();
});
