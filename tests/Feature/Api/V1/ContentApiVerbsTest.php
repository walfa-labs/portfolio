<?php

use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkExperience;
use App\Models\Writing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| The verb matrix (§4.5 Table B) across the eight Table A resources
|--------------------------------------------------------------------------
|
| Every resource in §4.2 answers the same six methods plus `OPTIONS` and
| `HEAD` — no exceptions, no per-resource verbs, and `tags` is a full
| six-method resource (§15.8). One dataset carries the eight rows so a verb
| that works on one resource and not on another cannot pass unnoticed.
|
| Reads are public and `published()`-scoped, so a fixture a read must expose
| is created through the `published()` state and a draft is created beside it.
| Writes are session-authenticated through the `api-admin` group (§4.11) and
| declare the JSON:API media type, which the write guard requires before the
| body is read (§4.6).
|
| `api-public-read` is 120/min keyed on the client IP and the `array` cache
| store lives for the whole process, so the bucket is emptied before every
| test — otherwise this file's own reads, or a neighbouring file's, would turn
| into 429s. `ThrottleRequests` keys a named limiter as
| `md5($limiterName.$limit->key)`.
|
*/

dataset('api verb resources', [
    'work-experiences' => [
        'work-experiences',
        WorkExperience::class,
        'work_experiences',
        'title',
        [
            'title' => 'Verb Work Experience',
            'employer' => 'Verb Employer',
            'start_date' => '2021-01-01',
        ],
    ],
    'educations' => [
        'educations',
        Education::class,
        'educations',
        'title',
        [
            'title' => 'Verb Education',
            'institution' => 'Verb Institution',
        ],
    ],
    'certifications' => [
        'certifications',
        Certification::class,
        'certifications',
        'title',
        [
            'title' => 'Verb Certification',
            'issuer' => 'Verb Issuer',
            'issued_on' => '2021-01-01',
        ],
    ],
    'publications' => [
        'publications',
        Publication::class,
        'publications',
        'title',
        [
            'title' => 'Verb Publication',
            'venue' => 'Verb Venue',
            'issued_on' => '2021-01-01',
        ],
    ],
    'projects' => [
        'projects',
        Project::class,
        'projects',
        'title',
        ['title' => 'Verb Project'],
    ],
    'awards' => [
        'awards',
        Award::class,
        'awards',
        'title',
        [
            'title' => 'Verb Award',
            'awarding_body' => 'Verb Awarding Body',
            'awarded_at' => '2021-01-01',
        ],
    ],
    'writings' => [
        'writings',
        Writing::class,
        'writings',
        'title',
        ['title' => 'Verb Writing'],
    ],
    'tags' => [
        'tags',
        Tag::class,
        'tags',
        'name',
        ['name' => 'Verb Tag'],
    ],
]);

/**
 * Create a fixture every public read must expose.
 *
 * A tag carries no publication state (§4.8), so it is read as it stands; the
 * seven content models are only visible once published.
 */
function apiVerbRecord(string $modelClass, array $attributes): Model
{
    $factory = $modelClass::factory();

    return method_exists($modelClass, 'scopePublished')
        ? $factory->published()->create($attributes)
        : $factory->create($attributes);
}

/**
 * Create a draft beside the visible fixture, or nothing for a tag.
 *
 * The title is prefixed so the two rows do not contend for one derived slug,
 * which keeps every assertion about which row a response named unambiguous.
 */
function apiVerbDraft(string $modelClass, array $attributes, string $titleKey): ?Model
{
    if (! method_exists($modelClass, 'scopePublished')) {
        return null;
    }

    return $modelClass::factory()->draft()->create(
        array_merge($attributes, [$titleKey => 'Draft '.$attributes[$titleKey]]),
    );
}

/**
 * The administrator whose session authorises every write (§4.11).
 */
function apiVerbAdmin(): User
{
    $admin = User::factory()->administrator()->create();

    RateLimiter::clear(md5('api-admin-write'.$admin->getAuthIdentifier()));

    return $admin;
}

