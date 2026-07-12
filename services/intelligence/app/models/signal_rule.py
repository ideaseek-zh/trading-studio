from sqlalchemy import JSON, Boolean, DateTime, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class SignalRule(Base):
    __tablename__ = "signal_rules"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    rule_key: Mapped[str] = mapped_column(String(64), unique=True, nullable=False)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    description: Mapped[str | None] = mapped_column(Text, nullable=True)
    scope_type: Mapped[str] = mapped_column(String(32), nullable=False, default="event_chain")
    chain_type: Mapped[str | None] = mapped_column(String(64), nullable=True)
    signal_type: Mapped[str] = mapped_column(String(64), nullable=False)
    default_direction: Mapped[str] = mapped_column(String(16), nullable=False)
    horizon_label: Mapped[str] = mapped_column(String(32), nullable=False)
    horizon_days: Mapped[int] = mapped_column(nullable=False, default=5)
    min_signal_score: Mapped[float] = mapped_column(Numeric(5, 2), nullable=False, default=60)
    enabled: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    weight_profile: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    trigger_conditions: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
