<?php

use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use App\Models\Writing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| The tags resource (§4.2 row 8, §15.8)
|--------------------------------------------------------------------------
|
| §15.8 settles the one open question about this resource: `tags` is a **full
| six-method resource**, not a read-only one, because §4.5's Table B is defined
| as applying "identically to every resource — no exceptions". So this file
| walks all six methods over the wire rather than trusting the route table, and
| asserts what makes a tag different from the seven content types:
|
| - its own table (§2.5): `name`, `description` and `is_placeholder` are the
|   only writable members, and a content attribute is a `422` rather than a
|   silently dropped key;
| - no publication state, so the collection is not `published()`-scoped — a tag
|   attached only to a draft is still listed, exactly as `/tags` lists it;
| - it is addressed by its numeric id on the API even though `Tag` binds by slug
|   on the public site (§2.4), which is what `findForWrite()` exists for;
| - `TagPolicy` gates all four writes, so an authenticated non-admin is a `403`
|   and an absent session a `401` (§4.11);
| - deleting one detaches its pivot rows and leaves every tagged record intact
|   (`taggables.tag_id` is `ON DELETE CASCADE`, §2.5).
|
| Writes carry the JSON:API media type (§4.6) and go through the `api-admin`
| group, so the session is established before the limiter keys on it.
| `api-admin-write` is 60/min per user id and the `array` cache store lives for
| the whole process, so each helper empties the bucket for the user it creates;
| `api-public-read` is emptied in `beforeEach()`.
|
| The 422 assertions read the failing key from `errors[].source.pointer`, not
| through `assertJsonValidationErrors()`: §4.6 makes `errors` a JSON:API **list**,
| while that helper reads the framework's own `{errors: {key: [message]}}` map.
| `ContentApiValidationTest` documents the substitution in full.
|
*/

/**
 * The administrator whose session authorises every tag write (§4.11).
 */
function apiTagAdmin(): User
{
    $admin = User::factory()->administrator()->create();

    RateLimiter::clear(md5('api-admin-write'.$admin->getAuthIdentifier()));

    return $admin;
}

/**
 * An authenticated, verified user who is not the administrator.
 */
function apiTagNonAdmin(): User
{
    $user = User::factory()->create();

    RateLimiter::clear(md5('api-admin-write'.$user->getAuthIdentifier()));

    return $user;
}

/**
 * The one `Content-Type` a write is allowed to declare (§4.6).
 *
 * @return array<string, string>
 */
function apiTagJsonApi(): array
{
    return ['Content-Type' => 'application/vnd.api+json'];
}

/**
 * A JSON:API request document for a tag.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, array<string, mixed>>
 */
function apiTagDocument(array $attributes, ?int $id = null): array
{
    $data = ['type' => 'tags', 'attributes' => $attributes];

    if ($id !== null) {
        $data['id'] = (string) $id;
    }

    return ['data' => $data];
}

/**
 * Assert a `422` whose only failing member is the one at `$pointer`.
 */
function apiTagAssert422(TestResponse $response, string $pointer): void
{
    $response->assertStatus(422);
    $response->assertHeader('Content-Type', 'application/vnd.api+json');
    $response->assertJsonPath('errors.0.status', '422');
    $response->assertJsonPath('errors.0.code', 'validation_failed');

    expect(collect($response->json('errors'))->pluck('source.pointer')->filter()->values()->all())
        ->toBe([$pointer]);
}

/**
 * Assert a JSON:API error document for a status §4.6 assigns a code to.
 *
 * The media type is asserted with a message because the failure it catches is
 * not readable from the header alone: `AppServiceProvider` registers Inertia's
 * exception handler for `403`, `404`, `500` and `503`
 * (`app/Providers/AppServiceProvider.php:206`), and that handler replaces the
 * response the `api/*` renderers produced with the public `public/Error` page
 * (§3.12) whatever the request's path is. §4.6 says every error from the API —
 * "including `429` and `500`" — is `application/vnd.api+json`.
 */
function apiTagAssertErrorDocument(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status);

    expect($response->headers->get('Content-Type'))->toBe(
        'application/vnd.api+json',
        "A {$status} from /api/v1 must be a JSON:API document (§4.6); this one arrived as "
        .var_export($response->headers->get('Content-Type'), true).'.',
    );

    $response->assertJsonPath('errors.0.status', (string) $status);
    $response->assertJsonPath('errors.0.code', $code);
}

