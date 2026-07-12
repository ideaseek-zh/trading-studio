from sqlalchemy import Boolean, DateTime, ForeignKey, Numeric, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class NewsArticleSecurity(Base):
    __tablename__ = "news_article_securities"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    news_article_id: Mapped[int] = mapped_column(ForeignKey("news_articles.id"), nullable=False)
    security_id: Mapped[int] = mapped_column(ForeignKey("securities.id"), nullable=False)
    mention_type: Mapped[str] = mapped_column(String(32), default="mentioned", nullable=False)
    matched_text: Mapped[str | None] = mapped_column(String(128), nullable=True)
    confidence: Mapped[float | None] = mapped_column(Numeric(5, 4), nullable=True)
    is_primary: Mapped[bool] = mapped_column(Boolean, default=False, nullable=False)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
