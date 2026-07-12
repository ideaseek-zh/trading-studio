from __future__ import annotations

from datetime import UTC, datetime

from sqlalchemy.dialects.mysql import insert
from sqlalchemy.orm import Session

from app.models.security import Security
from app.providers.market import get_market_provider


class SecuritySyncService:
    def __init__(self, db: Session) -> None:
        self.db = db

    def sync(self, provider_key: str) -> dict[str, int | str]:
        provider = get_market_provider(provider_key)
        source_records = provider.get_security_list()
        now = datetime.now(UTC).replace(tzinfo=None)

        normalized_rows = [
            {
                "canonical_symbol": self._canonical_symbol(record["symbol"]),
                "symbol": record["symbol"],
                "exchange": self._exchange(record["symbol"]),
                "market": "CN",
                "security_type": "stock",
                "name": record["name"],
                "short_name": record["name"],
                "status": "active",
                "currency": "CNY",
                "metadata_json": {"provider": provider_key},
                "created_at": now,
                "updated_at": now,
            }
            for record in source_records
        ]

        if not normalized_rows:
            return {
                "provider": provider_key,
                "inserted_or_updated": 0,
                "total_received": 0,
            }

        statement = insert(Security).values(normalized_rows)
        update_columns = {
            "name": statement.inserted.name,
            "short_name": statement.inserted.short_name,
            "exchange": statement.inserted.exchange,
            "market": statement.inserted.market,
            "security_type": statement.inserted.security_type,
            "status": statement.inserted.status,
            "currency": statement.inserted.currency,
            "metadata": statement.inserted["metadata"],
            "updated_at": statement.inserted.updated_at,
        }
        self.db.execute(statement.on_duplicate_key_update(**update_columns))
        self.db.commit()

        return {
            "provider": provider_key,
            "inserted_or_updated": len(normalized_rows),
            "total_received": len(source_records),
        }

    @staticmethod
    def _exchange(symbol: str) -> str:
        if symbol.startswith(("600", "601", "603", "605", "688", "689", "900")):
            return "XSHG"
        if symbol.startswith(("430", "830", "831", "832", "833", "834", "835", "836", "837", "838", "839", "870", "871", "872", "873", "874", "875", "876", "877", "878", "879", "880", "881", "882", "883", "884", "885", "886", "887", "888", "889", "920")):
            return "BJSE"
        return "XSHE"

    @classmethod
    def _canonical_symbol(cls, symbol: str) -> str:
        return f"CN.{cls._exchange(symbol)}.{symbol}"
