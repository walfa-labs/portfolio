<?php

use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Consent record versioning (§8.3)
|--------------------------------------------------------------------------
|
| The `consent` cookie carries `v`, the policy version it was written against.
| `ResolvePrivacyState` only accepts a record whose `v` equals
| `config('consent.policy_version')`, so bumping the policy invalidates every
| stored decision: the record is treated as **absent** — which is what makes the
| banner show again — and the stale cookie is deleted rather than left for the
| client to read and honour (decision 20).
|
| The cookie is client-written and therefore untrusted, so the parser also has
| to fail closed on a malformed record and on a truthy-but-not-boolean flag.
|
| The banner is a client component and nothing renders it without SSR, so
| "re-shown" is asserted through the server state that drives it: `optOut` false
| with `consent` null.
|
*/

/**
 * A `consent` cookie value at the given policy version.
 */
function consentVersionRecord(int $version, bool $analytics = true, bool $advertising = false): string
{
    return json_encode([
        'v' => $version,
        'ts' => '2026-10-11T10:30:00Z',
        'analytics' => $analytics,
        'advertising' => $advertising,
        'decision' => 'custom',
    ]);
}

/**
 * The raw `consent` cookie the response sets, if any.
 */
function consentVersionResponseCookie(TestResponse $response): ?string
{
    $cookie = $response->getCookie('consent', false);

    return $cookie === null ? null : (string) $cookie->getValue();
}

test('a record at the configured version is read', function () {
    config(['consent.policy_version' => 1]);

    $this->withUnencryptedCookie('consent', consentVersionRecord(1))
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('privacy.consent.v', 1)
            ->where('privacy.consent.analytics', true)
            ->where('privacy.consent.advertising', false)
            ->where('privacy.consent.decision', 'custom')
            ->etc()
        );
});

test('a record below the configured version is treated as absent and the banner is due again', function () {
    config(['consent.policy_version' => 2]);

    $this->withUnencryptedCookie('consent', consentVersionRecord(1))
        ->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('privacy.consent', null)
            ->where('privacy.optOut', false)
            ->where('privacy.analyticsAllowed', false)
            ->where('privacy.adsAllowed', false)
            ->etc()
        );
});

test('a stale granting record is deleted and loads nothing', function () {
    config([
        'consent.policy_version' => 2,
        'consent.analytics_enabled' => true,
        'consent.ads_enabled' => true,
        'consent.clarity_project_id' => 'stalevers1',
    ]);

    $response = $this->withUnencryptedCookie('consent', consentVersionRecord(1, analytics: true, advertising: true))
        ->get('/');

    $response->assertOk();

    expect($response->getContent())->not->toContain('clarity.ms');

    $deleted = consentVersionResponseCookie($response);

    expect($deleted)->not->toBeNull()
        ->and($deleted)->not->toContain('"analytics":true');
});

test('a malformed consent cookie is treated as absent and deleted', function () {
    config(['consent.policy_version' => 1]);

    $response = $this->withUnencryptedCookie('consent', 'not-json-at-all')->get('/');

    $response->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.consent', null)
        ->where('privacy.analyticsAllowed', false)
        ->etc()
    );

    expect(consentVersionResponseCookie($response))->not->toContain('not-json-at-all');
});

test('a truthy string flag does not grant consent', function () {
    config(['consent.policy_version' => 1]);

    $tampered = json_encode([
        'v' => 1,
        'ts' => '2026-10-11T10:30:00Z',
        'analytics' => 'true',
        'advertising' => 'true',
        'decision' => 'accept_all',
    ]);

    $response = $this->withUnencryptedCookie('consent', $tampered)->get('/');

    $response->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.consent', null)
        ->where('privacy.analyticsAllowed', false)
        ->where('privacy.adsAllowed', false)
        ->etc()
    );
});
