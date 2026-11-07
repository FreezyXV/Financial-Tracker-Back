<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetPrice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cotations : Yahoo Finance (actions, ETF — ex. CW8.PA, ESE.PA, AAPL)
 * et CoinGecko (crypto, cotées en EUR). Échecs silencieux : le prix manuel reste.
 */
class PriceService
{
    private const UA = 'Mozilla/5.0 (FinancialTracker)';

    private static function http()
    {
        return Http::timeout(8)->withHeaders(['User-Agent' => self::UA, 'Accept' => 'application/json']);
    }

    /** Taux de change devise -> EUR. */
    public static function fx(string $currency): float
    {
        $currency = strtoupper($currency);
        if ($currency === 'EUR') {
            return 1.0;
        }
        if ($currency === 'GBX' || $currency === 'GBP') {
            $gbp = self::fx('GBP_');

            return $currency === 'GBX' ? $gbp / 100 : $gbp;
        }
        $cur = rtrim($currency, '_');

        return Cache::remember("fx:$cur", 3600, function () use ($cur) {
            try {
                $r = self::http()->get("https://query1.finance.yahoo.com/v8/finance/chart/{$cur}EUR=X", ['range' => '1d', 'interval' => '1d']);
                $p = $r->json('chart.result.0.meta.regularMarketPrice');
                if ($p) {
                    return (float) $p;
                }
            } catch (Throwable) {
            }
            try {
                $r = self::http()->get('https://api.frankfurter.app/latest', ['from' => $cur, 'to' => 'EUR']);

                return (float) ($r->json('rates.EUR') ?: 1.0);
            } catch (Throwable) {
                return 1.0;
            }
        });
    }

    /** @return array{updated: int, failed: array<int, string>} */
    public static function refresh(User $user): array
    {
        $updated = 0;
        $failed = [];
        $assets = Asset::where('user_id', $user->id)->where('price_source', '!=', 'manual')->get();

        $crypto = $assets->where('price_source', 'coingecko');
        if ($crypto->isNotEmpty()) {
            try {
                $ids = $crypto->pluck('source_id')->filter()->implode(',');
                $data = self::http()->get('https://api.coingecko.com/api/v3/simple/price', ['ids' => $ids, 'vs_currencies' => 'eur'])->json();
                foreach ($crypto as $a) {
                    $p = $data[$a->source_id]['eur'] ?? null;
                    $p ? self::store($a, (float) $p, 1.0) && $updated++ : $failed[] = $a->symbol;
                }
            } catch (Throwable) {
                array_push($failed, ...$crypto->pluck('symbol')->all());
            }
        }

        foreach ($assets->where('price_source', 'yahoo') as $a) {
            try {
                $r = self::http()->get('https://query1.finance.yahoo.com/v8/finance/chart/'.urlencode($a->source_id ?: $a->symbol), ['range' => '1d', 'interval' => '1d']);
                $meta = $r->json('chart.result.0.meta');
                if (! $meta || ! isset($meta['regularMarketPrice'])) {
                    $failed[] = $a->symbol;

                    continue;
                }
                $currency = $meta['currency'] ?? $a->currency;
                if ($currency && $currency !== $a->currency) {
                    $a->currency = $currency;
                }
                self::store($a, (float) $meta['regularMarketPrice'], self::fx($a->currency));
                $updated++;
            } catch (Throwable) {
                $failed[] = $a->symbol;
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    private static function store(Asset $a, float $price, float $fx): bool
    {
        $a->current_price = $price;
        $a->fx_rate = $fx;
        $a->price_updated_at = now();
        $a->save();
        AssetPrice::updateOrCreate(['asset_id' => $a->id, 'date' => Carbon::today()->format('Y-m-d')], ['price' => $price, 'fx_rate' => $fx]);

        return true;
    }

    /** Historique mensuel sur 2 ans (pour les courbes de patrimoine). */
    public static function backfill(Asset $a): int
    {
        $n = 0;
        try {
            if ($a->price_source === 'yahoo') {
                $r = self::http()->get('https://query1.finance.yahoo.com/v8/finance/chart/'.urlencode($a->source_id ?: $a->symbol), ['range' => '2y', 'interval' => '1mo']);
                $ts = $r->json('chart.result.0.timestamp') ?? [];
                $close = $r->json('chart.result.0.indicators.quote.0.close') ?? [];
                $fx = self::fx($a->currency);
                foreach ($ts as $i => $t) {
                    if (! empty($close[$i])) {
                        AssetPrice::updateOrCreate(['asset_id' => $a->id, 'date' => Carbon::createFromTimestamp($t)->format('Y-m-d')], ['price' => $close[$i], 'fx_rate' => $fx]);
                        $n++;
                    }
                }
            } elseif ($a->price_source === 'coingecko' && $a->source_id) {
                $r = self::http()->get("https://api.coingecko.com/api/v3/coins/{$a->source_id}/market_chart", ['vs_currency' => 'eur', 'days' => 365, 'interval' => 'daily']);
                $seen = [];
                foreach ($r->json('prices') ?? [] as [$t, $p]) {
                    $d = Carbon::createFromTimestampMs($t);
                    $key = $d->format('Y-m');
                    if (isset($seen[$key]) && $d->day < 25) {
                        continue;
                    }
                    $seen[$key] = true;
                    AssetPrice::updateOrCreate(['asset_id' => $a->id, 'date' => $d->format('Y-m-d')], ['price' => $p, 'fx_rate' => 1]);
                    $n++;
                }
            }
        } catch (Throwable) {
        }

        return $n;
    }

    /** Recherche d'instruments (Yahoo + CoinGecko). */
    public static function search(string $q): array
    {
        $out = [];
        try {
            $r = self::http()->get('https://query2.finance.yahoo.com/v1/finance/search', ['q' => $q, 'quotesCount' => 8, 'newsCount' => 0]);
            foreach ($r->json('quotes') ?? [] as $item) {
                if (! isset($item['symbol'])) {
                    continue;
                }
                $type = strtolower($item['quoteType'] ?? '');
                $out[] = [
                    'symbol' => $item['symbol'],
                    'name' => $item['longname'] ?? $item['shortname'] ?? $item['symbol'],
                    'asset_class' => match ($type) {
                        'etf' => 'etf', 'equity' => 'stock', 'mutualfund' => 'fund',
                        'cryptocurrency' => 'crypto', default => 'other',
                    },
                    'exchange' => $item['exchDisp'] ?? $item['exchange'] ?? null,
                    'price_source' => 'yahoo',
                    'source_id' => $item['symbol'],
                ];
            }
        } catch (Throwable) {
        }
        try {
            $r = self::http()->get('https://api.coingecko.com/api/v3/search', ['query' => $q]);
            foreach (array_slice($r->json('coins') ?? [], 0, 5) as $c) {
                $out[] = [
                    'symbol' => strtoupper($c['symbol']), 'name' => $c['name'], 'asset_class' => 'crypto',
                    'exchange' => 'CoinGecko', 'price_source' => 'coingecko', 'source_id' => $c['id'],
                ];
            }
        } catch (Throwable) {
        }

        return $out;
    }
}
