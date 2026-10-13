<?php

use App\Http\Controllers\Public\AwardController;
use App\Http\Controllers\Public\CertificationController;
use App\Http\Controllers\Public\ContactController;
use App\Http\Controllers\Public\CvController;
use App\Http\Controllers\Public\CvDownloadController;
use App\Http\Controllers\Public\EducationController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\MediaController;
use App\Http\Controllers\Public\PrivacyController;
use App\Http\Controllers\Public\ProjectController;
use App\Http\Controllers\Public\PublicationController;
use App\Http\Controllers\Public\RobotsController;
use App\Http\Controllers\Public\SearchController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\TagController;
use App\Http\Controllers\Public\WorkExperienceController;
use App\Http\Controllers\Public\WritingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public routes (§3.2)
|--------------------------------------------------------------------------
|
| Everything here is in the `web` group assembled in `bootstrap/app.php`. No
| public *page* route carries `auth`: the only public write is the contact
| submission, and `/cv/download` is a public attachment.
|
| Only published content is public, so an unknown or unpublished slug answers
| 404 rather than 403 (§3.1 item 4) — that binding lives on the models.
|
| Five public responses are deliberately not Inertia pages: `/sitemap.xml`,
| `/robots.txt`, `/writing/feed.atom`, `/cv/download` and (from phase 8)
| `/media/{media}/{variant}`.
|
*/

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::get('/experience', [WorkExperienceController::class, 'index'])->name('experience.index');
Route::get('/education', [EducationController::class, 'index'])->name('education.index');
Route::get('/certifications', [CertificationController::class, 'index'])->name('certifications.index');
Route::get('/publications', [PublicationController::class, 'index'])->name('publications.index');
Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::get('/awards', [AwardController::class, 'index'])->name('awards.index');
Route::get('/writing', [WritingController::class, 'index'])->name('writing.index');

/*
 * `/writing/feed.atom` and `/writing/{writing:slug}` are both two-segment paths,
 * so they genuinely collide: Laravel matches routes in registration order and
 * `Route::feeds()` must come first, or the literal feed URL is swallowed by the
 * binding (§3.2).
 *
 * `Route::feeds()` names the route `feeds.writings`, not `feed.writings` — the
 * package derives it from the `feed.feeds` config key, and its own controller
 * resolves the feed by stripping the `feeds.` prefix back off the route name.
 * Renaming it breaks the endpoint.
 */
Route::feeds();

Route::get('/writing/{writing:slug}', [WritingController::class, 'show'])->name('writing.show');

Route::get('/cv', CvController::class)->name('cv');
Route::get('/cv/download', CvDownloadController::class)
    ->middleware('throttle:media-download')
    ->name('cv.download');

/*
 * The app-mediated media byte route (§9.7): every public object is streamed
 * here, never linked directly. `{media}` binds the ULID row and `{variant}`
 * is the full variant key including its extension, e.g. `cover_800x450.webp`.
 * The row's own disk is used, so the `s3-private` CV stays unreachable — it
 * has no variants to resolve.
 */
Route::get('/media/{media}/{variant}', [MediaController::class, 'show'])
    ->middleware('throttle:media-download')
    ->name('media.show');

Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
Route::get('/tags/{tag:slug}', [TagController::class, 'show'])->name('tags.show');

Route::get('/search', SearchController::class)->name('search');

/*
 * `GET /contact` renders through the controller, not `Route::inertia` (§3.2).
 *
 * The §3.2 row declares `Route::inertia('/contact', 'public/Contact')` with no
 * props, but §3.9 requires a non-empty title and description on every route and
 * `SeoHead.vue` has no fallback — so the page *must* be handed props, and those
 * props are built from the database and from `route('contact.show')`.
 *
 * `Route::inertia` evaluates its `$props` argument while the route table is
 * still being registered: before `contact.show` exists in the name lookup table
 * and, on a fresh install, before the content tables do. That throws during
 * boot for every request and every `php artisan` command. Resolving the props
 * per request is the only correct wiring, so the action is a closure rather
 * than a route default.
 */
Route::get('/contact', fn () => Inertia::render('public/Contact', ContactController::pageProps()))
    ->name('contact.show');

Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact')
    ->name('contact.store');

/*
 * `/privacy` deliberately carries no `cache.headers` (§3.2 declared
 * `public;max_age=3600;etag`). Its response is per-visitor: it embeds the
 * `privacy` prop — the opt-out state and the stored consent record surfaced at
 * `#choices` — and a per-visitor CSP header, so a shared cache could serve one
 * visitor's state to another. It also embeds content-hashed asset URLs, and the
 * hour-long `max-age` meant a visitor who loaded it just before a deploy kept a
 * document whose chunks no longer exist (measured as three 404s on `/privacy`).
 *
 * Dropping the middleware rather than narrowing it to `private` leaves the
 * framework default — `Cache-Control: no-cache, private` — which forbids shared
 * storage *and* forces revalidation. `private;max_age=3600` would have fixed
 * only the shared-cache leak and kept the stale-document one for the same hour.
 */
Route::get('/privacy', PrivacyController::class)->name('privacy');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');

require __DIR__.'/settings.php';
