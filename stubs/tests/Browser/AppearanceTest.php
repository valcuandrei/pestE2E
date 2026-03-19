<?php

use App\Models\User;

test('that an authenticated user can change appearance preference', function () {
    $user = User::factory()->create();

    e2e('frontend')
        ->actingAs($user)
        ->only('Appearance')
        ->run();

    expect($user->fresh())->not->toBeNull();
});
