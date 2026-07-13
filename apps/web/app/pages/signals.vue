<script setup lang="ts">
type SignalItem = {
  id: number
  signal_key: string
  signal_type: string
  direction: string
  horizon_label: string
  status: string
  title: string
  summary: string | null
  signal_score: number
  dashboard_rank: number
  confidence_score: number
  urgency_score: number
  impact_score: number
  risk_score: number
  priority_tier: string
  evaluation_status: string
  published_at: string | null
  triggered_at: string | null
  explanation: {
    factor_panel?: Array<{
      label: string
      score?: number | null
      weight?: number | null
      contribution?: number | null
    }>
    narrative?: string[]
  } | null
  sort_metrics: {
    latest_alpha_return_pct?: number | null
    latest_return_pct?: number | null
    best_alpha_return_pct?: number | null
    best_return_pct?: number | null
  }
  primary_security: {
    symbol: string
    name: string
    canonical_symbol: string
  } | null
  latest_event: {
    event_type: string
    title: string
    timeline_stage: string | null
    occurred_at: string | null
  } | null
  chain: {
    chain_type: string
    topic: string
    status: string
  } | null
}

type DashboardOverview = {
  total: number
  active: number
  positive: number
  negative: number
  high_priority: number
  evaluated: number
  pending_evaluation: number
  avg_signal_score: number
  avg_dashboard_rank: number
  by_signal_type: Record<string, number>
  by_direction: Record<string, number>
  by_priority_tier: Record<string, number>
  by_timeline_stage: Record<string, number>
}

type SubscriptionOverviewItem = {
  id: number
  subscriber_key: string
  subscriber_name: string | null
  priority_level: string
  priority_order: number
  enabled: boolean
  min_signal_score: number
  deliveries_count: number
  last_notified_at: string | null
}

type SignalHeatmapItem = {
  security_id: number | null
  symbol: string | null
  name: string | null
  signal_count: number
  avg_signal_score: number
  max_dashboard_rank: number
  directions: Record<string, number>
  top_signal: {
    id: number
    title: string
    signal_type: string
    priority_tier: string
    dashboard_rank: number
  }
}

type SignalFilterOptions = {
  signal_types: string[]
  directions: string[]
  statuses: string[]
  chain_types: string[]
  timeline_stages: string[]
  security_symbols: string[]
  priority_tiers: string[]
  evaluation_statuses: string[]
  sort_options: Array<{
    key: string
    label: string
  }>
}

type SignalListResponse = {
  code: number
  message: string
  data: SignalItem[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    summary: DashboardOverview
    filter_options: SignalFilterOptions
    timestamp: string
  }
}

type SignalDashboardResponse = {
  code: number
  message: string
  data: {
    overview: DashboardOverview
    top_signals: SignalItem[]
    risk_alerts: SignalItem[]
    pending_evaluations: SignalItem[]
    subscription_overview: {
      total: number
      enabled: number
      critical_priority: number
      by_priority_level: Record<string, number>
      items: SubscriptionOverviewItem[]
    }
    heatmap: SignalHeatmapItem[]
  }
  meta: {
    timestamp: string
  }
}

type DashboardFilters = {
  signalTypes: string[]
  directions: string[]
  statuses: string[]
  chainTypes: string[]
  timelineStages: string[]
  priorityTiers: string[]
  evaluationStatuses: string[]
  securitySymbol: string
  minScore: string
  minAlphaReturn: string
  highPriorityOnly: boolean
}

const config = useRuntimeConfig()
const apiBase = config.public.apiBase

const loading = ref(true)
const listLoading = ref(false)
const error = ref('')
const board = ref<SignalDashboardResponse['data'] | null>(null)
const signals = ref<SignalItem[]>([])
const filterOptions = ref<SignalFilterOptions>({
  signal_types: [],
  directions: [],
  statuses: [],
  chain_types: [],
  timeline_stages: [],
  security_symbols: [],
  priority_tiers: ['critical', 'high', 'normal', 'observe'],
  evaluation_statuses: ['evaluated', 'pending', 'no_market_data', 'no_security'],
  sort_options: [
    { key: 'dashboardRank', label: '综合看板排序' },
    { key: 'score', label: '信号分' },
    { key: 'urgency', label: '时效性' },
    { key: 'impact', label: '影响力' },
    { key: 'confidence', label: '置信度' },
    { key: 'risk', label: '风险分' },
    { key: 'alpha', label: '超额收益' },
    { key: 'return', label: '绝对收益' },
    { key: 'publishedAt', label: '发布时间' },
    { key: 'triggeredAt', label: '触发时间' },
  ],
})

