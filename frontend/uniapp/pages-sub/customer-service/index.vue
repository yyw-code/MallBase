<script setup>
import { computed, nextTick, ref } from 'vue'
import { onHide, onLoad, onShow, onUnload } from '@dcloudio/uni-app'
import {
  createExternalCustomerServiceConversation,
  getCustomerServiceMessages,
  markCustomerServiceRead,
  resolveCustomerServiceAssetUrl,
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
  normalizeCustomerServiceConversationResource,
  parseCustomerServiceResourceCard,
} from '@/utils/customer-service-resource'
import { createCustomerServiceSocket } from '@/utils/customer-service-socket'

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

let launchContext = null
let socket = null
let destroyed = false
let sendTimer = null
let pendingDraft = ''
let audioContext = null
let authRedirecting = false
let pageVisible = false
let reloadMessagesPending = false
let authSessionId = ''

const sessionReady = computed(() => Boolean(conversation.value?.id && visitorToken.value))
const conversationClosed = computed(() => conversation.value?.status === 'CLOSED')
const canSend = computed(() => (
  sessionReady.value
  && socketConnected.value
  && conversationJoined.value
  && !conversationClosed.value
  && !sending.value
  && !uploading.value
))
const canSendText = computed(() => canSend.value && Boolean(draft.value.trim()))
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
    return '已连接客服'
  }
  if (sessionReady.value) return '实时连接中断，正在重试'
  return '正在连接客服'
})
const connectionClass = computed(() => {
  if (conversationClosed.value) return 'is-closed'
  if (socketConnected.value && conversationJoined.value) return 'is-online'
  return 'is-connecting'
})
const composerPlaceholder = computed(() => {
  if (conversationClosed.value) return '会话已结束'
  if (!socketConnected.value || !conversationJoined.value) return '连接恢复后可继续发送'
  return '请输入您的问题'
})

onLoad(() => {
  uni.$on(AUTH_CLEARED_EVENT, handleAuthCleared)
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
  if (!socket) connectSocket()
})

onHide(() => {
  pageVisible = false
  reloadMessagesPending = false
  disconnectSocket()
})

onUnload(() => {
  pageVisible = false
  destroyed = true
  reloadMessagesPending = false
  uni.$off(AUTH_CLEARED_EVENT, handleAuthCleared)
  clearSendTimer()
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

    await loadMessages()
    if (destroyed) return
    if (pageVisible && !socket) connectSocket()
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
  })
  socket.on('message:new', (payload) => {
    if (payload?.conversationId !== conversation.value?.id) return
    mergeMessages([payload])
    if (payload.senderType === 'VISITOR') {
      finishSending(true)
    } else if (payload.senderType === 'AGENT' || payload.senderType === 'AI') {
      markReadQuietly()
    }
  })
  socket.on('message:sent', (payload) => {
    if (payload?.conversationId !== conversation.value?.id) return
    mergeMessages([payload])
    if (payload.senderType === 'VISITOR') finishSending(true)
  })
  socket.on('conversation:updated', (payload) => {
    if (payload?.id !== conversation.value?.id) return
    conversation.value = payload
    loadMessages()
  })
  socket.on('online:updated', (payload) => {
    if (typeof payload?.agentOnlineCount === 'number') {
      agentOnlineCount.value = payload.agentOnlineCount
    }
  })
  socket.on('disconnect', () => {
    socketConnected.value = false
    conversationJoined.value = false
    if (!destroyed) notice.value = '实时连接已断开，正在重试'
    finishSending(false)
  })
  socket.on('connect_error', () => {
    socketConnected.value = false
    conversationJoined.value = false
    notice.value = '客服连接异常，正在重试'
    finishSending(false)
  })
  socket.on('error', (payload) => {
    notice.value = typeof payload === 'string'
      ? payload
      : payload?.message || '客服消息处理失败'
    finishSending(false)
  })
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
  clearSendTimer()
  reloadMessagesPending = false
  disconnectSocket()
  destroyAudio()
  conversation.value = null
  visitorToken.value = ''
  messages.value = []
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
  clearSendTimer()
  reloadMessagesPending = false
  disconnectSocket()
  destroyAudio()
  conversation.value = null
  visitorToken.value = ''
  messages.value = []
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

