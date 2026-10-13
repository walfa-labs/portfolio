<?php

use App\Http\Controllers\Admin\AwardController;
use App\Http\Controllers\Admin\CertificationController;
use App\Http\Controllers\Admin\EducationController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\PublicationController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\WorkExperienceController;
use App\Http\Controllers\Admin\WritingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin routes (§5.8)
|--------------------------------------------------------------------------
|
| Required from `routes/web.php`, so every route here also sits inside the `web`
| group assembled in `bootstrap/app.php`: the session cookie, CSRF protection,
| `HandleInertiaRequests` and the `PreventIndexing` header (§5.11).
|
| `auth` + `verified` + `admin` is the whole gate. `admin` is the
| `EnsureUserIsAdministrator` alias (§5.7) and answers 403 for anyone who is not
| the configured administrator; the per-resource policies are the second,
| per-record check, called from the controllers with `$this->authorize(...)`.
| There is deliberately no `Gate::before` super-gate, which would leave every
| policy method unreachable and therefore untested.
|
| URIs are plural-kebab and resource-shaped (`/admin/work-experiences`) while
| the public site stays editorial (`/experience`) — three spellings by design
| (§2.6). Route model binding parameters are the snake_case model names
| (`{work_experience}`) rather than `{id}`, so a missing record is a 404 and
| Wayfinder's `edit(record)` signature resolves the route key itself.
|
| `PATCH`, never `PUT`, for `update`: the forms send partial payloads and `PUT`
| is reserved for full-document replacement. `POST`/`PATCH`/`DELETE` here are
| Inertia visits, not API calls — the admin UI never touches `/api/v1` (§5.6).
|
*/

/**
 * The eight resource segments, in sidebar order (§5.8).
 *
 * `orderable` marks the six types with a manual `sort_order`. `writings` sorts
 * by `published_at` and `tags` by the ASCII-normalised key `LOWER(name), id`,
 * so neither gets a `reorder` route (§2.9 item 4, §5.8).
 *
 * @var array<string, array{controller: class-string, parameter: string, orderable: bool}>
 */
$resources = [
    'work-experiences' => ['controller' => WorkExperienceController::class, 'parameter' => 'work_experience', 'orderable' => true],
    'educations' => ['controller' => EducationController::class, 'parameter' => 'education', 'orderable' => true],
    'certifications' => ['controller' => CertificationController::class, 'parameter' => 'certification', 'orderable' => true],
    'publications' => ['controller' => PublicationController::class, 'parameter' => 'publication', 'orderable' => true],
    'projects' => ['controller' => ProjectController::class, 'parameter' => 'project', 'orderable' => true],
    'awards' => ['controller' => AwardController::class, 'parameter' => 'award', 'orderable' => true],
    'writings' => ['controller' => WritingController::class, 'parameter' => 'writing', 'orderable' => false],
    'tags' => ['controller' => TagController::class, 'parameter' => 'tag', 'orderable' => false],
];

Route::middleware(['auth', 'verified', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () use ($resources): void {
        /*
         * The dashboard keeps its `Route::inertia` binding: it is a static shell
         * with no server-side props, and §12 phase 1 created it this way.
         */
        Route::inertia('/', 'admin/Dashboard')->name('dashboard');

        /*
         * The contact inbox. Reading a submission is an explicit action —
         * opening the list must not mark anything as read, or an admin who
         * merely glanced at the inbox would destroy the unread signal (§5.8).
         */
        Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
        Route::patch('messages/{contact_message}/read', [MessageController::class, 'read'])->name('messages.read');

        foreach ($resources as $segment => $resource) {
            Route::prefix($segment)->name($segment.'.')->group(function () use ($resource): void {
                $controller = $resource['controller'];

                /*
                 * Built by concatenation rather than as `"{$parameter}"`: inside
                 * a double-quoted string that is PHP's complex-curly
                 * interpolation, so the braces are consumed and the segment
                 * comes out literal — the route would be
                 * `/admin/work-experiences/work_experience/edit`, not a binding.
                 */
                $binding = '{'.$resource['parameter'].'}';

                /*
                 * Collection routes first. `reorder`, `publish` and `unpublish`
                 * are single segments where the binding routes below expect a
                 * model parameter, and Laravel matches in registration order —
                 * registering the literals first is what keeps
                 * `PATCH /admin/tags/publish` from being read as an update of
                 * the tag whose id is "publish".
                 */
                Route::get('/', [$controller, 'index'])->name('index');
                Route::get('/create', [$controller, 'create'])->name('create');
                Route::post('/', [$controller, 'store'])->name('store');

                if ($resource['orderable']) {
                    Route::patch('/reorder', [$controller, 'reorder'])->name('reorder');
                }

                Route::patch('/publish', [$controller, 'bulkPublish'])->name('bulkPublish');
                Route::patch('/unpublish', [$controller, 'bulkUnpublish'])->name('bulkUnpublish');

                /*
                 * `password.confirm` on the destructive routes only (§5.5).
                 * Create and update stay unconfirmed so ordinary editing is not
                 * interrupted every fifteen minutes.
                 */
                Route::delete('/', [$controller, 'bulkDestroy'])
                    ->middleware('password.confirm')
                    ->name('bulkDestroy');

                Route::get("/{$binding}/edit", [$controller, 'edit'])->name('edit');
                Route::patch("/{$binding}", [$controller, 'update'])->name('update');
                Route::delete("/{$binding}", [$controller, 'destroy'])
                    ->middleware('password.confirm')
                    ->name('destroy');
                Route::patch("/{$binding}/publish", [$controller, 'publish'])->name('publish');
                Route::patch("/{$binding}/unpublish", [$controller, 'unpublish'])->name('unpublish');
            });
        }

        /*
         * The media surface (§9.10). Uploads are Inertia form posts or fetch
         * calls against `web` routes — never `/api/v1` — guarded by the
         * `media-upload` limiter keyed on the admin id. `destroy` carries
         * `password.confirm` with the other destructive admin routes (§5.5
         * names it explicitly; §9.10's silence is an omission, not a
         * prohibition).
         */
        Route::post('media', [MediaController::class, 'store'])
            ->middleware('throttle:media-upload')
            ->name('media.store');
        Route::post('media/{media}/regenerate', [MediaController::class, 'regenerate'])
            ->name('media.regenerate');
        Route::delete('media/{media}', [MediaController::class, 'destroy'])
            ->middleware('password.confirm')
            ->name('media.destroy');
    });
