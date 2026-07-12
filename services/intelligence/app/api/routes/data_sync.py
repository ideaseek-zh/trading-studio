from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session

from app.api.routes.health import require_internal_token
from app.core.database import get_db
from app.schemas.market_sync import (
    DailyBarSyncRequest,
    DailyBarSyncResponse,
    IndexDailyBarSyncRequest,
    IndexDailyBarSyncResponse,
    IndexSyncRequest,
    IndexSyncResponse,
    QuoteSyncRequest,
    QuoteSyncResponse,
)
from app.schemas.news_sync import (
    EventChainRebuildRequest,
    EventChainRebuildResponse,
    NewsSourceSyncRequest,
    NewsSourceSyncResponse,
    NewsSyncRequest,
    NewsSyncResponse,
    SignalInsightRebuildRequest,
    SignalInsightRebuildResponse,
    SignalRebuildRequest,
    SignalRebuildResponse,
)
from app.schemas.security_sync import SecuritySyncRequest, SecuritySyncResponse
from app.services.event_chain import EventChainService
from app.services.market_sync import MarketSyncService
from app.services.news_sync import NewsSyncService
from app.services.security_sync import SecuritySyncService
from app.services.signal_engine import SignalEngineService
from app.services.signal_insight import SignalInsightService

router = APIRouter(dependencies=[Depends(require_internal_token)])


@router.post("/data/sync/securities", response_model=SecuritySyncResponse)
def sync_securities(payload: SecuritySyncRequest, db: Session = Depends(get_db)) -> SecuritySyncResponse:
    service = SecuritySyncService(db)
    result = service.sync(payload.provider)
    return SecuritySyncResponse(**result)


@router.post("/data/sync/news-sources", response_model=NewsSourceSyncResponse)
def sync_news_sources(
    payload: NewsSourceSyncRequest,
    db: Session = Depends(get_db),
) -> NewsSourceSyncResponse:
    service = NewsSyncService(db)
    result = service.seed_sources(payload.provider)
    return NewsSourceSyncResponse(**result)


@router.post("/data/sync/news", response_model=NewsSyncResponse)
def sync_news(payload: NewsSyncRequest, db: Session = Depends(get_db)) -> NewsSyncResponse:
    service = NewsSyncService(db)
    result = service.sync_news(
        payload.provider,
        payload.scopes,
        payload.symbols,
        payload.start_date or service.default_start_date(),
        payload.end_date or service.default_end_date(),
        payload.limit_per_source,
    )
    return NewsSyncResponse(**result)


@router.post("/data/sync/event-chains/rebuild", response_model=EventChainRebuildResponse)
def rebuild_event_chains(
    payload: EventChainRebuildRequest,
    db: Session = Depends(get_db),
) -> EventChainRebuildResponse:
    service = EventChainService(db)
    result = service.rebuild_all(security_id=payload.security_id)
    db.commit()
    return EventChainRebuildResponse(**result)


@router.post("/data/sync/signals/rebuild", response_model=SignalRebuildResponse)
def rebuild_signals(
    payload: SignalRebuildRequest,
    db: Session = Depends(get_db),
) -> SignalRebuildResponse:
    service = SignalEngineService(db)
    result = service.rebuild_all(
        security_id=payload.security_id,
        event_chain_id=payload.event_chain_id,
    )
    db.commit()
    return SignalRebuildResponse(**result)


@router.post("/data/sync/signals/insights", response_model=SignalInsightRebuildResponse)
def rebuild_signal_insights(
    payload: SignalInsightRebuildRequest,
    db: Session = Depends(get_db),
) -> SignalInsightRebuildResponse:
    service = SignalInsightService(db)
    result = service.rebuild_all(
        signal_id=payload.signal_id,
        security_id=payload.security_id,
    )
    db.commit()
    return SignalInsightRebuildResponse(**result)


@router.post("/data/sync/quotes", response_model=QuoteSyncResponse)
def sync_quotes(payload: QuoteSyncRequest, db: Session = Depends(get_db)) -> QuoteSyncResponse:
    service = MarketSyncService(db)
    result = service.sync_quotes(payload.provider, payload.symbols)
    return QuoteSyncResponse(**result)


@router.post("/data/sync/daily-bars", response_model=DailyBarSyncResponse)
def sync_daily_bars(payload: DailyBarSyncRequest, db: Session = Depends(get_db)) -> DailyBarSyncResponse:
    service = MarketSyncService(db)
    result = service.sync_daily_bars(
        payload.provider,
        payload.symbol,
        payload.start_date or service.default_start_date(),
        payload.end_date or service.default_end_date(),
        payload.adjust,
    )
    return DailyBarSyncResponse(**result)


@router.post("/data/sync/indices", response_model=IndexSyncResponse)
def sync_indices(payload: IndexSyncRequest, db: Session = Depends(get_db)) -> IndexSyncResponse:
    service = MarketSyncService(db)
    result = service.sync_indices(payload.provider, payload.codes)
    return IndexSyncResponse(**result)


@router.post("/data/sync/index-daily-bars", response_model=IndexDailyBarSyncResponse)
def sync_index_daily_bars(
    payload: IndexDailyBarSyncRequest,
    db: Session = Depends(get_db),
) -> IndexDailyBarSyncResponse:
    service = MarketSyncService(db)
    result = service.sync_index_daily_bars(
        payload.provider,
        payload.code,
        payload.start_date or service.default_start_date(),
        payload.end_date or service.default_end_date(),
    )
    return IndexDailyBarSyncResponse(**result)
