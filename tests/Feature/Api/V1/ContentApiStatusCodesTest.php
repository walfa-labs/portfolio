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
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use PDOException;

/*
|--------------------------------------------------------------------------
| The status codes of §4.5 Table B and the §4.6 envelope
|--------------------------------------------------------------------------
|
| One dataset carries the eight Table A resources through every status this
| file pins, so a code that is right for `writings` and wrong for `tags`
| cannot pass. The three `Allow` values are per URI shape, not per resource:
| a collection advertises `GET, HEAD, OPTIONS, POST` and never `PUT`, a single
| resource `GET, HEAD, OPTIONS, PUT, PATCH, DELETE`, and `/search` adds the one
| `QUERY` endpoint (§4.1).
|
| Writes are session-authenticated through the `api-admin` group (§4.11): the
| resolved pipeline is cookies → session → auth → throttle → bindings → CSRF →
| verified, so an absent session is a real `401` and a session that is not the
| administrator a real `403`, with the media-type guard (a controller
| constructor) running after both.
|
| `api-public-read` is 120/min and `api-admin-write` 60/min, and the `array`
| cache store lives for the whole process, so every bucket this file could fill
| is emptied before each test. `ThrottleRequests` keys a named limiter as
| `md5($limiterName.$limit->key)`.
|
*/

dataset('api status resources', [
    'work-experiences' => [
        'work-experiences',
        WorkExperience::class,
        'work_experiences',
        [
            'title' => 'Status Work Experience',
            'employer' => 'Status Employer',
            'start_date' => '2021-01-01',
        ],
    ],
    'educations' => [
        'educations',
        Education::class,
        'educations',
        [
            'title' => 'Status Education',
            'institution' => 'Status Institution',
        ],
    ],
    'certifications' => [
        'certifications',
        Certification::class,
        'certifications',
        [
            'title' => 'Status Certification',
            'issuer' => 'Status Issuer',
            'issued_on' => '2021-01-01',
        ],
    ],
    'publications' => [
        'publications',
        Publication::class,
        'publications',
        [
            'title' => 'Status Publication',
            'venue' => 'Status Venue',
            'issued_on' => '2021-01-01',
        ],
    ],
    'projects' => [
        'projects',
        Project::class,
        'projects',
        ['title' => 'Status Project'],
    ],
    'awards' => [
        'awards',
        Award::class,
        'awards',
        [
            'title' => 'Status Award',
            'awarding_body' => 'Status Awarding Body',
            'awarded_at' => '2021-01-01',
        ],
    ],
    'writings' => [
        'writings',
        Writing::class,
        'writings',
        ['title' => 'Status Writing'],
    ],
    'tags' => [
        'tags',
        Tag::class,
        'tags',
        ['name' => 'Status Tag'],
    ],
]);

/**
 * The one id no resource in a fresh database can hold.
 */
const API_STATUS_UNKNOWN_ID = '999999';

/**
 * Create a fixture every public read must expose.
 */
function apiStatusRecord(string $modelClass, array $attributes): Model
{
    $factory = $modelClass::factory();

    return method_exists($modelClass, 'scopePublished')
        ? $factory->published()->create($attributes)
        : $factory->create($attributes);
}

/**
 * The administrator whose session authorises every write (§4.11).
 */
function apiStatusAdmin(): User
{
    return User::factory()->administrator()->create();
}

/**
 * A verified session that is not the configured administrator.
 */
function apiStatusNonAdmin(): User
{
    return User::factory()->create();
}

/**
 * A JSON:API request document for one resource.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, array<string, mixed>>
 */
function apiStatusDocument(string $type, array $attributes, ?int $id = null): array
{
    $data = ['type' => $type, 'attributes' => $attributes];

    if ($id !== null) {
        $data['id'] = (string) $id;
    }

    return ['data' => $data];
}

/**
 * The `Content-Type` a write must declare to get past the media-type guard.
 */
const API_STATUS_WRITE_HEADERS = ['Content-Type' => 'application/vnd.api+json'];

beforeEach(function () {
    foreach (['api-public-read', 'api-search'] as $limiter) {
        RateLimiter::clear(md5($limiter.'127.0.0.1'));
    }

    // The write limiter keys on the authenticated user id, and a fresh
    // transaction hands the same low ids back to every test.
    foreach (['1', '2', '3', '127.0.0.1'] as $key) {
        RateLimiter::clear(md5('api-admin-write'.$key));
    }
});

