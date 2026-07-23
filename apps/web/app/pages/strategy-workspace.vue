<script setup lang="ts">
type SignalItem = {
  id: number
  title: string
  signal_type: string
  direction: string
  signal_score: number
  published_at: string | null
  primary_security: {
    symbol: string
    name: string
  } | null
}

type WorkspaceItem = {
  id: number
  workspace_key: string
  name: string
  owner_key: string
  workspace_type: string
  risk_profile: string
  base_currency: string
  default_signal_subscription_id: number | null
  enabled: boolean
  items_count?: number
}

type SubscriptionItem = {
  id: number
  subscriber_key: string
  subscriber_name: string | null
  priority_level: string
  enabled: boolean
  min_signal_score: number
}

type MonitorItem = {
  item_id: number
  security: {
    id: number
    canonical_symbol: string
    symbol: string
    name: string
    exchange: string
  } | null
  item_type: string
  status: string
  position_quantity: number | null
  average_cost: number | null
  last_price: number | null
  pct_change: number | null
  quote_time: string | null
  market_value: number | null
  cost_value: number | null
  unrealized_pnl: number | null
  unrealized_pnl_pct: number | null
  target_price: number | null
  target_distance_pct: number | null
  stop_loss_price: number | null
  stop_loss_distance_pct: number | null
  alert_score_threshold: number
  alert_triggered: boolean
  risk_state: string
  attention_score: number
  signal_count: number
  positive_signal_count: number
  negative_signal_count: number
  top_signal_score: number
  top_signal: {
    id: number
    title: string
    signal_type: string
    direction: string
    signal_score: number
    published_at: string | null
  } | null
  tags: string[] | null
  notes: string | null
}

type RecommendationItem = {
  security: {
    id: number
    canonical_symbol: string
    symbol: string
    name: string
    exchange: string
  }
  recommendation_score: number
  recommendation_type: string
  already_tracked: boolean
  signal_count: number
  positive_signal_count: number
  negative_signal_count: number
  event_chain_count: number
  event_heat: number
  latest_published_at: string | null
  top_signal: {
    id: number
    title: string
    signal_type: string
    direction: string
    signal_score: number
    published_at: string | null
  } | null
  top_chains: Array<{
    id: number
    chain_type: string
    topic: string
    importance_level: string
    sentiment: string
    event_count: number
    article_count: number
    latest_published_at: string | null
  }>
  reasons: string[]
}

type WorkspaceOverview = {
  workspace: {
    id: number
    workspace_key: string
    name: string
    workspace_type: string
    risk_profile: string
    base_currency: string
    enabled: boolean
    last_reviewed_at: string | null
  }
  overview: {
    item_count: number
    holding_count: number
    watch_count: number
    portfolio_value: number
    cost_value: number
    unrealized_pnl: number
    unrealized_pnl_pct: number | null
    high_risk_count: number
    opportunity_count: number
    alert_triggered_count: number
    avg_signal_score: number
  }
  subscription_bridge: {
    linked: boolean
    subscription_id: number | null
    subscriber_key: string | null
    subscriber_name: string | null
    enabled: boolean | null
    min_signal_score: number | null
    security_symbols: string[]
    coverage_count: number
  }
  monitors: MonitorItem[]
  top_signals: SignalItem[]
}

type WorkspaceListResponse = {
  code: number
  message: string
  data: WorkspaceItem[]
}

type WorkspaceOverviewResponse = {
  code: number
  message: string
  data: WorkspaceOverview
}

type SubscriptionListResponse = {
  code: number
  message: string
  data: SubscriptionItem[]
}

type RecommendationResponse = {
  code: number
  message: string
  data: RecommendationItem[]
}

const config = useRuntimeConfig()
const apiBase = config.public.apiBase
const apiServerBase = String(config.apiServerBase || 'http://127.0.0.1:8000/api/v1')
const requestApiBase = () => import.meta.server ? apiServerBase : apiBase

