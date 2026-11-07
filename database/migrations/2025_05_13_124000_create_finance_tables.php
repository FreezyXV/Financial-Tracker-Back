<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma financier complet : comptes, catégories, transactions, budgets,
 * récurrences, actifs, opérations d'investissement, historique de prix,
 * objectifs, règles de catégorisation et alertes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('currency', 3)->default('EUR');
            $table->decimal('savings_rate_target', 5, 2)->default(20);
            $table->decimal('large_transaction_threshold', 15, 2)->default(500);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['income', 'expense'])->default('expense');
            $table->string('color', 9)->default('#64748b');
            $table->string('icon', 40)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->boolean('is_essential')->default(false);
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // checking, savings, livret_a, ldds, lep, pel, pea, pea_pme, cto,
            // assurance_vie, per, crypto, cash, real_estate, loan, other
            $table->string('type', 30)->default('checking');
            $table->string('institution')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opened_at')->nullable();
            $table->decimal('manual_value', 15, 2)->nullable();
            $table->decimal('interest_rate', 6, 3)->nullable();
            $table->string('color', 9)->default('#1b2a4a');
            $table->boolean('include_in_net_worth')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });

        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transfer_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->enum('type', ['income', 'expense', 'transfer'])->default('expense');
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->enum('frequency', ['weekly', 'monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->date('next_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_subscription')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_post')->default(true);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transfer_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('recurring_id')->nullable()->constrained('recurring_transactions')->nullOnDelete();
            $table->enum('type', ['income', 'expense', 'transfer'])->default('expense');
            $table->decimal('amount', 15, 2); // toujours positif, le sens vient du type
            $table->date('date');
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->string('import_hash', 64)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'date']);
            $table->index(['user_id', 'import_hash']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2); // montant mensuel
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('rollover')->default(false);
            $table->unsignedTinyInteger('alert_threshold')->default(80); // %
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 40);
            $table->string('name');
            // stock, etf, crypto, bond, fund, reit, commodity, other
            $table->string('asset_class', 20)->default('etf');
            $table->string('isin', 12)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->enum('price_source', ['manual', 'yahoo', 'coingecko'])->default('manual');
            $table->string('source_id')->nullable(); // ex: CW8.PA, bitcoin
            $table->decimal('current_price', 20, 8)->default(0);
            $table->decimal('fx_rate', 16, 8)->default(1); // devise actif -> EUR
            $table->timestamp('price_updated_at')->nullable();
            $table->string('region', 40)->nullable();
            $table->string('sector', 60)->nullable();
            $table->boolean('pea_eligible')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'symbol']);
        });

        Schema::create('asset_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('price', 20, 8);
            $table->decimal('fx_rate', 16, 8)->default(1);
            $table->unique(['asset_id', 'date']);
        });

        Schema::create('investment_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('type', ['buy', 'sell', 'dividend', 'interest', 'fee']);
            $table->decimal('quantity', 24, 10)->default(0);
            $table->decimal('price', 20, 8)->default(0); // devise de l'actif
            $table->decimal('fx_rate', 16, 8)->default(1);
            $table->decimal('fees', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0); // dividendes / intérêts / frais (EUR)
            $table->date('date');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'date']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('target_amount', 15, 2);
            $table->decimal('current_amount', 15, 2)->default(0);
            $table->date('target_date')->nullable();
            $table->string('color', 9)->default('#1f7a5c');
            $table->unsignedTinyInteger('priority')->default(2);
            $table->timestamps();
        });

        Schema::create('categorization_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('pattern');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // budget, transaction, goal, recurring, custom
            $table->string('level', 10)->default('info'); // info, warning, danger
            $table->text('message');
            $table->string('trigger')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        foreach ([
            'alerts', 'categorization_rules', 'goals', 'investment_operations',
            'asset_prices', 'assets', 'budgets', 'transactions',
            'recurring_transactions', 'accounts', 'categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['currency', 'savings_rate_target', 'large_transaction_threshold']);
        });
    }
};
