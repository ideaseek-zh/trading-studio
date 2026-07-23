<?php

namespace Tests\Feature;

use App\Models\EventChain;
use App\Models\MarketEvent;
use App\Models\MarketQuote;
use App\Models\Security;
use App\Models\SignalRule;
use App\Models\SignalSubscription;
use App\Models\StrategyWorkspace;
use App\Models\StrategyWorkspaceItem;
use App\Models\TradingSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_workspace_monitor_overview_with_quotes_positions_and_signals(): void
    {
        $security = $this->createSecurity('600000', '浦发银行');
        $riskSecurity = $this->createSecurity('300059', '东方财富');

        $subscription = SignalSubscription::query()->create([
            'subscriber_key' => 'portfolio-desk',
            'subscriber_name' => '组合盯盘',
            'channel_type' => 'webhook',
            'priority_level' => 'critical',
            'priority_order' => 10,
            'endpoint_url' => 'https://example.com/portfolio',
            'min_signal_score' => 70,
            'enabled' => true,
            'filters' => [],
        ]);

        $workspace = StrategyWorkspace::query()->create([
            'workspace_key' => 'core_portfolio',
            'name' => '核心组合',
            'owner_key' => 'desk-alpha',
            'workspace_type' => 'portfolio',
            'risk_profile' => 'balanced',
            'base_currency' => 'CNY',
            'default_signal_subscription_id' => $subscription->id,
            'enabled' => true,
        ]);

        StrategyWorkspaceItem::query()->create([
            'strategy_workspace_id' => $workspace->id,
            'security_id' => $security->id,
            'item_type' => 'holding',
            'status' => 'active',
            'position_quantity' => 1000,
            'average_cost' => 9.8,
            'target_price' => 12.5,
            'stop_loss_price' => 8.7,
            'alert_score_threshold' => 70,
            'tags' => ['银行', '红利'],
        ]);
        StrategyWorkspaceItem::query()->create([
            'strategy_workspace_id' => $workspace->id,
            'security_id' => $riskSecurity->id,
            'item_type' => 'watch',
            'status' => 'active',
            'alert_score_threshold' => 70,
            'tags' => ['券商', '互联网金融'],
        ]);

        $this->createQuote($security, 10.2, 2.12);
        $this->createQuote($riskSecurity, 14.8, -3.4);
        $positiveSignal = $this->createSignal($security, [
            'signal_type' => 'alpha_opportunity',
            'direction' => 'positive',
            'signal_score' => 82.4,
        ]);
        $riskSignal = $this->createSignal($riskSecurity, [
            'signal_type' => 'risk_alert',
            'direction' => 'negative',
            'signal_score' => 78.6,
        ]);

        $response = $this->getJson('/api/v1/strategy-workspaces/'.$workspace->id.'/overview');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.workspace.name', '核心组合')
            ->assertJsonPath('data.overview.item_count', 2)
            ->assertJsonPath('data.overview.holding_count', 1)
            ->assertJsonPath('data.overview.portfolio_value', 10200)
            ->assertJsonPath('data.overview.unrealized_pnl', 400)
            ->assertJsonPath('data.overview.high_risk_count', 1)
            ->assertJsonPath('data.subscription_bridge.linked', true)
            ->assertJsonPath('data.monitors.0.security.symbol', '300059')
            ->assertJsonPath('data.monitors.0.top_signal.id', $riskSignal->id)
            ->assertJsonPath('data.monitors.1.top_signal.id', $positiveSignal->id)
            ->assertJsonPath('data.top_signals.0.id', $positiveSignal->id);
    }

    public function test_it_manages_workspace_items_and_syncs_subscription_symbol_filters(): void
    {
        $security = $this->createSecurity('600519', '贵州茅台');
        $secondSecurity = $this->createSecurity('300750', '宁德时代');

        $subscription = SignalSubscription::query()->create([
            'subscriber_key' => 'watchlist-desk',
            'subscriber_name' => '自选提醒',
            'channel_type' => 'webhook',
            'priority_level' => 'high',
            'priority_order' => 20,
            'endpoint_url' => 'https://example.com/watchlist',
            'min_signal_score' => 65,
            'enabled' => true,
            'filters' => ['directions' => ['positive']],
        ]);

        $workspaceResponse = $this->postJson('/api/v1/strategy-workspaces', [
            'workspace_key' => 'watchlist_alpha',
            'name' => 'Alpha 自选',
            'owner_key' => 'desk-alpha',
            'workspace_type' => 'watchlist',
            'risk_profile' => 'aggressive',
            'default_signal_subscription_id' => $subscription->id,
            'enabled' => true,
        ]);

        $workspaceResponse->assertCreated();
        $workspaceId = (int) $workspaceResponse->json('data.id');

        $itemResponse = $this->postJson('/api/v1/strategy-workspaces/'.$workspaceId.'/items', [
            'symbol' => '600519',
            'item_type' => 'holding',
            'position_quantity' => 100,
            'average_cost' => 1500.25,
            'alert_score_threshold' => 75,
            'tags' => ['白酒'],
        ]);

        $itemResponse
            ->assertCreated()
            ->assertJsonPath('data.security.symbol', '600519')
            ->assertJsonPath('data.item_type', 'holding');

        $subscription->refresh();
        $this->assertSame(['600519'], $subscription->filters['security_symbols']);
        $this->assertSame(['positive'], $subscription->filters['directions']);

        $this->postJson('/api/v1/strategy-workspaces/'.$workspaceId.'/items', [
            'security_id' => $secondSecurity->id,
            'item_type' => 'watch',
            'alert_score_threshold' => 70,
        ])->assertCreated();

        $subscription->refresh();
        $this->assertSame(['600519', '300750'], $subscription->filters['security_symbols']);

        $itemId = (int) $itemResponse->json('data.id');
        $this->deleteJson('/api/v1/strategy-workspaces/'.$workspaceId.'/items/'.$itemId)->assertOk();

        $subscription->refresh();
        $this->assertSame(['300750'], $subscription->filters['security_symbols']);

        $this->getJson('/api/v1/strategy-workspaces/'.$workspaceId)
            ->assertOk()
            ->assertJsonPath('data.items.0.security.symbol', '300750');
    }

    public function test_it_recommends_stocks_from_recent_signals_and_marks_existing_workspace_items(): void
    {
        $trackedSecurity = $this->createSecurity('600000', '浦发银行');
        $recommendedSecurity = $this->createSecurity('300750', '宁德时代');

        $workspace = StrategyWorkspace::query()->create([
            'workspace_key' => 'radar_watchlist',
            'name' => '热点雷达自选',
            'owner_key' => 'desk-alpha',
            'workspace_type' => 'watchlist',
            'risk_profile' => 'balanced',
            'base_currency' => 'CNY',
            'enabled' => true,
        ]);

        StrategyWorkspaceItem::query()->create([
            'strategy_workspace_id' => $workspace->id,
            'security_id' => $trackedSecurity->id,
            'item_type' => 'watch',
            'status' => 'active',
            'alert_score_threshold' => 70,
        ]);

        $this->createSignal($trackedSecurity, [
            'signal_type' => 'risk_alert',
            'direction' => 'negative',
            'signal_score' => 84.3,
        ]);
        $this->createSignal($recommendedSecurity, [
            'signal_type' => 'alpha_opportunity',
            'direction' => 'positive',
            'signal_score' => 91.8,
        ]);

        $globalResponse = $this->getJson('/api/v1/strategy-workspaces/recommendations?limit=10');
        $globalResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(2, 'data');

        $workspaceResponse = $this->getJson('/api/v1/strategy-workspaces/'.$workspace->id.'/recommendations?limit=10');
        $workspaceResponse->assertOk()->assertJsonPath('code', 0);

        $recommendations = collect($workspaceResponse->json('data'));
        $tracked = $recommendations->firstWhere('security.symbol', '600000');
        $candidate = $recommendations->firstWhere('security.symbol', '300750');

        $this->assertNotNull($tracked);
        $this->assertNotNull($candidate);
        $this->assertTrue($tracked['already_tracked']);
        $this->assertFalse($candidate['already_tracked']);
        $this->assertSame('risk_watch', $tracked['recommendation_type']);
        $this->assertSame('opportunity', $candidate['recommendation_type']);
        $this->assertNotEmpty($candidate['reasons']);
    }

    private function createSecurity(string $symbol, string $name): Security
    {
        return Security::query()->create([
            'canonical_symbol' => 'CN.XSHG.'.$symbol,
            'symbol' => $symbol,
            'exchange' => str_starts_with($symbol, '3') ? 'XSHE' : 'XSHG',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => $name,
            'short_name' => $name,
            'status' => 'active',
            'currency' => 'CNY',
        ]);
    }

    private function createQuote(Security $security, float $lastPrice, float $pctChange): MarketQuote
    {
        return MarketQuote::query()->create([
            'security_id' => $security->id,
            'quote_time' => now(),
            'last_price' => $lastPrice,
            'pre_close' => round($lastPrice / (1 + $pctChange / 100), 4),
            'open' => $lastPrice - 0.1,
            'high' => $lastPrice + 0.2,
            'low' => $lastPrice - 0.3,
            'volume' => 1000000,
            'amount' => 12000000,
            'turnover_rate' => 1.25,
            'pct_change' => $pctChange,
            'provider' => 'test',
            'source_timestamp' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSignal(Security $security, array $overrides = []): TradingSignal
    {
        static $sequence = 1;

        $signalType = $overrides['signal_type'] ?? 'alpha_opportunity';
        $direction = $overrides['direction'] ?? 'positive';
        $chainType = $overrides['chain_type'] ?? 'workspace_monitor';

        $chain = EventChain::query()->create([
            'chain_key' => hash('sha256', 'workspace-chain-'.$sequence.'-'.$security->symbol),
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
            'fingerprint' => hash('sha256', 'workspace-event-'.$sequence.'-'.$security->symbol),
            'facts' => ['timeline' => ['stage' => 'completion']],
            'published_at' => now()->subHours(2),
        ]);

        $rule = SignalRule::query()->create([
            'rule_key' => 'workspace_rule_'.$sequence.'_'.$signalType,
            'name' => '工作台规则'.$sequence,
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
            'signal_key' => hash('sha256', 'workspace-signal-'.$sequence.'-'.$security->symbol),
            'signal_rule_id' => $rule->id,
            'event_chain_id' => $chain->id,
            'latest_event_id' => $event->id,
            'primary_security_id' => $security->id,
            'signal_type' => $signalType,
            'direction' => $direction,
            'horizon_label' => 'short_term',
            'status' => 'active',
            'title' => $security->name.'工作台信号'.$sequence,
            'summary' => $security->name.'信号摘要',
            'signal_score' => $overrides['signal_score'] ?? 82.6,
            'confidence_score' => 79.8,
            'urgency_score' => 83.5,
            'impact_score' => 80.4,
            'risk_score' => $direction === 'negative' ? 72.5 : 24.3,
            'triggered_at' => now()->subHours(2),
            'published_at' => now()->subHours(2),
            'expires_at' => now()->addDays(10),
            'reasoning' => ['version' => 'strategy-workspace-test'],
            'performance_summary' => ['evaluation_status' => 'evaluated'],
            'facts' => ['chain_type' => $chainType],
        ]);

        $sequence++;

        return $signal;
    }
}
