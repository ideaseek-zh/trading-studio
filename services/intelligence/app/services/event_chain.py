from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime
import hashlib
import re
from typing import Any

from sqlalchemy import delete, distinct, func, select, update
from sqlalchemy.orm import Session

from app.models.event_chain import EventChain
from app.models.event_source import EventSource
from app.models.market_event import MarketEvent


@dataclass(slots=True)
class EventChainAssignmentResult:
    chain_id: int
    chain_key: str
    chain_type: str
    timeline_stage: str
    created: int
    updated: int


class EventChainService:
    version = "event-chain-v1"

    def __init__(self, db: Session) -> None:
        self.db = db

    def assign_event(self, event: MarketEvent) -> EventChainAssignmentResult:
        normalized = dict((event.facts or {}).get("normalized_data") or {})
        chain_type = str(normalized.get("event_type") or event.event_type or "disclosure")
        topic_key = self._topic_key(event.title, chain_type, normalized, event.primary_security_id)
        chain_key = self._sha256(f"{event.primary_security_id or 0}|{chain_type}|{topic_key}")
        timeline_stage = self._timeline_stage(title=event.title, chain_type=chain_type, normalized=normalized)

        chain = self.db.scalar(select(EventChain).where(EventChain.chain_key == chain_key))
        is_insert = chain is None
        now = self._now()

        if chain is None:
            chain = EventChain(chain_key=chain_key)
            self.db.add(chain)

        chain.chain_type = chain_type
        chain.topic = self._topic_title(event.title, chain_type, normalized)
        chain.summary = self._chain_summary(event.summary, normalized, timeline_stage)
        chain.status = self._chain_status(chain_type, timeline_stage)
        chain.primary_security_id = event.primary_security_id
        chain.started_at = min(chain.started_at, event.occurred_at) if chain.started_at else event.occurred_at
        chain.latest_occurred_at = max(chain.latest_occurred_at, event.occurred_at) if chain.latest_occurred_at else event.occurred_at
        latest_published = event.published_at or event.occurred_at
        chain.latest_published_at = max(chain.latest_published_at, latest_published) if chain.latest_published_at else latest_published
        chain.importance_level = self._higher_importance(chain.importance_level, event.importance_level) if chain.importance_level else event.importance_level
        chain.sentiment = event.sentiment or chain.sentiment
        chain.facts = self._chain_facts(chain_type, topic_key, timeline_stage, normalized)
        chain.updated_at = now
        if is_insert:
            chain.created_at = now

        self.db.flush()

        event.event_chain_id = chain.id
        event.timeline_stage = timeline_stage
        event.updated_at = now
        event_facts = dict(event.facts or {})
        event_facts["timeline"] = {
            "version": self.version,
            "chain_id": chain.id,
            "chain_key": chain.chain_key,
            "chain_type": chain.chain_type,
            "topic_key": topic_key,
            "topic": chain.topic,
            "stage": timeline_stage,
            "stage_label": self._stage_label(timeline_stage),
        }
        event.facts = event_facts

        self.db.flush()
        self._refresh_chain_timeline(chain.id)

        return EventChainAssignmentResult(
            chain_id=chain.id,
            chain_key=chain.chain_key,
            chain_type=chain.chain_type,
            timeline_stage=timeline_stage,
            created=1 if is_insert else 0,
            updated=0 if is_insert else 1,
        )

    def rebuild_all(self, *, security_id: int | None = None) -> dict[str, int]:
        event_query = select(MarketEvent).where(MarketEvent.status != "merged")
        if security_id is not None:
            event_query = event_query.where(MarketEvent.primary_security_id == security_id)

        events = self.db.execute(event_query.order_by(MarketEvent.occurred_at.asc(), MarketEvent.id.asc())).scalars().all()

        if security_id is None:
            self.db.execute(update(MarketEvent).values(event_chain_id=None, timeline_stage=None, timeline_order=None))
            self.db.execute(delete(EventChain))
        else:
            chain_ids = self.db.execute(
                select(distinct(MarketEvent.event_chain_id)).where(
                    MarketEvent.primary_security_id == security_id,
                    MarketEvent.event_chain_id.is_not(None),
                )
            ).scalars().all()
            self.db.execute(
                update(MarketEvent)
                .where(MarketEvent.primary_security_id == security_id)
                .values(event_chain_id=None, timeline_stage=None, timeline_order=None)
            )
            if chain_ids:
                self.db.execute(delete(EventChain).where(EventChain.id.in_(chain_ids)))

        self.db.flush()

        created = 0
        updated = 0
        for event in events:
            result = self.assign_event(event)
            created += result.created
            updated += result.updated

        return {
            "processed": len(events),
            "chains_created": created,
            "chains_updated": updated,
        }

    def _refresh_chain_timeline(self, chain_id: int) -> None:
        active_events = self.db.execute(
            select(MarketEvent)
            .where(
                MarketEvent.event_chain_id == chain_id,
                MarketEvent.status != "merged",
            )
            .order_by(MarketEvent.occurred_at.asc(), MarketEvent.id.asc())
        ).scalars().all()

        for index, event in enumerate(active_events, start=1):
            event.timeline_order = index
            event_facts = dict(event.facts or {})
            timeline = dict(event_facts.get("timeline") or {})
            timeline["sequence"] = index
            event_facts["timeline"] = timeline
            event.facts = event_facts

        chain = self.db.get(EventChain, chain_id)
        if chain is None:
            return

        article_count = self.db.scalar(
            select(func.count(distinct(EventSource.news_article_id)))
            .select_from(EventSource)
            .join(MarketEvent, MarketEvent.id == EventSource.event_id)
            .where(
                MarketEvent.event_chain_id == chain_id,
                MarketEvent.status != "merged",
            )
        ) or 0
        stage_counts: dict[str, int] = {}
        for event in active_events:
            stage = event.timeline_stage or "announcement"
            stage_counts[stage] = stage_counts.get(stage, 0) + 1

        chain.event_count = len(active_events)
        chain.article_count = int(article_count)
        if active_events:
            first_event = active_events[0]
            last_event = active_events[-1]
            chain.started_at = first_event.occurred_at
            chain.latest_occurred_at = last_event.occurred_at
            chain.latest_published_at = max(
                (item.published_at or item.occurred_at for item in active_events),
                default=last_event.occurred_at,
            )
            chain.importance_level = self._max_importance_level(active_events)
            chain.sentiment = last_event.sentiment or chain.sentiment
            facts = dict(chain.facts or {})
            facts["timeline_summary"] = {
                "version": self.version,
                "stage_counts": stage_counts,
                "first_event_id": first_event.id,
                "latest_event_id": last_event.id,
                "article_count": chain.article_count,
            }
            chain.facts = facts
            chain.updated_at = self._now()

    @staticmethod
    def _topic_key(
        title: str,
        chain_type: str,
        normalized: dict[str, Any],
        primary_security_id: int | None,
    ) -> str:
        counterparties = [str(item.get("name") or "").strip() for item in normalized.get("counterparties") or [] if item.get("name")]
        proposal_types = [str(item) for item in normalized.get("proposal_summary", {}).get("proposal_types") or [] if item]
        date_summary = normalized.get("date_summary") or {}

        if chain_type == "external_investment" and counterparties:
            return "|".join(sorted(counterparties)[:2])
        if chain_type == "bond_issue":
            return EventChainService._bond_topic_key(title)
        if chain_type == "buyback":
            return "buyback|" + "|".join(proposal_types[:2] or ["plan"])
        if chain_type == "investor_relations":
            return str(date_summary.get("decision_date") or title)
        if proposal_types:
            return "|".join(proposal_types[:3])

        cleaned = EventChainService._clean_topic_tokens(title)
        return cleaned or f"{chain_type}|{primary_security_id or 0}"

    @staticmethod
    def _topic_title(title: str, chain_type: str, normalized: dict[str, Any]) -> str:
        issuer = str((normalized.get("issuer") or {}).get("name") or "").strip()
        counterparties = [str(item.get("name") or "").strip() for item in normalized.get("counterparties") or [] if item.get("name")]
        proposal_types = [str(item) for item in normalized.get("proposal_summary", {}).get("proposal_types") or [] if item]
        if counterparties:
            return " / ".join(part for part in [issuer, counterparties[0], chain_type] if part)
        if proposal_types:
            return " / ".join(part for part in [issuer, proposal_types[0], chain_type] if part)
        return " / ".join(part for part in [issuer, EventChainService._display_title(title), chain_type] if part)

    @staticmethod
    def _chain_summary(summary: str | None, normalized: dict[str, Any], timeline_stage: str) -> str:
        issuer = str((normalized.get("issuer") or {}).get("name") or "").strip()
        amount = (normalized.get("amount_summary") or {}).get("primary_amount") or {}
        amount_text = str(amount.get("text") or "").strip()
        counterparty = next(
            (str(item.get("name") or "").strip() for item in normalized.get("counterparties") or [] if item.get("name")),
            "",
        )
        parts = [issuer, counterparty, amount_text, EventChainService._stage_label(timeline_stage)]
        compact = " ".join(part for part in parts if part)
        return (summary or compact or "事件链").strip()[:300]

    @staticmethod
    def _chain_facts(
        chain_type: str,
        topic_key: str,
        timeline_stage: str,
        normalized: dict[str, Any],
    ) -> dict[str, Any]:
        return {
            "version": EventChainService.version,
            "chain_type": chain_type,
            "topic_key": topic_key,
            "latest_stage": timeline_stage,
            "issuer": normalized.get("issuer"),
            "counterparties": normalized.get("counterparties") or [],
            "proposal_types": (normalized.get("proposal_summary") or {}).get("proposal_types") or [],
            "risk_level": (normalized.get("risk_summary") or {}).get("risk_level"),
        }

    @staticmethod
    def _timeline_stage(*, title: str, chain_type: str, normalized: dict[str, Any]) -> str:
        title_text = title.strip()
        if chain_type == "bond_issue":
            if any(keyword in title_text for keyword in ["兑付完成", "偿还完成"]):
                return "redemption"
            if any(keyword in title_text for keyword in ["发行结果", "发行完成"]):
                return "issuance_result"
            if any(keyword in title_text for keyword in ["获批", "注册", "同意"]):
                return "approval"
            return "issuance_update"
        if chain_type == "external_investment":
            if any(keyword in title_text for keyword in ["完成", "交割", "备案"]):
                return "completion"
            if any(keyword in title_text for keyword in ["签署", "协议"]):
                return "signing"
            if any(keyword in title_text for keyword in ["董事会", "审议", "议案"]):
                return "board_resolution"
            return "investment_update"
        if chain_type == "buyback":
            if any(keyword in title_text for keyword in ["完成", "实施完毕", "结果公告"]):
                return "completion"
            if any(keyword in title_text for keyword in ["进展", "实施情况"]):
                return "progress"
            if any(keyword in title_text for keyword in ["董事会", "方案", "议案"]):
                return "board_resolution"
            return "buyback_update"
        if chain_type == "investor_relations":
            return "activity_record"
        if chain_type == "board_resolution":
            proposal_types = (normalized.get("proposal_summary") or {}).get("proposal_types") or []
            return str(proposal_types[0]) if proposal_types else "board_resolution"
        if chain_type == "earnings":
            if "快报" in title_text:
                return "earnings_flash"
            if "预告" in title_text:
                return "forecast"
            return "earnings_update"
        return "announcement"

    @staticmethod
    def _chain_status(chain_type: str, timeline_stage: str) -> str:
        if chain_type in {"bond_issue", "buyback", "external_investment"} and timeline_stage in {"completion", "redemption"}:
            return "completed"
        return "active"

    @staticmethod
    def _bond_topic_key(title: str) -> str:
        cleaned = re.sub(r"关于|公告|发行结果|兑付完成|完成|子公司", "", title)
        cleaned = re.sub(r"[（(].*?[)）]", "", cleaned)
        cleaned = re.sub(r"\s+", "", cleaned)
        cleaned = re.sub(r"东方财富信息股份有限公司|东方财富证券股份有限公司", "", cleaned)
        return cleaned[:80] or "bond_issue"

    @staticmethod
    def _clean_topic_tokens(title: str) -> str:
        cleaned = re.sub(r"关于|公告|提示性|进展|情况|股份有限公司|有限责任公司|公司", "", title)
        cleaned = re.sub(r"[（(].*?[)）]", "", cleaned)
        cleaned = re.sub(r"\s+", "", cleaned)
        return cleaned[:80]

    @staticmethod
    def _display_title(title: str) -> str:
        cleaned = re.sub(r"关于|公告", "", title).strip("：: ")
        return cleaned[:80]

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
    def _higher_importance(left: str | None, right: str | None) -> str:
        if left is None:
            return right or "C"
        if right is None:
            return left
        ranking = {"S": 4, "A": 3, "B": 2, "C": 1}
        return left if ranking.get(left, 0) >= ranking.get(right, 0) else right

    def _max_importance_level(self, events: list[MarketEvent]) -> str:
        level = "C"
        for event in events:
            level = self._higher_importance(level, event.importance_level)
        return level

    @staticmethod
    def _sha256(value: str) -> str:
        return hashlib.sha256(value.encode("utf-8")).hexdigest()

    @staticmethod
    def _now() -> datetime:
        return datetime.now(UTC).replace(tzinfo=None)
