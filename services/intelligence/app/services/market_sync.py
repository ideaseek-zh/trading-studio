from __future__ import annotations

from datetime import UTC, date, datetime, timedelta

from sqlalchemy import select
from sqlalchemy.dialects.mysql import insert
from sqlalchemy.orm import Session

from app.models.index_daily_bar import IndexDailyBar
from app.models.market_daily_bar import MarketDailyBar
from app.models.market_index import MarketIndex
from app.models.market_quote import MarketQuote
from app.models.security import Security
from app.providers.market import get_market_provider


class MarketSyncService:
    def __init__(self, db: Session) -> None:
        self.db = db

    def sync_quotes(self, provider_key: str, symbols: list[str]) -> dict[str, int | str]:
        provider = get_market_provider(provider_key)
        quote_rows = provider.get_realtime_quotes(symbols)
        if not quote_rows:
            return {"provider": provider_key, "processed": 0}

        security_map = self._security_map([row["symbol"] for row in quote_rows])
        now = self._now()
        payload = []
        for row in quote_rows:
            security_id = security_map.get(row["symbol"])
            if security_id is None:
                continue
            payload.append(
                {
                    "security_id": security_id,
                    "quote_time": row["quote_time"],
                    "last_price": row["last_price"],
                    "pre_close": row["pre_close"],
                    "open": row["open"],
                    "high": row["high"],
                    "low": row["low"],
                    "volume": row["volume"],
                    "amount": row["amount"],
                    "turnover_rate": row["turnover_rate"],
                    "pct_change": row["pct_change"],
                    "provider": provider_key,
                    "source_timestamp": row["source_timestamp"],
                    "metadata_json": {"name": row.get("name")},
                    "created_at": now,
                    "updated_at": now,
                }
            )

        if not payload:
            return {"provider": provider_key, "processed": 0}

        statement = insert(MarketQuote).values(payload)
        self.db.execute(
            statement.on_duplicate_key_update(
                last_price=statement.inserted.last_price,
                pre_close=statement.inserted.pre_close,
                open=statement.inserted.open,
                high=statement.inserted.high,
                low=statement.inserted.low,
                volume=statement.inserted.volume,
                amount=statement.inserted.amount,
                turnover_rate=statement.inserted.turnover_rate,
                pct_change=statement.inserted.pct_change,
                provider=statement.inserted.provider,
                source_timestamp=statement.inserted.source_timestamp,
                metadata=statement.inserted["metadata"],
                updated_at=statement.inserted.updated_at,
            )
        )
        self.db.commit()
        return {"provider": provider_key, "processed": len(payload)}

    def sync_daily_bars(
        self,
        provider_key: str,
        symbol: str,
        start_date: str,
        end_date: str,
        adjust: str,
    ) -> dict[str, int | str]:
        provider = get_market_provider(provider_key)
        bars = provider.get_daily_bars(symbol, start_date, end_date, adjust)
        security_id = self._security_map([symbol]).get(symbol)
        if security_id is None or not bars:
            return {"provider": provider_key, "symbol": symbol, "processed": 0}

        now = self._now()
        payload = [
            {
                "security_id": security_id,
                "trade_date": row["trade_date"],
                "open": row["open"],
                "high": row["high"],
                "low": row["low"],
                "close": row["close"],
                "pre_close": row["pre_close"],
                "volume": row["volume"],
                "amount": row["amount"],
                "turnover_rate": row["turnover_rate"],
                "pct_change": row["pct_change"],
                "adjust_type": adjust,
                "provider": provider_key,
                "source_timestamp": now,
                "metadata_json": None,
                "created_at": now,
                "updated_at": now,
            }
            for row in bars
        ]

        statement = insert(MarketDailyBar).values(payload)
        self.db.execute(
            statement.on_duplicate_key_update(
                open=statement.inserted.open,
                high=statement.inserted.high,
                low=statement.inserted.low,
                close=statement.inserted.close,
                pre_close=statement.inserted.pre_close,
                volume=statement.inserted.volume,
                amount=statement.inserted.amount,
                turnover_rate=statement.inserted.turnover_rate,
                pct_change=statement.inserted.pct_change,
                provider=statement.inserted.provider,
                source_timestamp=statement.inserted.source_timestamp,
                metadata=statement.inserted["metadata"],
                updated_at=statement.inserted.updated_at,
            )
        )
        self.db.commit()
        return {"provider": provider_key, "symbol": symbol, "processed": len(payload)}

    def sync_indices(self, provider_key: str, codes: list[str]) -> dict[str, int | str]:
        provider = get_market_provider(provider_key)
        rows = provider.get_index_snapshots(codes or None)
        if not rows:
            return {"provider": provider_key, "processed": 0}

        now = self._now()
        payload = [
            {
                "code": row["code"],
                "name": row["name"],
                "exchange": row["exchange"],
                "market": row["market"],
                "index_type": row["index_type"],
                "status": row["status"],
                "quote_time": row["quote_time"],
                "last_price": row["last_price"],
                "change_amount": row["change_amount"],
                "pct_change": row["pct_change"],
                "open": row["open"],
                "high": row["high"],
                "low": row["low"],
                "pre_close": row["pre_close"],
                "volume": row["volume"],
                "amount": row["amount"],
                "source_timestamp": row["source_timestamp"],
                "metadata_json": None,
                "created_at": now,
                "updated_at": now,
            }
            for row in rows
        ]

        statement = insert(MarketIndex).values(payload)
        self.db.execute(
            statement.on_duplicate_key_update(
                name=statement.inserted.name,
                exchange=statement.inserted.exchange,
                market=statement.inserted.market,
                index_type=statement.inserted.index_type,
                status=statement.inserted.status,
                quote_time=statement.inserted.quote_time,
                last_price=statement.inserted.last_price,
                change_amount=statement.inserted.change_amount,
                pct_change=statement.inserted.pct_change,
                open=statement.inserted.open,
                high=statement.inserted.high,
                low=statement.inserted.low,
                pre_close=statement.inserted.pre_close,
                volume=statement.inserted.volume,
                amount=statement.inserted.amount,
                source_timestamp=statement.inserted.source_timestamp,
                metadata=statement.inserted["metadata"],
                updated_at=statement.inserted.updated_at,
            )
        )
        self.db.commit()
        return {"provider": provider_key, "processed": len(payload)}

    def sync_index_daily_bars(
        self,
        provider_key: str,
        code: str,
        start_date: str,
        end_date: str,
    ) -> dict[str, int | str]:
        provider = get_market_provider(provider_key)
        rows = provider.get_index_daily_bars(code, start_date, end_date)
        market_index = self.db.scalar(select(MarketIndex).where(MarketIndex.code == code))
        if market_index is None or not rows:
            return {"provider": provider_key, "code": code, "processed": 0}

        now = self._now()
        payload = [
            {
                "market_index_id": market_index.id,
                "trade_date": row["trade_date"],
                "open": row["open"],
                "high": row["high"],
                "low": row["low"],
                "close": row["close"],
                "pre_close": row["pre_close"],
                "volume": row["volume"],
                "amount": row["amount"],
                "pct_change": row["pct_change"],
                "provider": provider_key,
                "source_timestamp": now,
                "metadata_json": None,
                "created_at": now,
                "updated_at": now,
            }
            for row in rows
        ]

        statement = insert(IndexDailyBar).values(payload)
        self.db.execute(
            statement.on_duplicate_key_update(
                open=statement.inserted.open,
                high=statement.inserted.high,
                low=statement.inserted.low,
                close=statement.inserted.close,
                pre_close=statement.inserted.pre_close,
                volume=statement.inserted.volume,
                amount=statement.inserted.amount,
                pct_change=statement.inserted.pct_change,
                provider=statement.inserted.provider,
                source_timestamp=statement.inserted.source_timestamp,
                metadata=statement.inserted["metadata"],
                updated_at=statement.inserted.updated_at,
            )
        )
        self.db.commit()
        return {"provider": provider_key, "code": code, "processed": len(payload)}

    def _security_map(self, symbols: list[str]) -> dict[str, int]:
        statement = select(Security.symbol, Security.id).where(Security.symbol.in_(symbols))
        rows = self.db.execute(statement).all()
        return {symbol: identifier for symbol, identifier in rows}

    @staticmethod
    def _now() -> datetime:
        return datetime.now(UTC).replace(tzinfo=None)

    @staticmethod
    def default_start_date() -> str:
        return (date.today() - timedelta(days=365)).isoformat()

    @staticmethod
    def default_end_date() -> str:
        return date.today().isoformat()
