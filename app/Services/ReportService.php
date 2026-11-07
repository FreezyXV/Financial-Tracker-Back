<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Carbon\Carbon;

/**
 * Bilans mensuels et annuels : revenus, dépenses, épargne, budgets,
 * évolution du patrimoine et constats automatiques.
 */
class ReportService
{
    private WealthEngine $engine;

    /** @var array<int, Category> */
    private array $categories = [];

    public function __construct(private User $user, ?WealthEngine $engine = null)
    {
        $this->engine = $engine ?? WealthEngine::for($user);
        foreach (Category::where('user_id', $user->id)->get() as $c) {
            $this->categories[$c->id] = $c;
        }
    }

    /** Comptes exclus du patrimoine : leurs flux ne comptent pas dans le cashflow. */
    private function excludedAccounts(): array
    {
        $ids = [];
        foreach ($this->engine->accounts as $a) {
            if (! $a->include_in_net_worth) {
                $ids[] = $a->id;
            }
        }

        return $ids;
    }

    /** Agrégats d'une période [from, to]. */
    public function aggregate(string $from, string $to): array
    {
        $excluded = $this->excludedAccounts();
        $income = 0.0;
        $expense = 0.0;
        $essential = 0.0;
        $byCategory = [];
        $invested = 0.0;
        $count = 0;
        foreach ($this->engine->transactions as $t) {
            if ($t->date < $from || $t->date > $to) {
                continue;
            }
            if ($t->type === 'transfer') {
                $src = $this->engine->accounts[$t->account_id] ?? null;
                $dst = $this->engine->accounts[$t->transfer_account_id] ?? null;
                if ($dst && $dst->isInvestment() && (! $src || ! $src->isInvestment())) {
                    $invested += $t->amount;
                }

                continue;
            }
            if (in_array((int) $t->account_id, $excluded, true)) {
                continue;
            }
            $count++;
            $catId = $t->category_id ? (int) $t->category_id : 0;
            $byCategory[$catId] ??= ['income' => 0.0, 'expense' => 0.0, 'count' => 0];
            $byCategory[$catId]['count']++;
            if ($t->type === 'income') {
                $income += $t->amount;
                $byCategory[$catId]['income'] += $t->amount;
            } else {
                $expense += $t->amount;
                $byCategory[$catId]['expense'] += $t->amount;
                if ($catId && ($this->categories[$catId]->is_essential ?? false)) {
                    $essential += $t->amount;
                }
            }
        }

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'savings' => round($income - $expense, 2),
            'savings_rate' => $income > 0 ? round(($income - $expense) / $income * 100, 1) : 0,
            'essential' => round($essential, 2),
            'discretionary' => round($expense - $essential, 2),
            'invested' => round($invested, 2),
            'count' => $count,
            'by_category' => $byCategory,
        ];
    }

    private function categoryInfo(int $id): array
    {
        $c = $this->categories[$id] ?? null;

        return [
            'category_id' => $id ?: null,
            'name' => $c->name ?? 'Non catégorisé',
            'color' => $c->color ?? '#94a3b8',
            'icon' => $c->icon ?? null,
            'type' => $c->type ?? 'expense',
            'is_essential' => (bool) ($c->is_essential ?? false),
        ];
    }

    /** Dépenses d'une catégorie (et de ses sous-catégories) sur un agrégat. */
    private function spentIn(array $agg, int $categoryId): float
    {
        $total = $agg['by_category'][$categoryId]['expense'] ?? 0.0;
        foreach ($this->categories as $c) {
            if ((int) $c->parent_id === $categoryId) {
                $total += $agg['by_category'][$c->id]['expense'] ?? 0.0;
            }
        }

        return $total;
    }

    /** État des budgets pour un mois donné. */
    public function budgets(string $month, ?array $agg = null): array
    {
        $start = Carbon::parse($month.'-01');
        $from = $start->format('Y-m-d');
        $to = $start->copy()->endOfMonth()->format('Y-m-d');
        $agg ??= $this->aggregate($from, $to);
        $daysInMonth = $start->daysInMonth;
        $elapsed = Carbon::today()->format('Y-m') === $month ? Carbon::today()->day : $daysInMonth;

        $rows = [];
        $budgets = Budget::where('user_id', $this->user->id)->get();
        foreach ($budgets as $b) {
            if (($b->start_date && $b->start_date->format('Y-m-d') > $to) || ($b->end_date && $b->end_date->format('Y-m-d') < $from)) {
                continue;
            }
            $limit = $b->amount;
            $carry = 0.0;
            if ($b->rollover) {
                $prev = $start->copy()->subMonth();
                $pAgg = $this->aggregate($prev->format('Y-m-01'), $prev->endOfMonth()->format('Y-m-d'));
                $carry = max(0, $b->amount - $this->spentIn($pAgg, $b->category_id));
                $limit += $carry;
            }
            $spent = $this->spentIn($agg, $b->category_id);
            $pct = $limit > 0 ? $spent / $limit * 100 : 0;
            // Projection linéaire seulement après 10 jours : avant, les prélèvements fixes de début de mois faussent le rythme.
            $projected = $elapsed >= 10 && $elapsed < $daysInMonth ? max($spent, $spent / $elapsed * $daysInMonth) : $spent;
            $status = $pct >= 100 ? 'over' : ($pct >= $b->alert_threshold ? 'warning' : 'ok');
            $rows[] = array_merge($this->categoryInfo($b->category_id), [
                'id' => $b->id,
                'amount' => $b->amount,
                'carry_over' => round($carry, 2),
                'limit' => round($limit, 2),
                'spent' => round($spent, 2),
                'remaining' => round($limit - $spent, 2),
                'percent' => round($pct, 1),
                'projected' => round($projected, 2),
                'status' => $status,
                'rollover' => $b->rollover,
                'alert_threshold' => $b->alert_threshold,
                'start_date' => $b->start_date?->format('Y-m-d'),
                'end_date' => $b->end_date?->format('Y-m-d'),
            ]);
        }
        usort($rows, fn ($a, $b) => $b['percent'] <=> $a['percent']);

        return $rows;
    }

    /** Bilan mensuel complet. */
    public function monthly(string $month): array
    {
        $start = Carbon::parse($month.'-01')->startOfDay();
        $from = $start->format('Y-m-d');
        $to = $start->copy()->endOfMonth()->format('Y-m-d');
        $prevStart = $start->copy()->subMonthNoOverflow();
        $prevTo = $prevStart->copy()->endOfMonth()->format('Y-m-d');

        $cur = $this->aggregate($from, $to);
        $prev = $this->aggregate($prevStart->format('Y-m-d'), $prevTo);

        // Moyenne des 3 mois précédents par catégorie
        $avg3 = [];
        $avgTotals = ['income' => 0, 'expense' => 0];
        for ($i = 1; $i <= 3; $i++) {
            $s = $start->copy()->subMonthsNoOverflow($i);
            $a = $this->aggregate($s->format('Y-m-d'), $s->copy()->endOfMonth()->format('Y-m-d'));
            $avgTotals['income'] += $a['income'] / 3;
            $avgTotals['expense'] += $a['expense'] / 3;
            foreach ($a['by_category'] as $cid => $v) {
                $avg3[$cid] = ($avg3[$cid] ?? 0) + $v['expense'] / 3;
            }
        }

        $categories = [];
        $incomeCats = [];
        foreach ($cur['by_category'] as $cid => $v) {
            $info = $this->categoryInfo((int) $cid);
            if ($v['expense'] > 0) {
                $p = $prev['by_category'][$cid]['expense'] ?? 0;
                $avg = $avg3[$cid] ?? 0;
                $categories[] = array_merge($info, [
                    'amount' => round($v['expense'], 2),
                    'count' => $v['count'],
                    'share' => $cur['expense'] > 0 ? round($v['expense'] / $cur['expense'] * 100, 1) : 0,
                    'previous' => round($p, 2),
                    'average_3m' => round($avg, 2),
                    'delta_pct' => $avg > 0 ? round(($v['expense'] - $avg) / $avg * 100, 1) : null,
                ]);
            }
            if ($v['income'] > 0) {
                $incomeCats[] = array_merge($info, ['amount' => round($v['income'], 2), 'count' => $v['count']]);
            }
        }
        usort($categories, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        usort($incomeCats, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        // Top dépenses et courbe cumulée journalière
        $top = [];
        $daily = array_fill(1, $start->daysInMonth, 0.0);
        $prevDaily = array_fill(1, $prevStart->daysInMonth, 0.0);
        foreach ($this->engine->transactions as $t) {
            if ($t->type !== 'expense') {
                continue;
            }
            if ($t->date >= $from && $t->date <= $to) {
                $daily[(int) substr($t->date, 8, 2)] += $t->amount;
                $top[] = [
                    'id' => $t->id, 'date' => $t->date, 'description' => $t->description,
                    'amount' => $t->amount, 'category' => $this->categoryInfo((int) $t->category_id)['name'],
                ];
            } elseif ($t->date >= $prevStart->format('Y-m-d') && $t->date <= $prevTo) {
                $prevDaily[(int) substr($t->date, 8, 2)] += $t->amount;
            }
        }
        usort($top, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $cum = 0;
        $cumPrev = 0;
        $curve = [];
        $limitDay = Carbon::today()->format('Y-m') === $month ? Carbon::today()->day : $start->daysInMonth;
        foreach ($daily as $d => $v) {
            $cum += $v;
            $cumPrev += $prevDaily[$d] ?? 0;
            $curve[] = ['day' => $d, 'current' => $d <= $limitDay ? round($cum, 2) : null, 'previous' => round($cumPrev, 2)];
        }

        // Patrimoine
        $nwStart = $this->engine->netWorth($prevTo)['net_worth'];
        $nwEnd = $this->engine->netWorth(min($to, $this->engine->today()))['net_worth'];
        $delta = $nwEnd - $nwStart;

        // Revenus du portefeuille
        $dividends = 0.0;
        foreach ($this->engine->operations as $o) {
            if (in_array($o->type, ['dividend', 'interest'], true) && $o->date >= $from && $o->date <= $to) {
                $dividends += $o->amount;
            }
        }

        $budgets = $this->budgets($month, $cur);
        $target = (float) $this->user->savings_rate_target;
        $summary = collect($cur)->except('by_category')->all();

        $report = [
            'month' => $month,
            'label' => ucfirst($start->locale('fr')->isoFormat('MMMM YYYY')),
            'is_current' => Carbon::today()->format('Y-m') === $month,
            'summary' => $summary,
            'previous' => collect($prev)->except('by_category')->all(),
            'average_3m' => ['income' => round($avgTotals['income'], 2), 'expense' => round($avgTotals['expense'], 2)],
            'savings_rate_target' => $target,
            'rule_50_30_20' => [
                'needs' => $cur['income'] > 0 ? round($cur['essential'] / $cur['income'] * 100, 1) : 0,
                'wants' => $cur['income'] > 0 ? round($cur['discretionary'] / $cur['income'] * 100, 1) : 0,
                'savings' => $cur['savings_rate'],
            ],
            'expenses_by_category' => $categories,
            'income_by_category' => $incomeCats,
            'top_expenses' => array_slice($top, 0, 8),
            'daily_curve' => $curve,
            'budgets' => $budgets,
            'net_worth' => [
                'start' => $nwStart,
                'end' => $nwEnd,
                'change' => round($delta, 2),
                'change_pct' => $nwStart != 0 ? round($delta / abs($nwStart) * 100, 2) : null,
                'from_savings' => $cur['savings'],
                'from_markets' => round($delta - $cur['savings'], 2),
            ],
            'portfolio_income' => round($dividends, 2),
        ];
        $report['insights'] = $this->insights($report);

        return $report;
    }

    /** Constats automatiques, rédigés pour être lus tels quels. */
    private function insights(array $r): array
    {
        $out = [];
        $s = $r['summary'];
        $fmt = fn (float $v) => number_format($v, 0, ',', "\u{202F}").' €';
        $pc = fn (float $v) => str_replace('.', ',', (string) $v).' %';

        if ($s['income'] == 0 && $s['expense'] == 0) {
            return [['level' => 'info', 'text' => 'Aucune opération ce mois-ci. Importez un relevé ou saisissez vos dépenses.']];
        }
        if ($s['income'] > 0) {
            if ($s['savings_rate'] >= $r['savings_rate_target']) {
                $out[] = ['level' => 'good', 'text' => "Taux d'épargne de ".$pc($s['savings_rate']).', au-dessus de votre objectif de '.$pc($r['savings_rate_target']).'.'];
            } elseif ($s['savings_rate'] >= 0) {
                $gap = $s['income'] * $r['savings_rate_target'] / 100 - $s['savings'];
                $out[] = ['level' => 'warning', 'text' => "Taux d'épargne de ".$pc($s['savings_rate']).' : il manque '.$fmt($gap)." pour atteindre l'objectif de ".$pc($r['savings_rate_target']).'.'];
            } else {
                $out[] = ['level' => 'danger', 'text' => 'Dépenses supérieures aux revenus de '.$fmt(-$s['savings']).' ce mois-ci.'];
            }
        }
        foreach ($r['expenses_by_category'] as $c) {
            if ($c['delta_pct'] !== null && $c['delta_pct'] >= 30 && $c['amount'] - $c['average_3m'] >= 50) {
                $out[] = ['level' => 'warning', 'text' => "{$c['name']} : ".$fmt($c['amount']).' soit +'.$pc($c['delta_pct']).' par rapport à votre moyenne sur 3 mois.'];
            }
            // Une baisse n'a de sens qu'une fois le mois terminé.
            if (! $r['is_current'] && $c['delta_pct'] !== null && $c['delta_pct'] <= -30 && $c['average_3m'] - $c['amount'] >= 50) {
                $out[] = ['level' => 'good', 'text' => "{$c['name']} en baisse de ".$pc(abs($c['delta_pct'])).' par rapport à votre moyenne.'];
            }
        }
        $over = array_filter($r['budgets'], fn ($b) => $b['status'] === 'over');
        foreach ($over as $b) {
            $out[] = ['level' => 'danger', 'text' => "Budget {$b['name']} dépassé de ".$fmt(-$b['remaining']).'.'];
        }
        if ($r['is_current']) {
            foreach ($r['budgets'] as $b) {
                if ($b['status'] !== 'over' && $b['projected'] > $b['limit'] * 1.05) {
                    $out[] = ['level' => 'warning', 'text' => "Au rythme actuel, le budget {$b['name']} finira à ".$fmt($b['projected']).' pour '.$fmt($b['limit']).' prévus.'];
                }
            }
        }
        $nw = $r['net_worth'];
        if (abs($nw['from_markets']) >= 100) {
            $verb = $nw['from_markets'] > 0 ? 'ajouté' : 'retiré';
            $out[] = ['level' => $nw['from_markets'] > 0 ? 'good' : 'info', 'text' => 'Les marchés ont '.$verb.' '.$fmt(abs($nw['from_markets'])).' à votre patrimoine ce mois-ci.'];
        }
        if ($s['invested'] > 0) {
            $out[] = ['level' => 'good', 'text' => $fmt($s['invested']).' versés sur vos enveloppes d\'investissement.'];
        }
        if ($r['portfolio_income'] > 0) {
            $out[] = ['level' => 'good', 'text' => 'Revenus passifs encaissés : '.$fmt($r['portfolio_income']).' (dividendes, intérêts).'];
        }
        $needs = $r['rule_50_30_20']['needs'];
        if ($needs > 60) {
            $out[] = ['level' => 'info', 'text' => 'Les dépenses essentielles absorbent '.$pc($needs).' des revenus (repère : 50 %).'];
        }

        return $out;
    }

    /** Bilan annuel : série mensuelle + cumul par catégorie. */
    public function yearly(int $year): array
    {
        $months = [];
        $catTotals = [];
        $totals = ['income' => 0, 'expense' => 0, 'invested' => 0];
        for ($m = 1; $m <= 12; $m++) {
            $s = Carbon::create($year, $m, 1);
            $to = $s->copy()->endOfMonth()->format('Y-m-d');
            $a = $this->aggregate($s->format('Y-m-d'), $to);
            $isFuture = $s->format('Y-m') > Carbon::today()->format('Y-m');
            $months[] = [
                'month' => $s->format('Y-m'),
                'income' => $a['income'], 'expense' => $a['expense'],
                'savings' => $a['savings'], 'savings_rate' => $a['savings_rate'],
                'invested' => $a['invested'],
                'net_worth' => $isFuture ? null : $this->engine->netWorth(min($to, $this->engine->today()))['net_worth'],
            ];
            foreach (['income', 'expense', 'invested'] as $k) {
                $totals[$k] += $a[$k];
            }
            foreach ($a['by_category'] as $cid => $v) {
                $catTotals[$cid] = ($catTotals[$cid] ?? 0) + $v['expense'];
            }
        }
        $cats = [];
        foreach ($catTotals as $cid => $v) {
            if ($v > 0) {
                $cats[] = array_merge($this->categoryInfo((int) $cid), ['amount' => round($v, 2),
                    'share' => $totals['expense'] > 0 ? round($v / $totals['expense'] * 100, 1) : 0]);
            }
        }
        usort($cats, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        $startNw = $this->engine->netWorth(($year - 1).'-12-31')['net_worth'];
        $endNw = $this->engine->netWorth(min($year.'-12-31', $this->engine->today()))['net_worth'];
        $elapsed = count(array_filter($months, fn ($m) => $m['net_worth'] !== null)) ?: 1;

        return [
            'year' => $year,
            'months' => $months,
            'totals' => [
                'income' => round($totals['income'], 2),
                'expense' => round($totals['expense'], 2),
                'savings' => round($totals['income'] - $totals['expense'], 2),
                'savings_rate' => $totals['income'] > 0 ? round(($totals['income'] - $totals['expense']) / $totals['income'] * 100, 1) : 0,
                'invested' => round($totals['invested'], 2),
                'avg_monthly_expense' => round($totals['expense'] / $elapsed, 2),
                'avg_monthly_income' => round($totals['income'] / $elapsed, 2),
            ],
            'net_worth' => ['start' => $startNw, 'end' => $endNw, 'change' => round($endNw - $startNw, 2)],
            'expenses_by_category' => $cats,
        ];
    }

    /** Cashflow des N derniers mois. */
    public function cashflow(int $months = 12): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $s = Carbon::today()->startOfMonth()->subMonthsNoOverflow($i);
            $a = $this->aggregate($s->format('Y-m-d'), $s->copy()->endOfMonth()->format('Y-m-d'));
            $out[] = ['month' => $s->format('Y-m'), 'income' => $a['income'], 'expense' => $a['expense'],
                'savings' => $a['savings'], 'savings_rate' => $a['savings_rate'], 'invested' => $a['invested']];
        }

        return $out;
    }
}
