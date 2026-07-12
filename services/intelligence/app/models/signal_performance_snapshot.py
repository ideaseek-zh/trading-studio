from sqlalchemy import JSON, Date, DateTime, ForeignKey, Numeric, String, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class SignalPerformanceSnapshot(Base):
    __tablename__ = "signal_performance_snapshots"
    __table_args__ = (
        UniqueConstraint("trading_signal_id", "horizon_days", name="uniq_signal_performance_signal_horizon"),
    )

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    trading_signal_id: Mapped[int] = mapped_column(ForeignKey("trading_signals.id"), nullable=False)
    horizon_days: Mapped[int] = mapped_column(nullable=False)
    evaluation_status: Mapped[str] = mapped_column(String(32), nullable=False, default="pending")
    benchmark_code: Mapped[str | None] = mapped_column(String(32), nullable=True)
    entry_trade_date: Mapped[Date | None] = mapped_column(Date, nullable=True)
    exit_trade_date: Mapped[Date | None] = mapped_column(Date, nullable=True)
    holding_days: Mapped[int | None] = mapped_column(nullable=True)
    entry_price: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    exit_price: Mapped[float | None] = mapped_column(Numeric(18, 4), nullable=True)
    return_pct: Mapped[float | None] = mapped_column(Numeric(9, 4), nullable=True)
    benchmark_return_pct: Mapped[float | None] = mapped_column(Numeric(9, 4), nullable=True)
    alpha_return_pct: Mapped[float | None] = mapped_column(Numeric(9, 4), nullable=True)
    max_upside_pct: Mapped[float | None] = mapped_column(Numeric(9, 4), nullable=True)
    max_drawdown_pct: Mapped[float | None] = mapped_column(Numeric(9, 4), nullable=True)
    win_probability: Mapped[float | None] = mapped_column(Numeric(5, 2), nullable=True)
    coverage_pct: Mapped[float | None] = mapped_column(Numeric(5, 2), nullable=True)
    evaluated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    metrics: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
