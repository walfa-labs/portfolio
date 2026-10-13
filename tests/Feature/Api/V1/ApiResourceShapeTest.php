<?php

use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\WorkExperience;
use App\Models\Writing;
use App\Models\WritingSeries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| The serialised resource shape (§4.4)
|--------------------------------------------------------------------------
|
| §4.4's two worked examples are the contract: a collection document is
| `{data, included, links, meta}` with a list under `data`; a single-resource
| document is the same with an object under `data`; every resource object is
| `{type, id, attributes, relationships, links}`; and the `attributes` key set
| is fixed per type. The dataset below is the only copy of those key sets, so a
| per-resource attribute cannot drift without failing here.
|
| Both worked examples request `include=tags`, and that is what the seven
| content resources are asked for here too — in this implementation the
| `relationships` member rides with the `include`, and §4.2 says "reads use
| `include=tags`", so the no-include case is not pinned by the PRD. `tags`
| itself is asked for without it: a tag declares no relationships at all
| (§4.9), and its resource object therefore has no `relationships` member.
| The resource objects inside `included` are `{type, id, attributes, links}`.
|
| Every record is created with an explicit title and every query is pinned to
| an explicit sort or a single page, so nothing here depends on factory
| randomness or on an unspecified row order.
|
*/

dataset('api v1 resources', [
    'work_experiences' => [[
        'type' => 'work_experiences',
        'segment' => 'work-experiences',
        'model' => WorkExperience::class,
        'publishable' => true,
        'content' => true,
        'attributes' => [
            'title', 'slug', 'summary', 'body', 'employer', 'employer_url', 'location',
            'start_date', 'end_date', 'highlights', 'published_at', 'is_published',
            'is_placeholder', 'created_at', 'updated_at', 'logo_url', 'logo_alt',
        ],
    ]],
    'educations' => [[
        'type' => 'educations',
        'segment' => 'educations',
        'model' => Education::class,
        'publishable' => true,
        'content' => true,
        'attributes' => [
            'title', 'slug', 'summary', 'body', 'institution', 'institution_url',
            'field_of_study', 'start_date', 'end_date', 'published_at', 'is_published',
            'is_placeholder', 'created_at', 'updated_at', 'logo_url', 'logo_alt',
        ],
    ]],
    'certifications' => [[
        'type' => 'certifications',
        'segment' => 'certifications',
        'model' => Certification::class,
        'publishable' => true,
        'content' => true,
        'attributes' => [
            'title', 'slug', 'summary', 'body', 'issuer', 'credential_id', 'credential_url',
            'issued_on', 'expires_on', 'published_at', 'is_published', 'is_placeholder',
            'created_at', 'updated_at', 'badge_url', 'badge_alt',
        ],
    ]],
    'publications' => [[
        'type' => 'publications',
        'segment' => 'publications',
        'model' => Publication::class,
        'publishable' => true,
        'content' => true,
        'attributes' => [
            'title', 'slug', 'summary', 'body', 'venue', 'authors', 'doi', 'doi_url',
            'issued_on', 'published_at', 'is_published', 'is_placeholder',
            'created_at', 'updated_at', 'pdf_url',
        ],
    ]],
    'projects' => [[
        'type' => 'projects',
        'segment' => 'projects',
        'model' => Project::class,
        'publishable' => true,
        'content' => true,
        'attributes' => [
            'title', 'slug', 'summary', 'body', 'repo_url', 'live_url', 'started_on',
            'ended_on', 'is_featured', 'published_at', 'is_published', 'is_placeholder',
            'created_at', 'updated_at', 'cover_url', 'cover_variants', 'cover_alt', 'screenshots',
        ],
    ]],
    'awards' => [[
        'type' => 'awards',
        'segment' => 'awards',
        'model' => Award::class,
        'publishable' => true,
        'content' => true,
        'attributes' => [
            'title', 'slug', 'summary', 'body', 'awarding_body', 'awarded_at',
            'published_at', 'is_published', 'is_placeholder', 'created_at', 'updated_at',
        ],
    ]],
    'writings' => [[
        'type' => 'writings',
        'segment' => 'writings',
        'model' => Writing::class,
        'publishable' => true,
        'content' => true,
        'attributes' => [
            'title', 'slug', 'summary', 'excerpt', 'body_markdown', 'reading_time_minutes',
            'series', 'published_at', 'is_published', 'is_placeholder', 'created_at',
            'updated_at', 'cover_url', 'cover_variants', 'cover_alt', 'og_image_path',
        ],
    ]],
    'tags' => [[
        'type' => 'tags',
        'segment' => 'tags',
        'model' => Tag::class,
        'publishable' => false,
        'content' => false,
        'attributes' => ['name', 'slug', 'description', 'is_placeholder', 'created_at', 'updated_at'],
    ]],
]);

