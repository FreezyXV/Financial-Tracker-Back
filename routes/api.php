<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\AlertController;

// 🔓 Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// 🔐 Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('budgets', BudgetController::class);
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('alerts', AlertController::class);
});
