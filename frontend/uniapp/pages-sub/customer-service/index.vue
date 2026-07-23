<script setup>
import { computed, nextTick, ref } from 'vue'
import { onHide, onLoad, onShow, onUnload } from '@dcloudio/uni-app'
import UniIcons from '@dcloudio/uni-ui/lib/uni-icons/uni-icons.vue'
import {
  createExternalCustomerServiceConversation,
  getCustomerServiceComposer,
  getCustomerServiceMessages,
  markCustomerServiceRead,
  requestCustomerServiceHandoff,
  resolveCustomerServiceAssetUrl,
  searchCustomerServiceResourceCandidates,
  sendCustomerServiceResourceMessage,
  sendCustomerServiceVisitorMessage,
  uploadCustomerServiceAttachment,
} from '@/api/customer-service'
import { useDecorateStore } from '@/store/decorate'
import {
  clearCustomerServiceLaunch,
  consumeCustomerServiceLaunch,
} from '@/utils/customer-service'
import {
  AUTH_CLEARED_EVENT,
  getAuthSessionId,
  isLoggedIn,
} from '@/utils/auth'
import {
  createCustomerServiceClientId,
  normalizeCustomerServiceComposerActions,
} from '@/utils/customer-service-composer'
import {
  normalizeCustomerServiceConversationResource,
  parseCustomerServiceResourceCard,
} from '@/utils/customer-service-resource'
import {
  CUSTOMER_SERVICE_MIN_RECORDING_MS,
  createCustomerServiceRecorder,
} from '@/utils/customer-service-recorder'
import { createCustomerServiceSocket } from '@/utils/customer-service-socket'
import { chooseImageFiles } from '@/utils/image-picker'
import { getUniWindowInfo } from '@/utils/system-info'

const decorateStore = useDecorateStore()

const loading = ref(true)
const loadingMessages = ref(false)
const fatalError = ref('')
const apiBase = ref('')
const socketBase = ref('')
const conversation = ref(null)
const visitorToken = ref('')
const messages = ref([])
const draft = ref('')
const notice = ref('')
const socketConnected = ref(false)
const conversationJoined = ref(false)
const agentOnlineCount = ref(null)
const sending = ref(false)
const uploading = ref(false)
const scrollIntoView = ref('')
const playingMessageId = ref('')
const voiceMode = ref(false)
const voiceSupported = ref(true)
const recording = ref(false)
const recordingElapsedMs = ref(0)
const recordingCancelled = ref(false)
const activeComposerPanel = ref('')
const composer = ref(null)
const composerLoading = ref(false)
const resourceSheetVisible = ref(false)
const selectedResourceAction = ref(null)
const resourceQuery = ref('')
const resourceCandidates = ref([])
const resourceNextCursor = ref('')
const resourceLoading = ref(false)
const resourceLoadingMore = ref(false)
const resourceSendingToken = ref('')
const handoffRequesting = ref(false)

const MESSAGE_POLL_INTERVAL_MS = 3000
const emojiOptions = [
  '😀', '😄', '😁', '😂', '😊', '😍', '🥰', '😘',
  '😎', '🤔', '😅', '😭', '😤', '😡', '👍', '👎',
  '👌', '👏', '🙏', '💪', '🎉', '❤️', '🔥', '✨',
  '🌹', '🎁', '💯', '✅', '👀', '🙌', '🤝', '💬',
]

let launchContext = null
let socket = null
let destroyed = false
let audioContext = null
let authRedirecting = false
let pageVisible = false
let reloadMessagesPending = false
let authSessionId = ''
let recorder = null
let recordingTimer = null
let resourceSearchSequence = 0
let handoffRequestClientId = ''
let messagePollTimer = null
let messagePollCount = 0
let recordingGestureActive = false
let recordingStartY = 0

const windowInfo = getUniWindowInfo()
const menuButtonRect = getCustomerServiceMenuButtonRect()
const statusBarHeight = Number(windowInfo.statusBarHeight) || 0
const headerContentHeight = menuButtonRect
  ? Math.max(44, (menuButtonRect.top - statusBarHeight) * 2 + menuButtonRect.height)
  : 48
const headerRightInset = menuButtonRect
  ? Math.max(12, Number(windowInfo.windowWidth || 375) - menuButtonRect.left + 8)
  : 12
const headerStatusStyle = { height: `${statusBarHeight}px` }
const headerContentStyle = {
  height: `${headerContentHeight}px`,
  paddingRight: `${headerRightInset}px`,
}

const sessionReady = computed(() => Boolean(conversation.value?.id && visitorToken.value))
const conversationClosed = computed(() => conversation.value?.status === 'CLOSED')
const composerFeatures = computed(() => normalizeCustomerServiceComposerActions(composer.value))
const resourceComposerActions = computed(() => composerFeatures.value.resources
  .map((action) => ({
    ...action,
    icon: resourceActionIcon(action.resourceDefinitionCode),
  }))
  .filter((action) => action.icon === 'shop' || action.icon === 'list'))
const emojiAvailable = computed(() => composerFeatures.value.emojiEnabled)
const imageAvailable = computed(() => Boolean(composerFeatures.value.imageAttachment))
const voiceAvailable = computed(() => (
  voiceSupported.value && Boolean(composerFeatures.value.audioAttachment)
))
const hasMoreActions = computed(() => imageAvailable.value || resourceComposerActions.value.length > 0)
const handoffState = computed(() => composerFeatures.value.handoff)
const handoffVisible = computed(() => handoffState.value.enabled)
const canRequestHandoff = computed(() => (
  sessionReady.value
  && !conversationClosed.value
  && !handoffRequesting.value
  && handoffState.value.status === 'AVAILABLE'
))
const handoffLabel = computed(() => {
  if (handoffRequesting.value) return '提交中'
  if (handoffState.value.status === 'QUEUED') return '排队中'
  if (handoffState.value.status === 'ASSIGNED') return '人工服务中'
  if (handoffState.value.status === 'UNAVAILABLE') return '人工暂不可用'
  return '转人工'
})
const canSend = computed(() => (
  sessionReady.value
  && !conversationClosed.value
  && !sending.value
  && !uploading.value
  && !recording.value
))
const canSendText = computed(() => (
  canSend.value
  && composerFeatures.value.textEnabled
  && Boolean(draft.value.trim())
))
const contextResource = computed(() => {
  const resources = Array.isArray(conversation.value?.resources)
    ? conversation.value.resources
    : []
  for (const resource of resources) {
    const normalized = normalizeCustomerServiceConversationResource(resource)
    if (normalized) return normalized
  }
  return null
})
const visibleMessages = computed(() => messages.value.map((message) => ({
  ...message,
  displayBody: formatCustomerServiceMessageBody(message.body),
  displayTime: formatMessageTime(message.createdAt),
  attachmentUrl: resolveCustomerServiceAssetUrl(apiBase.value, message.attachment?.url),
  resourceCard: message.type === 'RESOURCE_CARD'
    ? parseCustomerServiceResourceCard(message.body)
    : null,
})))
const connectionText = computed(() => {
  if (conversationClosed.value) return '本次会话已结束'
  if (socketConnected.value && conversationJoined.value) {
    if (agentOnlineCount.value === 0) return '客服当前离线，可继续留言'
    if (Number(agentOnlineCount.value) > 0) return '客服在线'
    return '在线服务中'
  }
  if (sessionReady.value) return '已连接，可正常发送'
  return '正在连接客服'
})
const connectionClass = computed(() => {
  if (conversationClosed.value) return 'is-closed'
  if (socketConnected.value && conversationJoined.value) return 'is-online'
  if (sessionReady.value) return 'is-fallback'
  return 'is-connecting'
})
const composerPlaceholder = computed(() => {
  if (conversationClosed.value) return '会话已结束'
  return '请输入您的问题'
})
const recordingElapsedText = computed(() => `${Math.max(1, Math.ceil(recordingElapsedMs.value / 1000))}″`)
const composerHint = computed(() => {
  const labels = [...new Set(resourceComposerActions.value.map((action) => action.label).filter(Boolean))]
  return labels.length ? `点击 + 可发送${labels.join('或')}` : ''
})

