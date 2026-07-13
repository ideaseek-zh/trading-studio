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

class SignalNotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_suppresses_notifications_during_quiet_hours(): void
    {
        $signal = $this->createSignal();
        $now = now(config('app.timezone', 'UTC'));

        $subscription = SignalSubscription::query()->create([
            'subscriber_key' => 'quiet-desk',
            'subscriber_name' => 'Quiet Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'normal',
            'priority_order' => 100,
            'endpoint_url' => 'https://example.com/quiet',
            'channel_routes' => [[
                'route_key' => 'primary_webhook',
                'label' => 'Primary Webhook',
                'channel_type' => 'webhook',
                'target' => 'https://example.com/quiet',
                'enabled' => true,
                'priority_order' => 1,
                'delivery_tier' => 'primary',
            ]],
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
            'quiet_hours' => [
                'enabled' => true,
                'timezone' => config('app.timezone', 'UTC'),
                'start' => $now->copy()->subHour()->format('H:i'),
                'end' => $now->copy()->addHour()->format('H:i'),
            ],
            'debounce_window_minutes' => 0,
            'merge_window_minutes' => 0,
            'max_merge_signals' => 5,
        ]);

        /** @var SignalDeliveryService $service */
        $service = app(SignalDeliveryService::class);
        $created = $service->enqueuePendingDeliveries();

        $this->assertSame(1, $created);

        $delivery = SignalDelivery::query()->first();
        $this->assertNotNull($delivery);
        $this->assertSame($subscription->id, $delivery->signal_subscription_id);
        $this->assertSame($signal->id, $delivery->trading_signal_id);
        $this->assertSame('suppressed', $delivery->delivery_status);
        $this->assertSame('quiet_hours', $delivery->suppression_reason);
        $this->assertNotNull($delivery->next_retry_at);
    }

    public function test_it_debounces_and_merges_same_group_notifications(): void
    {
        $security = $this->createSecurity('600888', '测试合并股');
        $signalA = $this->createSignal([
            'security' => $security,
            'signal_title' => '第一条回购信号',
        ]);
        $signalB = $this->createSignal([
            'security' => $security,
            'signal_title' => '第二条回购信号',
        ]);

        $mergeSubscription = SignalSubscription::query()->create([
            'subscriber_key' => 'merge-desk',
            'subscriber_name' => 'Merge Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'high',
            'priority_order' => 50,
            'endpoint_url' => 'https://example.com/merge',
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
            'debounce_window_minutes' => 0,
            'merge_window_minutes' => 15,
            'max_merge_signals' => 5,
        ]);

        $debounceSubscription = SignalSubscription::query()->create([
            'subscriber_key' => 'debounce-desk',
            'subscriber_name' => 'Debounce Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'high',
            'priority_order' => 60,
            'endpoint_url' => 'https://example.com/debounce',
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
            'debounce_window_minutes' => 30,
            'merge_window_minutes' => 0,
            'max_merge_signals' => 5,
        ]);

        /** @var SignalDeliveryService $service */
        $service = app(SignalDeliveryService::class);
        $service->enqueuePendingDeliveries();

        $mergeRepresentative = SignalDelivery::query()
            ->where('signal_subscription_id', $mergeSubscription->id)
            ->where('delivery_status', 'queued')
            ->first();
        $mergedRow = SignalDelivery::query()
            ->where('signal_subscription_id', $mergeSubscription->id)
            ->where('delivery_status', 'merged')
            ->first();
        $debouncedRow = SignalDelivery::query()
            ->where('signal_subscription_id', $debounceSubscription->id)
            ->where('suppression_reason', 'debounce')
            ->first();

        $this->assertNotNull($mergeRepresentative);
        $this->assertNotNull($mergedRow);
        $this->assertNotNull($debouncedRow);
        $this->assertSame('merge_window', $mergeRepresentative->suppression_reason);
        $this->assertCount(2, $mergeRepresentative->payload['batch_signals']);
        $this->assertSame($mergeRepresentative->batch_key, $mergedRow->batch_key);
        $this->assertSame('suppressed', $debouncedRow->delivery_status);
        $this->assertSame('debounce', $debouncedRow->suppression_reason);

        $this->assertContains($signalA->id, collect($mergeRepresentative->payload['batch_signals'])->pluck('id')->all());
        $this->assertContains($signalB->id, collect($mergeRepresentative->payload['batch_signals'])->pluck('id')->all());
    }

    public function test_it_escalates_to_multi_channel_routes_after_retry_attempts(): void
    {
        $signal = $this->createSignal([
            'signal_title' => '需要升级处理的信号',
        ]);

        $subscription = SignalSubscription::query()->create([
            'subscriber_key' => 'escalation-desk',
            'subscriber_name' => 'Escalation Desk',
            'channel_type' => 'webhook',
            'priority_level' => 'critical',
            'priority_order' => 10,
            'endpoint_url' => 'https://example.com/primary',
            'channel_routes' => [
                [
                    'route_key' => 'primary_webhook',
                    'label' => 'Primary Webhook',
                    'channel_type' => 'webhook',
                    'target' => 'https://example.com/primary',
                    'enabled' => true,
                    'priority_order' => 1,
                    'delivery_tier' => 'primary',
                ],
                [
                    'route_key' => 'ops_wecom',
                    'label' => 'Ops WeCom',
                    'channel_type' => 'wecom_bot',
                    'target' => 'https://example.com/wecom',
                    'enabled' => true,
                    'priority_order' => 20,
                    'delivery_tier' => 'escalation',
                ],
            ],
            'escalation_rules' => [
                [
                    'after_attempts' => 2,
                    'route_keys' => ['ops_wecom'],
                ],
            ],
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
            'debounce_window_minutes' => 0,
            'merge_window_minutes' => 0,
            'max_merge_signals' => 5,
        ]);

        $requestLog = [];

        Http::fake(function ($request) use (&$requestLog) {
            $requestLog[] = $request->url();

            return match ($request->url()) {
                'https://example.com/primary' => Http::response(['error' => 'primary_failed'], 500),
                'https://example.com/wecom' => Http::response(['ok' => true, 'channel' => 'wecom'], 200),
                default => Http::response(['ok' => true], 200),
            };
        });

        /** @var SignalDeliveryService $service */
        $service = app(SignalDeliveryService::class);
        $service->enqueuePendingDeliveries();

        $delivery = SignalDelivery::query()->where('signal_subscription_id', $subscription->id)->firstOrFail();
        $firstAttempt = $service->dispatchDelivery($delivery);

        $this->assertSame('failed', $firstAttempt);

        $delivery->refresh();
        $this->assertSame('retrying', $delivery->delivery_status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(['https://example.com/primary'], $requestLog);

        $requestLog = [];
        $retryResult = $service->retryDelivery($delivery, true);

        $this->assertSame('success', $retryResult['dispatch_result']);

        $delivery->refresh();
        $this->assertSame('partial_success', $delivery->delivery_status);
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame([
            'https://example.com/primary',
            'https://example.com/wecom',
        ], $requestLog);

        $routeResults = $delivery->dispatch_context['route_results'] ?? [];
        $this->assertCount(2, $routeResults);
        $this->assertSame('wecom_bot', $routeResults[1]['channel_type']);
    }

    private function createSecurity(string $symbol, string $name): Security
    {
        static $securitySeq = 1;

        return Security::query()->create([
            'canonical_symbol' => 'CN.XSHG.'.$symbol.'.'.$securitySeq,
            'symbol' => $symbol,
            'exchange' => 'XSHG',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => $name,
            'short_name' => $name,
            'status' => 'active',
            'currency' => 'CNY',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSignal(array $overrides = []): TradingSignal
    {
        static $sequence = 1;

        $security = $overrides['security'] ?? $this->createSecurity(sprintf('600%03d', $sequence), '策略测试股'.$sequence);
        $signalType = $overrides['signal_type'] ?? 'alpha_opportunity';
        $direction = $overrides['direction'] ?? 'positive';
        $chainType = $overrides['chain_type'] ?? 'buyback';

        $chain = EventChain::query()->create([
            'chain_key' => hash('sha256', 'policy-chain-'.$sequence.'-'.$security->symbol),
            'chain_type' => $chainType,
            'topic' => $security->name.$chainType.$sequence,
            'summary' => $security->name.'事件链',
            'status' => 'active',
            'primary_security_id' => $security->id,
            'started_at' => now()->subDays(2),
            'latest_occurred_at' => now()->subHours(2),
            'latest_published_at' => now()->subHours(2),
            'importance_level' => 'A',
            'sentiment' => $direction,
            'event_count' => 1,
            'article_count' => 1,
            'facts' => ['latest_stage' => 'completion'],
        ]);

        $event = MarketEvent::query()->create([
            'event_type' => $chainType,
            'title' => $security->name.'事件'.$sequence,
            'summary' => $security->name.'事件摘要',
            'occurred_at' => now()->subHours(2),
            'detected_at' => now()->subHours(2),
            'importance_level' => 'A',
            'sentiment' => $direction,
            'confidence' => 0.9,
            'status' => 'published',
            'primary_security_id' => $security->id,
            'event_chain_id' => $chain->id,
            'timeline_stage' => 'completion',
            'timeline_order' => 1,
            'fingerprint' => hash('sha256', 'policy-event-'.$sequence.'-'.$security->symbol),
            'facts' => ['timeline' => ['stage' => 'completion']],
            'published_at' => now()->subHours(2),
        ]);

        $rule = SignalRule::query()->create([
            'rule_key' => 'policy_rule_'.$sequence.'_'.$signalType,
            'name' => '策略规则'.$sequence,
            'scope_type' => 'event_chain',
            'chain_type' => $chainType,
            'signal_type' => $signalType,
            'default_direction' => $direction,
            'horizon_label' => 'short_term',
            'horizon_days' => 10,
            'min_signal_score' => 60,
            'enabled' => true,
        ]);

        $signal = TradingSignal::query()->create([
            'signal_key' => hash('sha256', 'policy-signal-'.$sequence.'-'.$security->symbol),
            'signal_rule_id' => $rule->id,
            'event_chain_id' => $chain->id,
            'latest_event_id' => $event->id,
            'primary_security_id' => $security->id,
            'signal_type' => $signalType,
            'direction' => $direction,
            'horizon_label' => 'short_term',
            'status' => 'active',
            'title' => $overrides['signal_title'] ?? $security->name.'信号'.$sequence,
            'summary' => $security->name.'信号摘要',
            'signal_score' => $overrides['signal_score'] ?? 82.6,
            'confidence_score' => 79.8,
            'urgency_score' => 83.5,
            'impact_score' => 80.4,
            'risk_score' => 24.3,
            'triggered_at' => now()->subHours(2),
            'published_at' => now()->subHours(2),
            'expires_at' => now()->addDays(10),
            'reasoning' => ['version' => 'signal-engine-v1'],
            'performance_summary' => $overrides['performance_summary'] ?? ['evaluation_status' => 'evaluated'],
            'facts' => ['chain_type' => $chainType],
        ]);

        $sequence++;

        return $signal;
    }
}
