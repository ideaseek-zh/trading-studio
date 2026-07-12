from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
import hashlib
from typing import Any

from sqlalchemy import select
from sqlalchemy.dialects.mysql import insert
from sqlalchemy.orm import Session

from app.models.event_chain import EventChain
from app.models.market_event import MarketEvent
from app.models.signal_rule import SignalRule
from app.models.trading_signal import TradingSignal


@dataclass(slots=True)
class SignalRebuildResult:
    processed: int
    rules_seeded: int
    signals_created: int
    signals_updated: int
    signals_suppressed: int


class SignalEngineService:
    version = "signal-engine-v1"

    def __init__(self, db: Session) -> None:
        self.db = db

    def rebuild_all(
        self,
        *,
        security_id: int | None = None,
        event_chain_id: int | None = None,
    ) -> dict[str, int]:
        from app.services.signal_insight import SignalInsightService

        rules_seeded = self.seed_default_rules()
        chain_query = select(EventChain)
        if security_id is not None:
            chain_query = chain_query.where(EventChain.primary_security_id == security_id)
        if event_chain_id is not None:
            chain_query = chain_query.where(EventChain.id == event_chain_id)

        chains = self.db.execute(chain_query.order_by(EventChain.latest_occurred_at.desc())).scalars().all()

        created = 0
        updated = 0
        suppressed = 0
        for chain in chains:
            result = self.evaluate_chain(chain)
            created += result["created"]
            updated += result["updated"]
            suppressed += result["suppressed"]

        insight_service = SignalInsightService(self.db)
        insight_service.rebuild_all(security_id=security_id)

        result = SignalRebuildResult(
            processed=len(chains),
            rules_seeded=rules_seeded,
            signals_created=created,
            signals_updated=updated,
            signals_suppressed=suppressed,
        )
        return {
            "processed": result.processed,
            "rules_seeded": result.rules_seeded,
            "signals_created": result.signals_created,
            "signals_updated": result.signals_updated,
            "signals_suppressed": result.signals_suppressed,
        }

    def seed_default_rules(self) -> int:
        now = self._now()
        rules = [
            {
                "rule_key": "buyback_alpha",
                "name": "回购事件强势信号",
                "description": "针对股份回购、回购进展和回购完成等事件链，生成偏正向短中期交易信号。",
                "scope_type": "event_chain",
                "chain_type": "buyback",
                "signal_type": "alpha_opportunity",
                "default_direction": "positive",
                "horizon_label": "short_term",
                "horizon_days": 10,
                "min_signal_score": 68,
                "enabled": True,
                "weight_profile": {"impact": 0.38, "urgency": 0.24, "confidence": 0.20, "risk": 0.18},
                "trigger_conditions": {"stages": ["board_resolution", "progress", "completion"]},
                "created_at": now,
                "updated_at": now,
            },
            {
                "rule_key": "external_investment_theme",
                "name": "对外投资主题信号",
                "description": "针对产业投资、共同投资和基金参与类事件链，生成偏主题型中期信号。",
                "scope_type": "event_chain",
                "chain_type": "external_investment",
                "signal_type": "theme_opportunity",
                "default_direction": "positive",
                "horizon_label": "medium_term",
                "horizon_days": 20,
                "min_signal_score": 60,
                "enabled": True,
                "weight_profile": {"impact": 0.38, "urgency": 0.18, "confidence": 0.20, "risk": 0.24},
                "trigger_conditions": {"stages": ["board_resolution", "signing", "completion", "investment_update"]},
                "created_at": now,
                "updated_at": now,
            },
            {
                "rule_key": "bond_issue_watch",
                "name": "融资发行跟踪信号",
                "description": "针对债券发行、融资券兑付和审批进展等链路，生成融资进展信号。",
                "scope_type": "event_chain",
                "chain_type": "bond_issue",
                "signal_type": "financing_watch",
                "default_direction": "neutral",
                "horizon_label": "short_term",
                "horizon_days": 7,
                "min_signal_score": 55,
                "enabled": True,
                "weight_profile": {"impact": 0.30, "urgency": 0.25, "confidence": 0.25, "risk": 0.20},
                "trigger_conditions": {"stages": ["approval", "issuance_result", "redemption", "issuance_update"]},
                "created_at": now,
                "updated_at": now,
            },
            {
                "rule_key": "regulatory_risk_alert",
                "name": "监管风险预警",
                "description": "针对监管处罚、问询、立案和高风险公告，生成负向风险预警信号。",
                "scope_type": "event_chain",
                "chain_type": "regulatory",
                "signal_type": "risk_alert",
                "default_direction": "negative",
                "horizon_label": "immediate",
                "horizon_days": 5,
                "min_signal_score": 60,
                "enabled": True,
                "weight_profile": {"impact": 0.28, "urgency": 0.24, "confidence": 0.18, "risk": 0.30},
                "trigger_conditions": {"risk_levels": ["medium", "high"]},
                "created_at": now,
                "updated_at": now,
            },
            {
                "rule_key": "investor_relations_monitor",
                "name": "投资者关系监控信号",
                "description": "针对调研纪要和投资者关系活动，生成低强度研究跟踪信号。",
                "scope_type": "event_chain",
                "chain_type": "investor_relations",
                "signal_type": "research_watch",
                "default_direction": "neutral",
                "horizon_label": "monitoring",
                "horizon_days": 3,
                "min_signal_score": 45,
                "enabled": True,
                "weight_profile": {"impact": 0.22, "urgency": 0.30, "confidence": 0.28, "risk": 0.20},
                "trigger_conditions": {"stages": ["activity_record"]},
                "created_at": now,
                "updated_at": now,
            },
            {
                "rule_key": "generic_event_watch",
                "name": "高重要度事件跟踪",
                "description": "针对无专属规则但重要度较高的事件链，生成通用监控信号。",
                "scope_type": "event_chain",
                "chain_type": None,
                "signal_type": "general_watch",
                "default_direction": "neutral",
                "horizon_label": "monitoring",
                "horizon_days": 5,
                "min_signal_score": 58,
                "enabled": True,
                "weight_profile": {"impact": 0.30, "urgency": 0.25, "confidence": 0.20, "risk": 0.25},
                "trigger_conditions": {"importance_levels": ["S", "A"]},
                "created_at": now,
                "updated_at": now,
            },
        ]

        statement = insert(SignalRule).values(rules)
        self.db.execute(
            statement.on_duplicate_key_update(
                name=statement.inserted.name,
                description=statement.inserted.description,
                scope_type=statement.inserted.scope_type,
                chain_type=statement.inserted.chain_type,
                signal_type=statement.inserted.signal_type,
                default_direction=statement.inserted.default_direction,
                horizon_label=statement.inserted.horizon_label,
                horizon_days=statement.inserted.horizon_days,
                min_signal_score=statement.inserted.min_signal_score,
                enabled=statement.inserted.enabled,
                weight_profile=statement.inserted.weight_profile,
                trigger_conditions=statement.inserted.trigger_conditions,
                updated_at=statement.inserted.updated_at,
            )
        )
        self.db.flush()
        return len(rules)

    def evaluate_chain(self, chain: EventChain) -> dict[str, int]:
        latest_event = self._latest_event(chain.id)
        if latest_event is None:
            return {"created": 0, "updated": 0, "suppressed": 0}

        applicable_rules = self._applicable_rules(chain)
        created = 0
        updated = 0
        suppressed = 0

        for rule in applicable_rules:
            payload = self._build_signal_payload(chain, latest_event, rule)
            if payload is None:
                suppressed += self._suppress_active_signal(chain.id, rule.id)
                continue

            signal = self.db.scalar(select(TradingSignal).where(TradingSignal.signal_key == payload["signal_key"]))
            is_insert = signal is None

            if signal is None:
                signal = TradingSignal(signal_key=payload["signal_key"])
                self.db.add(signal)
                self._supersede_older_signals(chain.id, rule.id, payload["signal_key"])

            for key, value in payload.items():
                if key == "signal_key":
                    continue
                setattr(signal, key, value)

            signal.updated_at = self._now()
            if is_insert:
                signal.created_at = signal.updated_at
                created += 1
            else:
                updated += 1

        self.db.flush()
        return {"created": created, "updated": updated, "suppressed": suppressed}

    def _applicable_rules(self, chain: EventChain) -> list[SignalRule]:
        all_rules = self.db.execute(
            select(SignalRule)
            .where(SignalRule.enabled.is_(True))
            .order_by(SignalRule.id.asc())
        ).scalars().all()

        matched = [rule for rule in all_rules if rule.chain_type == chain.chain_type]
        if matched:
            return matched
        return [rule for rule in all_rules if rule.chain_type is None]

    def _build_signal_payload(
        self,
        chain: EventChain,
        latest_event: MarketEvent,
        rule: SignalRule,
    ) -> dict[str, Any] | None:
        stage = latest_event.timeline_stage or "announcement"
        risk_level = str((chain.facts or {}).get("risk_level") or "none")
        trigger_conditions = dict(rule.trigger_conditions or {})

        if trigger_conditions.get("stages") and stage not in trigger_conditions["stages"]:
            return None
        if trigger_conditions.get("risk_levels") and risk_level not in trigger_conditions["risk_levels"]:
            return None
        if trigger_conditions.get("importance_levels") and chain.importance_level not in trigger_conditions["importance_levels"]:
            return None

        impact_score = self._impact_score(chain, latest_event, rule)
        confidence_score = self._confidence_score(chain, latest_event, stage)
        urgency_score = self._urgency_score(chain, latest_event, stage)
        risk_score = self._risk_score(chain, latest_event, rule)

        weights = dict(rule.weight_profile or {})
        signal_score = self._clamp(
            impact_score * float(weights.get("impact", 0.30))
            + urgency_score * float(weights.get("urgency", 0.25))
            + confidence_score * float(weights.get("confidence", 0.20))
            + (100 - risk_score) * float(weights.get("risk", 0.25))
        )

        signal_score *= self._signal_type_multiplier(rule.signal_type)
        signal_score = self._clamp(signal_score)

        if signal_score < float(rule.min_signal_score):
            return None

        direction = self._direction(chain, latest_event, rule)
        now = self._now()
        triggered_at = latest_event.occurred_at or chain.latest_occurred_at
        expires_at = triggered_at + timedelta(days=int(rule.horizon_days or 5))
        signal_key = self._sha256(f"{chain.chain_key}|{rule.rule_key}|{stage}")

        reasoning = {
            "version": self.version,
            "rule_key": rule.rule_key,
            "chain_type": chain.chain_type,
            "timeline_stage": stage,
            "impact_score": round(impact_score, 2),
            "confidence_score": round(confidence_score, 2),
            "urgency_score": round(urgency_score, 2),
            "risk_score": round(risk_score, 2),
            "score_breakdown": {
                "importance_level": chain.importance_level,
                "event_count": chain.event_count,
                "article_count": chain.article_count,
                "risk_level": risk_level,
                "stage_label": self._stage_label(stage),
            },
        }
        facts = {
            "engine_version": self.version,
            "event_chain_id": chain.id,
            "latest_event_id": latest_event.id,
            "chain_type": chain.chain_type,
            "timeline_stage": stage,
            "risk_level": risk_level,
            "event_count": chain.event_count,
            "article_count": chain.article_count,
            "issuer": (chain.facts or {}).get("issuer"),
            "counterparties": (chain.facts or {}).get("counterparties") or [],
        }

        return {
            "signal_key": signal_key,
            "signal_rule_id": rule.id,
            "event_chain_id": chain.id,
            "latest_event_id": latest_event.id,
            "primary_security_id": chain.primary_security_id,
            "signal_type": rule.signal_type,
            "direction": direction,
            "horizon_label": rule.horizon_label,
            "status": "active" if expires_at >= now else "expired",
            "title": self._signal_title(chain, latest_event, rule, direction),
            "summary": self._signal_summary(chain, latest_event, rule, signal_score, direction),
            "signal_score": round(signal_score, 2),
            "confidence_score": round(confidence_score, 2),
            "urgency_score": round(urgency_score, 2),
            "impact_score": round(impact_score, 2),
            "risk_score": round(risk_score, 2),
            "triggered_at": triggered_at,
            "published_at": now,
            "expires_at": expires_at,
            "reasoning": reasoning,
            "facts": facts,
        }

    def _latest_event(self, chain_id: int) -> MarketEvent | None:
        return self.db.scalar(
            select(MarketEvent)
            .where(
                MarketEvent.event_chain_id == chain_id,
                MarketEvent.status != "merged",
            )
            .order_by(MarketEvent.timeline_order.desc(), MarketEvent.occurred_at.desc(), MarketEvent.id.desc())
            .limit(1)
        )

    def _impact_score(self, chain: EventChain, event: MarketEvent, rule: SignalRule) -> float:
        base = {
            "buyback": 86,
            "external_investment": 80,
            "bond_issue": 68,
            "regulatory": 82,
            "share_increase": 76,
            "share_reduction": 78,
            "earnings": 79,
            "investor_relations": 56,
            "board_resolution": 52,
        }.get(chain.chain_type, 58)
        importance_bonus = {"S": 12, "A": 8, "B": 4, "C": 0}.get(chain.importance_level, 0)
        article_bonus = min(int(chain.article_count or 0) * 2, 8)
        event_bonus = min(int(chain.event_count or 0) * 2, 10)

        if event.timeline_stage in {"completion", "redemption"}:
            base += 8
        elif event.timeline_stage in {"issuance_result", "approval", "signing"}:
            base += 5

        return self._clamp(base + importance_bonus + article_bonus + event_bonus)

    def _confidence_score(self, chain: EventChain, event: MarketEvent, stage: str) -> float:
        base = 58
        if stage in {"completion", "redemption", "issuance_result", "approval"}:
            base += 18
        elif stage in {"board_resolution", "signing", "activity_record"}:
            base += 10

        event_confidence = float(event.confidence or 0.55) * 30
        article_bonus = min(int(chain.article_count or 0) * 3, 12)
        return self._clamp(base + event_confidence + article_bonus)

    def _urgency_score(self, chain: EventChain, event: MarketEvent, stage: str) -> float:
        now = self._now()
        delta_hours = max((now - (event.published_at or event.occurred_at)).total_seconds() / 3600, 0)
        if delta_hours <= 6:
            recency = 95
        elif delta_hours <= 24:
            recency = 86
        elif delta_hours <= 72:
            recency = 74
        elif delta_hours <= 168:
            recency = 62
        else:
            recency = 48

        stage_bonus = {
            "redemption": 10,
            "completion": 10,
            "issuance_result": 8,
            "approval": 7,
            "board_resolution": 5,
            "activity_record": 4,
        }.get(stage, 0)
        importance_bonus = {"S": 8, "A": 5, "B": 2, "C": 0}.get(chain.importance_level, 0)
        return self._clamp(recency + stage_bonus + importance_bonus)

    def _risk_score(self, chain: EventChain, event: MarketEvent, rule: SignalRule) -> float:
        risk_level = str((chain.facts or {}).get("risk_level") or "none")
        base = {"none": 18, "low": 26, "medium": 48, "high": 72}.get(risk_level, 30)
        if chain.chain_type in {"regulatory", "litigation", "guarantee"}:
            base += 12
        if rule.signal_type == "risk_alert":
            base += 10
        if event.timeline_stage in {"completion", "redemption"} and chain.chain_type not in {"regulatory", "litigation"}:
            base -= 6
        return self._clamp(base)

    @staticmethod
    def _direction(chain: EventChain, event: MarketEvent, rule: SignalRule) -> str:
        if chain.chain_type in {"regulatory", "litigation", "share_reduction", "guarantee"}:
            return "negative"
        if chain.chain_type == "bond_issue" and event.timeline_stage == "redemption":
            return "positive"
        return rule.default_direction

    def _signal_title(self, chain: EventChain, event: MarketEvent, rule: SignalRule, direction: str) -> str:
        direction_cn = {"positive": "正向", "negative": "负向", "neutral": "中性"}.get(direction, direction)
        return f"{chain.topic} {self._stage_label(event.timeline_stage or 'announcement')} {direction_cn}信号"

    def _signal_summary(
        self,
        chain: EventChain,
        event: MarketEvent,
        rule: SignalRule,
        signal_score: float,
        direction: str,
    ) -> str:
        return (
            f"{chain.topic} 在 {self._stage_label(event.timeline_stage or 'announcement')} 阶段触发 "
            f"{rule.name}，方向为 {direction}，综合评分 {round(signal_score, 2)}。"
        )[:300]

    @staticmethod
    def _signal_type_multiplier(signal_type: str) -> float:
        return {
            "alpha_opportunity": 1.0,
            "theme_opportunity": 0.96,
            "risk_alert": 0.98,
            "financing_watch": 0.90,
            "research_watch": 0.72,
            "general_watch": 0.78,
        }.get(signal_type, 0.85)

    def _supersede_older_signals(self, chain_id: int, rule_id: int | None, current_signal_key: str) -> None:
        rows = self.db.execute(
            select(TradingSignal).where(
                TradingSignal.event_chain_id == chain_id,
                TradingSignal.signal_rule_id == rule_id,
                TradingSignal.signal_key != current_signal_key,
                TradingSignal.status == "active",
            )
        ).scalars().all()
        for row in rows:
            row.status = "superseded"
            row.updated_at = self._now()

    def _suppress_active_signal(self, chain_id: int, rule_id: int | None) -> int:
        rows = self.db.execute(
            select(TradingSignal).where(
                TradingSignal.event_chain_id == chain_id,
                TradingSignal.signal_rule_id == rule_id,
                TradingSignal.status == "active",
            )
        ).scalars().all()
        for row in rows:
            row.status = "suppressed"
            row.updated_at = self._now()
        return len(rows)

    @staticmethod
    def _stage_label(stage: str) -> str:
        labels = {
            "approval": "审批通过",
            "issuance_result": "发行结果",
            "redemption": "兑付完成",
            "issuance_update": "发行进展",
            "completion": "完成落地",
            "signing": "协议签署",
            "board_resolution": "董事会审议",
            "investment_update": "投资进展",
            "progress": "实施进展",
            "buyback_update": "回购进展",
            "activity_record": "调研记录",
            "earnings_flash": "业绩快报",
            "forecast": "业绩预告",
            "earnings_update": "业绩更新",
            "announcement": "公告更新",
        }
        return labels.get(stage, stage)

    @staticmethod
    def _sha256(value: str) -> str:
        return hashlib.sha256(value.encode("utf-8")).hexdigest()

    @staticmethod
    def _clamp(value: float, low: float = 0, high: float = 100) -> float:
        return max(low, min(value, high))

    @staticmethod
    def _now() -> datetime:
        return datetime.now(UTC).replace(tzinfo=None)
