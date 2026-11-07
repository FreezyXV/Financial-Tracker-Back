<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Asset;
use App\Models\InvestmentOperation;
use App\Services\WealthEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationController extends Controller
{
    public function index(Request $request)
    {
        $q = InvestmentOperation::where('user_id', $request->user()->id)
            ->with(['asset:id,symbol,name,asset_class,currency', 'account:id,name,type']);
        foreach (['account_id', 'asset_id', 'type'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, $v);
            }
        }

        return $q->orderByDesc('date')->orderByDesc('id')->paginate(min((int) $request->query('per_page', 50), 500));
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'account_id' => "$req|integer",
            'asset_id' => 'nullable|integer|required_if:type,buy,sell',
            'type' => [$req, Rule::in(['buy', 'sell', 'dividend', 'interest', 'fee'])],
            'quantity' => 'nullable|numeric|min:0|required_if:type,buy,sell',
            'price' => 'nullable|numeric|min:0|required_if:type,buy,sell',
            'fx_rate' => 'nullable|numeric|gt:0',
            'fees' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0|required_if:type,dividend,interest,fee',
            'date' => "$req|date",
            'notes' => 'nullable|string|max:255',
        ];
    }

    private function prepare(Request $request, array $data, ?InvestmentOperation $op = null): array
    {
        $this->assertOwnedId($request, Account::class, $data['account_id'] ?? null);
        $this->assertOwnedId($request, Asset::class, $data['asset_id'] ?? null);
        $accountId = $data['account_id'] ?? $op?->account_id;
        $account = Account::find($accountId);
        abort_unless($account && $account->isInvestment(), 422, "Ce compte n'est pas une enveloppe d'investissement.");
        foreach (['quantity', 'price', 'fees', 'amount'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] === null) {
                $data[$f] = 0;
            }
        }
        $assetId = $data['asset_id'] ?? $op?->asset_id;
        if (in_array($account->type, ['pea', 'pea_pme'], true) && in_array($data['type'] ?? $op?->type, ['buy'], true) && $assetId) {
            abort_unless(Asset::find($assetId)?->pea_eligible, 422, "Cet actif n'est pas éligible au PEA (cochez « Éligible PEA » sur l'actif s'il l'est).");
        }
        if (empty($data['fx_rate']) && ! empty($data['asset_id'])) {
            $data['fx_rate'] = Asset::find($data['asset_id'])->fx_rate ?: 1;
        }
        $data['fx_rate'] ??= $op?->fx_rate ?? 1;

        // Vente : contrôle de la quantité détenue
        $type = $data['type'] ?? $op?->type;
        if ($type === 'sell') {
            $assetId = (int) ($data['asset_id'] ?? $op?->asset_id);
            $held = 0;
            foreach (WealthEngine::for($request->user())->holdings($data['date'] ?? null, (int) $accountId) as $p) {
                if ($p['asset_id'] === $assetId) {
                    $held = $p['quantity'];
                }
            }
            if ($op && $op->type === 'sell') {
                $held += $op->quantity;
            }
            abort_if(($data['quantity'] ?? $op->quantity) > $held + 1e-9, 422, 'Quantité vendue supérieure à la quantité détenue ('.$held.').');
        }

        return $data;
    }

    public function store(Request $request)
    {
        $data = $this->prepare($request, $request->validate($this->rules()));
        $op = $request->user()->operations()->create($data);

        return response()->json($op->load(['asset:id,symbol,name', 'account:id,name']), 201);
    }

    public function show(Request $request, InvestmentOperation $operation)
    {
        return $this->owned($request, $operation)->load(['asset', 'account']);
    }

    public function update(Request $request, InvestmentOperation $operation)
    {
        $this->owned($request, $operation);
        $operation->update($this->prepare($request, $request->validate($this->rules(true)), $operation));

        return $operation->load(['asset:id,symbol,name', 'account:id,name']);
    }

    public function destroy(Request $request, InvestmentOperation $operation)
    {
        $this->owned($request, $operation)->delete();

        return response()->noContent();
    }
}
