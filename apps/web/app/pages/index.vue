<script setup lang="ts">
type HealthResponse = {
  code: number
  message: string
  data: {
    name: string
    environment: string
    services: Record<string, string>
  }
  meta: {
    timestamp: string
  }
}

type SecurityItem = {
  id: number
  canonical_symbol: string
  symbol: string
  exchange: string
  market: string
  security_type: string
  name: string
  short_name: string | null
}

type SecurityResponse = {
  code: number
  message: string
  data: SecurityItem[]
  meta: {
    total: number
  }
}

type IndexItem = {
  id: number
  code: string
  name: string
  exchange: string | null
  last_price: string | number | null
  pct_change: string | number | null
}

type IndexResponse = {
  code: number
  message: string
  data: IndexItem[]
}

type NewsItem = {
  id: number
  title: string
  summary: string | null
  category: string | null
  importance_level: string
  quality_status: string
  published_at: string | null
  source: {
    source_key: string
    source_name: string
  } | null
}

type NewsResponse = {
  code: number
  message: string
  data: NewsItem[]
}

type EventItem = {
  id: number
  event_type: string
  title: string
  importance_level: string
  status: string
  occurred_at: string | null
}

type EventResponse = {
  code: number
  message: string
  data: EventItem[]
}

const config = useRuntimeConfig()
const apiBase = config.public.apiBase

const health = ref<HealthResponse | null>(null)
const securities = ref<SecurityItem[]>([])
const indices = ref<IndexItem[]>([])
const news = ref<NewsItem[]>([])
const events = ref<EventItem[]>([])
const totalSecurities = ref(0)
const loading = ref(true)
const loadError = ref('')

const loadDashboard = async () => {
  loading.value = true
  loadError.value = ''

  try {
    const [healthData, securityData, indexData, newsData, eventData] = await Promise.all([
      $fetch<HealthResponse>(`${apiBase}/health`),
      $fetch<SecurityResponse>(`${apiBase}/securities?pageSize=6`),
      $fetch<IndexResponse>(`${apiBase}/indices`),
      $fetch<NewsResponse>(`${apiBase}/news?pageSize=5`),
      $fetch<EventResponse>(`${apiBase}/events?pageSize=5`),
    ])

    health.value = healthData
    securities.value = securityData.data
    totalSecurities.value = securityData.meta.total
    indices.value = indexData.data
    news.value = newsData.data
    events.value = eventData.data
  } catch (error) {
    loadError.value =
      error instanceof Error
        ? error.message
        : '无法连接到 API，请确认 Laravel 与数据库已经启动。'
  } finally {
    loading.value = false
  }
}

onMounted(loadDashboard)

const modules = [
  {
    id: 'module-00',
    title: '模块 00 · 工程基线',
    summary: '三应用仓库、双安装方式、本地脚本、容器编排、版本约束与健康检查。',
  },
  {
    id: 'module-01',
    title: '模块 01 · 股票主数据',
    summary: 'A 股主数据表、搜索接口、主数据同步服务与主数据首页展示。',
  },
  {
    id: 'module-02',
    title: '模块 02 · 行情接入',
    summary: '已接入股票日线、快照、指数快照与指数日线，并通过 Laravel 缓存层提供读取与自动回源。',
  },
  {
    id: 'module-03',
    title: '模块 03 · 新闻源接入',
    summary: '已接入真实新闻源抓取、去重聚类、质量校验、证券关联和事件生成，并已落到真实 MySQL 数据库。',
  },
  {
    id: 'module-04',
    title: '模块 04 · 事件评分与信号引擎',
    summary: '已将事件链转换为交易信号，并接入解释面板、基线评估、综合排序和订阅优先级能力。',
  },
  {
    id: 'module-05',
    title: '模块 05 · 策略工作台',
    summary: '已接入自选池、真实持仓、行情盈亏、信号触发和订阅过滤同步，让交易者可以按自己的组合日常盯盘。',
  },
  {
    id: 'module-06',
    title: '模块 06 · 易用性与运行控制台',
    summary: '已开始把行情、新闻、公告、事件链和信号命令封装为可点击的数据任务中心，降低本地运行门槛。',
  },
]
</script>