function getCustomerServiceMenuButtonRect() {
  try {
    if (typeof uni.getMenuButtonBoundingClientRect === 'function') {
      const rect = uni.getMenuButtonBoundingClientRect()
      return rect?.width && rect?.height ? rect : null
    }
  } catch {}
  return null
}

onLoad(() => {
  uni.$on(AUTH_CLEARED_EVENT, handleAuthCleared)
  initializeRecorder()
  if (!ensureCustomerServiceLogin()) return
  authSessionId = getAuthSessionId()
  launchContext = consumeCustomerServiceLaunch()
  if (!launchContext) {
    loading.value = false
    fatalError.value = '客服入口已失效，请返回原页面重新打开'
    return
  }
  apiBase.value = launchContext.apiBase
  socketBase.value = launchContext.socketBase
  initializeConversation()
})

onShow(() => {
  pageVisible = true
  if (!ensureCustomerServiceLogin()) return
  if (!authSessionId || getAuthSessionId() !== authSessionId) {
    invalidateCustomerServiceSession()
    return
  }
  if (!sessionReady.value) return
  loadMessages()
  loadComposer()
  startMessagePolling()
  if (!socket) connectSocket()
})

onHide(() => {
  pageVisible = false
  reloadMessagesPending = false
  closeComposerPanels()
  cancelRecording()
  stopMessagePolling()
  disconnectSocket()
})

onUnload(() => {
  pageVisible = false
  destroyed = true
  reloadMessagesPending = false
  uni.$off(AUTH_CLEARED_EVENT, handleAuthCleared)
  stopMessagePolling()
  destroyRecorder()
  disconnectSocket()
  destroyAudio()
})

async function initializeConversation() {
  if (!launchContext?.contextToken) {
    fatalError.value = '客服身份凭证已失效，请返回重新打开'
    loading.value = false
    return
  }

  loading.value = true
  fatalError.value = ''
  notice.value = ''
  try {
    const created = await createExternalCustomerServiceConversation(
      launchContext.apiBase,
      launchContext.contextToken,
    )
    if (destroyed) return

    conversation.value = created
    visitorToken.value = created.visitorToken
    apiBase.value = launchContext.apiBase
    socketBase.value = launchContext.socketBase
    launchContext = null

    await Promise.all([loadMessages(), loadComposer()])
    if (destroyed) return
    if (pageVisible) {
      startMessagePolling()
      if (!socket) connectSocket()
    }
  } catch (error) {
    if (destroyed) return
    fatalError.value = error?.message || '客服会话创建失败，请稍后重试'
  } finally {
    if (!destroyed) loading.value = false
  }
}

async function loadMessages() {
  if (!sessionReady.value) return
  if (loadingMessages.value) {
    reloadMessagesPending = true
    return
  }
  loadingMessages.value = true
  try {
    const rows = await getCustomerServiceMessages(
      apiBase.value,
      conversation.value.id,
      visitorToken.value,
    )
    mergeMessages(rows)
    await markReadQuietly()
  } catch (error) {
    notice.value = error?.message || '历史消息加载失败'
  } finally {
    loadingMessages.value = false
    if (reloadMessagesPending && pageVisible && !destroyed && sessionReady.value) {
      reloadMessagesPending = false
      loadMessages()
    }
  }
}

function startMessagePolling() {
  if (messagePollTimer || !pageVisible || !sessionReady.value) return
  messagePollCount = 0
  messagePollTimer = setInterval(() => {
    if (!pageVisible || destroyed || !sessionReady.value) return
    loadMessages()
    messagePollCount += 1
    if (messagePollCount % 5 === 0) loadComposer()
  }, MESSAGE_POLL_INTERVAL_MS)
}

function stopMessagePolling() {
  if (!messagePollTimer) return
  clearInterval(messagePollTimer)
  messagePollTimer = null
  messagePollCount = 0
}

async function loadComposer() {
  if (!sessionReady.value || composerLoading.value) return
  const conversationId = conversation.value.id
  const token = visitorToken.value
  composerLoading.value = true
  try {
    const nextComposer = await getCustomerServiceComposer(apiBase.value, conversationId, token)
    if (destroyed || conversation.value?.id !== conversationId || visitorToken.value !== token) return
    composer.value = nextComposer
    const features = normalizeCustomerServiceComposerActions(nextComposer)
    if (!features.emojiEnabled && activeComposerPanel.value === 'emoji') closeComposerPanels()
    if (!features.audioAttachment && voiceMode.value) voiceMode.value = false
    if (!features.resources.some((action) => action.code === selectedResourceAction.value?.code)) {
      closeResourceSheet()
    }
  } catch (error) {
    if (!composer.value) notice.value = error?.message || '会话功能加载失败'
  } finally {
    composerLoading.value = false
  }
}

function connectSocket() {
  disconnectSocket()
  try {
    socket = createCustomerServiceSocket(socketBase.value)
  } catch (error) {
    fatalError.value = error?.message || '客服实时地址无效'
    return
  }

  socket.on('connect', () => {
    socketConnected.value = true
    conversationJoined.value = false
    notice.value = ''
    socket.emit('conversation:join', sessionPayload())
  })
  socket.on('conversation:joined', (payload) => {
    if (payload?.conversationId !== conversation.value?.id) return
    conversationJoined.value = true
    notice.value = ''
    loadMessages()
    loadComposer()
  })
  socket.on('message:new', (payload) => {
    if (payload?.conversationId !== conversation.value?.id) return
    mergeMessages([payload])
    if (payload.senderType === 'AGENT' || payload.senderType === 'AI') {
      markReadQuietly()
    }
  })
  socket.on('message:sent', (payload) => {
    if (payload?.conversationId !== conversation.value?.id) return
    mergeMessages([payload])
  })
  socket.on('conversation:updated', (payload) => {
    if (payload?.id !== conversation.value?.id) return
    conversation.value = payload
    loadMessages()
    loadComposer()
  })
  socket.on('handoff:updated', (payload) => {
    if (!composer.value || !payload?.status) return
    composer.value = {
      ...composer.value,
      handoff: payload,
    }
    if (payload.status === 'ASSIGNED') handoffRequestClientId = ''
  })
  socket.on('online:updated', (payload) => {
    if (typeof payload?.agentOnlineCount === 'number') {
      agentOnlineCount.value = payload.agentOnlineCount
      if (
        payload.agentOnlineCount > 0
        && handoffState.value.status === 'QUEUED'
        && !handoffRequesting.value
      ) {
        requestHandoff(true)
      }
    }
  })
  socket.on('disconnect', () => {
    socketConnected.value = false
    conversationJoined.value = false
  })
  socket.on('connect_error', () => {
    socketConnected.value = false
    conversationJoined.value = false
  })
  const handleSocketError = () => {
    socketConnected.value = false
    conversationJoined.value = false
  }
  socket.on('error', handleSocketError)
  socket.on('exception', handleSocketError)
  socket.connect()
}

