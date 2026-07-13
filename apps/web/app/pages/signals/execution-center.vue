<script setup lang="ts">
type DeliveryItem = {
  id: number
  delivery_channel: string
  delivery_status: string
  batch_key: string | null
  suppression_reason: string | null
  attempts: number
  response_status: number | null
  response_body: string | null
  dispatch_context?: Record<string, unknown> | null
  last_attempted_at: string | null
  next_retry_at: string | null
  delivered_at: string | null
  created_at: string | null
  subscription?: {
    id: number
    subscriber_key: string
    subscriber_name: string | null
    priority_level: string
    priority_order: number
    enabled: boolean
    endpoint_url: string
  }
  signal?: {
    id: number
    signal_key: string
    signal_type: string
    direction: string
    title: string
    signal_score: number
    published_at: string | null
    primary_security: {
      id: number
      symbol: string
      name: string
    } | null
    latest_event: {
      id: number
      event_type: string
      title: string
      timeline_stage: string | null
    } | null
  }
}

type RouteItem = {
  route_key: string
  label: string
  channel_type: string
  target: string
  target_masked?: string | null
  target_configured?: boolean
  secret_token_configured?: boolean
  signature_mode?: string
  message_format?: string | null
  template_id?: number | null
  credential_id?: number | null
  enabled: boolean
  priority_order: number
  delivery_tier: string
}

type NotificationTemplateItem = {
  id: number
  template_key: string
  name: string
  channel_type: string
  message_format: string
  subject_template: string | null
  body_template: string
  config?: Record<string, unknown> | null
  enabled: boolean
}

type NotificationCredentialItem = {
  id: number
  credential_key: string
  name: string
  channel_type: string
  endpoint_url: string | null
  endpoint_url_masked: string | null
  endpoint_configured: boolean
  secret_token_masked: string | null
  secret_token_configured: boolean
  signing_secret_masked: string | null
  signing_secret_configured: boolean
  config?: Record<string, unknown> | null
  enabled: boolean
  last_verified_at: string | null
}

type SubscriptionItem = {
  id: number
  subscriber_key: string
  subscriber_name: string | null
  channel_type: string
  priority_level: string
  priority_order: number
  endpoint_url: string
  notification_template_id?: number | null
  notification_channel_credential_id?: number | null
  min_signal_score: number
  enabled: boolean
  channel_routes?: RouteItem[]
  filters: {
    security_symbols?: string[]
    chain_types?: string[]
    signal_types?: string[]
    directions?: string[]
  } | null
  quiet_hours?: {
    enabled?: boolean
    timezone?: string
    start?: string
    end?: string
  } | null
  escalation_rules?: Array<{
    after_attempts: number
    route_keys?: string[]
    channel_types?: string[]
  }>
  debounce_window_minutes?: number
  merge_window_minutes?: number
  max_merge_signals?: number
  last_notified_at: string | null
  deliveries_count?: number
  recent_deliveries?: DeliveryItem[]
}

type OperationDashboardResponse = {
  code: number
  message: string
  data: {
    overview: {
      deliveries_total: number
      queued: number
      retrying: number
      failed: number
      success: number
      partial_success: number
      suppressed: number
      merged: number
      skipped: number
      due_retry: number
      active_signals: number
      pending_evaluation: number
      enabled_subscriptions: number
    }
    recent_failures: DeliveryItem[]
    retry_backlog: DeliveryItem[]
    recent_audit: DeliveryItem[]
    subscription_health: {
      total: number
      enabled: number
      disabled: number
      items: Array<{
        id: number
        subscriber_key: string
        subscriber_name: string | null
        priority_level: string
        priority_order: number
        enabled: boolean
        min_signal_score: number
        deliveries_count: number
        last_notified_at: string | null
      }>
    }
  }
}

type DeliveryListResponse = {
  code: number
  message: string
  data: DeliveryItem[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    summary: Record<string, number>
  }
}

type SubscriptionListResponse = {
  code: number
  message: string
  data: SubscriptionItem[]
  meta: {
    total: number
  }
}

type TemplateListResponse = {
  code: number
  message: string
  data: NotificationTemplateItem[]
  meta: {
    total: number
  }
}

type CredentialListResponse = {
  code: number
  message: string
  data: NotificationCredentialItem[]
  meta: {
    total: number
  }
}

const channelOptions = ['webhook', 'wecom_bot', 'dingtalk_bot', 'feishu_bot', 'email']

const config = useRuntimeConfig()
const apiBase = config.public.apiBase

const loading = ref(true)
const submitting = ref(false)
const pageError = ref('')
const pageNotice = ref('')

const dashboard = ref<OperationDashboardResponse['data'] | null>(null)
const deliveries = ref<DeliveryItem[]>([])
const subscriptions = ref<SubscriptionItem[]>([])
const templates = ref<NotificationTemplateItem[]>([])
const credentials = ref<NotificationCredentialItem[]>([])

const pagination = reactive({
  currentPage: 1,
  lastPage: 1,
  perPage: 12,
  total: 0,
})

const refreshForm = reactive({
  symbol: '',
  signalId: '',
  eventChainId: '',
  dispatchLimit: '50',
})

const auditFilters = reactive({
  deliveryStatus: '',
  subscriberKey: '',
  securitySymbol: '',
  signalType: '',
  needsRetry: false,
})

const editorMode = ref<'create' | 'edit'>('create')
const editingSubscriptionId = ref<number | null>(null)
const subscriptionForm = reactive({
  subscriberKey: '',
  subscriberName: '',
  channelType: 'feishu_bot',
  endpointUrl: '',
  secretToken: '',
  notificationTemplateId: '',
  notificationCredentialId: '',
  priorityLevel: 'normal',
  priorityOrder: '100',
  minSignalScore: '60',
  enabled: true,
  quietHoursEnabled: false,
  quietHoursTimezone: 'Asia/Shanghai',
  quietHoursStart: '',
  quietHoursEnd: '',
  debounceWindowMinutes: '5',
  mergeWindowMinutes: '0',
  maxMergeSignals: '5',
  securitySymbols: '',
  chainTypes: '',
  signalTypes: '',
  directions: '',
  channelRoutesJson: '',
  escalationRulesJson: '',
})

