# ideaseek-trading-studio

Trading Studio 是一个面向中国股票交易者的事件驱动型投研平台，不做模拟数据演示，不做自动交易终端，重点建设：

- A 股股票主数据、行情、新闻、公告、事件的统一数据底座
- 基于事实引用的 AI 分析与问答
- 面向个人投资者与轻量投研团队的可运行 SaaS 平台

## 仓库结构

```text
apps/
  api/            Laravel 10 业务后端
  web/            Nuxt 4 前端
services/
  intelligence/   FastAPI 数据与 AI 服务
scripts/          本地安装与启动脚本
doc/              产品、接口、数据库与运维文档
```

## 当前开发阶段

当前已完成三个基础模块：

- 模块 00：工程基线与运行基线
- 模块 01：股票主数据模块
- 模块 02：行情接入模块
- 模块 03：新闻源接入模块
- 模块 03.1：公告全文解析与附件抽取
- 模块 03.2：公告结构化抽取
- 模块 03.3：公告实体归一化与事件事实标准化
- 模块 03.4：事件聚合与时间线标准化
- 模块 04：事件评分与信号引擎
- 模块 04.1：信号解释与回测基线

这十部分已经把真实代码、真实数据库结构、真实同步链路、新闻事件抽取、公告全文解析、公告结构化抽取、事件事实标准化、事件链聚合、交易信号生成、信号解释与回测基线和基础缓存层串起来，为后续 AI 模块铺路。

## 技术栈

- PHP 8.1 + Laravel 10
- Python 3.12 + FastAPI
- Node 24 LTS + Nuxt 4
- MySQL 8
- Redis 7
- Qdrant

## 非 Docker 本地安装

### 1. 环境要求

- PHP `8.1+`
- Composer `2.8+`
- Python `3.12+`
- Node `24.11+`
- MySQL `8.0+`
- Redis `7+`

### 2. 创建数据库

```bash
mysql -u root -p < scripts/setup-mysql.sql
```

### 3. 初始化

```bash
cp .env.example .env
./scripts/bootstrap-local.sh
```

### 4. 启动服务

终端 1：

```bash
./scripts/run-api.sh
```

终端 2：

```bash
./scripts/run-intelligence.sh
```

终端 3：

```bash
./scripts/run-web.sh
```

## Docker Compose 安装

```bash
cp .env.example .env
docker compose up --build
```

## 访问地址

- Web：`http://localhost:3000`
- Laravel API：`http://localhost:8000/api/v1/health`
- FastAPI Intelligence：`http://localhost:8080/internal/v1/health`

## 数据同步命令

当 MySQL、Laravel API、FastAPI Intelligence 都启动后，执行：

```bash
cd apps/api
php artisan market:sync-securities
php artisan market:sync-quotes 000001 600000
php artisan market:sync-daily-bars 000001 --start=2025-01-01 --end=2026-07-12
php artisan market:sync-indices
php artisan market:sync-index-daily-bars sh000001 --start=2025-01-01 --end=2026-07-12
```

系统会调用 FastAPI 的真实同步接口，并通过 AKShare 把主数据、行情快照、日线和指数数据写入 MySQL。

新闻模块同步命令：

```bash
cd apps/api
php artisan news:sync-sources
php artisan news:sync --scope=global --scope=notice --limit=20
php artisan news:sync --scope=stock --symbols=300059,000001 --limit=20
php artisan news:sync --scope=disclosure --symbols=300059,000001 --start=2026-07-01 --end=2026-07-12 --limit=20
php artisan news:rebuild-event-chains
php artisan signals:rebuild
php artisan signals:evaluate
php artisan signals:dispatch --limit=100
```

系统会抓取真实新闻和公告源，完成去重、质量校验、证券关联与事件生成，并写入 MySQL。

公告全文解析说明：

- 东方财富公告源会通过详情接口抓取分页正文，并提取 PDF 附件链接
- 巨潮资讯公告源会通过公告明细接口获取 PDF，再本地提取正文文本并保存附件信息
- 公告详情字段会写入 `news_article_contents.content_text` 与 `attachments`

