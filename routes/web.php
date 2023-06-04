<?php

declare(strict_types=1);

use GoCPA\SpaceHealthcheck\Http\Controllers\SpaceHealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('space/check', SpaceHealthCheckController::class)->name('space.check');
