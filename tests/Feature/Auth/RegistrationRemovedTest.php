<?php

test('registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('new users can not register', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
});
