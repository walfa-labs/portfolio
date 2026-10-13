<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\Writing;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Search over the API (§4.1, §4.4.3)
|--------------------------------------------------------------------------
|
| The document is deliberately not JSON:API: `results` mixes resource types and
| carries the canonical public `path`, so the success media type is
| `application/json`. Every error is a JSON:API error document under
| `application/vnd.api+json` (§4.6), which is the asymmetry §4.1 pins.
|
| `throttle:api-search` is 30/min keyed by client IP and the `array` cache store
| lives for the whole process, so `clearSearchRateLimiter()` empties the bucket
| before every test — otherwise this file's own requests, or a neighbouring
| file's, would turn into 429s. `ThrottleRequests` keys a named limiter as
| `md5($limiterName.$limit->key)`, which is the key cleared here.
|
*/

/**
 * Clear the `api-search` bucket for an IP.
 */
function clearSearchRateLimiter(string $ip = '127.0.0.1'): void
{
    RateLimiter::clear(md5('api-search'.$ip));
}

beforeEach(function () {
    clearSearchRateLimiter();
});

test('QUERY returns the search document with its validators', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $writing = Writing::factory()->published()->create(['title' => 'Vue and Laravel']);
    $writing->tags()->attach($tag);

    $project = Project::factory()->published()->create(['title' => 'Vue project']);
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
    // `Vary: Accept` rides on JSON:API responses only (decision 15); the plain
    // `application/json` document must not vary on the request's `Accept`.
    $response->assertHeaderMissing('Vary');

    // §4.12: private, never shared-cached, with a strong validator. Symfony's
    // header bag re-serialises the directives in its canonical order.
    $response->assertHeader('Cache-Control', 'max-age=0, must-revalidate, private');
    expect($response->headers->get('ETag'))->toStartWith('"')->toEndWith('"');

    $response->assertJsonMissingPath('meta.query_method');
    $response->assertJsonPath('meta.page', ['number' => 1, 'size' => 20, 'total' => 2, 'pages' => 1]);
    $response->assertJsonPath('meta.counts_by_type', ['writings' => 1, 'projects' => 1]);

    // §4.4.3: exactly these members, the API's plural type, and a string id.
    expect($response->json('results.0'))->toBe([
        'type' => 'writings',
        'id' => (string) $writing->getKey(),
        'title' => 'Vue and Laravel',
        'excerpt' => $writing->excerpt,
        'path' => route('writing.show', $writing, false),
        'score' => $response->json('results.0.score'),
    ]);
});

test('GET is the flattened fallback and advertises QUERY support', function () {
    Writing::factory()->published()->create(['title' => 'Vue fallback']);

    $response = $this->getJson('/api/v1/search?q=vue&types=writings&sort=-published_at&page[number]=1&page[size]=20');

    $response->assertOk();
    $response->assertHeader('Accept-Query', 'application/json');
    $response->assertJsonMissingPath('meta.query_method');
    $response->assertJsonPath('results.0.title', 'Vue fallback');
    $response->assertJsonPath('meta.counts_by_type', ['writings' => 1]);
});

test('OPTIONS advertises the verbs and HEAD answers without a body', function () {
    $this->call('OPTIONS', '/api/v1/search')
        ->assertOk()
        ->assertHeader('Allow', 'GET, HEAD, OPTIONS, QUERY')
        ->assertHeader('Accept-Query', 'application/json');

    Writing::factory()->published()->create(['title' => 'Vue head']);

    $head = $this->call('HEAD', '/api/v1/search?q=vue');

    $head->assertOk();
    $head->assertHeader('Accept-Query', 'application/json');
    expect($head->getContent())->toBe('');
});

test('a conditional request is answered with 304 and the same validators', function () {
    Writing::factory()->published()->create(['title' => 'Vue conditional']);

    $first = $this->queryJson('/api/v1/search', ['q' => 'vue']);

    $second = $this->call('QUERY', '/api/v1/search', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_IF_NONE_MATCH' => $first->headers->get('ETag'),
    ], '{"q":"vue"}');

    $second->assertStatus(304);
    expect($second->getContent())->toBe('');
});

test('the document never contains a draft', function () {
    Writing::factory()->published()->create(['title' => 'Vue published']);
    Writing::factory()->draft()->create(['title' => 'Vue draft']);

    $this->queryJson('/api/v1/search', ['q' => 'vue'])
        ->assertOk()
        ->assertJsonPath('meta.page.total', 1)
        ->assertJsonPath('results.0.title', 'Vue published');
});

