<?php

use App\Enums\ContentStatus;
use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\User;
use App\Models\WorkExperience;
use App\Models\Writing;
use App\Support\ContentSlug;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Create / read / update / delete through the admin UI (§5.9, §5.10, §11.4)
|--------------------------------------------------------------------------
|
| The seven content types are one dataset, so a verb that works for
| `work-experiences` and is broken for `awards` cannot pass. Every assertion
| is on an explicit attribute the test set itself — never on factory
| randomness or on an unspecified row order.
|
| The writes are Inertia visits, not API calls: the payload is flat form data
| (`tag_ids: int[]`, not the JSON:API relationship object), a successful write
| is a `302` back to the index with a `toast` flash, and the destructive routes
| carry `password.confirm`. The one read that renders a page — `edit` — is
| asserted through `assertInertia`, which needs the Vite build; the
| authorisation and the `record` prop it carries are verified the same way the
| rest of this file is.
|
| `tags` is deliberately absent: it is not a content type and has its own
| file, `AdminTagCrudTest`.
|
*/

dataset('admin content resources', [
    'work-experiences' => [
        'work-experiences',
        WorkExperience::class,
        'work_experience',
        true,
        'title',
        ['title' => 'Crud Work Experience', 'employer' => 'Crud Employer', 'start_date' => '2021-01-01'],
    ],
    'educations' => [
        'educations',
        Education::class,
        'education',
        true,
        'title',
        ['title' => 'Crud Education', 'institution' => 'Crud Institution'],
    ],
    'certifications' => [
        'certifications',
        Certification::class,
        'certification',
        true,
        'title',
        ['title' => 'Crud Certification', 'issuer' => 'Crud Issuer', 'issued_on' => '2021-01-01'],
    ],
    'publications' => [
        'publications',
        Publication::class,
        'publication',
        true,
        'title',
        ['title' => 'Crud Publication', 'venue' => 'Crud Venue', 'issued_on' => '2021-01-01'],
    ],
    'projects' => [
        'projects',
        Project::class,
        'project',
        true,
        'title',
        ['title' => 'Crud Project'],
    ],
    'awards' => [
        'awards',
        Award::class,
        'award',
        true,
        'title',
        ['title' => 'Crud Award', 'awarding_body' => 'Crud Awarding Body', 'awarded_at' => '2021-01-01'],
    ],
    'writings' => [
        'writings',
        Writing::class,
        'writing',
        false,
        'title',
        ['title' => 'Crud Writing'],
    ],
]);

/**
 * The six ordered types — every content type except `writings`, which §5.8
 * orders by `published_at` and therefore has no `reorder` route.
 */
dataset('admin orderable resources', [
    'work-experiences' => [
        'work-experiences',
        WorkExperience::class,
        'work_experience',
        'title',
        ['title' => 'Reorder Work Experience', 'employer' => 'Reorder Employer', 'start_date' => '2021-01-01'],
    ],
    'educations' => [
        'educations',
        Education::class,
        'education',
        'title',
        ['title' => 'Reorder Education', 'institution' => 'Reorder Institution'],
    ],
    'certifications' => [
        'certifications',
        Certification::class,
        'certification',
        'title',
        ['title' => 'Reorder Certification', 'issuer' => 'Reorder Issuer', 'issued_on' => '2021-01-01'],
    ],
    'publications' => [
        'publications',
        Publication::class,
        'publication',
        'title',
        ['title' => 'Reorder Publication', 'venue' => 'Reorder Venue', 'issued_on' => '2021-01-01'],
    ],
    'projects' => [
        'projects',
        Project::class,
        'project',
        'title',
        ['title' => 'Reorder Project'],
    ],
    'awards' => [
        'awards',
        Award::class,
        'award',
        'title',
        ['title' => 'Reorder Award', 'awarding_body' => 'Reorder Awarding Body', 'awarded_at' => '2021-01-01'],
    ],
]);

test('store creates the row and redirects with a toast', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    $response = $this->post("/admin/{$segment}", $createAttributes);

    $response->assertRedirect("/admin/{$segment}");
    // The admin flashes through `Inertia::flash`, which nests under
    // `inertia.flash_data` — not the bare `toast` key a plain redirect
    // `->with('toast', …)` would use.
    $response->assertSessionHas('inertia.flash_data.toast', fn (mixed $toast): bool => is_array($toast)
        && ($toast['type'] ?? null) === 'success'
        && filled($toast['message'] ?? null));

    // §5.9: the slug is derived server-side from the title on create and is
    // never a form field.
    $record = $modelClass::query()->where($titleKey, $createAttributes[$titleKey])->firstOrFail();

    expect($record->slug)->toBe(ContentSlug::canonicalize($createAttributes[$titleKey]));
})->with('admin content resources');