const filters = reactive<DashboardFilters>({
  signalTypes: [],
  directions: [],
  statuses: ['active'],
  chainTypes: [],
  timelineStages: [],
  priorityTiers: [],
  evaluationStatuses: [],
  securitySymbol: '',
  minScore: '60',
  minAlphaReturn: '',
  highPriorityOnly: false,
})

const pagination = reactive({
  currentPage: 1,
  lastPage: 1,
  perPage: 12,
  total: 0,
})

const sortBy = ref('dashboardRank')
const sortDirection = ref<'asc' | 'desc'>('desc')

const activeFilterCount = computed(() => {
  let count = 0

  count += filters.signalTypes.length
  count += filters.directions.length
  count += filters.statuses.length > 0 ? 1 : 0
  count += filters.chainTypes.length
  count += filters.timelineStages.length
  count += filters.priorityTiers.length
  count += filters.evaluationStatuses.length
  count += filters.securitySymbol.trim() !== '' ? 1 : 0
  count += filters.minScore.trim() !== '' ? 1 : 0
  count += filters.minAlphaReturn.trim() !== '' ? 1 : 0
  count += filters.highPriorityOnly ? 1 : 0

  return count
})

const sortDirectionLabel = computed(() =>
  sortDirection.value === 'desc' ? '从高到低' : '从低到高',
)

const loadBoard = async () => {
  loading.value = true
  error.value = ''

  try {
    await Promise.all([loadDashboard(), loadSignals()])
  } catch (fetchError) {
    error.value =
      fetchError instanceof Error
        ? fetchError.message
        : '信号看板加载失败，请确认 API、数据库和信号引擎已经启动。'
  } finally {
    loading.value = false
  }
}

const loadDashboard = async () => {
  const query = new URLSearchParams(buildFilterParams()).toString()
  const response = await $fetch<SignalDashboardResponse>(`${apiBase}/signals/dashboard?${query}`)
  board.value = response.data
}

const loadSignals = async () => {
  listLoading.value = true

  try {
    const params = new URLSearchParams({
      ...buildFilterParams(),
      page: String(pagination.currentPage),
      pageSize: String(pagination.perPage),
      sortBy: sortBy.value,
      sortDirection: sortDirection.value,
    })

    const response = await $fetch<SignalListResponse>(`${apiBase}/signals?${params.toString()}`)
    signals.value = response.data
    pagination.currentPage = response.meta.current_page
    pagination.lastPage = response.meta.last_page
    pagination.perPage = response.meta.per_page
    pagination.total = response.meta.total
    filterOptions.value = response.meta.filter_options
  } finally {
    listLoading.value = false
  }
}

const buildFilterParams = (): Record<string, string> => {
  const params: Record<string, string> = {}

  appendArrayParam(params, 'signalTypes', filters.signalTypes)
  appendArrayParam(params, 'directions', filters.directions)
  appendArrayParam(params, 'statuses', filters.statuses)
  appendArrayParam(params, 'chainTypes', filters.chainTypes)
  appendArrayParam(params, 'timelineStages', filters.timelineStages)
  appendArrayParam(params, 'priorityTiers', filters.priorityTiers)
  appendArrayParam(params, 'evaluationStatuses', filters.evaluationStatuses)

  if (filters.securitySymbol.trim() !== '') {
    params.securitySymbol = filters.securitySymbol.trim()
  }
  if (filters.minScore.trim() !== '') {
    params.minScore = filters.minScore.trim()
  }
  if (filters.minAlphaReturn.trim() !== '') {
    params.minAlphaReturn = filters.minAlphaReturn.trim()
  }
  if (filters.highPriorityOnly) {
    params.highPriorityOnly = '1'
  }

  return params
}

