<?php

namespace App\Services;

use App\Models\IndexDailyBar;
use App\Models\MarketDailyBar;
use App\Models\MarketIndex;
use App\Models\MarketQuote;
use App\Models\Security;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MarketDataService
{
    public function __construct(
        private readonly IntelligenceClient $intelligenceClient
    ) {
    }

    public function latestQuote(Security $security): ?MarketQuote
    {
        $cacheKey = "stock:quote:{$security->canonical_symbol}";

        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($security) {
            $quote = MarketQuote::query()
                ->where('security_id', $security->id)
                ->latest('quote_time')
                ->first();

            if ($quote === null || optional($quote->quote_time)?->lt(now()->subMinutes(5))) {
                $this->refreshQuote($security);
                $quote = MarketQuote::query()
                    ->where('security_id', $security->id)
                    ->latest('quote_time')
                    ->first();
            }

            return $quote;
        });
    }

    public function dailyBars(
        Security $security,
        ?string $startDate = null,
        ?string $endDate = null,
        string $adjust = 'none'
    ): Collection {
        $resolvedEndDate = $endDate ?: now()->toDateString();
        $resolvedStartDate = $startDate ?: now()->subYear()->toDateString();
        $cacheKey = "stock:daily:{$security->canonical_symbol}:{$resolvedStartDate}:{$resolvedEndDate}:{$adjust}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use (
            $security,
            $resolvedStartDate,
            $resolvedEndDate,
            $adjust
        ) {
            $bars = $this->queryDailyBars($security, $resolvedStartDate, $resolvedEndDate, $adjust);

            if ($bars->isEmpty()) {
                $this->refreshDailyBars($security, $resolvedStartDate, $resolvedEndDate, $adjust);
                $bars = $this->queryDailyBars($security, $resolvedStartDate, $resolvedEndDate, $adjust);
            }

            return $bars;
        });
    }

    public function indexSnapshots(): Collection
    {
        return Cache::remember('market:indices:snapshot', now()->addSeconds(60), function () {
            $indices = MarketIndex::query()->orderBy('code')->get();
            $latestQuoteTime = MarketIndex::query()->max('quote_time');

            if ($indices->isEmpty() || $latestQuoteTime === null || Carbon::parse($latestQuoteTime)->lt(now()->subMinutes(10))) {
                $this->refreshIndices();
                $indices = MarketIndex::query()->orderBy('code')->get();
            }

            return $indices;
        });
    }

    public function indexDailyBars(
        MarketIndex $index,
        ?string $startDate = null,
        ?string $endDate = null
    ): Collection {
        $resolvedEndDate = $endDate ?: now()->toDateString();
        $resolvedStartDate = $startDate ?: now()->subYear()->toDateString();
        $cacheKey = "index:daily:{$index->code}:{$resolvedStartDate}:{$resolvedEndDate}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use (
            $index,
            $resolvedStartDate,
            $resolvedEndDate
        ) {
            $bars = IndexDailyBar::query()
                ->where('market_index_id', $index->id)
                ->whereBetween('trade_date', [$resolvedStartDate, $resolvedEndDate])
                ->orderBy('trade_date')
                ->get();

            if ($bars->isEmpty()) {
                $this->refreshIndexDailyBars($index, $resolvedStartDate, $resolvedEndDate);
                $bars = IndexDailyBar::query()
                    ->where('market_index_id', $index->id)
                    ->whereBetween('trade_date', [$resolvedStartDate, $resolvedEndDate])
                    ->orderBy('trade_date')
                    ->get();
            }

            return $bars;
        });
    }

    private function queryDailyBars(
        Security $security,
        string $startDate,
        string $endDate,
        string $adjust
    ): Collection {
        return MarketDailyBar::query()
            ->where('security_id', $security->id)
            ->where('adjust_type', $adjust)
            ->whereBetween('trade_date', [$startDate, $endDate])
            ->orderBy('trade_date')
            ->get();
    }

    private function refreshQuote(Security $security): void
    {
        try {
            $this->intelligenceClient->syncQuotes([$security->symbol]);
        } catch (Throwable $exception) {
            Log::warning('Failed to refresh quote from intelligence service.', [
                'security' => $security->canonical_symbol,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function refreshDailyBars(Security $security, string $startDate, string $endDate, string $adjust): void
    {
        try {
            $this->intelligenceClient->syncDailyBars($security->symbol, $startDate, $endDate, $adjust);
        } catch (Throwable $exception) {
            Log::warning('Failed to refresh daily bars from intelligence service.', [
                'security' => $security->canonical_symbol,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function refreshIndices(): void
    {
        try {
            $this->intelligenceClient->syncIndices();
        } catch (Throwable $exception) {
            Log::warning('Failed to refresh indices from intelligence service.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function refreshIndexDailyBars(MarketIndex $index, string $startDate, string $endDate): void
    {
        try {
            $this->intelligenceClient->syncIndexDailyBars($index->code, $startDate, $endDate);
        } catch (Throwable $exception) {
            Log::warning('Failed to refresh index daily bars from intelligence service.', [
                'index' => $index->code,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