<template>
  <main class="page">
    <section class="hero">
      <div class="hero__copy">
        <p class="eyebrow">Trading Studio</p>
        <h1>面向中国股票交易者的事件驱动投研平台</h1>
        <p class="hero__text">
          这不是演示站。当前页面直接读取真实 API 状态和主数据结果，用于驱动后续行情、新闻、事件与 AI 模块的持续开发。
        </p>
        <div class="hero__actions">
          <a class="button button--solid" href="http://127.0.0.1:8000/api/v1/health" target="_blank" rel="noreferrer">查看 API 健康</a>
          <NuxtLink class="button button--ghost" to="/ops">数据任务中心</NuxtLink>
          <NuxtLink class="button button--ghost" to="/signals">打开信号看板</NuxtLink>
          <NuxtLink class="button button--ghost" to="/strategy-workspace">策略工作台</NuxtLink>
          <a class="button button--ghost" href="http://127.0.0.1:8080/docs" target="_blank" rel="noreferrer">查看 Intelligence 接口</a>
        </div>
      </div>
      <div class="hero__panel">
        <p class="panel__label">系统状态</p>
        <div v-if="loading" class="status status--loading">正在连接后端服务...</div>
        <div v-else-if="loadError" class="status status--error">{{ loadError }}</div>
        <div v-else-if="health" class="status-grid">
          <div class="status-card">
            <span>应用</span>
            <strong>{{ health.data.name }}</strong>
          </div>
          <div class="status-card">
            <span>环境</span>
            <strong>{{ health.data.environment }}</strong>
          </div>
          <div class="status-card">
            <span>主数据总数</span>
            <strong>{{ totalSecurities }}</strong>
          </div>
          <div class="status-card">
            <span>检测时间</span>
            <strong>{{ health.meta.timestamp }}</strong>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Modules</p>
        <h2>按模块推进，而不是一次性堆叠 Demo 页面</h2>
      </div>
      <div class="module-grid">
        <article v-for="module in modules" :key="module.id" class="module-card">
          <p class="module-card__id">{{ module.id }}</p>
          <h3>{{ module.title }}</h3>
          <p>{{ module.summary }}</p>
        </article>
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Indices</p>
        <h2>指数快照模块首屏</h2>
      </div>
      <div v-if="!loading && !loadError && indices.length > 0" class="security-grid">
        <article v-for="index in indices" :key="index.id" class="security-card">
          <p class="security-card__symbol">{{ index.code }}</p>
          <h3>{{ index.name }}</h3>
          <p>最新点位：{{ index.last_price ?? '--' }}</p>
          <span>涨跌幅：{{ index.pct_change ?? '--' }}</span>
        </article>
      </div>
      <div v-else-if="!loading && !loadError" class="empty-state">
        当前数据库还没有指数快照。启动后执行 `php artisan market:sync-indices`，平台会同步主要市场指数。
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">News</p>
        <h2>最新新闻抓取结果</h2>
      </div>
      <div v-if="!loading && !loadError && news.length > 0" class="module-grid">
        <article v-for="item in news" :key="item.id" class="module-card">
          <p class="module-card__id">{{ item.importance_level }} · {{ item.quality_status }}</p>
          <h3>{{ item.title }}</h3>
          <p>{{ item.summary || '当前记录以结构化标题为主，详情可通过新闻详情接口读取。' }}</p>
          <p>{{ item.source?.source_name ?? '未知来源' }}</p>
        </article>
      </div>
      <div v-else-if="!loading && !loadError" class="empty-state">
        当前数据库还没有新闻数据。执行 `php artisan news:sync-sources` 和 `php artisan news:sync` 后，这里会展示真实抓取结果。
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Events</p>
        <h2>新闻驱动事件结果</h2>
      </div>
      <div v-if="!loading && !loadError && events.length > 0" class="module-grid">
        <article v-for="item in events" :key="item.id" class="module-card">
          <p class="module-card__id">{{ item.event_type }} · {{ item.importance_level }}</p>
          <h3>{{ item.title }}</h3>
          <p>状态：{{ item.status }}</p>
        </article>
      </div>
      <div v-else-if="!loading && !loadError" class="empty-state">
        当前数据库还没有事件数据。新闻同步完成后，系统会自动生成并关联事件。
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Securities</p>
        <h2>股票主数据模块首屏</h2>
      </div>
      <div v-if="!loading && !loadError && securities.length > 0" class="security-grid">
        <article v-for="security in securities" :key="security.id" class="security-card">
          <p class="security-card__symbol">{{ security.symbol }}</p>
          <h3>{{ security.name }}</h3>
          <p>{{ security.canonical_symbol }}</p>
          <span>{{ security.exchange }} · {{ security.security_type }}</span>
        </article>
      </div>
      <div v-else-if="!loading && !loadError" class="empty-state">
        当前数据库还没有股票主数据。启动后执行 `php artisan market:sync-securities`，平台会从真实 Provider 同步 A 股股票列表。
      </div>
    </section>
  </main>
</template>
