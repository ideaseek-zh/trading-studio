from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, date, datetime
from typing import Any

from sqlalchemy import delete, select
from sqlalchemy.orm import Session

from app.models.event_chain import EventChain
from app.models.index_daily_bar import IndexDailyBar
from app.models.market_daily_bar import MarketDailyBar
from app.models.market_event import MarketEvent
from app.models.market_index import MarketIndex
from app.models.signal_performance_snapshot import SignalPerformanceSnapshot
from app.models.signal_rule import SignalRule
from app.models.trading_signal import TradingSignal


@dataclass(slots=True)
class SignalInsightRebuildResult:
    processed: int
    updated: int
    snapshots_created: int
    snapshots_updated: int


class SignalInsightService:
    version = "signal-insight-v1"
    horizons = [1, 3, 5, 10, 20]

    def __init__(self, db: Session) -> None:
        self.db = db

    def rebuild_all(
        self,
        *,
        signal_id: int | None = None,
        security_id: int | None = None,
    ) -> dict[str, int]:
        query = select(TradingSignal).order_by(TradingSignal.id.asc())
        if signal_id is not None:
            query = query.where(TradingSignal.id == signal_id)
        if security_id is not None:
            query = query.where(TradingSignal.primary_security_id == security_id)

        signals = self.db.execute(query).scalars().all()
        updated = 0
        snapshots_created = 0
        snapshots_updated = 0
        for signal in signals:
            result = self.refresh_signal(signal)
            updated += 1 if result["updated"] else 0
            snapshots_created += result["snapshots_created"]
            snapshots_updated += result["snapshots_updated"]

        summary = SignalInsightRebuildResult(
            processed=len(signals),
            updated=updated,
            snapshots_created=snapshots_created,
            snapshots_updated=snapshots_updated,
        )
        return {
            "processed": summary.processed,
            "updated": summary.updated,
            "snapshots_created": summary.snapshots_created,
            "snapshots_updated": summary.snapshots_updated,
        }

    def refresh_signal(self, signal: TradingSignal) -> dict[str, int]:
        chain = self.db.get(EventChain, signal.event_chain_id) if signal.event_chain_id else None
        event = self.db.get(MarketEvent, signal.latest_event_id) if signal.latest_event_id else None
        rule = self.db.get(SignalRule, signal.signal_rule_id) if signal.signal_rule_id else None

        signal.explanation = self._build_explanation(signal, chain, event, rule)

        snapshots_created = 0
        snapshots_updated = 0
        snapshot_payloads = self._build_performance_snapshots(signal, chain)
        existing_rows = {
            int(row.horizon_days): row
            for row in self.db.execute(
                select(SignalPerformanceSnapshot).where(SignalPerformanceSnapshot.trading_signal_id == signal.id)
            ).scalars().all()
        }

        seen_horizons: set[int] = set()
        for payload in snapshot_payloads:
            horizon_days = int(payload["horizon_days"])
            seen_horizons.add(horizon_days)
            row = existing_rows.get(horizon_days)
            if row is None:
                row = SignalPerformanceSnapshot(trading_signal_id=signal.id, horizon_days=horizon_days)
                self.db.add(row)
                snapshots_created += 1
            else:
                snapshots_updated += 1

            for key, value in payload.items():
                if key == "trading_signal_id":
                    continue
                setattr(row, key, value)
            row.updated_at = self._now()
            if row.created_at is None:
                row.created_at = row.updated_at

        for horizon_days, row in existing_rows.items():
            if horizon_days not in seen_horizons:
                self.db.delete(row)

        signal.performance_summary = self._performance_summary(signal, snapshot_payloads)
        signal.last_evaluated_at = self._now()
        signal.updated_at = self._now()
        self.db.flush()

        return {
            "updated": 1,
            "snapshots_created": snapshots_created,
            "snapshots_updated": snapshots_updated,
        }

    def _build_explanation(
        self,
        signal: TradingSignal,
        chain: EventChain | None,
        event: MarketEvent | None,
        rule: SignalRule | None,
    ) -> dict[str, Any]:
        reasoning = dict(signal.reasoning or {})
        breakdown = dict(reasoning.get("score_breakdown") or {})
        stage = str(reasoning.get("timeline_stage") or (event.timeline_stage if event else "announcement"))
        risk_level = str(breakdown.get("risk_level") or (signal.facts or {}).get("risk_level") or "none")
        chain_type = str(reasoning.get("chain_type") or (chain.chain_type if chain else "unknown"))

        factor_rows = [
            self._factor_row(
                key="impact",
                label="事件影响力",
                score=float(reasoning.get("impact_score") or 0),
                max_score=100,
                weight=0.38 if signal.signal_type in {"alpha_opportunity", "theme_opportunity"} else 0.30,
                evidence=f"{chain_type} 事件链，重要度 {breakdown.get('importance_level') or '-'}。",
            ),
            self._factor_row(
                key="urgency",
                label="时效与催化",
                score=float(reasoning.get("urgency_score") or 0),
                max_score=100,
                weight=0.24 if signal.signal_type == "alpha_opportunity" else 0.25,
                evidence=f"当前处于 {self._stage_label(stage)} 阶段。",
            ),
            self._factor_row(
                key="confidence",
                label="事实确定性",
                score=float(reasoning.get("confidence_score") or 0),
                max_score=100,
                weight=0.20,
                evidence=f"事件数 {breakdown.get('event_count') or 0}，文章数 {breakdown.get('article_count') or 0}。",
            ),
            self._factor_row(
                key="risk",
                label="风险折减",
                score=100 - float(reasoning.get("risk_score") or 0),
                max_score=100,
                weight=0.18 if signal.signal_type == "alpha_opportunity" else 0.25,
                evidence=f"风险等级 {risk_level}。",
            ),
        ]

        positives = [
            f"事件链主题：{chain.topic}" if chain else None,
            f"阶段：{self._stage_label(stage)}",
            f"方向：{signal.direction}",
            f"评分：{float(signal.signal_score):.2f}",
        ]
        warnings = []
        if risk_level in {"medium", "high"}:
            warnings.append(f"风险等级为 {risk_level}，需要结合仓位与止损策略使用。")
        if signal.signal_type in {"research_watch", "general_watch"}:
            warnings.append("当前信号偏监控研究用途，不宜直接等同为交易指令。")
        if (chain.article_count if chain else 0) <= 1:
            warnings.append("当前证据源数量较少，后续公告或市场反馈可能改变判断。")

        counterparties = (signal.facts or {}).get("counterparties") or []

        return {
            "version": self.version,
            "headline": signal.title,
            "panel_type": "factor_explanation",
            "context": {
                "signal_type": signal.signal_type,
                "direction": signal.direction,
                "chain_type": chain_type,
                "timeline_stage": stage,
                "timeline_stage_label": self._stage_label(stage),
                "horizon_label": signal.horizon_label,
                "importance_level": breakdown.get("importance_level"),
                "risk_level": risk_level,
            },
            "scorecard": {
                "signal_score": float(signal.signal_score),
                "impact_score": float(signal.impact_score),
                "urgency_score": float(signal.urgency_score),
                "confidence_score": float(signal.confidence_score),
                "risk_score": float(signal.risk_score),
            },
            "factor_contributions": factor_rows,
            "evidence": {
                "chain_topic": chain.topic if chain else None,
                "event_title": event.title if event else None,
                "event_count": chain.event_count if chain else None,
                "article_count": chain.article_count if chain else None,
                "counterparties": counterparties[:5],
                "triggered_at": signal.triggered_at.isoformat() if signal.triggered_at else None,
            },
            "bull_points": [item for item in positives if item],
            "risk_points": warnings,
        }

    def _build_performance_snapshots(
        self,
        signal: TradingSignal,
        chain: EventChain | None,
    ) -> list[dict[str, Any]]:
        if signal.primary_security_id is None or signal.triggered_at is None:
            return [self._empty_snapshot(signal.id, horizon_days, "no_security") for horizon_days in self.horizons]

        security_bars = self._security_bars(signal.primary_security_id, signal.triggered_at.date())
        if not security_bars:
            return [self._empty_snapshot(signal.id, horizon_days, "no_market_data") for horizon_days in self.horizons]

        benchmark_code = self._benchmark_code(signal)
        benchmark_bars = self._benchmark_bars(benchmark_code, signal.triggered_at.date()) if benchmark_code else []
        entry_index = 0
        entry_bar = security_bars[entry_index]
        benchmark_entry_bar = benchmark_bars[0] if benchmark_bars else None

        payloads: list[dict[str, Any]] = []
        for horizon_days in self.horizons:
            target_index = horizon_days
            available_exit_index = min(target_index, len(security_bars) - 1)
            exit_bar = security_bars[available_exit_index]
            status = "evaluated" if available_exit_index == target_index else "pending"
            coverage_pct = round(min((available_exit_index / max(horizon_days, 1)) * 100, 100), 2)
            return_pct = self._return_pct(entry_bar["close"], exit_bar["close"], signal.direction)
            max_upside_pct, max_drawdown_pct = self._window_extremes(
                entry_price=entry_bar["close"],
                window=security_bars[entry_index : available_exit_index + 1],
                direction=signal.direction,
            )

            benchmark_return_pct = None
            alpha_return_pct = None
            benchmark_exit_bar = None
            if benchmark_entry_bar is not None and benchmark_bars:
                benchmark_exit_index = min(available_exit_index, len(benchmark_bars) - 1)
                benchmark_exit_bar = benchmark_bars[benchmark_exit_index]
                benchmark_return_pct = self._return_pct(
                    benchmark_entry_bar["close"],
                    benchmark_exit_bar["close"],
                    "positive",
                )
                if return_pct is not None and benchmark_return_pct is not None:
                    alpha_return_pct = round(return_pct - benchmark_return_pct, 4)

            payloads.append(
                {
                    "trading_signal_id": signal.id,
                    "horizon_days": horizon_days,
                    "evaluation_status": status,
                    "benchmark_code": benchmark_code,
                    "entry_trade_date": entry_bar["trade_date"],
                    "exit_trade_date": exit_bar["trade_date"],
                    "holding_days": available_exit_index,
                    "entry_price": round(entry_bar["close"], 4),
                    "exit_price": round(exit_bar["close"], 4),
                    "return_pct": return_pct,
                    "benchmark_return_pct": benchmark_return_pct,
                    "alpha_return_pct": alpha_return_pct,
                    "max_upside_pct": max_upside_pct,
                    "max_drawdown_pct": max_drawdown_pct,
                    "win_probability": self._win_probability(return_pct, signal.direction),
                    "coverage_pct": coverage_pct,
                    "evaluated_at": self._now(),
                    "metrics": {
                        "version": self.version,
                        "signal_direction": signal.direction,
                        "chain_type": chain.chain_type if chain else None,
                        "target_horizon_days": horizon_days,
                        "available_bars": len(security_bars),
                        "benchmark_available_bars": len(benchmark_bars),
                        "benchmark_exit_trade_date": benchmark_exit_bar["trade_date"].isoformat() if benchmark_exit_bar else None,
                    },
                }
            )

        return payloads

    def _performance_summary(self, signal: TradingSignal, payloads: list[dict[str, Any]]) -> dict[str, Any]:
        evaluated = [item for item in payloads if item["evaluation_status"] == "evaluated" and item["return_pct"] is not None]
        pending = [item for item in payloads if item["evaluation_status"] != "evaluated"]
        best_snapshot = max(evaluated, key=lambda item: float(item["alpha_return_pct"] or item["return_pct"] or -999), default=None)
        latest_snapshot = max(payloads, key=lambda item: int(item["horizon_days"]), default=None)

        return {
            "version": self.version,
            "evaluation_status": "evaluated" if evaluated else "pending",
            "evaluated_horizons": [int(item["horizon_days"]) for item in evaluated],
            "pending_horizons": [int(item["horizon_days"]) for item in pending],
            "best_horizon_days": int(best_snapshot["horizon_days"]) if best_snapshot else None,
            "best_return_pct": best_snapshot["return_pct"] if best_snapshot else None,
            "best_alpha_return_pct": best_snapshot["alpha_return_pct"] if best_snapshot else None,
            "latest_horizon_days": int(latest_snapshot["horizon_days"]) if latest_snapshot else None,
            "latest_return_pct": latest_snapshot["return_pct"] if latest_snapshot else None,
            "latest_alpha_return_pct": latest_snapshot["alpha_return_pct"] if latest_snapshot else None,
            "win_horizons": sum(
                1 for item in evaluated if self._win_probability(item["return_pct"], signal.direction) == 100
            ),
            "total_horizons": len(payloads),
        }

    @staticmethod
    def _factor_row(*, key: str, label: str, score: float, max_score: float, weight: float, evidence: str) -> dict[str, Any]:
        contribution = round(score * weight, 2)
        strength = "high" if score >= 80 else "medium" if score >= 60 else "low"
        return {
            "key": key,
            "label": label,
            "score": round(score, 2),
            "max_score": round(max_score, 2),
            "weight": round(weight, 4),
            "contribution": contribution,
            "strength": strength,
            "evidence": evidence,
        }

    def _empty_snapshot(self, signal_id: int, horizon_days: int, status: str) -> dict[str, Any]:
        return {
            "trading_signal_id": signal_id,
            "horizon_days": horizon_days,
            "evaluation_status": status,
            "benchmark_code": None,
            "entry_trade_date": None,
            "exit_trade_date": None,
            "holding_days": None,
            "entry_price": None,
            "exit_price": None,
            "return_pct": None,
            "benchmark_return_pct": None,
            "alpha_return_pct": None,
            "max_upside_pct": None,
            "max_drawdown_pct": None,
            "win_probability": None,
            "coverage_pct": 0,
            "evaluated_at": self._now(),
            "metrics": {
                "version": self.version,
                "reason": status,
            },
        }

    def _security_bars(self, security_id: int, triggered_date: date) -> list[dict[str, Any]]:
        rows = self.db.execute(
            select(MarketDailyBar)
            .where(
                MarketDailyBar.security_id == security_id,
                MarketDailyBar.adjust_type == "none",
                MarketDailyBar.trade_date >= triggered_date,
            )
            .order_by(MarketDailyBar.trade_date.asc())
        ).scalars().all()
        return [
            {
                "trade_date": row.trade_date,
                "close": float(row.close) if row.close is not None else None,
            }
            for row in rows
            if row.close is not None
        ]

    def _benchmark_bars(self, benchmark_code: str, triggered_date: date) -> list[dict[str, Any]]:
        benchmark = self.db.scalar(select(MarketIndex).where(MarketIndex.code == benchmark_code))
        if benchmark is None:
            return []
        rows = self.db.execute(
            select(IndexDailyBar)
            .where(
                IndexDailyBar.market_index_id == benchmark.id,
                IndexDailyBar.trade_date >= triggered_date,
            )
            .order_by(IndexDailyBar.trade_date.asc())
        ).scalars().all()
        return [
            {
                "trade_date": row.trade_date,
                "close": float(row.close) if row.close is not None else None,
            }
            for row in rows
            if row.close is not None
        ]

    def _benchmark_code(self, signal: TradingSignal) -> str | None:
        facts = dict(signal.facts or {})
        symbol = str(((facts.get("issuer") or {}).get("symbol")) or "").strip()
        if symbol.startswith("6"):
            return "sh000001"
        if symbol.startswith(("0", "3")):
            return "sz399001"
        return "sh000001"

    @staticmethod
    def _return_pct(entry_price: float | None, exit_price: float | None, direction: str) -> float | None:
        if not entry_price or not exit_price:
            return None
        raw = ((exit_price / entry_price) - 1) * 100
        if direction == "negative":
            raw = -raw
        return round(raw, 4)

    @staticmethod
    def _window_extremes(*, entry_price: float, window: list[dict[str, Any]], direction: str) -> tuple[float | None, float | None]:
        if not window or not entry_price:
            return None, None
        closes = [float(item["close"]) for item in window if item.get("close") is not None]
        if not closes:
            return None, None
        max_close = max(closes)
        min_close = min(closes)
        if direction == "negative":
            max_upside = ((entry_price / min_close) - 1) * 100 if min_close else None
            max_drawdown = ((entry_price / max_close) - 1) * 100 if max_close else None
        else:
            max_upside = ((max_close / entry_price) - 1) * 100
            max_drawdown = ((min_close / entry_price) - 1) * 100
        return round(max_upside, 4) if max_upside is not None else None, round(max_drawdown, 4) if max_drawdown is not None else None

    @staticmethod
    def _win_probability(return_pct: float | None, direction: str) -> float | None:
        if return_pct is None:
            return None
        if direction == "neutral":
            return 100.0 if abs(return_pct) <= 2 else 0.0
        return 100.0 if return_pct > 0 else 0.0

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
    def _now() -> datetime:
        return datetime.now(UTC).replace(tzinfo=None)
