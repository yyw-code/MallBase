<script setup>
import { ref, computed, onUnmounted } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getOrderDetail } from '@/api/order/order'

const sn = ref('')
const orderId = ref('')
const status = ref('fail')
const amount = ref('')
const message = ref('')

const isSuccess = computed(() => status.value === 'success')
const isPending = computed(() => status.value === 'pending')

const resultMeta = computed(() => {
  if (isSuccess.value) {
    return {
      type: 'success',
      icon: '✓',
      title: '支付成功',
      subtitle: '订单已确认，商家会尽快为你处理',
    }
  }

  if (isPending.value) {
    return {
      type: 'pending',
      icon: '…',
      title: '支付结果确认中',
      subtitle: '正在同步支付状态，请稍候查看订单',
    }
  }

  return {
    type: 'fail',
    icon: '!',
    title: '支付未完成',
    subtitle: message.value || '本次支付没有成功，你可以重新发起支付',
  }
})

// 轮询配置：2s/次，最多 30 次（共 1min）
const POLL_INTERVAL_MS = 2000
const POLL_MAX_TIMES = 30
const ORDER_STATUS_PAID = 10 // 与后端 OrderStatus::PAID 保持一致

let pollTimer = null
let pollCount = 0

function clearPoll() {
  if (pollTimer) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
}

async function pollOrderStatus() {
  if (!orderId.value || pollCount >= POLL_MAX_TIMES) {
    clearPoll()
    if (status.value === 'pending') {
      status.value = 'fail'
      message.value = '支付结果尚未同步，请稍后查看订单'
    }
    return
  }
  pollCount += 1
  try {
    const detail = await getOrderDetail(orderId.value)
    const orderStatus = Number(detail?.status ?? 0)
    if (orderStatus === ORDER_STATUS_PAID) {
      status.value = 'success'
      message.value = ''
      clearPoll()
      return
    }
  } catch {
    // 忽略单次失败，继续下一轮
  }
  pollTimer = setTimeout(pollOrderStatus, POLL_INTERVAL_MS)
}

function normalizeInitialStatus(queryStatus) {
  if (queryStatus === 'fail') {
    return 'fail'
  }
  if (queryStatus === 'success' && !orderId.value) {
    return 'success'
  }
  if (queryStatus === 'success' || queryStatus === 'pending') {
    return 'pending'
  }
  return 'fail'
}

onLoad((query) => {
  sn.value = query?.sn || ''
  orderId.value = query?.order_id || ''
  const queryStatus = query?.status
  status.value = normalizeInitialStatus(queryStatus)
  amount.value = query?.amount || ''
  message.value = String(query?.message || '').trim().slice(0, 160)

  // 兜底回调延迟 / 丢失：有订单号时立即轮询确认，避免前端回调先于支付通知落库。
  if (orderId.value) {
    pollOrderStatus()
  }
})

onUnmounted(() => {
  clearPoll()
})

function goOrderDetail() {
  uni.redirectTo({ url: `/pages-sub/order/detail?id=${orderId.value}` })
}

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

function goRetryPay() {
  uni.navigateBack()
}
</script>

<template>
  <view class="page">
    <mb-navbar title="支付结果" />

    <view class="result" :class="`result--${resultMeta.type}`">
      <view class="result__hero">
        <view class="result__halo">
          <view class="result__icon">
            <text class="result__icon-symbol">{{ resultMeta.icon }}</text>
          </view>
        </view>

        <text class="result__title">{{ resultMeta.title }}</text>
        <text class="result__subtitle">{{ resultMeta.subtitle }}</text>

        <view v-if="isSuccess && amount" class="result__amount">
          <text class="result__amount-prefix">¥</text>
          <text class="result__amount-value">{{ amount }}</text>
        </view>
      </view>

      <view class="result__info">
        <text class="result__info-label">订单编号</text>
        <text class="result__info-value">{{ sn || '-' }}</text>
      </view>

      <view class="result__actions">
        <template v-if="isSuccess">
          <view class="result__btn result__btn--primary" @tap="goOrderDetail">
            <text class="result__btn-text result__btn-text--primary">查看订单</text>
          </view>
          <view class="result__btn result__btn--outline" @tap="goHome">
            <text class="result__btn-text result__btn-text--outline">继续购物</text>
          </view>
        </template>
        <template v-else-if="isPending">
          <view class="result__btn result__btn--primary" @tap="goOrderDetail">
            <text class="result__btn-text result__btn-text--primary">查看订单</text>
          </view>
          <view class="result__btn result__btn--outline" @tap="goHome">
            <text class="result__btn-text result__btn-text--outline">继续购物</text>
          </view>
        </template>
        <template v-else>
          <view class="result__btn result__btn--primary" @tap="goRetryPay">
            <text class="result__btn-text result__btn-text--primary">重新支付</text>
          </view>
          <view class="result__btn result__btn--outline" @tap="goOrderDetail">
            <text class="result__btn-text result__btn-text--outline">查看订单</text>
          </view>
        </template>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: #f6f7fb;
}