/**
 * Create the one record a resource's endpoints will return, tagged where the
 * resource carries the `tags` relationship.
 *
 * @param  array<string, mixed>  $resource
 * @return array{model: Model, tag: Tag|null}
 */
function createApiV1ShapeRecord(array $resource): array
{
    $class = $resource['model'];

    if (! $resource['publishable']) {
        return ['model' => $class::factory()->create(['name' => 'Shape Probe']), 'tag' => null];
    }

    $model = $class::factory()->published()->create(['title' => 'Shape Probe']);
    $tag = Tag::factory()->create(['name' => 'Shape Probe Tag']);

    $model->tags()->attach($tag);

    return ['model' => $model, 'tag' => $tag];
}

/**
 * The absolute collection and single-resource URLs of a resource.
 *
 * The single URL is built from the primary key, never from the model: the API
 * binds by id even where the public site binds by slug (§4.2).
 *
 * @param  array<string, mixed>  $resource
 * @return array{index: string, show: string}
 */
function apiV1ShapeUrls(array $resource, Model $model): array
{
    return [
        'index' => route('api.v1.'.$resource['segment'].'.index'),
        'show' => route('api.v1.'.$resource['segment'].'.show', $model->getKey()),
    ];
}

/*
 * `throttle:api-public-read` is 120/min keyed by client IP and the `array` cache
 * store lives for the whole process, so the bucket is emptied before every test
 * — a neighbouring file's requests would otherwise turn this one's into 429s.
 * `ThrottleRequests` keys a named limiter as `md5($limiterName.$limit->key)`.
 */
beforeEach(function () {
    RateLimiter::clear(md5('api-public-read127.0.0.1'));
});

test('a collection document carries the §4.4.1 members for every resource', function (array $resource) {
    ['model' => $model, 'tag' => $tag] = createApiV1ShapeRecord($resource);

    $urls = apiV1ShapeUrls($resource, $model);
    $query = $resource['content'] ? '?include=tags' : '';

    $response = $this->getJson($urls['index'].$query);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertHeader('Vary', 'Accept');

    $document = $response->json();

    expect(array_keys($document))->toEqualCanonicalizing(
        $resource['content'] ? ['data', 'included', 'links', 'meta'] : ['data', 'links', 'meta'],
    )
        ->and(array_is_list($document['data']))->toBeTrue()
        ->and($document['data'])->toHaveCount(1);

    $object = $document['data'][0];

    expect(array_keys($object))->toEqualCanonicalizing(
        $resource['content']
            ? ['type', 'id', 'attributes', 'relationships', 'links']
            : ['type', 'id', 'attributes', 'links'],
    )
        ->and($object['type'])->toBe($resource['type'])
        ->and($object['id'])->toBe((string) $model->getKey())
        ->and(array_keys($object['attributes']))->toEqualCanonicalizing($resource['attributes'])
        ->and($object['links'])->toBe(['self' => $urls['show']]);

    if ($resource['content']) {
        // The relationship object is `data` only: v1 serves no relationship
        // endpoint, so a `links.self`/`links.related` pair would advertise a
        // route JSON:API 1.1 requires the server to answer and it does not.
        expect(array_keys($object['relationships']['tags']))->toBe(['data'])
            ->and($object['relationships']['tags']['data'])
            ->toEqualCanonicalizing([['type' => 'tags', 'id' => (string) $tag->getKey()]])
            ->and($object['attributes']['is_published'])->toBeTrue()
            ->and($document['included'])->toHaveCount(1)
            ->and($document['included'][0]['id'])->toBe((string) $tag->getKey())
            ->and($document['included'][0]['type'])->toBe('tags');
    } else {
        expect($object)->not->toHaveKey('relationships');
    }

    expect(array_keys($document['links']))->toEqualCanonicalizing(['self', 'first', 'prev', 'next', 'last'])
        ->and($document['links']['self'])->toBe($urls['index'].$query)
        ->and($document['meta']['page'])->toBe(['number' => 1, 'size' => 20, 'total' => 1, 'pages' => 1]);

    // No advertised relationship URL anywhere in the document: the only
    // `/relationships/` a JSON:API relationship object may publish is one the
    // server answers with `GET`, and v1 has none (§4.2, amendment A-107).
    expect($response->getContent())->not->toContain('/relationships/');
})->with('api v1 resources');

