<?php

namespace Tests\Feature;

use App\Models\EventChain;
use App\Models\MarketEvent;
use App\Models\SignalSubscription;
use App\Models\SignalRule;
use App\Models\Security;
use App\Models\TradingSignal;
use App\Services\SignalDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_signal_dashboard_with_priority_and_heatmap_data(): void
    {
        $alphaSignal = $this->createSignal([
            'symbol' => '600000',
            'name' => '浦发银行',
            'chain_type' => 'buyback',
            'signal_type' => 'alpha_opportunity',
            'direction' => 'positive',
            'signal_score' => 84.6,
            'confidence_score' => 80.2,
            'urgency_score' => 88.1,
            'impact_score' => 82.3,
            'risk_score' => 24.5,
            'timeline_stage' => 'completion',
            'performance_summary' => [
                'evaluation_status' => 'evaluated',
                'latest_alpha_return_pct' => 3.6,
                'latest_return_pct' => 5.4,
            ],
        ]);

        $riskSignal = $this->createSignal([
            'symbol' => '300059',
            'name' => '东方财富',
            'chain_type' => 'investigation',
            'signal_type' => 'risk_alert',
            'direction' => 'negative',
            'signal_score' => 76.4,
            'confidence_score' => 78.5,
            'urgency_score' => 73.6,
            'impact_score' => 79.2,
            'risk_score' => 66.8,
            'timeline_stage' => 'disclosure',
            'published_at' => now()->subHour(),
            'performance_summary' => [
                'evaluation_status' => 'pending',
            ],
        ]);

        SignalSubscription::query()->create([
            'subscriber_key' => 'desk-critical',
            'subscriber_name' => 'Critical Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'critical',
            'priority_order' => 10,
            'endpoint_url' => 'https://example.com/critical',
            'secret_token' => 'critical-token',
            'min_signal_score' => 75,
            'enabled' => true,
            'filters' => ['signal_types' => ['alpha_opportunity', 'risk_alert']],
        ]);

        SignalSubscription::query()->create([
            'subscriber_key' => 'desk-normal',
            'subscriber_name' => 'Normal Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'normal',
            'priority_order' => 120,
            'endpoint_url' => 'https://example.com/normal',
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
        ]);

        $response = $this->getJson('/api/v1/signals/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.overview.total', 2)
            ->assertJsonPath('data.overview.by_direction.positive', 1)
            ->assertJsonPath('data.overview.by_priority_tier.critical', 1)
            ->assertJsonPath('data.top_signals.0.id', $alphaSignal->id)
            ->assertJsonPath('data.risk_alerts.0.id', $riskSignal->id)
            ->assertJsonPath('data.subscription_overview.items.0.priority_level', 'critical')
            ->assertJsonPath('data.heatmap.0.symbol', '600000');
    }

    public function test_it_filters_and_sorts_signal_board_results(): void
    {
        $winner = $this->createSignal([
            'symbol' => '600000',
            'name' => '浦发银行',
            'chain_type' => 'buyback',
            'signal_type' => 'alpha_opportunity',
            'direction' => 'positive',
            'signal_score' => 82.4,
            'confidence_score' => 79.1,
            'urgency_score' => 84.5,
            'impact_score' => 81.3,
            'risk_score' => 22.1,
            'timeline_stage' => 'completion',
            'performance_summary' => [
                'evaluation_status' => 'evaluated',
                'latest_alpha_return_pct' => 4.2,
                'latest_return_pct' => 6.8,
            ],
        ]);

        $runnerUp = $this->createSignal([
            'symbol' => '600001',
            'name' => '示例股份',
            'chain_type' => 'buyback',
            'signal_type' => 'alpha_opportunity',
            'direction' => 'positive',
            'signal_score' => 80.2,
            'confidence_score' => 75.4,
            'urgency_score' => 78.6,
            'impact_score' => 77.1,
            'risk_score' => 28.4,
            'timeline_stage' => 'completion',
            'performance_summary' => [
                'evaluation_status' => 'evaluated',
                'latest_alpha_return_pct' => 1.6,
                'latest_return_pct' => 3.8,
            ],
        ]);

        $this->createSignal([
            'symbol' => '300750',
            'name' => '宁德时代',
            'chain_type' => 'theme',
            'signal_type' => 'theme_opportunity',
            'direction' => 'positive',
            'signal_score' => 66.3,
            'confidence_score' => 71.8,
            'urgency_score' => 64.5,
            'impact_score' => 68.9,
            'risk_score' => 41.2,
            'timeline_stage' => 'planning',
            'performance_summary' => [
                'evaluation_status' => 'no_market_data',
                'latest_alpha_return_pct' => 0.3,
            ],
        ]);

        $this->createSignal([
            'symbol' => '300059',
            'name' => '东方财富',
            'chain_type' => 'investigation',
            'signal_type' => 'risk_alert',
            'direction' => 'negative',
            'signal_score' => 79.6,
            'confidence_score' => 80.2,
            'urgency_score' => 83.8,
            'impact_score' => 77.6,
            'risk_score' => 69.4,
            'timeline_stage' => 'disclosure',
            'performance_summary' => [
                'evaluation_status' => 'pending',
                'latest_alpha_return_pct' => -2.5,
            ],
        ]);

        $response = $this->getJson('/api/v1/signals?signalTypes=alpha_opportunity&directions=positive&statuses=active&priorityTiers=critical&timelineStages=completion&minScore=80&sortBy=score');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $winner->id)
            ->assertJsonPath('data.1.id', $runnerUp->id)
            ->assertJsonPath('data.0.priority_tier', 'critical')
            ->assertJsonPath('data.0.evaluation_status', 'evaluated')
            ->assertJsonPath('meta.summary.total', 2)
            ->assertJsonPath('meta.filter_options.sort_options.0.key', 'dashboardRank');
    }

    public function test_it_dispatches_higher_priority_subscriptions_first(): void
    {
        $signal = $this->createSignal([
            'signal_score' => 85.2,
            'performance_summary' => [
                'evaluation_status' => 'evaluated',
                'latest_alpha_return_pct' => 2.1,
            ],
        ]);

        SignalSubscription::query()->create([
            'subscriber_key' => 'desk-low',
            'subscriber_name' => 'Low Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'low',
            'priority_order' => 200,
            'endpoint_url' => 'https://example.com/low',
            'secret_token' => 'low-token',
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
        ]);

        SignalSubscription::query()->create([
            'subscriber_key' => 'desk-high',
            'subscriber_name' => 'High Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'critical',
            'priority_order' => 5,
            'endpoint_url' => 'https://example.com/high',
            'secret_token' => 'high-token',
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
        ]);

        $requestOrder = [];
        Http::fake(function ($request) use (&$requestOrder) {
            $requestOrder[] = $request->url();

            return Http::response(['ok' => true], 200);
        });

        /** @var SignalDeliveryService $service */
        $service = app(SignalDeliveryService::class);
        $created = $service->enqueuePendingDeliveries();
        $result = $service->dispatchPendingWebhooks();

        $this->assertSame(2, $created);
        $this->assertSame(2, $result['sent']);
        $this->assertSame([
            'https://example.com/high',
            'https://example.com/low',
        ], $requestOrder);

        $signal->refresh();
        $this->assertSame('active', $signal->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSignal(array $overrides = []): TradingSignal
    {
        static $sequence = 1;

        $symbol = $overrides['symbol'] ?? sprintf('600%03d', $sequence);
        $name = $overrides['name'] ?? '测试证券'.$sequence;
        $chainType = $overrides['chain_type'] ?? 'buyback';
        $signalType = $overrides['signal_type'] ?? 'alpha_opportunity';
        $direction = $overrides['direction'] ?? 'positive';
        $timelineStage = $overrides['timeline_stage'] ?? 'completion';

        $security = Security::query()->create([
            'canonical_symbol' => $overrides['canonical_symbol'] ?? 'CN.XSHG.'.$symbol,
            'symbol' => $symbol,
            'exchange' => $overrides['exchange'] ?? 'XSHG',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => $name,
            'short_name' => $name,
            'status' => 'active',
            'currency' => 'CNY',
        ]);

        $chain = EventChain::query()->create([
            'chain_key' => hash('sha256', 'chain-'.$sequence.'-'.$symbol),
            'chain_type' => $chainType,
            'topic' => $overrides['topic'] ?? $name.$chainType,
            'summary' => $overrides['chain_summary'] ?? $name.'事件链',
            'status' => 'active',
            'primary_security_id' => $security->id,
            'started_at' => now()->subDays(2),
            'latest_occurred_at' => now()->subHours(2),
            'latest_published_at' => now()->subHours(2),
            'importance_level' => $overrides['importance_level'] ?? 'A',
            'sentiment' => $direction,
            'event_count' => 1,
            'article_count' => 1,
            'facts' => ['latest_stage' => $timelineStage],
        ]);

        $event = MarketEvent::query()->create([
            'event_type' => $overrides['event_type'] ?? $chainType,
            'title' => $overrides['event_title'] ?? $name.'事件'.$sequence,
            'summary' => $overrides['event_summary'] ?? $name.'事件摘要',
            'occurred_at' => $overrides['occurred_at'] ?? now()->subHours(2),
            'detected_at' => $overrides['detected_at'] ?? now()->subHours(2),
            'importance_level' => $overrides['importance_level'] ?? 'A',
            'sentiment' => $direction,
            'confidence' => $overrides['event_confidence'] ?? 0.9,
            'status' => 'published',
            'primary_security_id' => $security->id,
            'event_chain_id' => $chain->id,
            'timeline_stage' => $timelineStage,
            'timeline_order' => $overrides['timeline_order'] ?? 1,
            'fingerprint' => hash('sha256', 'event-'.$sequence.'-'.$symbol),
            'facts' => ['timeline' => ['stage' => $timelineStage]],
            'published_at' => $overrides['published_at'] ?? now()->subHours(2),
        ]);

        $rule = SignalRule::query()->create([
            'rule_key' => 'rule_'.$sequence.'_'.$signalType,
            'name' => $overrides['rule_name'] ?? '规则'.$sequence,
            'scope_type' => 'event_chain',
            'chain_type' => $chainType,
            'signal_type' => $signalType,
            'default_direction' => $direction,
            'horizon_label' => $overrides['horizon_label'] ?? 'short_term',
            'horizon_days' => $overrides['horizon_days'] ?? 10,
            'min_signal_score' => $overrides['rule_min_signal_score'] ?? 60,
            'enabled' => true,
        ]);

        $signal = TradingSignal::query()->create([
            'signal_key' => hash('sha256', 'signal-'.$sequence.'-'.$symbol),
            'signal_rule_id' => $rule->id,
            'event_chain_id' => $chain->id,
            'latest_event_id' => $event->id,
            'primary_security_id' => $security->id,
            'signal_type' => $signalType,
            'direction' => $direction,
            'horizon_label' => $overrides['horizon_label'] ?? 'short_term',
            'status' => $overrides['status'] ?? 'active',
            'title' => $overrides['title'] ?? $name.'信号'.$sequence,
            'summary' => $overrides['summary'] ?? $name.'信号摘要',
            'signal_score' => $overrides['signal_score'] ?? 80,
            'confidence_score' => $overrides['confidence_score'] ?? 78,
            'urgency_score' => $overrides['urgency_score'] ?? 80,
            'impact_score' => $overrides['impact_score'] ?? 76,
            'risk_score' => $overrides['risk_score'] ?? 28,
            'triggered_at' => $overrides['triggered_at'] ?? now()->subHours(2),
            'published_at' => $overrides['published_at'] ?? now()->subHours(2),
            'expires_at' => $overrides['expires_at'] ?? now()->addDays(10),
            'reasoning' => $overrides['reasoning'] ?? ['version' => 'signal-engine-v1'],
            'performance_summary' => $overrides['performance_summary'] ?? ['evaluation_status' => 'pending'],
            'facts' => $overrides['facts'] ?? ['chain_type' => $chainType],
        ]);

        $sequence++;

        return $signal;
    }
}
