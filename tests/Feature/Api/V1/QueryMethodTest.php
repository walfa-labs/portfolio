<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\Writing;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| The QUERY method on /api/v1/search (§4.1)
|--------------------------------------------------------------------------
|
| `QUERY /api/v1/search` is the API's only QUERY endpoint. Its *document* is
| §4.4.3's; what this file pins is the **method**: the request media type, the
| error set RFC 10008 and Table B assign to it, the discovery headers, the
| `GET` fallback returning the same document, and the media-type asymmetry that
| makes the success `application/json` while every error on the same URI is a
| JSON:API document under `application/vnd.api+json`.
|
| The one structural assertion is that the route is **not** in the `web` group.
| `PreventRequestForgery::isReading()` exempts only HEAD, GET and OPTIONS, so a
| QUERY route behind `web` would demand a CSRF token — and the middleware is
| bypassed entirely under `runningUnitTests()`, so the request alone cannot
| prove the grouping. The middleware list is asserted directly, and the
| token-less request is asserted alongside it as the behaviour that follows.
|
| `throttle:api-search` is 30/min keyed by client IP and the `array` cache store
| lives for the whole process, so the bucket is emptied before every test —
| otherwise this file's own requests, or a neighbouring file's, would turn into
| 429s. `ThrottleRequests` keys a named limiter as `md5($limiterName.$limit->key)`.
|
*/

beforeEach(function () {
    RateLimiter::clear(md5('api-search127.0.0.1'));
    RateLimiter::clear(md5('api-public-read127.0.0.1'));
});

test('QUERY answers the §4.4.3 document over the kernel', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $writing = Writing::factory()->published()->create([
        'title' => 'Vue and Laravel',
        'published_at' => now()->subDay(),
    ]);
    $writing->tags()->attach($tag);

    $project = Project::factory()->published()->create([
        'title' => 'Vue project',
        'published_at' => now()->subDays(2),
    ]);
    $project->tags()->attach($tag);

    $response = $this->queryJson('/api/v1/search', [
        'q' => 'vue',
        'types' => ['writings', 'projects'],
        'tags' => ['laravel'],
        'sort' => '-published_at',
        'page' => ['number' => 1, 'size' => 20],
    ]);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');

    // A-108: the document carries no method marker — QUERY and GET are two
    // input channels for one canonical search, and RFC 10008 defines the QUERY
    // response's selected representation as the equivalent GET resource's.
    expect($response->json('meta'))->not->toHaveKey('query_method')
        ->and($response->json('meta.page'))->toBe(['number' => 1, 'size' => 20, 'total' => 2, 'pages' => 1])
        ->and($response->json('meta.counts_by_type'))->toEqualCanonicalizing(['writings' => 1, 'projects' => 1])
        ->and(array_keys($response->json('results')))->toBe([0, 1])
        ->and(array_keys($response->json('results.0')))
        ->toEqualCanonicalizing(['type', 'id', 'title', 'excerpt', 'path', 'score'])
        ->and($response->json('results.0.type'))->toBe('writings')
        ->and($response->json('results.0.id'))->toBe((string) $writing->getKey())
        ->and($response->json('results.0.title'))->toBe('Vue and Laravel')
        ->and($response->json('results.0.excerpt'))->toBe($writing->excerpt)
        ->and($response->json('results.0.path'))->toBe(route('writing.show', $writing, false))
        ->and($response->json('results.0.score'))->toBeFloat()
        ->and($response->json('results.1.type'))->toBe('projects')
        ->and($response->json('results.1.id'))->toBe((string) $project->getKey())
        ->and($response->json('results.1.path'))->toBe(route('projects.index', absolute: false));
});

test('the search route is not in the web middleware group, so a QUERY needs no CSRF token', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'api/v1/search' && in_array('QUERY', $route->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('api')
        ->and($route->gatherMiddleware())->not->toContain('web')
        ->and($route->gatherMiddleware())->not->toContain(PreventRequestForgery::class);

    Writing::factory()->published()->create(['title' => 'No token needed']);

    // No session, no `X-CSRF-TOKEN` and no `X-XSRF-TOKEN`: the request is
    // accepted because the route carries no CSRF middleware, which is the
    // behaviour the grouping above exists to produce (§4.11).
    $this->queryJson('/api/v1/search', ['q' => 'token'])
        ->assertOk()
        ->assertJsonMissingPath('meta.query_method')
        ->assertJsonPath('results.0.title', 'No token needed');
});

