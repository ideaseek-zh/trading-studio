from pydantic import BaseModel, Field


class SecuritySyncRequest(BaseModel):
    provider: str = Field(default="akshare", min_length=1)


class SecuritySyncResponse(BaseModel):
    provider: str
    inserted_or_updated: int
    total_received: int