function disconnectSocket() {
  if (socket) {
    socket.removeAllListeners()
    socket.disconnect()
    socket = null
  }
  socketConnected.value = false
  conversationJoined.value = false
}

function ensureCustomerServiceLogin() {
  if (isLoggedIn()) return true
  if (authRedirecting) return false

  authRedirecting = true
  destroyed = true
  clearCustomerServiceLaunch()
  launchContext = null
  stopMessagePolling()
  reloadMessagesPending = false
  disconnectSocket()
  destroyRecorder()
  destroyAudio()
  conversation.value = null
  composer.value = null
  closeResourceSheet()
  visitorToken.value = ''
  messages.value = []
  handoffRequesting.value = false
  handoffRequestClientId = ''
  loading.value = false

  uni.redirectTo({
    url: '/pages-sub/user/login',
    fail() {
      uni.reLaunch({ url: '/pages-sub/user/login' })
    },
  })
  return false
}

function handleAuthCleared() {
  invalidateCustomerServiceSession()
}

function invalidateCustomerServiceSession() {
  destroyed = true
  pageVisible = false
  clearCustomerServiceLaunch()
  launchContext = null
  authSessionId = ''
  stopMessagePolling()
  reloadMessagesPending = false
  disconnectSocket()
  destroyRecorder()
  destroyAudio()
  conversation.value = null
  composer.value = null
  closeResourceSheet()
  visitorToken.value = ''
  messages.value = []
  handoffRequesting.value = false
  handoffRequestClientId = ''
  loading.value = false
  fatalError.value = '登录状态已变化，请返回原页面重新进入客服'
}

function sessionPayload() {
  return {
    conversationId: conversation.value?.id,
    visitorToken: visitorToken.value,
  }
}

function mergeMessages(rows) {
  const merged = new Map()
  messages.value.forEach((item) => {
    if (item?.id) merged.set(item.id, item)
  })
  ;(Array.isArray(rows) ? rows : []).forEach((item) => {
    if (item?.id && item.conversationId === conversation.value?.id) {
      merged.set(item.id, item)
    }
  })
  messages.value = Array.from(merged.values()).sort((left, right) => (
    String(left.createdAt || '').localeCompare(String(right.createdAt || ''))
  ))
  scrollToLatest()
}

function scrollToLatest() {
  nextTick(() => {
    const last = messages.value[messages.value.length - 1]
    if (!last?.id) return
    scrollIntoView.value = ''
    nextTick(() => {
      scrollIntoView.value = `message-${last.id}`
    })
  })
}

async function markReadQuietly() {
  if (!sessionReady.value) return
  try {
    await markCustomerServiceRead(apiBase.value, conversation.value.id, visitorToken.value)
  } catch {}
}

function initializeRecorder() {
  if (recorder) return
  recorder = createCustomerServiceRecorder({
    onStart() {
      if (!recordingGestureActive) {
        recorder?.cancel()
        return
      }
      recording.value = true
      recordingCancelled.value = false
      recordingElapsedMs.value = 0
      const startedAt = Date.now()
      clearRecordingTimer()
      recordingTimer = setInterval(() => {
        recordingElapsedMs.value = Date.now() - startedAt
      }, 200)
    },
    onStop(result) {
      const durationMs = Math.max(recordingElapsedMs.value, Number(result?.durationMs) || 0)
      recordingGestureActive = false
      recording.value = false
      recordingCancelled.value = false
      clearRecordingTimer()
      recordingElapsedMs.value = 0
      uploadAndSendVoice({ ...result, durationMs })
    },
    onError(message) {
      recordingGestureActive = false
      recording.value = false
      recordingCancelled.value = false
      clearRecordingTimer()
      recordingElapsedMs.value = 0
      notice.value = String(message || '录音失败，请稍后重试')
    },
  })
  voiceSupported.value = recorder.isSupported
}

function destroyRecorder() {
  clearRecordingTimer()
  recorder?.destroy()
  recorder = null
  recordingGestureActive = false
  recording.value = false
  recordingCancelled.value = false
  recordingElapsedMs.value = 0
}

function clearRecordingTimer() {
  if (!recordingTimer) return
  clearInterval(recordingTimer)
  recordingTimer = null
}

function toggleVoiceMode() {
  if (!voiceAvailable.value) {
    uni.showToast({ title: '当前会话暂不支持语音', icon: 'none' })
    return
  }
  if (conversationClosed.value || sending.value || uploading.value) return
  if (recording.value) cancelRecording()
  voiceMode.value = !voiceMode.value
  activeComposerPanel.value = ''
  hideKeyboardQuietly()
}

function toggleEmojiPanel() {
  if (!emojiAvailable.value || recording.value || conversationClosed.value) return
  voiceMode.value = false
  activeComposerPanel.value = activeComposerPanel.value === 'emoji' ? '' : 'emoji'
  hideKeyboardQuietly()
}

function toggleActionPanel() {
  if (!hasMoreActions.value || recording.value || conversationClosed.value) return
  activeComposerPanel.value = activeComposerPanel.value === 'actions' ? '' : 'actions'
  hideKeyboardQuietly()
}

function closeComposerPanels() {
  activeComposerPanel.value = ''
}

function hideKeyboardQuietly() {
  try {
    uni.hideKeyboard?.()
  } catch {}
}

function appendEmoji(emoji) {
  const next = `${draft.value}${emoji}`
  draft.value = next.slice(0, 1000)
}

async function requestHandoff(silent = false) {
  const status = handoffState.value.status
  const canRetryQueued = silent && status === 'QUEUED'
  if ((!canRequestHandoff.value && !canRetryQueued) || handoffRequesting.value) return

  handoffRequesting.value = true
  if (!handoffRequestClientId) {
    handoffRequestClientId = createCustomerServiceClientId('handoff')
  }
  try {
    const nextState = await requestCustomerServiceHandoff(
      apiBase.value,
      conversation.value.id,
      visitorToken.value,
      handoffRequestClientId,
    )
    if (!composer.value) return
    composer.value = {
      ...composer.value,
      handoff: nextState,
    }
    notice.value = ''
    if (nextState.status === 'ASSIGNED') {
      handoffRequestClientId = ''
      if (!silent) uni.showToast({ title: '已接入人工客服', icon: 'none' })
    } else if (nextState.status === 'QUEUED' && !silent) {
      uni.showToast({ title: '已进入人工服务队列', icon: 'none' })
    }
  } catch (error) {
    if (!silent) notice.value = error?.message || '转人工请求失败'
  } finally {
    handoffRequesting.value = false
  }
}

function resourceActionIcon(resourceDefinitionCode) {
  const code = String(resourceDefinitionCode || '').toLowerCase()
  if (/(product|goods|catalog)/.test(code)) return 'shop'
  if (/order/.test(code)) return 'list'
  return 'link'
}

