<?php

use App\Enums\ContentType;
use App\Models\Award;
use App\Models\Certification;
use App\Models\ContactMessage;
use App\Models\Education;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\Taggable;
use App\Models\User;
use App\Models\WorkExperience;
use App\Models\Writing;
use App\Models\WritingSeries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the database seeder runs cleanly and produces the SC-1 minimum corpus', function () {
    $this->seed();

    expect(WorkExperience::query()->count())->toBeGreaterThanOrEqual(6)
        ->and(Education::query()->count())->toBeGreaterThanOrEqual(4)
        ->and(Certification::query()->count())->toBeGreaterThanOrEqual(5)
        ->and(Publication::query()->count())->toBeGreaterThanOrEqual(3)
        ->and(Project::query()->count())->toBeGreaterThanOrEqual(8)
        ->and(Award::query()->count())->toBeGreaterThanOrEqual(2)
        ->and(Writing::query()->count())->toBeGreaterThanOrEqual(10)
        ->and(Tag::query()->count())->toBeGreaterThanOrEqual(15)
        ->and(WritingSeries::query()->count())->toBeGreaterThanOrEqual(2);
});

test('exactly one seeded work experience is current', function () {
    $this->seed();

    expect(WorkExperience::query()->whereNull('end_date')->count())->toBe(1)
        ->and(WorkExperience::query()->whereNotNull('end_date')->count())->toBeGreaterThanOrEqual(5);
});

test('the seeded projects include at least three featured ones', function () {
    $this->seed();

    expect(Project::query()->where('is_featured', true)->count())->toBeGreaterThanOrEqual(3)
        ->and(Project::query()->where('is_featured', false)->count())->toBeGreaterThan(0);
});

test('every seeded row that has an is_placeholder column carries true', function () {
    $this->seed();

    $models = [
        WorkExperience::class,
        Education::class,
        Certification::class,
        Publication::class,
        Project::class,
        Award::class,
        Writing::class,
        Tag::class,
    ];

    foreach ($models as $model) {
        $table = (new $model)->getTable();

        expect(Schema::hasColumn($table, 'is_placeholder'))->toBeTrue()
            ->and($model::query()->count())->toBeGreaterThan(0)
            ->and($model::query()->where('is_placeholder', false)->count())->toBe(0);
    }
});

test('the is_placeholder column defaults to false, so the seeded flag is real data', function () {
    $tag = Tag::query()->create(['name' => 'Unflagged Placeholder Probe']);

    expect($tag->fresh()->is_placeholder)->toBeFalse();
});

test('the seeders attach tags to every content type and share tags across types', function () {
    $this->seed();

    expect(Taggable::query()->count())->toBeGreaterThan(0);

    $attachedTypes = Taggable::query()->distinct()->pluck('taggable_type');

    foreach (ContentType::cases() as $type) {
        expect($attachedTypes)->toContain($type->value);
    }

    $typesPerTag = Taggable::query()
        ->get()
        ->groupBy('tag_id')
        ->map(fn (Collection $rows): int => $rows->pluck('taggable_type')->unique()->count());

    expect($typesPerTag->contains(fn (int $types): bool => $types >= 2))->toBeTrue();
});

test('the seeded administrator passes isAdministrator', function () {
    $this->seed();

    $administrator = User::query()->where('email', config('portfolio.admin.email'))->first();

    expect($administrator)->not->toBeNull()
        ->and($administrator->isAdministrator())->toBeTrue()
        ->and($administrator->email_verified_at)->not->toBeNull();
});

test('the seeded profile row exists', function () {
    $this->seed();

    $profile = Profile::query()->first();

    expect($profile)->not->toBeNull()
        ->and($profile->name)->not->toBe('')
        ->and($profile->role_headline)->not->toBe('');
});

test('re-running the seeders does not duplicate any rows', function () {
    $counts = fn (): array => [
        'work_experiences' => WorkExperience::query()->count(),
        'educations' => Education::query()->count(),
        'certifications' => Certification::query()->count(),
        'publications' => Publication::query()->count(),
        'projects' => Project::query()->count(),
        'awards' => Award::query()->count(),
        'writings' => Writing::query()->count(),
        'writing_series' => WritingSeries::query()->count(),
        'tags' => Tag::query()->count(),
        'taggables' => DB::table('taggables')->count(),
        'profile' => Profile::query()->count(),
        'users' => User::query()->count(),
    ];

    $this->seed();

    $before = $counts();

    $this->seed();

    expect($counts())->toBe($before)
        ->and(collect($before)->every(fn (int $count): bool => $count > 0))->toBeTrue();
});

test('a contact message is valid with a null request type', function () {
    $column = collect(Schema::getColumns('contact_messages'))->firstWhere('name', 'request_type');

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue();

    $message = ContactMessage::factory()->create(['request_type' => null]);

    expect($message->fresh()->request_type)->toBeNull();
});