test('POST answers 201 with an absolute Location naming the created resource', function (string $segment, string $modelClass, string $type, array $attributes) {
    $this->actingAs(apiStatusAdmin());

    $response = $this->postJson(
        "/api/v1/{$segment}",
        apiStatusDocument($type, $attributes),
        API_STATUS_WRITE_HEADERS,
    );

    $response->assertCreated();
    $response->assertHeader('Content-Type', 'application/vnd.api+json');

    $id = $response->json('data.id');
    $location = $response->headers->get('Location');

    expect($location)->toBeString()->toStartWith('http')
        ->and($location)->toBe(route('api.v1.'.$segment.'.show', $id))
        // §4.5: the `Location` header and the resource object's own
        // `links.self` are built from one URL and MUST match.
        ->and($response->json('data.links.self'))->toBe($location);

    $this->assertDatabaseHas($modelClass::query()->getModel()->getTable(), ['id' => (int) $id]);
})->with('api status resources');

test('DELETE answers 204 with an empty body and a second DELETE is 404', function (string $segment, string $modelClass, string $type, array $attributes) {
    $this->actingAs(apiStatusAdmin());

    $record = apiStatusRecord($modelClass, $attributes);
    $uri = "/api/v1/{$segment}/{$record->getKey()}";

    $first = $this->deleteJson($uri);

    $first->assertNoContent(204);
    expect($first->getContent())->toBe('');

    // §4.5: the effect of DELETE is idempotent, but a second DELETE of the
    // same id is a 404 rather than another 204.
    $second = $this->deleteJson($uri);

    $second->assertNotFound();
})->with('api status resources');

test('an unknown id is 404 for every verb that names one', function (string $segment, string $modelClass, string $type, array $attributes) {
    $this->actingAs(apiStatusAdmin());

    // §4.2: a single-resource URI binds the numeric id, so an id no row holds
    // is a 404 — including for `tags`, whose public route binds by slug.
    $this->getJson('/api/v1/'.$segment.'/'.API_STATUS_UNKNOWN_ID)->assertNotFound();

    $document = apiStatusDocument($type, $attributes, (int) API_STATUS_UNKNOWN_ID);

    $this->putJson('/api/v1/'.$segment.'/'.API_STATUS_UNKNOWN_ID, $document, API_STATUS_WRITE_HEADERS)
        ->assertNotFound();

    $this->patchJson('/api/v1/'.$segment.'/'.API_STATUS_UNKNOWN_ID, $document, API_STATUS_WRITE_HEADERS)
        ->assertNotFound();

    $this->deleteJson('/api/v1/'.$segment.'/'.API_STATUS_UNKNOWN_ID)
        ->assertNotFound();
})->with('api status resources');

test('DELETE on a collection is 405 with the collection Allow', function (string $segment, string $modelClass, string $type, array $attributes) {
    $response = $this->deleteJson("/api/v1/{$segment}");

    $response->assertStatus(405);
    $response->assertHeader('Allow', 'GET, HEAD, OPTIONS, POST');
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.code', 'method_not_allowed');
    $response->assertJsonPath('errors.0.status', '405');
})->with('api status resources');

test('a verb outside Table B is 405 with the Allow of its URI shape', function () {
    // QUERY exists only on `/api/v1/search`; a collection stays GET-only
    // (§4.1). The collection set never advertises PUT, PATCH or DELETE.
    $this->call('QUERY', '/api/v1/projects')
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET, HEAD, OPTIONS, POST');

    // A single resource has no POST.
    $this->postJson('/api/v1/projects/1', [], API_STATUS_WRITE_HEADERS)
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET, HEAD, OPTIONS, PUT, PATCH, DELETE')
        ->assertJsonPath('errors.0.code', 'method_not_allowed');

    // The search URI advertises the one QUERY method and nothing unsafe.
    $this->postJson('/api/v1/search', ['q' => 'vue'])
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET, HEAD, OPTIONS, QUERY')
        ->assertJsonPath('errors.0.code', 'method_not_allowed');
});

test('an unacceptable Accept is 406 with the media types the endpoint produces', function () {
    $collection = $this->getJson('/api/v1/projects', ['Accept' => 'text/html']);

    $collection->assertStatus(406);
    $collection->assertHeader('Content-Type', 'application/vnd.api+json');
    $collection->assertHeader('Accept', 'application/vnd.api+json');
    $collection->assertJsonPath('errors.0.code', 'not_acceptable');
    $collection->assertJsonPath('errors.0.source.header', 'Accept');

    $this->getJson('/api/v1/projects/1', ['Accept' => 'application/xml'])
        ->assertStatus(406)
        ->assertHeader('Accept', 'application/vnd.api+json');

    // The search document is deliberately not JSON:API (§4.4.3), so its 406
    // names both media types it can produce.
    $search = $this->getJson('/api/v1/search?q=vue', ['Accept' => 'text/html']);

    $search->assertStatus(406);
    $search->assertHeader('Accept', 'application/json, application/vnd.api+json');
    $search->assertJsonPath('errors.0.code', 'not_acceptable');
});

