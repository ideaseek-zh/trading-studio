<?php

namespace Tests\Feature;

use App\Models\EventChain;
use App\Models\MarketEvent;
use App\Models\NotificationChannelCredential;
use App\Models\NotificationTemplate;
use App\Models\Security;
use App\Models\SignalDelivery;
use App\Models\SignalRule;
use App\Models\SignalSubscription;
use App\Models\TradingSignal;
use App\Services\SignalDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignalNotificationTemplateAndCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_feishu_bot_with_signature_and_template(): void
    {
        $signal = $this->createSignal([
            'signal_title' => '飞书联调信号',
        ]);

        $template = NotificationTemplate::query()->create([
            'template_key' => 'feishu_default',
            'name' => '飞书默认模板',
            'channel_type' => 'feishu_bot',
            'message_format' => 'post',
            'subject_template' => '[Trading Studio] {{signal.title}}',
            'body_template' => "标题：{{signal.title}}\n证券：{{security.symbol}}\n方向：{{signal.direction}}\n评分：{{signal.signal_score}}",
            'enabled' => true,
        ]);

        $credential = NotificationChannelCredential::query()->create([
            'credential_key' => 'feishu_ops',
            'name' => '飞书值班机器人',
            'channel_type' => 'feishu_bot',
            'endpoint_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/abc123hooktoken',
            'signing_secret' => 'feishu-signing-secret',
            'enabled' => true,
        ]);

        $subscription = SignalSubscription::query()->create([
            'subscriber_key' => 'feishu-desk',
            'subscriber_name' => 'Feishu Desk',
            'channel_type' => 'feishu_bot',
            'priority_level' => 'high',
            'priority_order' => 20,
            'endpoint_url' => $credential->endpoint_url,
            'notification_template_id' => $template->id,
            'notification_channel_credential_id' => $credential->id,
            'channel_routes' => [[
                'route_key' => 'primary_feishu',
                'label' => 'Primary Feishu',
                'channel_type' => 'feishu_bot',
                'credential_id' => $credential->id,
                'template_id' => $template->id,
                'signature_mode' => 'feishu_v1',
                'enabled' => true,
                'priority_order' => 1,
                'delivery_tier' => 'primary',
            ]],
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
            'debounce_window_minutes' => 0,
            'merge_window_minutes' => 0,
            'max_merge_signals' => 5,
        ]);

        $capturedBody = null;
        $capturedUrl = null;

        Http::fake(function ($request) use (&$capturedBody, &$capturedUrl) {
            $capturedUrl = $request->url();
            $capturedBody = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

            return Http::response(['code' => 0, 'msg' => 'ok'], 200);
        });

        /** @var SignalDeliveryService $service */
        $service = app(SignalDeliveryService::class);
        $service->enqueuePendingDeliveries();

        $delivery = SignalDelivery::query()->where('signal_subscription_id', $subscription->id)->firstOrFail();
        $result = $service->dispatchDelivery($delivery);

        $this->assertSame('success', $result);
        $this->assertSame('https://open.feishu.cn/open-apis/bot/v2/hook/abc123hooktoken', $capturedUrl);
        $this->assertIsArray($capturedBody);
        $this->assertSame('post', $capturedBody['msg_type']);
        $this->assertSame('[Trading Studio] 飞书联调信号', $capturedBody['content']['post']['zh_cn']['title']);
        $this->assertNotEmpty($capturedBody['timestamp']);
        $this->assertNotEmpty($capturedBody['sign']);
        $this->assertSame(
            base64_encode(hash_hmac('sha256', '', $capturedBody['timestamp']."\nfeishu-signing-secret", true)),
            $capturedBody['sign']
        );
    }

    public function test_it_masks_credentials_and_keeps_existing_endpoint_when_client_submits_masked_value(): void
    {
        $credentialResponse = $this->postJson('/api/v1/notification-channel-credentials', [
            'credential_key' => 'feishu_primary',
            'name' => '飞书主通道',
            'channel_type' => 'feishu_bot',
            'endpoint_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/realhooktoken123456',
            'secret_token' => 'plain-secret-token',
            'signing_secret' => 'signing-secret-value',
            'enabled' => true,
        ]);

        $credentialResponse->assertCreated();
        $credentialResponse->assertJsonPath('data.channel_type', 'feishu_bot');
        $this->assertStringContainsString('open.feishu.cn', (string) $credentialResponse->json('data.endpoint_url'));
        $this->assertStringNotContainsString('realhooktoken123456', (string) $credentialResponse->json('data.endpoint_url'));
        $this->assertStringNotContainsString('plain-secret-token', (string) $credentialResponse->json('data.secret_token_masked'));
        $this->assertStringNotContainsString('signing-secret-value', (string) $credentialResponse->json('data.signing_secret_masked'));

        $storedCiphertext = DB::table('notification_channel_credentials')->where('credential_key', 'feishu_primary')->value('endpoint_url');
        $this->assertNotSame('https://open.feishu.cn/open-apis/bot/v2/hook/realhooktoken123456', $storedCiphertext);

        $template = NotificationTemplate::query()->create([
            'template_key' => 'ops_default',
            'name' => '默认模板',
            'channel_type' => 'feishu_bot',
            'message_format' => 'post',
            'body_template' => "标题：{{signal.title}}\n证券：{{security.symbol}}",
            'enabled' => true,
        ]);

        $subscriptionResponse = $this->postJson('/api/v1/signal-subscriptions', [
            'subscriber_key' => 'desk-masked',
            'subscriber_name' => 'Masked Desk',
            'channel_type' => 'feishu_bot',
            'notification_channel_credential_id' => $credentialResponse->json('data.id'),
            'notification_template_id' => $template->id,
            'priority_level' => 'normal',
            'priority_order' => 100,
            'min_signal_score' => 60,
            'enabled' => true,
            'filters' => [],
            'debounce_window_minutes' => 0,
            'merge_window_minutes' => 0,
            'max_merge_signals' => 5,
        ]);

        $subscriptionResponse->assertCreated();
        $subscriptionResponse->assertJsonPath('data.notification_channel_credential_id', $credentialResponse->json('data.id'));
        $this->assertStringNotContainsString('realhooktoken123456', (string) $subscriptionResponse->json('data.endpoint_url'));

        $subscriptionId = (int) $subscriptionResponse->json('data.id');
        $maskedEndpoint = (string) $subscriptionResponse->json('data.endpoint_url');

        $this->patchJson('/api/v1/signal-subscriptions/'.$subscriptionId, [
            'endpoint_url' => $maskedEndpoint,
            'priority_level' => 'high',
        ])->assertOk()->assertJsonPath('data.priority_level', 'high');

        $subscription = SignalSubscription::query()->findOrFail($subscriptionId);
        $credential = NotificationChannelCredential::query()->findOrFail((int) $credentialResponse->json('data.id'));

        $this->assertSame($credential->endpoint_url, $subscription->endpoint_url);
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

        $security = $overrides['security'] ?? $this->createSecurity(sprintf('601%03d', $sequence), '模板测试股'.$sequence);
        $signalType = $overrides['signal_type'] ?? 'alpha_opportunity';
        $direction = $overrides['direction'] ?? 'positive';
        $chainType = $overrides['chain_type'] ?? 'buyback';

        $chain = EventChain::query()->create([
            'chain_key' => hash('sha256', 'template-chain-'.$sequence.'-'.$security->symbol),
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
            'fingerprint' => hash('sha256', 'template-event-'.$sequence.'-'.$security->symbol),
            'facts' => ['timeline' => ['stage' => 'completion']],
            'published_at' => now()->subHours(2),
        ]);

        $rule = SignalRule::query()->create([
            'rule_key' => 'template_rule_'.$sequence.'_'.$signalType,
            'name' => '模板规则'.$sequence,
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
            'signal_key' => hash('sha256', 'template-signal-'.$sequence.'-'.$security->symbol),
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
            'performance_summary' => ['evaluation_status' => 'evaluated'],
            'facts' => ['chain_type' => $chainType],
        ]);

        $sequence++;

        return $signal;
    }
}
