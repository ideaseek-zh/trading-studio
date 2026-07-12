from fastapi import APIRouter, Depends, Header, HTTPException, status
from sqlalchemy import text
from sqlalchemy.orm import Session

from app.core.config import settings
from app.core.database import get_db
from app.providers.market import get_market_provider
from app.providers.news import get_news_provider

router = APIRouter()


def require_internal_token(x_service_token: str = Header(default="")) -> None:
    if x_service_token != settings.internal_service_token:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid service token")


@router.get("/health")
def health(db: Session = Depends(get_db)) -> dict:
    db.execute(text("SELECT 1"))
    return {
        "status": "ok",
        "app": settings.app_name,
        "environment": settings.app_env,
        "provider": settings.market_provider,
    }


@router.get("/health/providers", dependencies=[Depends(require_internal_token)])
def provider_health() -> dict:
    market_provider = get_market_provider(settings.market_provider)
    news_provider = get_news_provider(settings.news_provider)
    market_health = market_provider.health_check()
    news_health = news_provider.health_check()
    return {
        "status": "ok",
        "providers": [
            {
                "provider": market_health.provider,
                "status": market_health.status,
                "detail": market_health.detail,
                "domain": "market",
            },
            {
                "provider": news_health.provider,
                "status": news_health.status,
                "detail": news_health.detail,
                "domain": "news",
            },
        ],
    }
