<?php

namespace Tests\Feature;

use App\Models\IndexDailyBar;
use App\Models\MarketDailyBar;
use App\Models\MarketIndex;
use App\Models\MarketQuote;
use App\Models\Security;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_latest_quote_for_a_security(): void
    {
        $security = Security::query()->create([
            'canonical_symbol' => 'CN.XSHE.000001',
            'symbol' => '000001',
            'exchange' => 'XSHE',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => '平安银行',
            'short_name' => '平安银行',
            'status' => 'active',
            'currency' => 'CNY',
        ]);

        MarketQuote::query()->create([
            'security_id' => $security->id,
            'quote_time' => now(),
            'last_price' => 12.34,
            'pre_close' => 12.10,
            'open' => 12.15,
            'high' => 12.40,
            'low' => 12.00,
            'volume' => 123456,
            'amount' => 654321,
            'turnover_rate' => 1.2345,
            'pct_change' => 1.9834,
            'provider' => 'akshare',
        ]);

        $response = $this->getJson('/api/v1/securities/CN.XSHE.000001/quote');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.last_price', '12.3400');
    }

    public function test_it_returns_daily_bars_for_a_security(): void
    {
        $security = Security::query()->create([
            'canonical_symbol' => 'CN.XSHG.600000',
            'symbol' => '600000',
            'exchange' => 'XSHG',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => '浦发银行',
            'short_name' => '浦发银行',
            'status' => 'active',
            'currency' => 'CNY',
        ]);

        MarketDailyBar::query()->create([
            'security_id' => $security->id,
            'trade_date' => '2026-07-11',
            'open' => 10.01,
            'high' => 10.33,
            'low' => 9.98,
            'close' => 10.20,
            'pre_close' => 10.00,
            'volume' => 999999,
            'amount' => 8888888,
            'turnover_rate' => 1.4567,
            'pct_change' => 2.0000,
            'adjust_type' => 'none',
            'provider' => 'akshare',
        ]);

        $response = $this->getJson('/api/v1/securities/CN.XSHG.600000/daily-bars?startDate=2026-07-01&endDate=2026-07-12');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.trade_date', '2026-07-11');
    }

    public function test_it_returns_index_snapshots(): void
    {
        MarketIndex::query()->create([
            'code' => 'sh000001',
            'name' => '上证指数',
            'exchange' => 'XSHG',
            'market' => 'CN',
            'index_type' => 'broad',
            'status' => 'active',
            'quote_time' => now(),
            'last_price' => 3200.12,
            'change_amount' => 15.22,
            'pct_change' => 0.48,
        ]);

        $response = $this->getJson('/api/v1/indices');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.code', 'sh000001');
    }

    public function test_it_returns_index_daily_bars(): void
    {
        $index = MarketIndex::query()->create([
            'code' => 'sh000001',
            'name' => '上证指数',
            'exchange' => 'XSHG',
            'market' => 'CN',
            'index_type' => 'broad',
            'status' => 'active',
        ]);

        IndexDailyBar::query()->create([
            'market_index_id' => $index->id,
            'trade_date' => '2026-07-11',
            'open' => 3180.00,
            'high' => 3210.00,
            'low' => 3175.00,
            'close' => 3200.12,
            'pre_close' => 3184.90,
            'volume' => 111111,
            'amount' => 222222,
            'pct_change' => 0.48,
            'provider' => 'akshare',
        ]);

        $response = $this->getJson('/api/v1/indices/sh000001/daily-bars?startDate=2026-07-01&endDate=2026-07-12');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.trade_date', '2026-07-11');
    }
}
