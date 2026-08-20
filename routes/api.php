<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\RecommendationsController;
use App\Http\Controllers\TeacherController;

// Public auth routes
Route::prefix('auth')->group(function () {
    Route::post('/register',   [AuthController::class, 'register']);
    Route::post('/login',      [AuthController::class, 'login']);
    Route::post('/google',     [AuthController::class, 'googleLogin']);
    Route::post('/send-otp',   [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
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

    // Quiz routes
    Route::get('/quizzes',                           [App\Http\Controllers\QuizController::class, 'index']);
    Route::post('/quizzes/generate',                 [App\Http\Controllers\QuizController::class, 'generate']);
    Route::put('/quizzes/{id}',                      [App\Http\Controllers\QuizController::class, 'update']);
    Route::delete('/quizzes/{id}',                   [App\Http\Controllers\QuizController::class, 'destroy']);
    Route::get('/quizzes/{id}/submissions',          [App\Http\Controllers\QuizController::class, 'submissions']);
    Route::post('/quizzes/attempts/{attemptId}/grade', [App\Http\Controllers\QuizController::class, 'gradeAttempt']);

    Route::post('/quizzes/{id}/start',               [App\Http\Controllers\QuizController::class, 'start']);
    Route::post('/quizzes/attempts/{attemptId}/submit', [App\Http\Controllers\QuizController::class, 'submit']);
    Route::get('/quizzes/attempts/{attemptId}/result',  [App\Http\Controllers\QuizController::class, 'result']);

    // Teacher routes
    Route::prefix('teacher')->group(function () {
        Route::get('/stats',            [TeacherController::class, 'stats']);
        Route::get('/roster',           [TeacherController::class, 'roster']);
        Route::post('/nudge/{id}',      [TeacherController::class, 'sendNudge']);
    });

    // Admin routes (require admin role)
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/stats',                       [AdminController::class, 'stats']);
        Route::get('/users',                       [AdminController::class, 'listUsers']);
        Route::patch('/users/{id}/approve-teacher', [AdminController::class, 'toggleTeacherApproval']);
        Route::patch('/users/{id}/assign-teacher',  [AdminController::class, 'assignTeacher']);
        Route::get('/documents',                   [AdminController::class, 'listDocuments']);
    });
});
