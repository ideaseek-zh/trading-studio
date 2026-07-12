from sqlalchemy import JSON, Date, DateTime, ForeignKey, Numeric, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class IndexDailyBar(Base):
    __tablename__ = "index_daily_bars"
    __table_args__ = (UniqueConstraint("market_index_id", "trade_date", name="uk_index_trade_date"),)

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    market_index_id: Mapped[int] = mapped_column(ForeignKey("market_indices.id"), nullable=False)
    trade_date: Mapped[Date] = mapped_column(Date, nullable=False)
    open: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    high: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    low: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    close: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    pre_close: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    volume: Mapped[float | None] = mapped_column(Numeric(24, 4), nullable=True)
    amount: Mapped[float | None] = mapped_column(Numeric(24, 4), nullable=True)
    pct_change: Mapped[float | None] = mapped_column(Numeric(12, 6), nullable=True)
    provider: Mapped[str] = mapped_column(String(32), nullable=False)
    source_timestamp: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    metadata_json: Mapped[dict | None] = mapped_column("metadata", JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