test('a write whose media type is not JSON:API is 415', function (string $segment, string $modelClass, string $type, array $attributes) {
    $this->actingAs(apiStatusAdmin());

    $document = apiStatusDocument($type, $attributes);

    // `postJson()` declares `application/json`, which is exactly the media
    // type a write must not use.
    $response = $this->postJson("/api/v1/{$segment}", $document);

    $response->assertStatus(415);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertHeader('Accept', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.code', 'unsupported_media_type');
    $response->assertJsonPath('errors.0.source.header', 'Content-Type');
})->with('api status resources');

test('a parameterised JSON:API media type is 415 and DELETE carries no media type', function () {
    $this->actingAs(apiStatusAdmin());

    $document = apiStatusDocument('projects', ['title' => 'Media Type Project']);

    // §4.6: only `ext` and `profile` are JSON:API's own parameters.
    $this->postJson('/api/v1/projects', $document, ['Content-Type' => 'application/vnd.api+json; charset=utf-8'])
        ->assertStatus(415)
        ->assertJsonPath('errors.0.code', 'unsupported_media_type');

    $created = $this->postJson('/api/v1/projects', $document, ['Content-Type' => 'application/vnd.api+json; ext="https://example.test/ext"']);

    $created->assertCreated();

    $uri = '/api/v1/projects/'.$created->json('data.id');

    $this->putJson($uri, $document, ['Content-Type' => 'application/json'])
        ->assertStatus(415);

    $this->patchJson($uri, $document, ['Content-Type' => 'application/json'])
        ->assertStatus(415);

    // Table B gives DELETE no 415: it carries no body to interpret.
    $project = Project::factory()->published()->create(['title' => 'Delete Media Type Project']);

    $this->call('DELETE', "/api/v1/projects/{$project->getKey()}", [], [], [], [
        'CONTENT_TYPE' => 'text/plain',
        'HTTP_ACCEPT' => 'application/json',
    ])->assertNoContent(204);
});

test('a write with no session is 401 before the body is validated', function (string $segment, string $modelClass, string $type, array $attributes) {
    // The body is empty on purpose: if the `auth` middleware were not in front
    // of the form request this would be a 422, not a 401.
    $response = $this->postJson("/api/v1/{$segment}", [], API_STATUS_WRITE_HEADERS);

    $response->assertStatus(401);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.code', 'unauthenticated');
    $response->assertJsonPath('errors.0.status', '401');
})->with('api status resources');

test('PUT, PATCH and DELETE with no session are 401', function () {
    $project = Project::factory()->published()->create(['title' => 'Unauthenticated Project']);
    $uri = "/api/v1/projects/{$project->getKey()}";
    $document = apiStatusDocument('projects', ['title' => 'Unauthenticated Replacement']);

    $this->putJson($uri, $document, API_STATUS_WRITE_HEADERS)
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'unauthenticated');

    $this->patchJson($uri, $document, API_STATUS_WRITE_HEADERS)
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'unauthenticated');

    $this->deleteJson($uri)
        ->assertStatus(401)
        ->assertJsonPath('errors.0.code', 'unauthenticated');

    $this->assertDatabaseHas('projects', ['id' => $project->getKey()]);
});

test('a write by a non-admin is 403', function (string $segment, string $modelClass, string $type, array $attributes) {
    $this->actingAs(apiStatusNonAdmin());

    $response = $this->postJson(
        "/api/v1/{$segment}",
        apiStatusDocument($type, $attributes),
        API_STATUS_WRITE_HEADERS,
    );

    $response->assertStatus(403);

    $this->assertDatabaseCount($modelClass::query()->getModel()->getTable(), 0);
})->with('api status resources');

test('PUT, PATCH and DELETE by a non-admin are 403', function () {
    $this->actingAs(apiStatusNonAdmin());

    $project = Project::factory()->published()->create(['title' => 'Forbidden Project']);
    $uri = "/api/v1/projects/{$project->getKey()}";
    $document = apiStatusDocument('projects', ['title' => 'Forbidden Replacement']);

    $this->putJson($uri, $document, API_STATUS_WRITE_HEADERS)->assertStatus(403);
    $this->patchJson($uri, $document, API_STATUS_WRITE_HEADERS)->assertStatus(403);
    $this->deleteJson($uri)->assertStatus(403);

    // The denial happened before anything was written.
    expect($project->fresh()->title)->toBe('Forbidden Project')
        ->and($project->fresh()->trashed())->toBeFalse();
});

