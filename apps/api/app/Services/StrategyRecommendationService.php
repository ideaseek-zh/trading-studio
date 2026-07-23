<?php

namespace App\Services;

use App\Models\EventChain;
use App\Models\StrategyWorkspace;
use App\Models\TradingSignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StrategyRecommendationService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function recommendations(?StrategyWorkspace $workspace = null, int $limit = 20): array
    {
        $workspaceSymbols = $workspace
            ? $workspace->items()->with('security')->get()
                ->map(fn ($item): ?string => $item->security?->symbol)
                ->filter()
                ->values()
                ->all()
            : [];

        $signalGroups = TradingSignal::query()
            ->with(['primarySecurity', 'eventChain', 'latestEvent'])
            ->where('status', 'active')
            ->whereNotNull('primary_security_id')
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now()->subDays(14))
            ->orderByDesc('signal_score')
            ->orderByDesc('published_at')
            ->limit(200)
            ->get()
            ->groupBy('primary_security_id');

        $chainGroups = EventChain::query()
            ->with('primarySecurity')
            ->whereNotNull('primary_security_id')
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->where('latest_published_at', '>=', now()->subDays(14))
                    ->orWhere('latest_occurred_at', '>=', now()->subDays(14));
            })
            ->orderByDesc('latest_published_at')
            ->limit(200)
            ->get()
            ->groupBy('primary_security_id');

        return $signalGroups
            ->keys()
            ->merge($chainGroups->keys())
            ->unique()
            ->map(function ($securityId) use ($signalGroups, $chainGroups, $workspaceSymbols): ?array {
                $signals = $signalGroups->get($securityId, collect());
                $chains = $chainGroups->get($securityId, collect());
                $security = $signals->first()?->primarySecurity ?? $chains->first()?->primarySecurity;

                if ($security === null) {
                    return null;
                }

                return $this->recommendationRow($security, $signals, $chains, in_array($security->symbol, $workspaceSymbols, true));
            })
            ->filter()
            ->sortByDesc('recommendation_score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TradingSignal>  $signals
     * @param  Collection<int, EventChain>  $chains
     * @return array<string, mixed>
     */
    private function recommendationRow($security, Collection $signals, Collection $chains, bool $alreadyTracked): array
    {
        $topSignal = $signals->sortByDesc('signal_score')->first();
        $latestAt = $signals
            ->map(fn (TradingSignal $signal): ?Carbon => $signal->published_at)
            ->merge($chains->map(fn (EventChain $chain): ?Carbon => $chain->latest_published_at ?? $chain->latest_occurred_at))
            ->filter()
            ->sortDesc()
            ->first();

        $positiveCount = $signals->where('direction', 'positive')->count();
        $negativeCount = $signals->where('direction', 'negative')->count();
        $maxSignalScore = (float) ($signals->max('signal_score') ?? 0);
        $avgSignalScore = (float) ($signals->avg('signal_score') ?? 0);
        $eventHeat = (int) $chains->sum('event_count') + (int) $chains->sum('article_count');
        $freshnessScore = $latestAt instanceof Carbon
            ? max(0, 20 - min(20, $latestAt->diffInHours(now()) / 6))
            : 0;

        $score = round(
            $maxSignalScore * 0.45
            + $avgSignalScore * 0.20
            + min($eventHeat, 30) * 0.80
            + $freshnessScore
            + ($positiveCount > 0 ? 8 : 0)
            + ($negativeCount > 0 ? 6 : 0)
            - ($alreadyTracked ? 8 : 0),
            2
        );

        $recommendationType = match (true) {
            $negativeCount > 0 && $maxSignalScore >= 70 => 'risk_watch',
            $positiveCount > 0 && $maxSignalScore >= 70 => 'opportunity',
            $eventHeat >= 8 => 'hot_topic',
            default => 'observe',
        };

        return [
            'security' => [
                'id' => $security->id,
                'canonical_symbol' => $security->canonical_symbol,
                'symbol' => $security->symbol,
                'name' => $security->name,
                'exchange' => $security->exchange,
            ],
            'recommendation_score' => $score,
            'recommendation_type' => $recommendationType,
            'already_tracked' => $alreadyTracked,
            'signal_count' => $signals->count(),
            'positive_signal_count' => $positiveCount,
            'negative_signal_count' => $negativeCount,
            'event_chain_count' => $chains->count(),
            'event_heat' => $eventHeat,
            'latest_published_at' => optional($latestAt)?->toAtomString(),
            'top_signal' => $topSignal ? [
                'id' => $topSignal->id,
                'title' => $topSignal->title,
                'signal_type' => $topSignal->signal_type,
                'direction' => $topSignal->direction,
                'signal_score' => (float) $topSignal->signal_score,
                'published_at' => optional($topSignal->published_at)?->toAtomString(),
            ] : null,
            'top_chains' => $chains
                ->sortByDesc('latest_published_at')
                ->take(3)
                ->map(fn (EventChain $chain): array => [
                    'id' => $chain->id,
                    'chain_type' => $chain->chain_type,
                    'topic' => $chain->topic,
                    'importance_level' => $chain->importance_level,
                    'sentiment' => $chain->sentiment,
                    'event_count' => $chain->event_count,
                    'article_count' => $chain->article_count,
                    'latest_published_at' => optional($chain->latest_published_at)?->toAtomString(),
                ])
                ->values()
                ->all(),
            'reasons' => $this->reasons($recommendationType, $signals, $chains, $eventHeat, $alreadyTracked),
        ];
    }

    /**
     * @param  Collection<int, TradingSignal>  $signals
     * @param  Collection<int, EventChain>  $chains
     * @return array<int, string>
     */
    private function reasons(string $type, Collection $signals, Collection $chains, int $eventHeat, bool $alreadyTracked): array
    {
        $reasons = [];
        $topSignal = $signals->sortByDesc('signal_score')->first();

        if ($topSignal) {
            $reasons[] = sprintf('最高信号分 %.1f，类型 %s', (float) $topSignal->signal_score, $this->signalTypeLabel($topSignal->signal_type));
        }

        if ($eventHeat > 0) {
            $reasons[] = sprintf('近 14 天事件/文章热度 %d', $eventHeat);
        }

        if ($type === 'risk_watch') {
            $reasons[] = '存在高分负向信号，适合加入风险观察';
        } elseif ($type === 'opportunity') {
            $reasons[] = '存在高分正向信号，适合加入机会观察';
        } elseif ($type === 'hot_topic') {
            $reasons[] = '消息密度较高，适合跟踪主题演化';
        }

        if ($chains->isNotEmpty()) {
            $reasons[] = '最新事件链：'.$chains->sortByDesc('latest_published_at')->first()->topic;
        }

        if ($alreadyTracked) {
            $reasons[] = '已在当前工作台中';
        }

        return array_values(array_unique($reasons));
    }

    private function signalTypeLabel(string $type): string
    {
        return [
            'alpha_opportunity' => '机会信号',
            'risk_alert' => '风险预警',
            'financing_watch' => '融资/债券关注',
            'research_watch' => '调研关注',
            'event_heat' => '热点异动',
            'policy_watch' => '政策关注',
            'earnings_watch' => '业绩关注',
            'buyback' => '回购关注',
            'shareholder_change' => '股东变化',
            'litigation_watch' => '诉讼风险',
        ][$type] ?? $type;
    }
}