async function openComposerResource(action) {
  if (!canSend.value || !action) return
  if (action.availability === 'LOGIN_REQUIRED') {
    uni.showToast({ title: '登录后可选择此资源', icon: 'none' })
    return
  }
  closeComposerPanels()
  selectedResourceAction.value = action
  resourceQuery.value = ''
  resourceCandidates.value = []
  resourceNextCursor.value = ''
  resourceSheetVisible.value = true
  await searchResourceCandidates(false)
}

function switchContextResource() {
  const icon = contextResource.value?.type === 'order' ? 'list' : 'shop'
  const action = resourceComposerActions.value.find((item) => (
    item.icon === icon && item.availability === 'ENABLED'
  ))
  if (action) {
    openComposerResource(action)
    return
  }
  openResource(contextResource.value)
}

function closeResourceSheet() {
  resourceSearchSequence += 1
  resourceSheetVisible.value = false
  selectedResourceAction.value = null
  resourceQuery.value = ''
  resourceCandidates.value = []
  resourceNextCursor.value = ''
  resourceLoading.value = false
  resourceLoadingMore.value = false
  resourceSendingToken.value = ''
}

async function searchResourceCandidates(append = false) {
  const action = selectedResourceAction.value
  if (!resourceSheetVisible.value || !action || !sessionReady.value) return
  if (append && (!resourceNextCursor.value || resourceLoadingMore.value)) return
  if (!append && resourceLoading.value) return

  const sequence = ++resourceSearchSequence
  const cursor = append ? resourceNextCursor.value : ''
  if (append) resourceLoadingMore.value = true
  else resourceLoading.value = true
  try {
    const result = await searchCustomerServiceResourceCandidates(
      apiBase.value,
      conversation.value.id,
      visitorToken.value,
      {
        actionCode: action.code,
        query: resourceQuery.value.trim(),
        cursor,
        limit: 10,
        clientRequestId: createCustomerServiceClientId('resource-search'),
      },
    )
    if (sequence !== resourceSearchSequence || selectedResourceAction.value?.code !== action.code) return
    resourceCandidates.value = append
      ? mergeResourceCandidates(resourceCandidates.value, result.items)
      : result.items
    resourceNextCursor.value = result.page.nextCursor || ''
  } catch (error) {
    if (sequence !== resourceSearchSequence) return
    if (isResourceCandidateExpired(error)) {
      resourceCandidates.value = []
      resourceNextCursor.value = ''
      notice.value = '选择列表已过期，请重新搜索'
    } else {
      notice.value = error?.message || `${action.label}加载失败`
    }
  } finally {
    if (sequence === resourceSearchSequence) {
      resourceLoading.value = false
      resourceLoadingMore.value = false
    }
  }
}

function mergeResourceCandidates(current, incoming) {
  const merged = new Map()
  ;[...current, ...incoming].forEach((candidate) => {
    if (candidate?.candidateToken) merged.set(candidate.candidateToken, candidate)
  })
  return Array.from(merged.values())
}

function isResourceCandidateExpired(error) {
  if (Number(error?.statusCode) === 410) return true
  const body = error?.responseBody
  const message = Array.isArray(body?.message) ? body.message.join(' ') : body?.message
  return String(message || error?.message || '').includes('RESOURCE_CANDIDATE_EXPIRED')
}

async function sendResourceCandidate(candidate) {
  const token = String(candidate?.candidateToken || '')
  if (!token || resourceSendingToken.value || !canSend.value) return
  resourceSendingToken.value = token
  try {
    const message = await sendCustomerServiceResourceMessage(
      apiBase.value,
      conversation.value.id,
      visitorToken.value,
      token,
      createCustomerServiceClientId('resource-message'),
    )
    mergeMessages([message])
    notice.value = ''
    closeResourceSheet()
  } catch (error) {
    if (isResourceCandidateExpired(error)) {
      notice.value = '当前选择已过期，请重新选择'
      resourceCandidates.value = []
      resourceNextCursor.value = ''
      await searchResourceCandidates(false)
    } else {
      notice.value = error?.message || '资源发送失败'
    }
  } finally {
    resourceSendingToken.value = ''
  }
}

async function startRecording(event) {
  if (!canSend.value || !voiceAvailable.value || !voiceMode.value || !recorder || recording.value) return
  const touch = event?.touches?.[0] || event?.changedTouches?.[0]
  recordingGestureActive = true
  recordingCancelled.value = false
  recordingStartY = Number(touch?.clientY || touch?.pageY || 0)
  notice.value = ''
  closeComposerPanels()
  const started = await recorder.start()
  if (!started) recordingGestureActive = false
}

function updateRecordingGesture(event) {
  if (!recordingGestureActive) return
  const touch = event?.touches?.[0] || event?.changedTouches?.[0]
  const currentY = Number(touch?.clientY || touch?.pageY || recordingStartY)
  const cancelDistance = typeof uni.upx2px === 'function' ? uni.upx2px(120) : 60
  recordingCancelled.value = recordingStartY - currentY >= cancelDistance
}

function finishRecording() {
  if (!recordingGestureActive && !recording.value) return
  recordingGestureActive = false
  if (recordingCancelled.value) {
    cancelRecording()
    return
  }
  recorder?.stop()
}

function cancelRecording() {
  recordingGestureActive = false
  recorder?.cancel()
  recording.value = false
  recordingCancelled.value = false
  clearRecordingTimer()
  recordingElapsedMs.value = 0
}

function sendText() {
  const body = draft.value.trim()
  if (!canSendText.value || !body) return
  closeComposerPanels()
  emitMessage('TEXT', body)
}

async function chooseImage() {
  if (!canSend.value || !imageAvailable.value) return
  closeComposerPanels()
  const [image] = await chooseImageFiles({
    count: 1,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
  })
  if (image?.path || image?.file) uploadAndSendImage(image)
}

async function uploadAndSendImage(image) {
  uploading.value = true
  notice.value = '图片上传中'
  try {
    const attachment = await uploadCustomerServiceAttachment(
      apiBase.value,
      conversation.value.id,
      image?.file
        ? { file: image.file }
        : { filePath: String(image?.path || '') },
      visitorToken.value,
    )
    notice.value = ''
    uploading.value = false
    await emitMessage('IMAGE', '', attachment)
  } catch (error) {
    notice.value = error?.message || '图片发送失败'
  } finally {
    uploading.value = false
  }
}

async function uploadAndSendVoice(result) {
  const durationMs = Number(result?.durationMs) || 0
  if (durationMs < CUSTOMER_SERVICE_MIN_RECORDING_MS) {
    uni.showToast({ title: '说话时间太短', icon: 'none' })
    return
  }
  uploading.value = true
  notice.value = '语音上传中'
  try {
    const attachment = await uploadCustomerServiceAttachment(
      apiBase.value,
      conversation.value.id,
      {
        file: result?.file || null,
        filePath: String(result?.filePath || ''),
      },
      visitorToken.value,
    )
    notice.value = ''
    uploading.value = false
    await emitMessage('AUDIO', '', attachment)
  } catch (error) {
    notice.value = error?.message || '语音发送失败'
  } finally {
    uploading.value = false
  }
}

