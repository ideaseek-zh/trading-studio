# Trading Studio AI Prompt 规范

## 1. 目的

本文档定义 Trading Studio 中与 LLM 相关的 Prompt 设计、版本管理、结构化输出、安全约束和评测要求，确保 AI 功能可控、可审计、可回归。

## 2. 适用范围

- 新闻摘要
- 公告解读
- 事件抽取
- 个股分析
- 股票对比
- 自选股简报
- RAG 问答

## 3. 总体原则

### 3.1 事实优先

- 所有分析必须优先使用平台提供的结构化数据和原文片段
- 未获取到事实时必须明确说明“数据不足”
- 禁止凭空补全数字、时间和事件关系

### 3.2 可追溯

- 输出必须包含引用来源
- 输出必须标注数据时间或事件时间
- 长回答必须拆成“结论、依据、风险、引用”结构

### 3.3 合规克制

- 不输出“必涨”“强烈买入”“目标价”等直接投资建议
- 不承诺收益和胜率
- 不使用绝对确定性措辞替代概率表达

### 3.4 结构化优先

- 优先使用 JSON Schema 输出
- 业务侧必须对结构化结果做 schema 校验
- 校验失败最多自动修复一次，仍失败则降级为人工可读文本并标记异常

## 4. Prompt 模板结构

每个 Prompt 模板必须具备以下字段：

| 字段 | 说明 |
| --- | --- |
| `prompt_key` | 模板唯一标识 |
| `version` | 版本号，如 `v1.0.0` |
| `task_type` | 任务类型 |
| `system_prompt` | 系统提示词 |
| `user_template` | 用户侧模板 |
| `input_contract` | 输入字段定义 |
| `output_schema` | 输出 JSON Schema |
| `model_config` | 模型参数和路由建议 |
| `safety_rules` | 安全与合规规则 |
| `test_cases` | 回归样例 |
| `evaluation_score` | 当前评测分数 |

## 5. 统一 System Prompt 约束

```text
你是 Trading Studio 的财经信息分析助手。
你只能根据提供的数据、事件、公告、新闻和知识库片段进行分析。
如果事实不足，请明确指出“数据不足，无法得出可靠结论”。
你必须区分事实、推断和不确定性。
你不能提供直接投资建议、目标价、收益承诺和确定性涨跌判断。
你输出的每个核心结论都必须可以追溯到输入中的数据或引用片段。
```

## 6. 输入规范

### 6.1 输入信封

所有任务都应使用统一信封结构：

```json
{
  "task_id": "uuid",
  "task_type": "analyze_stock",
  "locale": "zh-CN",
  "user_tier": "pro",
  "as_of": "2026-07-12T09:00:00+08:00",
  "question": "东山精密最近为什么上涨？",
  "context": {},
  "citations": []
}
```

### 6.2 上下文限制

- 上下文优先包含结构化数据，而不是大段原文
- 原文引用总长度需受控，避免噪音和版权风险
- 模型不得直接访问数据库，只能读取工具返回数据

## 7. 输出规范

### 7.1 通用输出结构

```json
{
  "summary": "string",
  "key_points": ["string"],
  "evidence": [
    {
      "type": "news|announcement|market|event",
      "source_id": "string",
      "title": "string",
      "published_at": "2026-07-12T08:30:00+08:00",
      "reason": "string"
    }
  ],
  "uncertainties": ["string"],
  "risk_disclaimer": "信息仅供参考，不构成投资建议。"
}
```

### 7.2 结果要求

- 结论摘要不超过 120 字
- 关键依据至少 2 条，最多 6 条
- 必须包含不确定因素
- 风险声明必须固定输出

## 8. 任务级 Prompt 规范

### 8.1 新闻摘要

目标：在不改变事实的前提下，将新闻压缩为可读摘要。

附加要求：

- 提取主体、事件、时间、影响对象
- 不补充新闻未出现的结论
- 如果新闻为转载或重复内容，提示“信息可能来自二次传播”

推荐输出字段：

- `summary`
- `entities`
- `event_type`
- `importance_level`
- `market_scope`

### 8.2 公告解读

