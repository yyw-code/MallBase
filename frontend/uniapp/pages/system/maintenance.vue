<template>
  <view class="maintenance-page">
    <view class="maintenance-card">
      <view class="maintenance-icon">!</view>
      <text class="maintenance-title">系统正在维护</text>
      <text class="maintenance-description">
        为保证数据安全，商城功能暂时不可用。维护结束后将自动返回首页。
      </text>
      <view class="maintenance-status">
        <text class="maintenance-status__label">当前状态</text>
        <text class="maintenance-status__value">{{ stateLabel }}</text>
      </view>
      <text class="maintenance-countdown">
        {{ statusMessage || `${retrySeconds} 秒后自动检查` }}
      </text>
      <button class="maintenance-retry" :loading="loading" @tap="retryNow">
        立即检查
      </button>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
import { fetchMaintenanceStatus } from '@/api/maintenance'

const POLL_INTERVAL = 5000
const loading = ref(false)
const state = ref('maintenance')
const retrySeconds = ref(5)
const statusMessage = ref('')
let pollTimer = null
let countdownTimer = null

const stateLabel = computed(() => {
  const labels = {
    preparing: '准备升级',
    ready_to_drain: '等待排空',
    draining: '正在排空',
    paused: '升级维护中',
    backing_up: '正在备份',
    applying: '正在应用升级',
    awaiting_deployment: '等待部署',
    verifying: '正在验证',
    reconciling: '正在对账',
    failed_maintenance: '等待恢复',
  }
  return labels[state.value] || '维护中'
})

function clearTimers() {
  clearTimeout(pollTimer)
  clearInterval(countdownTimer)
  pollTimer = null
  countdownTimer = null
}

function startCountdown(seconds) {
  clearInterval(countdownTimer)
  retrySeconds.value = Math.max(1, Number(seconds) || 5)
  countdownTimer = setInterval(() => {
    if (retrySeconds.value > 1) retrySeconds.value -= 1
  }, 1000)
}

function schedulePoll() {
  clearTimeout(pollTimer)
  pollTimer = setTimeout(checkStatus, POLL_INTERVAL)
}

async function checkStatus() {
  loading.value = true
  try {
    const status = await fetchMaintenanceStatus()
    state.value = status?.state || 'maintenance'
    statusMessage.value = ''
    if (status?.state === 'normal' || status?.maintenance === false) {
      clearTimers()
      uni.reLaunch({ url: '/pages/index/index' })
      return
    }
    startCountdown(status?.retry_after)
  } catch (_) {
    statusMessage.value = '暂时无法获取维护状态，正在重试'
    startCountdown(5)
  } finally {
    loading.value = false
  }
  schedulePoll()
}

function retryNow() {
  clearTimeout(pollTimer)
  checkStatus()
}

onLoad(checkStatus)
onUnload(clearTimers)
</script>

<style lang="scss" scoped>
.maintenance-page {
  display: flex;
  min-height: 100vh;
  padding: 48rpx;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-secondary);
}

.maintenance-card {
  display: flex;
  width: 100%;
  max-width: 640rpx;
  padding: 56rpx 40rpx;
  border: 1rpx solid var(--color-border);
  border-radius: var(--radius-xl);
  background: var(--color-page-bg);
  align-items: center;
  flex-direction: column;
}

.maintenance-icon {
  display: flex;
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  align-items: center;
  justify-content: center;
  background: var(--color-warning-soft);
  color: var(--color-warning);
  font-size: 56rpx;
  font-weight: 700;
}

.maintenance-title {
  margin-top: 32rpx;
  color: var(--color-text-title-on-page);
  font-size: 40rpx;
  font-weight: 600;
}

.maintenance-description {
  margin-top: 20rpx;
  color: var(--color-text-secondary-on-page);
  font-size: 28rpx;
  line-height: 1.7;
  text-align: center;
}

.maintenance-status {
  display: flex;
  width: 100%;
  margin-top: 40rpx;
  padding: 24rpx;
  border-radius: var(--radius-md);
  background: var(--color-bg-surface);
  align-items: center;
  justify-content: space-between;
}

.maintenance-status__label,
.maintenance-countdown {
  color: var(--color-text-tertiary-on-page);
  font-size: 26rpx;
}

.maintenance-status__value {
  color: var(--color-text-on-surface);
  font-size: 28rpx;
  font-weight: 500;
}

.maintenance-countdown {
  margin-top: 24rpx;
}

.maintenance-retry {
  width: 100%;
  margin-top: 32rpx;
  border-radius: var(--radius-md);
  background: var(--color-primary);
  color: var(--color-text-on-primary);
  font-size: 28rpx;
}

.maintenance-retry::after {
  border: 0;
}
</style>
