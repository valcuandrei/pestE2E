<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use ValcuAndrei\PestE2E\Http\Controllers\E2EAuthController;

Route::post((string) config('pest-e2e.auth.route', '/.well-known/pest-e2e/auth/login'), E2EAuthController::class)->middleware('web')->name('pest-e2e.auth.login');
