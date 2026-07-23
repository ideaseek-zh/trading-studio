<script setup lang="ts">
type OpsTask = {
  task_key: string
  name: string
  category: string
  description: string
  estimated_seconds: number
  defaults: Record<string, unknown>
}

type OpsTaskRun = {
  id: number
  task_key: string
  task_name: string
  status: string
  triggered_by: string | null
  input: Record<string, unknown> | null
  result: {
    summary?: string
    steps?: Array<{
      name: string
      command: string
      status: string
      duration_ms: number
    }>
  } | null
  output: string | null
  error: string | null
  started_at: string | null
  finished_at: string | null
  duration_ms: number | null
  created_at: string | null
}

type TaskListResponse = {
  code: number
  message: string
  data: OpsTask[]
}

type RunListResponse = {
  code: number
  message: string
  data: OpsTaskRun[]
}

type RunResponse = {
  code: number
  message: string
  data: OpsTaskRun
}

const config = useRuntimeConfig()
const apiBase = config.public.apiBase
const apiServerBase = String(config.apiServerBase || 'http://127.0.0.1:8000/api/v1')
const requestApiBase = () => import.meta.server ? apiServerBase : apiBase

const loading = useState('ops.loading', () => true)
const runningTaskKey = ref('')
const pageError = useState('ops.pageError', () => '')
const pageNotice = ref('')
const tasks = useState<OpsTask[]>('ops.tasks', () => [])
const runs = useState<OpsTaskRun[]>('ops.runs', () => [])
const selectedRun = useState<OpsTaskRun | null>('ops.selectedRun', () => null)

const taskForm = reactive({
  symbols: '300059,000001,002311,300687,601127',
  startDate: '',
  endDate: '',
  limit: '50',
  indexCode: 'sh000001',
})

const categorizedTasks = computed(() => {
  const labels: Record<string, string> = {
    workflow: '推荐工作流',
    market: '行情数据',
    news: '新闻公告',
    signal: '事件与信号',
  }

  return Object.entries(
    tasks.value.reduce<Record<string, OpsTask[]>>((groups, task) => {
      groups[task.category] = groups[task.category] || []
      groups[task.category].push(task)
      return groups
    }, {}),
  ).map(([category, items]) => ({
    category,
    label: labels[category] || category,
    items,
  }))
})

const loadPage = async () => {
  loading.value = true
  pageError.value = ''

  try {
    await Promise.all([loadTasks(), loadRuns()])
    hydrateDefaults()
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '运行中心加载失败，请确认 API 服务已经启动。'
  } finally {
    loading.value = false
  }
}

const loadTasks = async () => {
  const response = await $fetch<TaskListResponse>(`${requestApiBase()}/ops/tasks`)
  tasks.value = response.data
}

const loadRuns = async () => {
  const response = await $fetch<RunListResponse>(`${requestApiBase()}/ops/task-runs?pageSize=20`)
  runs.value = response.data
  selectedRun.value = response.data[0] || selectedRun.value
}

const hydrateDefaults = () => {
  const radarTask = tasks.value.find((task) => task.task_key === 'one_click_radar_refresh')
  if (!radarTask) {
    return
  }

  const defaults = radarTask.defaults || {}
  if (Array.isArray(defaults.symbols)) {
    taskForm.symbols = defaults.symbols.join(',')
  }
  if (typeof defaults.start_date === 'string') {
    taskForm.startDate = defaults.start_date
  }
  if (typeof defaults.end_date === 'string') {
    taskForm.endDate = defaults.end_date
  }
  if (typeof defaults.limit === 'number') {
    taskForm.limit = String(defaults.limit)
  }
}

const runTask = async (task: OpsTask) => {
  runningTaskKey.value = task.task_key
  pageError.value = ''
  pageNotice.value = ''

  try {
    const response = await $fetch<RunResponse>(`${apiBase}/ops/tasks/${task.task_key}/run`, {
      method: 'POST',
      body: requestBodyFor(task),
    })
    selectedRun.value = response.data
    pageNotice.value = `${task.name} 已完成。`
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : `${task.name} 执行失败。`
  } finally {
    runningTaskKey.value = ''
    await loadRuns()
  }
}