/**
 * A JSON:API request document for one resource.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, array<string, mixed>>
 */
function apiVerbDocument(string $type, array $attributes, ?int $id = null): array
{
    $data = ['type' => $type, 'attributes' => $attributes];

    if ($id !== null) {
        $data['id'] = (string) $id;
    }

    return ['data' => $data];
}

beforeEach(function () {
    RateLimiter::clear(md5('api-public-read127.0.0.1'));
});

test('GET on the collection answers 200 with the published record only', function (string $segment, string $modelClass, string $type, string $titleKey, array $attributes) {
    $visible = apiVerbRecord($modelClass, $attributes);
    $draft = apiVerbDraft($modelClass, $attributes, $titleKey);

    $response = $this->getJson("/api/v1/{$segment}");

    $response->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.type'))->toBe($type)
        ->and($response->json('data.0.id'))->toBe((string) $visible->getKey())
        ->and($response->json('data.0.attributes.'.$titleKey))->toBe($attributes[$titleKey]);

    if ($draft !== null) {
        expect($response->json('data.0.id'))->not->toBe((string) $draft->getKey());
    }
})->with('api verb resources');

test('GET on the single resource answers 200', function (string $segment, string $modelClass, string $type, string $titleKey, array $attributes) {
    $visible = apiVerbRecord($modelClass, $attributes);

    $response = $this->getJson("/api/v1/{$segment}/{$visible->getKey()}");

    $response->assertOk();

    expect($response->json('data.type'))->toBe($type)
        ->and($response->json('data.id'))->toBe((string) $visible->getKey())
        ->and($response->json('data.attributes.'.$titleKey))->toBe($attributes[$titleKey]);
})->with('api verb resources');

test('POST answers 201 with the Location of the created resource', function (string $segment, string $modelClass, string $type, string $titleKey, array $attributes) {
    $this->actingAs(apiVerbAdmin());

    $response = $this->postJson(
        "/api/v1/{$segment}",
        apiVerbDocument($type, $attributes),
        ['Content-Type' => 'application/vnd.api+json'],
    );

    $response->assertCreated();

    $id = $response->json('data.id');

    expect($id)->toBeString()->not->toBe('')
        ->and($response->json('data.type'))->toBe($type)
        ->and($response->json('data.attributes.'.$titleKey))->toBe($attributes[$titleKey]);

    // §4.5: the `Location` header and the resource object's own `links.self`
    // are built from one URL, so they cannot disagree.
    $location = route('api.v1.'.$segment.'.show', $id);

    $response->assertHeader('Location', $location);
    expect($response->json('data.links.self'))->toBe($location);

    $created = $modelClass::query()->findOrFail($id);

    expect($created->getAttribute($titleKey))->toBe($attributes[$titleKey]);
})->with('api verb resources');

test('PUT answers 200 with the replaced resource', function (string $segment, string $modelClass, string $type, string $titleKey, array $attributes) {
    $this->actingAs(apiVerbAdmin());

    $record = apiVerbRecord($modelClass, $attributes);
    $replaced = array_merge($attributes, [$titleKey => 'Replaced '.$attributes[$titleKey]]);

    $response = $this->putJson(
        "/api/v1/{$segment}/{$record->getKey()}",
        apiVerbDocument($type, $replaced, (int) $record->getKey()),
        ['Content-Type' => 'application/vnd.api+json'],
    );

    $response->assertOk();

    expect($response->json('data.type'))->toBe($type)
        ->and($response->json('data.id'))->toBe((string) $record->getKey())
        ->and($response->json('data.attributes.'.$titleKey))->toBe($replaced[$titleKey])
        ->and($record->refresh()->getAttribute($titleKey))->toBe($replaced[$titleKey]);
})->with('api verb resources');

