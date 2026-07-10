<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\RecommendationsController;
use Illuminate\Support\Facades\Route;

// Public auth routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

// Admin login (separate — uses username+password, not email)
Route::post('/admin/login', [AdminController::class, 'login']);

// Protected routes (student)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    Route::get('/profile',           [ProfileController::class, 'show']);
    Route::patch('/profile',         [ProfileController::class, 'update']);
    Route::post('/profile/password', [ProfileController::class, 'changePassword']);

    Route::get('/dashboard/stats',   [DashboardController::class, 'stats']);
    Route::patch('/dashboard/stats', [DashboardController::class, 'updateStats']);

    // Documents
    Route::get('/documents',                  [DocumentController::class, 'index']);
    Route::post('/documents',                 [DocumentController::class, 'store']);
    Route::delete('/documents/{id}',          [DocumentController::class, 'destroy']);
    Route::post('/documents/{id}/summary',    [DocumentController::class, 'generateSummary']);
    Route::post('/documents/{id}/quiz',       [DocumentController::class, 'generateQuiz']);
    Route::delete('/documents/{id}/summary',  [DocumentController::class, 'clearSummary']);

    // Recommendations
    Route::post('/recommendations/generate', [RecommendationsController::class, 'generate']);

    // Chat
    Route::get('/chats',                        [ChatController::class, 'index']);
    Route::post('/chats',                       [ChatController::class, 'store']);
    Route::get('/chats/{id}/messages',          [ChatController::class, 'messages']);
    Route::post('/chats/{id}/message',          [ChatController::class, 'sendMessage']);
    Route::delete('/chats/{id}',                [ChatController::class, 'destroy']);

    // Admin routes (require admin role)
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/stats',     [AdminController::class, 'stats']);
        Route::get('/users',     [AdminController::class, 'listUsers']);
        Route::get('/documents', [AdminController::class, 'listDocuments']);
    });
});
