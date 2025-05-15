<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Alert;

class AlertSeeder extends Seeder
{
    public function run(): void
    {
        $alerts = [
            [
                'user_id' => 1,
                'type' => 'budget',
                'message' => 'Vous avez dépassé votre budget Transport pour mai.',
                'trigger' => 'limit_exceeded',
                'is_read' => false,
            ],
            [
                'user_id' => 1,
                'type' => 'transaction',
                'message' => 'Une transaction exceptionnelle de 800€ a été détectée.',
                'trigger' => 'large_transaction',
                'is_read' => false,
            ],
            [
                'user_id' => 1,
                'type' => 'custom',
                'message' => 'Pensez à mettre à jour vos objectifs financiers ce mois-ci.',
                'trigger' => 'manual',
                'is_read' => true,
            ],
        ];

        Alert::insert($alerts);
    }
}
