<?php

use App\Enums\ContentStatus;

test('it exposes exactly the two content status cases', function () {
    $cases = ContentStatus::cases();

    expect($cases)->toHaveCount(2);
    expect(array_map(fn (ContentStatus $status) => $status->name, $cases))->toBe([
        'Draft',
        'Published',
    ]);
});

test('each case is backed by its exact value', function (ContentStatus $status, string $value) {
    expect($status->value)->toBe($value);
})->with([
    [ContentStatus::Draft, 'draft'],
    [ContentStatus::Published, 'published'],
]);

test('each case maps to its human-readable label', function (ContentStatus $status, string $label) {
    expect($status->label())->toBe($label);
})->with([
    [ContentStatus::Draft, 'Draft'],
    [ContentStatus::Published, 'Published'],
]);
