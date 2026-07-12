from sqlalchemy import BigInteger, DateTime, ForeignKey, Integer, JSON, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.database import Base


class NewsArticle(Base):
    __tablename__ = "news_articles"

    id: Mapped[int] = mapped_column(primary_key=True, autoincrement=True)
    source_id: Mapped[int] = mapped_column(ForeignKey("news_sources.id"), nullable=False)
    source_item_id: Mapped[str] = mapped_column(String(128), nullable=False)
    title: Mapped[str] = mapped_column(String(512), nullable=False)
    summary: Mapped[str | None] = mapped_column(Text, nullable=True)
    canonical_url: Mapped[str | None] = mapped_column(String(1024), nullable=True)
    author: Mapped[str | None] = mapped_column(String(128), nullable=True)
    published_at: Mapped[DateTime] = mapped_column(DateTime, nullable=False)
    fetched_at: Mapped[DateTime] = mapped_column(DateTime, nullable=False)
    source_timestamp: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    category: Mapped[str | None] = mapped_column(String(64), nullable=True)
    importance_level: Mapped[str] = mapped_column(String(16), default="C", nullable=False)
    sentiment: Mapped[str | None] = mapped_column(String(16), nullable=True)
    status: Mapped[str] = mapped_column(String(32), default="published", nullable=False)
    language: Mapped[str] = mapped_column(String(16), default="zh-CN", nullable=False)
    copyright_status: Mapped[str] = mapped_column(String(32), default="restricted", nullable=False)
    quality_status: Mapped[str] = mapped_column(String(32), default="pending", nullable=False)
    quality_score: Mapped[int | None] = mapped_column(Integer, nullable=True)
    parser_version: Mapped[str | None] = mapped_column(String(32), nullable=True)
    request_id: Mapped[str | None] = mapped_column(String(64), nullable=True)
    checksum: Mapped[str | None] = mapped_column(String(64), nullable=True)
    title_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    content_hash: Mapped[str | None] = mapped_column(String(64), nullable=True)
    simhash: Mapped[int | None] = mapped_column(BigInteger, nullable=True)
    cluster_id: Mapped[int | None] = mapped_column(BigInteger, nullable=True)
    ai_processed_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    metadata_json: Mapped[dict | None] = mapped_column("metadata", JSON, nullable=True)
    created_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
    updated_at: Mapped[DateTime | None] = mapped_column(DateTime, nullable=True)
