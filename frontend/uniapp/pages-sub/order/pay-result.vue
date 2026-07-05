<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, computed, onUnmounted } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getOrderDetail, getOrderList } from '@/api/order/order'
import { usePayFlow } from '@/utils/usePayFlow'
const decorateStore = useDecorateStore()

const sn = ref('')
const orderId = ref('')
const status = ref('fail')
const amount = ref('')
const message = ref('')
const retrying = ref(false)
const resolvingOrder = ref(false)

const {
  sheetVisible,
  methods: payMethods,
  loading: payLoading,
  startPay,
  invokePay,
  closeSheet,
} = usePayFlow()

const isSuccess = computed(() => status.value === 'success')
const isPending = computed(() => status.value === 'pending')
const retryPayText = computed(() => (retrying.value || resolvingOrder.value || payLoading.value ? '处理中...' : '重新支付'))

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
let resolveOrderPromise = null

function clearPoll() {
  if (pollTimer) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
}

function normalizePayStatus(payStatus) {
  if (payStatus === 'fail') {
    return 'fail'
  }
  if (payStatus === 'success' && !orderId.value) {
    return 'success'
  }
  if (payStatus === 'success' || payStatus === 'pending') {
    return 'pending'
  }
  return 'fail'
}

function applyPayResult(payResult) {
  if (!payResult) return
  status.value = normalizePayStatus(payResult.status)
  message.value = String(payResult.message || '').trim().slice(0, 160)
  pollCount = 0
  clearPoll()
  if (orderId.value) {
    pollOrderStatus()
  }
}

async function resolveOrderBySn() {
  if (orderId.value) return true
  if (!sn.value) return false
  if (resolveOrderPromise) return await resolveOrderPromise

  resolvingOrder.value = true
  resolveOrderPromise = (async () => {
    const data = await getOrderList({ sn: sn.value, page: 1, limit: 1 })
    const order = Array.isArray(data?.list) ? data.list[0] : null
    if (!order?.id) return false

    orderId.value = String(order.id)
    if (!amount.value) {
      amount.value = order.pay_amount || order.total_amount || ''
    }
    return true
  })()

  try {
    return await resolveOrderPromise
  } catch {
    return false
  } finally {
    resolveOrderPromise = null
    resolvingOrder.value = false
  }
}

async function ensureOrderId() {
  if (orderId.value) return true
  const resolved = await resolveOrderBySn()
  if (!resolved) {
    uni.showToast({ title: '订单信息缺失，请查看订单', icon: 'none' })
  }
  return resolved
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
    if (!amount.value) {
      amount.value = detail?.pay_amount || detail?.total_amount || ''
    }
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
  } else if (sn.value) {
    resolveOrderBySn().then((resolved) => {
      if (resolved) pollOrderStatus()
    })
  }
})

onUnmounted(() => {
  clearPoll()
})

async function goOrderDetail() {
  if (!(await ensureOrderId())) return
  uni.redirectTo({ url: `/pages-sub/order/detail?id=${orderId.value}` })
}

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

async function goRetryPay() {
  if (retrying.value || resolvingOrder.value) return

  retrying.value = true
  try {
    if (!(await ensureOrderId())) return
    const payResult = await startPay(orderId.value)
    if (payResult) applyPayResult(payResult)
  } finally {
    retrying.value = false
  }
}

async function onPayMethodSelect(code) {
  const payResult = await invokePay(code)
  if (payResult) applyPayResult(payResult)
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
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
            <text class="result__btn-text result__btn-text--primary">{{ retryPayText }}</text>
          </view>
          <view class="result__btn result__btn--outline" @tap="goOrderDetail">
            <text class="result__btn-text result__btn-text--outline">查看订单</text>
          </view>
        </template>
      </view>
    </view>

    <mb-pay-method-sheet
      :visible="sheetVisible"
      :methods="payMethods"
      :loading="payLoading"
      :amount="amount"
      @select="onPayMethodSelect"
      @close="closeSheet"
    />
      <mb-copyright-footer />
      <mb-floating-action />
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
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 28rpx;

  &::before {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 12rpx;
    content: '';
    background: var(--color-primary, #0d50d5);
  }

  &--success::before {
    background: var(--color-success, #34c759);
  }

  &--pending::before {
    background: var(--color-warning, #f0ad4e);
  }

  &--fail::before {
    background: var(--color-error, #ba1a1a);
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
    background: var(--color-primary, #0d50d5);
  }

  &--success &__icon {
    background: var(--color-success, #34c759);
  }

  &--pending &__icon {
    background: var(--color-warning, #f0ad4e);
  }

  &--fail &__icon {
    background: var(--color-error, #ba1a1a);
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
    color: var(--color-text-title, #191b23);
    line-height: 1.35;
  }

  &__subtitle {
    margin-top: 12rpx;
    font-size: 26rpx;
    color: var(--color-text-tertiary, #737686);
    line-height: 1.5;
    text-align: center;
  }

  &__amount {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    margin-top: 28rpx;
    color: var(--color-text-title, #191b23);
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
    color: var(--color-text-tertiary, #737686);
  }

  &__info-value {
    min-width: 0;
    font-size: 26rpx;
    color: var(--color-text-secondary, #434654);
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
      background-color: var(--color-primary, #0d50d5);
    }

    &--outline {
      background-color: transparent;
      border: 2rpx solid var(--color-border, #e0e4e8);
    }
  }

  &__btn-text {
    font-size: 30rpx;
    font-weight: 600;
    line-height: 1;

    &--primary {
      color: var(--color-text-inverse, #ffffff);
    }

    &--outline {
      color: var(--color-text, #191b23);
    }
  }
}
</style>
