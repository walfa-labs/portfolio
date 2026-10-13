<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | Published and tightened for §4.14. The framework defaults are
    | `allowed_origins: ['*']`, `allowed_methods: ['*']` and
    | `allowed_headers: ['*']`, which are wrong for an API whose only writer is
    | one human on the same origin as the app.
    |
    */

    /*
     * Only the versioned API. The admin UI is same-origin and never preflights,
     * and `sanctum/csrf-cookie` is dropped with Sanctum: v1 authenticates with
     * Fortify sessions, not tokens (§4.11).
     */
    'paths' => ['api/*'],

    /*
     * `QUERY` must be listed: it is not a CORS-safelisted method, so a
     * cross-origin QUERY always preflights and would otherwise be blocked.
     * `max_age` below is what makes that preflight cost acceptable (§4.1).
     */
    'allowed_methods' => ['GET', 'HEAD', 'OPTIONS', 'QUERY', 'POST', 'PUT', 'PATCH', 'DELETE'],

    /*
     * Same-origin only — no wildcard. A cross-origin caller gets no CORS headers
     * at all, which is the intended default for a personal portfolio API.
     */
    'allowed_origins' => [env('APP_URL')],

    'allowed_origins_patterns' => [],

    /*
     * Exactly the headers the API reads: `Accept` for media-type negotiation,
     * `Content-Type` for the JSON:API write body, and the three the Inertia
     * client already sends for a stateful write.
     */
    'allowed_headers' => ['Accept', 'Content-Type', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN', 'X-Requested-With'],

    /*
     * A cross-origin client cannot read any of these otherwise: the validators,
     * the `Location` on a `201`, the QUERY discovery header and the rate-limit
     * accounting (§4.10, §4.12).
     */
    'exposed_headers' => [
        'ETag',
        'Last-Modified',
        'Location',
        'Accept-Query',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'Retry-After',
    ],

    /*
     * Stays `false`: the credentialed calls (admin writes) are same-origin and
     * need no CORS at all, so there is no cookie to expose (§4.14).
     */
    'supports_credentials' => false,

    'max_age' => 86400,

];
