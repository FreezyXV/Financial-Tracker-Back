<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\DefaultCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class FinanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['savings_rate_target' => 20, 'large_transaction_threshold' => 500]);
        DefaultCategories::seed($this->user);
        Sanctum::actingAs($this->user);
    }

    protected function account(array $attrs = []): Account
    {
        return Account::create(array_merge(['user_id' => $this->user->id, 'name' => 'Courant', 'type' => 'checking', 'opening_balance' => 0], $attrs));
    }

    protected function categoryId(string $name): int
    {
        return $this->user->categories()->where('name', $name)->value('id');
    }
}
