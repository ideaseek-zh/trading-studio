<?php

namespace Tests\Feature;

use App\Models\Security;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecuritiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_securities_from_the_database(): void
    {
        Security::query()->create([
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

        $response = $this->getJson('/api/v1/securities');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.canonical_symbol', 'CN.XSHE.000001');
    }

    public function test_it_can_search_securities(): void
    {
        Security::query()->create([
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

        $response = $this->getJson('/api/v1/securities/search?q='.urlencode('浦发'));

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('meta.count', 1);
    }
}