async function emitMessage(type, body, attachment = null) {
  if (!canSend.value) return
  const sentDraft = type === 'TEXT' ? body : ''
  sending.value = true
  notice.value = ''
  try {
    const message = await sendCustomerServiceVisitorMessage(
      apiBase.value,
      conversation.value.id,
      visitorToken.value,
      {
        type,
        body,
        attachmentId: attachment?.id,
        clientMessageId: createCustomerServiceClientId('message'),
      },
    )
    mergeMessages([message])
    if (sentDraft && draft.value.trim() === sentDraft) {
      draft.value = ''
    }
    await loadMessages()
  } catch (error) {
    notice.value = error?.message || '消息发送失败，请稍后重试'
  } finally {
    sending.value = false
  }
}

function openResource(resource) {
  if (!resource?.route) return
  uni.navigateTo({ url: resource.route })
}

function previewImage(url) {
  if (!url) return
  uni.previewImage({ urls: [url], current: url })
}

function playAudio(message) {
  const url = message?.attachmentUrl
  if (!url) return
  if (!audioContext) {
    audioContext = uni.createInnerAudioContext()
    audioContext.onEnded(() => {
      playingMessageId.value = ''
    })
    audioContext.onError(() => {
      playingMessageId.value = ''
      uni.showToast({ title: '语音播放失败', icon: 'none' })
    })
  }
  if (playingMessageId.value === message.id) {
    audioContext.stop()
    playingMessageId.value = ''
    return
  }
  audioContext.stop()
  audioContext.src = url
  playingMessageId.value = message.id
  audioContext.play()
}

function destroyAudio() {
  if (!audioContext) return
  audioContext.stop()
  audioContext.destroy()
  audioContext = null
  playingMessageId.value = ''
}

