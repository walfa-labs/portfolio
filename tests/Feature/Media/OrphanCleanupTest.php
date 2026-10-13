<?php

use App\Models\Media;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Orphan cleanup: the shared-object rule and `media:prune` (§9.4, §9.8)
|--------------------------------------------------------------------------
|
| Paths are content-addressed, so two different mediables uploading identical
| bytes produce two `media` rows pointing at one object. `Media::deleting()`
| therefore deletes an object only when the last row referencing it goes away —
| otherwise removing one row would break the other. Content deletion reaches
| that hook through `HasMedia`'s `forceDeleting` listener, so a soft-deleted
| (restorable) row keeps its media while a force-delete clears it.
|
| `media:prune` sweeps what the hooks cannot see: rows stuck `pending` or
| `processing` for more than 24 hours (with their originals), `failed` rows
| older than seven days, and rows whose mediable no longer exists at all.
|
| One pinned subtlety: a stale row whose original is *missing* from the disk is
| marked `failed`, never deleted. The row can be racing the job, and deleting
| it would lose the only record that an upload was ever attempted — so
| `media:prune` never deletes a row whose original is gone.
|
| `Storage::fake('s3')` stands in for Garage; the assertions prove which keys
| the app asked the disk to delete, not what a real S3 server did.
|
*/

beforeEach(function () {
    Storage::fake('s3');
});

/**
 * Backdate a media row's `created_at` without touching anything else.
 */
function mediaBackdate(Media $media, DateTimeInterface $createdAt): Media
{
    $media->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $media->refresh();
}

test('deleting a media row removes its original and every variant object', function () {
    $sha256 = str_repeat('b', 64);
    $original = "projects/{$sha256}/original.jpg";
    $variant = "projects/{$sha256}/cover_800x450.webp";

    $media = Media::factory()->create([
        'disk' => 's3',
        'path' => $original,
        'sha256' => $sha256,
        'variants' => [
            'cover_800x450.webp' => [
                'path' => $variant,
                'width' => 800,
                'height' => 450,
                'mime' => 'image/webp',
                'bytes' => 10,
            ],
        ],
    ]);

    Storage::disk('s3')->put($original, 'original');
    Storage::disk('s3')->put($variant, 'variant');

    $media->delete();

    expect(Storage::disk('s3')->exists($original))->toBeFalse();
    expect(Storage::disk('s3')->exists($variant))->toBeFalse();
});

test('an object shared by two rows survives the first deletion and goes with the last', function () {
    $sha256 = str_repeat('c', 64);
    $path = "projects/{$sha256}/original.jpg";

    Storage::disk('s3')->put($path, 'shared bytes');

    $first = Media::factory()->create([
        'disk' => 's3',
        'path' => $path,
        'sha256' => $sha256,
        'variants' => null,
    ]);

    $second = Media::factory()->create([
        'disk' => 's3',
        'path' => $path,
        'sha256' => $sha256,
        'variants' => null,
    ]);

    $first->delete();

    expect(Storage::disk('s3')->exists($path))->toBeTrue();

    $second->delete();

    expect(Storage::disk('s3')->exists($path))->toBeFalse();
});

test('force-deleting a content model removes its media rows and their objects', function () {
    $project = Project::factory()->create();

    $sha256 = str_repeat('d', 64);
    $original = "projects/{$sha256}/original.jpg";
    $variant = "projects/{$sha256}/cover_800x450.webp";

    $media = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'disk' => 's3',
        'path' => $original,
        'sha256' => $sha256,
        'variants' => [
            'cover_800x450.webp' => [
                'path' => $variant,
                'width' => 800,
                'height' => 450,
                'mime' => 'image/webp',
                'bytes' => 10,
            ],
        ],
    ]);

    Storage::disk('s3')->put($original, 'original');
    Storage::disk('s3')->put($variant, 'variant');

    $project->forceDelete();

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse();
    expect(Storage::disk('s3')->exists($original))->toBeFalse();
    expect(Storage::disk('s3')->exists($variant))->toBeFalse();
});

test('media:prune deletes a stale pending row together with its original', function () {
    $sha256 = str_repeat('e', 64);
    $path = "projects/{$sha256}/original.jpg";

    $media = Media::factory()->create([
        'status' => 'pending',
        'disk' => 's3',
        'path' => $path,
        'sha256' => $sha256,
        'variants' => null,
    ]);

    Storage::disk('s3')->put($path, 'stale upload');

    mediaBackdate($media, now()->subHours(25));

    $this->artisan('media:prune')->assertExitCode(0);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse();
    expect(Storage::disk('s3')->exists($path))->toBeFalse();
});

test('media:prune leaves a pending row younger than twenty-four hours alone', function () {
    $sha256 = str_repeat('f', 64);

    $media = Media::factory()->create([
        'status' => 'pending',
        'disk' => 's3',
        'path' => "projects/{$sha256}/original.jpg",
        'sha256' => $sha256,
        'variants' => null,
    ]);

    mediaBackdate($media, now()->subHours(23));

    $this->artisan('media:prune')->assertExitCode(0);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeTrue();
});

test('media:prune marks a stale pending row whose original is missing as failed, never deletes it', function () {
    $sha256 = str_repeat('g', 64);

    $media = Media::factory()->create([
        'status' => 'pending',
        'disk' => 's3',
        'path' => "projects/{$sha256}/original.jpg",
        'sha256' => $sha256,
        'variants' => null,
    ]);

    // Deliberately nothing on the disk: the original is gone while the row
    // still exists, so the row may be racing the job and must be kept.
    mediaBackdate($media, now()->subHours(25));

    $this->artisan('media:prune')->assertExitCode(0);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeTrue();
    expect($media->refresh()->status)->toBe('failed');
});

test('media:prune deletes failed rows older than seven days', function () {
    $sha256 = str_repeat('h', 64);

    $media = Media::factory()->create([
        'status' => 'failed',
        'disk' => 's3',
        'path' => "projects/{$sha256}/original.jpg",
        'sha256' => $sha256,
        'variants' => null,
    ]);

    mediaBackdate($media, now()->subDays(8));

    $this->artisan('media:prune')->assertExitCode(0);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse();
});

test('media:prune leaves a failed row younger than seven days alone', function () {
    $sha256 = str_repeat('i', 64);

    $media = Media::factory()->create([
        'status' => 'failed',
        'disk' => 's3',
        'path' => "projects/{$sha256}/original.jpg",
        'sha256' => $sha256,
        'variants' => null,
    ]);

    mediaBackdate($media, now()->subDays(6));

    $this->artisan('media:prune')->assertExitCode(0);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeTrue();
});

test('media:prune hard-deletes a row whose mediable no longer exists', function () {
    $sha256 = str_repeat('j', 64);

    $media = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => 999_999,
        'status' => 'processed',
        'disk' => 's3',
        'path' => "projects/{$sha256}/original.jpg",
        'sha256' => $sha256,
        'variants' => null,
    ]);

    $this->artisan('media:prune')->assertExitCode(0);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse();
});

test('media:prune is scheduled daily', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('media:prune')
        ->assertExitCode(0);
});