test('a single-resource document carries the §4.4.2 members for every resource', function (array $resource) {
    ['model' => $model, 'tag' => $tag] = createApiV1ShapeRecord($resource);

    $urls = apiV1ShapeUrls($resource, $model);
    $query = $resource['content'] ? '?include=tags' : '';

    $response = $this->getJson($urls['show'].$query);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.api+json');

    $document = $response->json();

    expect(array_keys($document))->toEqualCanonicalizing(
        $resource['content'] ? ['data', 'included', 'links', 'meta'] : ['data', 'links', 'meta'],
    )
        ->and(array_is_list($document['data']))->toBeFalse();

    $object = $document['data'];

    expect(array_keys($object))->toEqualCanonicalizing(
        $resource['content']
            ? ['type', 'id', 'attributes', 'relationships', 'links']
            : ['type', 'id', 'attributes', 'links'],
    )
        ->and($object['type'])->toBe($resource['type'])
        ->and($object['id'])->toBe((string) $model->getKey())
        ->and(array_keys($object['attributes']))->toEqualCanonicalizing($resource['attributes'])
        ->and($object['links'])->toBe(['self' => $urls['show']])
        ->and($document['links'])->toBe(['self' => $urls['show']])
        ->and($document['meta']['updated_at'])->toBe($object['attributes']['updated_at']);

    if ($resource['content']) {
        // Corrected from asserting the old `links` pair: the relationship object
        // is `data` only, because v1 serves no relationship endpoint and
        // JSON:API 1.1 requires `GET` on any relationship `self` URL advertised.
        // This is a correction of a wrong shape, not a weakened assertion.
        expect(array_keys($object['relationships']['tags']))->toBe(['data'])
            ->and($object['relationships']['tags']['data'])
            ->toEqualCanonicalizing([['type' => 'tags', 'id' => (string) $tag->getKey()]])
            ->and($document['included'])->toHaveCount(1)
            ->and(array_keys($document['included'][0]))
            ->toEqualCanonicalizing(['type', 'id', 'attributes', 'links']);
    } else {
        expect($object)->not->toHaveKey('relationships');
    }

    expect($response->getContent())->not->toContain('/relationships/');
})->with('api v1 resources');

test('the administrative and derived columns are never published as attributes', function (array $resource) {
    ['model' => $model] = createApiV1ShapeRecord($resource);

    $urls = apiV1ShapeUrls($resource, $model);

    $attributes = $this->getJson($urls['show'])->assertOk()->json('data.attributes');

    // `status`, the SEO columns and `sort_order` are writable but never read
    // (§4.5); `body_html` is derived from `body` and stays server-side (§2.6).
    $forbidden = ['id', 'type', 'deleted_at'];

    if ($resource['content']) {
        $forbidden = [...$forbidden, 'status', 'meta_title', 'meta_description', 'sort_order', 'body_html'];
    }

    foreach ($forbidden as $member) {
        expect($attributes)->not->toHaveKey($member);
    }
})->with('api v1 resources');

