<?php

use App\Enums\ContentStatus;
use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Models\Writing;

dataset('content models', [
    'work_experience' => [WorkExperience::class],
    'education' => [Education::class],
    'certification' => [Certification::class],
    'publication' => [Publication::class],
    'project' => [Project::class],
    'award' => [Award::class],
    'writing' => [Writing::class],
]);

/**
 * Normalise a column of primary keys so the assertions do not depend on the
 * driver's integer/string affinity.
 *
 * @param  iterable<int, mixed>  $ids
 * @return list<int>
 */
function contentModelIds(iterable $ids): array
{
    return array_map(intval(...), is_array($ids) ? $ids : iterator_to_array($ids));
}

test('a slug is derived from the title on create', function (string $modelClass) {
    $model = $modelClass::factory()->create(['title' => 'Hello, World! 2026']);

    expect($model->slug)->toBe('hello-world-2026');
})->with('content models');

test('the slug is stable when the title is updated', function (string $modelClass) {
    $model = $modelClass::factory()->create(['title' => 'Original Title']);

    expect($model->slug)->toBe('original-title');

    $model->update(['title' => 'A Completely Different Title']);

    expect($model->refresh()->slug)->toBe('original-title');
})->with('content models');

test('a colliding title gets a numeric suffix', function (string $modelClass) {
    $first = $modelClass::factory()->create(['title' => 'Duplicate Title']);
    $second = $modelClass::factory()->create(['title' => 'Duplicate Title']);
    $third = $modelClass::factory()->create(['title' => 'Duplicate Title']);

    expect([$first->slug, $second->slug, $third->slug])
        ->toBe(['duplicate-title', 'duplicate-title-2', 'duplicate-title-3']);
})->with('content models');

test('a pathological title round-trips through the database without error', function (string $modelClass) {
    // '@' dictionary-expands to 'at' inside Str::slug(), so a 150-character title of
    // '@' canonicalises to 449 characters — three times the varchar(191) slug column.
    // The derived slug must be cut back, and a colliding second row must get a
    // suffixed slug that still fits.
    $title = str_repeat('@', 150);

    $first = $modelClass::factory()->create(['title' => $title]);
    $second = $modelClass::factory()->create(['title' => $title]);

    expect($first->slug)->not->toBe($second->slug)
        ->and(strlen($first->slug))->toBeLessThanOrEqual(191)
        ->and(strlen($second->slug))->toBeLessThanOrEqual(191)
        ->and($modelClass::query()->where('slug', $second->slug)->exists())->toBeTrue();
})->with('content models');

test('a mass-assigned slug is ignored in favour of the derived one', function (string $modelClass) {
    $model = $modelClass::factory()->create(['title' => 'Derived Slug Wins', 'slug' => 'client-supplied-slug']);

    expect($model->slug)->toBe('derived-slug-wins')
        ->and($model->slug)->not->toBe('client-supplied-slug');
})->with('content models');

test('slug uniqueness is scoped to each table', function () {
    $slugs = [];

    foreach ([WorkExperience::class, Education::class, Certification::class, Publication::class, Project::class, Award::class, Writing::class] as $modelClass) {
        $slugs[] = $modelClass::factory()->create(['title' => 'Shared Title'])->slug;
    }

    expect($slugs)->each->toBe('shared-title');
});

test('a soft-deleted row still reserves its slug', function (string $modelClass) {
    $trashed = $modelClass::factory()->create(['title' => 'Reserved Title']);
    $trashed->delete();

    $replacement = $modelClass::factory()->create(['title' => 'Reserved Title']);

    expect($replacement->slug)->toBe('reserved-title-2')
        ->and($replacement->slug)->not->toBe($trashed->slug);
})->with('content models');

