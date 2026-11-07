<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Services\PriceService;
use Illuminate\Support\Facades\Http;

class PriceServiceTest extends FinanceTestCase
{
    public function test_refresh_parses_yahoo_and_coingecko(): void
    {
        Http::fake([
            'api.coingecko.com/*' => Http::response(['bitcoin' => ['eur' => 90000]]),
            'query1.finance.yahoo.com/v8/finance/chart/USDEUR=X*' => Http::response(['chart' => ['result' => [['meta' => ['regularMarketPrice' => 0.9]]]]]),
            'query1.finance.yahoo.com/*' => Http::response(['chart' => ['result' => [['meta' => ['regularMarketPrice' => 210.5, 'currency' => 'USD']]]]]),
        ]);
        $btc = Asset::create(['user_id' => $this->user->id, 'symbol' => 'BTC', 'name' => 'Bitcoin', 'asset_class' => 'crypto', 'price_source' => 'coingecko', 'source_id' => 'bitcoin']);
        $aapl = Asset::create(['user_id' => $this->user->id, 'symbol' => 'AAPL', 'name' => 'Apple', 'asset_class' => 'stock', 'price_source' => 'yahoo', 'source_id' => 'AAPL', 'currency' => 'USD']);

        $res = PriceService::refresh($this->user);

        $this->assertSame(2, $res['updated']);
        $this->assertEquals(90000, $btc->fresh()->current_price);
        $this->assertEquals(210.5, $aapl->fresh()->current_price);
        $this->assertEquals(0.9, $aapl->fresh()->fx_rate);
        $this->assertSame(1, $aapl->prices()->count());
    }

    public function test_refresh_failure_keeps_manual_price(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        $a = Asset::create(['user_id' => $this->user->id, 'symbol' => 'CW8', 'name' => 'World', 'asset_class' => 'etf', 'price_source' => 'yahoo', 'source_id' => 'CW8.PA', 'current_price' => 500]);
        $res = PriceService::refresh($this->user);
        $this->assertSame(['CW8'], $res['failed']);
        $this->assertEquals(500, $a->fresh()->current_price);
    }
}