目标：提取关键数字、事项类型、潜在影响、后续节点和风险。

附加要求：

- 关键数字必须来自原文
- 对影响判断使用“偏正面/偏负面/中性/不确定”
- 需要拆分“事实”和“推断”

示例输出：

```json
{
  "summary": "公司披露 2026H1 业绩预告，净利润同比增长 80%-110%。",
  "event_type": "earnings_forecast",
  "key_facts": [
    {
      "label": "净利润同比增长区间",
      "value": "80%-110%",
      "source_quote": "预计 2026 年半年度归属于上市公司股东的净利润同比增长 80%-110%。"
    }
  ],
  "impact": {
    "direction": "positive",
    "strength": 4,
    "time_horizon": "short",
    "reasoning": [
      "业绩增长预告通常改善短期市场预期。"
    ]
  },
  "risks": [
    "预告数据仍可能在正式财报披露前调整。"
  ],
  "follow_up_dates": ["2026-08-30"],
  "confidence": 0.84
}
```

### 8.3 事件抽取

目标：把一条或多条新闻/公告抽取为结构化事件。

附加要求：

- 必须给出 `event_type`
- `occurred_at` 与 `detected_at` 分开
- `entities` 必须带角色，如 `subject`、`counterparty`、`sector`

### 8.4 个股分析

目标：解释某只股票近期波动原因。

附加要求：

- 至少结合行情、新闻、公告、事件中的两类数据
- 区分短期催化与中期逻辑
- 不能把相关性强行表述成因果已证实

推荐输出字段：

- `summary`
- `recent_drivers`
- `supporting_data`
- `counter_signals`
- `watch_points`

### 8.5 股票对比

目标：比较两只股票在业务、事件、走势和风险上的差异。

附加要求：

- 输出必须对称，避免只分析一边
- 每个比较维度都要给出依据来源
- 如果数据时间不一致，必须明确提示

### 8.6 自选股简报

目标：为用户生成盘前/盘后自选股简报。

附加要求：

- 按重要性排序
- 同类事件合并
- 输出要区分“重点关注”和“仅供浏览”

## 9. 工具调用规范

允许模型使用的工具：

- `search_stock`
- `get_stock_snapshot`
- `get_daily_bars`
- `get_financial_summary`
- `get_money_flow`
- `get_stock_news`
- `get_stock_announcements`
- `get_stock_events`
- `get_sector_performance`
- `compare_stocks`
- `search_knowledge`

约束：

- 模型不能自由拼接 SQL
- 单次任务工具调用次数应有上限
- 工具结果必须进入日志与审计记录

## 10. 版本管理

### 10.1 发布流程

1. 创建新版本 Prompt
2. 绑定测试样例
3. 执行自动评测
4. 小流量灰度
5. 观察成本、成功率、用户反馈
6. 手动批准上线

### 10.2 回滚条件

- JSON 校验失败率超阈值
- 幻觉反馈率升高
- 首 Token 延迟和总耗时显著恶化
- 用户差评或人工抽检不达标

## 11. 评测规范

### 11.1 必测维度

- 事实准确性
- 引用完整性
- 输出结构合法性
- 投资建议风险控制
- 对“数据不足”的处理
- 对冲突信息的处理

### 11.2 指标建议

| 指标 | 目标 |
| --- | --- |
| Schema 通过率 | >= 98% |
| 引用覆盖率 | >= 90% |
| 幻觉反馈率 | <= 2% |
| 合规拦截准确率 | >= 95% |

## 12. 安全规范

- 输入侧检查 Prompt 注入、越权查询和恶意批量请求
- 输出侧检查虚假确定性、目标价、直接买卖建议和个人隐私
- 失败任务必须保留原始输入、模型输出和校验日志

## 13. 示例 Prompt Key

| Prompt Key | 用途 |
| --- | --- |
| `news.summary` | 新闻摘要 |
| `announcement.interpret` | 公告解读 |
| `event.extract` | 事件抽取 |
| `stock.analyze` | 个股分析 |
| `stock.compare` | 股票对比 |
| `watchlist.briefing` | 自选股简报 |
| `qa.market` | 市场问答 |

