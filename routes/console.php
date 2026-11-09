<?php

use App\Models\User;
use App\Services\PriceService;
use App\Services\RecurringService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('finance:post-recurring', function () {
    $total = 0;
    User::each(function (User $u) use (&$total) {
        $total += RecurringService::postDue($u);
    });
    $this->info("$total échéance(s) passée(s) en écriture.");
})->purpose('Passe en écriture les opérations récurrentes arrivées à échéance');

Artisan::command('finance:refresh-prices', function () {
    User::each(function (User $u) {
        $r = PriceService::refresh($u);
        $this->line("{$u->email} : {$r['updated']} cours mis à jour".($r['failed'] ? ', échecs : '.implode(', ', $r['failed']) : ''));
    });
})->purpose('Met à jour les cours (Yahoo Finance, CoinGecko)');

Artisan::command('finance:purge-test-accounts', function () {
    // Comptes créés par les tests de bout en bout (domaine réservé example.com, jamais un vrai utilisateur).
    $n = User::where('email', 'like', 'test-claude-%@example.com')->delete();
    $this->info("$n compte(s) de test supprimé(s).");
})->purpose('Supprime les comptes créés par les tests automatisés');

Schedule::command('finance:post-recurring')->dailyAt('06:00');
Schedule::command('finance:refresh-prices')->weekdays()->hourlyAt(17)->between('9:00', '22:00');