const templateEditorMode = ref<'create' | 'edit'>('create')
const editingTemplateId = ref<number | null>(null)
const templateForm = reactive({
  templateKey: '',
  name: '',
  channelType: 'feishu_bot',
  messageFormat: 'post',
  subjectTemplate: '[Trading Studio] {{signal.title}}',
  bodyTemplate: '标题：{{signal.title}}\n证券：{{security.symbol}}\n方向：{{signal.direction}}\n评分：{{signal.signal_score}}',
  enabled: true,
})

const credentialEditorMode = ref<'create' | 'edit'>('create')
const editingCredentialId = ref<number | null>(null)
const credentialForm = reactive({
  credentialKey: '',
  name: '',
  channelType: 'feishu_bot',
  endpointUrl: '',
  secretToken: '',
  signingSecret: '',
  enabled: true,
})

const loadPage = async () => {
  loading.value = true
  pageError.value = ''

  try {
    await Promise.all([loadDashboard(), loadDeliveries(), loadSubscriptions(), loadTemplates(), loadCredentials()])
  } catch (error) {
    pageError.value =
      error instanceof Error
        ? error.message
        : '执行中心加载失败，请确认 API、数据库和 intelligence 服务已经启动。'
  } finally {
    loading.value = false
  }
}

const loadDashboard = async () => {
  const response = await $fetch<OperationDashboardResponse>(`${apiBase}/signal-operations/dashboard`)
  dashboard.value = response.data
}

const loadDeliveries = async () => {
  const params = new URLSearchParams({
    page: String(pagination.currentPage),
    pageSize: String(pagination.perPage),
  })

  if (auditFilters.deliveryStatus) {
    params.set('deliveryStatus', auditFilters.deliveryStatus)
  }
  if (auditFilters.subscriberKey.trim()) {
    params.set('subscriberKey', auditFilters.subscriberKey.trim())
  }
  if (auditFilters.securitySymbol.trim()) {
    params.set('securitySymbol', auditFilters.securitySymbol.trim())
  }
  if (auditFilters.signalType.trim()) {
    params.set('signalType', auditFilters.signalType.trim())
  }
  if (auditFilters.needsRetry) {
    params.set('needsRetry', '1')
  }

  const response = await $fetch<DeliveryListResponse>(`${apiBase}/signal-deliveries?${params.toString()}`)
  deliveries.value = response.data
  pagination.currentPage = response.meta.current_page
  pagination.lastPage = response.meta.last_page
  pagination.perPage = response.meta.per_page
  pagination.total = response.meta.total
}

const loadSubscriptions = async () => {
  const response = await $fetch<SubscriptionListResponse>(`${apiBase}/signal-subscriptions?pageSize=50`)
  subscriptions.value = response.data
}

const loadTemplates = async () => {
  const response = await $fetch<TemplateListResponse>(`${apiBase}/notification-templates?pageSize=50`)
  templates.value = response.data
}

const loadCredentials = async () => {
  const response = await $fetch<CredentialListResponse>(`${apiBase}/notification-channel-credentials?pageSize=50`)
  credentials.value = response.data
}

const runRefreshAction = async (preset: 'full' | 'dispatch' | 'insight') => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  const payload = {
    symbol: refreshForm.symbol || undefined,
    signal_id: numberOrNull(refreshForm.signalId),
    event_chain_id: numberOrNull(refreshForm.eventChainId),
    dispatch_limit: Number(refreshForm.dispatchLimit || '50'),
    rebuild_signals: preset === 'full',
    evaluate_insights: preset === 'full' || preset === 'insight',
    enqueue_deliveries: preset === 'full' || preset === 'dispatch',
    dispatch_webhooks: preset === 'full' || preset === 'dispatch',
  }

  try {
    const response = await $fetch(`${apiBase}/signal-operations/refresh`, {
      method: 'POST',
      body: payload,
    })

    pageNotice.value = `执行完成：${preset === 'full' ? '全链刷新' : preset === 'dispatch' ? '派发刷新' : '解释重算'}`
    await Promise.all([loadDashboard(), loadDeliveries(), loadSubscriptions()])
    return response
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '刷新动作执行失败。'
  } finally {
    submitting.value = false
  }
}