beforeEach(function () {
    RateLimiter::clear(md5('api-public-read127.0.0.1'));
});

test('GET on the collection lists every tag, including one only a draft carries', function () {
    $real = Tag::factory()->real()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    $placeholder = Tag::factory()->create(['name' => 'Vue', 'slug' => 'vue']);

    // A tag has no `status` and no `published_at`, so the read scope cannot be
    // `published()` — a tag attached only to a draft is still listed (§4.8).
    Writing::factory()->draft()->create(['title' => 'Draft Writing'])->tags()->attach($placeholder);

    $response = $this->getJson('/api/v1/tags');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.api+json');

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.type'))->toBe('tags')
        ->and($response->json('data.0.id'))->toBe((string) $real->getKey())
        ->and($response->json('data.0.attributes.name'))->toBe('Laravel')
        ->and($response->json('data.0.attributes.slug'))->toBe('laravel')
        ->and($response->json('data.0.attributes.is_placeholder'))->toBeFalse()
        ->and($response->json('data.1.id'))->toBe((string) $placeholder->getKey())
        ->and($response->json('data.1.attributes.is_placeholder'))->toBeTrue()
        ->and($response->json('meta.page'))->toBe(['number' => 1, 'size' => 20, 'total' => 2, 'pages' => 1]);
});

test('GET on the single resource answers the tag document', function () {
    $tag = Tag::factory()->create([
        'name' => 'Laravel',
        'slug' => 'laravel',
        'description' => 'Framework notes',
    ]);

    $response = $this->getJson("/api/v1/tags/{$tag->getKey()}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.api+json');

    expect($response->json('data.type'))->toBe('tags')
        ->and($response->json('data.id'))->toBe((string) $tag->getKey())
        ->and($response->json('data.attributes'))->toBe([
            'name' => 'Laravel',
            'slug' => 'laravel',
            'description' => 'Framework notes',
            'is_placeholder' => true,
            'created_at' => $tag->created_at->toIso8601ZuluString(),
            'updated_at' => $tag->updated_at->toIso8601ZuluString(),
        ])
        ->and($response->json('data.links.self'))->toBe(route('api.v1.tags.show', ['tag' => $tag->getKey()]))
        ->and($response->json('meta.updated_at'))->toBe($tag->updated_at->toIso8601ZuluString());
});

test('the API addresses a tag by its numeric id, never by its slug', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    // `Tag::getRouteKeyName()` is `slug` for the public site (§2.4), so an
    // implicit binding would resolve the API's id as a slug. Reads and writes
    // both bind the primary key instead.
    $this->getJson('/api/v1/tags/laravel')->assertNotFound();

    $this->actingAs(apiTagAdmin());

    $this->deleteJson('/api/v1/tags/laravel')->assertNotFound();
    $this->putJson('/api/v1/tags/laravel', apiTagDocument(['name' => 'Laravel'], (int) $tag->getKey()), apiTagJsonApi())
        ->assertNotFound();

    expect(Tag::query()->find($tag->getKey()))->not->toBeNull();
});

test('POST creates a tag, derives its slug, and answers 201 with the Location of it', function () {
    $this->actingAs(apiTagAdmin());

    $response = $this->postJson('/api/v1/tags', apiTagDocument([
        'name' => 'Laravel',
        'description' => 'Framework notes',
        'is_placeholder' => false,
    ]), apiTagJsonApi());

    $response->assertCreated();
    $response->assertHeader('Content-Type', 'application/vnd.api+json');

    $created = Tag::query()->findOrFail($response->json('data.id'));

    // §2.4: the slug is derived server-side from the name, never client-supplied.
    expect($created->slug)->toBe('laravel')
        ->and($created->description)->toBe('Framework notes')
        ->and($created->is_placeholder)->toBeFalse()
        ->and($response->json('data.type'))->toBe('tags')
        ->and($response->json('data.attributes.slug'))->toBe('laravel');

    // §4.5: `Location` and the resource object's own `links.self` are built from
    // one URL — the id form, because that is the URI the API binds (§4.2).
    $location = route('api.v1.tags.show', ['tag' => $created->getKey()]);

    expect($response->json('data.links.self'))->toBe($location);

    expect($response->headers->get('Location'))->toBe(
        $location,
        'POST /api/v1/tags must point Location at the id URI it publishes in links.self (§4.5).',
    );
});

