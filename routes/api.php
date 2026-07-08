<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    Route::get('/profile',             [ProfileController::class, 'show']);
    Route::patch('/profile',           [ProfileController::class, 'update']);
    Route::post('/profile/password',   [ProfileController::class, 'changePassword']);

    Route::get('/dashboard/stats',     [DashboardController::class, 'stats']);
    Route::patch('/dashboard/stats',   [DashboardController::class, 'updateStats']);

    // Admin routes
    Route::prefix('admin')->group(function () {
        Route::get('/users', [AdminController::class, 'listUsers']);
    });
});
