<?php

use App\Models\User;
use App\Models\Writing;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Microsoft Clarity gating (§8.4)
|--------------------------------------------------------------------------
|
| Clarity loads site-wide — every public page, never `/admin` — but only when
| the whole conjunction passes: the kill switch, no opt-out signal, a stored
| `analytics: true`, and a route outside `admin*` (§8.4 item 2, decision 1).
|
| The loader is a server-rendered nonce'd inline script in `app.blade.php`
| (§8.4 item 1), so `clarity.ms` is present in the response body exactly when
| `analyticsAllowed` is true. That is what makes the decision assertable here
| rather than only in a browser.
|
*/

dataset('clarity routes', [
    'the home page' => '/',
    'the writing list' => '/writing',
    'a writing detail' => '/writing/analytics-gating-article',
    'the privacy policy' => '/privacy',
]);

beforeEach(function () {
    Writing::factory()->published()->create(['title' => 'Analytics Gating Article']);
});

/**
 * A consent record granting analytics only, keyed to the live policy version.
 */
function analyticsConsentCookie(): string
{
    return json_encode([
        'v' => (int) config('consent.policy_version', 1),
        'ts' => '2026-10-11T10:30:00Z',
        'analytics' => true,
        'advertising' => false,
        'decision' => 'custom',
    ]);
}

test('the kill switch off means no Clarity even when analytics is consented', function () {
    config([
        'consent.analytics_enabled' => false,
        'consent.clarity_project_id' => 'analyticz1',
    ]);

    $response = $this->withUnencryptedCookie('consent', analyticsConsentCookie())->get('/');

    $response->assertOk();

    expect($response->getContent())->not->toContain('clarity.ms');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.analyticsAllowed', false)
        ->etc()
    );
});

test('no consent means no Clarity even when the kill switch is on', function () {
    config([
        'consent.analytics_enabled' => true,
        'consent.clarity_project_id' => 'analyticz1',
    ]);

    $response = $this->get('/');

    $response->assertOk();

    expect($response->getContent())->not->toContain('clarity.ms');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.analyticsAllowed', false)
        ->where('privacy.consent', null)
        ->etc()
    );
});

test('the kill switch on plus analytics consent loads Clarity site-wide', function (string $path) {
    config([
        'consent.analytics_enabled' => true,
        'consent.clarity_project_id' => 'analyticz1',
    ]);

    $response = $this->withUnencryptedCookie('consent', analyticsConsentCookie())->get($path);

    $response->assertOk();

    expect($response->getContent())
        ->toContain('clarity.ms')
        ->toContain('analyticz1');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.analyticsAllowed', true)
        ->where('privacy.clarityProjectId', 'analyticz1')
        ->etc()
    );
})->with('clarity routes');

test('Clarity is never loaded on /admin, even with the kill switch on and analytics consented', function () {
    config([
        'consent.analytics_enabled' => true,
        'consent.clarity_project_id' => 'analyticz1',
    ]);

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->withUnencryptedCookie('consent', analyticsConsentCookie())
        ->get('/admin');

    $response->assertOk();

    expect($response->getContent())->not->toContain('clarity.ms');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.analyticsAllowed', false)
        ->etc()
    );
});
