from pydantic import BaseModel, Field


class NewsSourceSyncRequest(BaseModel):
    provider: str = "akshare"


class NewsSourceSyncResponse(BaseModel):
    provider: str
    processed: int


class NewsSyncRequest(BaseModel):
    provider: str = "akshare"
    scopes: list[str] = Field(default_factory=lambda: ["global", "stock", "notice"])
    symbols: list[str] = Field(default_factory=list)
    start_date: str | None = None
    end_date: str | None = None
    limit_per_source: int = 30


class NewsSyncResponse(BaseModel):
    provider: str
    fetched: int
    processed: int
    inserted: int
    updated: int
    clustered: int
    quality_failed: int
    events_created: int
    events_updated: int


class EventChainRebuildRequest(BaseModel):
    security_id: int | None = None


class EventChainRebuildResponse(BaseModel):
    processed: int
    chains_created: int
    chains_updated: int


class SignalRebuildRequest(BaseModel):
    security_id: int | None = None
    event_chain_id: int | None = None


class SignalRebuildResponse(BaseModel):
    processed: int
    rules_seeded: int
    signals_created: int
    signals_updated: int
    signals_suppressed: int


class SignalInsightRebuildRequest(BaseModel):
    signal_id: int | None = None
    security_id: int | None = None


class SignalInsightRebuildResponse(BaseModel):
    processed: int
    updated: int
    snapshots_created: int
    snapshots_updated: int
