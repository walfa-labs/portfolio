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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Write validation across the content resources (§4.5, §4.6, §11.4)
|--------------------------------------------------------------------------
|
| §11.4 gives this file "`422` + exact `assertJsonValidationErrors` keys on
| `POST`/`PUT`/`PATCH` per resource", and §11.3 spells out why the *key* matters:
| a status alone cannot tell a refused `slug` from a missing `title`.
|
| Two facts about the envelope decide how that assertion is spelled here.
| §4.6 makes every error a JSON:API document, so `errors` is a **list** of error
| objects and the failing key is carried in `source.pointer` as an RFC 6901
| pointer into the request document (`data.attributes.slug` → `/data/attributes/slug`).
| Laravel's `assertJsonValidationErrors()` reads the framework's own
| `{errors: {key: [message]}}` map — `Illuminate/Testing/TestResponse.php:1164`
| does `Arr::get($json, 'errors')` and then `assertArrayHasKey($key, …)`, so it
| cannot see a list. The key is therefore asserted against the pointer, with
| `apiValidationPointers()` additionally proving it is the **only** failing key.
| The mapping itself is `JsonApiError::validationSource()` (the rule key with
| `.` replaced by `/`), which is why the pointers below read as dotted keys.
|
| §4.5's write carve-outs are what most of these cases exercise: `title` is
| required on `POST` and on `PUT` but not on `PATCH`; `slug` and the timestamps
| are not writable at all; `body_html` and `reading_time_minutes` are derived;
| a member the resource does not declare is refused rather than silently
| dropped; and a `PUT` resets every writable member it was not sent.
|
| Writes go through the `api-admin` group, so every request here carries the
| JSON:API media type — without it the write guard answers `415` before any
| validation runs (§4.6). `api-admin-write` is 60/min keyed by the authenticated
| user id and the `array` cache store lives for the whole process, so
| `apiValidationAdmin()` empties the bucket for the user it creates;
| `api-public-read` is emptied in `beforeEach()` for the same reason.
|
*/

dataset('content resources', [
    'work-experiences' => [
        'work-experiences',
        WorkExperience::class,
        'work_experiences',
        ['title', 'employer', 'start_date'],
        [
            'title' => 'Validation Work Experience',
            'employer' => 'Validation Employer',
            'start_date' => '2021-01-01',
        ],
    ],
    'educations' => [
        'educations',
        Education::class,
        'educations',
        ['title', 'institution'],
        [
            'title' => 'Validation Education',
            'institution' => 'Validation Institution',
        ],
    ],
    'certifications' => [
        'certifications',
        Certification::class,
        'certifications',
        ['title', 'issuer', 'issued_on'],
        [
            'title' => 'Validation Certification',
            'issuer' => 'Validation Issuer',
            'issued_on' => '2021-01-01',
        ],
    ],
    'publications' => [
        'publications',
        Publication::class,
        'publications',
        ['title', 'venue', 'issued_on'],
        [
            'title' => 'Validation Publication',
            'venue' => 'Validation Venue',
            'issued_on' => '2021-01-01',
        ],
    ],
    'projects' => [
        'projects',
        Project::class,
        'projects',
        ['title'],
        ['title' => 'Validation Project'],
    ],
    'awards' => [
        'awards',
        Award::class,
        'awards',
        ['title', 'awarding_body', 'awarded_at'],
        [
            'title' => 'Validation Award',
            'awarding_body' => 'Validation Awarding Body',
            'awarded_at' => '2021-01-01',
        ],
    ],
    'writings' => [
        'writings',
        Writing::class,
        'writings',
        ['title'],
        ['title' => 'Validation Writing'],
    ],
]);

/**
 * The administrator whose session authorises every write (§4.11).
 */
function apiValidationAdmin(): User
{
    $admin = User::factory()->administrator()->create();

    RateLimiter::clear(md5('api-admin-write'.$admin->getAuthIdentifier()));

    return $admin;
}

/**
 * The one `Content-Type` a write is allowed to declare (§4.6).
 *
 * @return array<string, string>
 */
function apiValidationJsonApi(): array
{
    return ['Content-Type' => 'application/vnd.api+json'];
}

/**
 * A JSON:API request document for one resource.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, array<string, mixed>>
 */
function apiValidationDocument(string $type, array $attributes, ?int $id = null): array
{
    $data = ['type' => $type, 'attributes' => $attributes];

    if ($id !== null) {
        $data['id'] = (string) $id;
    }

    return ['data' => $data];
}

