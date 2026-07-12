from pydantic import BaseModel, Field


class QuoteSyncRequest(BaseModel):
    provider: str = Field(default="akshare", min_length=1)
    symbols: list[str] = Field(min_length=1)


class QuoteSyncResponse(BaseModel):
    provider: str
    processed: int


class DailyBarSyncRequest(BaseModel):
    provider: str = Field(default="akshare", min_length=1)
    symbol: str = Field(min_length=1)
    start_date: str | None = None
    end_date: str | None = None
    adjust: str = Field(default="none")


class DailyBarSyncResponse(BaseModel):
    provider: str
    symbol: str
    processed: int


class IndexSyncRequest(BaseModel):
    provider: str = Field(default="akshare", min_length=1)
    codes: list[str] = Field(default_factory=list)


class IndexSyncResponse(BaseModel):
    provider: str
    processed: int


class IndexDailyBarSyncRequest(BaseModel):
    provider: str = Field(default="akshare", min_length=1)
    code: str = Field(min_length=1)
    start_date: str | None = None
    end_date: str | None = None


class IndexDailyBarSyncResponse(BaseModel):
    provider: str
    code: str
    processed: int
