from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime
import re
from typing import Any


@dataclass(slots=True)
class StructuringResult:
    structured_data: dict[str, Any]
    tags: list[str]
    event_tags: list[str]
    primary_event_tag: str | None


class AnnouncementStructuringService:
    extraction_version = "announcement-struct-v1"

    def extract(
        self,
        *,
        title: str,
        content_text: str,
        summary: str | None,
        category: str | None,
        metadata: dict[str, Any] | None,
    ) -> StructuringResult:
        normalized_text = self._normalize_text(content_text)
        combined = " ".join(part for part in [title, summary or "", normalized_text] if part)

        agenda_items = self._agenda_items(normalized_text)
        amount_mentions = self._amount_mentions(combined)
        subjects = self._subjects(title=title, content_text=normalized_text, metadata=metadata or {})
        date_mentions = self._date_mentions(combined)
        risk_flags = self._risk_flags(normalized_text)
        event_tags = self._event_tags(title=title, content_text=normalized_text, category=category)
        primary_event_tag = event_tags[0] if event_tags else None

        structured_data = {
            "agenda_items": agenda_items,
            "amount_mentions": amount_mentions,
            "subjects": subjects,
            "date_mentions": date_mentions,
            "risk_flags": risk_flags,
            "event_tags": event_tags,
        }

        return StructuringResult(
            structured_data=structured_data,
            tags=event_tags,
            event_tags=event_tags,
            primary_event_tag=primary_event_tag,
        )

    @staticmethod
    def _normalize_text(value: str) -> str:
        text = value.replace("\r", "\n")
        text = re.sub(r"\u3000", " ", text)
        text = re.sub(r"[ \t]+\n", "\n", text)
        text = re.sub(r"\n{3,}", "\n\n", text)
        return text.strip()

    def _agenda_items(self, text: str) -> list[dict[str, Any]]:
        patterns = [
            r"([一二三四五六七八九十]+)、\s*审议通过了?《(?P<title>[^》]+)》",
            r"([一二三四五六七八九十]+)、\s*审议《(?P<title>[^》]+)》",
            r"(?P<title>关于[^。\n]{4,80}议案)",
        ]
        agenda_items: list[dict[str, Any]] = []
        seen: set[str] = set()

        for line in text.splitlines():
            line = line.strip()
            if not line:
                continue
            for pattern in patterns:
                match = re.search(pattern, line)
                if not match:
                    continue
                title = match.group("title").strip()
                if title in seen:
                    break
                seen.add(title)
                agenda_items.append(
                    {
                        "title": title,
                        "action": "审议通过" if "通过" in line else "审议",
                        "sequence": len(agenda_items) + 1,
                        "context": line[:200],
                    }
                )
                break

        return agenda_items[:20]

    def _amount_mentions(self, text: str) -> list[dict[str, Any]]:
        pattern = re.compile(
            r"(?P<value>\d[\d,]*(?:\.\d+)?)\s*(?P<unit>亿元人民币|万元人民币|亿元整|亿元至|亿元到|亿元|万元|亿股|万股|元)"
        )
        mentions: list[dict[str, Any]] = []
        seen: set[str] = set()

        for match in pattern.finditer(text):
            raw = match.group(0)
            if raw in seen:
                continue
            seen.add(raw)
            numeric = float(match.group("value").replace(",", ""))
            unit = match.group("unit")
            normalized_value = numeric
            if unit.startswith("亿"):
                normalized_value = numeric * 100000000
            elif unit.startswith("万"):
                normalized_value = numeric * 10000
            mentions.append(
                {
                    "text": raw,
                    "numeric_value": normalized_value,
                    "display_value": numeric,
                    "unit": unit,
                    "currency": "CNY" if "元" in unit else None,
                }
            )

        return mentions[:30]

    def _subjects(self, *, title: str, content_text: str, metadata: dict[str, Any]) -> list[dict[str, Any]]:
        candidates: list[dict[str, Any]] = []
        seen: set[str] = set()

        for key in ["security_name", "provider_symbol"]:
            value = str(metadata.get(key) or "").strip()
            if value and value not in seen:
                seen.add(value)
                candidates.append(
                    {
                        "name": value,
                        "type": "security" if key == "security_name" else "security_symbol",
                        "source": "metadata",
                    }
                )

        org_pattern = re.compile(
            r"([A-Za-z0-9\u4e00-\u9fff（）()·\-]{2,60}(?:公司|银行|证券|基金|合伙企业|委员会|交易所|集团|事务所|管理人|投资者))"
        )
        for scope in [title, content_text]:
            for match in org_pattern.finditer(scope):
                name = match.group(1).strip("：:;；，,。. ")
                if len(name) < 2 or name in seen:
                    continue
                seen.add(name)
                candidates.append(
                    {
                        "name": name,
                        "type": "organization",
                        "source": "content",
                    }
                )

        return candidates[:20]

    def _date_mentions(self, text: str) -> list[dict[str, Any]]:
        patterns = [
            r"(?P<y>\d{4})[-年/.](?P<m>\d{1,2})[-月/.](?P<d>\d{1,2})日?",
            r"(?P<y>\d{4})年(?P<m>\d{1,2})月",
        ]
        mentions: list[dict[str, Any]] = []
        seen: set[str] = set()

        for pattern in patterns:
            for match in re.finditer(pattern, text):
                raw = match.group(0)
                if raw in seen:
                    continue
                seen.add(raw)
                year = int(match.group("y"))
                month = int(match.group("m"))
                day = int(match.groupdict().get("d") or 1)
                try:
                    normalized = date(year, month, day).isoformat()
                except ValueError:
                    normalized = None
                mentions.append(
                    {
                        "text": raw,
                        "normalized": normalized,
                    }
                )

        return mentions[:30]

    def _risk_flags(self, text: str) -> list[dict[str, Any]]:
        keyword_map = {
            "market_risk": ["风险", "波动", "不确定", "可能"],
            "regulatory_risk": ["处罚", "立案", "监管", "问询"],
            "financial_risk": ["亏损", "减值", "违约", "逾期"],
            "execution_risk": ["终止", "延期", "失败", "未达预期"],
        }
        flags: list[dict[str, Any]] = []
        seen: set[str] = set()

        sentences = [segment.strip() for segment in re.split(r"[。！？\n]", text) if segment.strip()]
        for risk_type, keywords in keyword_map.items():
            for sentence in sentences:
                for keyword in keywords:
                    if keyword not in sentence:
                        continue
                    signature = f"{risk_type}:{keyword}:{sentence[:40]}"
                    if signature in seen:
                        continue
                    seen.add(signature)
                    flags.append(
                        {
                            "type": risk_type,
                            "keyword": keyword,
                            "context": sentence[:200],
                        }
                    )
                    break

        return flags[:20]

    def _event_tags(self, *, title: str, content_text: str, category: str | None) -> list[str]:
        title_text = f"{title} {category or ''}"
        head_text = content_text[:800]
        tags: list[str] = []

        def add(tag: str) -> None:
            if tag not in tags:
                tags.append(tag)

        if "董事会决议公告" in title or title.strip() == "董事会决议公告":
            add("board_resolution")
        if "股东大会" in title_text:
            add("shareholder_meeting")
        if any(keyword in title_text for keyword in ["共同投资", "对外投资", "投资机构"]):
            add("external_investment")
        if any(keyword in title_text for keyword in ["债券", "融资券", "次级债"]):
            add("bond_issue")
        if any(keyword in title_text for keyword in ["投资者关系活动记录表", "调研活动", "策略会"]):
            add("investor_relations")
        if any(keyword in title_text for keyword in ["业绩预告", "业绩快报", "净利润", "亏损"]):
            add("earnings_forecast")
        if "回购" in title_text:
            add("buyback")
        if "增持" in title_text:
            add("share_increase")
        if "减持" in title_text:
            add("share_reduction")
        if "担保" in title_text:
            add("guarantee")
        if any(keyword in title_text for keyword in ["诉讼", "仲裁"]):
            add("litigation")
        if any(keyword in title_text for keyword in ["处罚", "立案", "监管", "问询"]):
            add("regulatory")
        if any(keyword in title_text for keyword in ["合同", "协议", "中标"]):
            add("contract")
        if any(keyword in title_text for keyword in ["重组", "并购", "收购"]):
            add("merger_restructuring")
        if "ESG" in title_text:
            add("esg")
        if "回购" in head_text[:400]:
            add("buyback")

        if not tags:
            if "董事会决议公告" in head_text[:120]:
                add("board_resolution")
            if any(keyword in head_text for keyword in ["投资者关系活动", "投资者问答环节"]):
                add("investor_relations")
            if any(keyword in head_text for keyword in ["次级债", "融资券", "债券发行"]):
                add("bond_issue")
            if any(keyword in head_text for keyword in ["对外投资", "共同投资"]):
                add("external_investment")

        if not tags:
            tags.append("announcement")

        return tags
