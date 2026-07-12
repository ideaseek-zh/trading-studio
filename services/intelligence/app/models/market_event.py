from sqlalchemy import DateTime, ForeignKey, JSON, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class MarketEvent(Base):
    __tablename__ = "events"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    event_type: Mapped[str] = mapped_column(String(64), nullable=False)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    summary: Mapped[str | None] = mapped_column(Text, nullable=True)
    occurred_at: Mapped[DateTime] = mapped_column(DateTime, nullable=False)
    detected_at: Mapped[DateTime] = mapped_column(DateTime, nullable=False)
    importance_level: Mapped[str] = mapped_column(String(16), nullable=False)
    sentiment: Mapped[str | None] = mapped_column(String(16), nullable=True)
    confidence: Mapped[float | None] = mapped_column(Numeric(5, 4), nullable=True)
    status: Mapped[str] = mapped_column(String(32), default="detected", nullable=False)
    primary_security_id: Mapped[int | None] = mapped_column(ForeignKey("securities.id"), nullable=True)
    event_chain_id: Mapped[int | None] = mapped_column(ForeignKey("event_chains.id"), nullable=True)
    timeline_stage: Mapped[str | None] = mapped_column(String(32), nullable=True)
    timeline_order: Mapped[int | None] = mapped_column(nullable=True)
    fingerprint: Mapped[str] = mapped_column(String(64), unique=True, nullable=False)
    facts: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    ai_analysis: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    published_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
