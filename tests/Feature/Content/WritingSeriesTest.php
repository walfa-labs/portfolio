<?php

use App\Models\Writing;
use App\Models\WritingSeries;
use Illuminate\Support\Facades\Schema;

test('force deleting a referenced series succeeds and nulls the series id on its writings', function () {
    $series = WritingSeries::factory()->create(['title' => 'Referenced Series']);
    $writings = Writing::factory()->count(2)->create(['series_id' => $series->id]);

    $series->forceDelete();

    expect(WritingSeries::withTrashed()->whereKey($series->id)->exists())->toBeFalse()
        ->and(Writing::query()->count())->toBe(2);

    foreach ($writings as $writing) {
        $reloaded = $writing->fresh();

        expect($reloaded)->not->toBeNull()
            ->and($reloaded->series_id)->toBeNull()
            ->and($reloaded->title)->toBe($writing->title);
    }
});

test('force deleting a series leaves writings attached to another series alone', function () {
    $doomed = WritingSeries::factory()->create(['title' => 'Doomed Series']);
    $survivor = WritingSeries::factory()->create(['title' => 'Surviving Series']);

    $orphaned = Writing::factory()->create(['title' => 'Orphaned Writing', 'series_id' => $doomed->id]);
    $kept = Writing::factory()->create(['title' => 'Kept Writing', 'series_id' => $survivor->id]);

    $doomed->forceDelete();

    expect($orphaned->fresh()->series_id)->toBeNull()
        ->and($kept->fresh()->series_id)->toBe($survivor->id)
        ->and(WritingSeries::query()->whereKey($survivor->id)->exists())->toBeTrue();
});

test('a writing that belongs to no series is valid', function () {
    $column = collect(Schema::getColumns('writings'))->firstWhere('name', 'series_id');

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue();

    $writing = Writing::factory()->create(['title' => 'A Writing With No Series', 'series_id' => null]);

    expect($writing->exists)->toBeTrue()
        ->and($writing->series_id)->toBeNull()
        ->and($writing->series)->toBeNull()
        ->and(Writing::query()->whereKey($writing->id)->exists())->toBeTrue();
});

test('a writing series soft deletes normally and leaves its writings attached', function () {
    $series = WritingSeries::factory()->create(['title' => 'Soft Deleted Series']);
    $writing = Writing::factory()->create(['title' => 'Attached Writing', 'series_id' => $series->id]);

    $series->delete();

    expect($series->trashed())->toBeTrue()
        ->and(WritingSeries::query()->whereKey($series->id)->exists())->toBeFalse()
        ->and(WritingSeries::withTrashed()->whereKey($series->id)->exists())->toBeTrue()
        ->and($writing->fresh()->series_id)->toBe($series->id);
});

test('a soft deleted series is excluded from the default query only', function () {
    $series = WritingSeries::factory()->create(['title' => 'Hidden Series']);

    $series->delete();

    expect(WritingSeries::query()->count())->toBe(0)
        ->and(WritingSeries::withTrashed()->count())->toBe(1);
});

test('a writing resolves its series through the belongs-to relation', function () {
    $series = WritingSeries::factory()->create(['title' => 'Resolvable Series']);
    $writing = Writing::factory()->create(['title' => 'Resolvable Writing', 'series_id' => $series->id]);

    expect($writing->series)->not->toBeNull()
        ->and($writing->series->is($series))->toBeTrue();
});

test('a series exposes its writings through the inverse relation', function () {
    $series = WritingSeries::factory()->create(['title' => 'Inverse Relation Series']);
    $other = WritingSeries::factory()->create(['title' => 'Unrelated Series']);

    $first = Writing::factory()->create(['title' => 'First In Series', 'series_id' => $series->id]);
    $second = Writing::factory()->create(['title' => 'Second In Series', 'series_id' => $series->id]);
    Writing::factory()->create(['title' => 'In Another Series', 'series_id' => $other->id]);
    Writing::factory()->create(['title' => 'In No Series', 'series_id' => null]);

    expect($series->writings()->pluck('id')->all())->toEqualCanonicalizing([$first->id, $second->id])
        ->and($series->writings)->toHaveCount(2);
});
