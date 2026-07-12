from __future__ import annotations

import unittest

from app.services.announcement_detail import AnnouncementDetailService


class AnnouncementDetailServiceTest(unittest.TestCase):
    def test_parse_jsonp(self) -> None:
        payload = AnnouncementDetailService._parse_jsonp('callback({"data":{"notice_title":"示例公告"}})')
        self.assertEqual(payload["data"]["notice_title"], "示例公告")

    def test_extract_eastmoney_art_code(self) -> None:
        item = {
            "source_item_id": "AN202607121826910709",
            "canonical_url": "https://data.eastmoney.com/notices/detail/002311/AN202607121826910709.html",
        }
        self.assertEqual(
            AnnouncementDetailService._eastmoney_art_code(item),
            "AN202607121826910709",
        )

    def test_extract_cninfo_announcement_id_and_time(self) -> None:
        item = {
            "canonical_url": (
                "http://www.cninfo.com.cn/new/disclosure/detail?"
                "stockCode=000001&announcementId=1225406051&orgId=gssz0000001&announcementTime=2026-07-03"
            ),
        }
        self.assertEqual(AnnouncementDetailService._cninfo_announcement_id(item), "1225406051")
        self.assertEqual(AnnouncementDetailService._cninfo_announce_time(item), "2026-07-03")

    def test_normalize_notice_text(self) -> None:
        text = AnnouncementDetailService._normalize_notice_text("A\r\n\r\n\r\nB  \n   C")
        self.assertEqual(text, "A\n\nB\nC")

    def test_summary_from_text(self) -> None:
        summary = AnnouncementDetailService._summary_from_text("  第一段 \n 第二段  ", limit=6)
        self.assertEqual(summary, "第一段 第二")


if __name__ == "__main__":
    unittest.main()