test('the search URI advertises its verbs and its QUERY body media type', function () {
    $this->call('OPTIONS', '/api/v1/search')
        ->assertOk()
        ->assertHeader('Allow', 'GET, HEAD, OPTIONS, QUERY')
        ->assertHeader('Accept-Query', 'application/json')
        ->assertContent('');

    Writing::factory()->published()->create(['title' => 'Advertised']);

    $this->getJson('/api/v1/search?q=advertised')
        ->assertOk()
        ->assertHeader('Accept-Query', 'application/json');

    $this->call('HEAD', '/api/v1/search?q=advertised')
        ->assertOk()
        ->assertHeader('Accept-Query', 'application/json')
        ->assertContent('');
});

test('QUERY on a collection is 405 with that collection Allow', function (string $segment) {
    $response = $this->queryJson('/api/v1/'.$segment, ['q' => 'anything']);

    $response->assertStatus(405);
    $response->assertHeader('Allow', 'GET, HEAD, OPTIONS, POST');
    $response->assertJsonPath('errors.0.code', 'method_not_allowed');
})->with([
    'work-experiences',
    'educations',
    'certifications',
    'publications',
    'projects',
    'awards',
    'writings',
    'tags',
]);

test('a QUERY body that is not a JSON object is 400', function (string $body) {
    $this->call('QUERY', '/api/v1/search', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body)
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('errors.0.code', 'malformed_body');
})->with([
    'truncated' => ['{"q":'],
    'a list' => ['["vue"]'],
    'a scalar' => ['"vue"'],
    'empty' => [''],
]);

test('an undeclared QUERY parameter is 400 and names it', function () {
    $this->queryJson('/api/v1/search', ['q' => 'vue', 'limit' => 5])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_query_parameter')
        ->assertJsonPath('errors.0.source.parameter', 'limit');
});

test('a QUERY page size outside 1-100 is 400, never clamped', function (int $size) {
    Writing::factory()->published()->create(['title' => 'Paged query']);

    $this->queryJson('/api/v1/search', ['q' => 'paged', 'page' => ['size' => $size]])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_query_parameter')
        ->assertJsonPath('errors.0.source.parameter', 'page[size]');
})->with([0, 101, -1, 1000]);

test('a QUERY body that is not application/json is 415', function (?string $contentType) {
    $server = ['HTTP_ACCEPT' => 'application/json'];

    if ($contentType !== null) {
        $server['CONTENT_TYPE'] = $contentType;
    }

    $response = $this->call('QUERY', '/api/v1/search', [], [], [], $server, '{"q":"vue"}');

    $response->assertStatus(415);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    // §4.1: the 415 names the media type it wants *and* advertises QUERY
    // support, so a caller can retry without a discovery round trip.
    $response->assertHeader('Accept', 'application/json');
    $response->assertHeader('Accept-Query', 'application/json');
    $response->assertJsonPath('errors.0.code', 'unsupported_media_type');
    $response->assertJsonPath('errors.0.source.header', 'Content-Type');
})->with([
    'no content type' => [null],
    'text/plain' => ['text/plain'],
    'form encoded' => ['application/x-www-form-urlencoded'],
    'the json api media type' => ['application/vnd.api+json'],
]);

test('an unprocessable QUERY body is 422 with a pointer into the document', function (array $body, string $pointer) {
    $this->queryJson('/api/v1/search', $body)
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertJsonPath('errors.0.code', 'validation_failed')
        ->assertJsonPath('errors.0.source.pointer', $pointer);
})->with([
    'q is not a string' => [['q' => 42], '/q'],
    'q is too short' => [['q' => 'v'], '/q'],
    'q is too long' => [['q' => str_repeat('v', 101)], '/q'],
    'page is not an object' => [['q' => 'vue', 'page' => 'first'], '/page'],
    'page number is not an integer' => [['q' => 'vue', 'page' => ['number' => 'one']], '/page/number'],
    'page size is not an integer' => [['q' => 'vue', 'page' => ['size' => 'twenty']], '/page/size'],
    'types is not a list' => [['q' => 'vue', 'types' => 'writings'], '/types'],
    'types holds a non-string' => [['q' => 'vue', 'types' => [7]], '/types/0'],
    'tags is not a list of strings' => [['q' => 'vue', 'tags' => [1]], '/tags/0'],
    'sort is not a string' => [['q' => 'vue', 'sort' => 5], '/sort'],
]);