function formatMessageTime(value) {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${hours}:${minutes}`
}

function formatCustomerServiceMessageBody(value) {
  return String(value || '')
    .replace(/```[\s\S]*?```/g, (block) => block.replace(/```[a-z0-9_-]*\n?/gi, '').replace(/```/g, ''))
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/__([^_]+)__/g, '$1')
    .replace(/^[ \t]*#{1,6}[ \t]+/gm, '')
    .replace(/^[ \t]*[-*_]{3,}[ \t]*$/gm, '')
    .replace(/^[ \t]*[-*][ \t]+/gm, '• ')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

function formatResourcePrice(value) {
  const price = String(value || '').trim()
  if (!price) return ''
  if (/^[¥￥$€£]/.test(price)) return price
  if (/^-?\d+(?:\.\d+)?$/.test(price)) return `¥${price}`
  return price
}

function retryOrBack() {
  if (launchContext?.contextToken) {
    initializeConversation()
    return
  }
  goBack()
}

function goBack() {
  uni.navigateBack({
    fail() {
      uni.reLaunch({ url: '/pages/index/index' })
    },
  })
}
</script>

<template>
  <view
    class="customer-service-page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <view class="customer-header">
      <view class="customer-header__status" :style="headerStatusStyle" />
      <view class="customer-header__content" :style="headerContentStyle">
        <view
          class="customer-header__back"
          hover-class="customer-header__control--active"
          @tap="goBack"
        >
          <uni-icons type="back" size="27" color="#191b23" />
        </view>
        <view class="customer-header__brand">
          <view class="customer-header__avatar">
            <image src="/static/logo.png" mode="aspectFit" />
          </view>
          <view class="customer-header__identity">
            <text class="customer-header__title">MallBase 客服</text>
            <view class="customer-header__presence" :class="connectionClass">
              <view class="customer-header__presence-dot" />
              <text>{{ connectionText }}</text>
            </view>
          </view>
        </view>
        <view
          v-if="handoffVisible"
          class="customer-header__handoff"
          :class="{ 'is-disabled': !canRequestHandoff }"
          hover-class="customer-header__control--active"
          @tap="requestHandoff(false)"
        >
          <text>{{ handoffLabel }}</text>
        </view>
      </view>
    </view>

    <view v-if="loading" class="page-state">
      <view class="page-state__spinner" />
      <text class="page-state__text">正在进入客服会话…</text>
    </view>

    <mb-empty-state
      v-else-if="fatalError"
      :text="fatalError"
      :action-text="launchContext ? '重新连接' : '返回'"
      padding-top="180rpx"
      @action="retryOrBack"
    />

    <template v-else>
      <view v-if="contextResource" class="context-card">
        <view
          class="context-card__main"
          hover-class="context-card--active"
          @tap="openResource(contextResource)"
        >
          <image
            v-if="contextResource.imageUrl"
            class="context-card__image"
            :src="contextResource.imageUrl"
            mode="aspectFill"
          />
          <view v-else class="context-card__icon">
            <uni-icons
              :type="contextResource.type === 'order' ? 'list' : 'shop'"
              size="25"
              color="#0d50d5"
            />
          </view>
          <view class="context-card__content">
            <text class="context-card__label">当前咨询{{ contextResource.label }}</text>
            <text class="context-card__title">{{ contextResource.title }}</text>
            <text v-if="contextResource.price" class="context-card__price">
              {{ formatResourcePrice(contextResource.price) }}
            </text>
          </view>
        </view>
        <view
          class="context-card__action"
          hover-class="context-card--active"
          @tap.stop="switchContextResource"
        >
          <text>切换</text>
          <uni-icons type="right" size="18" color="#0d50d5" />
        </view>
      </view>

      <view v-if="notice" class="notice-bar">
        <text>{{ notice }}</text>
      </view>

      <scroll-view
        class="message-list"
        scroll-y
        :scroll-into-view="scrollIntoView"
        :show-scrollbar="false"
      >
        <view class="message-list__inner">
          <mb-empty-state
            v-if="!visibleMessages.length && !loadingMessages"
            text="暂无消息，请输入您想咨询的问题"
            padding-top="120rpx"
          />

          <template v-for="message in visibleMessages" :key="message.id">
            <view
              v-if="message.senderType === 'SYSTEM'"
              :id="`message-${message.id}`"
              class="system-message"
            >
              <text>{{ message.body || '系统消息' }}</text>
            </view>

            <view
              v-else
              :id="`message-${message.id}`"
              class="message-row"
              :class="{ 'is-visitor': message.senderType === 'VISITOR' }"
            >
              <view v-if="message.senderType !== 'VISITOR'" class="message-avatar">
                <image src="/static/logo.png" mode="aspectFit" />
              </view>
              <view class="message-content">
                <view
                  class="message-bubble"
                  :class="{
                    'message-bubble--resource': message.type === 'RESOURCE_CARD',
                    'message-bubble--media': message.type === 'IMAGE',
                  }"
                >
                  <text
                    v-if="message.type === 'TEXT' || message.type === 'EMOJI'"
                    class="message-text"
                    user-select
                  >{{ message.displayBody }}</text>

                  <image
                    v-else-if="message.type === 'IMAGE' && message.attachmentUrl"
                    class="message-image"
                    :src="message.attachmentUrl"
                    mode="widthFix"
                    @tap="previewImage(message.attachmentUrl)"
                  />

                  <view
                    v-else-if="message.type === 'AUDIO' && message.attachmentUrl"
                    class="audio-message"
                    @tap="playAudio(message)"
                  >
                    <uni-icons
                      class="audio-message__icon"
                      :type="playingMessageId === message.id ? 'sound-filled' : 'sound'"
                      size="20"
                      :color="message.senderType === 'VISITOR' ? '#ffffff' : '#596273'"
                    />
                    <text>语音消息</text>
                  </view>

                  <view
                    v-else-if="message.type === 'RESOURCE_CARD' && message.resourceCard"
                    class="resource-card"
                    :class="{ 'is-disabled': !message.resourceCard.route }"
                    @tap="openResource(message.resourceCard)"
                  >
                    <image
                      v-if="message.resourceCard.imageUrl"
                      class="resource-card__image"
                      :src="message.resourceCard.imageUrl"
                      mode="aspectFill"
                    />
                    <view class="resource-card__body">
                      <text class="resource-card__label">{{ message.resourceCard.label }}</text>
                      <text class="resource-card__title">{{ message.resourceCard.title }}</text>
                      <text v-if="message.resourceCard.summary" class="resource-card__summary">
                        {{ message.resourceCard.summary }}
                      </text>
                      <view class="resource-card__footer">
                        <text class="resource-card__price">
                          {{ formatResourcePrice(message.resourceCard.price) }}
                        </text>
                        <text v-if="message.resourceCard.route" class="resource-card__link">查看详情</text>
                      </view>
                    </view>
                  </view>

                  <view v-else-if="message.type === 'SATISFACTION_INVITE'" class="plain-card">
                    <text class="plain-card__title">服务评价邀请</text>
                    <text class="plain-card__text">客服邀请您评价本次服务</text>
                  </view>

                  <text v-else class="message-text message-text--muted">暂不支持此消息类型</text>
                </view>
                <text class="message-time">{{ message.displayTime }}</text>
              </view>
            </view>
          </template>
        </view>
      </scroll-view>

      <view v-if="composerHint" class="composer-hint">
        <uni-icons type="help" size="16" color="#8a8f9d" />
        <text>{{ composerHint }}</text>
      </view>

      <view class="composer">
        <view
          v-if="activeComposerPanel === 'emoji'"
          class="composer-panel composer-panel--emoji"
        >
          <view
            v-for="emoji in emojiOptions"
            :key="emoji"
            class="emoji-option"
            hover-class="emoji-option--active"
            @tap="appendEmoji(emoji)"
          >
            <text>{{ emoji }}</text>
          </view>
        </view>

        <view
          v-if="activeComposerPanel === 'actions'"
          class="composer-panel composer-panel--actions"
        >
          <view
            v-if="imageAvailable"
            class="composer-action"
            :class="{ 'is-disabled': !canSend }"
            hover-class="composer-action--active"
            @tap="chooseImage"
          >
            <view class="composer-action__icon">
              <uni-icons type="image" size="26" color="#0d50d5" />
            </view>
            <text class="composer-action__label">图片</text>
          </view>
          <view
            v-for="action in resourceComposerActions"
            :key="action.code"
            class="composer-action"
            :class="{ 'is-disabled': !canSend || action.availability !== 'ENABLED' }"
            hover-class="composer-action--active"
            @tap="openComposerResource(action)"
          >
            <view class="composer-action__icon">
              <uni-icons :type="action.icon || 'link'" size="26" color="#0d50d5" />
            </view>
            <text class="composer-action__label">{{ action.label }}</text>
          </view>
        </view>

        <view class="composer__main">
          <view
            v-if="voiceAvailable"
            class="composer__mode-toggle"
            :class="{ 'is-active': voiceMode, 'is-disabled': conversationClosed }"
            hover-class="composer-control--active"
            @tap="toggleVoiceMode"
          >
            <uni-icons
              :type="voiceMode ? 'compose' : 'mic'"
              size="25"
              :color="voiceMode ? '#0d50d5' : '#596273'"
            />
          </view>
          <view v-if="!voiceMode" class="composer__input-shell">
            <textarea
              v-model="draft"
              class="composer__input"
              :disabled="conversationClosed || sending"
              :placeholder="composerPlaceholder"
              :maxlength="1000"
              auto-height
              confirm-type="send"
              :cursor-spacing="20"
              @focus="closeComposerPanels"
              @confirm="sendText"
            />
            <view
              v-if="emojiAvailable"
              class="composer__emoji-toggle"
              :class="{ 'is-active': activeComposerPanel === 'emoji', 'is-disabled': conversationClosed }"
              hover-class="composer-control--active"
              @tap="toggleEmojiPanel"
            >
              <uni-icons type="chatbubble" size="24" color="#596273" />
            </view>
          </view>
          <view
            v-else
            class="composer__hold-to-talk"
            :class="{
              'is-recording': recording,
              'is-cancelling': recording && recordingCancelled,
              'is-disabled': !canSend && !recording,
            }"
            hover-class="composer__hold-to-talk--active"
            @touchstart.stop.prevent="startRecording"
            @touchmove.stop.prevent="updateRecordingGesture"
            @touchend.stop.prevent="finishRecording"
            @touchcancel.stop="cancelRecording"
          >
            <text v-if="recording && recordingCancelled">松开取消</text>
            <text v-else>{{ recording ? `松开发送 ${recordingElapsedText}` : '按住说话' }}</text>
          </view>
          <view
            v-if="hasMoreActions"
            class="composer__action-toggle"
            :class="{
              'is-active': activeComposerPanel === 'actions',
              'is-disabled': conversationClosed || sending || uploading,
            }"
            hover-class="composer-control--active"
            @tap="toggleActionPanel"
          >
            <uni-icons type="plus" size="27" color="#596273" />
          </view>
          <view
            class="composer__send"
            :class="{ 'is-disabled': !canSendText }"
            hover-class="composer-control--active"
            @tap="sendText"
          >
            <uni-icons type="paperplane-filled" size="25" color="#ffffff" />
          </view>
        </view>
        <view class="composer__safe-area" />
      </view>

      <view
        v-if="recording"
        class="recording-overlay"
        :class="{ 'is-cancelling': recordingCancelled }"
      >
        <view class="recording-overlay__card">
          <view class="recording-overlay__icon">
            <uni-icons
              :type="recordingCancelled ? 'clear' : 'mic-filled'"
              size="38"
              color="#ffffff"
            />
          </view>
          <text class="recording-overlay__time">{{ recordingElapsedText }}</text>
          <text class="recording-overlay__title">
            {{ recordingCancelled ? '松开手指，取消发送' : '正在录音' }}
          </text>
          <text class="recording-overlay__hint">
            {{ recordingCancelled ? '移回按钮可继续录音' : '上滑可取消' }}
          </text>
        </view>
      </view>

      <mb-customer-service-resource-sheet
        v-model:query="resourceQuery"
        :visible="resourceSheetVisible"
        :action="selectedResourceAction"
        :items="resourceCandidates"
        :loading="resourceLoading"
        :loading-more="resourceLoadingMore"
        :has-more="Boolean(resourceNextCursor)"
        :sending-token="resourceSendingToken"
        @close="closeResourceSheet"
        @search="searchResourceCandidates(false)"
        @load-more="searchResourceCandidates(true)"
        @select="sendResourceCandidate"
      />
    </template>
  </view>
</template>

<style scoped>
.customer-service-page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow: hidden;
  background: var(--color-bg-secondary, #faf8ff);
  color: var(--color-text, #191b23);
}

.customer-header {
  position: relative;
  z-index: 200;
  flex-shrink: 0;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
  background: var(--color-bg, #ffffff);
  box-shadow: 0 8rpx 24rpx rgba(15, 23, 42, 0.04);
}

.customer-header__content {
  display: flex;
  min-height: 96rpx;
  align-items: center;
  gap: 12rpx;
  padding-left: 8rpx;
  box-sizing: border-box;
}

.customer-header__back {
  display: flex;
  width: 88rpx;
  height: 88rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
}

.customer-header__brand {
  display: flex;
  min-width: 0;
  flex: 1;
  align-items: center;
  gap: 16rpx;
}

.customer-header__avatar {
  display: flex;
  width: 64rpx;
  height: 64rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border: 1rpx solid rgba(13, 80, 213, 0.14);
  border-radius: 50%;
  background: rgba(13, 80, 213, 0.06);
}

.customer-header__avatar image {
  width: 48rpx;
  height: 48rpx;
}

.customer-header__identity {
  display: flex;
  min-width: 0;
  flex-direction: column;
}

.customer-header__title {
  overflow: hidden;
  color: var(--color-text, #191b23);
  font-size: 30rpx;
  font-weight: 650;
  line-height: 40rpx;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.customer-header__presence {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 8rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: 22rpx;
  line-height: 30rpx;
}

.customer-header__presence.is-online {
  color: var(--color-success, #26733d);
}

.customer-header__presence.is-fallback {
  color: var(--color-primary, #0d50d5);
}

.customer-header__presence.is-closed {
  color: var(--color-text-tertiary, #737686);
}

.customer-header__presence-dot {
  width: 12rpx;
  height: 12rpx;
  flex-shrink: 0;
  border-radius: 50%;
  background: currentColor;
}

.customer-header__handoff {
  display: flex;
  min-width: 112rpx;
  height: 88rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  margin-left: 4rpx;
  color: var(--color-primary, #0d50d5);
  font-size: 25rpx;
  font-weight: 600;
}

.customer-header__handoff text {
  display: flex;
  min-height: 58rpx;
  align-items: center;
  padding: 0 22rpx;
  border: 2rpx solid rgba(13, 80, 213, 0.42);
  border-radius: 31rpx;
  box-sizing: border-box;
  white-space: nowrap;
}

.customer-header__handoff.is-disabled {
  opacity: 0.5;
}

.customer-header__control--active {
  opacity: 0.68;
}

.page-state {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24rpx;
}

.page-state__spinner {
  width: 48rpx;
  height: 48rpx;
  border: 5rpx solid var(--color-primary-softer, rgba(13, 80, 213, 0.12));
  border-top-color: var(--color-primary, #0d50d5);
  border-radius: 50%;
  animation: customer-service-spin 720ms linear infinite;
}

.page-state__text {
  color: var(--color-text-tertiary, #737686);
  font-size: 28rpx;
}

.context-card {
  display: flex;
  min-height: 132rpx;
  flex-shrink: 0;
  align-items: center;
  padding: 18rpx 32rpx;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
  background: var(--color-bg, #ffffff);
  box-sizing: border-box;
}

.context-card__main {
  display: flex;
  min-width: 0;
  min-height: 96rpx;
  flex: 1;
  align-items: center;
  gap: 20rpx;
}

.context-card--active {
  opacity: 0.82;
}

.context-card__image,
.context-card__icon {
  width: 88rpx;
  height: 88rpx;
  flex-shrink: 0;
  border-radius: 14rpx;
  background: rgba(13, 80, 213, 0.06);
}

.context-card__icon {
  display: flex;
  align-items: center;
  justify-content: center;
}

.context-card__content {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 4rpx;
}

.context-card__label,
.context-card__title,
.context-card__price {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.context-card__label {
  color: var(--color-text-tertiary, #737686);
  font-size: 22rpx;
}

.context-card__title {
  color: var(--color-text, #191b23);
  font-size: 28rpx;
  font-weight: 650;
}

.context-card__price {
  color: var(--color-primary, #0d50d5);
  font-size: 27rpx;
  font-weight: 650;
}

.context-card__action {
  display: flex;
  min-width: 88rpx;
  height: 88rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: flex-end;
  gap: 4rpx;
  color: var(--color-primary, #0d50d5);
  font-size: 25rpx;
}

.notice-bar {
  flex-shrink: 0;
  margin: 14rpx 24rpx 0;
  padding: 14rpx 20rpx;
  border-radius: var(--radius-md, 14rpx);
  background: var(--color-error-soft, #fff0f0);
  color: var(--color-error, #ba1a1a);
  font-size: 23rpx;
  line-height: 1.45;
}

.message-list {
  height: 0;
  min-height: 0;
  flex: 1;
}

.message-list__inner {
  padding: 30rpx 32rpx 40rpx;
}

.message-row {
  display: flex;
  align-items: flex-start;
  gap: 16rpx;
  margin-bottom: 30rpx;
}

.message-row.is-visitor {
  justify-content: flex-end;
}

.message-avatar {
  display: flex;
  width: 64rpx;
  height: 64rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.2));
  border-radius: 50%;
  background: var(--color-bg, #ffffff);
}

.message-avatar image {
  width: 46rpx;
  height: 46rpx;
}

.message-content {
  display: flex;
  max-width: 80%;
  min-width: 0;
  flex-direction: column;
  align-items: flex-start;
}

.is-visitor .message-content {
  align-items: flex-end;
}

.message-bubble {
  max-width: 100%;
  padding: 20rpx 22rpx;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.18));
  border-radius: 8rpx 24rpx 24rpx 24rpx;
  background: var(--color-bg, #ffffff);
  box-shadow: 0 6rpx 20rpx rgba(15, 23, 42, 0.04);
  box-sizing: border-box;
}

.is-visitor .message-bubble {
  border-color: transparent;
  border-radius: 24rpx 8rpx 24rpx 24rpx;
  background: var(--color-primary, #0d50d5);
  color: var(--color-text-inverse, #ffffff);
}

.is-visitor .message-bubble--resource {
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.18));
  background: var(--color-bg, #ffffff);
  color: var(--color-text, #191b23);
}

.is-visitor .message-bubble--media {
  padding: 0;
  border: 0;
  background: transparent;
  box-shadow: none;
}

.message-text {
  font-size: 28rpx;
  line-height: 1.58;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.message-text--muted {
  color: var(--color-text-tertiary, #737686);
}

.message-time {
  margin-top: 8rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: 20rpx;
}

.message-image {
  display: block;
  width: 320rpx;
  max-height: 420rpx;
  border-radius: var(--radius-md, 14rpx);
}

.audio-message {
  display: flex;
  min-width: 220rpx;
  align-items: center;
  gap: 14rpx;
  font-size: 27rpx;
}

.audio-message__icon {
  flex: 0 0 auto;
}

.resource-card {
  display: flex;
  width: 520rpx;
  max-width: 100%;
  align-items: stretch;
  gap: 20rpx;
  overflow: hidden;
}

.resource-card.is-disabled {
  opacity: 0.72;
}

.resource-card__image {
  display: block;
  width: 178rpx;
  height: 170rpx;
  flex-shrink: 0;
  border-radius: var(--radius-md, 14rpx);
  background: var(--color-bg-secondary, #f5f7fb);
}

.resource-card__body {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
}

.resource-card__label {
  color: var(--color-primary, #0d50d5);
  font-size: 21rpx;
  font-weight: 600;
}

.resource-card__title {
  margin-top: 6rpx;
  font-size: 28rpx;
  font-weight: 650;
  line-height: 1.4;
}

.resource-card__summary {
  display: -webkit-box;
  margin-top: 8rpx;
  overflow: hidden;
  color: var(--color-text-tertiary, #737686);
  font-size: 23rpx;
  line-height: 1.45;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.resource-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 14rpx;
}

.resource-card__price {
  color: var(--color-primary, #0d50d5);
  font-size: 30rpx;
  font-weight: 650;
}

.resource-card__link {
  display: flex;
  min-height: 52rpx;
  align-items: center;
  padding: 0 20rpx;
  border-radius: 26rpx;
  background: var(--color-primary, #0d50d5);
  color: #ffffff;
  font-size: 22rpx;
}

.plain-card {
  display: flex;
  min-width: 300rpx;
  flex-direction: column;
  gap: 8rpx;
}

.plain-card__title {
  font-size: 27rpx;
  font-weight: 650;
}

.plain-card__text {
  color: var(--color-text-tertiary, #737686);
  font-size: 23rpx;
}

.system-message {
  display: flex;
  justify-content: center;
  margin: 8rpx 40rpx 26rpx;
}

.system-message text {
  padding: 10rpx 18rpx;
  border-radius: 999rpx;
  background: var(--color-bg-surface, rgba(148, 163, 184, 0.12));
  color: var(--color-text-tertiary, #737686);
  font-size: 21rpx;
  line-height: 1.4;
  text-align: center;
}

.composer-hint {
  display: flex;
  min-height: 52rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  gap: 10rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: 22rpx;
}

.composer {
  position: relative;
  z-index: 300;
  flex-shrink: 0;
  border-top: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.2));
  background: var(--color-bg, #ffffff);
  box-shadow: 0 -8rpx 24rpx rgba(15, 23, 42, 0.04);
}

.composer-panel {
  border-bottom: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.16));
  background: var(--color-bg, #ffffff);
}

.composer-panel--emoji {
  display: flex;
  flex-wrap: wrap;
  padding: 18rpx 20rpx 10rpx;
}

.emoji-option {
  display: flex;
  width: 12.5%;
  height: 68rpx;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md, 14rpx);
  font-size: 42rpx;
  box-sizing: border-box;
}

.emoji-option--active {
  background: var(--color-bg-secondary, #f5f7fb);
}

.composer-panel--actions {
  display: flex;
  flex-wrap: wrap;
  min-height: 196rpx;
  align-items: center;
  padding: 18rpx;
  box-sizing: border-box;
}

.composer-action {
  display: flex;
  width: 33.333%;
  min-height: 152rpx;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 10rpx;
  padding-bottom: 14rpx;
  box-sizing: border-box;
}

.composer-action.is-disabled {
  opacity: 0.45;
}

.composer-action--active .composer-action__icon {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.14));
}

.composer-action__icon {
  display: flex;
  width: 76rpx;
  height: 76rpx;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-lg, 20rpx);
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
}

.composer-action__label {
  max-width: 130rpx;
  overflow: hidden;
  color: var(--color-text-secondary, #596273);
  font-size: 22rpx;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.composer__main {
  display: flex;
  min-height: 112rpx;
  align-items: center;
  gap: 8rpx;
  padding: 12rpx 14rpx;
  box-sizing: border-box;
}

.composer__mode-toggle,
.composer__action-toggle,
.composer__send {
  display: flex;
  width: 72rpx;
  height: 88rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}

.composer__mode-toggle.is-active,
.composer__action-toggle.is-active {
  color: var(--color-primary, #0d50d5);
}

.composer__send {
  width: 72rpx;
  height: 72rpx;
  margin: 8rpx;
  border-radius: 50%;
  background: var(--color-primary, #0d50d5);
  box-shadow: 0 8rpx 18rpx rgba(13, 80, 213, 0.22);
}

.composer__mode-toggle.is-disabled,
.composer__action-toggle.is-disabled,
.composer__send.is-disabled {
  opacity: 0.45;
}

.composer-control--active {
  opacity: 0.72;
}

.composer__input-shell {
  display: flex;
  min-height: 72rpx;
  max-height: 160rpx;
  flex: 1;
  align-items: center;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.24));
  border-radius: 36rpx;
  background: var(--color-bg-secondary, #f5f7fb);
  overflow: hidden;
  box-sizing: border-box;
}

.composer__input {
  min-height: 70rpx;
  max-height: 158rpx;
  min-width: 0;
  flex: 1;
  padding: 16rpx 4rpx 16rpx 18rpx;
  color: var(--color-text, #191b23);
  font-size: 27rpx;
  line-height: 1.35;
  box-sizing: border-box;
}

/* #ifdef H5 */
.customer-service-page {
  height: 100dvh;
}

.composer__input {
  min-height: 0;
}
/* #endif */

.composer__emoji-toggle {
  display: flex;
  width: 72rpx;
  height: 72rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
}

.composer__emoji-toggle.is-active {
  color: var(--color-primary, #0d50d5);
}

.composer__emoji-toggle.is-disabled {
  opacity: 0.45;
}

.composer__hold-to-talk {
  display: flex;
  min-height: 72rpx;
  flex: 1;
  align-items: center;
  justify-content: center;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.24));
  border-radius: 36rpx;
  background: var(--color-bg-secondary, #f5f7fb);
  color: var(--color-text-secondary, #596273);
  font-size: 26rpx;
  font-weight: 600;
  box-sizing: border-box;
}

.composer__hold-to-talk.is-recording,
.composer__hold-to-talk--active {
  border-color: var(--color-primary-softer, rgba(13, 80, 213, 0.24));
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.1));
  color: var(--color-primary, #0d50d5);
}

.composer__hold-to-talk.is-cancelling {
  border-color: rgba(186, 26, 26, 0.28);
  background: rgba(186, 26, 26, 0.08);
  color: var(--color-error, #ba1a1a);
}

.composer__hold-to-talk.is-disabled {
  opacity: 0.5;
}

.recording-overlay {
  position: fixed;
  z-index: 1100;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, 0.18);
  pointer-events: none;
}

.recording-overlay__card {
  display: flex;
  width: 330rpx;
  min-height: 330rpx;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 14rpx;
  border-radius: 32rpx;
  background: rgba(25, 27, 35, 0.9);
  color: #ffffff;
  box-shadow: 0 20rpx 48rpx rgba(15, 23, 42, 0.24);
}

.recording-overlay.is-cancelling .recording-overlay__card {
  background: rgba(143, 22, 22, 0.92);
}

.recording-overlay__icon {
  display: flex;
  width: 92rpx;
  height: 92rpx;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--color-primary, #0d50d5);
}

.is-cancelling .recording-overlay__icon {
  background: var(--color-error, #ba1a1a);
}

.recording-overlay__time {
  font-size: 36rpx;
  font-weight: 650;
}

.recording-overlay__title {
  font-size: 27rpx;
  font-weight: 600;
}

.recording-overlay__hint {
  color: rgba(255, 255, 255, 0.72);
  font-size: 22rpx;
}

.composer__safe-area {
  height: env(safe-area-inset-bottom);
}

@keyframes customer-service-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
