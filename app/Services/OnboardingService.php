<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Transforme les réponses du questionnaire d'accueil en un espace personnalisé :
 * profil, comptes, catégories propres à la situation, échéances, objectifs, budgets.
 * Idempotent : relancer le questionnaire ne crée pas de doublons.
 */
class OnboardingService
{
    /** Statuts aux revenus irréguliers : coussin de sécurité plus large. */
    public const IRREGULAR = ['freelance', 'business_owner', 'cdd', 'unemployed', 'student'];

    public const ACCOUNT_NAMES = [
        'checking' => 'Compte courant', 'savings' => 'Compte épargne', 'livret_a' => 'Livret A',
        'ldds' => 'LDDS', 'lep' => 'LEP', 'pel' => 'PEL', 'pea' => 'PEA', 'pea_pme' => 'PEA-PME',
        'cto' => 'Compte-titres', 'assurance_vie' => 'Assurance-vie', 'per' => 'PER',
        'crypto' => 'Crypto', 'real_estate' => 'Résidence principale', 'loan' => 'Crédit en cours', 'cash' => 'Espèces',
        'other' => 'Autre compte',
    ];

    public static function emergencyMonths(array $p): int
    {
        $months = in_array($p['employment'] ?? '', self::IRREGULAR, true) || ! empty($p['income_variable']) ? 6 : 3;
        if (($p['children'] ?? 0) > 0 || ($p['housing'] ?? '') === 'owner_mortgage') {
            $months += 1;
        }

        return $months;
    }

    /** Dépenses mensuelles estimées tant qu'il n'y a pas d'historique. */
    public static function estimatedExpenses(array $p): float
    {
        $income = (float) ($p['monthly_income'] ?? 0);
        $fixed = (float) ($p['housing_cost'] ?? 0) + (float) ($p['other_fixed_costs'] ?? 0);
        $variable = (float) ($p['variable_spending'] ?? 0);
        $est = $fixed + $variable;

        return $est > 0 ? $est : round($income * 0.7, 2);
    }

    /** Nombre de parts fiscales (barème français, approximation). */
    public static function taxParts(array $p): float
    {
        $parts = in_array($p['household'] ?? 'single', ['couple', 'family'], true) ? 2.0 : 1.0;
        $children = (int) ($p['children'] ?? 0);
        $parts += min(2, $children) * 0.5 + max(0, $children - 2) * 1.0;
        if ($parts === 1.0 && $children > 0) {
            $parts += 0.5; // parent isolé
        }

        return $parts;
    }

    /** Tranche marginale d'imposition estimée (barème appliqué aux revenus 2024). */
    public static function estimatedTmi(array $p): ?int
    {
        if (($p['country'] ?? 'FR') !== 'FR' || empty($p['monthly_income'])) {
            return null;
        }
        $taxable = (float) $p['monthly_income'] * 12 * 0.9 / self::taxParts($p);
        foreach ([[180294, 45], [83823, 41], [29315, 30], [11497, 11]] as [$threshold, $rate]) {
            if ($taxable > $threshold) {
                return $rate;
            }
        }

        return 0;
    }

