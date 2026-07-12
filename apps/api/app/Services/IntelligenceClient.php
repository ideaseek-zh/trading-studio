<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class IntelligenceClient
{
    public function request(): PendingRequest
    {
        return Http::timeout((int) config('services.intelligence.timeout', 30))
            ->acceptJson()
            ->withHeaders([
                'X-Service-Token' => (string) config('services.intelligence.service_token'),
            ]);
    }

    public function syncQuotes(array $symbols, string $provider = 'akshare'): void
    {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/quotes'), [
            'provider' => $provider,
            'symbols' => array_values($symbols),
        ])->throw();
    }

    public function syncNewsSources(string $provider = 'akshare'): void
    {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/news-sources'), [
            'provider' => $provider,
        ])->throw();
    }

    public function syncNews(
        array $scopes = ['global', 'stock', 'notice'],
        array $symbols = [],
        ?string $startDate = null,
        ?string $endDate = null,
        int $limitPerSource = 30,
        string $provider = 'akshare'
    ): void {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/news'), [
            'provider' => $provider,
            'scopes' => array_values($scopes),
            'symbols' => array_values($symbols),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit_per_source' => $limitPerSource,
        ])->throw();
    }

    public function rebuildEventChains(?int $securityId = null): void
    {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/event-chains/rebuild'), [
            'security_id' => $securityId,
        ])->throw();
    }

    public function rebuildSignals(?int $securityId = null, ?int $eventChainId = null): void
    {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/signals/rebuild'), [
            'security_id' => $securityId,
            'event_chain_id' => $eventChainId,
        ])->throw();
    }

    public function rebuildSignalInsights(?int $signalId = null, ?int $securityId = null): void
    {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/signals/insights'), [
            'signal_id' => $signalId,
            'security_id' => $securityId,
        ])->throw();
    }

    public function syncDailyBars(
        string $symbol,
        ?string $startDate = null,
        ?string $endDate = null,
        string $adjust = 'none',
        string $provider = 'akshare'
    ): void {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/daily-bars'), [
            'provider' => $provider,
            'symbol' => $symbol,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'adjust' => $adjust,
        ])->throw();
    }

    public function syncIndices(array $codes = [], string $provider = 'akshare'): void
    {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/indices'), [
            'provider' => $provider,
            'codes' => array_values($codes),
        ])->throw();
    }

    public function syncIndexDailyBars(
        string $code,
        ?string $startDate = null,
        ?string $endDate = null,
        string $provider = 'akshare'
    ): void {
        $this->request()->post($this->endpoint('/internal/v1/data/sync/index-daily-bars'), [
            'provider' => $provider,
            'code' => $code,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ])->throw();
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.intelligence.base_url'), '/').$path;
    }
}
