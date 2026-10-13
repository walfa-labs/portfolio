<?php

namespace App\Providers;

use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\Tag;
use App\Models\User;
use App\Models\WorkExperience;
use App\Models\Writing;
use App\Observers\SitemapCacheObserver;
use App\Support\JsonApi\JsonApiError;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiters();
        $this->configurePagination();
        $this->configureInertiaExceptionHandling();
        $this->configureSitemapCacheInvalidation();

        Relation::morphMap([
            'work_experience' => WorkExperience::class,
            'education' => Education::class,
            'certification' => Certification::class,
            'publication' => Publication::class,
            'project' => Project::class,
            'award' => Award::class,
            'writing' => Writing::class,
            'user' => User::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register the named rate limiters the routes reference (§4.10).
     *
     * Laravel 13's `api` middleware group applies no throttle of its own, so
     * every tier is attached explicitly with `throttle:<name>`.
     *
     * `contact` guards `POST /contact` — the site's only public write — at
     * 3/hour and 10/day, and `media-download` guards the attachment and media
     * byte routes at 30/min. Both key on `Request::ip()`, which is the
     * proxy-aware client address: it honours `X-Forwarded-For` only through
     * Laravel's trusted-proxy configuration, so behind a proxy the
     * trusted-proxy list must be set or every client shares one bucket (§3.2).
     *
     * The three `api-*` limiters answer through `Limit::response()` so a `429`
     * carries the JSON:API envelope rather than Laravel's `{"message":…}`; the
     * limiter's `X-RateLimit-*` and `Retry-After` headers ride along (§4.6,
     * §4.10). `api-admin-write` keys on the authenticated user id — the
     * `api-admin` group puts `auth` before `throttle` for exactly that reason
     * (decision 20) — with the client address as a belt-and-braces fallback.
     *
     * `media-upload` deliberately gets no envelope: it guards the admin
     * *browser* surface (`POST /admin/media`), not `/api/*`.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('contact', fn (Request $request): array => [
            Limit::perHour(3)->by($request->ip()),
            Limit::perDay(10)->by($request->ip()),
        ]);

        RateLimiter::for(
            'media-download',
            fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()),
        );

        RateLimiter::for(
            'api-public-read',
            fn (Request $request): Limit => Limit::perMinute(120)
                ->by($request->ip())
                ->response($this->jsonApiRateLimited()),
        );

        RateLimiter::for(
            'api-search',
            fn (Request $request): Limit => Limit::perMinute(30)
                ->by($request->ip())
                ->response($this->jsonApiRateLimited()),
        );

        RateLimiter::for(
            'api-admin-write',
            fn (Request $request): Limit => Limit::perMinute(60)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip())
                ->response($this->jsonApiRateLimited()),
        );

        RateLimiter::for(
            'media-upload',
            fn (Request $request): Limit => Limit::perMinute(30)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip()),
        );
    }

    /**
     * Render the public error page for the four statuses that have one (§3.12).
     *
     * The HTTP status is preserved, so `/this-does-not-exist` still answers a
     * real 404 and maintenance mode a real 503. Every other status keeps
     * Laravel's own rendering.
     *
     * §3.12 gives the public site an error page; §4.6 gives `/api/*` a JSON:API
     * document with `Content-Type: application/vnd.api+json`, and the two
     * conflict on exactly these four statuses. §4.6 wins for the API: the
     * callback below is the exception handler's *finalize* step
     * (`Handler::finalizeRenderedResponse()`), so it runs after the renderers
     * in `bootstrap/app.php` have built their document and would otherwise
     * replace it with the Inertia page. Returning `null` for an `api/*` request
     * leaves the rendered response untouched, exactly as every renderer there
     * does for a request it does not own.
     */
    protected function configureInertiaExceptionHandling(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): ?ExceptionResponse {
            if ($response->request->is('api/*')) {
                return null;
            }

            if (in_array($response->statusCode(), [403, 404, 500, 503], true)) {
                return $response->render('public/Error', ['status' => $response->statusCode()])->withSharedData();
            }

            return null;
        });
    }

    /**
     * Flush the cached sitemap when any content row that reaches it is saved.
     *
     * The document is cached for an hour (§3.9), so the flush is the only thing
     * that keeps a freshly published writing — or a tag whose last item was
     * unpublished — from being missing until the entry expires. Every content
     * model plus `Tag` is observed; the observer decides whether the write
     * actually matters.
     */
    protected function configureSitemapCacheInvalidation(): void
    {
        foreach ([
            WorkExperience::class,
            Education::class,
            Certification::class,
            Publication::class,
            Project::class,
            Award::class,
            Writing::class,
            Tag::class,
        ] as $model) {
            $model::observe(SitemapCacheObserver::class);
        }
    }

    /**
     * The limiter response that makes a `429` a JSON:API document.
     *
     * `Limit::response()` is handed the limiter's own headers, which is where
     * `Retry-After` and the `X-RateLimit-*` triple come from; the error body
     * quotes `Retry-After` in its `detail` the way §4.6's example does.
     *
     * @return Closure(Request, array<string, mixed>): JsonResponse
     */
    protected function jsonApiRateLimited(): Closure
    {
        return static function (Request $request, array $headers): JsonResponse {
            $retryAfter = $headers['Retry-After'] ?? null;

            return JsonApiError::rateLimited(is_numeric($retryAfter) ? (int) $retryAfter : null)->response($headers);
        };
    }

    /**
     * Teach the paginator to read JSON:API's `page[number]` (§4.7).
     *
     * §4.7 pins this resolver so `page[number]` drives every API collection.
     * The resolver is process-global, though, so the pinned one-liner —
     * `max(1, (int) request()->input('page.number', 1))` — would silently break
     * the public pages, which paginate with `?page=N` (`/search?q=…&page=2`):
     * when `page` is a scalar, `input('page.number')` finds nothing and every
     * public page would render page 1.
     *
     * So `page[number]` wins when it is present and a valid page, and the
     * caller's own `$pageName` (`'page'`) is honoured otherwise. The API's
     * allow-list rejects `?page=N` on `/api/v1/*` with a 400, so the fallback is
     * reachable only from the web routes.
     */
    protected function configurePagination(): void
    {
        Paginator::currentPageResolver(function (string $pageName = 'page'): int {
            $number = request()->input('page.number');

            if (is_numeric($number) && (int) $number >= 1) {
                return (int) $number;
            }

            $page = request()->input($pageName);

            return is_numeric($page) && (int) $page >= 1 ? (int) $page : 1;
        });
    }
}
