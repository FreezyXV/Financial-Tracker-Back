<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\WealthEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $engine = WealthEngine::for($request->user());

        return collect($engine->accounts)->values()->map(
            fn (Account $a) => array_merge($a->toArray(), $engine->accountSummary($a))
        );
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => "$req|string|max:120",
            'type' => [$req, Rule::in(Account::TYPES)],
            'institution' => 'nullable|string|max:120',
            'currency' => 'sometimes|string|size:3',
            'opening_balance' => 'sometimes|numeric',
            'opened_at' => 'nullable|date',
            'manual_value' => 'nullable|numeric',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'color' => 'sometimes|string|max:9',
            'include_in_net_worth' => 'sometimes|boolean',
            'is_archived' => 'sometimes|boolean',
        ];
    }

    public function store(Request $request)
    {
        $account = $request->user()->accounts()->create($request->validate($this->rules()));

        return response()->json($account, 201);
    }

    public function show(Request $request, Account $account)
    {
        $this->owned($request, $account);
        $engine = WealthEngine::for($request->user());
        $a = $engine->accounts[$account->id];

        return array_merge($a->toArray(), $engine->accountSummary($a), [
            'holdings' => $a->isInvestment() ? $engine->holdings(null, $a->id) : [],
        ]);
    }

    public function update(Request $request, Account $account)
    {
        $this->owned($request, $account)->update($request->validate($this->rules(true)));

        return $account;
    }

    public function destroy(Request $request, Account $account)
    {
        $this->owned($request, $account)->delete();

        return response()->noContent();
    }
}
