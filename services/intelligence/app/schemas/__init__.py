"""Pydantic schemas."""
from app.schemas.news_sync import (
    NewsSourceSyncRequest,
    NewsSourceSyncResponse,
    NewsSyncRequest,
    NewsSyncResponse,
)

__all__ = [
    "NewsSourceSyncRequest",
    "NewsSourceSyncResponse",
    "NewsSyncRequest",
    "NewsSyncResponse",
]
