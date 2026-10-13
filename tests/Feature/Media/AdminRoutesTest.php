<?php

use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Admin media routes: single-cardinality replacement, regenerate, destroy
| (§9.1, §9.10)
|--------------------------------------------------------------------------
|
| `POST /admin/media`, `POST /admin/media/{media}/regenerate` and
| `DELETE /admin/media/{media}` are the three admin routes; the delete carries
| `password.confirm` (§5.5) because it is destructive.
|
| The cardinality rule is the interesting one: `single` collections (`cover`,
| `avatar`, `logo`, `badge`, `pdf`, `cv`) replace the previous row — and its
| files — when a second upload lands, while `many` collections (`screenshot`,
| `inline`) are additive and deduplicated by hash. The replacement is pinned to
| run inside the same database transaction as the insert, so a failure halfway
| cannot leave the collection with no row; only the end state is observable
| from a test, so that is what is asserted.
|
| `Storage::fake('s3')` stands in for Garage: the assertions prove which keys
| the app wrote and deleted, not what a real S3 server did.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('s3-private');
});

/**
 * Upload a cover for a project and return the created row.
 */
function mediaAdminUploadCover(Project $project, string $name, int $width, int $height, string $alt): Media
{
    test()->post(route('admin.media.store'), [
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'collection' => 'cover',
        'file' => UploadedFile::fake()->image($name, $width, $height),
        'alt' => $alt,
    ])->assertRedirect();

    return Media::query()->where('collection', 'cover')->latest('id')->firstOrFail();
}

test('a second upload into a single-cardinality collection replaces the previous row and its files', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    // Different source dimensions guarantee different bytes and so a different
    // content hash — identical bytes would be an idempotent re-upload instead.
    $first = mediaAdminUploadCover($project, 'first.jpg', 1600, 900, 'First cover');
    $firstPath = $first->path;
    $firstVariantPath = (string) collect($first->variants)->first()['path'];

    expect(Media::query()->where('collection', 'cover')->count())->toBe(1);
    expect(Storage::disk('s3')->exists($firstPath))->toBeTrue();

    $second = mediaAdminUploadCover($project, 'second.jpg', 1200, 800, 'Second cover');

    expect(Media::query()->where('collection', 'cover')->count())->toBe(1);
    expect(Media::query()->whereKey($first->getKey())->exists())->toBeFalse();
    expect($second->alt)->toBe('Second cover');
    expect(Storage::disk('s3')->exists($firstPath))->toBeFalse();
    expect(Storage::disk('s3')->exists($firstVariantPath))->toBeFalse();
});

test('a second avatar upload replaces the previous row for the same user', function () {
    $owner = User::factory()->administrator()->create();
    $this->actingAs($owner);

    foreach ([['one.jpg', 800, 800], ['two.jpg', 640, 640]] as [$name, $width, $height]) {
        $this->post(route('admin.media.store'), [
            'mediable_type' => 'user',
            'mediable_id' => $owner->id,
            'collection' => 'avatar',
            'file' => UploadedFile::fake()->image($name, $width, $height),
        ])->assertRedirect();
    }

    expect(Media::query()->where('collection', 'avatar')->count())->toBe(1);
});

test('regenerate re-runs the variant job for an existing row', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    $media = mediaAdminUploadCover($project, 'cover.jpg', 1600, 900, 'A cover');

    // Strip the variants but leave the original in place: regenerate must
    // rebuild them from the stored master.
    $media->forceFill(['variants' => null, 'status' => 'pending'])->saveQuietly();

    $this->post(route('admin.media.regenerate', $media))->assertRedirect();

    $media->refresh();

    expect($media->status)->toBe('processed');
    expect($media->variants)->toBeArray()->not->toBeEmpty();
    expect($media->variants)->toHaveKey('cover_1600x900.webp');
});

test('destroying a media row removes it and its objects, and requires password confirmation', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $project = Project::factory()->create();

    $media = mediaAdminUploadCover($project, 'cover.jpg', 1600, 900, 'A cover');

    $original = $media->path;
    $variant = (string) collect($media->variants)->first()['path'];

    // Without a recent confirmation the destructive route bounces to the
    // password-confirm screen and the row survives.
    $this->delete(route('admin.media.destroy', $media))
        ->assertRedirect(route('password.confirm'));

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeTrue();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('admin.media.destroy', $media))
        ->assertRedirect();

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse();
    expect(Storage::disk('s3')->exists($original))->toBeFalse();
    expect(Storage::disk('s3')->exists($variant))->toBeFalse();
});

test('the media-upload limiter answers 429 after thirty uploads in a minute', function () {
    $admin = User::factory()->administrator()->create();
    $this->actingAs($admin);

    // The limiter keys on the authenticated user id (§4.10), so this clears
    // exactly that bucket rather than relying on a fresh cache store.
    RateLimiter::clear(md5('media-upload'.$admin->getAuthIdentifier()));

    // The limiter runs as route middleware, ahead of validation, so an empty
    // payload still counts against the bucket.
    foreach (range(1, 30) as $ignored) {
        $this->post(route('admin.media.store'), []);
    }

    $this->post(route('admin.media.store'), [])->assertStatus(429);
});