/**
 * Every `source.pointer` in a validation document, in document order.
 *
 * @return list<string>
 */
function apiValidationPointers(TestResponse $response): array
{
    return collect($response->json('errors'))
        ->pluck('source.pointer')
        ->filter()
        ->values()
        ->all();
}

/**
 * Assert a `422` whose **only** failing member is the one at `$pointer`.
 *
 * Asserting the whole list rather than just its first member is what makes the
 * key exact: a response that named the right member *and* an unrelated one
 * still fails (§11.3).
 */
function apiValidationAssert422(TestResponse $response, string $pointer): void
{
    $response->assertStatus(422);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.status', '422');
    $response->assertJsonPath('errors.0.code', 'validation_failed');

    expect(apiValidationPointers($response))->toBe([$pointer]);
}

beforeEach(function () {
    RateLimiter::clear(md5('api-public-read127.0.0.1'));
});

test('POST without an attributes member is a 422 naming every required member', function (string $segment, string $modelClass, string $type, array $required, array $attributes) {
    $this->actingAs(apiValidationAdmin());

    $response = $this->postJson(
        "/api/v1/{$segment}",
        ['data' => ['type' => $type]],
        apiValidationJsonApi(),
    );

    $response->assertStatus(422);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.code', 'validation_failed');

    // `title` is one of them, and the per-type NOT NULL columns (§2.2, §2.3)
    // are the rest — never a key the column does not have.
    expect(apiValidationPointers($response))->toBe(
        collect($required)->map(fn (string $attribute): string => "/data/attributes/{$attribute}")->all(),
    );
})->with('content resources');

test('POST omitting a required attribute is a 422 naming exactly that key', function (string $segment, string $modelClass, string $type, array $required, array $attributes) {
    $this->actingAs(apiValidationAdmin());

    foreach ($required as $attribute) {
        $response = $this->postJson(
            "/api/v1/{$segment}",
            apiValidationDocument($type, Arr::except($attributes, [$attribute])),
            apiValidationJsonApi(),
        );

        apiValidationAssert422($response, "/data/attributes/{$attribute}");
    }
})->with('content resources');

test('PUT omitting a required attribute is a 422 naming exactly that key', function (string $segment, string $modelClass, string $type, array $required, array $attributes) {
    $this->actingAs(apiValidationAdmin());

    $record = $modelClass::factory()->create($attributes);

    foreach ($required as $attribute) {
        $response = $this->putJson(
            "/api/v1/{$segment}/{$record->getKey()}",
            apiValidationDocument($type, Arr::except($attributes, [$attribute]), (int) $record->getKey()),
            apiValidationJsonApi(),
        );

        apiValidationAssert422($response, "/data/attributes/{$attribute}");
    }
})->with('content resources');

test('PATCH leaves an omitted title untouched, where PUT requires it', function (string $segment, string $modelClass, string $type, array $required, array $attributes) {
    $this->actingAs(apiValidationAdmin());

    $record = $modelClass::factory()->create($attributes);

    $response = $this->patchJson(
        "/api/v1/{$segment}/{$record->getKey()}",
        apiValidationDocument($type, ['summary' => 'Amended summary'], (int) $record->getKey()),
        apiValidationJsonApi(),
    );

    // §4.5: the document's `data.type` must equal the endpoint's type, so a
    // `409` here means the controller compares against a type the resource does
    // not publish. That is the case for `educations`, where
    // `ApiController::type()` pluralises `education` to itself while
    // `EducationResource` publishes `educations` (§2.3, §4.2).
    expect($response->status())->toBe(
        200,
        "PATCH /api/v1/{$segment} must accept the data.type \"{$type}\" that its own resource publishes (§4.5).",
    );

    expect($record->refresh()->title)->toBe($attributes['title'])
        ->and($record->summary)->toBe('Amended summary');
})->with('content resources');

test('a status outside the enum is a 422 on POST and on PATCH', function (string $segment, string $modelClass, string $type, array $required, array $attributes) {
    $this->actingAs(apiValidationAdmin());

    $post = $this->postJson(
        "/api/v1/{$segment}",
        apiValidationDocument($type, [...$attributes, 'status' => 'archived']),
        apiValidationJsonApi(),
    );

    apiValidationAssert422($post, '/data/attributes/status');

    $record = $modelClass::factory()->create($attributes);

    $patch = $this->patchJson(
        "/api/v1/{$segment}/{$record->getKey()}",
        apiValidationDocument($type, ['status' => 'archived'], (int) $record->getKey()),
        apiValidationJsonApi(),
    );

    apiValidationAssert422($patch, '/data/attributes/status');
})->with('content resources');

