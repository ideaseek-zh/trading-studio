from __future__ import annotations

import unittest

from app.services.announcement_structuring import AnnouncementStructuringService


class AnnouncementStructuringServiceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.service = AnnouncementStructuringService()

    def test_extracts_agenda_amount_subject_date_risk_and_tags(self) -> None:
        content = """
        平安银行股份有限公司董事会决议公告
        本次会议审议通过了《关于回购公司股份方案的议案》。
        公司拟使用2.50亿元人民币实施回购，回购期限为2026年7月3日至2026年12月31日。
        若市场波动加大，方案执行可能存在不确定性。
        """
        result = self.service.extract(
            title="董事会决议公告",
            content_text=content,
            summary="回购方案通过",
            category="disclosure",
            metadata={"security_name": "平安银行", "provider_symbol": "000001"},
        )

        self.assertEqual(result.primary_event_tag, "board_resolution")
        self.assertIn("buyback", result.event_tags)
        self.assertEqual(result.structured_data["agenda_items"][0]["title"], "关于回购公司股份方案的议案")
        self.assertEqual(result.structured_data["amount_mentions"][0]["text"], "2.50亿元人民币")
        self.assertEqual(result.structured_data["subjects"][0]["name"], "平安银行")
        self.assertEqual(result.structured_data["date_mentions"][0]["normalized"], "2026-07-03")
        self.assertEqual(result.structured_data["risk_flags"][0]["type"], "market_risk")

    def test_falls_back_to_announcement_tag(self) -> None:
        result = self.service.extract(
            title="一般公告",
            content_text="这是一个普通公告文本。",
            summary=None,
            category="notice",
            metadata={},
        )

        self.assertEqual(result.event_tags, ["announcement"])


if __name__ == "__main__":
    unittest.main()
