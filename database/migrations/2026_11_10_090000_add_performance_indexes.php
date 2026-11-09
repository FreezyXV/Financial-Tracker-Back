<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur les clés étrangères : PostgreSQL ne les crée pas automatiquement,
 * or chaque écran filtre par utilisateur et les suppressions cascadent par compte.
 */
return new class extends Migration
{
    private array $indexes = [
        'accounts' => [['user_id']],
        'categories' => [['user_id']],
        'budgets' => [['user_id']],
        'goals' => [['user_id']],
        'recurring_transactions' => [['user_id', 'is_active']],
        'assets' => [['user_id', 'price_source']],
        'categorization_rules' => [['user_id']],
        'alerts' => [['user_id', 'is_read']],
        'transactions' => [['account_id'], ['category_id'], ['transfer_account_id'], ['user_id', 'category_id']],
        'investment_operations' => [['account_id'], ['asset_id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $sets) {
            Schema::table($table, function (Blueprint $t) use ($sets) {
                foreach ($sets as $cols) {
                    $t->index($cols);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $sets) {
            Schema::table($table, function (Blueprint $t) use ($sets) {
                foreach ($sets as $cols) {
                    $t->dropIndex($cols);
                }
            });
        }
    }
};
