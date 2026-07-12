<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TradingSignalResource;
use App\Models\SignalSubscription;
use App\Models\TradingSignal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

class SignalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->string('sortBy')->toString() ?: 'dashboardRank';
        $direction = strtolower($request->string('sortDirection')->toString() ?: 'desc') === 'asc' ? 'asc' : 'desc';

        $signals = $this->signalQuery($request);
        $paginated = $this->applySorting($signals, $sortBy, $direction)
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => TradingSignalResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'summary' => $this->summary($request),
                'filter_options' => $this->filterOptions(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $baseQuery = $this->signalQuery($request);
        $topSignals = $this->applySorting(clone $baseQuery, 'dashboardRank', 'desc')
            ->limit($request->integer('topSize', 8))
            ->get();

        $riskAlerts = (clone $baseQuery)
            ->where('direction', 'negative')
            ->orderByDesc('risk_score')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();

        $pendingEvaluations = (clone $baseQuery)
            ->where('performance_summary->evaluation_status', '!=', 'evaluated')
            ->orderByDesc('published_at')
            ->limit(6)
            ->get();

        $subscriptions = SignalSubscription::query()
            ->withCount('deliveries')
            ->orderBy('priority_order')
            ->limit(8)
            ->get();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'overview' => $this->summary($request),
                'top_signals' => TradingSignalResource::collection($topSignals),
                'risk_alerts' => TradingSignalResource::collection($riskAlerts),
                'pending_evaluations' => TradingSignalResource::collection($pendingEvaluations),
                'subscription_overview' => [
                    'total' => SignalSubscription::query()->count(),
                    'enabled' => SignalSubscription::query()->where('enabled', true)->count(),
                    'critical_priority' => SignalSubscription::query()->where('priority_level', 'critical')->count(),
                    'by_priority_level' => SignalSubscription::query()
                        ->select('priority_level')
                        ->selectRaw('COUNT(*) as aggregate, MIN(priority_order) as priority_sort')
                        ->groupBy('priority_level')
                        ->orderBy('priority_sort')
                        ->pluck('aggregate', 'priority_level')
                        ->all(),
                    'items' => $subscriptions->map(fn (SignalSubscription $subscription): array => [
                        'id' => $subscription->id,
                        'subscriber_key' => $subscription->subscriber_key,
                        'subscriber_name' => $subscription->subscriber_name,
                        'priority_level' => $subscription->priority_level,
                        'priority_order' => $subscription->priority_order,
                        'enabled' => $subscription->enabled,
                        'min_signal_score' => (float) $subscription->min_signal_score,
                        'deliveries_count' => $subscription->deliveries_count,
                        'last_notified_at' => optional($subscription->last_notified_at)?->toAtomString(),
                    ])->values()->all(),
                ],
                'heatmap' => $this->symbolHeatmap($request),
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function show(TradingSignal $signal): JsonResponse
    {
        $signal->load(['rule', 'eventChain', 'latestEvent', 'primarySecurity', 'deliveries', 'performanceSnapshots']);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new TradingSignalResource($signal),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    private function signalQuery(Request $request): Builder
    {
        $signals = TradingSignal::query()
            ->select('trading_signals.*')
            ->selectRaw($this->latestAlphaExpression().' as latest_alpha_return_pct')
            ->selectRaw($this->latestReturnExpression().' as latest_return_pct')
            ->selectRaw($this->dashboardRankExpression().' as dashboard_rank')
            ->selectRaw($this->priorityTierExpression().' as priority_tier')
            ->with(['rule', 'eventChain', 'latestEvent', 'primarySecurity']);

        $this->applyFilters($signals, $request);

        return $signals;
    }

    private function applyFilters(Builder $signals, Request $request): void
    {
        $signalTypes = $this->csvInput($request, 'signalTypes', 'signalType');
        $statuses = $this->csvInput($request, 'statuses', 'status');
        $directions = $this->csvInput($request, 'directions', 'direction');
        $chainTypes = $this->csvInput($request, 'chainTypes', 'chainType');
        $securitySymbols = $this->csvInput($request, 'securitySymbols', 'securitySymbol');
        $evaluationStatuses = $this->csvInput($request, 'evaluationStatuses', 'evaluationStatus');
        $timelineStages = $this->csvInput($request, 'timelineStages', 'timelineStage');
        $priorityTiers = $this->csvInput($request, 'priorityTiers', 'priorityTier');

        if ($signalTypes !== []) {
            $signals->whereIn('signal_type', $signalTypes);
        }
        if ($statuses !== []) {
            $signals->whereIn('status', $statuses);
        }
        if ($directions !== []) {
            $signals->whereIn('direction', $directions);
        }
        if ($evaluationStatuses !== []) {
            $signals->whereIn('performance_summary->evaluation_status', $evaluationStatuses);
        }
        if ($timelineStages !== []) {
            $signals->whereHas('latestEvent', fn (Builder $query) => $query->whereIn('timeline_stage', $timelineStages));
        }
        if ($chainTypes !== []) {
            $signals->whereHas('eventChain', fn (Builder $query) => $query->whereIn('chain_type', $chainTypes));
        }
        if ($securitySymbols !== []) {
            $signals->whereHas('primarySecurity', fn (Builder $query) => $query->whereIn('symbol', $securitySymbols));
        }
        if ($priorityTiers !== []) {
            $signals->whereRaw($this->priorityTierExpression().' in ('.implode(',', array_fill(0, count($priorityTiers), '?')).')', $priorityTiers);
        }

        if ($request->filled('minScore')) {
            $signals->where('signal_score', '>=', (float) $request->input('minScore'));
        }
        if ($request->filled('minUrgencyScore')) {
            $signals->where('urgency_score', '>=', (float) $request->input('minUrgencyScore'));
        }
        if ($request->filled('minConfidenceScore')) {
            $signals->where('confidence_score', '>=', (float) $request->input('minConfidenceScore'));
        }
        if ($request->filled('minImpactScore')) {
            $signals->where('impact_score', '>=', (float) $request->input('minImpactScore'));
        }
        if ($request->filled('maxRiskScore')) {
            $signals->where('risk_score', '<=', (float) $request->input('maxRiskScore'));
        }
        if ($request->filled('minAlphaReturn')) {
            $signals->where('performance_summary->latest_alpha_return_pct', '>=', (float) $request->input('minAlphaReturn'));
        }
        if ($request->filled('minReturnPct')) {
            $signals->where('performance_summary->latest_return_pct', '>=', (float) $request->input('minReturnPct'));
        }
        if ($request->boolean('highPriorityOnly')) {
            $signals->whereRaw($this->priorityTierExpression().' in (?, ?)', ['critical', 'high']);
        }
    }

    private function applySorting(Builder $signals, string $sortBy, string $direction): Builder
    {
        match ($sortBy) {
            'urgency' => $signals->orderBy('urgency_score', $direction),
            'impact' => $signals->orderBy('impact_score', $direction),
            'confidence' => $signals->orderBy('confidence_score', $direction),
            'risk' => $signals->orderBy('risk_score', $direction),
            'alpha' => $signals->orderBy('latest_alpha_return_pct', $direction),
            'return' => $signals->orderBy('latest_return_pct', $direction),
            'publishedAt' => $signals->orderBy('published_at', $direction),
            'triggeredAt' => $signals->orderBy('triggered_at', $direction),
            'dashboardRank' => $signals->orderBy('dashboard_rank', $direction),
            default => $signals->orderBy('signal_score', $direction),
        };

        return $signals->orderByDesc('published_at')->orderByDesc('id');
    }

    private function summary(Request $request): array
    {
        $rows = $this->signalQuery($request)->get();

        return [
            'total' => $rows->count(),
            'active' => $rows->where('status', 'active')->count(),
            'positive' => $rows->where('direction', 'positive')->count(),
            'negative' => $rows->where('direction', 'negative')->count(),
            'high_priority' => $rows->filter(fn (TradingSignal $signal) => in_array($signal->priority_tier, ['critical', 'high'], true))->count(),
            'evaluated' => $rows->filter(fn (TradingSignal $signal) => ($signal->performance_summary['evaluation_status'] ?? 'pending') === 'evaluated')->count(),
            'pending_evaluation' => $rows->filter(fn (TradingSignal $signal) => ($signal->performance_summary['evaluation_status'] ?? 'pending') !== 'evaluated')->count(),
            'avg_signal_score' => round((float) $rows->avg('signal_score'), 2),
            'avg_dashboard_rank' => round((float) $rows->avg('dashboard_rank'), 2),
            'by_signal_type' => $rows->pluck('signal_type')->countBy()->all(),
            'by_direction' => $rows->pluck('direction')->countBy()->all(),
            'by_priority_tier' => $rows->map(fn (TradingSignal $signal): string => $signal->priority_tier)->countBy()->all(),
            'by_timeline_stage' => $rows
                ->map(fn (TradingSignal $signal): string => $signal->latestEvent?->timeline_stage ?? 'unknown')
                ->countBy()
                ->all(),
        ];
    }

    private function filterOptions(): array
    {
        return [
            'signal_types' => TradingSignal::query()->select('signal_type')->distinct()->orderBy('signal_type')->pluck('signal_type')->values()->all(),
            'directions' => TradingSignal::query()->select('direction')->distinct()->orderBy('direction')->pluck('direction')->values()->all(),
            'statuses' => TradingSignal::query()->select('status')->distinct()->orderBy('status')->pluck('status')->values()->all(),
            'chain_types' => TradingSignal::query()
                ->join('event_chains', 'event_chains.id', '=', 'trading_signals.event_chain_id')
                ->select('event_chains.chain_type')
                ->distinct()
                ->orderBy('event_chains.chain_type')
                ->pluck('event_chains.chain_type')
                ->values()
                ->all(),
            'timeline_stages' => TradingSignal::query()
                ->join('events', 'events.id', '=', 'trading_signals.latest_event_id')
                ->select('events.timeline_stage')
                ->whereNotNull('events.timeline_stage')
                ->distinct()
                ->orderBy('events.timeline_stage')
                ->pluck('events.timeline_stage')
                ->values()
                ->all(),
            'security_symbols' => TradingSignal::query()
                ->join('securities', 'securities.id', '=', 'trading_signals.primary_security_id')
                ->select('securities.symbol')
                ->whereNotNull('securities.symbol')
                ->distinct()
                ->orderBy('securities.symbol')
                ->pluck('securities.symbol')
                ->values()
                ->all(),
            'priority_tiers' => ['critical', 'high', 'normal', 'observe'],
            'evaluation_statuses' => ['evaluated', 'pending', 'no_market_data', 'no_security'],
            'sort_options' => [
                ['key' => 'dashboardRank', 'label' => '综合看板排序'],
                ['key' => 'score', 'label' => '信号分'],
                ['key' => 'urgency', 'label' => '时效性'],
                ['key' => 'impact', 'label' => '影响力'],
                ['key' => 'confidence', 'label' => '置信度'],
                ['key' => 'risk', 'label' => '风险分'],
                ['key' => 'alpha', 'label' => '超额收益'],
                ['key' => 'return', 'label' => '绝对收益'],
                ['key' => 'publishedAt', 'label' => '发布时间'],
                ['key' => 'triggeredAt', 'label' => '触发时间'],
            ],
        ];
    }

    private function symbolHeatmap(Request $request): array
    {
        $rows = $this->signalQuery($request)->get()->groupBy('primary_security_id');

        return $rows->map(function (Collection $items): array {
            /** @var TradingSignal $top */
            $top = $items->sortByDesc('dashboard_rank')->first();

            return [
                'security_id' => $top->primary_security_id,
                'symbol' => $top->primarySecurity?->symbol,
                'name' => $top->primarySecurity?->name,
                'signal_count' => $items->count(),
                'avg_signal_score' => round((float) $items->avg('signal_score'), 2),
                'max_dashboard_rank' => round((float) $items->max('dashboard_rank'), 2),
                'directions' => $items->pluck('direction')->countBy()->all(),
                'top_signal' => [
                    'id' => $top->id,
                    'title' => $top->title,
                    'signal_type' => $top->signal_type,
                    'priority_tier' => $top->priority_tier,
                    'dashboard_rank' => round((float) $top->dashboard_rank, 2),
                ],
            ];
        })->sortByDesc('max_dashboard_rank')->values()->all();
    }

    private function csvInput(Request $request, string $pluralKey, string $singularKey): array
    {
        $raw = $request->input($pluralKey);
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }

        $raw = $request->string($pluralKey)->toString() ?: $request->string($singularKey)->toString();
        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->values()
            ->all();
    }

    private function latestAlphaExpression(): string
    {
        if ($this->databaseDriver() === 'sqlite') {
            return "COALESCE(CAST(json_extract(performance_summary, '$.latest_alpha_return_pct') AS REAL), 0)";
        }

        return "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(performance_summary, '$.latest_alpha_return_pct')) AS DECIMAL(12,4)), 0)";
    }

    private function latestReturnExpression(): string
    {
        if ($this->databaseDriver() === 'sqlite') {
            return "COALESCE(CAST(json_extract(performance_summary, '$.latest_return_pct') AS REAL), 0)";
        }

        return "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(performance_summary, '$.latest_return_pct')) AS DECIMAL(12,4)), 0)";
    }

    private function priorityTierExpression(): string
    {
        return "CASE
            WHEN signal_score >= 80 AND risk_score <= 35 THEN 'critical'
            WHEN signal_score >= 70 THEN 'high'
            WHEN signal_score >= 55 THEN 'normal'
            ELSE 'observe'
        END";
    }

    private function dashboardRankExpression(): string
    {
        return "ROUND(
            signal_score * 0.45
            + urgency_score * 0.20
            + confidence_score * 0.15
            + impact_score * 0.15
            + (100 - risk_score) * 0.05
            + ".$this->latestAlphaExpression()." * 1.5,
        2)";
    }

    private function databaseDriver(): string
    {
        return DB::connection()->getDriverName();
    }
}
