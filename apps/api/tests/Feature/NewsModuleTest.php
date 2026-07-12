<?php

namespace Tests\Feature;

use App\Models\EventSource;
use App\Models\EventChain;
use App\Models\MarketEvent;
use App\Models\NewsArticle;
use App\Models\NewsArticleContent;
use App\Models\NewsSource;
use App\Models\SignalDelivery;
use App\Models\SignalPerformanceSnapshot;
use App\Models\SignalRule;
use App\Models\SignalSubscription;
use App\Models\Security;
use App\Models\TradingSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_news_articles_with_filters(): void
    {
        $source = NewsSource::query()->create([
            'source_key' => 'em_stock_news',
            'source_name' => '东方财富个股新闻',
            'source_type' => 'news',
            'provider' => 'akshare',
            'access_mode' => 'api',
        ]);

        $security = Security::query()->create([
            'canonical_symbol' => 'CN.XSHE.300059',
            'symbol' => '300059',
            'exchange' => 'XSHE',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => '东方财富',
            'short_name' => '东方财富',
            'status' => 'active',
            'currency' => 'CNY',
        ]);

        $article = NewsArticle::query()->create([
            'source_id' => $source->id,
            'source_item_id' => 'demo-news-1',
            'title' => '东方财富上半年业绩增长',
            'summary' => '公司预计上半年业绩增长。',
            'canonical_url' => 'https://example.com/news/1',
            'published_at' => now(),
            'fetched_at' => now(),
            'source_timestamp' => now(),
            'category' => 'stock_news',
            'importance_level' => 'A',
            'sentiment' => 'positive',
            'status' => 'published',
            'language' => 'zh-CN',
            'copyright_status' => 'restricted',
            'quality_status' => 'passed',
            'quality_score' => 95,
            'title_hash' => str_repeat('a', 64),
            'content_hash' => str_repeat('b', 64),
            'checksum' => str_repeat('c', 64),
        ]);

        $article->securities()->attach($security->id, [
            'mention_type' => 'provider_symbol',
            'matched_text' => '300059',
            'confidence' => 0.98,
            'is_primary' => true,
        ]);

        $response = $this->getJson('/api/v1/news?securitySymbol=300059&qualityStatus=passed');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.title', '东方财富上半年业绩增长')
            ->assertJsonPath('data.0.quality_status', 'passed')
            ->assertJsonPath('data.0.securities.0.symbol', '300059');
    }

    public function test_it_returns_news_article_detail_with_content_and_events(): void
    {
        $source = NewsSource::query()->create([
            'source_key' => 'em_notice_report',
            'source_name' => '东方财富公告大全',
            'source_type' => 'announcement',
            'provider' => 'akshare',
            'access_mode' => 'api',
        ]);

        $security = Security::query()->create([
            'canonical_symbol' => 'CN.XSHG.600000',
            'symbol' => '600000',
            'exchange' => 'XSHG',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => '浦发银行',
            'short_name' => '浦发银行',
            'status' => 'active',
            'currency' => 'CNY',
        ]);

        $article = NewsArticle::query()->create([
            'source_id' => $source->id,
            'source_item_id' => 'demo-news-2',
            'title' => '浦发银行董事会决议公告',
            'summary' => '董事会审议通过回购议案。',
            'canonical_url' => 'https://example.com/news/2',
            'published_at' => now(),
            'fetched_at' => now(),
            'source_timestamp' => now(),
            'category' => 'notice',
            'importance_level' => 'A',
            'sentiment' => 'positive',
            'status' => 'published',
            'language' => 'zh-CN',
            'copyright_status' => 'public',
            'quality_status' => 'partial',
            'quality_score' => 82,
            'title_hash' => str_repeat('d', 64),
            'content_hash' => str_repeat('e', 64),
            'checksum' => str_repeat('f', 64),
        ]);

        NewsArticleContent::query()->create([
            'news_article_id' => $article->id,
            'content_text' => '浦发银行董事会审议通过回购相关议案。',
            'attachments' => [
                [
                    'name' => '公告原文',
                    'url' => 'https://example.com/notice.pdf',
                    'type' => 'pdf',
                ],
            ],
            'quality_issues' => ['announcement_summary_only'],
            'structured_data' => [
                'agenda_items' => [
                    [
                        'title' => '关于回购公司股份方案的议案',
                        'action' => '审议通过',
                        'sequence' => 1,
                    ],
                ],
                'amount_mentions' => [
                    [
                        'text' => '2亿元',
                        'numeric_value' => 200000000,
                        'unit' => '亿元',
                    ],
                ],
                'subjects' => [
                    [
                        'name' => '浦发银行',
                        'type' => 'security',
                    ],
                ],
                'date_mentions' => [
                    [
                        'text' => '2026年7月12日',
                        'normalized' => '2026-07-12',
                    ],
                ],
                'risk_flags' => [],
                'event_tags' => ['board_resolution', 'buyback'],
                'normalized' => [
                    'event_code' => 'buyback',
                    'event_type' => 'buyback',
                    'issuer' => [
                        'name' => '浦发银行',
                        'symbol' => '600000',
                        'entity_type' => 'listed_company',
                    ],
                    'counterparties' => [],
                    'participants' => [],
                    'regulators' => [],
                    'proposal_summary' => [
                        'proposal_types' => ['buyback_plan'],
                        'items' => [],
                    ],
                    'amount_summary' => [
                        'primary_amount' => [
                            'text' => '2亿元',
                            'numeric_value' => 200000000,
                            'semantic_type' => 'buyback_amount',
                        ],
                        'amounts' => [],
                    ],
                    'date_summary' => [
                        'decision_date' => '2026-07-12',
                        'effective_date' => null,
                        'all_dates' => ['2026-07-12'],
                        'semantic_type' => 'announcement_timeline',
                    ],
                    'risk_summary' => [
                        'risk_level' => 'none',
                        'risk_count' => 0,
                        'risk_breakdown' => [],
                        'flags' => [],
                    ],
                    'version' => 'announcement-normalize-v1',
                ],
            ],
            'extraction_version' => 'announcement-struct-v1',
            'extracted_at' => now(),
            'cleaned_at' => now(),
        ]);

        $article->securities()->attach($security->id, [
            'mention_type' => 'name',
            'matched_text' => '浦发银行',
            'confidence' => 0.82,
            'is_primary' => true,
        ]);

        $event = MarketEvent::query()->create([
            'event_type' => 'buyback',
            'title' => '浦发银行董事会决议公告',
            'summary' => '董事会审议通过回购议案。',
            'occurred_at' => now(),
            'detected_at' => now(),
            'importance_level' => 'A',
            'sentiment' => 'positive',
            'confidence' => 0.82,
            'status' => 'published',
            'primary_security_id' => $security->id,
            'fingerprint' => str_repeat('1', 64),
            'facts' => [
                'quality_status' => 'partial',
                'normalized_data' => [
                    'event_code' => 'buyback',
                    'event_type' => 'buyback',
                    'issuer' => ['name' => '浦发银行'],
                ],
            ],
            'published_at' => now(),
        ]);

        EventSource::query()->create([
            'event_id' => $event->id,
            'news_article_id' => $article->id,
            'relation_type' => 'primary',
        ]);

        $response = $this->getJson("/api/v1/news/{$article->id}");

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.content.content_text', '浦发银行董事会审议通过回购相关议案。')
            ->assertJsonPath('data.content.attachments.0.type', 'pdf')
            ->assertJsonPath('data.content.structured_data.event_tags.0', 'board_resolution')
            ->assertJsonPath('data.content.structured_data.amount_mentions.0.text', '2亿元')
            ->assertJsonPath('data.content.normalized_data.event_type', 'buyback')
            ->assertJsonPath('data.events.0.event_type', 'buyback')
            ->assertJsonPath('data.securities.0.canonical_symbol', 'CN.XSHG.600000');

        $eventResponse = $this->getJson("/api/v1/events/{$event->id}");

        $eventResponse
            ->assertOk()
            ->assertJsonPath('data.normalized_facts.event_type', 'buyback')
            ->assertJsonPath('data.normalized_facts.issuer.name', '浦发银行');
    }

    public function test_it_returns_event_detail_with_articles(): void
    {
        $source = NewsSource::query()->create([
            'source_key' => 'em_global_flash',
            'source_name' => '东方财富全球财经快讯',
            'source_type' => 'news',
            'provider' => 'akshare',
            'access_mode' => 'api',
        ]);

        $article = NewsArticle::query()->create([
            'source_id' => $source->id,
            'source_item_id' => 'demo-news-3',
            'title' => '央行发布重要政策消息',
            'summary' => '市场关注政策影响。',
            'canonical_url' => 'https://example.com/news/3',
            'published_at' => now(),
            'fetched_at' => now(),
            'source_timestamp' => now(),
            'category' => 'global_flash',
            'importance_level' => 'B',
            'sentiment' => 'neutral',
            'status' => 'published',
            'language' => 'zh-CN',
            'copyright_status' => 'restricted',
            'quality_status' => 'passed',
            'quality_score' => 90,
            'title_hash' => str_repeat('9', 64),
            'content_hash' => str_repeat('8', 64),
            'checksum' => str_repeat('7', 64),
        ]);

        $event = MarketEvent::query()->create([
            'event_type' => 'macro_news',
            'title' => '央行发布重要政策消息',
            'summary' => '市场关注政策影响。',
            'occurred_at' => now(),
            'detected_at' => now(),
            'importance_level' => 'B',
            'sentiment' => 'neutral',
            'confidence' => 0.55,
            'status' => 'published',
            'fingerprint' => str_repeat('2', 64),
            'facts' => ['matched_symbols' => []],
            'published_at' => now(),
        ]);

        EventSource::query()->create([
            'event_id' => $event->id,
            'news_article_id' => $article->id,
            'relation_type' => 'primary',
        ]);

        $response = $this->getJson("/api/v1/events/{$event->id}");

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.event_type', 'macro_news')
            ->assertJsonPath('data.normalized_facts', null)
            ->assertJsonPath('data.articles.0.title', '央行发布重要政策消息');
    }

    public function test_it_returns_event_chain_detail_with_timeline(): void
    {
        $source = NewsSource::query()->create([
            'source_key' => 'em_notice_report',
            'source_name' => '东方财富公告大全',
            'source_type' => 'announcement',
            'provider' => 'akshare',
            'access_mode' => 'api',
        ]);

        $security = Security::query()->create([
            'canonical_symbol' => 'CN.XSHE.300059',
            'symbol' => '300059',
            'exchange' => 'XSHE',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => '东方财富',
            'short_name' => '东方财富',
            'status' => 'active',
            'currency' => 'CNY',
        ]);

        $chain = EventChain::query()->create([
            'chain_key' => str_repeat('a', 64),
            'chain_type' => 'bond_issue',
            'topic' => '东方财富 / 债券发行 / bond_issue',
            'summary' => '东方财富债券发行事件链',
            'status' => 'active',
            'primary_security_id' => $security->id,
            'started_at' => now()->subDays(3),
            'latest_occurred_at' => now(),
            'latest_published_at' => now(),
            'importance_level' => 'A',
            'sentiment' => 'neutral',
            'event_count' => 2,
            'article_count' => 2,
            'facts' => [
                'version' => 'event-chain-v1',
                'latest_stage' => 'issuance_result',
            ],
        ]);

        $articleOne = NewsArticle::query()->create([
            'source_id' => $source->id,
            'source_item_id' => 'chain-news-1',
            'title' => '东方财富债券发行获批公告',
            'summary' => '债券发行审批通过。',
            'canonical_url' => 'https://example.com/chain/1',
            'published_at' => now()->subDays(2),
            'fetched_at' => now(),
            'source_timestamp' => now()->subDays(2),
            'category' => 'disclosure',
            'importance_level' => 'A',
            'sentiment' => 'neutral',
            'status' => 'published',
            'language' => 'zh-CN',
            'copyright_status' => 'public',
            'quality_status' => 'passed',
            'quality_score' => 92,
            'title_hash' => str_repeat('3', 64),
            'content_hash' => str_repeat('4', 64),
            'checksum' => str_repeat('5', 64),
        ]);

        $articleTwo = NewsArticle::query()->create([
            'source_id' => $source->id,
            'source_item_id' => 'chain-news-2',
            'title' => '东方财富债券发行结果公告',
            'summary' => '债券发行结果披露。',
            'canonical_url' => 'https://example.com/chain/2',
            'published_at' => now(),
            'fetched_at' => now(),
            'source_timestamp' => now(),
            'category' => 'disclosure',
            'importance_level' => 'A',
            'sentiment' => 'neutral',
            'status' => 'published',
            'language' => 'zh-CN',
            'copyright_status' => 'public',
            'quality_status' => 'passed',
            'quality_score' => 94,
            'title_hash' => str_repeat('6', 64),
            'content_hash' => str_repeat('7', 64),
            'checksum' => str_repeat('8', 64),
        ]);

        $eventOne = MarketEvent::query()->create([
            'event_type' => 'bond_issue',
            'title' => '东方财富债券发行获批公告',
            'summary' => '债券发行审批通过。',
            'occurred_at' => now()->subDays(2),
            'detected_at' => now()->subDays(2),
            'importance_level' => 'A',
            'sentiment' => 'neutral',
            'confidence' => 0.88,
            'status' => 'published',
            'primary_security_id' => $security->id,
            'event_chain_id' => $chain->id,
            'timeline_stage' => 'approval',
            'timeline_order' => 1,
            'fingerprint' => str_repeat('b', 64),
            'facts' => [
                'timeline' => [
                    'version' => 'event-chain-v1',
                    'stage' => 'approval',
                    'sequence' => 1,
                ],
            ],
            'published_at' => now()->subDays(2),
        ]);

        $eventTwo = MarketEvent::query()->create([
            'event_type' => 'bond_issue',
            'title' => '东方财富债券发行结果公告',
            'summary' => '债券发行结果披露。',
            'occurred_at' => now(),
            'detected_at' => now(),
            'importance_level' => 'A',
            'sentiment' => 'neutral',
            'confidence' => 0.91,
            'status' => 'published',
            'primary_security_id' => $security->id,
            'event_chain_id' => $chain->id,
            'timeline_stage' => 'issuance_result',
            'timeline_order' => 2,
            'fingerprint' => str_repeat('c', 64),
            'facts' => [
                'timeline' => [
                    'version' => 'event-chain-v1',
                    'stage' => 'issuance_result',
                    'sequence' => 2,
                ],
            ],
            'published_at' => now(),
        ]);

        EventSource::query()->create([
            'event_id' => $eventOne->id,
            'news_article_id' => $articleOne->id,
            'relation_type' => 'primary',
        ]);

        EventSource::query()->create([
            'event_id' => $eventTwo->id,
            'news_article_id' => $articleTwo->id,
            'relation_type' => 'primary',
        ]);

        $listResponse = $this->getJson('/api/v1/event-chains?securitySymbol=300059');

        $listResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.chain_type', 'bond_issue')
            ->assertJsonPath('data.0.primary_security.symbol', '300059');

        $detailResponse = $this->getJson("/api/v1/event-chains/{$chain->id}");

        $detailResponse
            ->assertOk()
            ->assertJsonPath('data.topic', '东方财富 / 债券发行 / bond_issue')
            ->assertJsonPath('data.timeline.0.timeline_stage', 'approval')
            ->assertJsonPath('data.timeline.1.timeline_stage', 'issuance_result')
            ->assertJsonPath('data.timeline.1.articles.0.title', '东方财富债券发行结果公告');

        $eventResponse = $this->getJson("/api/v1/events/{$eventTwo->id}");

        $eventResponse
            ->assertOk()
            ->assertJsonPath('data.timeline_stage', 'issuance_result')
            ->assertJsonPath('data.chain.chain_type', 'bond_issue')
            ->assertJsonPath('data.chain.id', $chain->id);
    }

    public function test_it_lists_signals_and_returns_signal_detail(): void
    {
        $security = Security::query()->create([
            'canonical_symbol' => 'CN.XSHE.300059',
            'symbol' => '300059',
            'exchange' => 'XSHE',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => '东方财富',
            'short_name' => '东方财富',
            'status' => 'active',
            'currency' => 'CNY',
        ]);

        $chain = EventChain::query()->create([
            'chain_key' => str_repeat('d', 64),
            'chain_type' => 'external_investment',
            'topic' => '东方财富 / 上海云锋新创投资管理有限公司 / external_investment',
            'summary' => '共同投资主题链',
            'status' => 'active',
            'primary_security_id' => $security->id,
            'started_at' => now()->subDay(),
            'latest_occurred_at' => now(),
            'latest_published_at' => now(),
            'importance_level' => 'A',
            'sentiment' => 'positive',
            'event_count' => 1,
            'article_count' => 1,
            'facts' => ['latest_stage' => 'investment_update'],
        ]);

        $event = MarketEvent::query()->create([
            'event_type' => 'external_investment',
            'title' => '东方财富关于与专业投资机构共同投资的公告',
            'summary' => '共同投资进展。',
            'occurred_at' => now(),
            'detected_at' => now(),
            'importance_level' => 'A',
            'sentiment' => 'positive',
            'confidence' => 0.87,
            'status' => 'published',
            'primary_security_id' => $security->id,
            'event_chain_id' => $chain->id,
            'timeline_stage' => 'investment_update',
            'timeline_order' => 1,
            'fingerprint' => str_repeat('e', 64),
            'facts' => ['timeline' => ['stage' => 'investment_update']],
            'published_at' => now(),
        ]);

        $rule = SignalRule::query()->create([
            'rule_key' => 'external_investment_theme',
            'name' => '对外投资主题信号',
            'scope_type' => 'event_chain',
            'chain_type' => 'external_investment',
            'signal_type' => 'theme_opportunity',
            'default_direction' => 'positive',
            'horizon_label' => 'medium_term',
            'horizon_days' => 20,
            'min_signal_score' => 66,
            'enabled' => true,
        ]);

        $signal = TradingSignal::query()->create([
            'signal_key' => str_repeat('f', 64),
            'signal_rule_id' => $rule->id,
            'event_chain_id' => $chain->id,
            'latest_event_id' => $event->id,
            'primary_security_id' => $security->id,
            'signal_type' => 'theme_opportunity',
            'direction' => 'positive',
            'horizon_label' => 'medium_term',
            'status' => 'active',
            'title' => '东方财富 对外投资 正向信号',
            'summary' => '主题投资信号。',
            'signal_score' => 78.50,
            'confidence_score' => 72.20,
            'urgency_score' => 80.10,
            'impact_score' => 76.40,
            'risk_score' => 34.50,
            'triggered_at' => now(),
            'published_at' => now(),
            'expires_at' => now()->addDays(20),
            'reasoning' => ['version' => 'signal-engine-v1'],
            'explanation' => [
                'version' => 'signal-insight-v1',
                'panel_type' => 'factor_explanation',
                'scorecard' => [
                    'signal_score' => 78.5,
                ],
            ],
            'performance_summary' => [
                'version' => 'signal-insight-v1',
                'evaluation_status' => 'evaluated',
                'best_horizon_days' => 5,
                'best_return_pct' => 6.4,
            ],
            'last_evaluated_at' => now(),
            'facts' => ['chain_type' => 'external_investment'],
        ]);

        SignalPerformanceSnapshot::query()->create([
            'trading_signal_id' => $signal->id,
            'horizon_days' => 5,
            'evaluation_status' => 'evaluated',
            'benchmark_code' => 'sz399001',
            'entry_trade_date' => now()->toDateString(),
            'exit_trade_date' => now()->addDays(5)->toDateString(),
            'holding_days' => 5,
            'entry_price' => 20.10,
            'exit_price' => 21.39,
            'return_pct' => 6.40,
            'benchmark_return_pct' => 1.80,
            'alpha_return_pct' => 4.60,
            'max_upside_pct' => 7.00,
            'max_drawdown_pct' => -1.20,
            'win_probability' => 100.00,
            'coverage_pct' => 100.00,
            'evaluated_at' => now(),
            'metrics' => ['version' => 'signal-insight-v1'],
        ]);

        $listResponse = $this->getJson('/api/v1/signals?securitySymbol=300059&minScore=70');

        $listResponse
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.0.signal_type', 'theme_opportunity')
            ->assertJsonPath('data.0.primary_security.symbol', '300059')
            ->assertJsonPath('data.0.chain.chain_type', 'external_investment');

        $detailResponse = $this->getJson("/api/v1/signals/{$signal->id}");

        $detailResponse
            ->assertOk()
            ->assertJsonPath('data.rule.rule_key', 'external_investment_theme')
            ->assertJsonPath('data.latest_event.id', $event->id)
            ->assertJsonPath('data.signal_score', 78.5)
            ->assertJsonPath('data.explanation.panel_type', 'factor_explanation')
            ->assertJsonPath('data.performance_summary.best_horizon_days', 5)
            ->assertJsonPath('data.performance_snapshots.0.alpha_return_pct', 4.6);
    }

    public function test_it_stores_subscription_and_dispatches_signal_webhook(): void
    {
        Http::fake([
            'https://example.com/hooks/signal' => Http::response(['ok' => true], 200),
        ]);

        $security = Security::query()->create([
            'canonical_symbol' => 'CN.XSHG.600000',
            'symbol' => '600000',
            'exchange' => 'XSHG',
            'market' => 'CN',
            'security_type' => 'stock',
            'name' => '浦发银行',
            'short_name' => '浦发银行',
            'status' => 'active',
            'currency' => 'CNY',
        ]);

        $chain = EventChain::query()->create([
            'chain_key' => str_repeat('1', 64),
            'chain_type' => 'buyback',
            'topic' => '浦发银行 / buyback',
            'summary' => '回购事件链',
            'status' => 'active',
            'primary_security_id' => $security->id,
            'started_at' => now()->subHours(2),
            'latest_occurred_at' => now()->subHours(2),
            'latest_published_at' => now()->subHours(2),
            'importance_level' => 'A',
            'sentiment' => 'positive',
            'event_count' => 1,
            'article_count' => 1,
            'facts' => ['latest_stage' => 'completion'],
        ]);

        $event = MarketEvent::query()->create([
            'event_type' => 'buyback',
            'title' => '浦发银行回购完成公告',
            'summary' => '回购完成。',
            'occurred_at' => now()->subHours(2),
            'detected_at' => now()->subHours(2),
            'importance_level' => 'A',
            'sentiment' => 'positive',
            'confidence' => 0.91,
            'status' => 'published',
            'primary_security_id' => $security->id,
            'event_chain_id' => $chain->id,
            'timeline_stage' => 'completion',
            'timeline_order' => 1,
            'fingerprint' => str_repeat('2', 64),
            'facts' => ['timeline' => ['stage' => 'completion']],
            'published_at' => now()->subHours(2),
        ]);

        $rule = SignalRule::query()->create([
            'rule_key' => 'buyback_alpha',
            'name' => '回购事件强势信号',
            'scope_type' => 'event_chain',
            'chain_type' => 'buyback',
            'signal_type' => 'alpha_opportunity',
            'default_direction' => 'positive',
            'horizon_label' => 'short_term',
            'horizon_days' => 10,
            'min_signal_score' => 68,
            'enabled' => true,
        ]);

        $signal = TradingSignal::query()->create([
            'signal_key' => str_repeat('3', 64),
            'signal_rule_id' => $rule->id,
            'event_chain_id' => $chain->id,
            'latest_event_id' => $event->id,
            'primary_security_id' => $security->id,
            'signal_type' => 'alpha_opportunity',
            'direction' => 'positive',
            'horizon_label' => 'short_term',
            'status' => 'active',
            'title' => '浦发银行回购完成正向信号',
            'summary' => '回购完成。',
            'signal_score' => 82.40,
            'confidence_score' => 79.10,
            'urgency_score' => 84.50,
            'impact_score' => 81.30,
            'risk_score' => 22.10,
            'triggered_at' => now()->subHours(2),
            'published_at' => now()->subHours(2),
            'expires_at' => now()->addDays(10),
            'reasoning' => ['version' => 'signal-engine-v1'],
            'facts' => ['chain_type' => 'buyback'],
        ]);

        $response = $this->postJson('/api/v1/signal-subscriptions', [
            'subscriber_key' => 'desk-a',
            'subscriber_name' => 'Desk A',
            'channel_type' => 'webhook',
            'endpoint_url' => 'https://example.com/hooks/signal',
            'secret_token' => 'signal-secret',
            'min_signal_score' => 70,
            'filters' => [
                'security_symbols' => ['600000'],
                'signal_types' => ['alpha_opportunity'],
                'directions' => ['positive'],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.subscriber_key', 'desk-a')
            ->assertJsonPath('data.channel_type', 'webhook');

        Artisan::call('signals:dispatch', ['--limit' => 10]);

        $delivery = SignalDelivery::query()->first();

        $this->assertNotNull($delivery);
        $this->assertSame($signal->id, $delivery->trading_signal_id);
        $this->assertSame('success', $delivery->delivery_status);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/hooks/signal'
                && $request->hasHeader('X-Trading-Signal-Token', 'signal-secret')
                && $request['signal']['signal_type'] === 'alpha_opportunity';
        });

        $subscription = SignalSubscription::query()->first();
        $this->assertNotNull($subscription);
        $this->assertNotNull($subscription->last_notified_at);
    }
}
