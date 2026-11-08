<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Goal;
use App\Models\Transaction;
use App\Services\RecommendationService;
use App\Services\RecurringService;
use App\Services\ReportService;
use App\Services\WealthEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Tableau de bord, bilans, patrimoine et portefeuille. */
class InsightController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();
        RecurringService::postDue($user);
        $engine = WealthEngine::for($user);
        $reports = new ReportService($user, $engine);
        $month = Carbon::today()->format('Y-m');

        $now = $engine->netWorth();
        $monthAgo = $engine->netWorth(Carbon::today()->subDays(30)->format('Y-m-d'));
        $yearStart = $engine->netWorth(Carbon::today()->startOfYear()->subDay()->format('Y-m-d'));
        $current = $reports->aggregate(Carbon::today()->startOfMonth()->format('Y-m-d'), Carbon::today()->format('Y-m-d'));
        $cashflow = $reports->cashflow(12);
        $complete = array_slice($cashflow, 0, -1);
        $last6 = array_slice($complete, -6);
        $avgExpense = count($last6) ? array_sum(array_column($last6, 'expense')) / count($last6) : 0;
        $avgIncome = count($last6) ? array_sum(array_column($last6, 'income')) / count($last6) : 0;
        $liquid = ($now['by_group']['liquidites'] ?? 0) + ($now['by_group']['epargne'] ?? 0);
        $portfolio = $engine->portfolio()['totals'];

        return [
            'net_worth' => $now,
            'change_30d' => round($now['net_worth'] - $monthAgo['net_worth'], 2),
            'change_ytd' => round($now['net_worth'] - $yearStart['net_worth'], 2),
            'month' => collect($current)->except('by_category')->all(),
            'cashflow' => $cashflow,
            'history' => $engine->history(12),
            'allocation' => $engine->allocation(),
            'budgets' => array_slice($reports->budgets($month), 0, 6),
            'upcoming' => array_slice(RecurringService::upcoming($user, 21), 0, 8),
            'recent' => Transaction::where('user_id', $user->id)->with(['category:id,name,color,icon', 'account:id,name'])
                ->orderByDesc('date')->orderByDesc('id')->limit(8)->get(),
            'goals' => Goal::where('user_id', $user->id)->orderBy('priority')->limit(4)->get()
                ->map(fn ($g) => array_merge($g->toArray(), GoalController::progress($g, $engine))),
            'portfolio' => $portfolio,
            'indicators' => [
                'avg_monthly_income' => round($avgIncome, 2),
                'avg_monthly_expense' => round($avgExpense, 2),
                'avg_savings_rate' => $avgIncome > 0 ? round(($avgIncome - $avgExpense) / $avgIncome * 100, 1) : 0,
                'emergency_fund_months' => $avgExpense > 0 ? round($liquid / $avgExpense, 1) : null,
                'liquid_savings' => round($liquid, 2),
                'savings_rate_target' => $user->savings_rate_target,
            ],
            'recommendations' => array_slice(RecommendationService::for($user, $engine), 0, 5),
            'unread_alerts' => Alert::where('user_id', $user->id)->where('is_read', false)->count(),
            'uncategorized' => Transaction::where('user_id', $user->id)->whereNull('category_id')->where('type', '!=', 'transfer')->count(),
        ];
    }

    public function monthly(Request $request)
    {
        $month = $request->query('month', Carbon::today()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month), 422, 'Format attendu : YYYY-MM');

        return (new ReportService($request->user()))->monthly($month);
    }

    public function yearly(Request $request)
    {
        $year = (int) $request->query('year', Carbon::today()->year);

        return (new ReportService($request->user()))->yearly($year);
    }

    public function netWorth(Request $request)
    {
        $engine = WealthEngine::for($request->user());
        $months = max(2, min(120, (int) $request->query('months', 24)));

        return [
            'current' => $engine->netWorth(),
            'history' => $engine->history($months),
            'allocation' => $engine->allocation(),
        ];
    }

    public function portfolio(Request $request)
    {
        $engine = WealthEngine::for($request->user());
        $history = array_map(fn ($p) => ['date' => $p['date'], 'value' => $p['investments']], $engine->history(12));

        return array_merge($engine->portfolio(), ['history' => $history]);
    }

    /** Données de départ pour le simulateur d'indépendance financière. */
    public function simulatorDefaults(Request $request)
    {
        $user = $request->user();
        $engine = WealthEngine::for($user);
        $cash = (new ReportService($user, $engine))->cashflow(13);
        $complete = array_slice($cash, 0, -1);
        $last = array_slice($complete, -6);
        $exp = count($last) ? array_sum(array_column($last, 'expense')) / count($last) : 0;
        $sav = count($last) ? array_sum(array_column($last, 'savings')) / count($last) : 0;
        $nw = $engine->netWorth();

        $p = $user->profile ?? [];

        return [
            'birth_year' => $p['birth_year'] ?? null,
            'risk' => $p['risk'] ?? null,
            'monthly_invest' => $p['monthly_invest'] ?? null,
            'goals' => $p['goals'] ?? [],
            'net_worth' => $nw['net_worth'],
            'invested' => $engine->portfolio()['totals']['value'],
            'monthly_expense' => round($exp, 2),
            'monthly_savings' => round(max(0, $sav), 2),
        ];
    }
}