const appendArrayParam = (params: Record<string, string>, key: string, values: string[]) => {
  if (values.length > 0) {
    params[key] = values.join(',')
  }
}

const toggleFilter = (bucket: keyof Pick<
  DashboardFilters,
  'signalTypes' | 'directions' | 'statuses' | 'chainTypes' | 'timelineStages' | 'priorityTiers' | 'evaluationStatuses'
>, value: string) => {
  const values = filters[bucket]
  const index = values.indexOf(value)

  if (index >= 0) {
    values.splice(index, 1)
    return
  }

  values.push(value)
}

const applyFilters = async () => {
  pagination.currentPage = 1
  await loadBoard()
}

const resetFilters = async () => {
  filters.signalTypes = []
  filters.directions = []
  filters.statuses = ['active']
  filters.chainTypes = []
  filters.timelineStages = []
  filters.priorityTiers = []
  filters.evaluationStatuses = []
  filters.securitySymbol = ''
  filters.minScore = '60'
  filters.minAlphaReturn = ''
  filters.highPriorityOnly = false
  pagination.currentPage = 1
  await loadBoard()
}

const goToPage = async (page: number) => {
  if (page < 1 || page > pagination.lastPage || page === pagination.currentPage) {
    return
  }

  pagination.currentPage = page
  await loadSignals()
}

const refreshBoard = async () => {
  await loadBoard()
}

const formatNumber = (value: number | string | null | undefined, digits = 1) => {
  if (value === null || value === undefined || value === '') {
    return '--'
  }

  return new Intl.NumberFormat('zh-CN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: digits,
  }).format(Number(value))
}

const formatPercent = (value: number | string | null | undefined) => {
  if (value === null || value === undefined || value === '') {
    return '--'
  }

  const numericValue = Number(value)
  const prefix = numericValue > 0 ? '+' : ''

  return `${prefix}${formatNumber(numericValue, 2)}%`
}

