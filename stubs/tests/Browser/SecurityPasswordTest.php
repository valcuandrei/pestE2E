<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('that an authenticated user can update their password', function () {
    $user = User::factory()->create();
    $newPassword = 'new-secure-password-123';

    e2e('frontend')
        ->actingAs($user)
        ->withParams([
            'currentPassword' => 'password',
            'newPassword' => $newPassword,
            'newPasswordConfirmation' => $newPassword,
        ])
        ->only('SecurityPassword')
        ->run();

    expect(Hash::check($newPassword, $user->fresh()->password))->toBeTrue();
});