const loading = useState('strategyWorkspace.loading', () => true)
const submitting = ref(false)
const recommendationLoading = useState('strategyWorkspace.recommendationLoading', () => true)
const recommendationLoaded = useState('strategyWorkspace.recommendationLoaded', () => false)
const recommendationError = useState('strategyWorkspace.recommendationError', () => '')
const pageError = useState('strategyWorkspace.pageError', () => '')
const pageNotice = ref('')
const workspaces = useState<WorkspaceItem[]>('strategyWorkspace.workspaces', () => [])
const subscriptions = useState<SubscriptionItem[]>('strategyWorkspace.subscriptions', () => [])
const selectedWorkspaceId = useState<number | null>('strategyWorkspace.selectedWorkspaceId', () => null)
const overview = useState<WorkspaceOverview | null>('strategyWorkspace.overview', () => null)
const recommendations = useState<RecommendationItem[]>('strategyWorkspace.recommendations', () => [])

const workspaceForm = reactive({
  workspaceKey: '',
  name: '',
  ownerKey: 'desk-alpha',
  workspaceType: 'watchlist',
  riskProfile: 'balanced',
  defaultSignalSubscriptionId: '',
})

const itemForm = reactive({
  symbol: '',
  itemType: 'watch',
  positionQuantity: '',
  averageCost: '',
  targetPrice: '',
  stopLossPrice: '',
  alertScoreThreshold: '70',
  tags: '',
  notes: '',
})

const selectedWorkspace = computed(() =>
  workspaces.value.find((workspace) => workspace.id === selectedWorkspaceId.value) || null,
)

const loadPage = async () => {
  loading.value = true
  pageError.value = ''

  try {
    await Promise.all([loadWorkspaces(), loadSubscriptions()])
    if (workspaces.value.length > 0) {
      selectedWorkspaceId.value = selectedWorkspaceId.value || workspaces.value[0].id
      await Promise.all([loadOverview(), loadRecommendations()])
    } else {
      await loadRecommendations()
    }
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '策略工作台加载失败，请确认 API 与数据库已经启动。'
  } finally {
    loading.value = false
  }
}

const loadWorkspaces = async () => {
  const response = await $fetch<WorkspaceListResponse>(`${requestApiBase()}/strategy-workspaces?pageSize=50`)
  workspaces.value = response.data
}

const loadSubscriptions = async () => {
  const response = await $fetch<SubscriptionListResponse>(`${requestApiBase()}/signal-subscriptions?pageSize=50`)
  subscriptions.value = response.data
}

const loadOverview = async () => {
  if (!selectedWorkspaceId.value) {
    overview.value = null
    return
  }

  const response = await $fetch<WorkspaceOverviewResponse>(`${requestApiBase()}/strategy-workspaces/${selectedWorkspaceId.value}/overview`)
  overview.value = response.data
}

const loadRecommendations = async () => {
  recommendationLoading.value = true
  recommendationError.value = ''

  try {
    const path = selectedWorkspaceId.value
      ? `${requestApiBase()}/strategy-workspaces/${selectedWorkspaceId.value}/recommendations?limit=12`
      : `${requestApiBase()}/strategy-workspaces/recommendations?limit=12`
    const response = await $fetch<RecommendationResponse>(path)
    recommendations.value = response.data
  } catch (error) {
    recommendations.value = []
    recommendationError.value = error instanceof Error ? error.message : '推荐池加载失败，请确认 API 服务已启动。'
  } finally {
    recommendationLoading.value = false
    recommendationLoaded.value = true
  }
}

const selectWorkspace = async (workspaceId: number) => {
  selectedWorkspaceId.value = workspaceId
  await Promise.all([loadOverview(), loadRecommendations()])
}

