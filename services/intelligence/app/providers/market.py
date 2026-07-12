from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, date, datetime
from typing import Protocol

import akshare as ak
import pandas as pd


@dataclass(slots=True)
class ProviderHealth:
    provider: str
    status: str
    detail: str


class MarketProvider(Protocol):
    provider_key: str

    def get_security_list(self) -> list[dict]:
        ...

    def get_realtime_quotes(self, symbols: list[str]) -> list[dict]:
        ...

    def get_daily_bars(
        self,
        symbol: str,
        start_date: str,
        end_date: str,
        adjust: str = "none",
    ) -> list[dict]:
        ...

    def get_index_snapshots(self, codes: list[str] | None = None) -> list[dict]:
        ...

    def get_index_daily_bars(self, code: str, start_date: str, end_date: str) -> list[dict]:
        ...

    def health_check(self) -> ProviderHealth:
        ...


class AKShareMarketProvider:
    provider_key = "akshare"
    default_index_codes = ["sh000001", "sz399001", "sz399006", "sh000300", "sh000016", "sh000905"]

    def get_security_list(self) -> list[dict]:
        dataframe = ak.stock_info_a_code_name()
        records: list[dict] = dataframe.to_dict(orient="records")
        return [
            {
                "symbol": str(item["code"]).strip(),
                "name": str(item["name"]).strip(),
            }
            for item in records
            if item.get("code") and item.get("name")
        ]

    def get_realtime_quotes(self, symbols: list[str]) -> list[dict]:
        dataframe = ak.stock_zh_a_spot_em()
        records = dataframe.to_dict(orient="records")
        matched = [item for item in records if str(item.get("代码", "")).strip() in set(symbols)]
        now = datetime.now(UTC).replace(tzinfo=None)

        return [
            {
                "symbol": str(item.get("代码", "")).strip(),
                "name": self._clean_text(item.get("名称")),
                "quote_time": now,
                "last_price": self._number(item.get("最新价")),
                "pre_close": self._number(item.get("昨收")),
                "open": self._number(item.get("今开")),
                "high": self._number(item.get("最高")),
                "low": self._number(item.get("最低")),
                "volume": self._number(item.get("成交量")),
                "amount": self._number(item.get("成交额")),
                "turnover_rate": self._number(item.get("换手率")),
                "pct_change": self._number(item.get("涨跌幅")),
                "source_timestamp": now,
            }
            for item in matched
            if str(item.get("代码", "")).strip()
        ]

    def get_daily_bars(
        self,
        symbol: str,
        start_date: str,
        end_date: str,
        adjust: str = "none",
    ) -> list[dict]:
        adjust_arg = "" if adjust == "none" else adjust
        dataframe = ak.stock_zh_a_hist(
            symbol=symbol,
            period="daily",
            start_date=start_date.replace("-", ""),
            end_date=end_date.replace("-", ""),
            adjust=adjust_arg,
        )
        records = dataframe.to_dict(orient="records")
        result: list[dict] = []
        for item in records:
            close = self._number(item.get("收盘"))
            change_amount = self._number(item.get("涨跌额"))
            pre_close = close - change_amount if close is not None and change_amount is not None else None
            result.append(
                {
                    "symbol": symbol,
                    "trade_date": self._date_value(item.get("日期")),
                    "open": self._number(item.get("开盘")),
                    "high": self._number(item.get("最高")),
                    "low": self._number(item.get("最低")),
                    "close": close,
                    "pre_close": pre_close,
                    "volume": self._number(item.get("成交量")),
                    "amount": self._number(item.get("成交额")),
                    "turnover_rate": self._number(item.get("换手率")),
                    "pct_change": self._number(item.get("涨跌幅")),
                    "adjust_type": adjust,
                }
            )
        return [item for item in result if item["trade_date"] is not None]

    def get_index_snapshots(self, codes: list[str] | None = None) -> list[dict]:
        dataframe = ak.stock_zh_index_spot_sina()
        records = dataframe.to_dict(orient="records")
        code_set = set(codes or self.default_index_codes)
        now = datetime.now(UTC).replace(tzinfo=None)

        filtered = [
            item for item in records
            if str(item.get("代码", "")).strip() and (not code_set or str(item.get("代码", "")).strip() in code_set)
        ]

        return [
            {
                "code": str(item.get("代码", "")).strip(),
                "name": self._clean_text(item.get("名称")) or str(item.get("代码", "")).strip(),
                "exchange": self._index_exchange(str(item.get("代码", "")).strip()),
                "market": "CN",
                "index_type": "broad",
                "status": "active",
                "quote_time": now,
                "last_price": self._number(item.get("最新价")),
                "change_amount": self._number(item.get("涨跌额")),
                "pct_change": self._number(item.get("涨跌幅")),
                "open": self._number(item.get("今开")),
                "high": self._number(item.get("最高")),
                "low": self._number(item.get("最低")),
                "pre_close": self._number(item.get("昨收")),
                "volume": self._number(item.get("成交量")),
                "amount": self._number(item.get("成交额")),
                "source_timestamp": now,
            }
            for item in filtered
        ]

    def get_index_daily_bars(self, code: str, start_date: str, end_date: str) -> list[dict]:
        dataframe = ak.stock_zh_index_daily_em(
            symbol=code,
            start_date=start_date.replace("-", ""),
            end_date=end_date.replace("-", ""),
        )
        records = dataframe.to_dict(orient="records")
        result: list[dict] = []
        for item in records:
            close = self._number(item.get("close") or item.get("收盘"))
            pre_close = self._number(item.get("pre_close") or item.get("昨收"))
            pct_change = self._number(item.get("pct_chg") or item.get("涨跌幅"))
            if pre_close is None and close is not None and pct_change is not None:
                pre_close = close / (1 + pct_change / 100) if pct_change != -100 else None
            result.append(
                {
                    "code": code,
                    "trade_date": self._date_value(item.get("date") or item.get("日期")),
                    "open": self._number(item.get("open") or item.get("开盘")),
                    "high": self._number(item.get("high") or item.get("最高")),
                    "low": self._number(item.get("low") or item.get("最低")),
                    "close": close,
                    "pre_close": pre_close,
                    "volume": self._number(item.get("volume") or item.get("成交量")),
                    "amount": self._number(item.get("amount") or item.get("成交额")),
                    "pct_change": pct_change,
                }
            )
        return [item for item in result if item["trade_date"] is not None]

    def health_check(self) -> ProviderHealth:
        return ProviderHealth(
            provider=self.provider_key,
            status="configured",
            detail="AKShare provider is configured and ready for runtime fetch.",
        )

    @staticmethod
    def _clean_text(value: object) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        return text or None

    @staticmethod
    def _number(value: object) -> float | None:
        if value is None or value == "" or (isinstance(value, float) and pd.isna(value)):
            return None
        try:
            return float(value)
        except (TypeError, ValueError):
            return None

    @staticmethod
    def _date_value(value: object) -> date | None:
        if value is None or value == "":
            return None
        timestamp = pd.to_datetime(value, errors="coerce")
        if pd.isna(timestamp):
            return None
        return timestamp.date()

    @staticmethod
    def _index_exchange(code: str) -> str | None:
        code_lower = code.lower()
        if code_lower.startswith("sh"):
            return "XSHG"
        if code_lower.startswith("sz"):
            return "XSHE"
        if code_lower.startswith("bj"):
            return "BJSE"
        return None


def get_market_provider(provider_key: str) -> MarketProvider:
    providers: dict[str, MarketProvider] = {
        "akshare": AKShareMarketProvider(),
    }
    if provider_key not in providers:
        raise ValueError(f"Unsupported market provider: {provider_key}")
    return providers[provider_key]
