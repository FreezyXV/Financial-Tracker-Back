<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Services\WealthEngine;

class WealthEngineTest extends FinanceTestCase
{
    public function test_balances_follow_income_expense_and_transfers(): void
    {
        $checking = $this->account(['opening_balance' => 1000]);
        $livret = $this->account(['name' => 'Livret A', 'type' => 'livret_a']);
        $this->postJson('/api/transactions', ['account_id' => $checking->id, 'type' => 'income', 'amount' => 2000, 'date' => '2026-01-01'])->assertCreated();
        $this->postJson('/api/transactions', ['account_id' => $checking->id, 'type' => 'expense', 'amount' => 300, 'date' => '2026-01-05'])->assertCreated();
        $this->postJson('/api/transactions', ['account_id' => $checking->id, 'type' => 'transfer', 'transfer_account_id' => $livret->id, 'amount' => 500, 'date' => '2026-01-06'])->assertCreated();

        $e = WealthEngine::for($this->user);
        $this->assertSame(2200.0, $e->cashBalance($e->accounts[$checking->id]));
        $this->assertSame(500.0, $e->cashBalance($e->accounts[$livret->id]));
        $this->assertSame(2700.0, $e->netWorth()['net_worth']);
        // Historique : avant les opérations, seul le solde d'ouverture compte
        $this->assertSame(1000.0, $e->netWorth('2025-12-31')['net_worth']);
    }

    public function test_weighted_average_cost_and_realized_gain(): void
    {
        $pea = $this->account(['name' => 'PEA', 'type' => 'pea', 'opening_balance' => 5000]);
        $asset = Asset::create(['user_id' => $this->user->id, 'symbol' => 'CW8', 'name' => 'MSCI World', 'asset_class' => 'etf', 'current_price' => 130, 'pea_eligible' => true]);
        $op = fn ($data) => $this->postJson('/api/operations', array_merge(['account_id' => $pea->id, 'asset_id' => $asset->id], $data));
        $op(['type' => 'buy', 'quantity' => 10, 'price' => 100, 'date' => '2026-01-01'])->assertCreated();
        $op(['type' => 'buy', 'quantity' => 10, 'price' => 120, 'date' => '2026-02-01'])->assertCreated();
        $op(['type' => 'sell', 'quantity' => 5, 'price' => 150, 'fees' => 0, 'date' => '2026-03-01'])->assertCreated();
        $op(['type' => 'sell', 'quantity' => 50, 'price' => 150, 'date' => '2026-03-02'])->assertStatus(422);

        $e = WealthEngine::for($this->user);
        [$p] = $e->holdings();
        $this->assertEquals(15, $p['quantity']);
        $this->assertEquals(110, $p['avg_price']);
        $this->assertEquals(1650, $p['cost']);
        $this->assertEquals(1950, $p['value']);
        $this->assertEquals(200, $p['realized']);
        // Cash : 5000 - 1000 - 1200 + 750
        $this->assertEquals(3550, $e->cashBalance($e->accounts[$pea->id]));
        $this->assertEquals(5500, $e->accountValue($e->accounts[$pea->id]));
    }

    public function test_foreign_currency_asset_is_valued_in_eur(): void
    {
        $cto = $this->account(['name' => 'CTO', 'type' => 'cto', 'opening_balance' => 1000]);
        $asset = Asset::create(['user_id' => $this->user->id, 'symbol' => 'AAPL', 'name' => 'Apple', 'asset_class' => 'stock', 'currency' => 'USD', 'current_price' => 200, 'fx_rate' => 0.9]);
        $this->postJson('/api/operations', ['account_id' => $cto->id, 'asset_id' => $asset->id, 'type' => 'buy', 'quantity' => 2, 'price' => 180, 'fx_rate' => 0.92, 'date' => '2026-01-01'])->assertCreated();

        [$p] = WealthEngine::for($this->user)->holdings();
        $this->assertEqualsWithDelta(331.2, $p['cost'], 0.01);
        $this->assertEqualsWithDelta(360, $p['value'], 0.01);
    }

    public function test_pea_rejects_ineligible_asset(): void
    {
        $pea = $this->account(['name' => 'PEA', 'type' => 'pea', 'opening_balance' => 1000]);
        $aapl = Asset::create(['user_id' => $this->user->id, 'symbol' => 'AAPL', 'name' => 'Apple', 'asset_class' => 'stock', 'current_price' => 200]);
        $this->postJson('/api/operations', ['account_id' => $pea->id, 'asset_id' => $aapl->id, 'type' => 'buy', 'quantity' => 1, 'price' => 200, 'date' => '2026-01-01'])
            ->assertStatus(422)->assertJsonFragment(['message' => "Cet actif n'est pas éligible au PEA (cochez « Éligible PEA » sur l'actif s'il l'est)."]);
    }

    public function test_operations_rejected_on_non_investment_account(): void
    {
        $checking = $this->account();
        $this->postJson('/api/operations', ['account_id' => $checking->id, 'type' => 'interest', 'amount' => 5, 'date' => '2026-01-01'])->assertStatus(422);
    }

    public function test_pea_ceiling_is_tracked(): void
    {
        $checking = $this->account(['opening_balance' => 50000]);
        $pea = $this->account(['name' => 'PEA', 'type' => 'pea', 'opened_at' => '2020-01-01']);
        $this->postJson('/api/transactions', ['account_id' => $checking->id, 'type' => 'transfer', 'transfer_account_id' => $pea->id, 'amount' => 20000, 'date' => '2026-01-01']);

        $res = $this->getJson("/api/accounts/{$pea->id}")->assertOk();
        $this->assertEquals(130000, $res->json('ceiling_remaining'));
        $this->assertEquals('2025-01-01', $res->json('tax_free_date'));
    }
}
