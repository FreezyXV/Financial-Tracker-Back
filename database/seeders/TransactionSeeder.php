<?php

namespace Database\Seeders;

use App\Models\Transaction;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $transactions = [
            [
                'user_id' => 1,
                'category_id' => 1,
                'amount' => 25.5,
                'description' => 'Groceries',
                'date' => '2025-05-01',
            ],
            [
                'user_id' => 1,
                'category_id' => 2,
                'amount' => 15.0,
                'description' => 'Bus ticket',
                'date' => '2025-05-02',
            ],
            [
                'user_id' => 1,
                'category_id' => 3,
                'amount' => 60.0,
                'description' => 'Electricity bill',
                'date' => '2025-05-03',
            ],
            [
                'user_id' => 1,
                'category_id' => 4,
                'amount' => 3000.0,
                'description' => 'Monthly Salary',
                'date' => '2025-05-01',
            ],
            [
                'user_id' => 1,
                'category_id' => 5,
                'amount' => 800.0,
                'description' => 'Freelance project',
                'date' => '2025-05-04',
            ],
        ];

        Transaction::insert($transactions);
    }
}
