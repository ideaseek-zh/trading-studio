from sqlalchemy import DateTime, ForeignKey, JSON, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class NewsArticleContent(Base):
    __tablename__ = "news_article_contents"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    news_article_id: Mapped[int] = mapped_column(ForeignKey("news_articles.id"), unique=True, nullable=False)
    content_text: Mapped[str] = mapped_column(Text, nullable=False)
    content_html: Mapped[str | None] = mapped_column(Text, nullable=True)
    attachments: Mapped[list | None] = mapped_column(JSON, nullable=True)
    images: Mapped[list | None] = mapped_column(JSON, nullable=True)
    tags: Mapped[list | None] = mapped_column(JSON, nullable=True)
    raw_payload: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    quality_issues: Mapped[list | None] = mapped_column(JSON, nullable=True)
    structured_data: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    extraction_version: Mapped[str | None] = mapped_column(String(32), nullable=True)
    extracted_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    cleaned_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
