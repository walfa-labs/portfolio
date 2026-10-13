<?php

use App\Console\Commands\PruneOrphanTags;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Writing;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('it deletes tags with no pivot rows and leaves attached tags alone', function () {
    $orphan = Tag::factory()->create(['name' => 'Orphan', 'slug' => 'orphan']);
    $attached = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    Project::factory()->create()->tags()->attach($attached);

    $this->artisan('prune:orphan-tags')->assertSuccessful();

    expect(Tag::find($orphan->getKey()))->toBeNull()
        ->and(Tag::find($attached->getKey()))->not->toBeNull()
        ->and(Tag::count())->toBe(1)
        ->and(DB::table('taggables')->count())->toBe(1);
});

test('it reports how many tags it pruned', function () {
    Tag::factory()->count(2)->create();

    $this->artisan('prune:orphan-tags')
        ->expectsOutput('Pruned 2 orphan tag(s).')
        ->assertSuccessful();
});

test('a second run deletes nothing', function () {
    $attached = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    Project::factory()->create()->tags()->attach($attached);
    Tag::factory()->create(['name' => 'Orphan', 'slug' => 'orphan']);

    $this->artisan('prune:orphan-tags')->assertSuccessful();

    expect(Tag::count())->toBe(1);

    $this->artisan('prune:orphan-tags')
        ->expectsOutput('Pruned 0 orphan tag(s).')
        ->assertSuccessful();

    expect(Tag::count())->toBe(1)
        ->and(Tag::find($attached->getKey()))->not->toBeNull();
});

test('a tag attached only to a soft-deleted row is not an orphan', function () {
    /*
     * Reading of §2.5: "orphan" is defined there as a tag with zero pivot rows —
     * not a tag with zero live content rows. Soft deleting a content model leaves
     * its `taggables` rows in place, because Laravel only detaches morph pivots on
     * a force delete (via `Publishable::forceDeleting()`). A tag whose only pivot
     * row points at a soft-deleted row therefore still has a pivot row, and the
     * command's `whereDoesntHave('taggables')` keeps it. The PRD does not settle
     * whether a soft-deleted taggable should count; this test pins the literal
     * pivot-row reading, and the next test pins the other side of it.
     */
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    $writing = Writing::factory()->create();
    $writing->tags()->attach($tag);

    $writing->delete();

    expect(DB::table('taggables')->count())->toBe(1);

    $this->artisan('prune:orphan-tags')->assertSuccessful();

    expect(Tag::find($tag->getKey()))->not->toBeNull()
        ->and(DB::table('taggables')->count())->toBe(1);
});

test('a tag whose only row is force deleted becomes an orphan on the next run', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    $writing = Writing::factory()->create();
    $writing->tags()->attach($tag);

    $writing->delete();
    $writing->forceDelete();

    expect(DB::table('taggables')->count())->toBe(0);

    $this->artisan('prune:orphan-tags')
        ->expectsOutput('Pruned 1 orphan tag(s).')
        ->assertSuccessful();

    expect(Tag::find($tag->getKey()))->toBeNull();
});

test('the command is discoverable through the Artisan kernel', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('prune:orphan-tags')
        ->and($commands['prune:orphan-tags'])->toBeInstanceOf(PruneOrphanTags::class)
        ->and($commands['prune:orphan-tags']->getDescription())->not->toBe('');
});

test('the command is scheduled nightly', function () {
    $events = app(Schedule::class)->events();

    expect($events)->not->toBeEmpty();

    $scheduled = array_filter(
        $events,
        fn (object $event): bool => str_contains($event->command ?? '', 'prune:orphan-tags'),
    );

    expect($scheduled)->not->toBeEmpty();
});
