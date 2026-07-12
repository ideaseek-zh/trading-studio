<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TradingSignalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signal_key' => $this->signal_key,
            'signal_type' => $this->signal_type,
            'direction' => $this->direction,
            'horizon_label' => $this->horizon_label,
            'status' => $this->status,
            'title' => $this->title,
            'summary' => $this->summary,
            'signal_score' => (float) $this->signal_score,
            'dashboard_rank' => isset($this->dashboard_rank) ? (float) $this->dashboard_rank : $this->dashboardRank(),
            'confidence_score' => (float) $this->confidence_score,
            'urgency_score' => (float) $this->urgency_score,
            'impact_score' => (float) $this->impact_score,
            'risk_score' => (float) $this->risk_score,
            'priority_tier' => $this->priority_tier ?? $this->priorityTier(),
            'evaluation_status' => $this->performance_summary['evaluation_status'] ?? 'pending',
            'triggered_at' => optional($this->triggered_at)?->toAtomString(),
            'published_at' => optional($this->published_at)?->toAtomString(),
            'expires_at' => optional($this->expires_at)?->toAtomString(),
            'reasoning' => $this->reasoning,
            'explanation' => $this->explanation,
            'performance_summary' => $this->performance_summary,
            'last_evaluated_at' => optional($this->last_evaluated_at)?->toAtomString(),
            'facts' => $this->facts,
            'sort_metrics' => [
                'latest_alpha_return_pct' => $this->performance_summary['latest_alpha_return_pct'] ?? null,
                'latest_return_pct' => $this->performance_summary['latest_return_pct'] ?? null,
                'best_alpha_return_pct' => $this->performance_summary['best_alpha_return_pct'] ?? null,
                'best_return_pct' => $this->performance_summary['best_return_pct'] ?? null,
            ],
            'rule' => $this->rule ? [
                'id' => $this->rule->id,
                'rule_key' => $this->rule->rule_key,
                'name' => $this->rule->name,
            ] : null,
            'chain' => $this->eventChain ? [
                'id' => $this->eventChain->id,
                'chain_key' => $this->eventChain->chain_key,
                'chain_type' => $this->eventChain->chain_type,
                'topic' => $this->eventChain->topic,
                'status' => $this->eventChain->status,
            ] : null,
            'latest_event' => $this->latestEvent ? [
                'id' => $this->latestEvent->id,
                'event_type' => $this->latestEvent->event_type,
                'title' => $this->latestEvent->title,
                'timeline_stage' => $this->latestEvent->timeline_stage,
                'occurred_at' => optional($this->latestEvent->occurred_at)?->toAtomString(),
            ] : null,
            'primary_security' => $this->primarySecurity ? [
                'id' => $this->primarySecurity->id,
                'canonical_symbol' => $this->primarySecurity->canonical_symbol,
                'symbol' => $this->primarySecurity->symbol,
                'name' => $this->primarySecurity->name,
            ] : null,
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn ($delivery): array => [
                'id' => $delivery->id,
                'delivery_status' => $delivery->delivery_status,
                'delivery_channel' => $delivery->delivery_channel,
                'attempts' => $delivery->attempts,
                'delivered_at' => optional($delivery->delivered_at)?->toAtomString(),
            ])->values()->all()),
            'performance_snapshots' => $this->whenLoaded('performanceSnapshots', fn () => $this->performanceSnapshots
                ->sortBy('horizon_days')
                ->map(fn ($snapshot): array => [
                    'id' => $snapshot->id,
                    'horizon_days' => $snapshot->horizon_days,
                    'evaluation_status' => $snapshot->evaluation_status,
                    'benchmark_code' => $snapshot->benchmark_code,
                    'entry_trade_date' => optional($snapshot->entry_trade_date)?->toDateString(),
                    'exit_trade_date' => optional($snapshot->exit_trade_date)?->toDateString(),
                    'holding_days' => $snapshot->holding_days,
                    'entry_price' => $snapshot->entry_price !== null ? (float) $snapshot->entry_price : null,
                    'exit_price' => $snapshot->exit_price !== null ? (float) $snapshot->exit_price : null,
                    'return_pct' => $snapshot->return_pct !== null ? (float) $snapshot->return_pct : null,
                    'benchmark_return_pct' => $snapshot->benchmark_return_pct !== null ? (float) $snapshot->benchmark_return_pct : null,
                    'alpha_return_pct' => $snapshot->alpha_return_pct !== null ? (float) $snapshot->alpha_return_pct : null,
                    'max_upside_pct' => $snapshot->max_upside_pct !== null ? (float) $snapshot->max_upside_pct : null,
                    'max_drawdown_pct' => $snapshot->max_drawdown_pct !== null ? (float) $snapshot->max_drawdown_pct : null,
                    'win_probability' => $snapshot->win_probability !== null ? (float) $snapshot->win_probability : null,
                    'coverage_pct' => $snapshot->coverage_pct !== null ? (float) $snapshot->coverage_pct : null,
                    'evaluated_at' => optional($snapshot->evaluated_at)?->toAtomString(),
                    'metrics' => $snapshot->metrics,
                ])->values()->all()),
        ];
    }

    private function priorityTier(): string
    {
        $score = (float) $this->signal_score;
        $risk = (float) $this->risk_score;

        return match (true) {
            $score >= 80 && $risk <= 35 => 'critical',
            $score >= 70 => 'high',
            $score >= 55 => 'normal',
            default => 'observe',
        };
    }

    private function dashboardRank(): float
    {
        $alpha = (float) ($this->performance_summary['latest_alpha_return_pct'] ?? 0);

        return round(
            (float) $this->signal_score * 0.45
            + (float) $this->urgency_score * 0.20
            + (float) $this->confidence_score * 0.15
            + (float) $this->impact_score * 0.15
            + (100 - (float) $this->risk_score) * 0.05
            + $alpha * 1.5,
            2
        );
    }
}