test('the published scope includes only published rows whose time has passed', function (string $modelClass) {
    $modelClass::factory()->draft()->create();

    $scheduled = $modelClass::factory()->create([
        'status' => ContentStatus::Published,
        'published_at' => now()->addDay(),
    ]);

    $undated = $modelClass::factory()->create([
        'status' => ContentStatus::Published,
        'published_at' => null,
    ]);

    $live = $modelClass::factory()->create([
        'status' => ContentStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    expect(contentModelIds($modelClass::query()->published()->pluck('id')))
        ->toBe([$live->getKey()]);
})->with('content models');

test('publish preserves an already scheduled publication time', function (string $modelClass) {
    $scheduled = now()->addDays(3)->startOfMinute();

    $model = $modelClass::factory()->draft()->create(['published_at' => $scheduled]);

    $model->publish();

    expect($model->refresh()->status)->toBe(ContentStatus::Published)
        ->and($model->published_at?->toDateTimeString())->toBe($scheduled->toDateTimeString())
        ->and($modelClass::query()->published()->whereKey($model->getKey())->exists())->toBeFalse();
})->with('content models');

test('publish stamps the publication time when it is not set', function (string $modelClass) {
    $model = $modelClass::factory()->draft()->create(['published_at' => null]);

    $before = now()->startOfSecond();
    $model->publish();
    $after = now()->addSecond()->startOfSecond();

    expect($model->refresh()->status)->toBe(ContentStatus::Published)
        ->and($model->published_at)->not->toBeNull()
        ->and($model->published_at->greaterThanOrEqualTo($before))->toBeTrue()
        ->and($model->published_at->lessThanOrEqualTo($after))->toBeTrue();
})->with('content models');

test('unpublish returns the row to draft and clears the publication time', function (string $modelClass) {
    $model = $modelClass::factory()->published()->create();

    expect($model->published_at)->not->toBeNull();

    $model->unpublish();

    expect($model->refresh()->status)->toBe(ContentStatus::Draft)
        ->and($model->published_at)->toBeNull()
        ->and($modelClass::query()->published()->whereKey($model->getKey())->exists())->toBeFalse();
})->with('content models');

test('every content model soft deletes', function (string $modelClass) {
    $model = $modelClass::factory()->create();

    $model->delete();

    expect($modelClass::query()->whereKey($model->getKey())->exists())->toBeFalse()
        ->and($modelClass::withTrashed()->whereKey($model->getKey())->exists())->toBeTrue()
        ->and($modelClass::withTrashed()->find($model->getKey())->trashed())->toBeTrue();

    $this->assertSoftDeleted($model->getTable(), ['id' => $model->getKey()]);
})->with('content models');

test('force deleting detaches tags without error', function (string $modelClass) {
    $model = $modelClass::factory()->create();
    $tag = Tag::factory()->create();

    $model->tags()->attach($tag);

    expect($model->tags()->count())->toBe(1);

    $model->forceDelete();

    expect($model->tags()->count())->toBe(0)
        ->and($modelClass::withTrashed()->whereKey($model->getKey())->exists())->toBeFalse()
        ->and(Tag::query()->whereKey($tag->getKey())->exists())->toBeTrue();
})->with('content models');

test('tags attach through the polymorphic relation', function (string $modelClass) {
    $model = $modelClass::factory()->create();
    $tag = Tag::factory()->create();

    $model->tags()->attach($tag);

    expect($model->tags()->count())->toBe(1)
        ->and($model->tags()->first()->is($tag))->toBeTrue()
        ->and(contentModelIds($model->fresh()->tags->pluck('id')))->toBe([$tag->getKey()]);
})->with('content models');

test('the same tag attaches to more than one model instance', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel']);

    $first = Project::factory()->create(['title' => 'First Project']);
    $second = Project::factory()->create(['title' => 'Second Project']);

    $first->tags()->attach($tag);
    $second->tags()->attach($tag);

    expect(Tag::query()->count())->toBe(1)
        ->and($first->tags()->count())->toBe(1)
        ->and($second->tags()->count())->toBe(1)
        ->and($tag->taggables()->count())->toBe(2)
        ->and(contentModelIds($tag->taggables()->pluck('taggable_id')))
        ->toEqualCanonicalizing([$first->getKey(), $second->getKey()]);
});

test('the is_placeholder column defaults to false when the attribute is not set', function (string $modelClass) {
    $model = $modelClass::factory()->make();
    $model->offsetUnset('is_placeholder');
    $model->save();

    expect($model->refresh()->is_placeholder)->toBeFalse();
})->with('content models');

test('the content factories default is_placeholder to true and the real state to false', function (string $modelClass) {
    expect($modelClass::factory()->create()->is_placeholder)->toBeTrue()
        ->and($modelClass::factory()->real()->create()->is_placeholder)->toBeFalse();
})->with('content models');

test('work experience orders current roles first, then by end date descending with an id tiebreaker', function () {
    $oldest = WorkExperience::factory()->create([
        'title' => 'Oldest Role',
        'start_date' => '2015-01-01',
        'end_date' => '2020-01-01',
    ]);

    $earlierTie = WorkExperience::factory()->create([
        'title' => 'Tied Role One',
        'start_date' => '2021-01-01',
        'end_date' => '2023-06-30',
    ]);

    $laterTie = WorkExperience::factory()->create([
        'title' => 'Tied Role Two',
        'start_date' => '2021-06-01',
        'end_date' => '2023-06-30',
    ]);

    $current = WorkExperience::factory()->current()->create([
        'title' => 'Current Role',
        'start_date' => '2024-01-01',
    ]);

    expect(contentModelIds(WorkExperience::query()->currentFirst()->pluck('id')))
        ->toBe([$current->getKey(), $laterTie->getKey(), $earlierTie->getKey(), $oldest->getKey()]);
});

test('education resolves the educations table', function () {
    $education = Education::factory()->create(['title' => 'BSc Computing']);

    expect($education->getTable())->toBe('educations')
        ->and($education->slug)->toBe('bsc-computing');

    $this->assertDatabaseHas('educations', [
        'id' => $education->getKey(),
        'slug' => 'bsc-computing',
    ]);
});

test('publication exposes a doi resolver url and null when the doi is missing', function () {
    $withDoi = Publication::factory()->create(['doi' => '10.1234/abcd.5678']);
    $withoutDoi = Publication::factory()->create(['doi' => null]);

    expect($withDoi->doi_url)->toBe('https://doi.org/10.1234/abcd.5678')
        ->and($withoutDoi->doi_url)->toBeNull();
});

test('project exposes is_featured as a boolean', function () {
    $featured = Project::factory()->featured()->create();
    $plain = Project::factory()->create(['is_featured' => false]);

    expect($featured->is_featured)->toBeTrue()
        ->and($plain->is_featured)->toBeFalse()
        ->and(Project::query()->where('is_featured', true)->whereKey($featured->getKey())->exists())->toBeTrue();
});

test('writing renders body_html from markdown and computes the reading time', function () {
    $short = Writing::factory()->create([
        'title' => 'Rendering Notes',
        'body' => "## A heading\n\nHello world.",
    ]);

    expect($short->body_html)->toContain('<h2>A heading</h2>')
        ->and($short->body_html)->toContain('<p>Hello world.</p>')
        ->and($short->reading_time_minutes)->toBe(1);

    $long = Writing::factory()->create([
        'title' => 'A Long Note',
        'body' => implode(' ', array_fill(0, 600, 'word')),
    ]);

    expect($long->reading_time_minutes)->toBe(3);
});

test('writing leaves body_html and the reading time null when the body is empty', function () {
    $nullBody = Writing::factory()->create(['body' => null]);
    $emptyBody = Writing::factory()->create(['body' => '']);

    expect($nullBody->body_html)->toBeNull()
        ->and($nullBody->reading_time_minutes)->toBeNull()
        ->and($emptyBody->body_html)->toBeNull()
        ->and($emptyBody->reading_time_minutes)->toBeNull();
});

test('clearing the body clears the derived columns', function () {
    $writing = Writing::factory()->create(['body' => 'Some text.']);

    expect($writing->body_html)->not->toBeNull()
        ->and($writing->reading_time_minutes)->not->toBeNull();

    $writing->update(['body' => '']);

    expect($writing->refresh()->body_html)->toBeNull()
        ->and($writing->reading_time_minutes)->toBeNull();
});
