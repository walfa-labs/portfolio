<?php

use App\Models\Tag;
use App\Models\Writing;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Google AdSense scope (§8.5)
|--------------------------------------------------------------------------
|
| Ads are allowed on `writing.show` only, and only with `ads_enabled`, no
| opt-out, and a stored `advertising: true`. The loader is never written into
| the Blade `<head>` — Inertia does not re-run `<head>`, so a head-level script
| would load Google on every route — and the only code path that can create an
| ad request lives in `WritingAdSlot.vue`, which is mounted only inside
| `WritingShow.vue` (§8.5 item 2).
|
| The server shares a boolean and the two ids, nothing more, so the HTML half of
| this file is a regression guard: it fails if a future edit ever puts the
| loader back into the shell.
|
*/

dataset('non-ad routes', [
    'the home page' => '/',
    'the writing list' => '/writing',
    'a tag page' => '/tags/ad-scope',
    'the privacy policy' => '/privacy',
]);

beforeEach(function () {
    config([
        'consent.analytics_enabled' => true,
        'consent.ads_enabled' => true,
        'consent.clarity_project_id' => 'adscopeid1',
        'consent.adsense_client' => 'ca-pub-0000000000000000',
        'consent.adsense_slot' => '0000000000',
    ]);

    $writing = Writing::factory()->published()->create(['title' => 'Ads Only On Article Pages']);

    $writing->tags()->attach(Tag::factory()->create(['name' => 'Ad Scope']));
});

/**
 * A consent record granting analytics and advertising, at the live version.
 */
function adScopeConsentCookie(): string
{
    return json_encode([
        'v' => (int) config('consent.policy_version', 1),
        'ts' => '2026-10-11T10:30:00Z',
        'analytics' => true,
        'advertising' => true,
        'decision' => 'accept_all',
    ]);
}

test('no non-ad route serves an Ads loader or a Google script', function (string $path) {
    $response = $this->withUnencryptedCookie('consent', adScopeConsentCookie())->get($path);

    $response->assertOk();

    expect($response->getContent())
        ->not->toContain('adsbygoogle')
        ->not->toContain('googlesyndication');
})->with('non-ad routes');

test('adsAllowed is true on the writing detail page and the ids reach the client', function () {
    $response = $this->withUnencryptedCookie('consent', adScopeConsentCookie())
        ->get('/writing/ads-only-on-article-pages');

    $response->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.adsAllowed', true)
        ->where('privacy.adsenseClient', 'ca-pub-0000000000000000')
        ->where('privacy.adsenseSlot', '0000000000')
        ->etc()
    );
});

test('adsAllowed is false on every route that is not the writing detail page', function (string $path) {
    $this->withUnencryptedCookie('consent', adScopeConsentCookie())->get($path)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('privacy.adsAllowed', false)
            ->etc()
        );
})->with('non-ad routes');

test('adsAllowed is false on the writing detail page without advertising consent', function () {
    $analyticsOnly = json_encode([
        'v' => (int) config('consent.policy_version', 1),
        'ts' => '2026-10-11T10:30:00Z',
        'analytics' => true,
        'advertising' => false,
        'decision' => 'custom',
    ]);

    $this->withUnencryptedCookie('consent', $analyticsOnly)
        ->get('/writing/ads-only-on-article-pages')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('privacy.adsAllowed', false)
            ->etc()
        );
});

test('a non-ad route keeps the analytics CSP variant even with advertising consent', function (string $path) {
    $csp = (string) $this->withUnencryptedCookie('consent', adScopeConsentCookie())
        ->get($path)
        ->headers
        ->get('Content-Security-Policy-Report-Only');

    expect($csp)
        ->toContain('clarity.ms')
        ->not->toContain('googlesyndication')
        ->not->toContain('doubleclick')
        ->not->toContain('pagead2')
        ->not->toContain('googleadservices');
})->with('non-ad routes');

test('advertising consent widens the writing detail page to the Google origins', function () {
    $csp = (string) $this->withUnencryptedCookie('consent', adScopeConsentCookie())
        ->get('/writing/ads-only-on-article-pages')
        ->headers
        ->get('Content-Security-Policy-Report-Only');

    expect($csp)
        ->toContain('https://pagead2.googlesyndication.com')
        ->toContain('https://*.doubleclick.net')
        ->toContain('clarity.ms');
});

test('the ad slot reserves its space and mounts only on the writing detail page', function () {
    $slot = File::get(base_path('resources/js/components/ads/WritingAdSlot.vue'));

    expect($slot)->toMatch('/min-h-\[[1-9]\d*px\]/')
        ->and($slot)->toMatch('/md:min-h-\[[1-9]\d*px\]/')
        ->and($slot)->not->toContain("setAttribute('data-ad-format'")
        ->and($slot)->not->toContain("setAttribute('data-full-width-responsive'");

    $pages = collect(File::allFiles(resource_path('js/pages')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'vue')
        ->filter(fn (SplFileInfo $file): bool => str_contains(File::get($file->getPathname()), 'WritingAdSlot'))
        ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getRelativePathname()))
        ->values()
        ->all();

    expect($pages)->toBe(['public/WritingShow.vue']);
});
