<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Consent policy version
    |--------------------------------------------------------------------------
    |
    | The version the `consent` cookie is keyed to (§8.3). A stored record whose
    | `v` does not match this value is treated as absent, so bumping the policy
    | invalidates every existing consent and re-shows the banner. `PrivacyController`
    | reads the same key for the version history it publishes on `/privacy`.
    |
    */

    'policy_version' => (int) env('CONSENT_POLICY_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | Analytics (Microsoft Clarity) and advertising (Google AdSense)
    |--------------------------------------------------------------------------
    |
    | `analytics_enabled` and `ads_enabled` are the kill switches (§8.4 item 2,
    | §8.9): with either off, the matching processor is never loaded, whatever a
    | visitor consented to. Both default to `false` so a fresh checkout loads no
    | third party at all.
    |
    | The ids are public by nature; they ship empty in `.env.example` so no real
    | account id is ever committed (§8.9). `?:` rather than an `env()` default
    | because `.env.example` ships `CLARITY_PROJECT_ID=` — an empty string, which
    | `env()` would return instead of null (the `config/portfolio.php` precedent).
    |
    */

    'analytics_enabled' => (bool) env('ANALYTICS_ENABLED', false),

    'ads_enabled' => (bool) env('ADS_ENABLED', false),

    'clarity_project_id' => env('CLARITY_PROJECT_ID') ?: null,

    'adsense_client' => env('ADSENSE_CLIENT') ?: null,

    'adsense_slot' => env('ADSENSE_SLOT') ?: null,

];
