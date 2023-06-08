<?php

declare(strict_types=1);

use GoCPA\SpaceHealthcheck\Http\Controllers\SpaceHealthCheckController;
use GoCPA\SpaceHealthcheck\Http\Middleware\EnsureSecretKeyIsValid;
use Illuminate\Support\Facades\Route;

Route::get('space/check', SpaceHealthCheckController::class)
    ->middleware(EnsureSecretKeyIsValid::class)
    ->name('space.check');
