<?php

namespace Database\Seeders;

use App\Models\Budget;
use Illuminate\Database\Seeder;

class BudgetSeeder extends Seeder
{
    public function run(): void
    {
        $budgets = [
            [
                'user_id' => 1,
                'category_id' => 1,
                'amount' => 300,
                'start_date' => '2025-05-01',
                'end_date' => '2025-05-31',
            ],
            [
                'user_id' => 1,
                'category_id' => 2,
                'amount' => 100,
                'start_date' => '2025-05-01',
                'end_date' => '2025-05-31',
            ],
            [
                'user_id' => 1,
                'category_id' => 3,
                'amount' => 200,
                'start_date' => '2025-05-01',
                'end_date' => '2025-05-31',
            ],
        ];

        Budget::insert($budgets);
    }
}