const saveWorkspace = async () => {
  submitting.value = true
  pageError.value = ''
  pageNotice.value = ''

  try {
    const response = await $fetch<{ data: WorkspaceItem }>(`${apiBase}/strategy-workspaces`, {
      method: 'POST',
      body: {
        workspace_key: workspaceForm.workspaceKey || undefined,
        name: workspaceForm.name,
        owner_key: workspaceForm.ownerKey || 'default',
        workspace_type: workspaceForm.workspaceType,
        risk_profile: workspaceForm.riskProfile,
        default_signal_subscription_id: numberOrNull(workspaceForm.defaultSignalSubscriptionId),
        enabled: true,
      },
    })

    pageNotice.value = '策略工作台已创建。'
    resetWorkspaceForm()
    await loadWorkspaces()
    selectedWorkspaceId.value = response.data.id
    await Promise.all([loadOverview(), loadRecommendations()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '创建策略工作台失败。'
  } finally {
    submitting.value = false
  }
}

const addItem = async () => {
  if (!selectedWorkspaceId.value) {
    pageError.value = '请先创建或选择一个策略工作台。'
    return
  }

  submitting.value = true
  pageError.value = ''
  pageNotice.value = ''

  try {
    await $fetch(`${apiBase}/strategy-workspaces/${selectedWorkspaceId.value}/items`, {
      method: 'POST',
      body: {
        symbol: itemForm.symbol,
        item_type: itemForm.itemType,
        position_quantity: numberOrNull(itemForm.positionQuantity),
        average_cost: numberOrNull(itemForm.averageCost),
        target_price: numberOrNull(itemForm.targetPrice),
        stop_loss_price: numberOrNull(itemForm.stopLossPrice),
        alert_score_threshold: Number(itemForm.alertScoreThreshold || '70'),
        tags: csvList(itemForm.tags),
        notes: itemForm.notes || null,
      },
    })

    pageNotice.value = `${itemForm.symbol} 已加入工作台。`
    resetItemForm()
    await Promise.all([loadWorkspaces(), loadOverview(), loadRecommendations()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '添加标的失败，请确认股票代码已在主数据中。'
  } finally {
    submitting.value = false
  }
}

const addRecommendation = async (recommendation: RecommendationItem) => {
  if (!selectedWorkspaceId.value) {
    pageError.value = '请先创建或选择一个策略工作台，再加入推荐股票。'
    return
  }

  submitting.value = true
  pageError.value = ''
  pageNotice.value = ''

  try {
    await $fetch(`${apiBase}/strategy-workspaces/${selectedWorkspaceId.value}/items`, {
      method: 'POST',
      body: {
        security_id: recommendation.security.id,
        item_type: recommendation.recommendation_type === 'risk_watch' ? 'watch' : 'candidate',
        alert_score_threshold: Math.max(70, Math.min(90, Math.round(recommendation.recommendation_score))),
        tags: ['系统推荐', recommendation.recommendation_type],
        notes: recommendation.reasons.join('；') || null,
      },
    })

    pageNotice.value = `${recommendation.security.symbol} 已加入工作台。`
    await Promise.all([loadWorkspaces(), loadOverview(), loadRecommendations()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '加入推荐股票失败。'
  } finally {
    submitting.value = false
  }
}

const removeItem = async (itemId: number) => {
  if (!selectedWorkspaceId.value) {
    return
  }

  submitting.value = true
  pageError.value = ''
  pageNotice.value = ''

  try {
    await $fetch(`${apiBase}/strategy-workspaces/${selectedWorkspaceId.value}/items/${itemId}`, {
      method: 'DELETE',
    })
    pageNotice.value = `标的 #${itemId} 已移出工作台。`
    await Promise.all([loadWorkspaces(), loadOverview(), loadRecommendations()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '移除标的失败。'
  } finally {
    submitting.value = false
  }
}

const resetWorkspaceForm = () => {
  workspaceForm.workspaceKey = ''
  workspaceForm.name = ''
  workspaceForm.ownerKey = 'desk-alpha'
  workspaceForm.workspaceType = 'watchlist'
  workspaceForm.riskProfile = 'balanced'
  workspaceForm.defaultSignalSubscriptionId = ''
}

const resetItemForm = () => {
  itemForm.symbol = ''
  itemForm.itemType = 'watch'
  itemForm.positionQuantity = ''
  itemForm.averageCost = ''
  itemForm.targetPrice = ''
  itemForm.stopLossPrice = ''
  itemForm.alertScoreThreshold = '70'
  itemForm.tags = ''
  itemForm.notes = ''
}

const csvList = (value: string) =>
  value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)

const numberOrNull = (value: string) => {
  if (!value.trim()) {
    return null
  }

  return Number(value)
}

const formatNumber = (value: number | string | null | undefined, digits = 2) => {
  if (value === null || value === undefined || value === '') {
    return '--'
  }

  return new Intl.NumberFormat('zh-CN', {
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

const riskStateClass = (state: string) => `signal-pill workspace-pill--${state}`
const directionClass = (direction: string) => `signal-pill signal-pill--${direction}`
const signalTypeLabel = (type: string | null | undefined) => {
  const labels: Record<string, string> = {
    alpha_opportunity: '机会信号',
    risk_alert: '风险预警',
    financing_watch: '融资/债券关注',
    research_watch: '调研关注',
    event_heat: '热点异动',
    policy_watch: '政策关注',
    earnings_watch: '业绩关注',
    buyback: '回购关注',
    shareholder_change: '股东变化',
    litigation_watch: '诉讼风险',
  }

  return type ? labels[type] || type : '--'
}
const directionLabel = (direction: string | null | undefined) => {
  const labels: Record<string, string> = {
    positive: '正向机会',
    negative: '负向风险',
    neutral: '中性观察',
  }

  return direction ? labels[direction] || direction : '--'
}
const recommendationTypeLabel = (type: string) => {
  const labels: Record<string, string> = {
    risk_watch: '风险观察',
    opportunity: '机会关注',
    hot_topic: '热点跟踪',
    observe: '观察候选',
  }

  return labels[type] || type
}
const recommendationClass = (type: string) => {
  const state = type === 'risk_watch'
    ? 'risk'
    : type === 'opportunity'
      ? 'opportunity'
      : type === 'hot_topic'
        ? 'watch'
        : 'quiet'

  return riskStateClass(state)
}
const pnlClass = (value: number | null | undefined) => {
  if (value === null || value === undefined || value === 0) {
    return 'is-neutral'
  }

  return value > 0 ? 'is-strong' : 'is-weak'
}

if (import.meta.server) {
  await loadPage()
}

onMounted(() => {
  if (!recommendationLoaded.value || pageError.value) {
    void loadPage()
  }
})
</script>

<template>
  <main class="page page--signals">
    <section class="hero hero--signals">
      <div class="hero__copy">
        <p class="eyebrow">Module 05</p>
        <h1>策略工作台 / 自选与组合监控</h1>
        <p class="hero__text">
          把信号订阅接到交易者的日常盯盘流程里：自选池、真实持仓、实时行情、事件信号和通知订阅在同一个工作面板里闭环。
        </p>
        <div class="hero__actions">
          <button type="button" class="button button--solid" :disabled="loading" @click="loadPage">
            刷新工作台
          </button>
          <NuxtLink class="button button--ghost" to="/signals">
            信号看板
          </NuxtLink>
          <NuxtLink class="button button--ghost" to="/signals/execution-center">
            通知执行中心
          </NuxtLink>
        </div>
      </div>

      <div class="hero__panel">
        <p class="panel__label">组合状态</p>
        <div v-if="loading" class="status status--loading">正在读取策略工作台...</div>
        <div v-else-if="pageError" class="status status--error">{{ pageError }}</div>
        <div v-else-if="overview" class="status-grid status-grid--signals">
          <div class="status-card">
            <span>标的 / 持仓</span>
            <strong>{{ overview.overview.item_count }} / {{ overview.overview.holding_count }}</strong>
          </div>
          <div class="status-card">
            <span>组合市值</span>
            <strong>{{ formatNumber(overview.overview.portfolio_value, 2) }}</strong>
          </div>
          <div class="status-card">
            <span>浮动盈亏</span>
            <strong :class="pnlClass(overview.overview.unrealized_pnl)">
              {{ formatNumber(overview.overview.unrealized_pnl, 2) }}
            </strong>
          </div>
          <div class="status-card">
            <span>风险 / 机会</span>
            <strong>{{ overview.overview.high_risk_count }} / {{ overview.overview.opportunity_count }}</strong>
          </div>
          <div class="status-card">
            <span>触发提醒</span>
            <strong>{{ overview.overview.alert_triggered_count }}</strong>
          </div>
          <div class="status-card">
            <span>订阅覆盖</span>
            <strong>{{ overview.subscription_bridge.coverage_count }} / {{ overview.subscription_bridge.security_symbols.length }}</strong>
          </div>
        </div>
        <div v-else class="status status--loading">还没有策略工作台，先创建一个自选或组合。</div>
      </div>
    </section>

    <section class="section">
      <div class="section__header section__header--inline">
        <div>
          <p class="eyebrow">Radar</p>
          <h2>推荐关注股票</h2>
        </div>
        <button type="button" class="button button--ghost" :disabled="recommendationLoading" @click="loadRecommendations">
          刷新推荐
        </button>
      </div>

      <div v-if="recommendationLoading" class="status status--loading">正在根据最新消息和信号计算推荐池...</div>
      <div v-else-if="recommendationError" class="status status--error">{{ recommendationError }}</div>
      <div v-else-if="recommendations.length > 0" class="heatmap-grid">
        <article v-for="item in recommendations" :key="item.security.id" class="heatmap-card">
          <p class="heatmap-card__symbol">{{ item.security.symbol }} · {{ item.security.exchange }}</p>
          <h3>{{ item.security.name }}</h3>
          <p>
            推荐分 {{ formatNumber(item.recommendation_score, 1) }}
            · 热度 {{ item.event_heat }}
            · 最新 {{ formatDateTime(item.latest_published_at) }}
          </p>
          <div class="signal-pill-row">
            <span :class="recommendationClass(item.recommendation_type)">
              {{ recommendationTypeLabel(item.recommendation_type) }}
            </span>
            <span class="signal-pill">信号 {{ item.signal_count }}</span>
            <span class="signal-pill">事件链 {{ item.event_chain_count }}</span>
            <span v-if="item.already_tracked" class="signal-pill workspace-pill--quiet">已在工作台</span>
          </div>
          <div v-if="item.top_signal" class="meta-stack">
            <strong>{{ item.top_signal.title }}</strong>
            <span>
              {{ signalTypeLabel(item.top_signal.signal_type) }}
              · {{ directionLabel(item.top_signal.direction) }}
              · {{ formatNumber(item.top_signal.signal_score, 1) }} 分
            </span>
          </div>
          <div v-if="item.reasons.length > 0" class="compact-list compact-list--flush">
            <p v-for="reason in item.reasons.slice(0, 3)" :key="reason" class="compact-list__meta">
              {{ reason }}
            </p>
          </div>
          <div class="heatmap-card__footer">
            <span>正 {{ item.positive_signal_count }} / 负 {{ item.negative_signal_count }}</span>
            <button
              type="button"
              class="button button--solid button--small"
              :disabled="submitting || !selectedWorkspaceId || item.already_tracked"
              @click="addRecommendation(item)"
            >
              {{ item.already_tracked ? '已加入' : '加入工作台' }}
            </button>
          </div>
        </article>
      </div>
      <div v-else-if="recommendationLoaded" class="empty-state">
        当前还没有可推荐股票。请先到数据任务中心运行“抓取热点新闻”或“一键刷新热点雷达”，系统会基于真实入库数据生成推荐池。
        <NuxtLink class="button button--ghost button--small" to="/ops">
          打开数据任务中心
        </NuxtLink>
      </div>
    </section>

    <section class="section">
      <div class="section__header section__header--inline">
        <div>
          <p class="eyebrow">Workspace</p>
          <h2>工作台选择与创建</h2>
        </div>
        <p class="section__meta">{{ selectedWorkspace?.name || '未选择工作台' }}</p>
      </div>

      <div class="operations-grid operations-grid--editor">
        <article class="board-panel">
          <div class="board-panel__header">
            <h3>工作台列表</h3>
            <span>{{ workspaces.length }} 个</span>
          </div>
          <div v-if="workspaces.length > 0" class="compact-list">
            <article v-for="workspace in workspaces" :key="workspace.id" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ workspace.name }}</p>
                <p class="compact-list__meta">
                  {{ workspace.workspace_type }} · {{ workspace.risk_profile }} · {{ workspace.items_count || 0 }} 个标的
                </p>
              </div>
              <div class="compact-actions">
                <span :class="riskStateClass(workspace.enabled ? 'opportunity' : 'quiet')">
                  {{ workspace.enabled ? 'enabled' : 'disabled' }}
                </span>
                <button type="button" class="button button--ghost button--small" @click="selectWorkspace(workspace.id)">
                  打开
                </button>
              </div>
            </article>
          </div>
          <p v-else class="empty-inline">当前还没有策略工作台。</p>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>新建工作台</h3>
            <span>绑定订阅后会自动同步自选股票过滤</span>
          </div>
          <div class="editor-grid">
            <label class="field">
              <span>工作台 Key</span>
              <input v-model="workspaceForm.workspaceKey" type="text" placeholder="core_portfolio" />
            </label>
            <label class="field">
              <span>名称</span>
              <input v-model="workspaceForm.name" type="text" placeholder="核心组合" />
            </label>
            <label class="field">
              <span>Owner</span>
              <input v-model="workspaceForm.ownerKey" type="text" placeholder="desk-alpha" />
            </label>
            <label class="field">
              <span>类型</span>
              <select v-model="workspaceForm.workspaceType">
                <option value="watchlist">watchlist</option>
                <option value="portfolio">portfolio</option>
                <option value="theme">theme</option>
                <option value="risk_watch">risk_watch</option>
              </select>
            </label>
            <label class="field">
              <span>风险偏好</span>
              <select v-model="workspaceForm.riskProfile">
                <option value="conservative">conservative</option>
                <option value="balanced">balanced</option>
                <option value="aggressive">aggressive</option>
              </select>
            </label>
            <label class="field">
              <span>默认订阅</span>
              <select v-model="workspaceForm.defaultSignalSubscriptionId">
                <option value="">不绑定</option>
                <option v-for="subscription in subscriptions" :key="subscription.id" :value="String(subscription.id)">
                  {{ subscription.subscriber_name || subscription.subscriber_key }}
                </option>
              </select>
            </label>
          </div>
          <div class="filter-actions">
            <button type="button" class="button button--solid" :disabled="submitting" @click="saveWorkspace">
              创建工作台
            </button>
            <button type="button" class="button button--ghost" :disabled="submitting" @click="resetWorkspaceForm">
              重置
            </button>
          </div>
        </article>
      </div>
    </section>

    <section class="section">
      <div class="section__header section__header--inline">
        <div>
          <p class="eyebrow">Monitor</p>
          <h2>自选与持仓监控</h2>
        </div>
        <p v-if="overview" class="section__meta">
          订阅 {{ overview.subscription_bridge.subscriber_name || overview.subscription_bridge.subscriber_key || '未绑定' }}
        </p>
      </div>

      <div class="filter-panel">
        <div class="filter-toolbar">
          <label class="field">
            <span>股票代码</span>
            <input v-model="itemForm.symbol" type="text" placeholder="例如 600000" />
          </label>
          <label class="field">
            <span>类型</span>
            <select v-model="itemForm.itemType">
              <option value="watch">watch</option>
              <option value="holding">holding</option>
              <option value="candidate">candidate</option>
              <option value="hedge">hedge</option>
            </select>
          </label>
          <label class="field">
            <span>数量</span>
            <input v-model="itemForm.positionQuantity" type="number" min="0" step="100" />
          </label>
          <label class="field">
            <span>成本</span>
            <input v-model="itemForm.averageCost" type="number" min="0" step="0.01" />
          </label>
          <label class="field">
            <span>目标价</span>
            <input v-model="itemForm.targetPrice" type="number" min="0" step="0.01" />
          </label>
          <label class="field">
            <span>止损价</span>
            <input v-model="itemForm.stopLossPrice" type="number" min="0" step="0.01" />
          </label>
          <label class="field">
            <span>提醒分数</span>
            <input v-model="itemForm.alertScoreThreshold" type="number" min="0" max="100" />
          </label>
          <label class="field">
            <span>标签</span>
            <input v-model="itemForm.tags" type="text" placeholder="红利, 银行" />
          </label>
        </div>
        <label class="field field--wide">
          <span>备注</span>
          <input v-model="itemForm.notes" type="text" placeholder="关注逻辑、仓位计划或复盘记录" />
        </label>
        <div class="filter-actions">
          <button type="button" class="button button--solid" :disabled="submitting || !selectedWorkspaceId" @click="addItem">
            加入工作台
          </button>
          <button type="button" class="button button--ghost" :disabled="submitting" @click="resetItemForm">
            清空
          </button>
        </div>
        <p v-if="pageNotice" class="status status--loading execution-hint">{{ pageNotice }}</p>
      </div>

      <div v-if="overview && overview.monitors.length > 0" class="table-shell">
        <table class="signal-table">
          <thead>
            <tr>
              <th>标的</th>
              <th>行情 / 盈亏</th>
              <th>交易计划</th>
              <th>信号状态</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in overview.monitors" :key="item.item_id">
              <td>
                <div class="signal-cell">
                  <p class="signal-cell__title">{{ item.security?.symbol }} · {{ item.security?.name }}</p>
                  <p class="signal-cell__summary">{{ item.item_type }} · {{ item.tags?.join(', ') || '--' }}</p>
                  <span :class="riskStateClass(item.risk_state)">{{ item.risk_state }}</span>
                </div>
              </td>
              <td>
                <div class="metric-stack">
                  <strong>{{ formatNumber(item.last_price, 2) }}</strong>
                  <span :class="pnlClass(item.pct_change)">{{ formatPercent(item.pct_change) }}</span>
                  <span>市值 {{ formatNumber(item.market_value, 2) }}</span>
                  <span :class="pnlClass(item.unrealized_pnl)">盈亏 {{ formatNumber(item.unrealized_pnl, 2) }}</span>
                </div>
              </td>
              <td>
                <div class="meta-stack">
                  <span>持仓 {{ formatNumber(item.position_quantity, 0) }} · 成本 {{ formatNumber(item.average_cost, 2) }}</span>
                  <span>目标 {{ formatNumber(item.target_price, 2) }} · 空间 {{ formatPercent(item.target_distance_pct) }}</span>
                  <span>止损 {{ formatNumber(item.stop_loss_price, 2) }} · 缓冲 {{ formatPercent(item.stop_loss_distance_pct) }}</span>
                </div>
              </td>
              <td>
                <div class="meta-stack">
                  <strong>{{ item.top_signal?.title || '暂无活跃信号' }}</strong>
                  <span>信号 {{ item.signal_count }} · 正 {{ item.positive_signal_count }} · 负 {{ item.negative_signal_count }}</span>
                  <span>最高分 {{ formatNumber(item.top_signal_score, 1) }} · 阈值 {{ formatNumber(item.alert_score_threshold, 1) }}</span>
                  <span v-if="item.top_signal" :class="directionClass(item.top_signal.direction)">
                    {{ directionLabel(item.top_signal.direction) }}
                  </span>
                </div>
              </td>
              <td>
                <button type="button" class="button button--ghost button--small" :disabled="submitting" @click="removeItem(item.item_id)">
                  移除
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else-if="!loading" class="empty-state">
        当前工作台还没有可监控标的。
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Signals</p>
        <h2>工作台触发信号</h2>
      </div>

      <div v-if="overview && overview.top_signals.length > 0" class="board-grid">
        <article v-for="signal in overview.top_signals" :key="signal.id" class="board-panel">
          <div class="board-panel__header">
            <h3>{{ signal.primary_security?.symbol }} · {{ signal.primary_security?.name }}</h3>
            <span>{{ formatDateTime(signal.published_at) }}</span>
          </div>
          <p class="compact-list__title">{{ signal.title }}</p>
          <div class="signal-pill-row">
            <span :class="directionClass(signal.direction)">{{ directionLabel(signal.direction) }}</span>
            <span class="signal-pill">{{ signalTypeLabel(signal.signal_type) }}</span>
            <span class="signal-pill">分数 {{ formatNumber(signal.signal_score, 1) }}</span>
          </div>
        </article>
      </div>
      <div v-else class="empty-state">
        当前工作台暂无活跃信号。
      </div>
    </section>
  </main>
</template>
