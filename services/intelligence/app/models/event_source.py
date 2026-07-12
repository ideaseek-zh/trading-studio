from sqlalchemy import DateTime, ForeignKey, String
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class EventSource(Base):
    __tablename__ = "event_sources"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    event_id: Mapped[int] = mapped_column(ForeignKey("events.id"), nullable=False)
    news_article_id: Mapped[int] = mapped_column(ForeignKey("news_articles.id"), nullable=False)
    relation_type: Mapped[str] = mapped_column(String(32), default="primary", nullable=False)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
