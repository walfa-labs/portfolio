<?php

use App\Models\Writing;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Do Not Track and Global Privacy Control (§8.2)
|--------------------------------------------------------------------------
|
| The server reads `DNT` and `Sec-GPC` in `ResolvePrivacyState` and exposes the
| decision as the shared `privacy` prop. Neither signal ever *grants* consent,
| so an opt-out means: no Clarity, no Ads, no `consent` cookie, no banner, and
| the strict CSP permanently.
|
| `assertCookieMissing('consent')` on a request that never carried the cookie
| proves almost nothing, so the second test sends a *granting* cookie and shows
| it is neither honoured nor echoed back. That is the case that would catch a
| regression.
|
| The kill switches are turned on throughout, so `optOut` is the only reason
| anything is suppressed; a test with analytics disabled would pass for the
| wrong reason.
|
*/

dataset('opt-out signals', [
    'DNT: 1' => ['DNT', '1'],
    'Sec-GPC: 1' => ['Sec-GPC', '1'],
]);

dataset('dnt routes', [
    'the home page' => '/',
    'the writing list' => '/writing',
    'a writing detail' => '/writing/dnt-signal-article',
    'the privacy policy' => '/privacy',
]);

beforeEach(function () {
    config([
        'consent.analytics_enabled' => true,
        'consent.ads_enabled' => true,
        'consent.clarity_project_id' => 'dn7signalz',
        'consent.adsense_client' => 'ca-pub-0000000000000000',
        'consent.adsense_slot' => '0000000000',
    ]);

    Writing::factory()->published()->create(['title' => 'DNT Signal Article']);
});

/**
 * A consent record granting both categories, keyed to the live policy version.
 */
function dntGrantingConsent(): string
{
    return json_encode([
        'v' => (int) config('consent.policy_version', 1),
        'ts' => '2026-10-11T10:30:00Z',
        'analytics' => true,
        'advertising' => true,
        'decision' => 'accept_all',
    ]);
}

/**
 * Assert that no third-party loader was injected into a rendered page.
 *
 * The bare host is not a leak signature: `/privacy` has to name the hosts it
 * describes, so `clarity.ms` appears in its own prose and `data-clarity-mask`
 * on the elements it marks. These are the signatures of the *loader* instead —
 * its tag URL, the marker attribute it sets on the script it injects, and any
 * script element pointing at the vendor.
 */
function expectNoTrackingLoaders(string $html): void
{
    expect($html)
        ->not->toContain('clarity.ms/tag/')
        ->not->toContain("setAttribute('data-clarity'")
        ->not->toMatch('/<script[^>]*clarity\.ms/i')
        ->not->toContain('adsbygoogle');
}

test('an opt-out signal suppresses Clarity, Ads and the consent cookie on every public route', function (string $header, string $value, string $path) {
    $response = $this->withHeader($header, $value)->get($path);

    $response->assertOk();

    expectNoTrackingLoaders($response->getContent());

    /*
     * The named assertion checks exactly the raw `Set-Cookie` set: no cookie
     * named `consent` is present. On its own it is nearly vacuous — the request
     * never carried one — which is why the next test sends a granting cookie.
     */
    $response->assertCookieMissing('consent');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.optOut', true)
        ->where('privacy.analyticsAllowed', false)
        ->where('privacy.adsAllowed', false)
        ->where('privacy.consent', null)
        ->etc()
    );
})->with('opt-out signals')->with('dnt routes');

test('a granting consent cookie is not honoured under an opt-out signal', function (string $header, string $value) {
    $response = $this->withUnencryptedCookie('consent', dntGrantingConsent())
        ->withHeader($header, $value)
        ->get('/writing/dnt-signal-article');

    $response->assertOk();

    expectNoTrackingLoaders($response->getContent());

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.optOut', true)
        ->where('privacy.analyticsAllowed', false)
        ->where('privacy.adsAllowed', false)
        ->where('privacy.consent', null)
        ->etc()
    );

    $cookie = $response->getCookie('consent', false);

    if ($cookie !== null) {
        $decoded = json_decode((string) $cookie->getValue(), true);

        expect(is_array($decoded) ? ($decoded['analytics'] ?? false) : false)->toBeFalse();
    }
})->with('opt-out signals');

test('without an opt-out signal the banner is due and nothing non-essential loads', function () {
    $response = $this->get('/');

    $response->assertOk();

    expectNoTrackingLoaders($response->getContent());

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.optOut', false)
        ->where('privacy.consent', null)
        ->where('privacy.analyticsAllowed', false)
        ->where('privacy.adsAllowed', false)
        ->etc()
    );
});

/*
 * The value matrix belongs to `tests/Unit/Privacy/DoNotTrackTest.php`; this one
 * case proves the parsed value actually reaches the shared prop.
 */
test('a legacy opt-out header reaches the shared privacy prop', function () {
    $response = $this->withHeader('DNT', 'On')->get('/');

    $response->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.optOut', true)
        ->etc()
    );
});
