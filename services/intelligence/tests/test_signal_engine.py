from __future__ import annotations

from datetime import UTC, datetime, timedelta
import unittest

from app.models.event_chain import EventChain
from app.models.market_event import MarketEvent
from app.models.signal_rule import SignalRule
from app.services.signal_engine import SignalEngineService


class SignalEngineServiceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.service = SignalEngineService.__new__(SignalEngineService)

    def test_builds_high_score_for_buyback_completion(self) -> None:
        now = datetime.now(UTC).replace(tzinfo=None)
        chain = EventChain(
            id=1,
            chain_key="chain-1",
            chain_type="buyback",
            topic="浦发银行 / buyback",
            status="active",
            primary_security_id=1,
            started_at=now - timedelta(days=2),
            latest_occurred_at=now - timedelta(hours=3),
            latest_published_at=now - timedelta(hours=3),
            importance_level="A",
            sentiment="positive",
            event_count=2,
            article_count=2,
            facts={"risk_level": "none", "issuer": {"name": "浦发银行"}, "counterparties": []},
        )
        event = MarketEvent(
            id=11,
            event_type="buyback",
            title="浦发银行回购完成公告",
            summary="公司披露回购完成。",
            occurred_at=now - timedelta(hours=3),
            detected_at=now - timedelta(hours=3),
            importance_level="A",
            sentiment="positive",
            confidence=0.88,
            status="published",
            primary_security_id=1,
            event_chain_id=1,
            timeline_stage="completion",
            timeline_order=2,
            fingerprint="f" * 64,
            published_at=now - timedelta(hours=3),
        )
        rule = SignalRule(
            id=101,
            rule_key="buyback_alpha",
            name="回购事件强势信号",
            chain_type="buyback",
            signal_type="alpha_opportunity",
            default_direction="positive",
            horizon_label="short_term",
            horizon_days=10,
            min_signal_score=68,
            enabled=True,
            weight_profile={"impact": 0.38, "urgency": 0.24, "confidence": 0.20, "risk": 0.18},
            trigger_conditions={"stages": ["completion"]},
        )

        payload = self.service._build_signal_payload(chain, event, rule)

        self.assertIsNotNone(payload)
        assert payload is not None
        self.assertEqual(payload["direction"], "positive")
        self.assertGreaterEqual(payload["signal_score"], 75)
        self.assertEqual(payload["status"], "active")

    def test_suppresses_signal_below_threshold(self) -> None:
        now = datetime.now(UTC).replace(tzinfo=None)
        chain = EventChain(
            id=2,
            chain_key="chain-2",
            chain_type="investor_relations",
            topic="赛意信息 / investor_relations",
            status="active",
            primary_security_id=2,
            started_at=now - timedelta(days=1),
            latest_occurred_at=now - timedelta(days=1),
            latest_published_at=now - timedelta(days=1),
            importance_level="C",
            sentiment="neutral",
            event_count=1,
            article_count=1,
            facts={"risk_level": "none", "issuer": {"name": "赛意信息"}, "counterparties": []},
        )
        event = MarketEvent(
            id=12,
            event_type="investor_relations",
            title="赛意信息投资者关系活动记录表",
            summary="投资者交流活动。",
            occurred_at=now - timedelta(days=1),
            detected_at=now - timedelta(days=1),
            importance_level="C",
            sentiment="neutral",
            confidence=0.55,
            status="published",
            primary_security_id=2,
            event_chain_id=2,
            timeline_stage="activity_record",
            timeline_order=1,
            fingerprint="e" * 64,
            published_at=now - timedelta(days=1),
        )
        rule = SignalRule(
            id=102,
            rule_key="generic_event_watch",
            name="高重要度事件跟踪",
            chain_type=None,
            signal_type="general_watch",
            default_direction="neutral",
            horizon_label="monitoring",
            horizon_days=3,
            min_signal_score=80,
            enabled=True,
            weight_profile={"impact": 0.30, "urgency": 0.25, "confidence": 0.20, "risk": 0.25},
            trigger_conditions={},
        )

        payload = self.service._build_signal_payload(chain, event, rule)

        self.assertIsNone(payload)


if __name__ == "__main__":
    unittest.main()