test('a non-writable attribute is a 422 naming its source.pointer', function (string $segment, string $modelClass, string $type, array $required, array $attributes) {
    $this->actingAs(apiValidationAdmin());

    foreach (['id', 'slug', 'created_at', 'updated_at', 'deleted_at'] as $member) {
        $response = $this->postJson(
            "/api/v1/{$segment}",
            apiValidationDocument($type, [...$attributes, $member => '2020-01-01']),
            apiValidationJsonApi(),
        );

        apiValidationAssert422($response, "/data/attributes/{$member}");
    }
})->with('content resources');

test('an attribute the resource does not declare is refused rather than dropped', function (string $segment, string $modelClass, string $type, array $required, array $attributes) {
    $this->actingAs(apiValidationAdmin());

    $response = $this->postJson(
        "/api/v1/{$segment}",
        apiValidationDocument($type, [...$attributes, 'nonsense' => 'x']),
        apiValidationJsonApi(),
    );

    apiValidationAssert422($response, '/data/attributes/nonsense');
})->with('content resources');

test('slug is never writable, on any of the three write verbs', function () {
    $this->actingAs(apiValidationAdmin());

    $writing = Writing::factory()->create(['title' => 'Slug Writing']);
    $document = apiValidationDocument('writings', ['title' => 'Slug Writing', 'slug' => 'hand-written'], (int) $writing->getKey());

    apiValidationAssert422(
        $this->postJson('/api/v1/writings', $document, apiValidationJsonApi()),
        '/data/attributes/slug',
    );

    apiValidationAssert422(
        $this->putJson("/api/v1/writings/{$writing->getKey()}", $document, apiValidationJsonApi()),
        '/data/attributes/slug',
    );

    apiValidationAssert422(
        $this->patchJson("/api/v1/writings/{$writing->getKey()}", $document, apiValidationJsonApi()),
        '/data/attributes/slug',
    );

    // The derived slug survives untouched: §2.4 derives it server-side and keeps
    // it stable, and a refused write changed nothing.
    expect($writing->refresh()->slug)->toBe('slug-writing');
});

test('the derived writing attributes are never writable', function () {
    $this->actingAs(apiValidationAdmin());

    $writing = Writing::factory()->create(['title' => 'Derived Writing']);

    foreach (['body_html' => '<p>Hand-written</p>', 'reading_time_minutes' => 7] as $attribute => $value) {
        $attributes = ['title' => 'Derived Writing', $attribute => $value];

        apiValidationAssert422(
            $this->postJson('/api/v1/writings', apiValidationDocument('writings', $attributes), apiValidationJsonApi()),
            "/data/attributes/{$attribute}",
        );

        apiValidationAssert422(
            $this->putJson("/api/v1/writings/{$writing->getKey()}", apiValidationDocument('writings', $attributes, (int) $writing->getKey()), apiValidationJsonApi()),
            "/data/attributes/{$attribute}",
        );
    }
});

test('PUT resets an omitted status to draft and an omitted nullable member to null', function () {
    $this->actingAs(apiValidationAdmin());

    $writing = Writing::factory()->published()->create([
        'title' => 'Reset Writing',
        'summary' => 'Reset summary',
        'meta_title' => 'Reset meta title',
    ]);

    $response = $this->putJson(
        "/api/v1/writings/{$writing->getKey()}",
        apiValidationDocument('writings', ['title' => 'Reset Writing'], (int) $writing->getKey()),
        apiValidationJsonApi(),
    );

    $response->assertOk();

    $writing->refresh();

    expect($writing->status)->toBe(ContentStatus::Draft)
        ->and($writing->published_at)->toBeNull()
        ->and($writing->summary)->toBeNull()
        ->and($writing->meta_title)->toBeNull()
        ->and($writing->slug)->toBe('reset-writing');
});

test('PUT resets an omitted is_featured to its column default rather than to null', function () {
    $this->actingAs(apiValidationAdmin());

    $project = Project::factory()->featured()->create(['title' => 'Reset Project']);

    $response = $this->putJson(
        "/api/v1/projects/{$project->getKey()}",
        apiValidationDocument('projects', ['title' => 'Reset Project'], (int) $project->getKey()),
        apiValidationJsonApi(),
    );

    $response->assertOk();

    expect($project->refresh()->is_featured)->toBeFalse();
});