公告结构化抽取说明：

- 同步链路会对公告正文自动抽取 `agenda_items`、`amount_mentions`、`subjects`、`date_mentions`、`risk_flags`、`event_tags`
- 结构化结果会写入 `news_article_contents.structured_data`
- 事件表 `events.facts` 会同步保留公告结构化结果，便于后续 AI 分析与引用

公告实体归一化与事件事实标准化说明：

- 同步链路会基于公告结构化结果继续生成 `normalized` 事实层，统一输出发行主体、参与方、交易对手、监管方、金额摘要、日期摘要、风险摘要
- 归一化结果会写入 `news_article_contents.structured_data.normalized`
- 事件表 `events.facts.normalized_data` 会保留同一份标准化事实，供事件查询、规则引擎和 AI 分析共用
- 归一化过程会尽量清洗正文里混入的噪声短语，保留更接近业务实体的名称

事件聚合与时间线标准化说明：

- 同步链路会基于 `events` 自动归并 `event_chains`，把同一公司、同一主题的多条公告和新闻串成事件链
- 事件表会补充 `event_chain_id`、`timeline_stage`、`timeline_order`，支持事件阶段和时间线排序
- 事件详情接口会返回所属事件链摘要，事件链详情接口会返回完整时间线
- 历史事件可通过 `php artisan news:rebuild-event-chains` 一键重建事件链

事件评分与信号引擎说明：

- Intelligence 服务会基于 `event_chains`、`events` 和 `signal_rules` 自动生成 `trading_signals`
- 当前内置信号规则覆盖 `buyback`、`external_investment`、`bond_issue`、`regulatory`、`investor_relations` 与通用高重要度事件
- 信号评分会综合事件类型、时间线阶段、重要度、风险等级、文章数、事件数和时效性，产出 `signal_score`、`impact_score`、`urgency_score`、`confidence_score`、`risk_score`
- API 层支持 `signal_subscriptions` 和 `signal_deliveries`，可通过 webhook 订阅高分信号
- `signals:dispatch` 命令会先补齐待投递记录，再按重试策略分发 webhook

信号解释与回测基线说明：

- 每条信号会补充 `explanation` 因子面板，展示影响力、时效性、确定性、风险折减四类可解释因子及其贡献
- 每条信号会生成 `signal_performance_snapshots`，按 `1/3/5/10/20` 个交易日计算事后表现基线
- 回测快照会输出 `return_pct`、`benchmark_return_pct`、`alpha_return_pct`、`max_upside_pct`、`max_drawdown_pct` 等指标
- 当个股或基准指数日线缺失时，快照会保留 `no_market_data` 或 `pending` 状态，而不是伪造评估结果
- `signals:evaluate` 命令可单独刷新解释面板与表现快照

## 当前模块说明

当前第一阶段已经落地：

- 工程基线
- 双安装模式
- 股票主数据表结构
- 股票列表与搜索接口
- FastAPI 主数据同步服务
- 股票快照、日线、指数快照、指数日线表结构
- 行情与指数读取接口
- Laravel 缓存层与缺数据自动回源逻辑
- FastAPI 行情同步服务
- 新闻源配置、抓取、去重、质量校验、证券关联与事件生成
- 公告全文解析与 PDF 附件抽取
- 公告结构化抽取与事件标签生成
- 公告实体归一化与事件事实标准化
- 事件聚合与时间线标准化
- 事件评分与信号引擎
- 信号解释与回测基线
- 新闻详情与事件详情读取接口
- 事件链列表与事件链详情接口
- 信号列表、信号详情、订阅与 webhook 投递接口
- 前端首页真实状态联调

当前阶段尚未完成：

- AI 工作流

这些会按模块继续推进，而不是混成一个不可维护的大 Demo。

## 首批目标

- 建立企业级可维护目录结构
- 保持 Docker 与非 Docker 双运行方式
- 落地股票主数据表、查询接口、同步命令和同步服务
- 不引入模拟样本，不为了展示而写假数据