function sendText() {
  const body = draft.value.trim()
  if (!canSend.value || !body) return
  emitMessage('TEXT', body)
}

function chooseImage() {
  if (!canSend.value) return
  uni.chooseImage({
    count: 1,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
    success(result) {
      const filePath = result.tempFilePaths?.[0]
      if (filePath) uploadAndSendImage(filePath)
    },
  })
}

async function uploadAndSendImage(filePath) {
  uploading.value = true
  notice.value = '图片上传中'
  try {
    const attachment = await uploadCustomerServiceAttachment(
      apiBase.value,
      filePath,
      visitorToken.value,
    )
    notice.value = ''
    uploading.value = false
    if (!canSend.value) {
      notice.value = '图片已上传，但实时连接已中断，请恢复后重新选择'
      return
    }
    emitMessage('IMAGE', '', attachment)
  } catch (error) {
    notice.value = error?.message || '图片发送失败'
  } finally {
    uploading.value = false
  }
}

function emitMessage(type, body, attachment = null) {
  if (!canSend.value || !socket) return
  pendingDraft = type === 'TEXT' ? body : ''
  sending.value = true
  notice.value = ''
  socket.emit('message:send', {
    ...sessionPayload(),
    type,
    body,
    attachmentId: attachment?.id,
  })
  clearSendTimer()
  sendTimer = setTimeout(() => {
    notice.value = '消息发送超时，请检查连接后重试'
    finishSending(false)
  }, 12000)
}

function finishSending(succeeded) {
  clearSendTimer()
  if (succeeded && pendingDraft && draft.value.trim() === pendingDraft) {
    draft.value = ''
  }
  pendingDraft = ''
  sending.value = false
}