const formatDateTime = (value: string | null) => {
  if (!value) {
    return '--'
  }

  return new Intl.DateTimeFormat('zh-CN', {
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

const scoreTone = (value: number | null | undefined) => {
  if (value === null || value === undefined) {
    return ''
  }

  if (value >= 80) {
    return 'is-strong'
  }
  if (value >= 65) {
    return 'is-good'
  }
  if (value >= 50) {
    return 'is-neutral'
  }

  return 'is-weak'
}

const directionClass = (direction: string) => `signal-pill signal-pill--${direction}`
const priorityClass = (tier: string) => `signal-pill signal-pill--priority-${tier}`
const evaluationClass = (status: string) => `signal-pill signal-pill--evaluation-${status}`

onMounted(loadBoard)

watch([sortBy, sortDirection], async () => {
  pagination.currentPage = 1
  await loadSignals()
})
</script>

<template>
  <main class="page page--signals">
    <section class="hero hero--signals">
      <div class="hero__copy">
        <p class="eyebrow">Module 04.2</p>
        <h1>交易信号看板与排序策略</h1>
        <p class="hero__text">
          这个页面直接消费真实信号 API。它不是静态展示页，而是给交易员做盘中监控、排序筛选和订阅优先级判断的工作面板。
        </p>
        <div class="hero__actions">
          <button type="button" class="button button--solid" @click="refreshBoard">
            刷新信号看板
          </button>
          <NuxtLink class="button button--ghost" to="/signals/execution-center">
            打开执行中心
          </NuxtLink>
          <NuxtLink class="button button--ghost" to="/">
            返回平台总览
          </NuxtLink>
        </div>
      </div>

      <div class="hero__panel">
        <p class="panel__label">核心指标</p>
        <div v-if="loading" class="status status--loading">正在读取实时信号...</div>
        <div v-else-if="error" class="status status--error">{{ error }}</div>
        <div v-else-if="board" class="status-grid status-grid--signals">
          <div class="status-card">
            <span>活跃信号</span>
            <strong>{{ board.overview.active }} / {{ board.overview.total }}</strong>
          </div>
          <div class="status-card">
            <span>高优先级</span>
            <strong>{{ board.overview.high_priority }}</strong>
          </div>
          <div class="status-card">
            <span>已评估</span>
            <strong>{{ board.overview.evaluated }}</strong>
          </div>
          <div class="status-card">
            <span>待评估</span>
            <strong>{{ board.overview.pending_evaluation }}</strong>
          </div>
          <div class="status-card">
            <span>平均信号分</span>
            <strong>{{ formatNumber(board.overview.avg_signal_score, 2) }}</strong>
          </div>
          <div class="status-card">
            <span>平均看板分</span>
            <strong>{{ formatNumber(board.overview.avg_dashboard_rank, 2) }}</strong>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="section__header section__header--inline">
        <div>
          <p class="eyebrow">Filters</p>
          <h2>组合筛选面板</h2>
        </div>
        <p class="section__meta">当前激活筛选 {{ activeFilterCount }} 项</p>
      </div>

      <div class="filter-panel">
        <div class="filter-toolbar">
          <label class="field">
            <span>证券代码</span>
            <input v-model="filters.securitySymbol" type="text" placeholder="例如 600000" />
          </label>
          <label class="field">
            <span>最低信号分</span>
            <input v-model="filters.minScore" type="number" min="0" max="100" step="1" />
          </label>
          <label class="field">
            <span>最低超额收益</span>
            <input v-model="filters.minAlphaReturn" type="number" step="0.1" placeholder="例如 2.5" />
          </label>
          <label class="toggle-field">
            <input v-model="filters.highPriorityOnly" type="checkbox" />
            <span>仅看高优先级</span>
          </label>
        </div>

        <div class="chip-groups">
          <div class="chip-group">
            <p>信号类型</p>
            <div class="chip-row">
              <button
                v-for="option in filterOptions.signal_types"
                :key="option"
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.signalTypes.includes(option) }"
                @click="toggleFilter('signalTypes', option)"
              >
                {{ option }}
              </button>
            </div>
          </div>

          <div class="chip-group">
            <p>方向</p>
            <div class="chip-row">
              <button
                v-for="option in filterOptions.directions"
                :key="option"
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.directions.includes(option) }"
                @click="toggleFilter('directions', option)"
              >
                {{ option }}
              </button>
            </div>
          </div>

          <div class="chip-group">
            <p>状态</p>
            <div class="chip-row">
              <button
                v-for="option in filterOptions.statuses"
                :key="option"
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.statuses.includes(option) }"
                @click="toggleFilter('statuses', option)"
              >
                {{ option }}
              </button>
            </div>
          </div>

          <div class="chip-group">
            <p>事件链类型</p>
            <div class="chip-row">
              <button
                v-for="option in filterOptions.chain_types"
                :key="option"
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.chainTypes.includes(option) }"
                @click="toggleFilter('chainTypes', option)"
              >
                {{ option }}
              </button>
            </div>
          </div>

          <div class="chip-group">
            <p>时间线阶段</p>
            <div class="chip-row">
              <button
                v-for="option in filterOptions.timeline_stages"
                :key="option"
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.timelineStages.includes(option) }"
                @click="toggleFilter('timelineStages', option)"
              >
                {{ option }}
              </button>
            </div>
          </div>

          <div class="chip-group">
            <p>优先级</p>
            <div class="chip-row">
              <button
                v-for="option in filterOptions.priority_tiers"
                :key="option"
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.priorityTiers.includes(option) }"
                @click="toggleFilter('priorityTiers', option)"
              >
                {{ option }}
              </button>
            </div>
          </div>

          <div class="chip-group">
            <p>评估状态</p>
            <div class="chip-row">
              <button
                v-for="option in filterOptions.evaluation_statuses"
                :key="option"
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.evaluationStatuses.includes(option) }"
                @click="toggleFilter('evaluationStatuses', option)"
              >
                {{ option }}
              </button>
            </div>
          </div>
        </div>

        <div class="filter-actions">
          <button type="button" class="button button--solid" @click="applyFilters">
            应用筛选
          </button>
          <button type="button" class="button button--ghost" @click="resetFilters">
            重置到默认盘中视图
          </button>
        </div>
      </div>
    </section>

    <section v-if="board && !error" class="section">
      <div class="section__header section__header--inline">
        <div>
          <p class="eyebrow">Board</p>
          <h2>核心监控面板</h2>
        </div>
      </div>

      <div class="board-grid">
        <article class="board-panel">
          <div class="board-panel__header">
            <h3>顶部信号</h3>
            <span>综合排序前列</span>
          </div>
          <div v-if="board.top_signals.length > 0" class="compact-list">
            <article v-for="signal in board.top_signals" :key="signal.id" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ signal.title }}</p>
                <p class="compact-list__meta">
                  {{ signal.primary_security?.symbol ?? '--' }} · {{ signal.chain?.chain_type ?? '--' }}
                </p>
              </div>
              <strong :class="scoreTone(signal.dashboard_rank)">
                {{ formatNumber(signal.dashboard_rank, 2) }}
              </strong>
            </article>
          </div>
          <p v-else class="empty-inline">当前没有可展示的顶部信号。</p>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>风险预警</h3>
            <span>优先关注负向链路</span>
          </div>
          <div v-if="board.risk_alerts.length > 0" class="compact-list">
            <article v-for="signal in board.risk_alerts" :key="signal.id" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ signal.title }}</p>
                <p class="compact-list__meta">
                  风险分 {{ formatNumber(signal.risk_score, 1) }} · {{ signal.primary_security?.symbol ?? '--' }}
                </p>
              </div>
              <span :class="directionClass(signal.direction)">
                {{ signal.direction }}
              </span>
            </article>
          </div>
          <p v-else class="empty-inline">当前筛选条件下没有负向高风险信号。</p>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>待评估信号</h3>
            <span>回测基线未完成</span>
          </div>
          <div v-if="board.pending_evaluations.length > 0" class="compact-list">
            <article v-for="signal in board.pending_evaluations" :key="signal.id" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ signal.title }}</p>
                <p class="compact-list__meta">
                  {{ signal.primary_security?.symbol ?? '--' }} · {{ signal.evaluation_status }}
                </p>
              </div>
              <span :class="evaluationClass(signal.evaluation_status)">
                {{ signal.evaluation_status }}
              </span>
            </article>
          </div>
          <p v-else class="empty-inline">当前筛选结果都已经完成基线评估。</p>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>订阅优先级</h3>
            <span>{{ board.subscription_overview.enabled }} 个启用订阅</span>
          </div>
          <div v-if="board.subscription_overview.items.length > 0" class="compact-list">
            <article
              v-for="subscription in board.subscription_overview.items"
              :key="subscription.id"
              class="compact-list__item"
            >
              <div>
                <p class="compact-list__title">
                  {{ subscription.subscriber_name || subscription.subscriber_key }}
                </p>
                <p class="compact-list__meta">
                  顺位 {{ subscription.priority_order }} · 阈值 {{ formatNumber(subscription.min_signal_score, 1) }}
                </p>
              </div>
              <span :class="priorityClass(subscription.priority_level)">
                {{ subscription.priority_level }}
              </span>
            </article>
          </div>
          <p v-else class="empty-inline">还没有配置任何信号订阅。</p>
        </article>
      </div>
    </section>

    <section v-if="board && board.heatmap.length > 0 && !error" class="section">
      <div class="section__header">
        <p class="eyebrow">Heatmap</p>
        <h2>证券热力分布</h2>
      </div>

      <div class="heatmap-grid">
        <article v-for="item in board.heatmap" :key="item.top_signal.id" class="heatmap-card">
          <p class="heatmap-card__symbol">{{ item.symbol ?? '--' }}</p>
          <h3>{{ item.name ?? '未关联证券' }}</h3>
          <p>信号数量 {{ item.signal_count }} · 最高看板分 {{ formatNumber(item.max_dashboard_rank, 2) }}</p>
          <div class="heatmap-card__footer">
            <span>{{ item.top_signal.signal_type }}</span>
            <span :class="priorityClass(item.top_signal.priority_tier)">
              {{ item.top_signal.priority_tier }}
            </span>
          </div>
        </article>
      </div>
    </section>

    <section class="section">
      <div class="section__header section__header--inline">
        <div>
          <p class="eyebrow">Signals</p>
          <h2>实时信号列表</h2>
        </div>
        <div class="sort-toolbar">
          <label class="field field--compact">
            <span>排序字段</span>
            <select v-model="sortBy">
              <option v-for="option in filterOptions.sort_options" :key="option.key" :value="option.key">
                {{ option.label }}
              </option>
            </select>
          </label>
          <label class="field field--compact">
            <span>方向</span>
            <select v-model="sortDirection">
              <option value="desc">从高到低</option>
              <option value="asc">从低到高</option>
            </select>
          </label>
        </div>
      </div>

      <div v-if="listLoading" class="status status--loading">
        正在按 {{ sortDirectionLabel }} 更新信号排序...
      </div>

      <div v-else-if="signals.length > 0" class="table-shell">
        <table class="signal-table">
          <thead>
            <tr>
              <th>信号</th>
              <th>排序与分值</th>
              <th>证券 / 事件</th>
              <th>收益与评估</th>
              <th>时间</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="signal in signals" :key="signal.id">
              <td>
                <div class="signal-cell">
                  <p class="signal-cell__title">{{ signal.title }}</p>
                  <p class="signal-cell__summary">{{ signal.summary || '当前信号以结构化摘要为主。' }}</p>
                  <div class="signal-pill-row">
                    <span :class="directionClass(signal.direction)">{{ signal.direction }}</span>
                    <span :class="priorityClass(signal.priority_tier)">{{ signal.priority_tier }}</span>
                    <span :class="evaluationClass(signal.evaluation_status)">{{ signal.evaluation_status }}</span>
                  </div>
                </div>
              </td>
              <td>
                <div class="metric-stack">
                  <strong :class="scoreTone(signal.dashboard_rank)">
                    看板 {{ formatNumber(signal.dashboard_rank, 2) }}
                  </strong>
                  <span>信号 {{ formatNumber(signal.signal_score, 1) }}</span>
                  <span>时效 {{ formatNumber(signal.urgency_score, 1) }}</span>
                  <span>影响 {{ formatNumber(signal.impact_score, 1) }}</span>
                  <span>置信 {{ formatNumber(signal.confidence_score, 1) }}</span>
                  <span>风险 {{ formatNumber(signal.risk_score, 1) }}</span>
                </div>
              </td>
              <td>
                <div class="meta-stack">
                  <strong>{{ signal.primary_security?.symbol ?? '--' }} {{ signal.primary_security?.name ?? '' }}</strong>
                  <span>{{ signal.chain?.chain_type ?? '--' }} · {{ signal.signal_type }}</span>
                  <span>{{ signal.latest_event?.timeline_stage ?? '--' }} · {{ signal.latest_event?.title ?? '--' }}</span>
                </div>
              </td>
              <td>
                <div class="metric-stack">
                  <strong :class="scoreTone(signal.sort_metrics.latest_alpha_return_pct ?? null)">
                    超额 {{ formatPercent(signal.sort_metrics.latest_alpha_return_pct) }}
                  </strong>
                  <span>收益 {{ formatPercent(signal.sort_metrics.latest_return_pct) }}</span>
                  <span>最佳超额 {{ formatPercent(signal.sort_metrics.best_alpha_return_pct) }}</span>
                </div>
              </td>
              <td>
                <div class="meta-stack">
                  <span>触发 {{ formatDateTime(signal.triggered_at) }}</span>
                  <span>发布 {{ formatDateTime(signal.published_at) }}</span>
                  <span>{{ signal.horizon_label }}</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else-if="!loading && !error" class="empty-state">
        当前筛选条件下没有匹配的交易信号。可以降低最低分阈值，或放宽优先级和事件类型条件。
      </div>

      <div class="pager">
        <button
          type="button"
          class="button button--ghost"
          :disabled="pagination.currentPage <= 1 || listLoading"
          @click="goToPage(pagination.currentPage - 1)"
        >
          上一页
        </button>
        <p>
          第 {{ pagination.currentPage }} / {{ pagination.lastPage }} 页，共 {{ pagination.total }} 条
        </p>
        <button
          type="button"
          class="button button--ghost"
          :disabled="pagination.currentPage >= pagination.lastPage || listLoading"
          @click="goToPage(pagination.currentPage + 1)"
        >
          下一页
        </button>
      </div>
    </section>
  </main>
</template>