test('include=tags embeds one copy of each tag in the top-level included array', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $first = Writing::factory()->published()->create(['title' => 'First Embedded']);
    $second = Writing::factory()->published()->create(['title' => 'Second Embedded']);

    $first->tags()->attach($tag);
    $second->tags()->attach($tag);

    $response = $this->getJson('/api/v1/writings?include=tags');

    $response->assertOk();

    $document = $response->json();

    expect(array_keys($document))->toEqualCanonicalizing(['data', 'included', 'links', 'meta'])
        ->and($document['data'])->toHaveCount(2)
        ->and($document['included'])->toHaveCount(1);

    $included = $document['included'][0];

    expect(array_keys($included))->toEqualCanonicalizing(['type', 'id', 'attributes', 'links'])
        ->and($included['type'])->toBe('tags')
        ->and($included['id'])->toBe((string) $tag->getKey())
        ->and($included['attributes'])->toBe([
            'name' => 'Laravel',
            'slug' => 'laravel',
            'description' => $tag->description,
            'is_placeholder' => true,
            'created_at' => $tag->created_at->toIso8601ZuluString(),
            'updated_at' => $tag->updated_at->toIso8601ZuluString(),
        ])
        ->and($included['links'])->toBe(['self' => route('api.v1.tags.show', $tag->getKey())]);

    foreach ($document['data'] as $object) {
        expect(array_keys($object['relationships']['tags']))->toBe(['data'])
            ->and($object['relationships']['tags']['data'])
            ->toEqualCanonicalizing([['type' => 'tags', 'id' => (string) $tag->getKey()]]);
    }

    // §4.2/A-107: the relationship object advertises no URL at all.
    expect($response->getContent())->not->toContain('/relationships/');
});

test('a collection without include carries no included member', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $writing = Writing::factory()->published()->create(['title' => 'Unincluded']);
    $writing->tags()->attach($tag);

    $document = $this->getJson('/api/v1/writings')->assertOk()->json();

    expect($document)->not->toHaveKey('included')
        ->and($document['data'][0]['attributes']['title'])->toBe('Unincluded');
});

test('a sparse fieldset narrows attributes but never removes id or type', function () {
    $writing = Writing::factory()->published()->create(['title' => 'Sparse Fieldset']);

    $document = $this->getJson('/api/v1/writings?fields[writings]=title,slug')->assertOk()->json();

    $object = $document['data'][0];

    expect(array_keys($object['attributes']))->toEqualCanonicalizing(['title', 'slug'])
        ->and($object['attributes']['title'])->toBe('Sparse Fieldset')
        ->and($object['attributes']['slug'])->toBe('sparse-fieldset')
        ->and($object['id'])->toBe((string) $writing->getKey())
        ->and($object['type'])->toBe('writings');
});

test('writings publishes body_markdown, the reading time and series as an attribute object', function () {
    $series = WritingSeries::factory()->create(['title' => 'Placeholder Series']);

    $writing = Writing::factory()->published()->create([
        'title' => 'Carve Out',
        'body' => 'A short body.',
        'series_id' => $series->getKey(),
    ]);

    $attributes = $this->getJson(route('api.v1.writings.show', $writing->getKey()))
        ->assertOk()
        ->json('data.attributes');

    expect($attributes['body_markdown'])->toBe('A short body.')
        ->and($attributes)->not->toHaveKey('body')
        ->and($attributes)->not->toHaveKey('body_html')
        ->and($attributes['reading_time_minutes'])->toBe($writing->reading_time_minutes)
        ->and($attributes['series'])->toBe([
            'id' => (string) $series->getKey(),
            'title' => 'Placeholder Series',
            'slug' => $series->slug,
        ]);
});

