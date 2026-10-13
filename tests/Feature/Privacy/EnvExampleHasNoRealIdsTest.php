<?php

use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The no-real-ID rule (§8.9)
|--------------------------------------------------------------------------
|
| `.env.example` is committed; `.env` is not. A live Clarity project id or
| Google publisher id in the committed file would publish the owner's vendor
| accounts and bake one identity into a generic repository, so the committed
| file must hold empty placeholders only (§8.9 item 1).
|
| The ids themselves reach the browser only through the `privacy` Inertia prop
| (§8.9 item 2), which is why the `privacy` prop keys are asserted in
| `AnalyticsGatingTest` and `AdScopeTest` rather than here.
|
*/

/**
 * Read the committed example environment file into key => value pairs.
 *
 * Values are trimmed and any surrounding quotes stripped, so `KEY=` and
 * `KEY=""` both read as the empty string.
 *
 * @return array<string, string>
 */
function exampleEnvValues(): array
{
    $values = [];

    foreach (File::lines(base_path('.env.example')) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $values[trim($key)] = trim(trim($value), '"\'');
    }

    return $values;
}

test('.env.example commits no Google publisher id', function () {
    expect(File::get(base_path('.env.example')))->not->toMatch('/ca-pub-\d{16}/');
});

test('.env.example commits no Clarity project id', function () {
    $value = exampleEnvValues()['CLARITY_PROJECT_ID'] ?? null;

    expect($value)->not->toMatch('/^[A-Za-z0-9]{10}$/');
});

test('.env.example carries every §8.9 key with a safe placeholder', function () {
    $values = exampleEnvValues();

    expect($values)->toHaveKeys([
        'SESSION_COOKIE',
        'ANALYTICS_ENABLED',
        'ADS_ENABLED',
        'CLARITY_PROJECT_ID',
        'ADSENSE_CLIENT',
        'ADSENSE_SLOT',
        'CONSENT_POLICY_VERSION',
        'CONTACT_EMAIL',
    ]);

    expect($values['ANALYTICS_ENABLED'])->toBe('false')
        ->and($values['ADS_ENABLED'])->toBe('false')
        ->and($values['CLARITY_PROJECT_ID'])->toBe('')
        ->and($values['ADSENSE_CLIENT'])->toBe('')
        ->and($values['ADSENSE_SLOT'])->toBe('')
        ->and($values['CONSENT_POLICY_VERSION'])->toBe('1');
});
