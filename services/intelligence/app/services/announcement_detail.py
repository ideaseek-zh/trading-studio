from __future__ import annotations

from io import BytesIO
import json
import re
from typing import Any
from urllib.parse import parse_qs, urlparse

import httpx
from pypdf import PdfReader


class AnnouncementDetailService:
    def __init__(self) -> None:
        self.client = httpx.Client(
            follow_redirects=True,
            timeout=20.0,
            headers={
                "User-Agent": (
                    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0 Safari/537.36"
                ),
            },
        )

    def close(self) -> None:
        self.client.close()

    def enrich(self, item: dict[str, Any]) -> dict[str, Any]:
        source_key = item.get("source_key")
        if source_key == "em_notice_report":
            return self._enrich_eastmoney_notice(item)
        if source_key == "cninfo_disclosure":
            return self._enrich_cninfo_disclosure(item)
        return item

    def _enrich_eastmoney_notice(self, item: dict[str, Any]) -> dict[str, Any]:
        art_code = self._eastmoney_art_code(item)
        if not art_code:
            return self._with_detail_error(item, "missing_art_code")

        first_page = self._eastmoney_notice_page(art_code, 1)
        data = first_page.get("data") or {}
        page_size = int(data.get("page_size") or 1)
        content_parts = [self._normalize_notice_text(data.get("notice_content"))]

        for page in range(2, page_size + 1):
            page_data = self._eastmoney_notice_page(art_code, page).get("data") or {}
            content_parts.append(self._normalize_notice_text(page_data.get("notice_content")))

        full_text = "\n\n".join(part for part in content_parts if part)
        attachments = self._eastmoney_attachments(data)
        pdf_url = data.get("attach_url_web")
        if pdf_url and not attachments:
            attachments = [
                {
                    "name": "PDF原文",
                    "url": pdf_url,
                    "type": "pdf",
                    "size_kb": self._number_value(data.get("attach_size")),
                }
            ]

        item["content_text"] = full_text or item.get("content_text")
        item["summary"] = self._summary_from_text(full_text) or item.get("summary")
        item["content_html"] = f"<pre>{full_text}</pre>" if full_text else item.get("content_html")
        item["attachments"] = attachments
        item["images"] = []
        item["metadata"] = {
            **(item.get("metadata") or {}),
            "detail_status": "fetched",
            "detail_provider": "eastmoney_notice_api",
            "detail_page_size": page_size,
            "pdf_url": pdf_url,
        }
        return item

    def _enrich_cninfo_disclosure(self, item: dict[str, Any]) -> dict[str, Any]:
        announce_id = self._cninfo_announcement_id(item)
        if not announce_id:
            return self._with_detail_error(item, "missing_announcement_id")

        announce_time = self._cninfo_announce_time(item)
        response = self.client.post(
            "https://www.cninfo.com.cn/new/announcement/bulletin_detail",
            params={
                "announceId": announce_id,
                "flag": "false",
                "announceTime": announce_time,
            },
            headers={"Referer": "https://www.cninfo.com.cn/"},
        )
        response.raise_for_status()
        payload = response.json()
        announcement = payload.get("announcement") or {}
        pdf_url = payload.get("fileUrl")

        content_text = self._normalize_notice_text(announcement.get("announcementContent"))
        if not content_text and pdf_url:
            content_text = self._extract_pdf_text(pdf_url)

        attachments = []
        if pdf_url:
            attachments.append(
                {
                    "name": announcement.get("announcementTitle") or item.get("title") or "公告原文",
                    "url": pdf_url,
                    "type": str(announcement.get("adjunctType") or "PDF").lower(),
                    "size_kb": self._number_value(announcement.get("adjunctSize")),
                }
            )

        item["content_text"] = content_text or item.get("content_text")
        item["summary"] = self._summary_from_text(content_text) or item.get("summary")
        item["attachments"] = attachments
        item["images"] = []
        item["metadata"] = {
            **(item.get("metadata") or {}),
            "detail_status": "fetched",
            "detail_provider": "cninfo_bulletin_detail",
            "pdf_url": pdf_url,
            "announcement_id": announce_id,
            "adjunct_type": announcement.get("adjunctType"),
        }
        return item

    def _eastmoney_notice_page(self, art_code: str, page_index: int) -> dict[str, Any]:
        response = self.client.get(
            "https://np-cnotice-stock.eastmoney.com/api/content/ann",
            params={
                "cb": "callback",
                "art_code": art_code,
                "client_source": "web",
                "page_index": page_index,
            },
            headers={"Referer": "https://data.eastmoney.com/"},
        )
        response.raise_for_status()
        return self._parse_jsonp(response.text)

    @staticmethod
    def _parse_jsonp(body: str) -> dict[str, Any]:
        match = re.search(r"^[^(]+\((.*)\)\s*$", body, re.S)
        if not match:
            raise ValueError("Invalid JSONP payload")
        return json.loads(match.group(1))

    @staticmethod
    def _normalize_notice_text(value: Any) -> str:
        text = str(value or "")
        text = text.replace("\r", "\n")
        text = re.sub(r"\n{3,}", "\n\n", text)
        text = re.sub(r"[ \t]+\n", "\n", text)
        text = re.sub(r"\n[ \t]+", "\n", text)
        return text.strip()

    @staticmethod
    def _summary_from_text(text: str | None, limit: int = 180) -> str | None:
        if not text:
            return None
        normalized = re.sub(r"\s+", " ", text).strip()
        return normalized[:limit] if normalized else None

    @staticmethod
    def _eastmoney_art_code(item: dict[str, Any]) -> str | None:
        for candidate in [item.get("source_item_id"), item.get("canonical_url")]:
            text = str(candidate or "")
            match = re.search(r"(AN\d{10,})", text)
            if match:
                return match.group(1)
        return None

    @staticmethod
    def _eastmoney_attachments(data: dict[str, Any]) -> list[dict[str, Any]]:
        attachments: list[dict[str, Any]] = []
        for attachment in data.get("attach_list") or []:
            attachments.append(
                {
                    "name": "PDF原文" if str(attachment.get("attach_type")) == "0" else "附件",
                    "url": attachment.get("attach_url"),
                    "type": "pdf" if str(attachment.get("attach_type")) == "0" else "file",
                    "size_kb": AnnouncementDetailService._number_value(attachment.get("attach_size")),
                    "seq": attachment.get("seq"),
                }
            )
        return attachments

    @staticmethod
    def _cninfo_announcement_id(item: dict[str, Any]) -> str | None:
        for candidate in [item.get("source_item_id"), item.get("canonical_url")]:
            text = str(candidate or "")
            match = re.search(r"(12\d{8,})", text)
            if match:
                return match.group(1)

        url = str(item.get("canonical_url") or "")
        parsed = parse_qs(urlparse(url).query)
        return (parsed.get("announcementId") or [None])[0]

    @staticmethod
    def _cninfo_announce_time(item: dict[str, Any]) -> str:
        url = str(item.get("canonical_url") or "")
        parsed = parse_qs(urlparse(url).query)
        from_url = (parsed.get("announcementTime") or [None])[0]
        if from_url:
            return str(from_url)

        published_at = item.get("published_at")
        if published_at is not None:
            return str(published_at.date())
        return ""

    def _extract_pdf_text(self, pdf_url: str) -> str:
        response = self.client.get(pdf_url, headers={"Referer": "https://www.cninfo.com.cn/"})
        response.raise_for_status()
        reader = PdfReader(BytesIO(response.content))
        pages: list[str] = []
        for page in reader.pages:
            page_text = page.extract_text() or ""
            page_text = self._normalize_notice_text(page_text)
            if page_text:
                pages.append(page_text)
        return "\n\n".join(pages)

    @staticmethod
    def _number_value(value: Any) -> float | None:
        if value is None or value == "":
            return None
        try:
            return float(value)
        except (TypeError, ValueError):
            return None

    @staticmethod
    def _with_detail_error(item: dict[str, Any], error: str) -> dict[str, Any]:
        item["metadata"] = {
            **(item.get("metadata") or {}),
            "detail_status": "failed",
            "detail_error": error,
        }
        return item
