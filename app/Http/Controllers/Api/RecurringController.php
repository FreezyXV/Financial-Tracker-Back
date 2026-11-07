<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Services\RecurringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecurringController extends Controller
{
    public function index(Request $request)
    {
        $items = RecurringTransaction::where('user_id', $request->user()->id)
            ->with(['category:id,name,color,icon', 'account:id,name'])
            ->orderBy('next_date')->get();
        $active = $items->where('is_active', true);

        return [
            'items' => $items->map(fn ($r) => array_merge($r->toArray(), ['monthly_amount' => round($r->monthlyAmount(), 2)])),
            'totals' => [
                'subscriptions_monthly' => round($active->where('is_subscription', true)->where('type', 'expense')->sum(fn ($r) => $r->monthlyAmount()), 2),
                'expenses_monthly' => round($active->where('type', 'expense')->sum(fn ($r) => $r->monthlyAmount()), 2),
                'income_monthly' => round($active->where('type', 'income')->sum(fn ($r) => $r->monthlyAmount()), 2),
                'transfers_monthly' => round($active->where('type', 'transfer')->sum(fn ($r) => $r->monthlyAmount()), 2),
            ],
            'upcoming' => RecurringService::upcoming($request->user(), 30),
        ];
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'account_id' => "$req|integer",
            'category_id' => 'nullable|integer',
            'transfer_account_id' => 'nullable|integer|required_if:type,transfer',
            'type' => [$req, Rule::in(['income', 'expense', 'transfer'])],
            'amount' => "$req|numeric|gt:0",
            'description' => "$req|string|max:255",
            'frequency' => [$req, Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])],
            'next_date' => "$req|date",
            'end_date' => 'nullable|date',
            'is_subscription' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'auto_post' => 'sometimes|boolean',
        ];
    }

    private function check(Request $request, array $d): void
    {
        $this->assertOwnedId($request, Account::class, $d['account_id'] ?? null);
        $this->assertOwnedId($request, Account::class, $d['transfer_account_id'] ?? null);
        $this->assertOwnedId($request, Category::class, $d['category_id'] ?? null);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $this->check($request, $data);

        return response()->json($request->user()->recurring()->create($data), 201);
    }

    public function show(Request $request, RecurringTransaction $recurring)
    {
        return $this->owned($request, $recurring);
    }

    public function update(Request $request, RecurringTransaction $recurring)
    {
        $this->owned($request, $recurring);
        $data = $request->validate($this->rules(true));
        $this->check($request, $data);
        $recurring->update($data);

        return $recurring;
    }

    public function destroy(Request $request, RecurringTransaction $recurring)
    {
        $this->owned($request, $recurring)->delete();

        return response()->noContent();
    }

    public function post(Request $request)
    {
        return ['created' => RecurringService::postDue($request->user())];
    }

    public function suggestions(Request $request)
    {
        return RecurringService::suggestions($request->user());
    }
}
