<?php

use App\Models\Tag;
use App\Models\User;
use App\Models\Writing;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| The kill switches (§8.9 item 4)
|--------------------------------------------------------------------------
|
| `ANALYTICS_ENABLED` and `ADS_ENABLED` default to `false` and outrank a stored
| consent: with either off the matching processor never loads, whatever a
| visitor accepted. The consent cookie is sent in every case here, so a passing
| assertion cannot be a request that simply had no consent.
|
| Both switches are environment-driven (§8.9), which is why the last test walks
| the value from the environment into `config/consent.php`.
|
*/

dataset('kill switch routes', [
    'the home page' => '/',
    'the writing list' => '/writing',
    'a writing detail' => '/writing/kill-switch-article',
    'a tag page' => '/tags/kill-switch',
    'the privacy policy' => '/privacy',
]);

beforeEach(function () {
    $writing = Writing::factory()->published()->create(['title' => 'Kill Switch Article']);

    $writing->tags()->attach(Tag::factory()->create(['name' => 'Kill Switch']));
});

/*
 * `killSwitchEnvironment()` writes to `$_ENV`/`$_SERVER`/`putenv()`, which
 * outlive the test's application instance. Leaving them set would let a later
 * test in the same process boot with the switches flipped, so they are cleared
 * once this file is done with them.
 */
afterEach(function () {
    foreach (['ANALYTICS_ENABLED', 'ADS_ENABLED', 'CLARITY_PROJECT_ID'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

/**
 * A consent record granting both categories, keyed to the live policy version.
 */
function killSwitchConsentCookie(): string
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
 * Set a phase-7 environment switch and reload configuration.
 *
 * Configuration is read once at boot, so a runtime `config()` write would prove
 * nothing about §8.9's requirement that these values come from the environment
 * and work without a rebuild. Writing them into `$_ENV`/`$_SERVER` and
 * re-running `LoadConfiguration` exercises the real mapping.
 *
 * `afterEach` clears the keys again: superglobals outlive the application
 * instance, so leaving them set would leak the flipped switches into a later
 * test in the same process.
 *
 * @param  array<string, string>  $values
 */
function killSwitchEnvironment(array $values): void
{
    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    app()->bootstrapWith([LoadConfiguration::class]);
}

test('the analytics kill switch suppresses Clarity on every route', function (string $path) {
    config([
        'consent.analytics_enabled' => false,
        'consent.clarity_project_id' => 'killswitch',
    ]);

    $response = $this->withUnencryptedCookie('consent', killSwitchConsentCookie())->get($path);

    $response->assertOk();

    /*
     * The loader's signatures, not the bare host: `/privacy` has to name
     * `clarity.ms` in its own prose, so the substring alone fails on a page
     * that correctly loads nothing. The same correction lives in
     * `tests/Feature/Privacy/DntSignalTest.php`.
     */
    expect($response->getContent())
        ->not->toContain('clarity.ms/tag/')
        ->not->toContain("setAttribute('data-clarity'")
        ->not->toMatch('/<script[^>]*clarity\.ms/i');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.analyticsAllowed', false)
        ->etc()
    );
})->with('kill switch routes');

test('the ads kill switch clears adsAllowed on every route', function (string $path) {
    config([
        'consent.ads_enabled' => false,
        'consent.adsense_client' => 'ca-pub-0000000000000000',
        'consent.adsense_slot' => '0000000000',
    ]);

    $this->withUnencryptedCookie('consent', killSwitchConsentCookie())->get($path)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('privacy.adsAllowed', false)
            ->etc()
        );
})->with('kill switch routes');

test('both kill switches also hold on /admin', function () {
    config([
        'consent.analytics_enabled' => false,
        'consent.ads_enabled' => false,
        'consent.clarity_project_id' => 'killswitch',
        'consent.adsense_client' => 'ca-pub-0000000000000000',
        'consent.adsense_slot' => '0000000000',
    ]);

    $response = $this->actingAs(User::factory()->administrator()->create())
        ->withUnencryptedCookie('consent', killSwitchConsentCookie())
        ->get('/admin');

    $response->assertOk();

    expect($response->getContent())->not->toContain('clarity.ms');

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('privacy.analyticsAllowed', false)
        ->where('privacy.adsAllowed', false)
        ->etc()
    );
});

test('both kill switches are read from the environment', function () {
    killSwitchEnvironment([
        'ANALYTICS_ENABLED' => 'true',
        'ADS_ENABLED' => 'true',
        'CLARITY_PROJECT_ID' => 'envdriven1',
    ]);

    expect(config('consent.analytics_enabled'))->toBeTrue()
        ->and(config('consent.ads_enabled'))->toBeTrue()
        ->and(config('consent.clarity_project_id'))->toBe('envdriven1');
});
