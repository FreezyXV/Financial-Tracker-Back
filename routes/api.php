<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\RecurringController;
use App\Http\Controllers\Api\RuleController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

// Diagnostic de déploiement (aucun secret exposé)
Route::get('/health', function () {
    $db = ['connection' => config('database.default')];
    try {
        DB::select('select 1');
        $db['status'] = 'ok';
        $db['migrated'] = Schema::hasTable('transactions');
        $log = storage_path('logs/boot-migrate.log');
        if (! $db['migrated'] && is_file($log)) {
            $db['migrate_log'] = mb_substr(preg_replace('#\w+://\S+#', '[url]', (string) file_get_contents($log)), -700);
        }
    } catch (Throwable $e) {
        $db['status'] = 'error';
        $db['error'] = class_basename($e).(preg_match('/SQLSTATE\[\w+\]/', $e->getMessage(), $m) ? ' '.$m[0] : '');
        // Cause libpq (hôte, « password authentication failed »…) sans identifiants ni mot de passe.
        if (preg_match('/failed: (.{0,160})/s', $e->getMessage(), $m)) {
            $db['detail'] = preg_replace('#\w+://\S+#', '[url]', strtok($m[1], '(') ?: $m[1]);
        }
        $url = (string) config('database.connections.pgsql.url');
        $db['url_set'] = $url !== '';
        $db['host'] = $url ? parse_url($url, PHP_URL_HOST) : config('database.connections.pgsql.host');
    }

    return response()->json([
        'app' => 'ok',
        'env' => app()->environment(),
        'debug' => (bool) config('app.debug'),
        'key_set' => (bool) config('app.key'),
        'cors_origins' => config('cors.allowed_origins'),
        'db' => $db,
    ], $db['status'] === 'ok' ? 200 : 503);
});

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

    // Questionnaire d'accueil et conseils personnalisés
    Route::get('/onboarding', [OnboardingController::class, 'show']);
    Route::post('/onboarding', [OnboardingController::class, 'store']);
    Route::post('/onboarding/preview', [OnboardingController::class, 'preview']);
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip']);
    Route::get('/recommendations', [OnboardingController::class, 'recommendations']);

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
