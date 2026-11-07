<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Goal;
use App\Services\WealthEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function index(Request $request)
    {
        $engine = WealthEngine::for($request->user());

        return Goal::where('user_id', $request->user()->id)->orderBy('priority')->orderBy('target_date')->get()
            ->map(fn (Goal $g) => array_merge($g->toArray(), self::progress($g, $engine)));
    }

    public static function progress(Goal $g, WealthEngine $engine): array
    {
        $current = $g->account_id && isset($engine->accounts[$g->account_id])
            ? $engine->accountValue($engine->accounts[$g->account_id])
            : $g->current_amount;
        $remaining = max(0, $g->target_amount - $current);
        $monthsLeft = null;
        $monthly = null;
        if ($g->target_date) {
            $monthsLeft = max(0, (int) ceil(Carbon::today()->floatDiffInMonths($g->target_date, false)));
            $monthly = $monthsLeft > 0 ? round($remaining / $monthsLeft, 2) : $remaining;
        }

        return [
            'current' => round($current, 2),
            'remaining' => round($remaining, 2),
            'percent' => $g->target_amount > 0 ? round(min(100, $current / $g->target_amount * 100), 1) : 0,
            'months_left' => $monthsLeft,
            'monthly_needed' => $monthly,
            'reached' => $current >= $g->target_amount,
        ];
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => "$req|string|max:120",
            'target_amount' => "$req|numeric|gt:0",
            'current_amount' => 'sometimes|numeric|min:0',
            'target_date' => 'nullable|date',
            'account_id' => 'nullable|integer',
            'color' => 'sometimes|string|max:9',
            'priority' => 'sometimes|integer|min:1|max:5',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $this->assertOwnedId($request, Account::class, $data['account_id'] ?? null);

        return response()->json($request->user()->goals()->create($data), 201);
    }

    public function show(Request $request, Goal $goal)
    {
        return $this->owned($request, $goal);
    }

    public function update(Request $request, Goal $goal)
    {
        $this->owned($request, $goal);
        $data = $request->validate($this->rules(true));
        $this->assertOwnedId($request, Account::class, $data['account_id'] ?? null);
        $goal->update($data);

        return $goal;
    }

    public function destroy(Request $request, Goal $goal)
    {
        $this->owned($request, $goal)->delete();

        return response()->noContent();
    }
}
