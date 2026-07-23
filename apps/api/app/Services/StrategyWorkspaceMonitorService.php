<?php

namespace App\Services;

use App\Http\Resources\TradingSignalResource;
use App\Models\MarketQuote;
use App\Models\SignalSubscription;
use App\Models\StrategyWorkspace;
use App\Models\StrategyWorkspaceItem;
use App\Models\TradingSignal;
use Illuminate\Support\Collection;

class StrategyWorkspaceMonitorService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(StrategyWorkspace $workspace): array
    {
        $workspace->loadMissing([
            'defaultSignalSubscription',
            'items.security',
        ]);

        $items = $workspace->items
            ->filter(fn (StrategyWorkspaceItem $item): bool => $item->status === 'active')
            ->values();
        $securityIds = $items->pluck('security_id')->filter()->values();

        $quotes = $this->latestQuotes($securityIds);
        $signalsBySecurity = $this->recentSignals($securityIds)->groupBy('primary_security_id');

        $monitors = $items->map(function (StrategyWorkspaceItem $item) use ($quotes, $signalsBySecurity): array {
            $quote = $quotes->get($item->security_id);
            $signals = $signalsBySecurity->get($item->security_id, collect());

            return $this->monitorRow($item, $quote, $signals);
        })->values();

        $portfolioValue = round((float) $monitors->sum('market_value'), 2);
        $costValue = round((float) $monitors->sum('cost_value'), 2);
        $unrealizedPnl = round((float) $monitors->sum('unrealized_pnl'), 2);
        $highRiskCount = $monitors->where('risk_state', 'risk')->count();
        $opportunityCount = $monitors->where('risk_state', 'opportunity')->count();
        $triggeredCount = $monitors->where('alert_triggered', true)->count();

        return [
            'workspace' => [
                'id' => $workspace->id,
                'workspace_key' => $workspace->workspace_key,
                'name' => $workspace->name,
                'workspace_type' => $workspace->workspace_type,
                'risk_profile' => $workspace->risk_profile,
                'base_currency' => $workspace->base_currency,
                'enabled' => $workspace->enabled,
                'last_reviewed_at' => optional($workspace->last_reviewed_at)?->toAtomString(),
            ],
            'overview' => [
                'item_count' => $items->count(),
                'holding_count' => $items->where('item_type', 'holding')->count(),
                'watch_count' => $items->where('item_type', 'watch')->count(),
                'portfolio_value' => $portfolioValue,
                'cost_value' => $costValue,
                'unrealized_pnl' => $unrealizedPnl,
                'unrealized_pnl_pct' => $costValue > 0 ? round($unrealizedPnl / $costValue * 100, 2) : null,
                'high_risk_count' => $highRiskCount,
                'opportunity_count' => $opportunityCount,
                'alert_triggered_count' => $triggeredCount,
                'avg_signal_score' => round((float) $monitors->avg('top_signal_score'), 2),
            ],
            'subscription_bridge' => $this->subscriptionBridge($workspace, $items),
            'monitors' => $monitors
                ->sortByDesc(fn (array $row): float => (float) ($row['attention_score'] ?? 0))
                ->values()
                ->all(),
            'top_signals' => TradingSignalResource::collection(
                $this->recentSignals($securityIds)
                    ->sortByDesc(fn (TradingSignal $signal): float => (float) $signal->signal_score)
                    ->take(8)
                    ->values()
            ),
        ];
    }

    /**
     * @param  Collection<int, int>  $securityIds
     * @return Collection<int, MarketQuote>
     */
    private function latestQuotes(Collection $securityIds): Collection
    {
        if ($securityIds->isEmpty()) {
            return collect();
        }

        return MarketQuote::query()
            ->whereIn('security_id', $securityIds->all())
            ->whereIn('id', function ($query) use ($securityIds): void {
                $query
                    ->selectRaw('MAX(id)')
                    ->from('market_quotes')
                    ->whereIn('security_id', $securityIds->all())
                    ->groupBy('security_id');
            })
            ->get()
            ->keyBy('security_id');
    }

    /**
     * @param  Collection<int, int>  $securityIds
     * @return Collection<int, TradingSignal>
     */
    private function recentSignals(Collection $securityIds): Collection
    {
        if ($securityIds->isEmpty()) {
            return collect();
        }

        return TradingSignal::query()
            ->with(['rule', 'eventChain', 'latestEvent', 'primarySecurity'])
            ->whereIn('primary_security_id', $securityIds->all())
            ->where('status', 'active')
            ->whereNotNull('published_at')
            ->orderByDesc('signal_score')
            ->orderByDesc('published_at')
            ->limit(60)
            ->get();
    }

    /**
     * @param  Collection<int, TradingSignal>  $signals
     * @return array<string, mixed>
     */
    private function monitorRow(StrategyWorkspaceItem $item, ?MarketQuote $quote, Collection $signals): array
    {
        $lastPrice = $quote?->last_price !== null ? (float) $quote->last_price : null;
        $quantity = $item->position_quantity !== null ? (float) $item->position_quantity : null;
        $averageCost = $item->average_cost !== null ? (float) $item->average_cost : null;
        $marketValue = $lastPrice !== null && $quantity !== null ? round($lastPrice * $quantity, 2) : null;
        $costValue = $averageCost !== null && $quantity !== null ? round($averageCost * $quantity, 2) : null;
        $unrealizedPnl = $marketValue !== null && $costValue !== null ? round($marketValue - $costValue, 2) : null;
        $topSignal = $signals->first();
        $negativeSignals = $signals->where('direction', 'negative')->count();
        $positiveSignals = $signals->where('direction', 'positive')->count();
        $topSignalScore = $topSignal ? (float) $topSignal->signal_score : 0.0;
        $alertTriggered = $topSignalScore >= (float) $item->alert_score_threshold;

        $targetDistancePct = $lastPrice !== null && $item->target_price !== null && $lastPrice > 0
            ? round(((float) $item->target_price - $lastPrice) / $lastPrice * 100, 2)
            : null;
        $stopLossDistancePct = $lastPrice !== null && $item->stop_loss_price !== null && $lastPrice > 0
            ? round(($lastPrice - (float) $item->stop_loss_price) / $lastPrice * 100, 2)
            : null;

        $riskState = match (true) {
            $negativeSignals > 0 && $topSignalScore >= (float) $item->alert_score_threshold => 'risk',
            $positiveSignals > 0 && $topSignalScore >= (float) $item->alert_score_threshold => 'opportunity',
            $signals->isNotEmpty() => 'watch',
            default => 'quiet',
        };

        return [
            'item_id' => $item->id,
            'security' => $item->security ? [
                'id' => $item->security->id,
                'canonical_symbol' => $item->security->canonical_symbol,
                'symbol' => $item->security->symbol,
                'name' => $item->security->name,
                'exchange' => $item->security->exchange,
            ] : null,
            'item_type' => $item->item_type,
            'status' => $item->status,
            'position_quantity' => $quantity,
            'average_cost' => $averageCost,
            'last_price' => $lastPrice,
            'pct_change' => $quote?->pct_change !== null ? (float) $quote->pct_change : null,
            'quote_time' => optional($quote?->quote_time)?->toAtomString(),
            'market_value' => $marketValue,
            'cost_value' => $costValue,
            'unrealized_pnl' => $unrealizedPnl,
            'unrealized_pnl_pct' => $costValue !== null && $costValue > 0 && $unrealizedPnl !== null ? round($unrealizedPnl / $costValue * 100, 2) : null,
            'target_price' => $item->target_price !== null ? (float) $item->target_price : null,
            'target_distance_pct' => $targetDistancePct,
            'stop_loss_price' => $item->stop_loss_price !== null ? (float) $item->stop_loss_price : null,
            'stop_loss_distance_pct' => $stopLossDistancePct,
            'alert_score_threshold' => (float) $item->alert_score_threshold,
            'alert_triggered' => $alertTriggered,
            'risk_state' => $riskState,
            'attention_score' => round($topSignalScore + ($negativeSignals * 8) + ($alertTriggered ? 10 : 0), 2),
            'signal_count' => $signals->count(),
            'positive_signal_count' => $positiveSignals,
            'negative_signal_count' => $negativeSignals,
            'top_signal_score' => $topSignalScore,
            'top_signal' => $topSignal ? [
                'id' => $topSignal->id,
                'title' => $topSignal->title,
                'signal_type' => $topSignal->signal_type,
                'direction' => $topSignal->direction,
                'signal_score' => (float) $topSignal->signal_score,
                'published_at' => optional($topSignal->published_at)?->toAtomString(),
            ] : null,
            'tags' => $item->tags,
            'notes' => $item->notes,
        ];
    }

    /**
     * @param  Collection<int, StrategyWorkspaceItem>  $items
     * @return array<string, mixed>
     */
    private function subscriptionBridge(StrategyWorkspace $workspace, Collection $items): array
    {
        $subscription = $workspace->defaultSignalSubscription;
        $symbols = $items
            ->map(fn (StrategyWorkspaceItem $item): ?string => $item->security?->symbol)
            ->filter()
            ->values()
            ->all();

        return [
            'linked' => $subscription !== null,
            'subscription_id' => $subscription?->id,
            'subscriber_key' => $subscription?->subscriber_key,
            'subscriber_name' => $subscription?->subscriber_name,
            'enabled' => $subscription?->enabled,
            'min_signal_score' => $subscription?->min_signal_score !== null ? (float) $subscription->min_signal_score : null,
            'security_symbols' => $symbols,
            'coverage_count' => $subscription ? $this->coveredSymbolCount($subscription, $symbols) : 0,
        ];
    }

    /**
     * @param  array<int, string>  $symbols
     */
    private function coveredSymbolCount(SignalSubscription $subscription, array $symbols): int
    {
        $filters = $subscription->filters ?? [];
        $configuredSymbols = collect($filters['security_symbols'] ?? [])->map(fn ($value): string => (string) $value)->filter();

        if ($configuredSymbols->isEmpty()) {
            return count($symbols);
        }

        return collect($symbols)->intersect($configuredSymbols)->count();
    }
}
