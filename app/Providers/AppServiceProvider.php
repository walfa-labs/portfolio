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
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        RateLimiter::for(
            'media-download',
            fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()),
        );

        RateLimiter::for(
            'media-upload',
            fn (Request $request): Limit => Limit::perMinute(30)
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip()),
        );
    }
}