test('tags narrow the result set and sort replaces the relevance order', function () {
    $laravel = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    $vue = Tag::factory()->create(['name' => 'Vue', 'slug' => 'vue']);

    $alpha = Writing::factory()->published()->create(['title' => 'Alpha vue']);
    $alpha->tags()->attach($laravel);

    $zulu = Writing::factory()->published()->create(['title' => 'Zulu vue']);
    $zulu->tags()->attach($vue);

    $this->queryJson('/api/v1/search', ['q' => 'vue', 'tags' => ['laravel']])
        ->assertOk()
        ->assertJsonPath('meta.page.total', 1)
        ->assertJsonPath('results.0.title', 'Alpha vue');

    $this->queryJson('/api/v1/search', ['q' => 'vue', 'sort' => 'title'])
        ->assertOk()
        ->assertJsonPath('results.0.title', 'Alpha vue')
        ->assertJsonPath('results.1.title', 'Zulu vue');

    $this->queryJson('/api/v1/search', ['q' => 'vue', 'sort' => '-title'])
        ->assertOk()
        ->assertJsonPath('results.0.title', 'Zulu vue')
        ->assertJsonPath('results.1.title', 'Alpha vue');
});

test('an absent query answers an empty document, not an error', function () {
    Writing::factory()->published()->create(['title' => 'Anything']);

    $response = $this->queryJson('/api/v1/search', []);

    $response->assertOk();
    $response->assertJsonPath('meta.page', ['number' => 1, 'size' => 20, 'total' => 0, 'pages' => 1]);
    $response->assertJsonPath('results', []);
    expect($response->getContent())->toContain('"counts_by_type":{}');
});

test('an out-of-range page number answers 200 with an empty page', function () {
    Writing::factory()->published()->create(['title' => 'Vue paged']);

    $this->queryJson('/api/v1/search', ['q' => 'vue', 'page' => ['number' => 9, 'size' => 1]])
        ->assertOk()
        ->assertJsonPath('meta.page.total', 1)
        ->assertJsonPath('results', []);
});

test('the QUERY error set is the JSON:API envelope', function () {
    $cases = [
        'a non-JSON content type is 415' => fn () => $this->call('QUERY', '/api/v1/search', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"q":"vue"}'),
        'an undecodable body is 400' => fn () => $this->call('QUERY', '/api/v1/search', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"q":'),
        'a short q is 422' => fn () => $this->queryJson('/api/v1/search', ['q' => 'a']),
        'a wildcard Accept passes' => fn () => $this->call('QUERY', '/api/v1/search', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => '*/*',
        ], '{"q":"vue"}'),
    ];

    [$wrongType, $malformed, $short, $wildcard] = [
        $cases['a non-JSON content type is 415'](),
        $cases['an undecodable body is 400'](),
        $cases['a short q is 422'](),
        $cases['a wildcard Accept passes'](),
    ];

    $wrongType->assertStatus(415);
    $wrongType->assertHeader('Content-Type', 'application/vnd.api+json');
    $wrongType->assertHeader('Accept', 'application/json');
    $wrongType->assertHeader('Accept-Query', 'application/json');
    $wrongType->assertJsonPath('errors.0.code', 'unsupported_media_type');
    $wrongType->assertJsonPath('errors.0.source.header', 'Content-Type');

    $malformed->assertStatus(400);
    $malformed->assertJsonPath('errors.0.code', 'malformed_body');

    $short->assertStatus(422);
    $short->assertJsonPath('errors.0.code', 'validation_failed');
    $short->assertJsonPath('errors.0.source.pointer', '/q');

    $wildcard->assertOk();
});

test('an undeclared parameter or an out-of-range page size is 400', function () {
    $unknown = $this->queryJson('/api/v1/search', ['q' => 'vue', 'foo' => 'bar']);

    $unknown->assertStatus(400);
    $unknown->assertJsonPath('errors.0.code', 'invalid_query_parameter');
    $unknown->assertJsonPath('errors.0.source.parameter', 'foo');

    $this->queryJson('/api/v1/search', ['q' => 'vue', 'page' => ['size' => 101]])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.source.parameter', 'page[size]');

    $this->getJson('/api/v1/search?q=vue&page[size]=0')
        ->assertStatus(400)
        ->assertJsonPath('errors.0.source.parameter', 'page[size]');

    $this->queryJson('/api/v1/search', ['q' => 'vue', 'sort' => 'nonsense'])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.source.parameter', 'sort');

    $this->queryJson('/api/v1/search', ['q' => 'vue', 'types' => ['nonsense']])
        ->assertStatus(400)
        ->assertJsonPath('errors.0.source.parameter', 'types');
});

test('an unacceptable Accept is 406 and names both media types', function () {
    $response = $this->call('GET', '/api/v1/search?q=vue', [], [], [], ['HTTP_ACCEPT' => 'text/html']);

    $response->assertStatus(406);
    $response->assertHeader('Accept', 'application/json, application/vnd.api+json');
    $response->assertJsonPath('errors.0.code', 'not_acceptable');
    $response->assertJsonPath('errors.0.source.header', 'Accept');
});

test('another verb on the search URI is 405 with Allow', function () {
    $response = $this->postJson('/api/v1/search', ['q' => 'vue']);

    $response->assertStatus(405);
    $response->assertHeader('Allow', 'GET, HEAD, OPTIONS, QUERY');
    $response->assertJsonPath('errors.0.code', 'method_not_allowed');
});

test('the search route is not in the web middleware group', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'api/v1/search' && in_array('QUERY', $route->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->not->toContain('web');
});