test('PATCH answers 200 and leaves the members it was not sent untouched', function (string $segment, string $modelClass, string $type, string $titleKey, array $attributes) {
    $this->actingAs(apiVerbAdmin());

    $record = apiVerbRecord($modelClass, $attributes);
    $before = $record->fresh()->getAttributes();
    $amended = 'Amended '.$attributes[$titleKey];

    $response = $this->patchJson(
        "/api/v1/{$segment}/{$record->getKey()}",
        apiVerbDocument($type, [$titleKey => $amended], (int) $record->getKey()),
        ['Content-Type' => 'application/vnd.api+json'],
    );

    $response->assertOk();

    $after = $record->fresh()->getAttributes();

    expect($response->json('data.id'))->toBe((string) $record->getKey())
        ->and($response->json('data.attributes.'.$titleKey))->toBe($amended)
        ->and($after[$titleKey])->toBe($amended)
        ->and(Arr::except($after, [$titleKey, 'updated_at']))
        ->toBe(Arr::except($before, [$titleKey, 'updated_at']));
})->with('api verb resources');

test('DELETE answers 204 with an empty body', function (string $segment, string $modelClass, string $type, string $titleKey, array $attributes) {
    $this->actingAs(apiVerbAdmin());

    $record = apiVerbRecord($modelClass, $attributes);

    $response = $this->deleteJson("/api/v1/{$segment}/{$record->getKey()}");

    $response->assertNoContent(204);
    expect($response->getContent())->toBe('');

    if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
        $this->assertSoftDeleted($record->getTable(), ['id' => $record->getKey()]);
    } else {
        $this->assertDatabaseMissing($record->getTable(), ['id' => $record->getKey()]);
    }
})->with('api verb resources');

test('OPTIONS answers 200 with the Allow of each URI shape', function (string $segment, string $modelClass, string $type, string $titleKey, array $attributes) {
    $collection = $this->call('OPTIONS', "/api/v1/{$segment}");

    $collection->assertOk();
    $collection->assertHeader('Allow', 'GET, HEAD, OPTIONS, POST');
    expect($collection->getContent())->toBe('');

    $record = apiVerbRecord($modelClass, $attributes);

    $single = $this->call('OPTIONS', "/api/v1/{$segment}/{$record->getKey()}");

    $single->assertOk();
    $single->assertHeader('Allow', 'GET, HEAD, OPTIONS, PUT, PATCH, DELETE');
    expect($single->getContent())->toBe('');
})->with('api verb resources');

test('HEAD answers 200 with no body and the same validators as GET', function (string $segment, string $modelClass, string $type, string $titleKey, array $attributes) {
    $record = apiVerbRecord($modelClass, $attributes);

    $collectionGet = $this->getJson("/api/v1/{$segment}");
    $collectionHead = $this->call('HEAD', "/api/v1/{$segment}");

    $collectionGet->assertOk();
    $collectionHead->assertOk();

    expect($collectionHead->getContent())->toBe('')
        ->and($collectionHead->headers->get('Content-Type'))->toBe($collectionGet->headers->get('Content-Type'))
        ->and($collectionHead->headers->get('ETag'))->toBe($collectionGet->headers->get('ETag'));

    $singleGet = $this->getJson("/api/v1/{$segment}/{$record->getKey()}");
    $singleHead = $this->call('HEAD', "/api/v1/{$segment}/{$record->getKey()}");

    $singleGet->assertOk();
    $singleHead->assertOk();

    expect($singleHead->getContent())->toBe('')
        ->and($singleHead->headers->get('Content-Type'))->toBe($singleGet->headers->get('Content-Type'))
        ->and($singleHead->headers->get('ETag'))->toBe($singleGet->headers->get('ETag'));
})->with('api verb resources');

test('the eight resources are addressed by their plural-kebab segments only', function () {
    // §4.2: path segments are plural kebab-case. A snake_case or singular
    // spelling is an unknown endpoint, not an alias for the real one.
    $this->getJson('/api/v1/work_experiences')->assertNotFound();
    $this->getJson('/api/v1/work-experience')->assertNotFound();
    $this->getJson('/api/v1/education')->assertNotFound();
    $this->getJson('/api/v1/tag')->assertNotFound();
});
