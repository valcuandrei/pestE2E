<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract;

Route::post('/.well-known/pest-e2e/auth/login', function (Request $request, E2EAuthActionContract $action) {
    $payload = $request->validate([
        'ticket' => ['required', 'string'],
    ]);

    return $action->handle($payload);
});