test('a data.type or data.id that contradicts the route is a 409, not a 422', function () {
    $this->actingAs(apiValidationAdmin());

    $writing = Writing::factory()->create(['title' => 'Conflict Writing']);

    $type = $this->putJson(
        "/api/v1/writings/{$writing->getKey()}",
        ['data' => [
            'type' => 'projects',
            'id' => (string) $writing->getKey(),
            'attributes' => ['title' => 'Conflict Writing'],
        ]],
        apiValidationJsonApi(),
    );

    $type->assertStatus(409);
    $type->assertJsonPath('errors.0.code', 'conflict');
    $type->assertJsonPath('errors.0.source.pointer', '/data/type');

    $id = $this->patchJson(
        "/api/v1/writings/{$writing->getKey()}",
        ['data' => [
            'type' => 'writings',
            'id' => '999999',
            'attributes' => ['summary' => 'Amended summary'],
        ]],
        apiValidationJsonApi(),
    );

    $id->assertStatus(409);
    $id->assertJsonPath('errors.0.code', 'conflict');
    $id->assertJsonPath('errors.0.source.pointer', '/data/id');
});

test('the 422 document is the JSON:API envelope, so the failing key is its source.pointer', function () {
    $this->actingAs(apiValidationAdmin());

    $response = $this->postJson('/api/v1/projects', ['data' => ['type' => 'projects']], apiValidationJsonApi());

    $response->assertStatus(422);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonMissingPath('data');
    $response->assertJsonStructure([
        'errors' => [['status', 'code', 'title', 'detail', 'meta' => ['request_id']]],
    ]);

    // §4.6: a list of error objects, never a map keyed by field — which is why
    // the exact key is read from `source.pointer` rather than through
    // `assertJsonValidationErrors()`. See this file's header.
    expect(array_keys($response->json('errors')))->toBe([0]);

    expect($response->json('errors.0.status'))->toBe('422')
        ->and($response->json('errors.0.code'))->toBe('validation_failed')
        ->and($response->json('errors.0.title'))->toBe('Validation failed')
        ->and($response->json('errors.0.detail'))->toBe('The data.attributes.title field is required.')
        ->and($response->json('errors.0.source.pointer'))->toBe('/data/attributes/title')
        ->and($response->json('errors.0.meta.request_id'))->toBeString()->not->toBe('');

    // §4.12: an error is never shared-cached. Symfony appends `private` to a
    // bare `no-store`, which is the framework's directive, not a decision here.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('the same envelope carries source.parameter for a query parameter', function () {
    Project::factory()->published()->create(['title' => 'Parameter Project']);

    $response = $this->getJson('/api/v1/projects?page[size]=101');

    $response->assertStatus(400);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.code', 'invalid_query_parameter');
    $response->assertJsonPath('errors.0.source.parameter', 'page[size]');
    $response->assertJsonMissingPath('errors.0.source.pointer');
});

test('credential_id is validated against the §2.3 pattern', function () {
    $this->actingAs(apiValidationAdmin());

    $accepted = $this->postJson('/api/v1/certifications', apiValidationDocument('certifications', [
        'title' => 'Pattern Certification',
        'issuer' => 'Pattern Issuer',
        'issued_on' => '2021-01-01',
        'credential_id' => 'AB-1234-5678',
    ]), apiValidationJsonApi());

    $accepted->assertCreated();

    $rejected = $this->postJson('/api/v1/certifications', apiValidationDocument('certifications', [
        'title' => 'Pattern Certification Two',
        'issuer' => 'Pattern Issuer',
        'issued_on' => '2021-01-01',
        'credential_id' => 'not a credential id!',
    ]), apiValidationJsonApi());

    apiValidationAssert422($rejected, '/data/attributes/credential_id');
});

test('doi is validated against the §2.3 identifier pattern', function () {
    $this->actingAs(apiValidationAdmin());

    $accepted = $this->postJson('/api/v1/publications', apiValidationDocument('publications', [
        'title' => 'Pattern Publication',
        'venue' => 'Pattern Venue',
        'issued_on' => '2021-01-01',
        'doi' => '10.1234/pattern.2021.1',
    ]), apiValidationJsonApi());

    $accepted->assertCreated();

    $rejected = $this->postJson('/api/v1/publications', apiValidationDocument('publications', [
        'title' => 'Pattern Publication Two',
        'venue' => 'Pattern Venue',
        'issued_on' => '2021-01-01',
        'doi' => 'not-a-doi',
    ]), apiValidationJsonApi());

    apiValidationAssert422($rejected, '/data/attributes/doi');
});
