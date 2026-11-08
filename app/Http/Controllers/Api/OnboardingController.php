<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\OnboardingService;
use App\Services\RecommendationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnboardingController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return [
            'profile' => $user->profile,
            'onboarded_at' => $user->onboarded_at,
            'accounts' => $user->accounts()->get(['id', 'name', 'type', 'institution']),
        ];
    }

    /** Aperçu chiffré avant validation (sans rien enregistrer). */
    public function preview(Request $request)
    {
        $p = $this->validateProfile($request);
        $months = OnboardingService::emergencyMonths($p);
        $expenses = OnboardingService::estimatedExpenses($p);

        return [
            'emergency_months' => $months,
            'emergency_target' => round($expenses * $months, -2),
            'estimated_expenses' => round($expenses, 2),
            'estimated_savings' => round(max(0, (float) ($p['monthly_income'] ?? 0) - $expenses), 2),
            'tax_parts' => OnboardingService::taxParts($p),
            'tmi' => OnboardingService::estimatedTmi($p),
        ];
    }

    public function store(Request $request)
    {
        $p = $this->validateProfile($request);
        $extra = $request->validate([
            'accounts' => 'array|max:30',
            'accounts.*.type' => ['required', Rule::in(Account::TYPES)],
            'accounts.*.name' => 'nullable|string|max:120',
            'accounts.*.institution' => 'nullable|string|max:120',
            'accounts.*.balance' => 'nullable|numeric',
            'accounts.*.opened_year' => 'nullable|integer|min:1950|max:'.date('Y'),
            'accounts.*.interest_rate' => 'nullable|numeric|min:0|max:30',
            'options' => 'array',
            'options.create_budgets' => 'boolean',
            'options.create_recurring' => 'boolean',
            'options.create_goals' => 'boolean',
        ]);
        $created = OnboardingService::apply($request->user(), $p, $extra['accounts'] ?? [], $extra['options'] ?? []);
        $user = $request->user()->fresh();

        return [
            'user' => $user,
            'created' => $created,
            'recommendations' => array_slice(RecommendationService::for($user), 0, 5),
        ];
    }

    public function skip(Request $request)
    {
        $user = $request->user();
        $user->update(['onboarded_at' => now(), 'profile' => $user->profile ?? ['skipped' => true]]);

        return $user->fresh();
    }

    public function recommendations(Request $request)
    {
        return RecommendationService::for($request->user());
    }

    private function validateProfile(Request $request): array
    {
        $data = $request->validate([
            'profile' => 'required|array',
            'profile.birth_year' => 'nullable|integer|min:1920|max:'.date('Y'),
            'profile.country' => ['nullable', Rule::in(['FR', 'LU', 'BE', 'CH', 'DE', 'other'])],
            'profile.household' => ['nullable', Rule::in(['single', 'couple', 'family'])],
            'profile.children' => 'nullable|integer|min:0|max:12',
            'profile.has_pets' => 'nullable|boolean',
            'profile.employment' => ['nullable', Rule::in(['cdi', 'cdd', 'freelance', 'business_owner', 'civil_servant', 'student', 'unemployed', 'retired'])],
            'profile.housing' => ['nullable', Rule::in(['tenant', 'owner_mortgage', 'owner', 'hosted'])],
            'profile.monthly_income' => 'nullable|numeric|min:0|max:1000000',
            'profile.income_variable' => 'nullable|boolean',
            'profile.payday' => 'nullable|integer|min:1|max:31',
            'profile.housing_cost' => 'nullable|numeric|min:0',
            'profile.other_fixed_costs' => 'nullable|numeric|min:0',
            'profile.variable_spending' => 'nullable|numeric|min:0',
            'profile.goals' => 'nullable|array',
            'profile.goals.*' => Rule::in(['emergency', 'debt', 'home', 'retirement', 'fire', 'invest', 'project', 'expat', 'budget']),
            'profile.home_amount' => 'nullable|numeric|min:0',
            'profile.home_date' => 'nullable|date',
            'profile.project_name' => 'nullable|string|max:120',
            'profile.project_amount' => 'nullable|numeric|min:0',
            'profile.project_date' => 'nullable|date',
            'profile.expat_date' => 'nullable|date',
            'profile.experience' => ['nullable', Rule::in(['none', 'beginner', 'intermediate', 'advanced'])],
            'profile.risk' => ['nullable', Rule::in(['prudent', 'balanced', 'dynamic'])],
            'profile.horizon' => ['nullable', Rule::in(['short', 'medium', 'long'])],
            'profile.monthly_invest' => 'nullable|numeric|min:0',
            'profile.budget_method' => ['nullable', Rule::in(['50_30_20', 'envelopes', 'none'])],
        ]);

        return array_filter($data['profile'], fn ($v) => $v !== null);
    }
}
