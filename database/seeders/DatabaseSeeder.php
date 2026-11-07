<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Alert;
use App\Models\Asset;
use App\Models\AssetPrice;
use App\Models\Category;
use App\Models\User;
use App\Services\DefaultCategories;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de démonstration : 14 mois d'historique réaliste.
 * Connexion : demo@finance.test / password
 */
class DatabaseSeeder extends Seeder
{
    private User $user;

    /** @var array<string, int> */
    private array $cat = [];

    private array $tx = [];

    private array $ops = [];

    public function run(): void
    {
        mt_srand(42);
        User::where('email', 'demo@finance.test')->delete();
        $this->user = User::create([
            'name' => 'Compte démo', 'email' => 'demo@finance.test', 'password' => 'password',
            'savings_rate_target' => 25, 'large_transaction_threshold' => 400,
        ]);
        DefaultCategories::seed($this->user);
        $this->cat = Category::where('user_id', $this->user->id)->pluck('id', 'name')->all();

        $today = Carbon::today();
        $start = $today->copy()->startOfMonth()->subMonths(13);

        $acc = fn (array $a) => Account::create(array_merge(['user_id' => $this->user->id], $a));
        $checking = $acc(['name' => 'Compte courant', 'type' => 'checking', 'institution' => 'BoursoBank', 'opening_balance' => 2400, 'opened_at' => $start->copy()->subDay(), 'color' => '#1b2a4a']);
        $livretA = $acc(['name' => 'Livret A', 'type' => 'livret_a', 'institution' => 'BoursoBank', 'opening_balance' => 8200, 'opened_at' => '2019-03-12', 'interest_rate' => 1.7, 'color' => '#3d5a80']);
        $ldds = $acc(['name' => 'LDDS', 'type' => 'ldds', 'institution' => 'BoursoBank', 'opening_balance' => 3100, 'opened_at' => '2021-09-01', 'interest_rate' => 1.7, 'color' => '#6a8bb0']);
        $pea = $acc(['name' => 'PEA', 'type' => 'pea', 'institution' => 'BoursoBank', 'opening_balance' => 0, 'opened_at' => $start->copy()->subDay(), 'color' => '#1f7a5c']);
        $cto = $acc(['name' => 'Compte-titres', 'type' => 'cto', 'institution' => 'Trade Republic', 'opening_balance' => 0, 'opened_at' => $start->copy()->subDay(), 'color' => '#a07a2c']);
        $crypto = $acc(['name' => 'Portefeuille crypto', 'type' => 'crypto', 'institution' => 'Coinbase', 'opening_balance' => 0, 'opened_at' => $start->copy()->subDay(), 'color' => '#c9a65a']);
        $acc(['name' => 'Assurance-vie', 'type' => 'assurance_vie', 'institution' => 'Linxea Spirit 2', 'opening_balance' => 0, 'manual_value' => 12850, 'opened_at' => '2020-06-15', 'color' => '#5c4b8a']);
        $loan = $acc(['name' => 'Prêt auto', 'type' => 'loan', 'institution' => 'Cetelem', 'opening_balance' => -9600, 'opened_at' => $start->copy()->subDay(), 'interest_rate' => 4.2, 'color' => '#b23a3a']);

        // Actifs + historique de prix mensuel (marche aléatoire reproductible)
        $assets = [];
        $mk = function (array $a, float $drift, float $vol) use (&$assets, $today) {
            $asset = Asset::create(array_merge(['user_id' => $this->user->id, 'price_updated_at' => now()], $a));
            $prices = [];
            $p = $a['current_price'];
            for ($m = 0; $m <= 14; $m++) {
                $d = $m === 0 ? $today->copy() : $today->copy()->startOfMonth()->subMonths($m - 1)->subDay();
                $prices[$d->format('Y-m-d')] = round($p, 6);
                $p = $p / (1 + $drift + (mt_rand(-100, 100) / 100) * $vol);
            }
            foreach ($prices as $date => $price) {
                AssetPrice::create(['asset_id' => $asset->id, 'date' => $date, 'price' => $price, 'fx_rate' => $a['fx_rate'] ?? 1]);
            }
            ksort($prices);
            $assets[$a['symbol']] = ['model' => $asset, 'prices' => $prices];
        };
        $mk(['symbol' => 'CW8', 'name' => 'Amundi MSCI World UCITS ETF', 'asset_class' => 'etf', 'isin' => 'LU1681043599', 'price_source' => 'yahoo', 'source_id' => 'CW8.PA', 'current_price' => 598.40, 'region' => 'Monde', 'sector' => 'Diversifié', 'pea_eligible' => true], 0.008, 0.03);
        $mk(['symbol' => 'ESE', 'name' => 'BNP Paribas Easy S&P 500 UCITS ETF', 'asset_class' => 'etf', 'isin' => 'FR0011550185', 'price_source' => 'yahoo', 'source_id' => 'ESE.PA', 'current_price' => 29.85, 'region' => 'États-Unis', 'sector' => 'Diversifié', 'pea_eligible' => true], 0.01, 0.035);
        $mk(['symbol' => 'PAEEM', 'name' => 'Amundi PEA Emerging Markets ESG', 'asset_class' => 'etf', 'isin' => 'FR0013412020', 'price_source' => 'yahoo', 'source_id' => 'PAEEM.PA', 'current_price' => 25.10, 'region' => 'Émergents', 'sector' => 'Diversifié', 'pea_eligible' => true], 0.004, 0.04);
        $mk(['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'asset_class' => 'stock', 'isin' => 'US0378331005', 'currency' => 'USD', 'fx_rate' => 0.87, 'price_source' => 'yahoo', 'source_id' => 'AAPL', 'current_price' => 248.00, 'region' => 'États-Unis', 'sector' => 'Technologie'], 0.012, 0.06);
        $mk(['symbol' => 'MC', 'name' => 'LVMH Moët Hennessy', 'asset_class' => 'stock', 'isin' => 'FR0000121014', 'price_source' => 'yahoo', 'source_id' => 'MC.PA', 'current_price' => 612.00, 'region' => 'Europe', 'sector' => 'Luxe'], 0.002, 0.06);
        $mk(['symbol' => 'BTC', 'name' => 'Bitcoin', 'asset_class' => 'crypto', 'price_source' => 'coingecko', 'source_id' => 'bitcoin', 'current_price' => 88500, 'region' => 'Monde', 'sector' => 'Crypto'], 0.025, 0.12);
        $mk(['symbol' => 'ETH', 'name' => 'Ethereum', 'asset_class' => 'crypto', 'price_source' => 'coingecko', 'source_id' => 'ethereum', 'current_price' => 3150, 'region' => 'Monde', 'sector' => 'Crypto'], 0.02, 0.15);

        $priceAt = function (string $sym, Carbon $d) use (&$assets): float {
            $found = null;
            foreach ($assets[$sym]['prices'] as $date => $p) {
                if ($date <= $d->format('Y-m-d')) {
                    $found = $p;
                }
            }

            return $found ?? reset($assets[$sym]['prices']);
        };
        $buy = function (Account $a, string $sym, Carbon $d, float $budget, float $fees = 0) use ($priceAt, &$assets) {
            $asset = $assets[$sym]['model'];
            $price = $priceAt($sym, $d) * (1 + mt_rand(-20, 20) / 1000);
            $fx = $asset->fx_rate ?: 1;
            $qty = $asset->asset_class === 'crypto' ? round(($budget - $fees) / ($price * $fx), 8) : floor(($budget - $fees) / ($price * $fx));
            if ($qty <= 0) {
                return;
            }
            $this->op($a, $asset->id, 'buy', $d, $qty, round($price, 4), $fx, $fees);
        };

        $C = $this->cat;
        for ($m = 0; $m <= 13; $m++) {
            $month = $start->copy()->addMonths($m);
            $day = fn (int $d) => $month->copy()->day(min($d, $month->daysInMonth));
            $include = fn (Carbon $d) => $d->lte($today);
            $add = function (Account $a, string $type, float $amount, Carbon $d, ?string $desc, ?string $cat = null, ?Account $to = null) use ($include, $C) {
                if ($include($d)) {
                    $this->tx($a, $type, $amount, $d, $desc, $cat ? $C[$cat] : null, $to);
                }
            };

            // Revenus
            $add($checking, 'income', 3450, $day(1), 'VIR SALAIRE ACME CONSULTING', 'Salaire');
            if ($month->month === 12) {
                $add($checking, 'income', 1800, $day(20), 'VIR PRIME ANNUELLE ACME', 'Primes & bonus');
            }
            if ($m % 3 === 1) {
                $add($checking, 'income', mt_rand(55, 95) * 10, $day(18), 'VIR MISSION FREELANCE AMOA', 'Freelance');
            }
            if ($m % 4 === 2) {
                $add($checking, 'income', round(mt_rand(1500, 6000) / 100, 2), $day(14), 'REMBOURSEMENT AMELI', 'Remboursements');
            }
            if ($month->month === 1) {
                $add($livretA, 'income', 139.40, $day(1), 'INTERETS LIVRET A', 'Autres revenus');
                $add($ldds, 'income', 52.70, $day(1), 'INTERETS LDDS', 'Autres revenus');
            }

            // Charges fixes
            $add($checking, 'expense', 980, $day(3), 'PRLV LOYER FONCIA', 'Logement');
            $add($checking, 'expense', 86.40, $day(2), 'NAVIGO ANNUEL RATP', 'Transport');
            $add($checking, 'expense', round(62 + mt_rand(0, 2400) / 100, 2), $day(8), 'PRLV EDF CLIENTS PARTICULIERS', 'Énergie & eau');
            $add($checking, 'expense', 19.99, $day(10), 'PRLV FREE MOBILE', 'Télécom & internet');
            $add($checking, 'expense', 29.99, $day(12), 'PRLV FREEBOX', 'Télécom & internet');
            $add($checking, 'expense', 13.49, $day(15), 'NETFLIX.COM', 'Abonnements');
            $add($checking, 'expense', 11.12, $day(16), 'SPOTIFY', 'Abonnements');
            $add($checking, 'expense', 22.99, $day(19), 'OPENAI CHATGPT SUBSCR', 'Abonnements');
            $add($checking, 'expense', 17.50, $day(5), 'PRLV ASSURANCE HABITATION MAIF', 'Assurances');
            $add($checking, 'expense', 42.30, $day(6), 'PRLV MUTUELLE ALAN', 'Santé');
            $add($checking, 'expense', 34.90, $day(4), 'PRLV BASIC FIT', 'Sport & bien-être');
            if ($month->month === 9) {
                $add($checking, 'expense', 742, $day(15), 'DGFIP TAXE FONCIERE', 'Impôts & taxes');
            }

            // Courses, restaurants, loisirs (variables)
            $shops = ['CB CARREFOUR MARKET', 'CB LIDL', 'CB MONOPRIX', 'CB PICARD', 'CB FRANPRIX', 'CB BIOCOOP'];
            for ($i = 0; $i < mt_rand(6, 9); $i++) {
                $add($checking, 'expense', round(mt_rand(1800, 9500) / 100, 2), $day(mt_rand(1, 28)), $shops[array_rand($shops)]);
            }
            $restos = ['CB DELIVEROO', 'CB UBER EATS', 'CB LE PETIT BISTROT', 'CB STARBUCKS', 'CB BRASSERIE LIPP', 'CB SUSHI SHOP'];
            for ($i = 0; $i < mt_rand(4, 8); $i++) {
                $add($checking, 'expense', round(mt_rand(850, 5200) / 100, 2), $day(mt_rand(1, 28)), $restos[array_rand($restos)]);
            }
            $shopping = ['CB AMAZON EU', 'CB FNAC', 'CB DECATHLON', 'CB ZARA', 'CB IKEA'];
            for ($i = 0; $i < mt_rand(1, 3); $i++) {
                $add($checking, 'expense', round(mt_rand(1500, 16000) / 100, 2), $day(mt_rand(1, 28)), $shopping[array_rand($shopping)]);
            }
            $add($checking, 'expense', round(mt_rand(1100, 4500) / 100, 2), $day(mt_rand(5, 25)), 'CB UGC CINE CITE', 'Loisirs & culture');
            if (mt_rand(0, 2) === 0) {
                $add($checking, 'expense', round(mt_rand(800, 3500) / 100, 2), $day(mt_rand(1, 28)), 'CB PHARMACIE DU MARCHE');
            }
            if (in_array($month->month, [7, 8], true)) {
                $add($checking, 'expense', 684, $day(6), 'AIR FRANCE BILLETS', 'Voyages');
                $add($checking, 'expense', 512, $day(20), 'AIRBNB', 'Voyages');
            }
            if ($month->month === 12) {
                $add($checking, 'expense', 310, $day(18), 'CB GALERIES LAFAYETTE CADEAUX', 'Cadeaux & dons');
            }

            // Épargne et investissement automatisés
            $add($checking, 'transfer', 320, $day(5), 'ECHEANCE PRET AUTO', null, $loan);
            $add($checking, 'transfer', 200, $day(2), 'VIR EPARGNE LIVRET A', null, $livretA);
            $add($checking, 'transfer', 500, $day(2), 'VIR PEA MENSUEL', null, $pea);
            $add($checking, 'transfer', 150, $day(2), 'VIR COINBASE', null, $crypto);
            $add($checking, 'transfer', 250, $day(2), 'VIR TRADE REPUBLIC', null, $cto);

            $d3 = $day(3);
            if ($include($d3)) {
                if ($m % 2 === 0) {
                    $buy($pea, 'CW8', $d3, 625, 1.99);
                } else {
                    $buy($pea, 'ESE', $d3, 230, 1);
                    $buy($pea, 'PAEEM', $d3, 140, 1);
                }
                $buy($crypto, 'BTC', $d3, 100, 0.99);
                $buy($crypto, 'ETH', $d3, 49, 0.49);
                if ($m % 4 === 0) {
                    $buy($cto, 'AAPL', $d3, 249, 1);
                } elseif ($m % 4 === 2) {
                    $buy($cto, 'MC', $d3, 640, 1);
                }
            }
            // Dividendes Apple (trimestriels) et LVMH (annuel)
            $dd = $day(15);
            if ($m > 1 && $m % 3 === 0 && $include($dd)) {
                $this->op($cto, $assets['AAPL']['model']->id, 'dividend', $dd, 0, 0, 1, 0, 0.6);
            }
            if ($month->month === 4 && $include($dd)) {
                $this->op($cto, $assets['MC']['model']->id, 'dividend', $dd, 0, 0, 1, 0, 5.2);
            }
        }

        // Une vente partielle pour illustrer les plus-values réalisées
        $sellDate = $start->copy()->addMonths(10)->day(20);
        if ($sellDate->lte($today)) {
            $this->op($crypto, $assets['ETH']['model']->id, 'sell', $sellDate, 0.01, $priceAt('ETH', $sellDate), 1, 0.3);
        }

        foreach (array_chunk($this->tx, 300) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }
        foreach (array_chunk($this->ops, 300) as $chunk) {
            DB::table('investment_operations')->insert($chunk);
        }

        // Catégorisation des libellés non catégorisés par les règles
        $categorizer = new \App\Services\Categorizer($this->user);
        foreach (\App\Models\Transaction::where('user_id', $this->user->id)->whereNull('category_id')->where('type', '!=', 'transfer')->get() as $t) {
            if ($id = $categorizer->guess($t->description, $t->type)) {
                $t->update(['category_id' => $id]);
            }
        }

        // Budgets mensuels
        foreach (['Courses' => 450, 'Restaurants & sorties' => 180, 'Shopping' => 150, 'Loisirs & culture' => 60, 'Transport' => 110, 'Abonnements' => 50] as $name => $amount) {
            $this->user->budgets()->create(['category_id' => $C[$name], 'amount' => $amount, 'alert_threshold' => 85, 'rollover' => $name === 'Shopping']);
        }

        // Récurrences (prochaine échéance après aujourd'hui)
        $next = fn (int $d) => ($today->day < $d ? $today->copy()->day($d) : $today->copy()->addMonthNoOverflow()->day($d))->format('Y-m-d');
        $rec = fn (array $r) => $this->user->recurring()->create(array_merge(['account_id' => $checking->id, 'frequency' => 'monthly'], $r));
        $rec(['type' => 'income', 'amount' => 3450, 'description' => 'VIR SALAIRE ACME CONSULTING', 'category_id' => $C['Salaire'], 'next_date' => $next(1)]);
        $rec(['type' => 'expense', 'amount' => 980, 'description' => 'PRLV LOYER FONCIA', 'category_id' => $C['Logement'], 'next_date' => $next(3)]);
        $rec(['type' => 'expense', 'amount' => 13.49, 'description' => 'NETFLIX.COM', 'category_id' => $C['Abonnements'], 'next_date' => $next(15), 'is_subscription' => true]);
        $rec(['type' => 'expense', 'amount' => 11.12, 'description' => 'SPOTIFY', 'category_id' => $C['Abonnements'], 'next_date' => $next(16), 'is_subscription' => true]);
        $rec(['type' => 'expense', 'amount' => 34.90, 'description' => 'PRLV BASIC FIT', 'category_id' => $C['Sport & bien-être'], 'next_date' => $next(4), 'is_subscription' => true]);
        $rec(['type' => 'transfer', 'amount' => 500, 'description' => 'VIR PEA MENSUEL', 'transfer_account_id' => $pea->id, 'next_date' => $next(2)]);
        $rec(['type' => 'transfer', 'amount' => 320, 'description' => 'ECHEANCE PRET AUTO', 'transfer_account_id' => $loan->id, 'next_date' => $next(5), 'end_date' => $today->copy()->addMonths(26)->format('Y-m-d')]);
        $rec(['type' => 'transfer', 'amount' => 200, 'description' => 'VIR EPARGNE LIVRET A', 'transfer_account_id' => $livretA->id, 'next_date' => $next(2)]);
        $rec(['type' => 'transfer', 'amount' => 150, 'description' => 'VIR COINBASE', 'transfer_account_id' => $crypto->id, 'next_date' => $next(2)]);

        // Objectifs
        $this->user->goals()->create(['name' => 'Épargne de précaution (6 mois)', 'target_amount' => 15000, 'account_id' => $livretA->id, 'priority' => 1, 'color' => '#3d5a80']);
        $this->user->goals()->create(['name' => 'Apport immobilier Luxembourg', 'target_amount' => 60000, 'current_amount' => 9400, 'target_date' => $today->copy()->addYears(4)->format('Y-m-d'), 'priority' => 2, 'color' => '#1f7a5c']);
        $this->user->goals()->create(['name' => 'Voyage au Japon', 'target_amount' => 4500, 'current_amount' => 1650, 'target_date' => $today->copy()->addMonths(9)->format('Y-m-d'), 'priority' => 3, 'color' => '#a07a2c']);

        Alert::create(['user_id' => $this->user->id, 'type' => 'custom', 'level' => 'info', 'message' => 'Bienvenue ! Ce compte de démonstration contient 14 mois de données.', 'trigger' => 'manual']);
    }

    private function tx(Account $a, string $type, float $amount, Carbon $d, ?string $desc, ?int $cat, ?Account $to): void
    {
        $now = now();
        $this->tx[] = [
            'user_id' => $this->user->id, 'account_id' => $a->id, 'category_id' => $type === 'transfer' ? null : $cat,
            'transfer_account_id' => $to?->id, 'type' => $type, 'amount' => $amount, 'date' => $d->format('Y-m-d'),
            'description' => $desc, 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function op(Account $a, int $assetId, string $type, Carbon $d, float $qty, float $price, float $fx, float $fees, float $amount = 0): void
    {
        $now = now();
        $this->ops[] = [
            'user_id' => $this->user->id, 'account_id' => $a->id, 'asset_id' => $assetId, 'type' => $type,
            'quantity' => $qty, 'price' => $price, 'fx_rate' => $fx, 'fees' => $fees, 'amount' => $amount,
            'date' => $d->format('Y-m-d'), 'created_at' => $now, 'updated_at' => $now,
        ];
    }
}
