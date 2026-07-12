from sqlalchemy import JSON, DateTime, Numeric, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class MarketIndex(Base):
    __tablename__ = "market_indices"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    code: Mapped[str] = mapped_column(String(32), unique=True, nullable=False)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    exchange: Mapped[str | None] = mapped_column(String(16), nullable=True)
    market: Mapped[str] = mapped_column(String(16), default="CN", nullable=False)
    index_type: Mapped[str] = mapped_column(String(32), default="broad", nullable=False)
    status: Mapped[str] = mapped_column(String(32), default="active", nullable=False)
    quote_time: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    last_price: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    change_amount: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    pct_change: Mapped[float | None] = mapped_column(Numeric(12, 6), nullable=True)
    open: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    high: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    low: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    pre_close: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    volume: Mapped[float | None] = mapped_column(Numeric(24, 4), nullable=True)
    amount: Mapped[float | None] = mapped_column(Numeric(24, 4), nullable=True)
    source_timestamp: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    metadata_json: Mapped[dict | None] = mapped_column("metadata", JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