test('POST derives a collision suffix rather than reusing a slug', function () {
    $this->actingAs(apiTagAdmin());

    Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $response = $this->postJson('/api/v1/tags', apiTagDocument(['name' => 'Laravel']), apiTagJsonApi());

    $response->assertCreated();

    expect($response->json('data.attributes.slug'))->toBe('laravel-2');
});

test('PUT replaces the tag and leaves its slug stable', function () {
    $this->actingAs(apiTagAdmin());

    $tag = Tag::factory()->create([
        'name' => 'Laravel',
        'slug' => 'laravel',
        'description' => 'Framework notes',
        'is_placeholder' => true,
    ]);

    $response = $this->putJson(
        "/api/v1/tags/{$tag->getKey()}",
        apiTagDocument(['name' => 'Laravel Framework'], (int) $tag->getKey()),
        apiTagJsonApi(),
    );

    $response->assertOk();

    $tag->refresh();

    // §2.4: the slug is stable on update, and §4.5 resets the writable members a
    // `PUT` omitted — `description` to null, `is_placeholder` to its default.
    expect($tag->name)->toBe('Laravel Framework')
        ->and($tag->slug)->toBe('laravel')
        ->and($tag->description)->toBeNull()
        ->and($tag->is_placeholder)->toBeFalse()
        ->and($response->json('data.attributes.name'))->toBe('Laravel Framework');
});

test('PATCH amends only what it was sent', function () {
    $this->actingAs(apiTagAdmin());

    $tag = Tag::factory()->create([
        'name' => 'Laravel',
        'slug' => 'laravel',
        'description' => 'Framework notes',
    ]);

    $response = $this->patchJson(
        "/api/v1/tags/{$tag->getKey()}",
        apiTagDocument(['description' => 'Amended notes'], (int) $tag->getKey()),
        apiTagJsonApi(),
    );

    $response->assertOk();

    $tag->refresh();

    expect($tag->name)->toBe('Laravel')
        ->and($tag->slug)->toBe('laravel')
        ->and($tag->description)->toBe('Amended notes')
        ->and($tag->is_placeholder)->toBeTrue();
});

test('DELETE detaches the pivot rows and leaves the tagged records intact', function () {
    $this->actingAs(apiTagAdmin());

    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);
    $other = Tag::factory()->create(['name' => 'Vue', 'slug' => 'vue']);

    $writing = Writing::factory()->published()->create(['title' => 'Tagged Writing']);
    $project = Project::factory()->published()->create(['title' => 'Tagged Project']);

    $writing->tags()->attach([$tag->getKey(), $other->getKey()]);
    $project->tags()->attach($tag);

    expect(DB::table('taggables')->where('tag_id', $tag->getKey())->count())->toBe(2);

    $response = $this->deleteJson("/api/v1/tags/{$tag->getKey()}");

    $response->assertNoContent(204);
    expect($response->getContent())->toBe('');

    // §2.5: `taggables.tag_id` cascades, so the pivot rows go with the tag.
    expect(Tag::query()->find($tag->getKey()))->toBeNull()
        ->and(DB::table('taggables')->where('tag_id', $tag->getKey())->count())->toBe(0)
        ->and(DB::table('taggables')->where('tag_id', $other->getKey())->count())->toBe(1);

    // The content itself is untouched — not deleted, not trashed, still tagged
    // with the tag that survived.
    expect(Writing::query()->find($writing->getKey()))->not->toBeNull()
        ->and(Project::query()->find($project->getKey()))->not->toBeNull()
        ->and($writing->fresh()->tags()->pluck('tags.slug')->all())->toBe(['vue']);
});

test('a second DELETE of the same tag is a 404', function () {
    $this->actingAs(apiTagAdmin());

    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $this->deleteJson("/api/v1/tags/{$tag->getKey()}")->assertNoContent(204);

    $second = $this->deleteJson("/api/v1/tags/{$tag->getKey()}");

    apiTagAssertErrorDocument($second, 404, 'not_found');
});