const retryBacklog = async () => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  try {
    await $fetch(`${apiBase}/signal-operations/retry-backlog`, {
      method: 'POST',
      body: {
        limit: 20,
        dispatch_after: true,
      },
    })
    pageNotice.value = '失败与待重试队列已经重新投递。'
    await Promise.all([loadDashboard(), loadDeliveries()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '批量重试失败。'
  } finally {
    submitting.value = false
  }
}

const retryDelivery = async (deliveryId: number) => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  try {
    await $fetch(`${apiBase}/signal-deliveries/${deliveryId}/retry`, {
      method: 'POST',
      body: {
        dispatch_now: true,
      },
    })
    pageNotice.value = `投递 #${deliveryId} 已重新发送。`
    await Promise.all([loadDashboard(), loadDeliveries()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '单条重试失败。'
  } finally {
    submitting.value = false
  }
}

const applyAuditFilters = async () => {
  pagination.currentPage = 1
  await loadDeliveries()
}

const resetAuditFilters = async () => {
  auditFilters.deliveryStatus = ''
  auditFilters.subscriberKey = ''
  auditFilters.securitySymbol = ''
  auditFilters.signalType = ''
  auditFilters.needsRetry = false
  pagination.currentPage = 1
  await loadDeliveries()
}

const editSubscription = (subscription: SubscriptionItem) => {
  editorMode.value = 'edit'
  editingSubscriptionId.value = subscription.id
  subscriptionForm.subscriberKey = subscription.subscriber_key
  subscriptionForm.subscriberName = subscription.subscriber_name || ''
  subscriptionForm.channelType = subscription.channel_type
  subscriptionForm.endpointUrl = subscription.endpoint_url || ''
  subscriptionForm.secretToken = ''
  subscriptionForm.notificationTemplateId = subscription.notification_template_id ? String(subscription.notification_template_id) : ''
  subscriptionForm.notificationCredentialId = subscription.notification_channel_credential_id ? String(subscription.notification_channel_credential_id) : ''
  subscriptionForm.priorityLevel = subscription.priority_level
  subscriptionForm.priorityOrder = String(subscription.priority_order)
  subscriptionForm.minSignalScore = String(subscription.min_signal_score)
  subscriptionForm.enabled = subscription.enabled
  subscriptionForm.quietHoursEnabled = Boolean(subscription.quiet_hours?.enabled)
  subscriptionForm.quietHoursTimezone = subscription.quiet_hours?.timezone || 'Asia/Shanghai'
  subscriptionForm.quietHoursStart = subscription.quiet_hours?.start || ''
  subscriptionForm.quietHoursEnd = subscription.quiet_hours?.end || ''
  subscriptionForm.debounceWindowMinutes = String(subscription.debounce_window_minutes ?? 5)
  subscriptionForm.mergeWindowMinutes = String(subscription.merge_window_minutes ?? 0)
  subscriptionForm.maxMergeSignals = String(subscription.max_merge_signals ?? 5)
  subscriptionForm.securitySymbols = (subscription.filters?.security_symbols || []).join(', ')
  subscriptionForm.chainTypes = (subscription.filters?.chain_types || []).join(', ')
  subscriptionForm.signalTypes = (subscription.filters?.signal_types || []).join(', ')
  subscriptionForm.directions = (subscription.filters?.directions || []).join(', ')
  subscriptionForm.channelRoutesJson = subscription.channel_routes?.length
    ? JSON.stringify(subscription.channel_routes, null, 2)
    : ''
  subscriptionForm.escalationRulesJson = subscription.escalation_rules?.length
    ? JSON.stringify(subscription.escalation_rules, null, 2)
    : ''
}

const resetSubscriptionForm = () => {
  editorMode.value = 'create'
  editingSubscriptionId.value = null
  subscriptionForm.subscriberKey = ''
  subscriptionForm.subscriberName = ''
  subscriptionForm.channelType = 'feishu_bot'
  subscriptionForm.endpointUrl = ''
  subscriptionForm.secretToken = ''
  subscriptionForm.notificationTemplateId = ''
  subscriptionForm.notificationCredentialId = ''
  subscriptionForm.priorityLevel = 'normal'
  subscriptionForm.priorityOrder = '100'
  subscriptionForm.minSignalScore = '60'
  subscriptionForm.enabled = true
  subscriptionForm.quietHoursEnabled = false
  subscriptionForm.quietHoursTimezone = 'Asia/Shanghai'
  subscriptionForm.quietHoursStart = ''
  subscriptionForm.quietHoursEnd = ''
  subscriptionForm.debounceWindowMinutes = '5'
  subscriptionForm.mergeWindowMinutes = '0'
  subscriptionForm.maxMergeSignals = '5'
  subscriptionForm.securitySymbols = ''
  subscriptionForm.chainTypes = ''
  subscriptionForm.signalTypes = ''
  subscriptionForm.directions = ''
  subscriptionForm.channelRoutesJson = ''
  subscriptionForm.escalationRulesJson = ''
}

const editTemplate = (template: NotificationTemplateItem) => {
  templateEditorMode.value = 'edit'
  editingTemplateId.value = template.id
  templateForm.templateKey = template.template_key
  templateForm.name = template.name
  templateForm.channelType = template.channel_type
  templateForm.messageFormat = template.message_format
  templateForm.subjectTemplate = template.subject_template || ''
  templateForm.bodyTemplate = template.body_template
  templateForm.enabled = template.enabled
}

const resetTemplateForm = () => {
  templateEditorMode.value = 'create'
  editingTemplateId.value = null
  templateForm.templateKey = ''
  templateForm.name = ''
  templateForm.channelType = 'feishu_bot'
  templateForm.messageFormat = 'post'
  templateForm.subjectTemplate = '[Trading Studio] {{signal.title}}'
  templateForm.bodyTemplate = '标题：{{signal.title}}\n证券：{{security.symbol}}\n方向：{{signal.direction}}\n评分：{{signal.signal_score}}'
  templateForm.enabled = true
}

const editCredential = (credential: NotificationCredentialItem) => {
  credentialEditorMode.value = 'edit'
  editingCredentialId.value = credential.id
  credentialForm.credentialKey = credential.credential_key
  credentialForm.name = credential.name
  credentialForm.channelType = credential.channel_type
  credentialForm.endpointUrl = credential.endpoint_url || credential.endpoint_url_masked || ''
  credentialForm.secretToken = ''
  credentialForm.signingSecret = ''
  credentialForm.enabled = credential.enabled
}

const resetCredentialForm = () => {
  credentialEditorMode.value = 'create'
  editingCredentialId.value = null
  credentialForm.credentialKey = ''
  credentialForm.name = ''
  credentialForm.channelType = 'feishu_bot'
  credentialForm.endpointUrl = ''
  credentialForm.secretToken = ''
  credentialForm.signingSecret = ''
  credentialForm.enabled = true
}

const saveSubscription = async () => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  const channelRoutes = parseJsonArray(subscriptionForm.channelRoutesJson, '高级通道路由')
  if (channelRoutes === null) {
    submitting.value = false
    return
  }

  const escalationRules = parseJsonArray(subscriptionForm.escalationRulesJson, '升级规则')
  if (escalationRules === null) {
    submitting.value = false
    return
  }

  const body = {
    subscriber_key: subscriptionForm.subscriberKey,
    subscriber_name: subscriptionForm.subscriberName || null,
    channel_type: subscriptionForm.channelType,
    endpoint_url: subscriptionForm.endpointUrl || null,
    secret_token: subscriptionForm.secretToken || null,
    notification_template_id: numberOrNull(subscriptionForm.notificationTemplateId),
    notification_channel_credential_id: numberOrNull(subscriptionForm.notificationCredentialId),
    priority_level: subscriptionForm.priorityLevel,
    priority_order: Number(subscriptionForm.priorityOrder),
    min_signal_score: Number(subscriptionForm.minSignalScore),
    enabled: subscriptionForm.enabled,
    quiet_hours: {
      enabled: subscriptionForm.quietHoursEnabled,
      timezone: subscriptionForm.quietHoursTimezone || 'Asia/Shanghai',
      start: subscriptionForm.quietHoursStart || null,
      end: subscriptionForm.quietHoursEnd || null,
    },
    escalation_rules: escalationRules,
    debounce_window_minutes: Number(subscriptionForm.debounceWindowMinutes || '5'),
    merge_window_minutes: Number(subscriptionForm.mergeWindowMinutes || '0'),
    max_merge_signals: Number(subscriptionForm.maxMergeSignals || '5'),
    channel_routes: channelRoutes,
    filters: {
      security_symbols: csvList(subscriptionForm.securitySymbols),
      chain_types: csvList(subscriptionForm.chainTypes),
      signal_types: csvList(subscriptionForm.signalTypes),
      directions: csvList(subscriptionForm.directions),
    },
  }

  try {
    if (editorMode.value === 'edit' && editingSubscriptionId.value) {
      await $fetch(`${apiBase}/signal-subscriptions/${editingSubscriptionId.value}`, {
        method: 'PATCH',
        body,
      })
      pageNotice.value = '订阅规则已更新。'
    } else {
      await $fetch(`${apiBase}/signal-subscriptions`, {
        method: 'POST',
        body,
      })
      pageNotice.value = '订阅规则已创建。'
    }

    resetSubscriptionForm()
    await Promise.all([loadDashboard(), loadSubscriptions(), loadDeliveries()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '订阅保存失败。'
  } finally {
    submitting.value = false
  }
}

const saveTemplate = async () => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  const body = {
    template_key: templateForm.templateKey,
    name: templateForm.name,
    channel_type: templateForm.channelType,
    message_format: templateForm.messageFormat,
    subject_template: templateForm.subjectTemplate || null,
    body_template: templateForm.bodyTemplate,
    enabled: templateForm.enabled,
  }

  try {
    if (templateEditorMode.value === 'edit' && editingTemplateId.value) {
      await $fetch(`${apiBase}/notification-templates/${editingTemplateId.value}`, {
        method: 'PATCH',
        body,
      })
      pageNotice.value = '通知模板已更新。'
    } else {
      await $fetch(`${apiBase}/notification-templates`, {
        method: 'POST',
        body,
      })
      pageNotice.value = '通知模板已创建。'
    }

    resetTemplateForm()
    await Promise.all([loadTemplates(), loadSubscriptions()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '模板保存失败。'
  } finally {
    submitting.value = false
  }
}

const saveCredential = async () => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  const body = {
    credential_key: credentialForm.credentialKey,
    name: credentialForm.name,
    channel_type: credentialForm.channelType,
    endpoint_url: credentialForm.endpointUrl || null,
    secret_token: credentialForm.secretToken || null,
    signing_secret: credentialForm.signingSecret || null,
    enabled: credentialForm.enabled,
  }

  try {
    if (credentialEditorMode.value === 'edit' && editingCredentialId.value) {
      await $fetch(`${apiBase}/notification-channel-credentials/${editingCredentialId.value}`, {
        method: 'PATCH',
        body,
      })
      pageNotice.value = '渠道凭证已更新。'
    } else {
      await $fetch(`${apiBase}/notification-channel-credentials`, {
        method: 'POST',
        body,
      })
      pageNotice.value = '渠道凭证已创建。'
    }

    resetCredentialForm()
    await Promise.all([loadCredentials(), loadSubscriptions()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '渠道凭证保存失败。'
  } finally {
    submitting.value = false
  }
}

const testSubscription = async () => {
  if (editorMode.value !== 'edit' || !editingSubscriptionId.value) {
    pageError.value = '请先选择一个已存在的订阅进行联调测试。'
    return
  }

  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  try {
    await $fetch(`${apiBase}/signal-subscriptions/${editingSubscriptionId.value}/test`, {
      method: 'POST',
    })
    pageNotice.value = '测试通知已发送到当前配置通道。'
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '测试投递失败。'
  } finally {
    submitting.value = false
  }
}

const verifyCredential = async () => {
  if (credentialEditorMode.value !== 'edit' || !editingCredentialId.value) {
    pageError.value = '请先选择一个已存在的渠道凭证进行联调测试。'
    return
  }

  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  try {
    await $fetch(`${apiBase}/notification-channel-credentials/${editingCredentialId.value}/verify`, {
      method: 'POST',
      body: {
        template_id: numberOrNull(subscriptionForm.notificationTemplateId),
      },
    })
    pageNotice.value = '渠道联调通知已发送。'
    await loadCredentials()
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '渠道凭证联调失败。'
  } finally {
    submitting.value = false
  }
}

const deleteSubscription = async (subscriptionId: number) => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  try {
    await $fetch(`${apiBase}/signal-subscriptions/${subscriptionId}`, {
      method: 'DELETE',
    })
    if (editingSubscriptionId.value === subscriptionId) {
      resetSubscriptionForm()
    }
    pageNotice.value = `订阅 #${subscriptionId} 已删除。`
    await Promise.all([loadDashboard(), loadSubscriptions(), loadDeliveries()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '删除订阅失败。'
  } finally {
    submitting.value = false
  }
}

const deleteTemplate = async (templateId: number) => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  try {
    await $fetch(`${apiBase}/notification-templates/${templateId}`, {
      method: 'DELETE',
    })
    if (editingTemplateId.value === templateId) {
      resetTemplateForm()
    }
    pageNotice.value = `模板 #${templateId} 已删除。`
    await Promise.all([loadTemplates(), loadSubscriptions()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '删除模板失败。'
  } finally {
    submitting.value = false
  }
}

const deleteCredential = async (credentialId: number) => {
  submitting.value = true
  pageNotice.value = ''
  pageError.value = ''

  try {
    await $fetch(`${apiBase}/notification-channel-credentials/${credentialId}`, {
      method: 'DELETE',
    })
    if (editingCredentialId.value === credentialId) {
      resetCredentialForm()
    }
    pageNotice.value = `凭证 #${credentialId} 已删除。`
    await Promise.all([loadCredentials(), loadSubscriptions()])
  } catch (error) {
    pageError.value = error instanceof Error ? error.message : '删除凭证失败。'
  } finally {
    submitting.value = false
  }
}

const goToPage = async (page: number) => {
  if (page < 1 || page > pagination.lastPage || page === pagination.currentPage) {
    return
  }

  pagination.currentPage = page
  await loadDeliveries()
}

const csvList = (value: string) =>
  value
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)

const parseJsonArray = (value: string, label: string) => {
  if (!value.trim()) {
    return []
  }

  try {
    const parsed = JSON.parse(value)
    if (!Array.isArray(parsed)) {
      pageError.value = `${label} 必须是 JSON 数组。`
      return null
    }

    return parsed
  } catch (error) {
    pageError.value = `${label} 解析失败：${error instanceof Error ? error.message : '未知错误'}`
    return null
  }
}

const numberOrNull = (value: string) => {
  if (!value.trim()) {
    return null
  }

  return Number(value)
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

const formatNumber = (value: number | null | undefined, digits = 1) => {
  if (value === null || value === undefined) {
    return '--'
  }

  return new Intl.NumberFormat('zh-CN', {
    maximumFractionDigits: digits,
  }).format(value)
}

const deliveryStatusClass = (status: string) => `signal-pill signal-pill--delivery-${status}`
const priorityClass = (tier: string) => `signal-pill signal-pill--priority-${tier}`

onMounted(loadPage)
</script>

<template>
  <main class="page page--execution">
    <section class="hero hero--signals">
      <div class="hero__copy">
        <p class="eyebrow">Module 04.5</p>
        <h1>通知模板与渠道凭证管理中心</h1>
        <p class="hero__text">
          这里负责盘中刷新、飞书机器人等真实通知通道、模板编排、凭证脱敏管理和通知审计。它不是演示操作面板，而是围绕真实信号与真实投递记录构建的执行工作台。
        </p>
        <div class="hero__actions">
          <button type="button" class="button button--solid" :disabled="submitting" @click="loadPage">
            刷新执行中心
          </button>
          <NuxtLink class="button button--ghost" to="/signals">
            返回信号看板
          </NuxtLink>
        </div>
      </div>

      <div class="hero__panel">
        <p class="panel__label">运行状态</p>
        <div v-if="loading" class="status status--loading">正在读取执行中心...</div>
        <div v-else-if="pageError" class="status status--error">{{ pageError }}</div>
        <div v-else-if="dashboard" class="status-grid status-grid--signals">
          <div class="status-card">
            <span>投递总数</span>
            <strong>{{ dashboard.overview.deliveries_total }}</strong>
          </div>
          <div class="status-card">
            <span>失败 / 重试</span>
            <strong>{{ dashboard.overview.failed }} / {{ dashboard.overview.retrying }}</strong>
          </div>
          <div class="status-card">
            <span>抑制 / 合并</span>
            <strong>{{ dashboard.overview.suppressed }} / {{ dashboard.overview.merged }}</strong>
          </div>
          <div class="status-card">
            <span>待重试</span>
            <strong>{{ dashboard.overview.due_retry }}</strong>
          </div>
          <div class="status-card">
            <span>成功 / 部分成功</span>
            <strong>{{ dashboard.overview.success }} / {{ dashboard.overview.partial_success }}</strong>
          </div>
          <div class="status-card">
            <span>活跃信号</span>
            <strong>{{ dashboard.overview.active_signals }}</strong>
          </div>
          <div class="status-card">
            <span>启用订阅</span>
            <strong>{{ dashboard.overview.enabled_subscriptions }}</strong>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Actions</p>
        <h2>盘中刷新与执行动作</h2>
      </div>

      <div class="operations-grid">
        <article class="board-panel">
          <div class="board-panel__header">
            <h3>盘中刷新</h3>
            <span>支持按股票或按单条信号刷新</span>
          </div>
          <div class="filter-toolbar">
            <label class="field">
              <span>股票代码</span>
              <input v-model="refreshForm.symbol" type="text" placeholder="例如 600000" />
            </label>
            <label class="field">
              <span>信号 ID</span>
              <input v-model="refreshForm.signalId" type="number" min="1" />
            </label>
            <label class="field">
              <span>事件链 ID</span>
              <input v-model="refreshForm.eventChainId" type="number" min="1" />
            </label>
            <label class="field">
              <span>派发上限</span>
              <input v-model="refreshForm.dispatchLimit" type="number" min="1" max="500" />
            </label>
          </div>
          <div class="filter-actions">
            <button type="button" class="button button--solid" :disabled="submitting" @click="runRefreshAction('full')">
              全链刷新
            </button>
            <button type="button" class="button button--ghost" :disabled="submitting" @click="runRefreshAction('dispatch')">
              仅刷新派发
            </button>
            <button type="button" class="button button--ghost" :disabled="submitting" @click="runRefreshAction('insight')">
              仅重算解释
            </button>
          </div>
          <p v-if="pageNotice" class="status status--loading execution-hint">{{ pageNotice }}</p>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>通知重试面板</h3>
            <span>优先处理失败、静默恢复和积压投递</span>
          </div>
          <div v-if="dashboard && dashboard.retry_backlog.length > 0" class="compact-list">
            <article v-for="delivery in dashboard.retry_backlog" :key="delivery.id" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ delivery.signal?.title ?? '未关联信号' }}</p>
                <p class="compact-list__meta">
                  {{ delivery.subscription?.subscriber_name || delivery.subscription?.subscriber_key || '--' }}
                  · {{ delivery.signal?.primary_security?.symbol ?? '--' }}
                </p>
              </div>
              <div class="compact-actions">
                <span :class="deliveryStatusClass(delivery.delivery_status)">{{ delivery.delivery_status }}</span>
                <button type="button" class="button button--ghost button--small" :disabled="submitting" @click="retryDelivery(delivery.id)">
                  重试
                </button>
              </div>
            </article>
          </div>
          <p v-else class="empty-inline">当前没有需要人工介入的待重试任务。</p>
          <div class="filter-actions">
            <button type="button" class="button button--solid" :disabled="submitting" @click="retryBacklog">
              批量重试积压
            </button>
          </div>
        </article>
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Subscriptions</p>
        <h2>订阅规则编辑</h2>
      </div>

      <div class="operations-grid operations-grid--editor">
        <article class="board-panel">
          <div class="board-panel__header">
            <h3>{{ editorMode === 'edit' ? '编辑订阅' : '新建订阅' }}</h3>
            <span>只使用真实信号，不生成模拟 webhook 数据，飞书机器人优先支持</span>
          </div>
          <div class="editor-grid">
            <label class="field">
              <span>订阅 Key</span>
              <input v-model="subscriptionForm.subscriberKey" type="text" placeholder="desk-alpha" />
            </label>
            <label class="field">
              <span>订阅名称</span>
              <input v-model="subscriptionForm.subscriberName" type="text" placeholder="Alpha Desk" />
            </label>
            <label class="field field--wide">
              <span>主通道目标</span>
              <input v-model="subscriptionForm.endpointUrl" type="text" placeholder="Webhook URL、企微机器人 URL、钉钉机器人 URL 或邮箱地址" />
            </label>
            <label class="field">
              <span>主通道类型</span>
              <select v-model="subscriptionForm.channelType">
                <option v-for="option in channelOptions" :key="option" :value="option">
                  {{ option }}
                </option>
              </select>
            </label>
            <label class="field">
              <span>密钥</span>
              <input v-model="subscriptionForm.secretToken" type="text" placeholder="可留空" />
            </label>
            <label class="field">
              <span>默认模板</span>
              <select v-model="subscriptionForm.notificationTemplateId">
                <option value="">不指定</option>
                <option v-for="template in templates" :key="template.id" :value="String(template.id)">
                  {{ template.name }} · {{ template.channel_type }}
                </option>
              </select>
            </label>
            <label class="field">
              <span>默认凭证</span>
              <select v-model="subscriptionForm.notificationCredentialId">
                <option value="">不指定</option>
                <option v-for="credential in credentials" :key="credential.id" :value="String(credential.id)">
                  {{ credential.name }} · {{ credential.channel_type }}
                </option>
              </select>
            </label>
            <label class="field">
              <span>优先级</span>
              <select v-model="subscriptionForm.priorityLevel">
                <option value="critical">critical</option>
                <option value="high">high</option>
                <option value="normal">normal</option>
                <option value="low">low</option>
              </select>
            </label>
            <label class="field">
              <span>顺位</span>
              <input v-model="subscriptionForm.priorityOrder" type="number" min="1" max="9999" />
            </label>
            <label class="field">
              <span>最低信号分</span>
              <input v-model="subscriptionForm.minSignalScore" type="number" min="0" max="100" />
            </label>
            <label class="field">
              <span>去抖窗口（分钟）</span>
              <input v-model="subscriptionForm.debounceWindowMinutes" type="number" min="0" max="1440" />
            </label>
            <label class="field">
              <span>合并窗口（分钟）</span>
              <input v-model="subscriptionForm.mergeWindowMinutes" type="number" min="0" max="1440" />
            </label>
            <label class="field">
              <span>最大合并条数</span>
              <input v-model="subscriptionForm.maxMergeSignals" type="number" min="1" max="50" />
            </label>
            <label class="toggle-field">
              <input v-model="subscriptionForm.enabled" type="checkbox" />
              <span>启用订阅</span>
            </label>
            <label class="toggle-field">
              <input v-model="subscriptionForm.quietHoursEnabled" type="checkbox" />
              <span>启用静默时段</span>
            </label>
            <label class="field">
              <span>静默时区</span>
              <input v-model="subscriptionForm.quietHoursTimezone" type="text" placeholder="Asia/Shanghai" />
            </label>
            <label class="field">
              <span>静默开始</span>
              <input v-model="subscriptionForm.quietHoursStart" type="time" />
            </label>
            <label class="field">
              <span>静默结束</span>
              <input v-model="subscriptionForm.quietHoursEnd" type="time" />
            </label>
            <label class="field field--wide">
              <span>股票代码过滤</span>
              <input v-model="subscriptionForm.securitySymbols" type="text" placeholder="600000, 300059" />
            </label>
            <label class="field field--wide">
              <span>事件链类型过滤</span>
              <input v-model="subscriptionForm.chainTypes" type="text" placeholder="buyback, financing_watch" />
            </label>
            <label class="field field--wide">
              <span>信号类型过滤</span>
              <input v-model="subscriptionForm.signalTypes" type="text" placeholder="alpha_opportunity, risk_alert" />
            </label>
            <label class="field field--wide">
              <span>方向过滤</span>
              <input v-model="subscriptionForm.directions" type="text" placeholder="positive, negative" />
            </label>
            <label class="field field--wide">
              <span>高级通道路由 JSON</span>
              <textarea
                v-model="subscriptionForm.channelRoutesJson"
                rows="8"
                placeholder='[{"route_key":"primary_feishu","channel_type":"feishu_bot","credential_id":1,"template_id":2,"signature_mode":"feishu_v1","priority_order":1,"delivery_tier":"primary"},{"route_key":"ops_wecom","channel_type":"wecom_bot","target":"https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=...","priority_order":20,"delivery_tier":"escalation"}]'
              ></textarea>
            </label>
            <label class="field field--wide">
              <span>升级规则 JSON</span>
              <textarea
                v-model="subscriptionForm.escalationRulesJson"
                rows="6"
                placeholder='[{"after_attempts":2,"route_keys":["ops_wecom"]}]'
              ></textarea>
            </label>
          </div>
          <p class="compact-list__meta">
            飞书机器人建议配置方式：把 webhook 地址和签名密钥放进“渠道凭证”，订阅只绑定默认凭证与模板，避免在规则里散落敏感信息。
          </p>
          <div class="filter-actions">
            <button type="button" class="button button--solid" :disabled="submitting" @click="saveSubscription">
              {{ editorMode === 'edit' ? '保存修改' : '创建订阅' }}
            </button>
            <button type="button" class="button button--ghost" :disabled="submitting" @click="resetSubscriptionForm">
              重置表单
            </button>
            <button
              type="button"
              class="button button--ghost"
              :disabled="submitting || editorMode !== 'edit'"
              @click="testSubscription"
            >
              测试投递
            </button>
          </div>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>订阅列表</h3>
            <span>{{ subscriptions.length }} 条规则</span>
          </div>
          <div v-if="subscriptions.length > 0" class="compact-list">
            <article v-for="subscription in subscriptions" :key="subscription.id" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ subscription.subscriber_name || subscription.subscriber_key }}</p>
                <p class="compact-list__meta">
                  {{ subscription.channel_type }} · 阈值 {{ formatNumber(subscription.min_signal_score, 1) }}
                </p>
                <p class="compact-list__meta">
                  去抖 {{ subscription.debounce_window_minutes ?? 0 }} 分钟 · 合并 {{ subscription.merge_window_minutes ?? 0 }} 分钟
                </p>
                <p class="compact-list__meta">
                  模板 {{ subscription.notification_template_id || '--' }} · 凭证 {{ subscription.notification_channel_credential_id || '--' }}
                </p>
              </div>
              <div class="compact-actions">
                <span :class="priorityClass(subscription.priority_level)">{{ subscription.priority_level }}</span>
                <button type="button" class="button button--ghost button--small" @click="editSubscription(subscription)">
                  编辑
                </button>
                <button type="button" class="button button--ghost button--small" :disabled="submitting" @click="deleteSubscription(subscription.id)">
                  删除
                </button>
              </div>
            </article>
          </div>
          <p v-else class="empty-inline">当前还没有配置任何订阅规则。</p>
        </article>
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Audit</p>
        <h2>通知审计页</h2>
      </div>

      <div class="filter-panel">
        <div class="filter-toolbar">
          <label class="field">
            <span>投递状态</span>
            <select v-model="auditFilters.deliveryStatus">
              <option value="">全部</option>
              <option value="queued">queued</option>
              <option value="retrying">retrying</option>
              <option value="failed">failed</option>
              <option value="suppressed">suppressed</option>
              <option value="merged">merged</option>
              <option value="partial_success">partial_success</option>
              <option value="success">success</option>
              <option value="skipped">skipped</option>
            </select>
          </label>
          <label class="field">
            <span>订阅 Key</span>
            <input v-model="auditFilters.subscriberKey" type="text" placeholder="desk-alpha" />
          </label>
          <label class="field">
            <span>股票代码</span>
            <input v-model="auditFilters.securitySymbol" type="text" placeholder="600000" />
          </label>
          <label class="field">
            <span>信号类型</span>
            <input v-model="auditFilters.signalType" type="text" placeholder="alpha_opportunity" />
          </label>
          <label class="toggle-field">
            <input v-model="auditFilters.needsRetry" type="checkbox" />
            <span>仅看待重试</span>
          </label>
        </div>
        <div class="filter-actions">
          <button type="button" class="button button--solid" @click="applyAuditFilters">
            应用筛选
          </button>
          <button type="button" class="button button--ghost" @click="resetAuditFilters">
            重置筛选
          </button>
        </div>
      </div>

      <div v-if="deliveries.length > 0" class="table-shell">
        <table class="signal-table">
          <thead>
            <tr>
              <th>投递记录</th>
              <th>订阅</th>
              <th>状态</th>
              <th>响应</th>
              <th>时间</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="delivery in deliveries" :key="delivery.id">
              <td>
                <div class="signal-cell">
                  <p class="signal-cell__title">{{ delivery.signal?.title ?? '未关联信号' }}</p>
                  <p class="signal-cell__summary">
                    {{ delivery.signal?.primary_security?.symbol ?? '--' }}
                    · {{ delivery.signal?.signal_type ?? '--' }}
                    · {{ delivery.signal?.latest_event?.timeline_stage ?? '--' }}
                  </p>
                  <p class="compact-list__meta">
                    批次 {{ delivery.batch_key || '--' }} · 抑制原因 {{ delivery.suppression_reason || '--' }}
                  </p>
                </div>
              </td>
              <td>
                <div class="meta-stack">
                  <strong>{{ delivery.subscription?.subscriber_name || delivery.subscription?.subscriber_key || '--' }}</strong>
                  <span>{{ delivery.subscription?.endpoint_url ?? '--' }}</span>
                  <span>顺位 {{ delivery.subscription?.priority_order ?? '--' }}</span>
                </div>
              </td>
              <td>
                <div class="metric-stack">
                  <span :class="deliveryStatusClass(delivery.delivery_status)">{{ delivery.delivery_status }}</span>
                  <span>尝试 {{ delivery.attempts }}</span>
                  <button
                    v-if="['failed', 'retrying', 'queued', 'suppressed'].includes(delivery.delivery_status)"
                    type="button"
                    class="button button--ghost button--small"
                    :disabled="submitting"
                    @click="retryDelivery(delivery.id)"
                  >
                    重试
                  </button>
                </div>
              </td>
              <td>
                <div class="meta-stack">
                  <strong>{{ delivery.response_status ?? '--' }}</strong>
                  <span class="response-preview">{{ delivery.response_body || '暂无响应体' }}</span>
                </div>
              </td>
              <td>
                <div class="meta-stack">
                  <span>创建 {{ formatDateTime(delivery.created_at) }}</span>
                  <span>尝试 {{ formatDateTime(delivery.last_attempted_at) }}</span>
                  <span>送达 {{ formatDateTime(delivery.delivered_at) }}</span>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else-if="!loading" class="empty-state">
        当前筛选下没有通知审计记录。
      </div>

      <div class="pager">
        <button type="button" class="button button--ghost" :disabled="pagination.currentPage <= 1" @click="goToPage(pagination.currentPage - 1)">
          上一页
        </button>
        <p>第 {{ pagination.currentPage }} / {{ pagination.lastPage }} 页，共 {{ pagination.total }} 条</p>
        <button type="button" class="button button--ghost" :disabled="pagination.currentPage >= pagination.lastPage" @click="goToPage(pagination.currentPage + 1)">
          下一页
        </button>
      </div>
    </section>

    <section class="section">
      <div class="section__header">
        <p class="eyebrow">Templates & Credentials</p>
        <h2>通知模板与渠道凭证</h2>
      </div>

      <div class="operations-grid operations-grid--editor">
        <article class="board-panel">
          <div class="board-panel__header">
            <h3>{{ templateEditorMode === 'edit' ? '编辑模板' : '新建模板' }}</h3>
            <span>支持 webhook、企微、钉钉、飞书和邮件模板</span>
          </div>
          <div class="editor-grid">
            <label class="field">
              <span>模板 Key</span>
              <input v-model="templateForm.templateKey" type="text" placeholder="feishu_primary" />
            </label>
            <label class="field">
              <span>模板名称</span>
              <input v-model="templateForm.name" type="text" placeholder="飞书盘中模板" />
            </label>
            <label class="field">
              <span>模板通道</span>
              <select v-model="templateForm.channelType">
                <option v-for="option in channelOptions" :key="option" :value="option">
                  {{ option }}
                </option>
              </select>
            </label>
            <label class="field">
              <span>消息格式</span>
              <select v-model="templateForm.messageFormat">
                <option value="post">post</option>
                <option value="markdown">markdown</option>
                <option value="text">text</option>
              </select>
            </label>
            <label class="field field--wide">
              <span>标题模板</span>
              <input v-model="templateForm.subjectTemplate" type="text" placeholder="[Trading Studio] {{signal.title}}" />
            </label>
            <label class="field field--wide">
              <span>正文模板</span>
              <textarea
                v-model="templateForm.bodyTemplate"
                rows="8"
                placeholder="标题：{{signal.title}}\n证券：{{security.symbol}}\n方向：{{signal.direction}}\n评分：{{signal.signal_score}}"
              ></textarea>
            </label>
            <label class="toggle-field">
              <input v-model="templateForm.enabled" type="checkbox" />
              <span>启用模板</span>
            </label>
          </div>
          <p class="compact-list__meta">可用占位符示例：`{{signal.title}}`、`{{security.symbol}}`、`{{signal.direction}}`、`{{signal.signal_score}}`、`{{batch.count}}`。</p>
          <div class="filter-actions">
            <button type="button" class="button button--solid" :disabled="submitting" @click="saveTemplate">
              {{ templateEditorMode === 'edit' ? '保存模板' : '创建模板' }}
            </button>
            <button type="button" class="button button--ghost" :disabled="submitting" @click="resetTemplateForm">
              重置模板
            </button>
          </div>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>{{ credentialEditorMode === 'edit' ? '编辑凭证' : '新建凭证' }}</h3>
            <span>凭证只回显脱敏值，适合飞书机器人密钥托管</span>
          </div>
          <div class="editor-grid">
            <label class="field">
              <span>凭证 Key</span>
              <input v-model="credentialForm.credentialKey" type="text" placeholder="feishu_ops" />
            </label>
            <label class="field">
              <span>凭证名称</span>
              <input v-model="credentialForm.name" type="text" placeholder="飞书值班机器人" />
            </label>
            <label class="field">
              <span>凭证通道</span>
              <select v-model="credentialForm.channelType">
                <option v-for="option in channelOptions" :key="option" :value="option">
                  {{ option }}
                </option>
              </select>
            </label>
            <label class="field field--wide">
              <span>Webhook / 目标地址</span>
              <input v-model="credentialForm.endpointUrl" type="text" placeholder="飞书 webhook、企微机器人 URL、通用 webhook 或邮箱地址" />
            </label>
            <label class="field">
              <span>通道密钥</span>
              <input v-model="credentialForm.secretToken" type="text" placeholder="可留空，保存后只回显脱敏值" />
            </label>
            <label class="field">
              <span>签名密钥</span>
              <input v-model="credentialForm.signingSecret" type="text" placeholder="飞书签名 secret" />
            </label>
            <label class="toggle-field">
              <input v-model="credentialForm.enabled" type="checkbox" />
              <span>启用凭证</span>
            </label>
          </div>
          <p class="compact-list__meta">飞书机器人启用签名后，请把 `secret` 填到“签名密钥”，服务端会自动生成 `timestamp` 与 `sign`。</p>
          <div class="filter-actions">
            <button type="button" class="button button--solid" :disabled="submitting" @click="saveCredential">
              {{ credentialEditorMode === 'edit' ? '保存凭证' : '创建凭证' }}
            </button>
            <button type="button" class="button button--ghost" :disabled="submitting" @click="resetCredentialForm">
              重置凭证
            </button>
            <button
              type="button"
              class="button button--ghost"
              :disabled="submitting || credentialEditorMode !== 'edit'"
              @click="verifyCredential"
            >
              联调测试
            </button>
          </div>
        </article>
      </div>

      <div class="operations-grid operations-grid--editor">
        <article class="board-panel">
          <div class="board-panel__header">
            <h3>模板列表</h3>
            <span>{{ templates.length }} 个模板</span>
          </div>
          <div v-if="templates.length > 0" class="compact-list">
            <article v-for="template in templates" :key="template.id" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ template.name }}</p>
                <p class="compact-list__meta">
                  {{ template.template_key }} · {{ template.channel_type }} · {{ template.message_format }}
                </p>
              </div>
              <div class="compact-actions">
                <span :class="priorityClass(template.enabled ? 'normal' : 'low')">{{ template.enabled ? 'enabled' : 'disabled' }}</span>
                <button type="button" class="button button--ghost button--small" @click="editTemplate(template)">
                  编辑
                </button>
                <button type="button" class="button button--ghost button--small" :disabled="submitting" @click="deleteTemplate(template.id)">
                  删除
                </button>
              </div>
            </article>
          </div>
          <p v-else class="empty-inline">当前还没有通知模板。</p>
        </article>

        <article class="board-panel">
          <div class="board-panel__header">
            <h3>凭证列表</h3>
            <span>{{ credentials.length }} 个凭证</span>
          </div>
          <div v-if="credentials.length > 0" class="compact-list">
            <article v-for="credential in credentials" :key="credential.id" class="compact-list__item">
              <div>
                <p class="compact-list__title">{{ credential.name }}</p>
                <p class="compact-list__meta">
                  {{ credential.credential_key }} · {{ credential.channel_type }}
                </p>
                <p class="compact-list__meta">
                  {{ credential.endpoint_url_masked || '--' }}
                </p>
                <p class="compact-list__meta">
                  签名 {{ credential.signing_secret_configured ? '已配置' : '未配置' }} · 最近验证 {{ formatDateTime(credential.last_verified_at) }}
                </p>
              </div>
              <div class="compact-actions">
                <span :class="priorityClass(credential.enabled ? 'normal' : 'low')">{{ credential.enabled ? 'enabled' : 'disabled' }}</span>
                <button type="button" class="button button--ghost button--small" @click="editCredential(credential)">
                  编辑
                </button>
                <button type="button" class="button button--ghost button--small" :disabled="submitting" @click="deleteCredential(credential.id)">
                  删除
                </button>
              </div>
            </article>
          </div>
          <p v-else class="empty-inline">当前还没有渠道凭证。</p>
        </article>
      </div>
    </section>
  </main>
</template>