function clearSendTimer() {
  if (!sendTimer) return
  clearTimeout(sendTimer)
  sendTimer = null
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

function senderLabel(message) {
  if (message.senderType === 'AI') return 'AI'
  if (message.senderType === 'AGENT') return '客'
  return '我'
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
    <mb-navbar title="在线客服" />

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
      <view class="connection-strip" :class="connectionClass">
        <view class="connection-strip__dot" />
        <text class="connection-strip__text">{{ connectionText }}</text>
        <text
          v-if="loadingMessages"
          class="connection-strip__extra"
        >同步消息中</text>
      </view>

      <view
        v-if="contextResource"
        class="context-card"
        hover-class="context-card--active"
        @tap="openResource(contextResource)"
      >
        <view class="context-card__badge">{{ contextResource.label }}</view>
        <view class="context-card__content">
          <text class="context-card__title">{{ contextResource.title }}</text>
          <text v-if="contextResource.summary" class="context-card__summary">
            {{ contextResource.summary }}
          </text>
        </view>
        <text class="context-card__action">查看</text>
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
              <view class="message-avatar">{{ senderLabel(message) }}</view>
              <view class="message-content">
                <view class="message-bubble">
                  <text
                    v-if="message.type === 'TEXT' || message.type === 'EMOJI'"
                    class="message-text"
                    user-select
                  >{{ message.body }}</text>

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
                    <text class="audio-message__icon">
                      {{ playingMessageId === message.id ? '■' : '▶' }}
                    </text>
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
                        <text class="resource-card__price">{{ message.resourceCard.price }}</text>
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

      <view class="composer">
        <view class="composer__main">
          <view
            class="composer__image"
            :class="{ 'is-disabled': !canSend }"
            @tap="chooseImage"
          >
            <text>{{ uploading ? '上传中' : '图片' }}</text>
          </view>
          <textarea
            v-model="draft"
            class="composer__input"
            :disabled="conversationClosed || sending"
            :placeholder="composerPlaceholder"
            :maxlength="1000"
            auto-height
            confirm-type="send"
            :cursor-spacing="20"
            @confirm="sendText"
          />
          <mb-button
            class="composer__send"
            type="primary"
            size="small"
            label="发送"
            :disabled="!canSendText"
            :loading="sending"
            @click="sendText"
          />
        </view>
        <view class="composer__safe-area" />
      </view>
    </template>
  </view>
</template>

<style scoped>
.customer-service-page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  overflow: hidden;
  background: var(--color-bg-secondary, #f5f7fb);
  color: var(--color-text, #191b23);
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

.connection-strip {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  min-height: 64rpx;
  padding: 0 28rpx;
  background: var(--color-warning-soft, #fff7e6);
  color: var(--color-warning, #8a5700);
  font-size: 24rpx;
  box-sizing: border-box;
}

.connection-strip.is-online {
  background: var(--color-success-soft, #edf8f0);
  color: var(--color-success, #26733d);
}

.connection-strip.is-closed {
  background: var(--color-bg-surface, #eef1f6);
  color: var(--color-text-tertiary, #737686);
}

.connection-strip__dot {
  width: 12rpx;
  height: 12rpx;
  margin-right: 12rpx;
  border-radius: 50%;
  background: currentColor;
}

.connection-strip__text {
  flex: 1;
}

.connection-strip__extra {
  margin-left: 16rpx;
  opacity: 0.75;
}

.context-card {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 18rpx;
  margin: 20rpx 24rpx 0;
  padding: 20rpx 22rpx;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.2));
  border-radius: var(--radius-lg, 20rpx);
  background: var(--color-bg, #ffffff);
  box-shadow: 0 8rpx 24rpx rgba(15, 23, 42, 0.05);
}

.context-card--active {
  opacity: 0.82;
}

.context-card__badge {
  flex-shrink: 0;
  padding: 8rpx 14rpx;
  border-radius: 999rpx;
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.08));
  color: var(--color-primary, #0d50d5);
  font-size: 22rpx;
  font-weight: 600;
}

.context-card__content {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 4rpx;
}

.context-card__title,
.context-card__summary {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.context-card__title {
  font-size: 27rpx;
  font-weight: 600;
}

.context-card__summary {
  color: var(--color-text-tertiary, #737686);
  font-size: 23rpx;
}

.context-card__action {
  flex-shrink: 0;
  color: var(--color-primary, #0d50d5);
  font-size: 24rpx;
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
  padding: 28rpx 24rpx 36rpx;
}

.message-row {
  display: flex;
  align-items: flex-start;
  gap: 16rpx;
  margin-bottom: 30rpx;
}

.message-row.is-visitor {
  flex-direction: row-reverse;
}

.message-avatar {
  display: flex;
  width: 64rpx;
  height: 64rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.2));
  border-radius: 50%;
  background: var(--color-bg, #ffffff);
  color: var(--color-primary, #0d50d5);
  font-size: 22rpx;
  font-weight: 700;
}

.is-visitor .message-avatar {
  border-color: transparent;
  background: var(--color-primary, #0d50d5);
  color: var(--color-text-inverse, #ffffff);
}

.message-content {
  display: flex;
  max-width: 76%;
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
  font-size: 22rpx;
}

.resource-card {
  width: 420rpx;
  max-width: 100%;
  overflow: hidden;
}

.resource-card.is-disabled {
  opacity: 0.72;
}

.resource-card__image {
  display: block;
  width: 100%;
  height: 190rpx;
  margin-bottom: 16rpx;
  border-radius: var(--radius-md, 14rpx);
  background: var(--color-bg-secondary, #f5f7fb);
}

.resource-card__body {
  display: flex;
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
  color: var(--color-error, #ba1a1a);
  font-size: 25rpx;
  font-weight: 650;
}

.resource-card__link {
  color: var(--color-primary, #0d50d5);
  font-size: 23rpx;
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

.composer {
  flex-shrink: 0;
  border-top: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.2));
  background: var(--color-bg, #ffffff);
  box-shadow: 0 -8rpx 24rpx rgba(15, 23, 42, 0.04);
}

.composer__main {
  display: flex;
  align-items: flex-end;
  gap: 14rpx;
  padding: 18rpx 22rpx;
}

.composer__image {
  display: flex;
  height: 64rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  padding: 0 18rpx;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.24));
  border-radius: 999rpx;
  color: var(--color-primary, #0d50d5);
  font-size: 23rpx;
  box-sizing: border-box;
}

.composer__image.is-disabled {
  opacity: 0.45;
}

.composer__input {
  min-height: 64rpx;
  max-height: 180rpx;
  flex: 1;
  padding: 15rpx 20rpx;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.24));
  border-radius: 28rpx;
  background: var(--color-bg-secondary, #f5f7fb);
  color: var(--color-text, #191b23);
  font-size: 27rpx;
  line-height: 1.35;
  box-sizing: border-box;
}

.composer__send {
  flex-shrink: 0;
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
