<template>
  <view v-if="visible" class="mb-pay" @tap="onClose">
    <view class="mb-pay__panel" :class="{ 'mb-pay__panel--show': show }" @tap.stop>
      <view class="mb-pay__header">
        <text class="mb-pay__title">选择支付方式</text>
        <view class="mb-pay__close" @tap.stop="onClose">
          <text class="mb-pay__close-icon">✕</text>
        </view>
      </view>

      <view v-if="amount" class="mb-pay__amount">
        <text class="mb-pay__amount-label">应付金额</text>
        <mb-price :value="amount" size="lg" color="var(--color-text-title, #191b23)" />
      </view>

      <view v-if="loading" class="mb-pay__loading">
        <text class="mb-pay__loading-text">加载支付方式中…</text>
      </view>

      <view v-else-if="!methods.length" class="mb-pay__empty">
        <text class="mb-pay__empty-text">当前无可用支付方式</text>
      </view>

      <view v-else class="mb-pay__list">
        <view
          v-for="m in methods"
          :key="m.code"
          class="mb-pay__row"
          :class="{
            'mb-pay__row--active': selected === m.code,
            'mb-pay__row--disabled': m.disabled,
          }"
          @tap.stop="onPick(m)"
        >
          <view class="mb-pay__icon" :class="iconClass(m.icon)">
            <text class="mb-pay__icon-text">{{ iconGlyph(m.icon) }}</text>
          </view>
          <view class="mb-pay__row-info">
            <text class="mb-pay__row-name">{{ m.name }}</text>
            <text v-if="methodDesc(m)" class="mb-pay__row-desc">{{ methodDesc(m) }}</text>
          </view>
          <view class="mb-pay__radio" :class="{ 'mb-pay__radio--checked': selected === m.code }">
            <view v-if="selected === m.code" class="mb-pay__radio-dot" />
          </view>
        </view>
      </view>

      <view v-if="methods.length" class="mb-pay__footer">
        <view
          class="mb-pay__confirm"
          :class="{ 'mb-pay__confirm--disabled': !selected }"
          @tap.stop="onConfirm"
        >
          <text class="mb-pay__confirm-text">{{ confirmText }}</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref, watch, nextTick } from 'vue'
import { formatPrice } from '@/utils/price'

const props = defineProps({
  visible: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  methods: { type: Array, default: () => [] },
  amount: { type: [Number, String], default: '' },
})

const emit = defineEmits(['select', 'close'])

const show = ref(false)
const selected = ref(null)
const selectedMethod = computed(() => props.methods.find((m) => m.code === selected.value))
const confirmText = computed(() => {
  if (!selected.value) return '请选择支付方式'
  if (props.amount) return `立即支付 ¥${formatPrice(props.amount)}`
  return '立即支付'
})

watch(
  () => props.visible,
  async (v) => {
    if (v) {
      await nextTick()
      show.value = true
      const first = firstEnabled(props.methods)
      if (first && !selected.value) {
        selected.value = first.code
      }
    } else {
      show.value = false
      selected.value = null
    }
  },
  { immediate: true },
)

watch(
  () => props.methods,
  (list) => {
    if (props.visible && list.length && !list.find((m) => m.code === selected.value && !m.disabled)) {
      selected.value = firstEnabled(list)?.code || null
    }
  },
)

function onPick(method) {
  if (!method || method.disabled) return
  selected.value = method.code
}

function onClose() {
  emit('close')
}

function onConfirm() {
  if (!selected.value || selectedMethod.value?.disabled) return
  emit('select', selected.value)
}

function iconClass(icon) {
  if (icon === 'wechat') return 'mb-pay__icon--wechat'
  if (icon === 'wallet' || icon === 'balance') return 'mb-pay__icon--wallet'
  return 'mb-pay__icon--default'
}

function iconGlyph(icon) {
  if (icon === 'wechat') return '微'
  if (icon === 'wallet' || icon === 'balance') return '¥'
  return '￥'
}

function methodDesc(method) {
  if (method.disabled_reason) return method.disabled_reason
  if (method.balance_amount !== undefined) return `可用余额 ¥${formatPrice(method.balance_amount || 0)}`
  if (method.desc) return method.desc
  if (method.icon === 'wechat') return '微信安全支付'
  return ''
}

function firstEnabled(list) {
  return Array.isArray(list) ? list.find((m) => !m.disabled) : null
}
</script>

<style lang="scss" scoped>
.mb-pay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: flex-end;
}

.mb-pay__panel {
  width: 100%;
  max-height: 80vh;
  background: var(--color-bg, #ffffff);
  border-radius: var(--radius-lg, 20rpx) var(--radius-lg, 20rpx) 0 0;
  display: flex;
  flex-direction: column;
  transform: translateY(100%);
  transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.mb-pay__panel--show {
  transform: translateY(0);
}

.mb-pay__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28rpx 32rpx;
  border-bottom: 1rpx solid var(--color-border, #e0e4e8);
}

.mb-pay__title {
  font-size: 30rpx;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
}

.mb-pay__close {
  width: 48rpx;
  height: 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

.mb-pay__close-icon {
  font-size: 28rpx;
  color: var(--color-text-tertiary, #737686);
}

.mb-pay__amount {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding: 24rpx 32rpx;
  border-bottom: 1rpx solid var(--color-border, #e0e4e8);
}

.mb-pay__amount-label {
  font-size: 26rpx;
  color: var(--color-text-secondary, #434654);
}

.mb-pay__loading,
.mb-pay__empty {
  padding: 80rpx 32rpx;
  display: flex;
  justify-content: center;
}

.mb-pay__loading-text,
.mb-pay__empty-text {
  font-size: 26rpx;
  color: var(--color-text-tertiary, #737686);
}

.mb-pay__list {
  padding: 16rpx 0;
}

.mb-pay__row {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 24rpx 32rpx;
}

.mb-pay__row--active {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.04));
}

.mb-pay__row--disabled {
  opacity: 0.52;
}

.mb-pay__icon {
  width: 64rpx;
  height: 64rpx;
  border-radius: 16rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.mb-pay__icon--wechat {
  background: #07c160;
}

.mb-pay__icon--wallet {
  background: var(--color-warning, #f0ad4e);
}

.mb-pay__icon--default {
  background: var(--color-bg-secondary, #faf8ff);
}

.mb-pay__icon-text {
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 600;
}

.mb-pay__row-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  min-width: 0;
}

.mb-pay__row-name {
  font-size: 28rpx;
  color: var(--color-text-title, #191b23);
}

.mb-pay__row-desc {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
}

.mb-pay__radio {
  width: 36rpx;
  height: 36rpx;
  border-radius: 50%;
  border: 2rpx solid var(--color-border, #c4c8d0);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.mb-pay__radio--checked {
  border-color: var(--color-primary, #0d50d5);
}

.mb-pay__radio-dot {
  width: 20rpx;
  height: 20rpx;
  border-radius: 50%;
  background: var(--color-primary, #0d50d5);
}

.mb-pay__footer {
  padding: 24rpx 32rpx;
  padding-bottom: calc(24rpx + env(safe-area-inset-bottom));
  border-top: 1rpx solid var(--color-border, #e0e4e8);
}

.mb-pay__confirm {
  height: 88rpx;
  border-radius: var(--radius-md, 12rpx);
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;

  &:active {
    opacity: 0.85;
  }
}

.mb-pay__confirm--disabled {
  background: var(--color-text-tertiary, #c4c8d0);
  pointer-events: none;
}

.mb-pay__confirm-text {
  font-size: 30rpx;
  font-weight: 600;
  color: var(--color-text-inverse, #ffffff);
}
</style>
