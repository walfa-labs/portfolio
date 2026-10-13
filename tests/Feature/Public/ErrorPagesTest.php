<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Error and maintenance pages (§3.12)
|--------------------------------------------------------------------------
|
| `AppServiceProvider::configureInertiaExceptionHandling()` renders
| `public/Error` for 403 / 404 / 500 / 503 through
| `Inertia::handleExceptionsUsing()`, preserving the HTTP status.
|
| `public/Error.vue` is the only page in the app that is *not* covered by an
| Inertia prop assertion: it is static markup driven by a single numeric prop.
| There is no SSR server running during the suite (`config('inertia.ssr.enabled')`
| is true but nothing listens on 127.0.0.1:13714), so the response body is the
| client-side shell and the rendered copy cannot be asserted from the response.
| The markup half of the contract is therefore asserted against the component
| source, which is the artefact that would lose a block.
|
| 403 and 500 are produced by routes registered inside the test itself (an
| explicit `abort(403)` / a throwing closure); 503 is produced by putting the
| application into real maintenance mode, because that is the path §3.12 flags
| as risky — it aborts inside the global middleware stack, before the `web`
| group starts the session.
|
*/

function errorPageComponentSource(): string
{
    return File::get(base_path('resources/js/pages/public/Error.vue'));
}

test('an unknown path answers a real 404 with the public error page', function () {
    $response = $this->get('/this-does-not-exist');

    $response->assertNotFound();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Error')
        ->where('status', 404)
        ->missing('jsonLd')
        ->missing('title')
    );
});

test('a 403 answers the public error page with the status preserved', function () {
    Route::get('/__test/forbidden', fn () => abort(403));

    $response = $this->get('/__test/forbidden');

    $response->assertForbidden();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Error')
        ->where('status', 403)
    );
});

test('a 500 answers the public error page with the status preserved', function () {
    Route::get('/__test/boom', fn () => throw new RuntimeException('boom'));

    $response = $this->get('/__test/boom');

    $response->assertStatus(500);
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/Error')
        ->where('status', 500)
    );
});

test('maintenance mode answers a real 503 with the public error page', function () {
    $this->app->maintenanceMode()->activate([]);

    try {
        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('public/Error')
            ->where('status', 503)
        );
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }

    expect($this->app->isDownForMaintenance())->toBeFalse();
});

test('the error page renders from the status prop alone', function () {
    $source = errorPageComponentSource();

    expect($source)->toContain('defineProps<{')
        ->and($source)->toContain('status: number');

    foreach (['usePage', 'useLayoutProps', 'useForm', 'useHttp', 'page.props', 'sharedProps'] as $sessionBacked) {
        expect($source)->not->toContain($sessionBacked);
    }
});

test('no error page loads an analytics or advertising script and none carries JSON-LD', function () {
    $source = errorPageComponentSource();

    foreach (['clarity', 'adsbygoogle', 'googlesyndication', 'doubleclick', 'ld+json', '<SeoHead'] as $thirdParty) {
        expect($source)->not->toContain($thirdParty);
    }
});

test('the error page is noindex and carries copy for all four statuses', function () {
    $source = errorPageComponentSource();

    expect($source)->toContain('noindex,nofollow')
        ->and($source)->toContain("You don't have access to this page")
        ->and($source)->toContain('Page not found')
        ->and($source)->toContain('Something went wrong')
        ->and($source)->toContain('Down for maintenance');
});
