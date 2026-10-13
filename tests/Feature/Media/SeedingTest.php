<?php

use App\Models\Media;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Deterministic seeding (§9.9)
|--------------------------------------------------------------------------
|
| Placeholder imagery is generated locally at seed time, never fetched and
| never committed. `App\Support\PlaceholderImage` draws from a per-item seeded
| RNG (`mt_srand(crc32($itemKey))`), so the same item key always produces the
| same bytes — re-seeding is byte-identical rather than merely similar. The
| seeder writes those bytes to the disk and then runs the *same*
| `GenerateMediaVariants` job a production upload runs, so the seeded path is
| not a shortcut around the pipeline.
|
| Determinism is asserted end-to-end, through the seeder and the row's
| `sha256`, rather than against `PlaceholderImage`'s method signature: the
| content hash is the observable artefact the guarantee is about, and it stays
| valid if the generator's API changes.
|
| `Storage::fake('s3')` / `Storage::fake('s3-private')` stand in for Garage, so
| this proves the app generated the right bytes, not that a real S3 server
| stored them.
|
*/

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('s3-private');
});

/**
 * A stable `type:id:collection` => `sha256` map for every media row.
 *
 * The content hash is what the determinism guarantee is about, and the key is
 * stable across re-seeds because the seeder `updateOrCreate`s content on its
 * slug, so the ids persist.
 *
 * @return array<string, string>
 */
function mediaSeedHashMap(): array
{
    return Media::query()
        ->get()
        ->mapWithKeys(fn (Media $media): array => [
            implode('|', [$media->mediable_type, $media->mediable_id, $media->collection]) => $media->sha256,
        ])
        ->all();
}

test('seeding produces processed media rows whose objects are on the disk', function () {
    $this->seed();

    $media = Media::query()->get();

    expect($media)->not->toBeEmpty();

    foreach ($media as $row) {
        expect($row->status)->toBe('processed', "The {$row->collection} row was not processed.");

        $disk = Storage::disk($row->disk);

        expect($disk->exists($row->path))->toBeTrue(
            "The original for {$row->collection} is missing: {$row->mediable_type} #{$row->mediable_id} on [{$row->disk}] at [{$row->path}].",
        );

        foreach ($row->variants ?? [] as $key => $variant) {
            expect($disk->exists($variant['path']))->toBeTrue("The {$key} variant object is missing.");
        }
    }

    expect($media->contains(fn (Media $row): bool => filled($row->variants)))->toBeTrue();
});

test('re-seeding reproduces byte-identical placeholder files', function () {
    $this->seed();

    $before = mediaSeedHashMap();

    expect($before)->not->toBeEmpty();

    /*
     * The rows are cleared with the query builder, which fires no model events,
     * so the seeder has to regenerate the media rather than find it already
     * attached. That is what makes this a determinism test and not an
     * idempotency test.
     */
    Media::query()->delete();

    $this->seed();

    expect(mediaSeedHashMap())->toBe($before);
});

test('no generated media binaries are committed to git', function () {
    $result = Process::path(base_path())->run(['git', 'ls-files']);

    if (! $result->successful()) {
        $this->markTestSkipped('git is not available to enumerate tracked files.');
    }

    /*
     * §9.9 states the broader claim ("no image or PDF blobs tracked"), but the
     * starter kit this project is built on commits `public/apple-touch-icon.png`
     * and `public/favicon.svg`, so that literal assertion cannot hold. What the
     * phase-8 contract actually needs is that nothing the *media pipeline*
     * produces — the generated variants and the uploaded originals — is
     * tracked, and those are exactly these extensions.
     */
    $generatedExtensions = ['avif', 'webp', 'jpg', 'jpeg', 'pdf'];

    $tracked = collect(explode("\n", trim($result->output())))
        ->filter(fn (string $file): bool => in_array(
            strtolower(pathinfo($file, PATHINFO_EXTENSION)),
            $generatedExtensions,
            true,
        ))
        ->values()
        ->all();

    expect($tracked)->toBe([]);
});
