<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\RecurringController;
use App\Http\Controllers\Api\RuleController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

// Public
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Authentifié (Bearer token Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'update']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Synthèses
    Route::get('/dashboard', [InsightController::class, 'dashboard']);
    Route::get('/reports/monthly', [InsightController::class, 'monthly']);
    Route::get('/reports/yearly', [InsightController::class, 'yearly']);
    Route::get('/net-worth', [InsightController::class, 'netWorth']);
    Route::get('/portfolio', [InsightController::class, 'portfolio']);
    Route::get('/simulator/defaults', [InsightController::class, 'simulatorDefaults']);

    // Transactions
    Route::post('/transactions/bulk', [TransactionController::class, 'bulk']);
    Route::post('/transactions/import', [TransactionController::class, 'import']);
    Route::get('/transactions/export', [TransactionController::class, 'export']);
    Route::apiResource('transactions', TransactionController::class);

    // Récurrences & abonnements
    Route::post('/recurring/post', [RecurringController::class, 'post']);
    Route::get('/recurring/suggestions', [RecurringController::class, 'suggestions']);
    Route::apiResource('recurring', RecurringController::class);

    // Investissements
    Route::post('/assets/refresh', [AssetController::class, 'refresh']);
    Route::get('/assets/search', [AssetController::class, 'search']);
    Route::apiResource('assets', AssetController::class);
    Route::apiResource('operations', OperationController::class);

    // Référentiels
    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('budgets', BudgetController::class);
    Route::apiResource('goals', GoalController::class);
    Route::post('/rules/apply', [RuleController::class, 'apply']);
    Route::apiResource('rules', RuleController::class)->except('show');

    // Alertes
    Route::post('/alerts/read-all', [AlertController::class, 'readAll']);
    Route::apiResource('alerts', AlertController::class)->only(['index', 'update', 'destroy']);
});
