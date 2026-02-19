<?php

use App\Models\User;

test('that a user can update their profile', function () {
    $user = User::factory()->create();

    e2e('frontend')
        ->actingAs($user)
        ->only('UserProfile')
        ->run();

    expect($user->fresh()->name)->toBe('Test User');
    expect($user->fresh()->email)->toBe('test@example.com');
});
