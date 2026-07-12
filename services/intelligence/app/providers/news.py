from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, date, datetime
import hashlib
import re
from typing import Protocol

import akshare as ak
import pandas as pd

from app.providers.market import ProviderHealth


@dataclass(slots=True)
class NewsSourceDefinition:
    source_key: str
    source_name: str
    source_type: str
    provider: str
    access_mode: str
    base_url: str
    copyright_status: str
    robots_checked: bool
    rate_limit_per_minute: int | None
    timeout_seconds: int
    retry_times: int
    enabled: bool = True


class NewsProvider(Protocol):
    provider_key: str

    def source_definitions(self) -> list[NewsSourceDefinition]:
        ...

    def fetch_news(
        self,
        scopes: list[str],
        symbols: list[str],
        start_date: str,
        end_date: str,
        limit_per_source: int,
    ) -> list[dict]:
        ...

    def health_check(self) -> ProviderHealth:
        ...


class AKShareNewsProvider:
    provider_key = "akshare"

    def source_definitions(self) -> list[NewsSourceDefinition]:
        return [
            NewsSourceDefinition(
                source_key="em_global_flash",
                source_name="东方财富全球财经快讯",
                source_type="news",
                provider=self.provider_key,
                access_mode="api",
                base_url="https://finance.eastmoney.com/",
                copyright_status="restricted",
                robots_checked=True,
                rate_limit_per_minute=60,
                timeout_seconds=15,
                retry_times=3,
            ),
            NewsSourceDefinition(
                source_key="em_stock_news",
                source_name="东方财富个股新闻",
                source_type="news",
                provider=self.provider_key,
                access_mode="api",
                base_url="https://finance.eastmoney.com/",
                copyright_status="restricted",
                robots_checked=True,
                rate_limit_per_minute=30,
                timeout_seconds=15,
                retry_times=3,
            ),
            NewsSourceDefinition(
                source_key="em_notice_report",
                source_name="东方财富公告大全",
                source_type="announcement",
                provider=self.provider_key,
                access_mode="api",
                base_url="https://data.eastmoney.com/notices/",
                copyright_status="restricted",
                robots_checked=True,
                rate_limit_per_minute=20,
                timeout_seconds=20,
                retry_times=3,
            ),
            NewsSourceDefinition(
                source_key="cninfo_disclosure",
                source_name="巨潮资讯公告查询",
                source_type="announcement",
                provider=self.provider_key,
                access_mode="api",
                base_url="http://www.cninfo.com.cn/",
                copyright_status="public",
                robots_checked=True,
                rate_limit_per_minute=20,
                timeout_seconds=20,
                retry_times=3,
            ),
        ]

    def fetch_news(
        self,
        scopes: list[str],
        symbols: list[str],
        start_date: str,
        end_date: str,
        limit_per_source: int,
    ) -> list[dict]:
        scope_set = {scope.strip().lower() for scope in scopes if scope.strip()}
        if not scope_set:
            scope_set = {"global", "stock", "notice"}

        results: list[dict] = []

        if "global" in scope_set:
            dataframe = ak.stock_info_global_em().head(limit_per_source)
            results.extend(self._map_global_news(dataframe))

        if "stock" in scope_set and symbols:
            for symbol in symbols:
                dataframe = ak.stock_news_em(symbol=symbol).head(limit_per_source)
                results.extend(self._map_stock_news(symbol, dataframe))

        if "notice" in scope_set:
            dataframe = ak.stock_notice_report(symbol="全部", date=end_date.replace("-", ""))
            results.extend(self._map_notice_report(dataframe.head(limit_per_source)))

        if "disclosure" in scope_set and symbols:
            for symbol in symbols:
                dataframe = ak.stock_zh_a_disclosure_report_cninfo(
                    symbol=symbol,
                    market="沪深京",
                    start_date=start_date.replace("-", ""),
                    end_date=end_date.replace("-", ""),
                )
                results.extend(self._map_cninfo_disclosure(symbol, dataframe.head(limit_per_source)))

        return results

    def health_check(self) -> ProviderHealth:
        return ProviderHealth(
            provider=self.provider_key,
            status="configured",
            detail="AKShare news provider is configured and ready for runtime fetch.",
        )

    def _map_global_news(self, dataframe: pd.DataFrame) -> list[dict]:
        now = datetime.now(UTC).replace(tzinfo=None)
        records = dataframe.to_dict(orient="records")
        mapped: list[dict] = []
        for item in records:
            title = self._clean_text(item.get("标题"))
            if not title:
                continue
            url = self._clean_text(item.get("链接"))
            content_text = self._clean_text(item.get("摘要"))
            published_at = self._datetime_value(item.get("发布时间")) or now
            mapped.append(
                self._base_item(
                    source_key="em_global_flash",
                    source_item_id=self._source_item_id(url, title, published_at),
                    title=title,
                    summary=content_text,
                    content_text=content_text,
                    content_html=None,
                    canonical_url=url,
                    author=None,
                    published_at=published_at,
                    source_timestamp=published_at,
                    category="global_flash",
                    language="zh-CN",
                    copyright_status="restricted",
                    raw_payload=item,
                    metadata={"channel": "global", "scope": "global"},
                )
            )
        return mapped

    def _map_stock_news(self, symbol: str, dataframe: pd.DataFrame) -> list[dict]:
        records = dataframe.to_dict(orient="records")
        mapped: list[dict] = []
        for item in records:
            title = self._clean_text(item.get("新闻标题"))
            if not title:
                continue
            url = self._clean_text(item.get("新闻链接"))
            content_text = self._clean_text(item.get("新闻内容"))
            published_at = self._datetime_value(item.get("发布时间")) or datetime.now(UTC).replace(tzinfo=None)
            author = self._clean_text(item.get("文章来源"))
            summary = self._clean_text((content_text or "")[:160]) if content_text else None
            mapped.append(
                self._base_item(
                    source_key="em_stock_news",
                    source_item_id=self._source_item_id(url, title, published_at),
                    title=title,
                    summary=summary,
                    content_text=content_text,
                    content_html=None,
                    canonical_url=url,
                    author=author,
                    published_at=published_at,
                    source_timestamp=published_at,
                    category="stock_news",
                    language="zh-CN",
                    copyright_status="restricted",
                    raw_payload=item,
                    metadata={
                        "channel": "stock",
                        "scope": "stock",
                        "provider_symbol": symbol,
                        "keyword": self._clean_text(item.get("关键词")),
                    },
                )
            )
        return mapped

    def _map_notice_report(self, dataframe: pd.DataFrame) -> list[dict]:
        records = dataframe.to_dict(orient="records")
        mapped: list[dict] = []
        for item in records:
            title = self._clean_text(item.get("公告标题"))
            if not title:
                continue
            published_date = self._date_value(item.get("公告日期")) or date.today()
            published_at = datetime.combine(published_date, datetime.min.time())
            url = self._clean_text(item.get("网址"))
            code = self._clean_text(item.get("代码"))
            name = self._clean_text(item.get("名称"))
            notice_type = self._clean_text(item.get("公告类型"))
            mapped.append(
                self._base_item(
                    source_key="em_notice_report",
                    source_item_id=self._source_item_id(url, title, published_at),
                    title=title,
                    summary=notice_type,
                    content_text=title,
                    content_html=None,
                    canonical_url=url,
                    author=name,
                    published_at=published_at,
                    source_timestamp=published_at,
                    category=notice_type or "notice",
                    language="zh-CN",
                    copyright_status="restricted",
                    raw_payload=item,
                    metadata={
                        "channel": "notice",
                        "scope": "notice",
                        "provider_symbol": code,
                        "security_name": name,
                        "notice_type": notice_type,
                    },
                )
            )
        return mapped

    def _map_cninfo_disclosure(self, symbol: str, dataframe: pd.DataFrame) -> list[dict]:
        records = dataframe.to_dict(orient="records")
        mapped: list[dict] = []
        for item in records:
            title = self._clean_text(item.get("公告标题"))
            if not title:
                continue
            published_date = self._date_value(item.get("公告时间")) or date.today()
            published_at = datetime.combine(published_date, datetime.min.time())
            url = self._clean_text(item.get("公告链接"))
            code = self._clean_text(item.get("代码")) or symbol
            name = self._clean_text(item.get("简称"))
            mapped.append(
                self._base_item(
                    source_key="cninfo_disclosure",
                    source_item_id=self._source_item_id(url, title, published_at),
                    title=title,
                    summary=name,
                    content_text=title,
                    content_html=None,
                    canonical_url=url,
                    author=name,
                    published_at=published_at,
                    source_timestamp=published_at,
                    category="disclosure",
                    language="zh-CN",
                    copyright_status="public",
                    raw_payload=item,
                    metadata={
                        "channel": "disclosure",
                        "scope": "disclosure",
                        "provider_symbol": code,
                        "security_name": name,
                    },
                )
            )
        return mapped

    @staticmethod
    def _base_item(
        *,
        source_key: str,
        source_item_id: str,
        title: str,
        summary: str | None,
        content_text: str | None,
        content_html: str | None,
        canonical_url: str | None,
        author: str | None,
        published_at: datetime,
        source_timestamp: datetime,
        category: str | None,
        language: str,
        copyright_status: str,
        raw_payload: dict,
        metadata: dict,
    ) -> dict:
        return {
            "source_key": source_key,
            "source_item_id": source_item_id,
            "title": title,
            "summary": summary,
            "content_text": content_text,
            "content_html": content_html,
            "canonical_url": canonical_url,
            "author": author,
            "published_at": published_at,
            "source_timestamp": source_timestamp,
            "category": category,
            "language": language,
            "copyright_status": copyright_status,
            "raw_payload": AKShareNewsProvider._json_safe(raw_payload),
            "metadata": AKShareNewsProvider._json_safe(metadata),
        }

    @staticmethod
    def _clean_text(value: object) -> str | None:
        if value is None:
            return None
        text = str(value).strip()
        return text or None

    @staticmethod
    def _datetime_value(value: object) -> datetime | None:
        if value is None or value == "":
            return None
        timestamp = pd.to_datetime(value, errors="coerce")
        if pd.isna(timestamp):
            return None
        return timestamp.to_pydatetime().replace(tzinfo=None)

    @staticmethod
    def _date_value(value: object) -> date | None:
        if value is None or value == "":
            return None
        timestamp = pd.to_datetime(value, errors="coerce")
        if pd.isna(timestamp):
            return None
        return timestamp.date()

    @staticmethod
    def _source_item_id(url: str | None, title: str, published_at: datetime) -> str:
        if url:
            match = re.search(r"(\d{9,})", url)
            if match:
                return match.group(1)
        digest = hashlib.sha256(f"{title}|{published_at.isoformat()}|{url or ''}".encode("utf-8")).hexdigest()
        return digest[:32]

    @classmethod
    def _json_safe(cls, value: object) -> object:
        if isinstance(value, dict):
            return {str(key): cls._json_safe(item) for key, item in value.items()}
        if isinstance(value, list):
            return [cls._json_safe(item) for item in value]
        if isinstance(value, tuple):
            return [cls._json_safe(item) for item in value]
        if isinstance(value, datetime):
            return value.isoformat()
        if isinstance(value, date):
            return value.isoformat()
        return value


def get_news_provider(provider_key: str) -> NewsProvider:
    providers: dict[str, NewsProvider] = {
        "akshare": AKShareNewsProvider(),
    }
    if provider_key not in providers:
        raise ValueError(f"Unsupported news provider: {provider_key}")
    return providers[provider_key]