.result {
  position: relative;
  display: flex;
  flex-direction: column;
  margin: 24rpx $mb-spacing-page 0;
  padding: 40rpx 32rpx 32rpx;
  overflow: hidden;
  background: #ffffff;
  border: 1rpx solid $mb-color-divider;
  border-radius: 28rpx;

  &::before {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 12rpx;
    content: '';
    background: $mb-color-primary;
  }

  &--success::before {
    background: $mb-color-success;
  }

  &--pending::before {
    background: $mb-color-warning;
  }

  &--fail::before {
    background: $mb-color-error;
  }

  &__hero {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 56rpx 8rpx 40rpx;
  }

  &__halo {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 152rpx;
    height: 152rpx;
    border-radius: 50%;
    background: rgba(13, 80, 213, 0.08);
  }

  &--success &__halo {
    background: rgba(52, 199, 89, 0.1);
  }

  &--pending &__halo {
    background: rgba(240, 173, 78, 0.14);
  }

  &--fail &__halo {
    background: rgba(186, 26, 26, 0.1);
  }

  &__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 104rpx;
    height: 104rpx;
    border-radius: 50%;
    background: $mb-color-primary;
  }

  &--success &__icon {
    background: $mb-color-success;
  }

  &--pending &__icon {
    background: $mb-color-warning;
  }

  &--fail &__icon {
    background: $mb-color-error;
  }

  &__icon-symbol {
    font-size: 52rpx;
    font-weight: 700;
    color: #ffffff;
    line-height: 1;
  }

  &__title {
    margin-top: 36rpx;
    font-size: 40rpx;
    font-weight: 700;
    color: $mb-color-text-title;
    line-height: 1.35;
  }

  &__subtitle {
    margin-top: 12rpx;
    font-size: 26rpx;
    color: $mb-color-text-tertiary;
    line-height: 1.5;
    text-align: center;
  }

  &__amount {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    margin-top: 28rpx;
    color: $mb-color-text-title;
  }

  &__amount-prefix {
    margin-right: 8rpx;
    padding-bottom: 8rpx;
    font-size: 28rpx;
    font-weight: 700;
    line-height: 1;
  }

  &__amount-value {
    font-size: 64rpx;
    font-weight: 800;
    line-height: 1;
  }

  &__info {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 88rpx;
    padding: 0 24rpx;
    background: #f8f9fc;
    border-radius: $mb-radius-md;
  }

  &__info-label {
    flex-shrink: 0;
    margin-right: 24rpx;
    font-size: 26rpx;
    color: $mb-color-text-tertiary;
  }

  &__info-value {
    min-width: 0;
    font-size: 26rpx;
    color: $mb-color-text-secondary;
    line-height: 1.4;
    text-align: right;
    word-break: break-all;
  }

  &__actions {
    display: flex;
    flex-direction: column;
    width: 100%;
    margin-top: 40rpx;
    gap: 20rpx;
  }

  &__btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: $mb-btn-height-lg;
    border-radius: $mb-radius-full;
    transition: opacity 0.15s, transform 0.15s;

    &:active {
      opacity: 0.85;
      transform: scale(0.98);
    }

    &--primary {
      background-color: $mb-color-primary;
    }

    &--outline {
      background-color: transparent;
      border: 2rpx solid $mb-color-border;
    }
  }

  &__btn-text {
    font-size: 30rpx;
    font-weight: 600;
    line-height: 1;

    &--primary {
      color: $mb-color-text-inverse;
    }

    &--outline {
      color: $mb-color-text;
    }
  }
}
</style>
