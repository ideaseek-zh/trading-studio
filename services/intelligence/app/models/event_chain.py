from sqlalchemy import DateTime, ForeignKey, JSON, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class EventChain(Base):
    __tablename__ = "event_chains"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    chain_key: Mapped[str] = mapped_column(String(64), unique=True, nullable=False)
    chain_type: Mapped[str] = mapped_column(String(64), nullable=False)
    topic: Mapped[str] = mapped_column(String(255), nullable=False)
    summary: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[str] = mapped_column(String(32), default="active", nullable=False)
    primary_security_id: Mapped[int | None] = mapped_column(ForeignKey("securities.id"), nullable=True)
    started_at: Mapped[DateTime] = mapped_column(DateTime, nullable=False)
    latest_occurred_at: Mapped[DateTime] = mapped_column(DateTime, nullable=False)
    latest_published_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    importance_level: Mapped[str] = mapped_column(String(16), nullable=False)
    sentiment: Mapped[str | None] = mapped_column(String(16), nullable=True)
    event_count: Mapped[int] = mapped_column(default=0, nullable=False)
    article_count: Mapped[int] = mapped_column(default=0, nullable=False)
    facts: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