test('edit returns 200 with the record prop', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    $record = $modelClass::factory()->create($createAttributes);

    $response = $this->get("/admin/{$segment}/{$record->getKey()}/edit");

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component("admin/{$segment}/Edit")
        ->where('record.id', $record->getKey())
        ->where('record.title', $record->title)
        ->where('record.slug', $record->slug));
})->with('admin content resources');

test('update changes the row and keeps the slug stable', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    $record = $modelClass::factory()->create($createAttributes);
    $originalSlug = $record->slug;
    $newTitle = 'Updated '.$createAttributes[$titleKey];

    $response = $this->patch("/admin/{$segment}/{$record->getKey()}", [$titleKey => $newTitle]);

    $response->assertRedirect("/admin/{$segment}");

    $record->refresh();

    expect($record->getAttribute($titleKey))->toBe($newTitle)
        // §5.9: renaming a title never rewrites an existing slug.
        ->and($record->slug)->toBe($originalSlug);
})->with('admin content resources');

test('destroy soft-deletes the row', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    $record = $modelClass::factory()->create($createAttributes);

    $response = $this->withSession(['auth.password_confirmed_at' => time()])
        ->delete("/admin/{$segment}/{$record->getKey()}");

    $response->assertRedirect("/admin/{$segment}");
    $this->assertSoftDeleted($record->getTable(), ['id' => $record->getKey()]);
})->with('admin content resources');

test('publish flips status and published_at', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    $record = $modelClass::factory()->draft()->create($createAttributes);

    $response = $this->patch("/admin/{$segment}/{$record->getKey()}/publish");

    $response->assertRedirect("/admin/{$segment}");

    $record->refresh();

    expect($record->status)->toBe(ContentStatus::Published)
        ->and($record->published_at)->not->toBeNull();
})->with('admin content resources');

test('unpublish flips status and published_at', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    $record = $modelClass::factory()->published()->create($createAttributes);

    $response = $this->patch("/admin/{$segment}/{$record->getKey()}/unpublish");

    $response->assertRedirect("/admin/{$segment}");

    $record->refresh();

    expect($record->status)->toBe(ContentStatus::Draft)
        ->and($record->published_at)->toBeNull();
})->with('admin content resources');

test('reorder reorders an orderable type', function (string $segment, string $modelClass, string $parameter, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    /*
     * Three rows, all at the factory's `sort_order` of 0. The submitted array
     * is the order the admin chose, so the position in it *is* the new
     * `sort_order` (§5.9).
     */
    $first = $modelClass::factory()->create($createAttributes);
    $second = $modelClass::factory()->create($createAttributes);
    $third = $modelClass::factory()->create($createAttributes);

    $response = $this->patch("/admin/{$segment}/reorder", [
        'ids' => [$third->getKey(), $first->getKey(), $second->getKey()],
    ]);

    $response->assertRedirect("/admin/{$segment}");

    expect($third->fresh()->sort_order)->toBe(0)
        ->and($first->fresh()->sort_order)->toBe(1)
        ->and($second->fresh()->sort_order)->toBe(2);
})->with('admin orderable resources');

test('bulk destroy, publish and unpublish work', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    $first = $modelClass::factory()->draft()->create($createAttributes);
    $second = $modelClass::factory()->draft()->create($createAttributes);

    // Bulk publish: two drafts become published.
    $this->patch("/admin/{$segment}/publish", ['ids' => [$first->getKey(), $second->getKey()]])
        ->assertRedirect("/admin/{$segment}");

    expect($first->fresh()->status)->toBe(ContentStatus::Published)
        ->and($second->fresh()->status)->toBe(ContentStatus::Published);

    // Bulk unpublish: they return to draft.
    $this->patch("/admin/{$segment}/unpublish", ['ids' => [$first->getKey(), $second->getKey()]])
        ->assertRedirect("/admin/{$segment}");

    expect($first->fresh()->status)->toBe(ContentStatus::Draft)
        ->and($second->fresh()->status)->toBe(ContentStatus::Draft);

    // Bulk destroy: both are soft-deleted.
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->delete("/admin/{$segment}", ['ids' => [$first->getKey(), $second->getKey()]])
        ->assertRedirect("/admin/{$segment}");

    $this->assertSoftDeleted($first->getTable(), ['id' => $first->getKey()]);
    $this->assertSoftDeleted($second->getTable(), ['id' => $second->getKey()]);
})->with('admin content resources');

test('a validation failure redirects back with the exact error key', function (string $segment, string $modelClass, string $parameter, bool $orderable, string $titleKey, array $createAttributes) {
    $this->actingAs(User::factory()->administrator()->create());

    /*
     * Send every required field except the key column, so the only failing
     * rule is the one for that column and the assertion names the exact key
     * (§5.10).
     */
    $payload = Arr::except($createAttributes, $titleKey);

    $response = $this->from("/admin/{$segment}/create")->post("/admin/{$segment}", $payload);

    $response->assertStatus(302);
    $response->assertSessionHasErrors([$titleKey]);

    expect($modelClass::query()->count())->toBe(0);
})->with('admin content resources');
