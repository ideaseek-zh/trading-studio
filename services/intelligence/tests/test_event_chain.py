from __future__ import annotations

import unittest

from app.services.event_chain import EventChainService


class EventChainServiceTest(unittest.TestCase):
    def test_bond_issue_timeline_stage_and_topic_key(self) -> None:
        stage = EventChainService._timeline_stage(
            title="东方财富关于子公司东方财富证券股份有限公司2026年面向专业投资者公开发行次级债券（第二期）发行结果的公告",
            chain_type="bond_issue",
            normalized={},
        )
        self.assertEqual(stage, "issuance_result")

        topic_key = EventChainService._topic_key(
            "东方财富关于子公司东方财富证券股份有限公司2026年面向专业投资者公开发行次级债券（第二期）发行结果的公告",
            "bond_issue",
            {"counterparties": [], "proposal_summary": {}, "date_summary": {}},
            1,
        )
        self.assertIn("2026年面向专业投资者公开发行次级债券", topic_key)

    def test_external_investment_topic_uses_counterparty(self) -> None:
        topic_key = EventChainService._topic_key(
            "关于与专业投资机构共同投资的公告",
            "external_investment",
            {
                "counterparties": [
                    {"name": "上海云锋新创投资管理有限公司"},
                    {"name": "上海麒鹏投资管理有限公司"},
                ],
                "proposal_summary": {},
                "date_summary": {},
            },
            2,
        )
        self.assertIn("上海云锋新创投资管理有限公司", topic_key)
        self.assertIn("上海麒鹏投资管理有限公司", topic_key)


if __name__ == "__main__":
    unittest.main()
