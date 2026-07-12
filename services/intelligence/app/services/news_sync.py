from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, date, datetime, timedelta
import hashlib
import re
from uuid import uuid4

from sqlalchemy import delete, or_, select
from sqlalchemy.dialects.mysql import insert
from sqlalchemy.orm import Session

from app.models.event_source import EventSource
from app.models.market_event import MarketEvent
from app.models.news_article import NewsArticle
from app.models.news_article_content import NewsArticleContent
from app.models.news_article_security import NewsArticleSecurity
from app.models.news_source import NewsSource
from app.models.security import Security
from app.providers.news import NewsSourceDefinition, get_news_provider
from app.services.announcement_detail import AnnouncementDetailService
from app.services.announcement_normalization import AnnouncementNormalizationService
from app.services.announcement_structuring import AnnouncementStructuringService
from app.services.event_chain import EventChainService


@dataclass(slots=True)
class MatchedSecurity:
    security_id: int
    canonical_symbol: str
    symbol: str
    name: str
    mention_type: str
    matched_text: str
    confidence: float


class NewsSyncService:
    parser_version = "news-v1.1"

    def __init__(self, db: Session) -> None:
        self.db = db
        self._security_cache: list[dict] | None = None

    def seed_sources(self, provider_key: str) -> dict[str, int | str]:
        provider = get_news_provider(provider_key)
        definitions = provider.source_definitions()
        now = self._now()
        payload = [
            {
                "source_key": item.source_key,
                "source_name": item.source_name,
                "source_type": item.source_type,
                "provider": item.provider,
                "access_mode": item.access_mode,
                "base_url": item.base_url,
                "copyright_status": item.copyright_status,
                "robots_checked": item.robots_checked,
                "rate_limit_per_minute": item.rate_limit_per_minute,
                "timeout_seconds": item.timeout_seconds,
                "retry_times": item.retry_times,
                "enabled": item.enabled,
                "created_at": now,
                "updated_at": now,
            }
            for item in definitions
        ]

        statement = insert(NewsSource).values(payload)
        self.db.execute(
            statement.on_duplicate_key_update(
                source_name=statement.inserted.source_name,
                source_type=statement.inserted.source_type,
                provider=statement.inserted.provider,
                access_mode=statement.inserted.access_mode,
                base_url=statement.inserted.base_url,
                copyright_status=statement.inserted.copyright_status,
                robots_checked=statement.inserted.robots_checked,
                rate_limit_per_minute=statement.inserted.rate_limit_per_minute,
                timeout_seconds=statement.inserted.timeout_seconds,
                retry_times=statement.inserted.retry_times,
                enabled=statement.inserted.enabled,
                updated_at=statement.inserted.updated_at,
            )
        )
        self.db.commit()
        return {"provider": provider_key, "processed": len(definitions)}

    def sync_news(
        self,
        provider_key: str,
        scopes: list[str],
        symbols: list[str],
        start_date: str,
        end_date: str,
        limit_per_source: int,
    ) -> dict[str, int | str]:
        self.seed_sources(provider_key)
        provider = get_news_provider(provider_key)
        items = provider.fetch_news(scopes, symbols, start_date, end_date, limit_per_source)
        source_map = self._source_map(provider.source_definitions())
        detail_service = AnnouncementDetailService()
        structuring_service = AnnouncementStructuringService()
        normalization_service = AnnouncementNormalizationService()
        chain_service = EventChainService(self.db)

        stats = {
            "provider": provider_key,
            "fetched": len(items),
            "processed": 0,
            "inserted": 0,
            "updated": 0,
            "clustered": 0,
            "quality_failed": 0,
            "events_created": 0,
            "events_updated": 0,
        }

        try:
            for item in items:
                source_id = source_map.get(item["source_key"])
                if source_id is None:
                    continue

                item = self._enrich_item_content(item, detail_service)
                item = self._structure_item_content(item, structuring_service)
                item = self._normalize_item_content(item, normalization_service)

                title = self._clean_text(item["title"])
                content_text = self._clean_text(item.get("content_text")) or title
                summary = self._clean_text(item.get("summary"))
                published_at = item["published_at"]
                request_id = uuid4().hex
                checksum = self._sha256(
                    "|".join(
                        [
                            title,
                            content_text,
                            published_at.isoformat(),
                            item.get("canonical_url") or "",
                        ]
                    )
                )
                title_hash = self._sha256(self._normalize_text(title))
                content_hash = self._sha256(self._normalize_text(content_text)) if content_text else None
                simhash = self._simhash(" ".join(part for part in [title, summary or "", content_text] if part))
                quality = self._quality_report(
                    title=title,
                    content_text=content_text,
                    canonical_url=item.get("canonical_url"),
                    published_at=published_at,
                    category=item.get("category"),
                )

                article = self.db.scalar(
                    select(NewsArticle).where(
                        NewsArticle.source_id == source_id,
                        NewsArticle.source_item_id == item["source_item_id"],
                    )
                )
                is_insert = article is None
                if article is None:
                    article = NewsArticle(
                        source_id=source_id,
                        source_item_id=item["source_item_id"],
                    )
                    self.db.add(article)

                article.title = title
                article.summary = summary
                article.canonical_url = item.get("canonical_url")
                article.author = item.get("author")
                article.published_at = published_at
                article.fetched_at = self._now()
                article.source_timestamp = item.get("source_timestamp")
                article.category = item.get("category")
                article.importance_level = self._importance_level(item, quality["status"])
                article.sentiment = self._sentiment(title, content_text)
                article.status = "published" if quality["status"] != "failed" else "quality_failed"
                article.language = item.get("language") or "zh-CN"
                article.copyright_status = item.get("copyright_status") or "restricted"
                article.quality_status = quality["status"]
                article.quality_score = quality["score"]
                article.parser_version = self.parser_version
                article.request_id = request_id
                article.checksum = checksum
                article.title_hash = title_hash
                article.content_hash = content_hash
                article.simhash = simhash
                article.metadata_json = item.get("metadata") or {}
                article.updated_at = self._now()
                if is_insert:
                    article.created_at = article.updated_at

                self.db.flush()

                cluster_root = self._find_cluster_candidate(
                    article.id,
                    article.canonical_url,
                    title_hash,
                    content_hash,
                )
                if cluster_root is not None:
                    article.cluster_id = cluster_root.cluster_id or cluster_root.id
                    stats["clustered"] += 1
                elif article.cluster_id is None:
                    article.cluster_id = article.id

                content = self.db.scalar(
                    select(NewsArticleContent).where(NewsArticleContent.news_article_id == article.id)
                )
                if content is None:
                    content = NewsArticleContent(news_article_id=article.id)
                    self.db.add(content)

                content.content_text = content_text
                content.content_html = item.get("content_html")
                content.attachments = item.get("attachments")
                content.images = item.get("images")
                content.tags = item.get("tags")
                content.raw_payload = item.get("raw_payload")
                content.quality_issues = quality["issues"]
                content.structured_data = item.get("structured_data")
                content.extraction_version = item.get("extraction_version")
                content.extracted_at = item.get("extracted_at")
                content.cleaned_at = self._now()
                content.updated_at = self._now()
                if content.created_at is None:
                    content.created_at = content.updated_at

                matches = self._match_securities(
                    title=title,
                    summary=summary,
                    content_text=content_text,
                    provider_symbol=(item.get("metadata") or {}).get("provider_symbol"),
                )
                self._replace_article_securities(article.id, matches)

                if quality["status"] == "failed":
                    stats["quality_failed"] += 1
                else:
                    event_result = self._upsert_event(
                        article,
                        matches,
                        summary,
                        item.get("structured_data"),
                        item.get("normalized_data"),
                        chain_service,
                    )
                    stats["events_created"] += event_result["created"]
                    stats["events_updated"] += event_result["updated"]

                stats["processed"] += 1
                stats["inserted"] += 1 if is_insert else 0
                stats["updated"] += 0 if is_insert else 1
        finally:
            detail_service.close()

        self.db.commit()
        return stats

    @staticmethod
    def default_start_date() -> str:
        return (date.today() - timedelta(days=7)).isoformat()

    @staticmethod
    def default_end_date() -> str:
        return date.today().isoformat()

    def _source_map(self, definitions: list[NewsSourceDefinition]) -> dict[str, int]:
        keys = [item.source_key for item in definitions]
        rows = self.db.execute(
            select(NewsSource.source_key, NewsSource.id).where(NewsSource.source_key.in_(keys))
        ).all()
        return {source_key: identifier for source_key, identifier in rows}

    @staticmethod
    def _enrich_item_content(item: dict, detail_service: AnnouncementDetailService) -> dict:
        try:
            return detail_service.enrich(item)
        except Exception as exception:
            item["metadata"] = {
                **(item.get("metadata") or {}),
                "detail_status": "failed",
                "detail_error": str(exception),
            }
            return item

    @staticmethod
    def _structure_item_content(item: dict, structuring_service: AnnouncementStructuringService) -> dict:
        source_key = str(item.get("source_key") or "")
        if source_key not in {"em_notice_report", "cninfo_disclosure"}:
            return item

        result = structuring_service.extract(
            title=str(item.get("title") or ""),
            content_text=str(item.get("content_text") or ""),
            summary=item.get("summary"),
            category=item.get("category"),
            metadata=item.get("metadata") or {},
        )

        item["structured_data"] = result.structured_data
        item["tags"] = result.tags
        item["extraction_version"] = structuring_service.extraction_version
        item["extracted_at"] = NewsSyncService._now()
        item["metadata"] = {
            **(item.get("metadata") or {}),
            "structuring_status": "fetched",
            "structuring_version": structuring_service.extraction_version,
            "primary_event_tag": result.primary_event_tag,
        }
        return item

    @staticmethod
    def _normalize_item_content(item: dict, normalization_service: AnnouncementNormalizationService) -> dict:
        source_key = str(item.get("source_key") or "")
        if source_key not in {"em_notice_report", "cninfo_disclosure"}:
            return item

        normalized_result = normalization_service.normalize(
            title=str(item.get("title") or ""),
            structured_data=item.get("structured_data") or {},
            metadata=item.get("metadata") or {},
        )
        structured = item.get("structured_data") or {}
        structured["normalized"] = normalized_result.normalized
        item["structured_data"] = structured
        item["normalized_data"] = normalized_result.normalized
        item["tags"] = list(dict.fromkeys([normalized_result.primary_event_tag, *(item.get("tags") or [])]))
        item["metadata"] = {
            **(item.get("metadata") or {}),
            "normalization_status": "fetched",
            "normalization_version": normalization_service.version,
            "primary_event_tag": normalized_result.primary_event_tag,
            "normalized_event_type": normalized_result.event_type,
        }
        return item

    def _security_rows(self) -> list[dict]:
        if self._security_cache is not None:
            return self._security_cache

        rows = self.db.execute(
            select(
                Security.id,
                Security.canonical_symbol,
                Security.symbol,
                Security.name,
                Security.short_name,
            ).where(Security.status == "active")
        ).all()
        self._security_cache = [
            {
                "id": identifier,
                "canonical_symbol": canonical_symbol,
                "symbol": symbol,
                "name": name,
                "short_name": short_name,
            }
            for identifier, canonical_symbol, symbol, name, short_name in rows
        ]
        return self._security_cache

    def _match_securities(
        self,
        *,
        title: str,
        summary: str | None,
        content_text: str,
        provider_symbol: str | None,
    ) -> list[MatchedSecurity]:
        text = " ".join(part for part in [title, summary or "", content_text] if part)
        matches: dict[int, MatchedSecurity] = {}
        security_rows = self._security_rows()

        if provider_symbol:
            for row in security_rows:
                if row["symbol"] == provider_symbol:
                    matches[row["id"]] = MatchedSecurity(
                        security_id=row["id"],
                        canonical_symbol=row["canonical_symbol"],
                        symbol=row["symbol"],
                        name=row["name"],
                        mention_type="provider_symbol",
                        matched_text=provider_symbol,
                        confidence=0.98,
                    )
                    break

        code_hits = set(re.findall(r"(?<!\d)(\d{6})(?!\d)", text))
        for row in security_rows:
            if row["symbol"] in code_hits:
                existing = matches.get(row["id"])
                confidence = 0.95
                if existing is None or confidence > existing.confidence:
                    matches[row["id"]] = MatchedSecurity(
                        security_id=row["id"],
                        canonical_symbol=row["canonical_symbol"],
                        symbol=row["symbol"],
                        name=row["name"],
                        mention_type="code",
                        matched_text=row["symbol"],
                        confidence=confidence,
                    )

        title_zone = " ".join(part for part in [title, summary or ""] if part)
        for row in security_rows:
            candidates = [row["name"], row["short_name"]]
            for candidate in candidates:
                if candidate and candidate in title_zone:
                    existing = matches.get(row["id"])
                    confidence = 0.82 if candidate == row["name"] else 0.76
                    if existing is None or confidence > existing.confidence:
                        matches[row["id"]] = MatchedSecurity(
                            security_id=row["id"],
                            canonical_symbol=row["canonical_symbol"],
                            symbol=row["symbol"],
                            name=row["name"],
                            mention_type="name",
                            matched_text=candidate,
                            confidence=confidence,
                        )
                    break

        ordered = sorted(matches.values(), key=lambda item: (-item.confidence, item.symbol))
        return ordered[:5]

    def _replace_article_securities(self, article_id: int, matches: list[MatchedSecurity]) -> None:
        self.db.execute(
            delete(NewsArticleSecurity).where(NewsArticleSecurity.news_article_id == article_id)
        )
        now = self._now()
        for index, match in enumerate(matches):
            self.db.add(
                NewsArticleSecurity(
                    news_article_id=article_id,
                    security_id=match.security_id,
                    mention_type=match.mention_type,
                    matched_text=match.matched_text,
                    confidence=match.confidence,
                    is_primary=index == 0,
                    created_at=now,
                    updated_at=now,
                )
            )

    def _upsert_event(
        self,
        article: NewsArticle,
        matches: list[MatchedSecurity],
        summary: str | None,
        structured_data: dict | None = None,
        normalized_data: dict | None = None,
        chain_service: EventChainService | None = None,
    ) -> dict[str, int]:
        primary = matches[0] if matches else None
        event_type = (
            str((normalized_data or {}).get("event_type") or "")
            or self._event_type(article.title, article.category, structured_data)
        )
        fingerprint = self._sha256(
            "|".join(
                [
                    event_type,
                    primary.canonical_symbol if primary else "market",
                    self._normalize_text(article.title),
                    article.published_at.date().isoformat(),
                ]
            )
        )
        linked_events = self.db.execute(
            select(MarketEvent)
            .join(EventSource, EventSource.event_id == MarketEvent.id)
            .where(EventSource.news_article_id == article.id)
            .order_by(MarketEvent.id.desc())
        ).scalars().all()
        existing_by_fingerprint = self.db.scalar(select(MarketEvent).where(MarketEvent.fingerprint == fingerprint))

        event = existing_by_fingerprint
        is_insert = event is None

        if event is None and linked_events:
            event = linked_events[0]
            event.fingerprint = fingerprint
            is_insert = False

        if event is None:
            event = MarketEvent(fingerprint=fingerprint)
            self.db.add(event)

        event.event_type = event_type
        event.title = article.title
        event.summary = summary or article.summary or article.title
        event.occurred_at = article.published_at
        event.detected_at = self._now()
        event.importance_level = article.importance_level
        event.sentiment = article.sentiment
        event.confidence = primary.confidence if primary else 0.55
        event.status = "published" if article.quality_status != "failed" else "rejected"
        event.primary_security_id = primary.security_id if primary else None
        event.facts = {
            "source_key": article.metadata_json.get("channel") if article.metadata_json else None,
            "source_article_id": article.id,
            "matched_symbols": [item.canonical_symbol for item in matches],
            "quality_status": article.quality_status,
            "quality_score": article.quality_score,
            "structured_data": structured_data,
            "normalized_data": normalized_data,
        }
        event.ai_analysis = None
        event.published_at = article.published_at
        event.updated_at = self._now()
        if is_insert:
            event.created_at = event.updated_at

        self.db.flush()

        for linked_event in linked_events:
            if linked_event.id == event.id:
                continue
            linked_event.status = "merged"
            linked_event.updated_at = self._now()
            self.db.execute(
                delete(EventSource).where(
                    EventSource.event_id == linked_event.id,
                    EventSource.news_article_id == article.id,
                )
            )

        source = self.db.scalar(
            select(EventSource).where(
                EventSource.event_id == event.id,
                EventSource.news_article_id == article.id,
            )
        )
        if source is None:
            self.db.add(
                EventSource(
                    event_id=event.id,
                    news_article_id=article.id,
                    relation_type="primary",
                    created_at=self._now(),
                    updated_at=self._now(),
                )
            )

        self.db.flush()

        if chain_service is not None:
            chain_service.assign_event(event)

        return {"created": 1 if is_insert else 0, "updated": 0 if is_insert else 1}

    def _find_cluster_candidate(
        self,
        article_id: int,
        canonical_url: str | None,
        title_hash: str,
        content_hash: str | None,
    ) -> NewsArticle | None:
        conditions = [NewsArticle.title_hash == title_hash]
        if canonical_url:
            conditions.append(NewsArticle.canonical_url == canonical_url)
        if content_hash:
            conditions.append(NewsArticle.content_hash == content_hash)

        return self.db.scalar(
            select(NewsArticle)
            .where(NewsArticle.id != article_id)
            .where(or_(*conditions))
            .order_by(NewsArticle.id.asc())
            .limit(1)
        )

    def _quality_report(
        self,
        *,
        title: str,
        content_text: str | None,
        canonical_url: str | None,
        published_at: datetime | None,
        category: str | None,
    ) -> dict:
        issues: list[str] = []
        score = 100

        if not title:
            issues.append("missing_title")
            score -= 60
        if not content_text:
            issues.append("missing_content")
            score -= 50
        if not canonical_url:
            issues.append("missing_url")
            score -= 10
        if published_at is None:
            issues.append("missing_published_at")
            score -= 30

        content_length = len(content_text or "")
        if content_length < 20:
            issues.append("content_too_short")
            score -= 20
        if category in {"notice", "disclosure"} and content_length < 40:
            issues.append("announcement_summary_only")
            score -= 10

        score = max(score, 0)
        if "missing_title" in issues or "missing_content" in issues or published_at is None:
            status = "failed"
        elif issues:
            status = "partial"
        else:
            status = "passed"

        return {"status": status, "score": score, "issues": issues}

    @staticmethod
    def _event_type(title: str, category: str | None, structured_data: dict | None = None) -> str:
        event_tags = (structured_data or {}).get("event_tags") or []
        tag_map = {
            "board_resolution": "board_resolution",
            "shareholder_meeting": "shareholder_meeting",
            "external_investment": "external_investment",
            "bond_issue": "bond_issue",
            "investor_relations": "investor_relations",
            "earnings_forecast": "earnings",
            "buyback": "buyback",
            "share_increase": "share_increase",
            "share_reduction": "share_reduction",
            "guarantee": "guarantee",
            "litigation": "litigation",
            "regulatory": "regulatory",
            "contract": "contract",
            "merger_restructuring": "mna",
            "esg": "esg",
            "announcement": "disclosure",
        }
        for tag in event_tags:
            if tag in tag_map:
                return tag_map[tag]

        rules = [
            ("regulatory", ["问询", "处罚", "立案", "监管", "函"]),
            ("earnings", ["业绩", "预盈", "预亏", "快报", "半年报", "年报", "季报"]),
            ("buyback", ["回购"]),
            ("share_increase", ["增持"]),
            ("share_reduction", ["减持"]),
            ("trading_halt", ["停牌", "复牌"]),
            ("mna", ["收购", "并购", "重组"]),
            ("contract", ["中标", "合同"]),
            ("research", ["调研"]),
            ("disclosure", ["公告"]),
        ]
        combined = f"{title} {category or ''}"
        for event_type, keywords in rules:
            if any(keyword in combined for keyword in keywords):
                return event_type
        if category == "global_flash":
            return "macro_news"
        return "company_news"

    @staticmethod
    def _importance_level(item: dict, quality_status: str) -> str:
        if quality_status == "failed":
            return "D"

        title = item["title"]
        source_key = item["source_key"]
        high_keywords = ["停牌", "复牌", "回购", "增持", "减持", "立案", "处罚", "重组", "中标", "业绩"]
        medium_keywords = ["公告", "调研", "合作", "发布", "快讯"]

        if source_key == "cninfo_disclosure":
            return "A" if any(keyword in title for keyword in high_keywords) else "B"
        if any(keyword in title for keyword in high_keywords):
            return "A"
        if any(keyword in title for keyword in medium_keywords):
            return "B"
        if source_key == "em_global_flash":
            return "B"
        return "C"

    @staticmethod
    def _sentiment(title: str, content_text: str | None) -> str:
        combined = f"{title} {content_text or ''}"
        negative_keywords = ["亏损", "减持", "处罚", "立案", "下滑", "终止", "停牌", "风险"]
        positive_keywords = ["增长", "中标", "回购", "增持", "盈利", "合作", "签约"]
        if any(keyword in combined for keyword in negative_keywords):
            return "negative"
        if any(keyword in combined for keyword in positive_keywords):
            return "positive"
        return "neutral"

    @staticmethod
    def _normalize_text(value: str) -> str:
        return re.sub(r"\s+", "", value).lower()

    @staticmethod
    def _clean_text(value: str | None) -> str:
        return (value or "").strip()

    @staticmethod
    def _sha256(value: str) -> str:
        return hashlib.sha256(value.encode("utf-8")).hexdigest()

    @staticmethod
    def _simhash(value: str) -> int:
        tokens = [token for token in re.split(r"[^\w\u4e00-\u9fff]+", value) if token]
        if not tokens:
            return 0
        vector = [0] * 64
        for token in tokens:
            token_hash = int(hashlib.md5(token.encode("utf-8")).hexdigest(), 16)
            for bit in range(64):
                if token_hash & (1 << bit):
                    vector[bit] += 1
                else:
                    vector[bit] -= 1
        fingerprint = 0
        for bit, weight in enumerate(vector):
            if weight >= 0:
                fingerprint |= 1 << bit
        return fingerprint

    @staticmethod
    def _now() -> datetime:
        return datetime.now(UTC).replace(tzinfo=None)