const requestBodyFor = (task: OpsTask) => {
  const body: Record<string, unknown> = {
    triggered_by: 'web-ops-center',
  }

  if (['refresh_quotes', 'refresh_daily_bars', 'fetch_stock_news', 'one_click_radar_refresh'].includes(task.task_key)) {
    body.symbols = taskForm.symbols
  }
  if (['refresh_daily_bars', 'fetch_stock_news', 'one_click_radar_refresh'].includes(task.task_key)) {
    body.start_date = taskForm.startDate || undefined
    body.end_date = taskForm.endDate || undefined
    body.index_code = taskForm.indexCode || undefined
  }
  if (['fetch_hot_news', 'fetch_stock_news', 'one_click_radar_refresh'].includes(task.task_key)) {
    body.limit = Number(taskForm.limit || '50')
  }

  return body
}

const statusLabel = (status: string) => {
  const labels: Record<string, string> = {
    running: '运行中',
    succeeded: '成功',
    partial_success: '部分成功',
    failed: '失败',
  }

  return labels[status] || status
}

const statusClass = (status: string) => {
  const classes: Record<string, string> = {
    running: 'signal-pill signal-pill--delivery-sending',
    succeeded: 'signal-pill signal-pill--delivery-success',
    partial_success: 'signal-pill signal-pill--delivery-partial_success',
    failed: 'signal-pill signal-pill--delivery-failed',
  }

  return classes[status] || 'signal-pill'
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

const formatDuration = (value: number | null) => {
  if (!value) {
    return '--'
  }

  if (value < 1000) {
    return `${value} ms`
  }

  return `${(value / 1000).toFixed(1)} 秒`
}

if (import.meta.server) {
  await loadPage()
}

onMounted(() => {
  if (tasks.value.length === 0 || pageError.value) {
    void loadPage()
  }
})
</script>

<template>
  <main class="page page--signals">
    <section class="hero hero--signals">
      <div class="hero__copy">
        <p class="eyebrow">Module 06.1</p>
        <h1>数据任务中心</h1>
        <p class="hero__text">
          把工程命令变成交易者能理解的按钮：抓行情、抓热点、抓公告、重建事件链、生成推荐关注股票，都在这里完成。
          启动调度器后，系统会默认每 10 分钟自动刷新热点雷达。
        </p>
        <div class="hero__actions">
          <button type="button" class="button button--solid" :disabled="loading" @click="loadPage">
            刷新任务状态
          </button>
          <NuxtLink class="button button--ghost" to="/strategy-workspace">
            查看推荐股票
          </NuxtLink>
          <NuxtLink class="button button--ghost" to="/signals">
            信号看板
          </NuxtLink>
        </div>
      </div>

      <div class="hero__panel">
        <p class="panel__label">运行提示</p>
        <div v-if="loading" class="status status--loading">正在读取任务中心...</div>
        <div v-else-if="pageError" class="status status--error">{{ pageError }}</div>
        <div v-else class="status-grid status-grid--signals">
          <div class="status-card">
            <span>可执行任务</span>
            <strong>{{ tasks.length }}</strong>
          </div>
          <div class="status-card">
            <span>最近运行</span>
            <strong>{{ runs.length }}</strong>
          </div>
          <div class="status-card">
            <span>最新状态</span>
            <strong>{{ statusLabel(runs[0]?.status || 'none') }}</strong>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="section__header section__header--inline">
        <div>
          <p class="eyebrow">Parameters</p>
          <h2>本次任务参数</h2>
        </div>
        <p class="section__meta">默认股票来自推荐池当前关注范围，可按需修改。</p>
      </div>

      <div class="filter-panel">
        <div class="filter-toolbar">
          <label class="field">
            <span>股票代码</span>
            <input v-model="taskForm.symbols" type="text" placeholder="300059,000001" />
          </label>
          <label class="field">
            <span>开始日期</span>
            <input v-model="taskForm.startDate" type="date" />
          </label>
          <label class="field">
            <span>结束日期</span>
            <input v-model="taskForm.endDate" type="date" />
          </label>
          <label class="field">
            <span>每源条数</span>
            <input v-model="taskForm.limit" type="number" min="1" max="200" />
          </label>
          <label class="field">
            <span>基准指数</span>
            <input v-model="taskForm.indexCode" type="text" placeholder="sh000001" />
          </label>
        </div>
        <p v-if="pageNotice" class="status status--loading execution-hint">{{ pageNotice }}</p>
      </div>
    </section>

    <section v-for="group in categorizedTasks" :key="group.category" class="section">
      <div class="section__header">
        <p class="eyebrow">{{ group.category }}</p>
        <h2>{{ group.label }}</h2>
      </div>

      <div class="board-grid">
        <article v-for="task in group.items" :key="task.task_key" class="board-panel">
          <div class="board-panel__header">
            <h3>{{ task.name }}</h3>
            <span>约 {{ task.estimated_seconds }} 秒</span>
          </div>
          <p class="compact-list__meta">{{ task.description }}</p>
          <div class="signal-pill-row">
            <span class="signal-pill">{{ task.category }}</span>
            <span v-if="task.task_key === 'one_click_radar_refresh'" class="signal-pill workspace-pill--opportunity">
              推荐优先
            </span>
          </div>
          <div class="filter-actions">
            <button
              type="button"
              class="button button--solid"
              :disabled="Boolean(runningTaskKey)"
              @click="runTask(task)"
            >
              {{ runningTaskKey === task.task_key ? '执行中...' : '立即执行' }}
            </button>
          </div>
        </article>
      </div>
    </section>

    <section class="section">
      <div class="section__header section__header--inline">
        <div>
          <p class="eyebrow">History</p>
          <h2>最近运行记录</h2>
        </div>
        <button type="button" class="button button--ghost" :disabled="loading" @click="loadRuns">
          刷新记录
        </button>
      </div>

      <div v-if="runs.length > 0" class="table-shell">
        <table class="signal-table">
          <thead>
            <tr>
              <th>任务</th>
              <th>状态</th>
              <th>耗时</th>
              <th>触发时间</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in runs" :key="run.id">
              <td>
                <div class="signal-cell">
                  <p class="signal-cell__title">{{ run.task_name }}</p>
                  <p class="signal-cell__summary">{{ run.task_key }}</p>
                </div>
              </td>
              <td><span :class="statusClass(run.status)">{{ statusLabel(run.status) }}</span></td>
              <td>{{ formatDuration(run.duration_ms) }}</td>
              <td>{{ formatDateTime(run.started_at || run.created_at) }}</td>
              <td>
                <button type="button" class="button button--ghost button--small" @click="selectedRun = run">
                  查看详情
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty-state">还没有任务运行记录。</div>
    </section>

    <section v-if="selectedRun" class="section">
      <div class="section__header">
        <p class="eyebrow">Run Detail</p>
        <h2>{{ selectedRun.task_name }} #{{ selectedRun.id }}</h2>
      </div>
      <div class="operations-grid operations-grid--editor">
        <article class="board-panel">
          <div class="board-panel__header">
            <h3>执行步骤</h3>
            <span :class="statusClass(selectedRun.status)">{{ statusLabel(selectedRun.status) }}</span>
          </div>
          <div v-if="selectedRun.result?.steps?.length" class="compact-list">
            <article v-for="step in selectedRun.result.steps" :key="step.name" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ step.name }}</p>
                <p class="compact-list__meta">{{ step.command }} · {{ formatDuration(step.duration_ms) }}</p>
              </div>
              <span :class="statusClass(step.status)">{{ statusLabel(step.status) }}</span>
            </article>
          </div>
          <p v-else class="empty-inline">暂无步骤明细。</p>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>输出与错误</h3>
            <span>{{ formatDateTime(selectedRun.finished_at) }}</span>
          </div>
          <p v-if="selectedRun.error" class="status status--error">{{ selectedRun.error }}</p>
          <pre class="response-preview response-preview--log">{{ selectedRun.output || '暂无输出' }}</pre>
        </article>
      </div>
    </section>
  </main>
</template>