test('an unacceptable Accept is 406 and names both media types', function () {
    $this->call('QUERY', '/api/v1/search', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'text/html',
    ], '{"q":"vue"}')
        ->assertStatus(406)
        ->assertHeader('Accept', 'application/json, application/vnd.api+json')
        ->assertJsonPath('errors.0.code', 'not_acceptable')
        ->assertJsonPath('errors.0.source.header', 'Accept');
});

test('the success media type is application/json while an error on the same URI is application/vnd.api+json', function () {
    Writing::factory()->published()->create(['title' => 'Asymmetric']);

    $success = $this->queryJson('/api/v1/search', ['q' => 'asymmetric']);

    $success->assertOk();
    $success->assertHeader('Content-Type', 'application/json');
    // The plain document does not vary on the request's `Accept`; `Vary: Accept`
    // rides on the JSON:API responses only, which is the other half of the
    // asymmetry (§4.1, §4.12).
    $success->assertHeaderMissing('Vary');

    $error = $this->queryJson('/api/v1/search', ['q' => 'asymmetric', 'unknown' => '1']);

    $error->assertStatus(400);
    $error->assertHeader('Content-Type', 'application/vnd.api+json');
    $error->assertHeader('Vary', 'Accept');
    $error->assertJsonPath('errors.0.code', 'invalid_query_parameter');
    $error->assertJsonMissingPath('data');
});

test('GET is the flattened fallback and returns the same document as QUERY', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $writing = Writing::factory()->published()->create([
        'title' => 'Fallback vue',
        'published_at' => now()->subDay(),
    ]);
    $writing->tags()->attach($tag);

    $project = Project::factory()->published()->create([
        'title' => 'Fallback project vue',
        'published_at' => now()->subDays(2),
    ]);
    $project->tags()->attach($tag);

    $body = [
        'q' => 'fallback',
        'types' => ['writings', 'projects'],
        'tags' => ['laravel'],
        'sort' => '-published_at',
        'page' => ['number' => 1, 'size' => 20],
    ];

    $query = $this->queryJson('/api/v1/search', $body)->assertOk();

    $get = $this->getJson(
        '/api/v1/search?q=fallback&types=writings,projects&tags=laravel&sort=-published_at&page[number]=1&page[size]=20',
    )->assertOk();

    // A-108: the two entry points return the *same document*. No method marker
    // distinguishes them, so the comparison is now over the whole document
    // rather than over `meta` minus a `query_method` member — a strengthening
    // of what this test used to allow.
    expect($get->json())->toBe($query->json())
        ->and($query->json('meta'))->not->toHaveKey('query_method');
});

test('a QUERY ETag incorporates the request body, so two bodies never share a validator', function () {
    Writing::factory()->published()->create(['title' => 'Body sensitive']);

    // Two QUERY bodies that decode to the same parameter set — `types: []`
    // normalises to "no type narrowing" — therefore produce the identical
    // document. The validator must still differ, because §4.12 makes the QUERY
    // input the request body, and a private cache must not reuse one body's
    // document for another's (A-109).
    $bare = $this->queryJson('/api/v1/search', ['q' => 'body'])->assertOk();

    $emptyTypes = $this->queryJson('/api/v1/search', ['q' => 'body', 'types' => []])->assertOk();

    expect($emptyTypes->json())->toBe($bare->json())
        ->and($emptyTypes->headers->get('ETag'))->not->toBe($bare->headers->get('ETag'));
});

test('QUERY is safe and idempotent, so two identical requests return one document', function () {
    Writing::factory()->published()->create(['title' => 'Idempotent vue']);

    $first = $this->queryJson('/api/v1/search', ['q' => 'idempotent']);
    $second = $this->queryJson('/api/v1/search', ['q' => 'idempotent']);

    $first->assertOk();
    $second->assertOk();

    expect($second->json())->toBe($first->json())
        ->and($second->headers->get('ETag'))->toBe($first->headers->get('ETag'));
});

test('QUERY is listed as an allowed cross-origin method', function () {
    // QUERY is not CORS-safelisted, so a cross-origin QUERY always preflights
    // and would be blocked without this entry (§4.1, §4.14).
    expect(config('cors.allowed_methods'))->toContain('QUERY')
        ->and(config('cors.paths'))->toContain('api/*');
});
