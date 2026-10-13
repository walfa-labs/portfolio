<?php

use App\Enums\ContentType;
use App\Models\Project;
use App\Models\Tag;
use App\Models\WorkExperience;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create one row of every content type, attach the given tag to each, and return
 * the rows keyed by their morph alias.
 *
 * @return array<string, Model>
 */
function contentTagSharingAttachToAllTypes(Tag $tag): array
{
    $attached = [];

    foreach (ContentType::cases() as $type) {
        $model = $type->modelClass()::factory()->create();
        $model->tags()->attach($tag);

        $attached[$type->value] = $model;
    }

    return $attached;
}

test('a single tag row can be attached to all seven content types at once', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    contentTagSharingAttachToAllTypes($tag);

    expect(Tag::count())->toBe(1)
        ->and(DB::table('taggables')->count())->toBe(7)
        ->and($tag->taggables()->count())->toBe(7)
        ->and($tag->taggables()->distinct()->pluck('taggable_type'))->toHaveCount(7);
});

test('each content type finds only its own rows through the shared tag', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    $taggedIds = [];

    foreach (ContentType::cases() as $type) {
        $tagged = $type->modelClass()::factory()->create();
        $tagged->tags()->attach($tag);

        $type->modelClass()::factory()->create();

        $taggedIds[$type->value] = $tagged->getKey();
    }

    foreach (ContentType::cases() as $type) {
        $found = $type->modelClass()::query()
            ->whereHas('tags', fn ($query) => $query->where('tags.id', $tag->getKey()))
            ->pluck('id')
            ->all();

        expect($found)->toBe([$taggedIds[$type->value]]);
    }
});

test('the inverse tag relation resolves every attachment back to its own model', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    contentTagSharingAttachToAllTypes($tag);

    $resolved = $tag->taggables()
        ->with('taggable')
        ->get()
        ->mapWithKeys(fn ($pivot) => [$pivot->taggable_type => $pivot->taggable::class]);

    foreach (ContentType::cases() as $type) {
        expect($resolved[$type->value])->toBe($type->modelClass());
    }
});

test('detaching a tag from one model leaves the tag row and the other attachments intact', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $attached = contentTagSharingAttachToAllTypes($tag);

    $attached[ContentType::Project->value]->tags()->detach($tag);

    expect(Tag::find($tag->getKey()))->not->toBeNull()
        ->and(DB::table('taggables')->count())->toBe(6)
        ->and(Project::query()->whereHas('tags', fn ($query) => $query->where('tags.id', $tag->getKey()))->count())->toBe(0);

    foreach (ContentType::cases() as $type) {
        if ($type === ContentType::Project) {
            continue;
        }

        expect(
            $type->modelClass()::query()
                ->whereHas('tags', fn ($query) => $query->where('tags.id', $tag->getKey()))
                ->count()
        )->toBe(1);
    }
});

test('taggable_type stores the enum morph aliases, not class names or route segments', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    contentTagSharingAttachToAllTypes($tag);

    $stored = DB::table('taggables')
        ->distinct()
        ->orderBy('taggable_type')
        ->pluck('taggable_type')
        ->all();

    $expected = collect(ContentType::cases())
        ->map(fn (ContentType $type) => $type->value)
        ->sort()
        ->values()
        ->all();

    expect($stored)->toBe($expected)
        ->and($stored)->not->toContain('WorkExperience')
        ->and($stored)->not->toContain('work-experiences')
        ->and($stored)->not->toContain(WorkExperience::class)
        ->and((new WorkExperience)->getMorphClass())->toBe(ContentType::WorkExperience->value);
});

test('the unique pivot index rejects a duplicate tag, type and id triple', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    $project = Project::factory()->create();

    $project->tags()->attach($tag);

    expect(fn () => DB::table('taggables')->insert([
        'tag_id' => $tag->getKey(),
        'taggable_type' => ContentType::Project->value,
        'taggable_id' => $project->getKey(),
    ]))->toThrow(QueryException::class);

    expect(fn () => $project->tags()->attach($tag))->toThrow(QueryException::class);

    expect(DB::table('taggables')->count())->toBe(1);
});

test('force deleting a content row removes its pivot rows and keeps the tag', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $attached = contentTagSharingAttachToAllTypes($tag);

    // Award composes no HasMedia; Project does. Both detach through Publishable.
    $attached[ContentType::Award->value]->forceDelete();
    $attached[ContentType::Project->value]->forceDelete();

    expect(Tag::find($tag->getKey()))->not->toBeNull()
        ->and(DB::table('taggables')->whereIn('taggable_type', [
            ContentType::Award->value,
            ContentType::Project->value,
        ])->count())->toBe(0)
        ->and(DB::table('taggables')->count())->toBe(5);

    foreach (ContentType::cases() as $type) {
        if ($type === ContentType::Award || $type === ContentType::Project) {
            continue;
        }

        expect(
            $type->modelClass()::query()
                ->whereHas('tags', fn ($query) => $query->where('tags.id', $tag->getKey()))
                ->count()
        )->toBe(1);
    }
});
