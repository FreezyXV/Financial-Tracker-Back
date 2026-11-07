<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetPrice;
use App\Services\PriceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        return Asset::where('user_id', $request->user()->id)->withCount('operations')->orderBy('symbol')->get();
    }

    private function rules(Request $request, ?Asset $asset = null): array
    {
        $req = $asset ? 'sometimes' : 'required';

        return [
            'symbol' => [$req, 'string', 'max:40', Rule::unique('assets')->where('user_id', $request->user()->id)->ignore($asset?->id)],
            'name' => "$req|string|max:160",
            'asset_class' => [$req, Rule::in(Asset::CLASSES)],
            'isin' => 'nullable|string|max:12',
            'currency' => 'sometimes|string|size:3',
            'price_source' => ['sometimes', Rule::in(['manual', 'yahoo', 'coingecko'])],
            'source_id' => 'nullable|string|max:120',
            'current_price' => 'sometimes|numeric|min:0',
            'region' => 'nullable|string|max:40',
            'sector' => 'nullable|string|max:60',
            'pea_eligible' => 'sometimes|boolean',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));
        $data['symbol'] = strtoupper($data['symbol']);
        $data['fx_rate'] = PriceService::fx($data['currency'] ?? 'EUR');
        $asset = $request->user()->assets()->create($data);
        if ($asset->current_price > 0) {
            AssetPrice::updateOrCreate(['asset_id' => $asset->id, 'date' => now()->format('Y-m-d')], ['price' => $asset->current_price, 'fx_rate' => $asset->fx_rate]);
        }
        if ($asset->price_source !== 'manual') {
            PriceService::backfill($asset);
            PriceService::refresh($request->user());
        }

        return response()->json($asset->fresh(), 201);
    }

    public function show(Request $request, Asset $asset)
    {
        $this->owned($request, $asset);

        return array_merge($asset->toArray(), [
            'prices' => $asset->prices()->orderBy('date')->get(['date', 'price', 'fx_rate']),
        ]);
    }

    public function update(Request $request, Asset $asset)
    {
        $this->owned($request, $asset);
        $data = $request->validate($this->rules($request, $asset));
        if (isset($data['symbol'])) {
            $data['symbol'] = strtoupper($data['symbol']);
        }
        if (isset($data['currency'])) {
            $data['fx_rate'] = PriceService::fx($data['currency']);
        }
        if (isset($data['current_price'])) {
            $data['price_updated_at'] = now();
        }
        $asset->update($data);
        if (isset($data['current_price'])) {
            AssetPrice::updateOrCreate(['asset_id' => $asset->id, 'date' => now()->format('Y-m-d')], ['price' => $asset->current_price, 'fx_rate' => $asset->fx_rate]);
        }

        return $asset;
    }

    public function destroy(Request $request, Asset $asset)
    {
        $this->owned($request, $asset)->delete();

        return response()->noContent();
    }

    public function refresh(Request $request)
    {
        return PriceService::refresh($request->user());
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));

        return strlen($q) < 2 ? [] : PriceService::search($q);
    }
}
