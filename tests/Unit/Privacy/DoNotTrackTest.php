<?php

use App\Http\Middleware\ResolvePrivacyState;
use Illuminate\Http\Request;
use ReflectionMethod;

/*
|--------------------------------------------------------------------------
| Do Not Track header parsing (§8.2, decision 10)
|--------------------------------------------------------------------------
|
| The server treats a request as opted out when `DNT` or `Sec-GPC` carries `1`,
| `01`, `yes` or `on`, case-insensitively and trimmed; anything else — `0`, an
| absent header, garbage — means "no objection recorded", never "track me". The
| signal is only ever additive, so neither header can grant consent.
|
| This file owns the value matrix. The same parser is exercised end-to-end
| through the `privacy` prop in `DntSignalTest`; the matrix lives here because a
| unit test can cover every input cheaply and without HTTP.
|
| `ResolvePrivacyState::detectsOptOut()` has no public entry point, so the
| helper below reaches it by reflection. That is a gap worth closing — a public
| static would make this file a plain unit test — and it is reported rather than
| worked around by editing a file this suite does not own.
|
*/

/**
 * Ask the middleware's parser about a request carrying the given headers.
 *
 * @param  array<string, string>  $headers
 */
function resolvePrivacyOptOut(array $headers): bool
{
    $request = Request::create('/');

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    $method = new ReflectionMethod(ResolvePrivacyState::class, 'detectsOptOut');

    return (bool) $method->invoke(new ResolvePrivacyState, $request);
}

test('an opt-out value in either header is honoured', function (string $header, string $value) {
    expect(resolvePrivacyOptOut([$header => $value]))->toBeTrue();
})->with([
    'DNT 1' => ['DNT', '1'],
    'DNT 01' => ['DNT', '01'],
    'DNT yes' => ['DNT', 'yes'],
    'DNT YES' => ['DNT', 'YES'],
    'DNT Yes' => ['DNT', 'Yes'],
    'DNT on' => ['DNT', 'on'],
    'DNT ON' => ['DNT', 'ON'],
    'DNT padded' => ['DNT', ' 1 '],
    'Sec-GPC 1' => ['Sec-GPC', '1'],
    'Sec-GPC 01' => ['Sec-GPC', '01'],
    'Sec-GPC yes' => ['Sec-GPC', 'yes'],
    'Sec-GPC on' => ['Sec-GPC', 'on'],
]);

test('a value that is not an explicit opt-out is not honoured', function (string $header, string $value) {
    expect(resolvePrivacyOptOut([$header => $value]))->toBeFalse();
})->with([
    'DNT 0' => ['DNT', '0'],
    'DNT 00' => ['DNT', '00'],
    'DNT false' => ['DNT', 'false'],
    'DNT no' => ['DNT', 'no'],
    'DNT off' => ['DNT', 'off'],
    'DNT empty' => ['DNT', ''],
    'DNT garbage' => ['DNT', 'maybe'],
    'Sec-GPC 0' => ['Sec-GPC', '0'],
    'Sec-GPC garbage' => ['Sec-GPC', 'sometimes'],
]);

test('an absent signal is not an opt-out', function () {
    expect(resolvePrivacyOptOut([]))->toBeFalse();
});

test('either header alone is enough, and DNT 0 never cancels Sec-GPC 1', function () {
    expect(resolvePrivacyOptOut(['DNT' => '1']))->toBeTrue()
        ->and(resolvePrivacyOptOut(['Sec-GPC' => '1']))->toBeTrue()
        ->and(resolvePrivacyOptOut(['DNT' => '0', 'Sec-GPC' => '1']))->toBeTrue()
        ->and(resolvePrivacyOptOut(['DNT' => '0', 'Sec-GPC' => '0']))->toBeFalse()
        ->and(resolvePrivacyOptOut(['DNT' => '0']))->toBeFalse();
});
