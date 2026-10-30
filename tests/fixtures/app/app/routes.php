<?php

declare(strict_types=1);
use App\Controllers\HomeController;
use Arix\Facades\Route;

Route::get('/', [HomeController::class, 'show']);