test('there is no API root', function () {
    // §4.5: level 2 has nothing to advertise, so `/api/v1` is an unknown
    // endpoint rather than an index document.
    $this->getJson('/api/v1')->assertNotFound();
});

test('a public error is the §4.6 JSON:API document whatever its status', function (string $method, string $uri, array $headers, string $code) {
    $response = $this->json($method, $uri, [], $headers);

    // §4.6: every error — not just the ones a renderer in `bootstrap/app.php`
    // builds — is a JSON:API document, and `errors` and `data` are never
    // top-level siblings.
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.status', (string) $response->getStatusCode());
    $response->assertJsonPath('errors.0.code', $code);
    $response->assertJsonMissingPath('data');

    expect($response->json('errors.0.title'))->toBeString()->not->toBe('')
        ->and($response->json('errors.0.detail'))->toBeString()->not->toBe('')
        ->and($response->json('errors.0.meta.request_id'))->toBeString()->not->toBe('');
})->with([
    '400 undeclared filter' => ['GET', '/api/v1/projects?filter[nonsense]=1', [], 'invalid_query_parameter'],
    '404 unknown id' => ['GET', '/api/v1/projects/'.API_STATUS_UNKNOWN_ID, [], 'not_found'],
    '404 unknown endpoint' => ['GET', '/api/v1', [], 'not_found'],
    '406 unacceptable Accept' => ['GET', '/api/v1/projects', ['Accept' => 'text/html'], 'not_acceptable'],
]);

test('a forbidden write is the §4.6 JSON:API document', function () {
    $this->actingAs(apiStatusNonAdmin());

    $response = $this->postJson(
        '/api/v1/projects',
        apiStatusDocument('projects', ['title' => 'Envelope Project']),
        API_STATUS_WRITE_HEADERS,
    );

    $response->assertStatus(403);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.status', '403');
    $response->assertJsonPath('errors.0.code', 'forbidden');
    $response->assertJsonMissingPath('data');
});

/**
 * A real `UniqueConstraintViolationException`, as a connection raises it.
 *
 * The previous exception carries the SQLSTATE, which is where `QueryException`
 * copies its own code from, so this is the object the renderer sees in
 * production rather than a hand-built stand-in.
 */
function apiStatusUniqueViolation(): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException(
        'sqlite',
        'insert into "projects" ("slug") values (?)',
        ['taken-slug'],
        new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', 23000),
    );
}

/**
 * The API request a renderer is exercised against.
 */
function apiStatusApiRequest(): Request
{
    $request = Request::create('/api/v1/projects', 'POST');
    $request->headers->set('Accept', 'application/vnd.api+json');

    return $request;
}

test('a unique-constraint violation renders the §4.6 409 rather than a 500', function () {
    // No reachable API path can provoke one: the only unique indexes a write
    // touches are the derived slug — whose deriver suffixes a taken slug, so a
    // replayed POST answers 201 — and `taggables`, whose `sync()` de-duplicates
    // its ids. So the registered renderer is exercised directly through the
    // exception handler, with the exact exception a connection raises, rather
    // than by inventing a route that could not exist in v1.
    $handler = app(ExceptionHandler::class);

    $exceptions = [
        apiStatusUniqueViolation(),
        new QueryException(
            'sqlite',
            'insert into "projects" ("slug") values (?)',
            ['taken-slug'],
            new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', 23000),
        ),
    ];

    foreach ($exceptions as $exception) {
        $response = $handler->render(apiStatusApiRequest(), $exception);

        expect($response->getStatusCode())->toBe(409)
            ->and($response->headers->get('Content-Type'))->toBe('application/vnd.api+json');

        $document = json_decode((string) $response->getContent(), true);

        expect($document['errors'][0]['status'])->toBe('409')
            ->and($document['errors'][0]['code'])->toBe('conflict')
            ->and($document['errors'][0]['detail'])->toBeString()->not->toBe('')
            ->and($document)->not->toHaveKey('data');
    }

    // A database error that is not a unique violation is left to Laravel: the
    // renderer returns null rather than claiming every QueryException is a 409.
    $other = $handler->render(apiStatusApiRequest(), new QueryException(
        'sqlite',
        'select * from "projects"',
        [],
        new PDOException('SQLSTATE[42S02]: Base table or view not found'),
    ));

    expect($other->getStatusCode())->toBe(500);
});
