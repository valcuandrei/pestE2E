<?php

use App\Models\User;

test('that a user can update their profile', function () {
    $user = User::factory()->create();
    $name = 'Test User';
    $email = 'test@example.com';

    e2e('frontend')
        ->actingAs($user)
        ->withParams([
            'name' => $name,
            'email' => $email,
        ])
        ->only('UserProfile')
        ->run();

    expect($user->fresh()->name)->toBe($name);
    expect($user->fresh()->email)->toBe($email);
});
