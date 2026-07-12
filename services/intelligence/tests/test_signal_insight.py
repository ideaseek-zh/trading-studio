from __future__ import annotations

from datetime import UTC, datetime
import unittest

from app.models.event_chain import EventChain
from app.models.market_event import MarketEvent
from app.models.signal_rule import SignalRule
from app.models.trading_signal import TradingSignal
from app.services.signal_insight import SignalInsightService


class SignalInsightServiceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.service = SignalInsightService.__new__(SignalInsightService)

    def test_return_pct_and_window_extremes(self) -> None:
        self.assertEqual(self.service._return_pct(10, 11, "positive"), 10.0)
        self.assertEqual(self.service._return_pct(10, 9, "negative"), 10.0)

        max_upside, max_drawdown = self.service._window_extremes(
            entry_price=10,
            window=[
                {"close": 10},
                {"close": 11},
                {"close": 9.5},
            ],
            direction="positive",
        )
        self.assertEqual(max_upside, 10.0)
        self.assertEqual(max_drawdown, -5.0)

    def test_builds_explanation_panel(self) -> None:
        now = datetime.now(UTC).replace(tzinfo=None)
        signal = TradingSignal(
            id=1,
            signal_type="theme_opportunity",
            direction="positive",
            horizon_label="medium_term",
            title="东方财富 对外投资 正向信号",
            summary="示例摘要",
            signal_score=64.55,
            confidence_score=72.0,
            urgency_score=75.0,
            impact_score=81.0,
            risk_score=58.0,
            triggered_at=now,
            reasoning={
                "timeline_stage": "investment_update",
                "chain_type": "external_investment",
                "impact_score": 81.0,
                "confidence_score": 72.0,
                "urgency_score": 75.0,
                "risk_score": 58.0,
                "score_breakdown": {
                    "importance_level": "A",
                    "event_count": 1,
                    "article_count": 1,
                    "risk_level": "high",
                },
            },
            facts={
                "counterparties": [
                    {"name": "上海云锋新创投资管理有限公司"},
                ],
            },
        )
        chain = EventChain(
            id=5,
            chain_key="chain",
            chain_type="external_investment",
            topic="东方财富 / 上海云锋新创投资管理有限公司 / external_investment",
            status="active",
            primary_security_id=1,
            started_at=now,
            latest_occurred_at=now,
            latest_published_at=now,
            importance_level="A",
            sentiment="positive",
            event_count=1,
            article_count=1,
            facts={},
        )
        event = MarketEvent(
            id=10,
            event_type="external_investment",
            title="东方财富共同投资公告",
            summary="共同投资",
            occurred_at=now,
            detected_at=now,
            importance_level="A",
            sentiment="positive",
            confidence=0.8,
            status="published",
            primary_security_id=1,
            event_chain_id=5,
            timeline_stage="investment_update",
            timeline_order=1,
            fingerprint="f" * 64,
            published_at=now,
        )
        rule = SignalRule(
            id=2,
            rule_key="external_investment_theme",
            name="对外投资主题信号",
            signal_type="theme_opportunity",
        )

        explanation = self.service._build_explanation(signal, chain, event, rule)

        self.assertEqual(explanation["panel_type"], "factor_explanation")
        self.assertEqual(explanation["context"]["timeline_stage"], "investment_update")
        self.assertEqual(explanation["scorecard"]["signal_score"], 64.55)
        self.assertGreaterEqual(len(explanation["factor_contributions"]), 4)
        self.assertTrue(any("风险等级为 high" in item for item in explanation["risk_points"]))


if __name__ == "__main__":
    unittest.main()
