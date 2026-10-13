<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Administrator
    |--------------------------------------------------------------------------
    |
    | The single account allowed to reach the admin area and to pass every
    | resource policy. The email is compared case-insensitively by
    | `User::isAdministrator()`.
    |
    | The placeholder default is what `AdministratorSeeder` seeds, so the seeded
    | account and the authorisation check can never disagree: on a fresh checkout
    | `php artisan migrate:fresh --seed` yields a working local login. A real
    | deployment sets `ADMIN_EMAIL`. `?:` rather than an `env()` default because
    | `.env.example` ships `ADMIN_EMAIL=` — an empty string, which `env()` would
    | return instead of the default.
    |
    */

    'admin' => [
        'email' => env('ADMIN_EMAIL') ?: 'admin@example.test',
        'name' => env('ADMIN_NAME', 'Portfolio Administrator'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeding overrides
    |--------------------------------------------------------------------------
    |
    | The administrator and demo-content seeders refuse to run in production
    | unless the matching flag is explicitly enabled.
    |
    */

    'admin_force_seed' => env('ADMIN_FORCE_SEED', false),

    'demo_force_seed' => env('DEMO_FORCE_SEED', false),

];