test('series is null for a writing without one and is never includable', function () {
    Writing::factory()->published()->create(['title' => 'Seriesless', 'series_id' => null]);

    $this->getJson('/api/v1/writings?sort=title')
        ->assertOk()
        ->assertJsonPath('data.0.attributes.series', null);

    $this->getJson('/api/v1/writings?include=series')
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_query_parameter')
        ->assertJsonPath('errors.0.source.parameter', 'include');
});

test('a single resource enforces the include allow-list exactly as the collection does', function () {
    $tag = Tag::factory()->create(['name' => 'Single Include Tag', 'slug' => 'single-include-tag']);
    $writing = Writing::factory()->published()->create(['title' => 'Single Include Writing']);
    $writing->tags()->attach($tag);

    $show = route('api.v1.writings.show', $writing->getKey());

    // Declared: the relationship is embedded, so the fix rejects only what the
    // contract says it must.
    $declared = $this->getJson($show.'?include=tags');

    $declared->assertOk();

    expect($declared->json('included.0.id'))->toBe((string) $tag->getKey())
        ->and($declared->json('data.relationships.tags.data'))
        ->toEqualCanonicalizing([['type' => 'tags', 'id' => (string) $tag->getKey()]]);

    // Undeclared: the single-resource path answers the same 400 the collection
    // path answers — same code, same `source.parameter`, same detail — because
    // both run one allow-list through one Spatie check (A-112).
    $collection = $this->getJson('/api/v1/writings?include=nonsense');
    $single = $this->getJson($show.'?include=nonsense');

    $collection->assertStatus(400);
    $single->assertStatus(400);

    expect($single->json('errors.0.code'))->toBe('invalid_query_parameter')
        ->and($single->json('errors.0.source.parameter'))->toBe('include')
        ->and($single->json('errors.0.detail'))->toBe($collection->json('errors.0.detail'));
});

test('every resource rejects an undeclared include on its single-resource URI', function (array $resource) {
    ['model' => $model] = createApiV1ShapeRecord($resource);

    $this->getJson(apiV1ShapeUrls($resource, $model)['show'].'?include=nonsense')
        ->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'invalid_query_parameter')
        ->assertJsonPath('errors.0.source.parameter', 'include');
})->with('api v1 resources');

test('the collection links and meta.page match §4.4.1 and preserve the caller query parameters', function () {
    $index = route('api.v1.writings.index');

    Writing::factory()->published()->create(['title' => 'Page One A', 'published_at' => now()->subDays(3)]);
    Writing::factory()->published()->create(['title' => 'Page One B', 'published_at' => now()->subDays(2)]);
    Writing::factory()->published()->create(['title' => 'Page Two C', 'published_at' => now()->subDay()]);

    $first = $this->getJson($index.'?page[size]=2')->assertOk()->json();

    expect($first['meta']['page'])->toBe(['number' => 1, 'size' => 2, 'total' => 3, 'pages' => 2])
        ->and($first['data'])->toHaveCount(2)
        ->and($first['data'][0]['attributes']['title'])->toBe('Page Two C')
        ->and($first['data'][1]['attributes']['title'])->toBe('Page One B')
        ->and(urldecode($first['links']['self']))->toBe($index.'?page[size]=2')
        ->and(urldecode($first['links']['first']))->toBe($index.'?page[number]=1&page[size]=2')
        ->and($first['links']['prev'])->toBeNull()
        ->and(urldecode($first['links']['next']))->toBe($index.'?page[number]=2&page[size]=2')
        ->and(urldecode($first['links']['last']))->toBe($index.'?page[number]=2&page[size]=2');

    $second = $this->getJson($index.'?page[number]=2&page[size]=2')->assertOk()->json();

    expect($second['meta']['page'])->toBe(['number' => 2, 'size' => 2, 'total' => 3, 'pages' => 2])
        ->and($second['data'])->toHaveCount(1)
        ->and($second['data'][0]['attributes']['title'])->toBe('Page One A')
        ->and(urldecode($second['links']['prev']))->toBe($index.'?page[number]=1&page[size]=2')
        ->and($second['links']['next'])->toBeNull();
});
