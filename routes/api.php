<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\RequirementController;
use App\Http\Controllers\AssumptionController;

Route::prefix('auth')->group(function () {
    Route::post('/check', [AuthController::class, 'checkAuth']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

Route::prefix('projects')->middleware('auth')->group(function () {
    Route::post('/dashboard', [ProjectController::class, 'dashboard']);
    Route::post('/create', [ProjectController::class, 'create']);
    Route::post('/update', [ProjectController::class, 'update']);
    Route::post('/close', [ProjectController::class, 'close']);
    Route::post('/documents', [ProjectController::class, 'documents']);
});

Route::prefix('tasks')->middleware('auth')->group(function () {
    Route::post('/get', [TaskController::class, 'get']);
    Route::post('/add', [TaskController::class, 'add']);
    Route::post('/update', [TaskController::class, 'update']);
    Route::post('/update_resource', [TaskController::class, 'update_resource']);
    Route::post('/schedule_variance', [TaskController::class, 'schedule_variance']);
    Route::post('/team_performance', [TaskController::class, 'team_performance']);
})->middleware('auth');

Route::prefix('comments')->middleware('auth')->group(function () {
    Route::post('/get', [CommentController::class, 'get']);
    Route::post('/add', [CommentController::class, 'add']);
});

Route::prefix('requirements')->middleware('auth')->group(function () {
    Route::post('/get', [RequirementController::class, 'get']);
    Route::post('/getall', [RequirementController::class, 'get_all']);
    Route::post('/update', [RequirementController::class, 'update']);
    Route::post('/create', [RequirementController::class, 'create']);
    Route::post('/delete', [RequirementController::class, 'delete']);
});

Route::prefix('assumptions')->middleware('auth')->group(function () {
    Route::post('/get', [AssumptionController::class, 'get']);
    Route::post('/getall', [AssumptionController::class, 'get_all']);
    Route::post('/update', [AssumptionController::class, 'update']);
    Route::post('/create', [AssumptionController::class, 'create']);
    Route::post('/delete', [AssumptionController::class, 'delete']);
});
