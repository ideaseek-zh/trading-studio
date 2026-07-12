from __future__ import annotations

from dataclasses import dataclass
import re
from typing import Any


@dataclass(slots=True)
class NormalizationResult:
    normalized: dict[str, Any]
    event_type: str
    primary_event_tag: str


class AnnouncementNormalizationService:
    version = "announcement-normalize-v2"

    def normalize(
        self,
        *,
        title: str,
        structured_data: dict[str, Any],
        metadata: dict[str, Any] | None,
    ) -> NormalizationResult:
        metadata = metadata or {}
        event_tags = list(structured_data.get("event_tags") or [])
        primary_event_tag = self._primary_event_tag(title=title, event_tags=event_tags)
        normalized_subjects = self._normalized_subjects(
            subjects=structured_data.get("subjects") or [],
            metadata=metadata,
            primary_event_tag=primary_event_tag,
        )
        normalized_agenda = self._normalized_agenda(
            agenda_items=structured_data.get("agenda_items") or [],
            primary_event_tag=primary_event_tag,
        )
        amount_summary = self._amount_summary(
            amount_mentions=structured_data.get("amount_mentions") or [],
            primary_event_tag=primary_event_tag,
        )
        date_summary = self._date_summary(
            date_mentions=structured_data.get("date_mentions") or [],
            primary_event_tag=primary_event_tag,
        )
        risk_summary = self._risk_summary(
            risk_flags=structured_data.get("risk_flags") or [],
        )

        normalized = {
            "event_code": primary_event_tag,
            "event_type": self._event_type(primary_event_tag),
            "issuer": normalized_subjects["issuer"],
            "counterparties": normalized_subjects["counterparties"],
            "participants": normalized_subjects["participants"],
            "regulators": normalized_subjects["regulators"],
            "proposal_summary": normalized_agenda,
            "amount_summary": amount_summary,
            "date_summary": date_summary,
            "risk_summary": risk_summary,
            "version": self.version,
        }

        return NormalizationResult(
            normalized=normalized,
            event_type=normalized["event_type"],
            primary_event_tag=primary_event_tag,
        )

    def _primary_event_tag(self, *, title: str, event_tags: list[str]) -> str:
        priority = [
            "external_investment",
            "bond_issue",
            "buyback",
            "share_increase",
            "share_reduction",
            "guarantee",
            "litigation",
            "regulatory",
            "contract",
            "merger_restructuring",
            "board_resolution",
            "shareholder_meeting",
            "investor_relations",
            "earnings_forecast",
            "esg",
            "announcement",
        ]
        for tag in priority:
            if tag in event_tags:
                return tag
        if "董事会决议" in title:
            return "board_resolution"
        return event_tags[0] if event_tags else "announcement"

    def _normalized_subjects(
        self,
        *,
        subjects: list[dict[str, Any]],
        metadata: dict[str, Any],
        primary_event_tag: str,
    ) -> dict[str, Any]:
        issuer_name = str(metadata.get("security_name") or "").strip() or None
        issuer_symbol = str(metadata.get("provider_symbol") or "").strip() or None

        issuer = {
            "name": issuer_name,
            "symbol": issuer_symbol,
            "entity_type": "listed_company" if issuer_name else None,
        }

        counterparties: list[dict[str, Any]] = []
        participants: list[dict[str, Any]] = []
        regulators: list[dict[str, Any]] = []
        seen: set[str] = set()

        for subject in subjects:
            entity_type = str(subject.get("type") or "")
            for name in self._expand_subject_names(str(subject.get("name") or ""), entity_type=entity_type):
                if not name or name in seen:
                    continue
                if len(name) < 2 or name.isdigit():
                    continue
                if self._is_noise_subject(name):
                    continue
                seen.add(name)

                role = self._subject_role(
                    name=name,
                    entity_type=entity_type,
                    primary_event_tag=primary_event_tag,
                    issuer_name=issuer_name,
                )
                payload = {
                    "name": name,
                    "entity_type": self._normalized_entity_type(name=name, source_type=entity_type),
                    "role": role,
                }
                if role == "counterparty":
                    counterparties.append(payload)
                elif role == "regulator":
                    regulators.append(payload)
                else:
                    participants.append(payload)

        return {
            "issuer": issuer,
            "counterparties": counterparties[:10],
            "participants": participants[:10],
            "regulators": regulators[:10],
        }

    def _normalized_agenda(
        self,
        *,
        agenda_items: list[dict[str, Any]],
        primary_event_tag: str,
    ) -> dict[str, Any]:
        proposal_types = []
        normalized_items = []
        for item in agenda_items:
            title = str(item.get("title") or "").strip()
            proposal_type = self._proposal_type(title, primary_event_tag)
            if proposal_type not in proposal_types:
                proposal_types.append(proposal_type)
            normalized_items.append(
                {
                    "title": title,
                    "action": item.get("action"),
                    "sequence": item.get("sequence"),
                    "proposal_type": proposal_type,
                }
            )

        if not normalized_items and primary_event_tag == "board_resolution":
            proposal_types.append("board_resolution")

        return {
            "proposal_types": proposal_types,
            "items": normalized_items,
        }

    def _amount_summary(
        self,
        *,
        amount_mentions: list[dict[str, Any]],
        primary_event_tag: str,
    ) -> dict[str, Any]:
        if not amount_mentions:
            return {
                "primary_amount": None,
                "amounts": [],
            }

        sorted_amounts = sorted(
            amount_mentions,
            key=lambda item: float(item.get("numeric_value") or 0),
            reverse=True,
        )
        primary_amount = sorted_amounts[0]

        return {
            "primary_amount": {
                "text": primary_amount.get("text"),
                "numeric_value": primary_amount.get("numeric_value"),
                "currency": primary_amount.get("currency"),
                "unit": primary_amount.get("unit"),
                "semantic_type": self._amount_semantic_type(primary_event_tag),
            },
            "amounts": sorted_amounts[:10],
        }

    def _date_summary(
        self,
        *,
        date_mentions: list[dict[str, Any]],
        primary_event_tag: str,
    ) -> dict[str, Any]:
        normalized_dates = [
            item["normalized"]
            for item in date_mentions
            if item.get("normalized")
        ]
        unique_dates = list(dict.fromkeys(normalized_dates))

        return {
            "decision_date": unique_dates[0] if unique_dates else None,
            "effective_date": unique_dates[1] if len(unique_dates) > 1 else None,
            "all_dates": unique_dates[:10],
            "semantic_type": self._date_semantic_type(primary_event_tag),
        }

    def _risk_summary(self, *, risk_flags: list[dict[str, Any]]) -> dict[str, Any]:
        risk_level = "none"
        if risk_flags:
            risk_level = "medium"
        if any(flag.get("type") in {"regulatory_risk", "financial_risk"} for flag in risk_flags):
            risk_level = "high"

        grouped: dict[str, int] = {}
        for flag in risk_flags:
            risk_type = str(flag.get("type") or "unknown")
            grouped[risk_type] = grouped.get(risk_type, 0) + 1

        return {
            "risk_level": risk_level,
            "risk_count": len(risk_flags),
            "risk_breakdown": grouped,
            "flags": risk_flags[:10],
        }

    @staticmethod
    def _event_type(primary_event_tag: str) -> str:
        mapping = {
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
        return mapping.get(primary_event_tag, "disclosure")

    @staticmethod
    def _proposal_type(title: str, primary_event_tag: str) -> str:
        rule_table = [
            ("buyback_plan", ["回购"]),
            ("investment_plan", ["投资", "共同投资", "设立"]),
            ("bond_issue_plan", ["债券", "融资券", "次级债"]),
            ("esg_policy", ["ESG"]),
            ("governance_rule", ["修订", "章程", "守则", "工作细则"]),
        ]
        for proposal_type, keywords in rule_table:
            if any(keyword in title for keyword in keywords):
                return proposal_type
        return primary_event_tag

    @staticmethod
    def _subject_role(
        name: str,
        entity_type: str,
        primary_event_tag: str,
        issuer_name: str | None,
    ) -> str:
        if name == "中国证监会" or any(keyword in name for keyword in ["证监会", "交易所", "监管"]):
            return "regulator"
        if issuer_name and name == issuer_name:
            return "participant"
        if primary_event_tag == "investor_relations" and any(
            keyword in name for keyword in ["基金", "投资", "证券", "私募", "资管"]
        ):
            return "counterparty"
        if primary_event_tag == "external_investment" and any(
            keyword in name for keyword in ["基金", "合伙企业", "投资", "管理有限公司", "私募"]
        ):
            return "counterparty"
        if primary_event_tag == "bond_issue" and "投资者" in name:
            return "counterparty"
        if entity_type == "security":
            return "participant"
        return "participant"

    @staticmethod
    def _amount_semantic_type(primary_event_tag: str) -> str:
        mapping = {
            "external_investment": "investment_amount",
            "bond_issue": "issue_amount",
            "buyback": "buyback_amount",
            "contract": "contract_amount",
        }
        return mapping.get(primary_event_tag, "amount")

    @staticmethod
    def _date_semantic_type(primary_event_tag: str) -> str:
        mapping = {
            "board_resolution": "decision_timeline",
            "external_investment": "transaction_timeline",
            "bond_issue": "issuance_timeline",
        }
        return mapping.get(primary_event_tag, "announcement_timeline")

    def _expand_subject_names(self, raw_name: str, *, entity_type: str) -> list[str]:
        name = self._clean_subject_text(raw_name)
        if not name:
            return []
        if entity_type in {"security", "security_symbol"}:
            return [name]

        matches: list[str] = []
        patterns = [
            r"(中国证券监督管理委员会|中国证监会|深圳证券交易所|上海证券交易所|北京证券交易所)",
            r"([A-Za-z0-9\u4e00-\u9fff（）()·\-]{2,40}?(?:股份有限公司|有限责任公司|证券股份有限公司|证券有限责任公司|基金管理有限公司|投资管理有限公司|私募基金|合伙企业|证券|银行|基金|交易所|集团|委员会|事务所|管理有限公司|管理人))",
        ]
        for pattern in patterns:
            for match in re.findall(pattern, name):
                candidate = self._clean_subject_text(match)
                if candidate and candidate not in matches:
                    matches.append(candidate)

        if not matches and self._looks_like_entity_name(name):
            matches.append(name)

        normalized = [self._canonicalize_subject_name(item) for item in matches]
        unique = [item for item in dict.fromkeys(normalized) if item and not self._is_noise_subject(item)]
        return [
            item
            for item in unique
            if not any(item != other and item in other for other in unique)
        ]

    @staticmethod
    def _normalized_entity_type(name: str, source_type: str) -> str:
        if source_type in {"security", "security_symbol"}:
            return source_type
        if any(keyword in name for keyword in ["证监会", "交易所", "委员会"]):
            return "regulator"
        return "organization"

    @staticmethod
    def _clean_subject_text(value: str) -> str:
        text = re.sub(r"\s+", "", value)
        text = re.sub(r"^[：:;；，,。.、“”\"'（）()\-\d年月日]+", "", text)
        text = re.sub(r"[：:;；，,。.、“”\"']+$", "", text)
        text = re.sub(r"^(关于|根据|本次|详情见公司|公告编号|同意|审议通过|审议)", "", text)
        text = re.sub(r"^(在|于|向|与|及|将|对)", "", text)
        text = re.sub(r"^(子公司)+", "", text)
        text = re.sub(r"^(人的|其他参与设立)", "", text)
        text = re.sub(r"^(募集资金用于补充|用于补充)", "", text)
        return text.strip()

    @staticmethod
    def _canonicalize_subject_name(name: str) -> str:
        aliases = {
            "中国证券监督管理委员会": "中国证监会",
        }
        return aliases.get(name, name)

    @staticmethod
    def _looks_like_entity_name(name: str) -> bool:
        if len(name) < 3 or len(name) > 40:
            return False
        keywords = [
            "公司",
            "银行",
            "证券",
            "基金",
            "合伙企业",
            "交易所",
            "集团",
            "委员会",
            "事务所",
            "管理有限公司",
            "管理人",
        ]
        return any(keyword in name for keyword in keywords)

    @staticmethod
    def _is_noise_subject(name: str) -> bool:
        noise_keywords = [
            "关于",
            "请问",
            "感谢",
            "万元",
            "亿元",
            "投资事项",
            "无需提交",
            "认购本期债券的投资者",
            "上市公司",
            "专业投资者",
            "投资者",
            "公司章程",
            "董事会专门委员会",
            "副总经理",
            "介绍了公司",
            "用于补充",
            "详情见公司",
            "募集资金",
            "本期债券",
            "交易所债券市场投资者",
            "交易所公司",
            "高级管理人",
            "持有公司",
            "与公司及公司",
            "投资基金",
            "私募基金",
            "其他参与设立合伙企业",
        ]
        if any(keyword in name for keyword in noise_keywords):
            return True
        if name in {"有限公司", "股份有限公司", "有限责任公司", "证券股份有限公司", "私募基金管理人", "合伙企业", "管理人"}:
            return True
        if name in {"中国证券", "深圳证券", "上海证券", "北京证券", "监督管理委员会"}:
            return True
        if "证监会" in name and name != "中国证监会":
            return True
        if re.search(r"\d{4}年\d{1,2}月\d{1,2}日", name):
            return True
        if any(keyword in name for keyword in ["介绍", "感谢", "请问", "修订", "规定", "不得从事"]):
            return True
        if len(name) > 28 and not any(keyword in name for keyword in ["公司", "银行", "证券", "基金", "集团"]):
            return True
        return False
