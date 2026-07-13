<?php

namespace Tests\Feature;

use App\Models\EventChain;
use App\Models\MarketEvent;
use App\Models\SignalDelivery;
use App\Models\SignalSubscription;
use App\Models\SignalRule;
use App\Models\Security;
use App\Models\TradingSignal;
use App\Services\SignalDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignalExecutionCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_execution_center_dashboard_and_delivery_audit(): void
    {
        $signal = $this->createSignal([
            'signal_score' => 88.4,
            'performance_summary' => [
                'evaluation_status' => 'evaluated',
                'latest_alpha_return_pct' => 3.2,
            ],
        ]);

        SignalSubscription::query()->create([
            'subscriber_key' => 'desk-success',
            'subscriber_name' => 'Success Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'critical',
            'priority_order' => 5,
            'endpoint_url' => 'https://example.com/success',
            'secret_token' => 'token-success',
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
        ]);

        SignalSubscription::query()->create([
            'subscriber_key' => 'desk-failed',
            'subscriber_name' => 'Failed Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'high',
            'priority_order' => 10,
            'endpoint_url' => 'https://example.com/failed',
            'secret_token' => 'token-failed',
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
        ]);

        Http::fake([
            'https://example.com/success' => Http::response(['ok' => true], 200),
            'https://example.com/failed' => Http::response(['error' => 'temporary'], 500),
        ]);

        /** @var SignalDeliveryService $deliveryService */
        $deliveryService = app(SignalDeliveryService::class);
        $deliveryService->enqueuePendingDeliveries();
        $deliveryService->dispatchPendingWebhooks(10);

        $dashboardResponse = $this->getJson('/api/v1/signal-operations/dashboard');

        $dashboardResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.overview.deliveries_total', 2)
            ->assertJsonPath('data.overview.success', 1)
            ->assertJsonPath('data.overview.retrying', 1)
            ->assertJsonPath('data.subscription_health.total', 2)
            ->assertJsonPath('data.recent_failures.0.signal.id', $signal->id)
            ->assertJsonPath('data.recent_failures.0.subscription.subscriber_key', 'desk-failed');

        $auditResponse = $this->getJson('/api/v1/signal-deliveries?deliveryStatus=retrying');

        $auditResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.signal.id', $signal->id)
            ->assertJsonPath('data.0.subscription.subscriber_key', 'desk-failed')
            ->assertJsonPath('meta.summary.retrying', 1);
    }

    public function test_it_supports_refresh_retry_and_subscription_center_operations(): void
    {
        $signal = $this->createSignal([
            'symbol' => '600001',
            'name' => '执行中心测试股',
            'signal_score' => 86.1,
            'performance_summary' => [
                'evaluation_status' => 'evaluated',
                'latest_alpha_return_pct' => 2.7,
            ],
        ]);

        $subscription = SignalSubscription::query()->create([
            'subscriber_key' => 'desk-ops',
            'subscriber_name' => 'Ops Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'critical',
            'priority_order' => 3,
            'endpoint_url' => 'https://example.com/ops',
            'secret_token' => 'ops-token',
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [
                'security_symbols' => ['600001'],
                'signal_types' => [$signal->signal_type],
                'directions' => [$signal->direction],
            ],
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/internal/v1/data/sync/signals/rebuild')) {
                return Http::response(['accepted' => true, 'action' => 'rebuild_signals'], 200);
            }

            if (str_contains($url, '/internal/v1/data/sync/signals/insights')) {
                return Http::response(['accepted' => true, 'action' => 'evaluate_insights'], 200);
            }

            if ($url === 'https://example.com/ops') {
                return Http::response(['ok' => true], 200);
            }

            return Http::response(['ok' => true], 200);
        });

        $refreshResponse = $this->postJson('/api/v1/signal-operations/refresh', [
            'symbol' => '600001',
            'signal_id' => $signal->id,
            'rebuild_signals' => true,
            'evaluate_insights' => true,
            'enqueue_deliveries' => true,
            'dispatch_webhooks' => true,
            'dispatch_limit' => 10,
        ]);

        $refreshResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.results.rebuild_signals.ok', true)
            ->assertJsonPath('data.results.evaluate_insights.ok', true)
            ->assertJsonPath('data.results.enqueue_deliveries.ok', true)
            ->assertJsonPath('data.results.dispatch_webhooks.ok', true)
            ->assertJsonPath('data.overview.success', 1);

        Http::fake([
            'https://example.com/ops' => Http::response(['ok' => true, 'retried' => true], 200),
        ]);

        $delivery = SignalDelivery::query()->firstOrFail();

        $retryResponse = $this->postJson("/api/v1/signal-deliveries/{$delivery->id}/retry", [
            'dispatch_now' => true,
        ]);

        $retryResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.dispatch_result', 'success')
            ->assertJsonPath('data.delivery.delivery_status', 'success');

        $testSubscriptionResponse = $this->postJson("/api/v1/signal-subscriptions/{$subscription->id}/test", [
            'signal_id' => $signal->id,
        ]);

        $testSubscriptionResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.tested', true)
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.signal.id', $signal->id);

        $deleteResponse = $this->deleteJson("/api/v1/signal-subscriptions/{$subscription->id}");

        $deleteResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('signal_subscriptions', [
            'id' => $subscription->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSignal(array $overrides = []): TradingSignal
    {
        static $sequence = 1;

        $symbol = $overrides['symbol'] ?? sprintf('601%03d', $sequence);
        $name = $overrides['name'] ?? '执行中心证券'.$sequence;
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
            'chain_key' => hash('sha256', 'ops-chain-'.$sequence.'-'.$symbol),
            'chain_type' => $chainType,
            'topic' => $name.$chainType,
            'summary' => $name.'事件链',
            'status' => 'active',
            'primary_security_id' => $security->id,
            'started_at' => now()->subDays(2),
            'latest_occurred_at' => now()->subHours(2),
            'latest_published_at' => now()->subHours(2),
            'importance_level' => 'A',
            'sentiment' => $direction,
            'event_count' => 1,
            'article_count' => 1,
            'facts' => ['latest_stage' => $timelineStage],
        ]);

        $event = MarketEvent::query()->create([
            'event_type' => $chainType,
            'title' => $name.'事件'.$sequence,
            'summary' => $name.'事件摘要',
            'occurred_at' => now()->subHours(2),
            'detected_at' => now()->subHours(2),
            'importance_level' => 'A',
            'sentiment' => $direction,
            'confidence' => 0.9,
            'status' => 'published',
            'primary_security_id' => $security->id,
            'event_chain_id' => $chain->id,
            'timeline_stage' => $timelineStage,
            'timeline_order' => 1,
            'fingerprint' => hash('sha256', 'ops-event-'.$sequence.'-'.$symbol),
            'facts' => ['timeline' => ['stage' => $timelineStage]],
            'published_at' => now()->subHours(2),
        ]);

        $rule = SignalRule::query()->create([
            'rule_key' => 'ops_rule_'.$sequence.'_'.$signalType,
            'name' => '执行中心规则'.$sequence,
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
            'signal_key' => hash('sha256', 'ops-signal-'.$sequence.'-'.$symbol),
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
            'signal_score' => $overrides['signal_score'] ?? 82.5,
            'confidence_score' => $overrides['confidence_score'] ?? 79.2,
            'urgency_score' => $overrides['urgency_score'] ?? 82.4,
            'impact_score' => $overrides['impact_score'] ?? 78.8,
            'risk_score' => $overrides['risk_score'] ?? 26.4,
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
