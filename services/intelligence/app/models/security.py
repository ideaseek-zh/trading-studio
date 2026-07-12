from sqlalchemy import JSON, Date, DateTime, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class Security(Base):
    __tablename__ = "securities"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    canonical_symbol: Mapped[str] = mapped_column(String(32), unique=True, nullable=False)
    symbol: Mapped[str] = mapped_column(String(16), nullable=False)
    exchange: Mapped[str] = mapped_column(String(16), nullable=False)
    market: Mapped[str] = mapped_column(String(16), default="CN", nullable=False)
    security_type: Mapped[str] = mapped_column(String(32), default="stock", nullable=False)
    name: Mapped[str] = mapped_column(String(128), nullable=False)
    short_name: Mapped[str | None] = mapped_column(String(64), nullable=True)
    pinyin: Mapped[str | None] = mapped_column(String(128), nullable=True)
    list_date: Mapped[Date | None] = mapped_column(Date, nullable=True)
    delist_date: Mapped[Date | None] = mapped_column(Date, nullable=True)
    status: Mapped[str] = mapped_column(String(32), default="active", nullable=False)
    currency: Mapped[str] = mapped_column(String(8), default="CNY", nullable=False)
    metadata_json: Mapped[dict | None] = mapped_column("metadata", JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
