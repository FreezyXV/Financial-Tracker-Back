<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\RecurringTransaction;
use App\Services\RecurringService;
use Carbon\Carbon;

class TransactionsAndReportsTest extends FinanceTestCase
{
    public function test_import_categorizes_and_deduplicates(): void
    {
        $acc = $this->account();
        $rows = [
            ['date' => '2026-03-02', 'description' => 'CB CARREFOUR CITY 01/03', 'amount' => -42.10],
            ['date' => '2026-03-03', 'description' => 'PRLV NETFLIX.COM', 'amount' => -13.49],
            ['date' => '2026-03-01', 'description' => 'VIR SALAIRE', 'amount' => 2500],
            ['date' => '2026-03-04', 'description' => 'Libellé inconnu', 'amount' => -5],
        ];
        $this->postJson('/api/transactions/import', ['account_id' => $acc->id, 'rows' => $rows])
            ->assertOk()->assertJson(['created' => 4, 'skipped' => 0, 'categorized' => 3]);
        $this->postJson('/api/transactions/import', ['account_id' => $acc->id, 'rows' => $rows])
            ->assertJson(['created' => 0, 'skipped' => 4]);

        $this->assertSame($this->categoryId('Courses'), $this->user->transactions()->where('amount', 42.10)->value('category_id'));
        $this->assertSame('income', $this->user->transactions()->where('amount', 2500)->value('type'));
    }

    public function test_categorizer_learns_from_history(): void
    {
        $acc = $this->account();
        $this->postJson('/api/transactions', ['account_id' => $acc->id, 'type' => 'expense', 'amount' => 30, 'date' => '2026-01-01', 'description' => 'CB CHEZ MARCEL 12/01', 'category_id' => $this->categoryId('Restaurants & sorties')]);
        $res = $this->postJson('/api/transactions', ['account_id' => $acc->id, 'type' => 'expense', 'amount' => 25, 'date' => '2026-02-01', 'description' => 'CB CHEZ MARCEL 03/02']);
        $this->assertSame($this->categoryId('Restaurants & sorties'), $res->json('category_id'));
    }

    public function test_monthly_report_and_budget_alerts(): void
    {
        $acc = $this->account();
        $courses = $this->categoryId('Courses');
        $this->postJson('/api/budgets', ['category_id' => $courses, 'amount' => 300])->assertCreated();
        $month = Carbon::today()->format('Y-m');
        $d = Carbon::today()->startOfMonth()->format('Y-m-d');
        $tx = fn ($type, $amount, $cat = null) => $this->postJson('/api/transactions', ['account_id' => $acc->id, 'type' => $type, 'amount' => $amount, 'date' => $d, 'category_id' => $cat]);
        $tx('income', 3000, $this->categoryId('Salaire'));
        $tx('expense', 200, $courses);
        $tx('expense', 150, $courses);
        $tx('expense', 900, $this->categoryId('Logement'));

        $r = $this->getJson("/api/reports/monthly?month=$month")->assertOk();
        $this->assertEquals(3000, $r->json('summary.income'));
        $this->assertEquals(1250, $r->json('summary.expense'));
        $this->assertEquals(58.3, $r->json('summary.savings_rate'));
        $this->assertSame('over', $r->json('budgets.0.status'));
        $this->assertSame('Logement', $r->json('expenses_by_category.0.name'));
        $this->assertNotEmpty($r->json('insights'));

        $this->assertTrue(Alert::where('user_id', $this->user->id)->where('trigger', 'limit_exceeded')->exists());
        $this->assertTrue(Alert::where('user_id', $this->user->id)->where('trigger', 'large_transaction')->exists());
        $this->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['net_worth', 'cashflow', 'history', 'indicators']);
        $this->getJson('/api/reports/yearly')->assertOk()->assertJsonCount(12, 'months');
    }

    public function test_recurring_posts_due_occurrences_and_advances(): void
    {
        $acc = $this->account();
        $r = RecurringTransaction::create([
            'user_id' => $this->user->id, 'account_id' => $acc->id, 'type' => 'expense', 'amount' => 10,
            'description' => 'SPOTIFY', 'frequency' => 'monthly',
            'next_date' => Carbon::today()->subMonths(2)->format('Y-m-d'),
        ]);
        $this->assertSame(3, RecurringService::postDue($this->user));
        $this->assertTrue($r->fresh()->next_date->isFuture());
        $this->assertSame(0, RecurringService::postDue($this->user));
    }

    public function test_export_csv(): void
    {
        $acc = $this->account();
        $this->postJson('/api/transactions', ['account_id' => $acc->id, 'type' => 'expense', 'amount' => 12.5, 'date' => '2026-01-01', 'description' => 'Test']);
        $res = $this->get('/api/transactions/export')->assertOk();
        $this->assertStringContainsString('-12,50', $res->getContent());
    }
}
