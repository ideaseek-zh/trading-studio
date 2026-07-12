from sqlalchemy import DateTime, ForeignKey, JSON, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class TradingSignal(Base):
    __tablename__ = "trading_signals"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    signal_key: Mapped[str] = mapped_column(String(64), unique=True, nullable=False)
    signal_rule_id: Mapped[int | None] = mapped_column(ForeignKey("signal_rules.id"), nullable=True)
    event_chain_id: Mapped[int | None] = mapped_column(ForeignKey("event_chains.id"), nullable=True)
    latest_event_id: Mapped[int | None] = mapped_column(ForeignKey("events.id"), nullable=True)
    primary_security_id: Mapped[int | None] = mapped_column(ForeignKey("securities.id"), nullable=True)
    signal_type: Mapped[str] = mapped_column(String(64), nullable=False)
    direction: Mapped[str] = mapped_column(String(16), nullable=False)
    horizon_label: Mapped[str] = mapped_column(String(32), nullable=False)
    status: Mapped[str] = mapped_column(String(32), nullable=False, default="active")
    title: Mapped[str] = mapped_column(String(255), nullable=False)
    summary: Mapped[str | None] = mapped_column(Text, nullable=True)
    signal_score: Mapped[float] = mapped_column(Numeric(5, 2), nullable=False)
    confidence_score: Mapped[float] = mapped_column(Numeric(5, 2), nullable=False)
    urgency_score: Mapped[float] = mapped_column(Numeric(5, 2), nullable=False)
    impact_score: Mapped[float] = mapped_column(Numeric(5, 2), nullable=False)
    risk_score: Mapped[float] = mapped_column(Numeric(5, 2), nullable=False)
    triggered_at: Mapped[DateTime] = mapped_column(DateTime, nullable=False)
    published_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    expires_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    reasoning: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    explanation: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    performance_summary: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    last_evaluated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    facts: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
