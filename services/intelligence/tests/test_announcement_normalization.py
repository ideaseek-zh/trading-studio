from __future__ import annotations

import unittest

from app.services.announcement_normalization import AnnouncementNormalizationService


class AnnouncementNormalizationServiceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.service = AnnouncementNormalizationService()

    def test_normalizes_external_investment_facts(self) -> None:
        structured_data = {
            "event_tags": ["external_investment", "board_resolution"],
            "agenda_items": [
                {"title": "关于参与投资私募基金的议案", "action": "审议通过", "sequence": 1},
            ],
            "amount_mentions": [
                {"text": "2亿元人民币", "numeric_value": 200000000, "currency": "CNY", "unit": "亿元人民币"},
                {"text": "30亿元", "numeric_value": 3000000000, "currency": "CNY", "unit": "亿元"},
            ],
            "subjects": [
                {"name": "东方财富", "type": "security", "source": "metadata"},
                {"name": "300059", "type": "security_symbol", "source": "metadata"},
                {"name": "上海云锋元创私募基金", "type": "organization", "source": "content"},
                {"name": "深圳证券交易所", "type": "organization", "source": "content"},
            ],
            "date_mentions": [
                {"text": "2026年7月4日", "normalized": "2026-07-04"},
                {"text": "2026年12月31日", "normalized": "2026-12-31"},
            ],
            "risk_flags": [
                {"type": "market_risk", "keyword": "风险", "context": "存在投资风险"},
                {"type": "regulatory_risk", "keyword": "监管", "context": "需符合监管要求"},
            ],
        }

        result = self.service.normalize(
            title="关于与专业投资机构共同投资的公告",
            structured_data=structured_data,
            metadata={"security_name": "东方财富", "provider_symbol": "300059"},
        )

        self.assertEqual(result.primary_event_tag, "external_investment")
        self.assertEqual(result.event_type, "external_investment")
        self.assertEqual(result.normalized["issuer"]["name"], "东方财富")
        self.assertEqual(result.normalized["amount_summary"]["primary_amount"]["semantic_type"], "investment_amount")
        self.assertEqual(result.normalized["proposal_summary"]["proposal_types"][0], "investment_plan")
        self.assertEqual(result.normalized["date_summary"]["decision_date"], "2026-07-04")
        self.assertEqual(result.normalized["risk_summary"]["risk_level"], "high")

    def test_prioritizes_board_resolution_when_only_board_tag_exists(self) -> None:
        result = self.service.normalize(
            title="董事会决议公告",
            structured_data={"event_tags": ["board_resolution"], "agenda_items": [], "amount_mentions": [], "subjects": [], "date_mentions": [], "risk_flags": []},
            metadata={},
        )

        self.assertEqual(result.event_type, "board_resolution")

    def test_filters_noise_entities_and_normalizes_roles(self) -> None:
        structured_data = {
            "event_tags": ["bond_issue"],
            "agenda_items": [],
            "amount_mentions": [
                {"text": "20亿元", "numeric_value": 2000000000, "currency": "CNY", "unit": "亿元"},
            ],
            "subjects": [
                {"name": "东方财富", "type": "security", "source": "metadata"},
                {"name": "300059", "type": "security_symbol", "source": "metadata"},
                {"name": "中国证券监督管理委员会同意东方财富证券向专业投资者", "type": "organization", "source": "content"},
                {"name": "日在深圳证券交易所", "type": "organization", "source": "content"},
                {"name": "募集资金用于补充东方财富证券", "type": "organization", "source": "content"},
                {"name": "认购本期债券的投资者", "type": "organization", "source": "content"},
                {"name": "有限公司", "type": "organization", "source": "content"},
            ],
            "date_mentions": [],
            "risk_flags": [],
        }

        result = self.service.normalize(
            title="关于子公司东方财富证券股份有限公司公开发行债券的公告",
            structured_data=structured_data,
            metadata={"security_name": "东方财富", "provider_symbol": "300059"},
        )

        self.assertEqual(result.normalized["version"], "announcement-normalize-v2")
        self.assertIn(
            {"name": "中国证监会", "entity_type": "regulator", "role": "regulator"},
            result.normalized["regulators"],
        )
        self.assertIn(
            {"name": "深圳证券交易所", "entity_type": "regulator", "role": "regulator"},
            result.normalized["regulators"],
        )
        participant_names = [item["name"] for item in result.normalized["participants"]]
        regulator_names = [item["name"] for item in result.normalized["regulators"]]
        self.assertIn("东方财富证券", participant_names)
        self.assertNotIn("有限公司", participant_names)
        self.assertNotIn("认购本期债券的投资者", participant_names)
        self.assertNotIn("中国证监会同意东方财富证券", regulator_names)
        self.assertNotIn("监督管理委员会", regulator_names)
        self.assertEqual(result.normalized["amount_summary"]["primary_amount"]["semantic_type"], "issue_amount")


if __name__ == "__main__":
    unittest.main()
