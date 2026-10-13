<?php

use App\Http\Controllers\Api\V1\AwardController;
use App\Http\Controllers\Api\V1\CertificationController;
use App\Http\Controllers\Api\V1\EducationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\PublicationController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\WorkExperienceController;
use App\Http\Controllers\Api\V1\WritingController;
use Illuminate\Http\Middleware\CheckResponseForModifications;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| API v1 — Richardson Maturity Model level 2 (§4)
|--------------------------------------------------------------------------
|
| Every v1 route lives in this one file inside the `v1` prefix group (§4.3's
| `routes/api/v1.php` is superseded by §12 phase 5). `bootstrap/app.php` keeps
| the default `apiPrefix`, so this whole file is served under `/api`.
|
| The routes are registered in the stateless `api` middleware group — never
| `web` (§4.11, §13 R1). That is not cosmetic: `PreventRequestForgery::isReading()`
| exempts only HEAD/GET/OPTIONS, so a QUERY route behind `web` would demand a
| CSRF token and answer 419 in the browser while Pest stayed green, because the
| middleware is bypassed under `runningUnitTests()`.
|
| Writes carry the `api-admin` group defined in `bootstrap/app.php` — cookies,
| session, CSRF, `auth`, `verified` and `throttle:api-admin-write`, declared in
| that order — so the limiter keys on a real user id rather than null. The
| framework's middleware priority map then resolves the pipeline to
| cookies → session → auth → throttle → bindings → CSRF → verified.
|
| Drafts are never exposed. Every public read is `published()`-scoped inside
| the controllers, and the scope is not overridable by a query parameter, so
| there is no authenticated read path in v1 (§4.12's "authenticated reads" row
| is vacuous here).
|
| Route names are `api.v1.<plural-kebab-segment>.<action>`. The explicit
| `->names()` is required: `routes/web.php` already owns `projects.index`,
| `awards.index`, `certifications.index`, `publications.index`, `tags.index`
| and `tags.show`, which the default `apiResource` names would collide with.
|
*/

/**
 * `Allow` per URI shape (§4.5 Table B, decision 5).
 *
 * Laravel derives the same *set* from the registered verbs but not the same
 * order, and a collection must not advertise `PUT`/`PATCH`/`DELETE`. These
 * strings are also what `JsonApiError::allowedMethods()` normalises the
 * framework's own `405` values into, so the two paths cannot drift.
 */
$collectionAllow = 'GET, HEAD, OPTIONS, POST';
$singleAllow = 'GET, HEAD, OPTIONS, PUT, PATCH, DELETE';
$searchAllow = 'GET, HEAD, OPTIONS, QUERY';

/**
 * The `OPTIONS` handler for a collection or a single resource: `200` + `Allow`,
 * empty body, public and unthrottled (§4.5 Table B).
 *
 * A closure action rather than `Route::any()` so no other verb is registered on
 * the URI, and unnamed so it contributes nothing to the generated Wayfinder
 * trees.
 */
$options = static fn (string $allow): Closure => static fn (): Response => response('', Response::HTTP_OK, [
    'Allow' => $allow,
]);

/**
 * The `OPTIONS` handler for `/api/v1/search`, which additionally advertises
 * QUERY support with `Accept-Query` (§4.1, decision 16).
 */
$searchOptions = static fn (): Response => response('', Response::HTTP_OK, [
    'Allow' => $searchAllow,
    'Accept-Query' => 'application/json',
]);

Route::prefix('v1')->group(function () use ($collectionAllow, $options, $searchOptions, $singleAllow): void {
    /*
     * Table A — the closed resource set (§4.2). Eight resources, no ninth, and
     * `tags` is a full six-method resource with no exceptions (§15.8).
     *
     * `writings` is not special-cased: the derived `body_html` and
     * `reading_time_minutes` attributes and the read-only `series` attribute
     * object are the resource class's business, not the router's.
     *
     * Idempotency (§4.13, decision 4): there is no `Idempotency-Key` support in
     * v1 and no `409`-on-replay path. §4.13 claims a replayed `POST` collides on
     * the unique slug index and returns `409`; it does not, because the slug is
     * derived from the title and the deriver suffixes a taken slug with `-2`.
     * A replay therefore creates a second row and answers `201`. `409` is
     * reserved for a genuine unique-constraint violation outside the slug, and
     * for a `data.type` / `data.id` mismatch on `PUT`/`PATCH`.
     */
    $resources = [
        'work-experiences' => WorkExperienceController::class,
        'educations' => EducationController::class,
        'certifications' => CertificationController::class,
        'publications' => PublicationController::class,
        'projects' => ProjectController::class,
        'awards' => AwardController::class,
        'writings' => WritingController::class,
        'tags' => TagController::class,
    ];

    foreach ($resources as $segment => $controller) {
        Route::apiResource($segment, $controller)
            ->names("api.v1.{$segment}")
            ->middlewareFor(['index', 'show'], ['throttle:api-public-read', CheckResponseForModifications::class])
            ->middlewareFor(['store', 'update', 'destroy'], 'api-admin');

        Route::options($segment, $options($collectionAllow));

        /*
         * The single-resource placeholder must match the one `apiResource`
         * derives, or the URI appears twice in `route:list` with different
         * parameter names and the OpenAPI contract test reads that as drift.
         */
        $parameter = str_replace('-', '_', Str::singular($segment));

        Route::options("{$segment}/{{$parameter}}", $options($singleAllow));
    }

    /*
     * `/api/v1/search` is the API's only QUERY endpoint (§4.1). `Route::query()`
     * is the framework helper — `Route::match(['QUERY'], …)` is forbidden — and
     * it lives in the `api` group, where no CSRF middleware can reach it.
     *
     * `GET` is the documented fallback for clients that cannot send QUERY, and
     * returns the same document with `meta.query_method: "GET"`. There is no
     * `POST /api/v1/search`: an unsafe fallback would teach clients the wrong
     * verb. One invokable controller serves both methods.
     *
     * The `accept-query` middleware alias (a closure registered in
     * `bootstrap/app.php`) puts `Accept-Query: application/json` on the GET and
     * HEAD responses so a client can discover QUERY support — that is all §4.1
     * promises it on, and the OPTIONS closure below covers discovery proper.
     * The QUERY registration deliberately carries no such alias: a caller that
     * has just sent a QUERY has already discovered it, and `SearchController`
     * does not add the header to a QUERY response either, so the two paths
     * agree instead of one of them advertising what the other withholds.
     */
    Route::get('search', SearchController::class)
        ->middleware(['throttle:api-search', CheckResponseForModifications::class, 'accept-query'])
        ->name('api.v1.search');

    Route::query('search', SearchController::class)
        ->middleware(['throttle:api-search', CheckResponseForModifications::class])
        ->name('api.v1.search.query');

    Route::options('search', $searchOptions);
});
