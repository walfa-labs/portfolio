<?php

use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Security headers (§8.6)
|--------------------------------------------------------------------------
|
| `SecurityHeaders` is registered globally, so it must cover the web surface,
| `/api/v1`, `/up` and error responses alike. Nothing else in the suite owns
| the header set, so this file pins it.
|
| The CSP ships as `Content-Security-Policy-Report-Only` with no report sink
| (a sink is deferred), so there must be no enforcing `Content-Security-Policy`
| header and no `report-uri`/`Report-To` directive — pointing a browser at a
| non-existent endpoint would only produce 404s and console noise.
|
| `SecurityHeaders` runs globally, i.e. *outside* the `web` group, so it reads
| the `PrivacyState` that `ResolvePrivacyState` left on the request after
| calling `$next()`. The variant therefore has to be asserted from the
| response, which is what every test here does.
|
*/

/**
 * Parse a CSP header into directive => value.
 *
 * @return array<string, string>
 */
function cspDirectives(string $header): array
{
    $directives = [];

    foreach (array_filter(array_map('trim', explode(';', $header))) as $directive) {
        [$name, $value] = array_pad(explode(' ', $directive, 2), 2, '');
        $directives[$name] = trim($value);
    }

    return $directives;
}

/**
 * The report-only CSP header of a response.
 */
function cspHeader(TestResponse $response): string
{
    return (string) $response->headers->get('Content-Security-Policy-Report-Only');
}

/**
 * A consent record granting both categories, keyed to the live policy version.
 */
function cspConsentCookie(): string
{
    return json_encode([
        'v' => (int) config('consent.policy_version', 1),
        'ts' => '2026-10-11T10:30:00Z',
        'analytics' => true,
        'advertising' => true,
        'decision' => 'accept_all',
    ]);
}

dataset('security header routes', [
    'the home page' => '/',
    'the API' => '/api/v1/writings',
    'the health check' => '/up',
    'a 404' => '/this-route-does-not-exist',
]);

test('every §8.6 header is present on a web route, the API, /up and a 404', function (string $path) {
    $response = $this->get($path);

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

    $permissions = (string) $response->headers->get('Permissions-Policy');

    expect($permissions)
        ->toContain('camera=()')
        ->toContain('microphone=()')
        ->toContain('geolocation=()')
        ->toContain('payment=()')
        ->toContain('usb=()')
        ->toContain('browsing-topics=()')
        ->toContain('attribution-reporting=()')
        ->toContain('publickey-credentials-get=(self)');

    expect(cspHeader($response))->not->toBe('');
})->with('security header routes');

test('the CSP ships report-only, with no enforcing header and no report sink', function () {
    $response = $this->get('/');

    $response->assertHeaderMissing('Content-Security-Policy');
    $response->assertHeaderMissing('Report-To');

    $csp = cspHeader($response);

    expect($csp)->not->toContain('report-uri')
        ->and($csp)->not->toContain('report-to');
});

test('the strict variant pins every directive §8.6 lists', function () {
    $directives = cspDirectives(cspHeader($this->get('/')));

    expect($directives['default-src'])->toBe("'self'")
        ->and($directives['font-src'])->toBe("'self' data:")
        ->and($directives['img-src'])->toBe("'self' data:")
        ->and($directives['connect-src'])->toBe("'self'")
        ->and($directives['object-src'])->toBe("'none'")
        ->and($directives['base-uri'])->toBe("'self'")
        ->and($directives['form-action'])->toBe("'self'")
        ->and($directives['frame-ancestors'])->toBe("'none'");

    expect($directives['script-src'])
        ->toContain("'self'")
        ->toContain("'strict-dynamic'")
        ->toMatch("/'nonce-[A-Za-z0-9+\/=]+'/");

    expect($directives['style-src'])
        ->toContain("'self'")
        ->toMatch("/'nonce-[A-Za-z0-9+\/=]+'/");
});

test('the default variant adds no third-party origin', function () {
    $csp = cspHeader($this->get('/'));

    expect($csp)->not->toContain('clarity.ms')
        ->and($csp)->not->toContain('bing.com')
        ->and($csp)->not->toContain('googlesyndication')
        ->and($csp)->not->toContain('doubleclick')
        ->and($csp)->not->toContain('pagead2');
});

test('the nonce the CSP declares is the one the document carries', function () {
    $response = $this->get('/');
    $csp = cspHeader($response);

    preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    $nonce = $matches[1];

    expect($response->getContent())
        ->toContain('nonce="'.$nonce.'"')
        ->toContain('__cspNonce');
});

test('HSTS is sent only over HTTPS', function () {
    $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('a DNT signal keeps the strict variant even when analytics is consented', function () {
    config([
        'consent.analytics_enabled' => true,
        'consent.clarity_project_id' => 'headerstst',
    ]);

    $csp = cspHeader(
        $this->withUnencryptedCookie('consent', cspConsentCookie())->withHeader('DNT', '1')->get('/')
    );

    expect($csp)->not->toContain('clarity.ms')
        ->and($csp)->not->toContain('googlesyndication')
        ->and($csp)->toContain("'strict-dynamic'");
});

test('analytics consent alone widens the CSP to Clarity only', function () {
    config([
        'consent.analytics_enabled' => true,
        'consent.ads_enabled' => true,
        'consent.clarity_project_id' => 'headerstst',
        'consent.adsense_client' => 'ca-pub-0000000000000000',
        'consent.adsense_slot' => '0000000000',
    ]);

    $csp = cspHeader($this->withUnencryptedCookie('consent', cspConsentCookie())->get('/'));

    expect($csp)
        ->toContain('https://www.clarity.ms')
        ->toContain('https://*.clarity.ms')
        ->toContain('https://c.bing.com')
        ->not->toContain('googlesyndication')
        ->not->toContain('doubleclick');
});
