<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('passwords are hashed with argon2id', function () {
    $user = User::factory()->create();

    expect($user->password)->toStartWith('$argon2id$');
});

test('a legacy bcrypt password is rehashed to argon2id on login', function () {
    $user = User::factory()->create();

    DB::table('users')->where('id', $user->id)->update(['password' => bcrypt('password')]);

    expect($user->refresh()->password)->toStartWith('$2y$');

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    expect($user->refresh()->password)->toStartWith('$argon2id$');
});
