<?php

use App\Exceptions\JsonApiException;
use App\Http\Middleware\EnsureUserIsAdministrator;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventIndexing;
use App\Http\Middleware\ResolvePrivacyState;
use App\Http\Middleware\SecurityHeaders;
use App\Support\JsonApi\JsonApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidIncludeQuery;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Advertise QUERY support on `/api/v1/search`'s GET, HEAD and OPTIONS
 * responses (§4.1, decision 16).
 *
 * Registered as a middleware *alias* rather than handed to `Route::middleware()`
 * directly. A closure resolves fine at runtime either way —
 * `MiddlewareNameResolver::resolve()` returns it and `SortedMiddleware` skips
 * non-strings — but a closure in a route's own middleware array cannot be
 * `var_export`ed by `route:cache`, whereas an alias lives on the router and
 * never reaches the route table.
 */
$advertiseQueryMethod = static function (Request $request, Closure $next): Response {
    /** @var Response $response */
    $response = $next($request);

    $response->headers->set('Accept-Query', JsonApiError::JSON_MEDIA_TYPE);

    return $response;
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) use ($advertiseQueryMethod): void {
        /*
         * `consent` joins `appearance` and `sidebar_state` as a plain,
         * client-writable cookie (§8.1, §8.11). It is a compliance record the
         * browser writes and the server only reads, so signing it would buy
         * nothing — and the Clarity partial re-reads it client-side, where an
         * encrypted value would be unreadable.
         */
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'consent']);

        /*
         * Global, so `/api/v1`, `/up` and error responses carry the security
         * headers too (§8.6). It calls `Vite::useCspNonce()` before `$next` and
         * sets the headers after, which is what lets it read the privacy state
         * the `web` group resolves downstream of it.
         */
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'admin' => EnsureUserIsAdministrator::class,
            'accept-query' => $advertiseQueryMethod,
        ]);

        /*
         * `PreventIndexing` is last so it sits innermost, right on the route's
         * own response: the header is then applied to every `/admin` and
         * `/settings` reply regardless of which middleware built it (§5.11).
         *
         * `ResolvePrivacyState` sits immediately before `HandleInertiaRequests`
         * (§8.2, §15.28): it must have shared the `privacy` prop before the
         * Inertia shared set is assembled, and it must run before any route so
         * the Blade shell can read `$privacy` from `View::share`.
         */
        $middleware->web(append: [
            HandleAppearance::class,
            ResolvePrivacyState::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            PreventIndexing::class,
        ]);

        /*
         * The session-authenticated write group (§4.11).
         *
         * The API writes with the `web` guard — Fortify sessions, same origin as
         * the Inertia admin UI — so this group is the `web` group minus
         * everything that does not belong on an API response: no
         * `HandleInertiaRequests`, no `HandleAppearance`, no
         * `AddLinkHeadersForPreloadedAssets`, no `ShareErrorsFromSession`.
         *
         * `throttle:api-admin-write` is declared last, after `auth` and
         * `verified` (decision 20): the limiter keys on the authenticated user
         * id, and before `auth` that id would always be null so every writer
         * would share one bucket.
         *
         * The resolved pipeline is
         *
         *     cookies → session → auth → throttle → bindings → CSRF → verified
         *
         * — Laravel's middleware priority map sorts `Authenticate` ahead of
         * `ThrottleRequests` and `SubstituteBindings` behind both, which is what
         * decision 20 actually needs, while `PreventRequestForgery` and
         * `EnsureEmailIsVerified` are not in that map and stay where they were
         * declared. CSRF landing *after* `auth` is the useful part: an
         * unauthenticated write is a real `401`, not a `419` (§4.5).
         */
        $middleware->group('api-admin', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            PreventRequestForgery::class,
            'auth',
            'verified',
            'throttle:api-admin-write',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
        |----------------------------------------------------------------------
        | The JSON:API error envelope (§4.6)
        |----------------------------------------------------------------------
        |
        | Every renderer is scoped to `api/*` and returns `null` otherwise, so
        | Laravel keeps rendering the web surface exactly as it did: Inertia
        | error pages, validation redirects, the login redirect for a guest.
        |
        | One `JsonApiError` value object renders every case, so the shape
        | cannot drift between statuses. Callbacks run in registration order and
        | the first non-null wins, so the specific renderers must come first.
        |
        */

        $exceptions->render(fn (ValidationException $e, Request $request) => $request->is('api/*')
            ? JsonApiError::fromValidationException($e)
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? JsonApiError::unauthenticated()->response()
            : null);

        /*
         * A policy denial and a form request's `authorize()` both surface here:
         * `prepareException()` maps `AuthorizationException` to
         * `AccessDeniedHttpException` before any callback runs, so a denial is a
         * real 403 and an absent session a real 401 — the two stay meaningful
         * (§4.11).
         */
        $exceptions->render(fn (AccessDeniedHttpException $e, Request $request) => $request->is('api/*')
            ? JsonApiError::forbidden($e->getMessage() ?: 'This action is unauthorized.')->response()
            : null);

        /*
         * `prepareException()` rewrites `ModelNotFoundException` into
         * `NotFoundHttpException`, so the model-aware 404 has to unwrap the
         * previous exception to name the resource type the way §4.2 derives it.
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previous = $e->getPrevious();

            return $previous instanceof ModelNotFoundException
                ? JsonApiError::modelNotFound($previous->getModel(), $previous->getIds())->response()
                : JsonApiError::notFound('The endpoint /'.$request->path().' does not exist.')->response();
        });

        /*
         * §4.6 omits the 405 body; `JsonApiError::methodNotAllowed()` authors it
         * and normalises the framework's `Allow` set into the pinned order, so a
         * collection reads `GET, HEAD, OPTIONS, POST` and a single resource
         * `GET, HEAD, OPTIONS, PUT, PATCH, DELETE` (decision 5).
         */
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $allow = $e->getHeaders()['Allow'] ?? '';
            $allow = is_array($allow) ? implode(', ', $allow) : (string) $allow;

            return JsonApiError::methodNotAllowed($request->method(), '/'.$request->path(), $allow)->response();
        });

        /*
         * Spatie rejects an undeclared filter, sort or include with a 400
         * `HttpException` carrying the allowed names in its message; the 400
         * body adds `source.parameter` naming the offending query parameter
         * (§4.8).
         */
        $exceptions->render(function (InvalidQuery $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $parameter = match (true) {
                $e instanceof InvalidFilterQuery => 'filter['.((string) $e->unknownFilters->first()).']',
                $e instanceof InvalidSortQuery => 'sort',
                $e instanceof InvalidIncludeQuery => 'include',
                default => null,
            };

            return JsonApiError::invalidQueryParameter($e->getMessage(), $parameter)->response();
        });

        /*
         * A unique-constraint violation is the §4.6 `409` (§4.13). The derived
         * slug cannot reach it — the deriver suffixes a taken slug, so a
         * replayed `POST` answers `201` — and `sync()` de-duplicates a tag
         * pivot, so no reachable v1 path provokes one today; the renderer is
         * here because §4.6 and the OpenAPI document both promise the `409`
         * and without it a violation would surface as a `500`.
         *
         * `UniqueConstraintViolationException` extends `QueryException`, so the
         * specific renderer is registered first and wins; the general one is
         * the backstop for a connection that reports the violation only as
         * SQLSTATE `23000` (integrity constraint) or `23505` (Postgres unique).
         * Any other database error is left to the renderers below.
         */
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return JsonApiError::conflict('The request conflicts with a unique constraint on the resource.')->response();
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*') || ! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                return null;
            }

            return JsonApiError::conflict('The request conflicts with a unique constraint on the resource.')->response();
        });

        $exceptions->render(fn (JsonApiException $e, Request $request) => $request->is('api/*')
            ? $e->error->response($e->getHeaders())
            : null);

        /*
         * A limiter that answers through `Limit::response()` never throws, so
         * this is the backstop for one that does not; either way the body is the
         * envelope and the `X-RateLimit-*` / `Retry-After` headers survive
         * (§4.10).
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $headers = $e->getHeaders();
            $retryAfter = $headers['Retry-After'] ?? null;

            return JsonApiError::rateLimited(is_numeric($retryAfter) ? (int) $retryAfter : null)->response($headers);
        });

        /*
         * Anything else the framework raises as an HTTP exception — `abort(409)`,
         * `abort(406)`, `abort(401)` — mapped to the §4.6 table by status.
         * Statuses the table does not list (419, 503, …) keep Laravel's own
         * rendering.
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return JsonApiError::forStatus($e->getStatusCode(), $e->getMessage() ?: null)?->response($e->getHeaders());
        });

        /*
         * Genuine server errors, and only in production: with `APP_DEBUG` on,
         * Laravel's own rendering shows the real exception, which is what makes
         * a failing test diagnosable. `HttpResponseException` is excluded
         * because it carries a response the framework has already built — the
         * 429 from `Limit::response()` among them.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface || $e instanceof HttpResponseException || config('app.debug')) {
                return null;
            }

            return $request->is('api/*') ? JsonApiError::serverError()->response() : null;
        });
    })->create();
