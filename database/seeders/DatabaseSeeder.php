<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Vérifie si l'utilisateur test existe déjà, sinon le crée
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')] // Optionnel si tu veux ajouter un mot de passe
        );

        // Seed les autres tables
        $this->call([
            CategorySeeder::class,
            BudgetSeeder::class,
            TransactionSeeder::class,
            AlertSeeder::class, // ← ajout ici
        ]);
    }
}