    public static function apply(User $user, array $p, array $accounts, array $options): array
    {
        $created = ['accounts' => 0, 'categories' => 0, 'recurring' => 0, 'goals' => 0, 'budgets' => 0];

        DB::transaction(function () use ($user, $p, $accounts, $options, &$created) {
            $income = (float) ($p['monthly_income'] ?? 0);
            $goals = $p['goals'] ?? [];

            $user->profile = array_merge($user->profile ?? [], $p);
            $user->onboarded_at = now();
            $user->savings_rate_target = match (true) {
                in_array('fire', $goals, true) => 40,
                $income > 0 && $income < 1800 => 10,
                in_array('home', $goals, true) || in_array('expat', $goals, true) => 25,
                default => 20,
            };
            if ($income > 0) {
                $user->large_transaction_threshold = max(100, round($income * 0.15, -1));
            }
            $user->save();

            if (! $user->categories()->exists()) {
                DefaultCategories::seed($user);
            }
            $created['categories'] = self::personalCategories($user, $p);

            // Comptes déclarés
            $byType = [];
            foreach ($accounts as $a) {
                $type = $a['type'];
                $name = trim($a['name'] ?? '') ?: self::ACCOUNT_NAMES[$type] ?? 'Compte';
                $balance = (float) ($a['balance'] ?? 0);
                if ($type === 'loan') {
                    $balance = -abs($balance);
                }
                $existing = Account::where('user_id', $user->id)->where('type', $type)->where('name', $name)->first();
                if ($existing) {
                    $byType[$type] ??= $existing;

                    continue;
                }
                $openedAt = ! empty($a['opened_year']) ? Carbon::create((int) $a['opened_year'], 1, 1)->format('Y-m-d') : null;
                $acc = $user->accounts()->create([
                    'name' => $name, 'type' => $type, 'institution' => $a['institution'] ?? null,
                    'opening_balance' => $type === 'real_estate' ? 0 : $balance,
                    'manual_value' => $type === 'real_estate' ? $balance : null,
                    // Sans date connue, le solde déclaré vaut pour tout l'historique (pas de faux « +X € » sur 30 jours).
                    'opened_at' => $openedAt,
                    'interest_rate' => $a['interest_rate'] ?? null,
                    'color' => self::color($type),
                ]);
                $byType[$type] ??= $acc;
                $created['accounts']++;
            }
            $checking = $byType['checking'] ?? Account::where('user_id', $user->id)->where('type', 'checking')->first();
            $cats = Category::where('user_id', $user->id)->pluck('id', 'name');

            // Échéances (prévisions, non passées automatiquement tant que l'utilisateur ne l'a pas validé)
            if (($options['create_recurring'] ?? true) && $checking) {
                $payday = max(1, min(28, (int) ($p['payday'] ?? 1)));
                $next = fn (int $d) => (Carbon::today()->day < $d ? Carbon::today()->day($d) : Carbon::today()->addMonthNoOverflow()->day($d))->format('Y-m-d');
                $rec = function (array $r) use ($user, $checking, &$created) {
                    if (! $user->recurring()->where('description', $r['description'])->exists()) {
                        $user->recurring()->create(array_merge(['account_id' => $checking->id, 'frequency' => 'monthly', 'auto_post' => false], $r));
                        $created['recurring']++;
                    }
                };
                if ($income > 0 && empty($p['income_variable']) && ! in_array($p['employment'] ?? '', ['freelance', 'business_owner', 'unemployed'], true)) {
                    $label = ($p['employment'] ?? '') === 'retired' ? 'Pension de retraite' : 'Salaire';
                    $rec(['type' => 'income', 'amount' => $income, 'description' => $label, 'category_id' => $cats[$label] ?? $cats['Salaire'] ?? null, 'next_date' => $next($payday)]);
                }
                $housing = (float) ($p['housing_cost'] ?? 0);
                if ($housing > 0 && in_array($p['housing'] ?? '', ['tenant', 'owner_mortgage'], true)) {
                    $label = $p['housing'] === 'tenant' ? 'Loyer' : 'Échéance crédit immobilier';
                    $cat = $p['housing'] === 'tenant' ? 'Logement' : 'Crédit immobilier';
                    $rec(['type' => 'expense', 'amount' => $housing, 'description' => $label, 'category_id' => $cats[$cat] ?? $cats['Logement'] ?? null, 'next_date' => $next(min(28, $payday + 4))]);
                }
                $invest = (float) ($p['monthly_invest'] ?? 0);
                $target = $byType['pea'] ?? $byType['cto'] ?? $byType['assurance_vie'] ?? null;
                if ($invest > 0 && $target) {
                    $rec(['type' => 'transfer', 'amount' => $invest, 'description' => 'Investissement programmé '.$target->name, 'transfer_account_id' => $target->id, 'next_date' => $next(min(28, $payday + 1))]);
                }
            }

            // Objectifs
            if ($options['create_goals'] ?? true) {
                $goal = function (array $g) use ($user, &$created) {
                    if (! $user->goals()->where('name', $g['name'])->exists()) {
                        $user->goals()->create($g);
                        $created['goals']++;
                    }
                };
                $months = self::emergencyMonths($p);
                $safe = $byType['livret_a'] ?? $byType['ldds'] ?? $byType['savings'] ?? $byType['lep'] ?? null;
                if (in_array('emergency', $goals, true) || ! $safe) {
                    $goal(['name' => "Épargne de précaution ({$months} mois)", 'target_amount' => max(1000, round(self::estimatedExpenses($p) * $months, -2)), 'account_id' => $safe?->id, 'priority' => 1, 'color' => '#3d5a80']);
                }
                if (in_array('home', $goals, true) && ! empty($p['home_amount'])) {
                    $goal(['name' => 'Apport résidence principale', 'target_amount' => (float) $p['home_amount'], 'target_date' => $p['home_date'] ?? null, 'priority' => 2, 'color' => '#1f7a5c']);
                }
                if (in_array('project', $goals, true) && ! empty($p['project_amount'])) {
                    $goal(['name' => $p['project_name'] ?: 'Projet', 'target_amount' => (float) $p['project_amount'], 'target_date' => $p['project_date'] ?? null, 'priority' => 3, 'color' => '#a07a2c']);
                }
                if (in_array('expat', $goals, true)) {
                    $goal(['name' => 'Fonds de départ à l\'étranger', 'target_amount' => max(3000, round(self::estimatedExpenses($p) * 3, -2)), 'target_date' => $p['expat_date'] ?? null, 'priority' => 2, 'color' => '#5c4b8a']);
                }
                if (in_array('debt', $goals, true) && isset($byType['loan'])) {
                    $goal(['name' => 'Solder '.$byType['loan']->name, 'target_amount' => abs($byType['loan']->opening_balance), 'priority' => 1, 'color' => '#b23a3a']);
                }
            }

            // Budgets calibrés sur les revenus
            if (($options['create_budgets'] ?? true) && $income > 0 && ($p['budget_method'] ?? '50_30_20') !== 'none') {
                $shares = ['Courses' => 0.10, 'Restaurants & sorties' => 0.05, 'Shopping' => 0.04, 'Loisirs & culture' => 0.03,
                    'Transport' => 0.05, 'Abonnements' => 0.015, 'Voyages' => 0.04];
                if (($p['children'] ?? 0) > 0) {
                    $shares['Enfants'] = 0.04 * min(3, (int) $p['children']);
                }
                foreach ($shares as $name => $share) {
                    $cid = $cats[$name] ?? null;
                    if (! $cid || $user->budgets()->where('category_id', $cid)->exists()) {
                        continue;
                    }
                    $user->budgets()->create(['category_id' => $cid, 'amount' => max(10, round($income * $share, -1)), 'rollover' => $name === 'Voyages', 'alert_threshold' => 85]);
                    $created['budgets']++;
                }
            }
        });

        return $created;
    }

