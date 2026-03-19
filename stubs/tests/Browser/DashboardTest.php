<?php

use App\Models\User;

test('that an authenticated user can view the dashboard', function () {
    $user = User::factory()->create();

    e2e('frontend')
        ->actingAs($user)
        ->only('Dashboard')
        ->run();

    expect($user->fresh())->not->toBeNull();
});
