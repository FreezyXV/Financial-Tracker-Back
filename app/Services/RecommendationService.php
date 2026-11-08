<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

/**
 * Plan d'action personnalisé : croise le profil déclaré (âge, statut, pays,
 * objectifs, profil de risque) avec les données réelles (soldes, dépenses,
 * dettes, portefeuille). Chaque recommandation est chiffrée et actionnable.
 */
class RecommendationService
{
    private const LIVRET_A_CEILING = 22950;

    private const LDDS_CEILING = 12000;

    public static function for(User $user, ?WealthEngine $engine = null): array
    {
        $p = $user->profile ?? [];
        if (! $p) {
            return [[
                'id' => 'onboarding', 'priority' => 0, 'level' => 'info',
                'title' => 'Personnalisez votre registre',
                'text' => 'Répondez au questionnaire (3 minutes) pour obtenir des conseils adaptés à votre situation.',
                'action' => ['label' => 'Commencer', 'route' => '/onboarding'],
            ]];
        }

        $engine ??= WealthEngine::for($user);
        $reports = new ReportService($user, $engine);
        $fmt = fn (float $v) => number_format($v, 0, ',', "\u{202F}").' €';
        $out = [];
        $add = function (string $id, int $priority, string $level, string $title, string $text, ?string $label = null, ?string $route = null) use (&$out) {
            $out[] = ['id' => $id, 'priority' => $priority, 'level' => $level, 'title' => $title, 'text' => $text,
                'action' => $label ? ['label' => $label, 'route' => $route] : null];
        };

        // Dépenses de référence : historique réel (3 mois complets) sinon estimation déclarée
        $cash = array_slice($reports->cashflow(4), 0, 3);
        $withData = array_filter($cash, fn ($m) => $m['expense'] > 0);
        $avgExpense = $withData ? array_sum(array_column($withData, 'expense')) / count($withData) : OnboardingService::estimatedExpenses($p);
        $avgIncome = $withData ? array_sum(array_column($withData, 'income')) / count($withData) : (float) ($p['monthly_income'] ?? 0);
        $avgSavings = $avgIncome - $avgExpense;

        $nw = $engine->netWorth();
        $liquid = ($nw['by_group']['liquidites'] ?? 0) + ($nw['by_group']['epargne'] ?? 0);
        $country = $p['country'] ?? 'FR';
        $age = ! empty($p['birth_year']) ? (int) date('Y') - (int) $p['birth_year'] : null;
        $risk = $p['risk'] ?? 'balanced';
        $goals = $p['goals'] ?? [];
        $byType = [];
        foreach ($engine->accounts as $a) {
            if (! $a->is_archived) {
                $byType[$a->type][] = $a;
            }
        }

        // 1. Épargne de précaution
        $months = OnboardingService::emergencyMonths($p);
        $target = $avgExpense * $months;
        if ($avgExpense > 0 && $liquid < $target) {
            $cover = $liquid / $avgExpense;
            $reasons = [];
            if (in_array($p['employment'] ?? '', OnboardingService::IRREGULAR, true) || ! empty($p['income_variable'])) {
                $reasons[] = 'revenus irréguliers';
            }
            if (($p['children'] ?? 0) > 0) {
                $reasons[] = 'enfants à charge';
            }
            if (($p['housing'] ?? '') === 'owner_mortgage') {
                $reasons[] = 'crédit immobilier';
            }
            $why = $reasons ? ' compte tenu de vos '.implode(', ', $reasons) : '';
            $add('emergency', 10, $cover < 1 ? 'danger' : 'warning', 'Sécurisez votre épargne de précaution',
                sprintf('Vos liquidités couvrent %s mois de dépenses ; %d mois sont conseillés%s. Il manque %s, à placer sur un livret disponible avant tout investissement risqué.',
                    number_format($cover, 1, ',', ''), $months, $why, $fmt($target - $liquid)), 'Voir mes objectifs', '/goals');
        }

        // 2. Dettes chères
        foreach ($byType['loan'] ?? [] as $loan) {
            $rate = (float) $loan->interest_rate;
            $due = -$engine->accountValue($loan);
            if ($rate >= 4 && $due > 0) {
                $add('debt-'.$loan->id, 15, 'warning', "Remboursez en priorité : {$loan->name}",
                    sprintf('À %s %%, chaque euro remboursé par anticipation rapporte %s %% sans risque, mieux qu\'un livret. Capital restant : %s.',
                        number_format($rate, 1, ',', ''), number_format($rate, 1, ',', ''), $fmt($due)), 'Voir le compte', '/accounts');
            }
        }

        // 3. Taux d'épargne
        $targetRate = (float) $user->savings_rate_target;
        if ($avgIncome > 0) {
            $rate = $avgSavings / $avgIncome * 100;
            if ($rate < $targetRate) {
                $gap = $avgIncome * $targetRate / 100 - $avgSavings;
                $add('savings-rate', 20, $rate < 0 ? 'danger' : 'warning', 'Rapprochez-vous de votre taux d\'épargne cible',
                    sprintf('Vous épargnez %s %% de vos revenus pour un objectif de %s %%. Il faut dégager %s de plus par mois : vos budgets montrent où.',
                        number_format($rate, 0, ',', ''), number_format($targetRate, 0, ',', ''), $fmt($gap)), 'Mes budgets', '/budgets');
            }
        }

        // 4. Objectifs datés vs capacité d'épargne
        $engineGoals = Goal::where('user_id', $user->id)->get();
        $needed = 0.0;
        foreach ($engineGoals as $g) {
            $needed += \App\Http\Controllers\Api\GoalController::progress($g, $engine)['monthly_needed'] ?? 0;
        }
        if ($needed > 0 && $avgSavings > 0 && $needed > $avgSavings * 1.05) {
            $add('goals-capacity', 25, 'warning', 'Vos objectifs dépassent votre capacité d\'épargne',
                sprintf('Vos objectifs datés demandent %s par mois, vous épargnez en moyenne %s. Repoussez une échéance ou priorisez.', $fmt($needed), $fmt($avgSavings)),
                'Ajuster mes objectifs', '/goals');
        }

        // 5. Livrets pleins (France)
        if ($country === 'FR') {
            foreach ($byType['livret_a'] ?? [] as $l) {
                if ($engine->cashBalance($l) >= self::LIVRET_A_CEILING * 0.95) {
                    $next = isset($byType['ldds']) ? 'votre PEA ou assurance-vie' : 'un LDDS (12 000 €), puis un PEA';
                    $add('livret-full', 30, 'info', 'Livret A au plafond', "Les nouveaux versements ne sont plus possibles : orientez l'épargne vers {$next}.", 'Mes comptes', '/accounts');
                }
            }
            foreach ($byType['ldds'] ?? [] as $l) {
                if ($engine->cashBalance($l) >= self::LDDS_CEILING * 0.95 && $liquid >= $target) {
                    $add('ldds-full', 32, 'info', 'LDDS au plafond', 'Votre épargne de précaution est constituée : le surplus a vocation à être investi à long terme.', 'Placements', '/investments');
                }
            }
        }

        // 6. Excédent de liquidités pour un profil long terme
        $surplus = $liquid - max($target, 3000) * 1.5;
        if ($surplus > 3000 && $risk !== 'prudent' && ($p['horizon'] ?? 'long') !== 'short') {
            $monthly = (float) ($p['monthly_invest'] ?? 0) ?: round($surplus / 12, -1);
            $add('idle-cash', 35, 'info', sprintf('%s dorment au-delà de votre sécurité', $fmt($surplus)),
                sprintf('Avec un profil %s et un horizon long, investir progressivement (environ %s par mois sur un ETF monde) lisse le risque d\'entrée.',
                    $risk === 'dynamic' ? 'dynamique' : 'équilibré', $fmt($monthly)), 'Simuler', '/simulator');
        }

        // 7. Enveloppes françaises à ouvrir pour prendre date
        if ($country === 'FR' && ($age === null || $age < 60) && ($p['horizon'] ?? 'long') !== 'short') {
            if (! isset($byType['pea']) && ($p['experience'] ?? 'none') !== 'none') {
                $add('open-pea', 40, 'info', 'Ouvrez un PEA dès maintenant', 'Le compteur fiscal de 5 ans démarre à l\'ouverture : ouvrir aujourd\'hui avec quelques euros, c\'est gagner du temps sur l\'exonération d\'impôt des plus-values.', 'Ajouter le compte', '/accounts');
            }
            if (! isset($byType['assurance_vie']) && ($age === null || $age >= 25)) {
                $add('open-av', 55, 'info', 'Prenez date avec une assurance-vie', 'Après 8 ans, les gains bénéficient d\'un abattement annuel. Une assurance-vie en ligne sans frais sur versements suffit pour lancer le compteur.', 'Ajouter le compte', '/accounts');
            }
        }

        // 8. PER si tranche marginale élevée
        $tmi = OnboardingService::estimatedTmi($p);
        if ($tmi !== null && $tmi >= 30 && (in_array('retirement', $goals, true) || in_array('fire', $goals, true)) && ! isset($byType['per'])) {
            $add('per', 45, 'info', 'Le PER est intéressant pour vous',
                sprintf('Avec une tranche marginale estimée à %d %%, 1 000 € versés sur un PER réduisent votre impôt d\'environ %s (somme bloquée jusqu\'à la retraite, sauf achat de la résidence principale).', $tmi, $fmt(1000 * $tmi / 100)),
                'Projection', '/simulator');
        }

        // 9. Concentration du portefeuille
        $portfolio = $engine->portfolio();
        foreach ($portfolio['positions'] as $pos) {
            if (($pos['weight'] ?? 0) > 35 && $pos['asset_class'] !== 'etf' && $portfolio['totals']['value'] > 2000) {
                $add('concentration-'.$pos['asset_id'], 50, 'warning', "Portefeuille concentré sur {$pos['symbol']}",
                    sprintf('%s pèse %s %% de vos placements. Pour un profil %s, une ligne individuelle au-delà de 10–20 %% augmente fortement le risque.',
                        $pos['name'], number_format($pos['weight'], 0, ',', ''), ['prudent' => 'prudent', 'balanced' => 'équilibré', 'dynamic' => 'dynamique'][$risk] ?? 'équilibré'),
                    'Positions', '/investments');
            }
        }
        if ($risk === 'prudent' && $portfolio['totals']['value'] > 0) {
            $crypto = $portfolio['allocation']['by_class']['crypto'] ?? 0;
            if ($crypto / max(1, $portfolio['totals']['value']) > 0.1) {
                $add('crypto-prudent', 52, 'warning', 'Exposition crypto élevée pour un profil prudent', 'Plus de 10 % de vos placements sont en crypto, un actif très volatil. Vérifiez que c\'est cohérent avec votre tolérance au risque.', 'Placements', '/investments');
            }
        }

        // 10. Situation internationale
        if ($country === 'LU') {
            $add('lu', 60, 'info', 'Résident luxembourgeois', 'Les versements sur un contrat d\'épargne-pension (art. 111bis LIR) sont déductibles dans la limite du plafond annuel. Vos comptes ouverts en France restent à déclarer.', null, null);
        }
        if (in_array('expat', $goals, true) && $country === 'FR') {
            $add('expat', 60, 'info', 'Préparer votre expatriation', 'Depuis la loi Pacte, un PEA peut rester ouvert après un départ à l\'étranger (hors pays non coopératifs). Prévoyez 3 mois de dépenses pour l\'installation et les dépôts de garantie.', 'Objectifs', '/goals');
        }

        // 11. Revenus variables
        if (! empty($p['income_variable']) || in_array($p['employment'] ?? '', ['freelance', 'business_owner'], true)) {
            $add('variable-income', 65, 'info', 'Lissez vos revenus variables', 'Versez-vous un « salaire » fixe depuis un compte tampon et mettez de côté vos cotisations et impôts à chaque encaissement : vos budgets deviennent prévisibles.', 'Échéances', '/recurring');
        }

        // 12. Hygiène de suivi
        $uncategorized = Transaction::where('user_id', $user->id)->whereNull('category_id')->where('type', '!=', 'transfer')->count();
        if ($uncategorized >= 5) {
            $add('categorize', 70, 'info', "{$uncategorized} opérations à classer", 'Vos bilans et budgets sont plus justes quand chaque dépense a une catégorie. Une correction suffit : les libellés identiques suivront.', 'Classer', '/transactions?category_id=none');
        }
        if (! Budget::where('user_id', $user->id)->exists() && ($p['budget_method'] ?? '') !== 'none') {
            $add('no-budget', 72, 'info', 'Aucun budget défini', 'Fixez 3 enveloppes sur vos postes variables (courses, sorties, shopping) pour piloter votre taux d\'épargne.', 'Créer un budget', '/budgets');
        }
        if (! Transaction::where('user_id', $user->id)->where('date', '>=', Carbon::today()->subDays(30)->format('Y-m-d'))->exists()) {
            $add('no-data', 5, 'info', 'Ajoutez vos premières opérations', 'Importez un relevé CSV de votre banque : catégorisation automatique et premier bilan en une minute.', 'Importer', '/transactions');
        }

        usort($out, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return $out;
    }
}