test('OPTIONS advertises all six methods on both URI shapes', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $collection = $this->call('OPTIONS', '/api/v1/tags');

    $collection->assertOk();
    $collection->assertHeader('Allow', 'GET, HEAD, OPTIONS, POST');
    expect($collection->getContent())->toBe('');

    $single = $this->call('OPTIONS', "/api/v1/tags/{$tag->getKey()}");

    $single->assertOk();
    $single->assertHeader('Allow', 'GET, HEAD, OPTIONS, PUT, PATCH, DELETE');
    expect($single->getContent())->toBe('');
});

test('TagPolicy gates all four writes: a non-admin is a 403', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $this->actingAs(apiTagNonAdmin());

    $store = $this->postJson('/api/v1/tags', apiTagDocument(['name' => 'Denied']), apiTagJsonApi());
    $update = $this->putJson("/api/v1/tags/{$tag->getKey()}", apiTagDocument(['name' => 'Denied'], (int) $tag->getKey()), apiTagJsonApi());
    $patch = $this->patchJson("/api/v1/tags/{$tag->getKey()}", apiTagDocument(['description' => 'Denied'], (int) $tag->getKey()), apiTagJsonApi());
    $destroy = $this->deleteJson("/api/v1/tags/{$tag->getKey()}");

    foreach ([$store, $update, $patch, $destroy] as $response) {
        apiTagAssertErrorDocument($response, 403, 'forbidden');
    }

    // A denial changes nothing: the tag is untouched and no second one exists.
    expect(Tag::query()->count())->toBe(1)
        ->and($tag->refresh()->name)->toBe('Laravel');
});

test('the reads stay public while the writes require a session', function () {
    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    $this->getJson('/api/v1/tags')->assertOk();
    $this->getJson("/api/v1/tags/{$tag->getKey()}")->assertOk();

    $this->postJson('/api/v1/tags', apiTagDocument(['name' => 'Denied']), apiTagJsonApi())->assertUnauthorized();
    $this->putJson("/api/v1/tags/{$tag->getKey()}", apiTagDocument(['name' => 'Denied'], (int) $tag->getKey()), apiTagJsonApi())->assertUnauthorized();
    $this->patchJson("/api/v1/tags/{$tag->getKey()}", apiTagDocument(['description' => 'Denied'], (int) $tag->getKey()), apiTagJsonApi())->assertUnauthorized();
    $this->deleteJson("/api/v1/tags/{$tag->getKey()}")->assertUnauthorized();
});

test('name is required on POST and on PUT, and is the only NOT NULL member', function () {
    $this->actingAs(apiTagAdmin());

    apiTagAssert422(
        $this->postJson('/api/v1/tags', apiTagDocument(['description' => 'No name']), apiTagJsonApi()),
        '/data/attributes/name',
    );

    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    apiTagAssert422(
        $this->putJson("/api/v1/tags/{$tag->getKey()}", apiTagDocument(['description' => 'No name'], (int) $tag->getKey()), apiTagJsonApi()),
        '/data/attributes/name',
    );

    // A `PATCH` leaves omitted members alone, so the same column is not required.
    $this->patchJson("/api/v1/tags/{$tag->getKey()}", apiTagDocument(['description' => 'Amended'], (int) $tag->getKey()), apiTagJsonApi())
        ->assertOk();
});

test('a tag refuses the content attributes and the non-writable members', function () {
    $this->actingAs(apiTagAdmin());

    $tag = Tag::factory()->create(['name' => 'Laravel', 'slug' => 'laravel']);

    foreach (['title', 'status', 'published_at', 'meta_title', 'sort_order'] as $attribute) {
        apiTagAssert422(
            $this->postJson('/api/v1/tags', apiTagDocument(['name' => 'Denied', $attribute => 'x']), apiTagJsonApi()),
            "/data/attributes/{$attribute}",
        );
    }

    foreach (['id', 'slug', 'created_at', 'updated_at', 'deleted_at'] as $member) {
        apiTagAssert422(
            $this->postJson('/api/v1/tags', apiTagDocument(['name' => 'Denied', $member => '2020-01-01']), apiTagJsonApi()),
            "/data/attributes/{$member}",
        );
    }

    apiTagAssert422(
        $this->patchJson("/api/v1/tags/{$tag->getKey()}", apiTagDocument(['slug' => 'hand-written'], (int) $tag->getKey()), apiTagJsonApi()),
        '/data/attributes/slug',
    );

    expect(Tag::query()->count())->toBe(1)
        ->and($tag->refresh()->slug)->toBe('laravel');
});
