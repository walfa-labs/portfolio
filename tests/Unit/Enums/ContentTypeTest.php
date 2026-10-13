<?php

use App\Enums\ContentType;
use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\WorkExperience;
use App\Models\Writing;
use Illuminate\Database\Eloquent\Model;

test('it exposes exactly the seven content-only cases', function () {
    $cases = ContentType::cases();

    expect($cases)->toHaveCount(7);
    expect(array_map(fn (ContentType $type) => $type->name, $cases))->toBe([
        'WorkExperience',
        'Education',
        'Certification',
        'Publication',
        'Project',
        'Award',
        'Writing',
    ]);
});

test('no case is backed by the user morph alias', function () {
    $aliases = array_map(fn (ContentType $type) => $type->value, ContentType::cases());

    expect($aliases)->not->toContain('user');
});

test('each case is backed by its morph alias', function (ContentType $type, string $alias) {
    expect($type->value)->toBe($alias);
})->with([
    [ContentType::WorkExperience, 'work_experience'],
    [ContentType::Education, 'education'],
    [ContentType::Certification, 'certification'],
    [ContentType::Publication, 'publication'],
    [ContentType::Project, 'project'],
    [ContentType::Award, 'award'],
    [ContentType::Writing, 'writing'],
]);

test('each case maps to its plural kebab-case route segment', function (ContentType $type, string $segment) {
    expect($type->routeSegment())->toBe($segment);
    expect($type->routeSegment())->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/');
})->with([
    [ContentType::WorkExperience, 'work-experiences'],
    [ContentType::Education, 'educations'],
    [ContentType::Certification, 'certifications'],
    [ContentType::Publication, 'publications'],
    [ContentType::Project, 'projects'],
    [ContentType::Award, 'awards'],
    [ContentType::Writing, 'writings'],
]);

test('each case maps to the Eloquent model that backs it', function (ContentType $type, string $class) {
    expect($type->modelClass())->toBe($class);
    expect(class_exists($class))->toBeTrue();
    expect(is_subclass_of($class, Model::class))->toBeTrue();
})->with([
    [ContentType::WorkExperience, WorkExperience::class],
    [ContentType::Education, Education::class],
    [ContentType::Certification, Certification::class],
    [ContentType::Publication, Publication::class],
    [ContentType::Project, Project::class],
    [ContentType::Award, Award::class],
    [ContentType::Writing, Writing::class],
]);

test('each case maps to its human-readable label', function (ContentType $type, string $label) {
    expect($type->label())->toBe($label);
})->with([
    [ContentType::WorkExperience, 'Work Experience'],
    [ContentType::Education, 'Education'],
    [ContentType::Certification, 'Certification'],
    [ContentType::Publication, 'Publication'],
    [ContentType::Project, 'Project'],
    [ContentType::Award, 'Award'],
    [ContentType::Writing, 'Writing'],
]);
