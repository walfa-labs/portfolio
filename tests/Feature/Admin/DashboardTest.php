<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.dashboard'));

    $response->assertRedirect(route('login'));
});

/*
 * The factory user must be the administrator: `User::isAdministrator()` compares
 * the email against `config('portfolio.admin.email')` and the `admin` middleware
 * guards the whole group, so a plain `User::factory()->create()` is refused with
 * a 403 the moment that middleware lands (§5.7).
 */
test('the administrator can visit the admin dashboard', function () {
    $user = User::factory()->administrator()->create();
    $this->actingAs($user);

    $response = $this->get(route('admin.dashboard'));

    $response->assertOk();
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('admin/Dashboard'));
});

test('a non-administrator is forbidden from the admin dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('admin.dashboard'))->assertForbidden();
});
