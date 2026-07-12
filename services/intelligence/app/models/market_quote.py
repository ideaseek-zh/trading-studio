from sqlalchemy import JSON, DateTime, ForeignKey, Numeric, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class MarketQuote(Base):
    __tablename__ = "market_quotes"
    __table_args__ = (UniqueConstraint("security_id", "quote_time", name="uk_security_quote_time"),)

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    security_id: Mapped[int] = mapped_column(ForeignKey("securities.id"), nullable=False)
    quote_time: Mapped[DateTime] = mapped_column(DateTime, nullable=False)
    last_price: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    pre_close: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    open: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    high: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    low: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    volume: Mapped[float | None] = mapped_column(Numeric(24, 4), nullable=True)
    amount: Mapped[float | None] = mapped_column(Numeric(24, 4), nullable=True)
    turnover_rate: Mapped[float | None] = mapped_column(Numeric(12, 6), nullable=True)
    pct_change: Mapped[float | None] = mapped_column(Numeric(12, 6), nullable=True)
    provider: Mapped[str] = mapped_column(String(32), nullable=False)
    source_timestamp: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    metadata_json: Mapped[dict | None] = mapped_column("metadata", JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