    /** Catégories spécifiques à la situation déclarée. */
    private static function personalCategories(User $user, array $p): int
    {
        $wanted = [];
        $employment = $p['employment'] ?? '';
        if (($p['children'] ?? 0) > 0) {
            $wanted[] = ['Enfants', 'expense', '#7a5ca8', true];
            $wanted[] = ['Garde & scolarité', 'expense', '#8d72b5', true];
        }
        if (in_array($employment, ['freelance', 'business_owner'], true)) {
            $wanted[] = ["Chiffre d'affaires", 'income', '#1f7a5c', false];
            $wanted[] = ['Cotisations sociales (URSSAF)', 'expense', '#5c4b8a', true];
            $wanted[] = ['Charges professionnelles', 'expense', '#4f6d94', true];
        }
        if ($employment === 'retired') {
            $wanted[] = ['Pension de retraite', 'income', '#1f7a5c', false];
        }
        if ($employment === 'student') {
            $wanted[] = ['Bourse & aides étudiantes', 'income', '#4aa889', false];
        }
        if (($p['housing'] ?? '') === 'owner_mortgage') {
            $wanted[] = ['Crédit immobilier', 'expense', '#1b2a4a', true];
        }
        if (in_array($p['housing'] ?? '', ['owner', 'owner_mortgage'], true)) {
            $wanted[] = ['Travaux & entretien', 'expense', '#8a6d3b', false];
            $wanted[] = ['Charges de copropriété', 'expense', '#2b3f66', true];
        }
        if (! empty($p['has_pets'])) {
            $wanted[] = ['Animaux', 'expense', '#b8913f', false];
        }
        if (($p['country'] ?? 'FR') !== 'FR' || in_array('expat', $p['goals'] ?? [], true)) {
            $wanted[] = ['Frais internationaux & change', 'expense', '#8a8f98', false];
        }
        $n = 0;
        foreach ($wanted as [$name, $type, $color, $essential]) {
            if (! $user->categories()->where('name', $name)->exists()) {
                $user->categories()->create(['name' => $name, 'type' => $type, 'color' => $color, 'is_essential' => $essential]);
                $n++;
            }
        }

        return $n;
    }

    private static function color(string $type): string
    {
        return match ($type) {
            'checking' => '#1b2a4a', 'livret_a', 'ldds', 'lep', 'pel', 'savings' => '#3d5a80',
            'pea', 'pea_pme' => '#1f7a5c', 'cto' => '#a07a2c', 'assurance_vie', 'per' => '#5c4b8a',
            'crypto' => '#c9a65a', 'loan' => '#b23a3a', 'real_estate' => '#7a5ca8', default => '#6a8bb0',
        };
    }
}
