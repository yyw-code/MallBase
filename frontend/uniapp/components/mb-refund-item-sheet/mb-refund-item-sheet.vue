<template>
  <view v-if="visible" class="mb-refund-sheet" @tap="onClose">
    <view class="mb-refund-sheet__panel" :class="{ 'mb-refund-sheet__panel--show': show }" @tap.stop>
      <view class="mb-refund-sheet__handle" />
      <view class="mb-refund-sheet__header">
        <view class="mb-refund-sheet__title-wrap">
          <text class="mb-refund-sheet__title">选择售后商品</text>
          <text class="mb-refund-sheet__desc">勾选商品并选择申请数量</text>
        </view>
        <view class="mb-refund-sheet__close" @tap.stop="onClose">
          <text class="mb-refund-sheet__close-icon">×</text>
        </view>
      </view>

      <view class="mb-refund-sheet__tools">
        <view v-if="items.length > 6" class="mb-refund-sheet__search">
          <text class="mb-refund-sheet__search-icon">搜</text>
          <input
            v-model="keyword"
            class="mb-refund-sheet__search-input"
            placeholder="搜索商品名称或规格"
            confirm-type="search"
            placeholder-style="color: #9aa0ad"
          />
        </view>
        <view
          v-if="refundableItems.length > 1"
          class="mb-refund-sheet__select-all"
          @tap.stop="toggleAll"
        >
          <text class="mb-refund-sheet__select-all-text">{{ allSelected ? '清空' : '全选可退' }}</text>
        </view>
      </view>

      <scroll-view scroll-y class="mb-refund-sheet__list">
        <view
          v-for="item in filteredItems"
          :key="getItemId(item)"
          class="mb-refund-sheet__item"
          :class="{
            'mb-refund-sheet__item--active': isSelected(item),
            'mb-refund-sheet__item--disabled': !isRefundItemSelectable(item),
          }"
          @tap.stop="toggleItem(item)"
        >
          <view class="mb-refund-sheet__checkbox" :class="{ 'mb-refund-sheet__checkbox--active': isSelected(item) }">
            <view v-if="isSelected(item)" class="mb-refund-sheet__checkbox-dot" />
          </view>
          <image
            v-if="getItemImage(item)"
            class="mb-refund-sheet__image"
            :src="getItemImage(item)"
            mode="aspectFill"
          />
          <view v-else class="mb-refund-sheet__image mb-refund-sheet__image--placeholder">
            <view class="mb-refund-sheet__placeholder-box" />
          </view>
          <view class="mb-refund-sheet__info">
            <text class="mb-refund-sheet__name">{{ getItemName(item) }}</text>
            <text v-if="getItemSpec(item)" class="mb-refund-sheet__spec">{{ getItemSpec(item) }}</text>
            <view class="mb-refund-sheet__meta">
              <text class="mb-refund-sheet__quantity">
                {{ getRefundItemTip(item) }}
              </text>
              <text v-if="isRefundItemSelectable(item) && getRefundableAmount(item)" class="mb-refund-sheet__amount">
                最高 ¥{{ getRefundableAmount(item) }}
              </text>
            </view>
          </view>
          <view v-if="isSelected(item)" class="mb-refund-sheet__stepper" @tap.stop>
            <view
              class="mb-refund-sheet__stepper-btn"
              :class="{ 'mb-refund-sheet__stepper-btn--disabled': getSelectedQuantity(item) <= 1 }"
              @tap.stop="changeQuantity(item, -1)"
            >
              <text class="mb-refund-sheet__stepper-text">-</text>
            </view>
            <text class="mb-refund-sheet__stepper-value">{{ getSelectedQuantity(item) }}</text>
            <view
              class="mb-refund-sheet__stepper-btn"
              :class="{ 'mb-refund-sheet__stepper-btn--disabled': getSelectedQuantity(item) >= getRefundableQuantity(item) }"
              @tap.stop="changeQuantity(item, 1)"
            >
              <text class="mb-refund-sheet__stepper-text">+</text>
            </view>
          </view>
        </view>
        <view v-if="filteredItems.length === 0" class="mb-refund-sheet__empty">
          <text class="mb-refund-sheet__empty-text">没有匹配的商品</text>
        </view>
      </scroll-view>

      <view class="mb-refund-sheet__footer">
        <view class="mb-refund-sheet__summary">
          <text class="mb-refund-sheet__summary-main">已选 {{ selectedItemCount }} 种商品</text>
          <text class="mb-refund-sheet__summary-sub">共 {{ selectedQuantityTotal }} 件</text>
        </view>
        <view class="mb-refund-sheet__actions">
          <view class="mb-refund-sheet__cancel" @tap.stop="onClose">
            <text class="mb-refund-sheet__cancel-text">取消</text>
          </view>
          <view
            class="mb-refund-sheet__confirm"
            :class="{ 'mb-refund-sheet__confirm--disabled': !canConfirm }"
            @tap.stop="onConfirm"
          >
            <text class="mb-refund-sheet__confirm-text">下一步</text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import config from '@/config/index'

const props = defineProps({
  visible: { type: Boolean, default: false },
  items: { type: Array, default: () => [] },
})

const emit = defineEmits(['confirm', 'close'])

const show = ref(false)
const keyword = ref('')
const selectedMap = ref({})

const refundableItems = computed(() => props.items.filter(isRefundItemSelectable))

const filteredItems = computed(() => {
  const word = keyword.value.trim().toLowerCase()
  if (!word) return props.items
  return props.items.filter((item) => {
    const haystack = `${getItemName(item)} ${getItemSpec(item)}`.toLowerCase()
    return haystack.includes(word)
  })
})

const selectedList = computed(() => Object.values(selectedMap.value))
const selectedItemCount = computed(() => selectedList.value.length)
const selectedQuantityTotal = computed(() =>
  selectedList.value.reduce((sum, row) => sum + Number(row.quantity || 0), 0),
)
const canConfirm = computed(() => selectedItemCount.value > 0)
const allSelected = computed(() =>
  refundableItems.value.length > 0 && selectedItemCount.value === refundableItems.value.length,
)

watch(
  () => props.visible,
  async (value) => {
    if (value) {
      resetSelection()
      await nextTick()
      show.value = true
    } else {
      show.value = false
    }
  },
  { immediate: true },
)

function resetSelection() {
  keyword.value = ''
  const next = {}
  if (refundableItems.value.length === 1) {
    const item = refundableItems.value[0]
    next[getItemId(item)] = { item, quantity: 1 }
  }
  selectedMap.value = next
}

function onClose() {
  emit('close')
}

function toggleAll() {
  if (allSelected.value) {
    selectedMap.value = {}
    return
  }
  const next = {}
  refundableItems.value.forEach((item) => {
    next[getItemId(item)] = { item, quantity: 1 }
  })
  selectedMap.value = next
}

function toggleItem(item) {
  if (!isRefundItemSelectable(item)) {
    uni.showToast({ title: getRefundItemDisabledMessage(item), icon: 'none' })
    return
  }
  const id = getItemId(item)
  const next = { ...selectedMap.value }
  if (next[id]) {
    delete next[id]
  } else {
    next[id] = { item, quantity: 1 }
  }
  selectedMap.value = next
}

function changeQuantity(item, delta) {
  const id = getItemId(item)
  const selected = selectedMap.value[id]
  if (!selected) return
  const max = getRefundableQuantity(item)
  const quantity = Math.min(max, Math.max(1, Number(selected.quantity || 1) + delta))
  selectedMap.value = {
    ...selectedMap.value,
    [id]: { item, quantity },
  }
}

function isSelected(item) {
  return Boolean(selectedMap.value[getItemId(item)])
}

function getSelectedQuantity(item) {
  return Number(selectedMap.value[getItemId(item)]?.quantity || 1)
}

function onConfirm() {
  if (!canConfirm.value) {
    uni.showToast({ title: '请选择售后商品', icon: 'none' })
    return
  }
  emit('confirm', selectedList.value.map((row) => ({
    item: row.item,
    order_item_id: getItemId(row.item),
    quantity: Number(row.quantity || 1),
  })))
}

function normalizeImageUrl(url) {
  if (!url) return ''
  const value = String(url)
  if (/^(https?:)?\/\//.test(value) || value.startsWith('data:') || value.startsWith('blob:')) {
    return value.startsWith('//') ? `https:${value}` : value
  }
  if (value.startsWith('/') && config.baseUrl) {
    return `${config.baseUrl}${value}`
  }
  return value
}

function getItemId(item) {
  return item?.id || item?.order_item_id || item?.goods_id || item?.goods_name || ''
}

function getItemImage(item) {
  return normalizeImageUrl(
    item?.goods_image_full_url
      || item?.goods_image_url
      || item?.main_image_full_url
      || item?.image_full_url
      || item?.cover_full_url
      || item?.goods_image
      || item?.main_image
      || item?.cover
      || '',
  )
}

function getItemName(item) {
  return item?.goods_name || item?.name || '商品信息'
}

function getItemSpec(item) {
  return item?.sku_spec_text || item?.sku_spec || item?.spec_text || item?.spec || ''
}

function getRefundableQuantity(item) {
  const explicit = Number(item?.refundable_quantity)
  if (Number.isFinite(explicit)) return Math.max(0, explicit)
  return Math.max(0, Number(item?.quantity || 0) - Number(item?.refunded_quantity || 0))
}

function hasActiveRefund(item) {
  return item?.has_active_refund === true || Number(item?.has_active_refund || 0) === 1
}

function isRefundItemSelectable(item) {
  return !hasActiveRefund(item) && getRefundableQuantity(item) > 0
}

function getRefundItemTip(item) {
  if (hasActiveRefund(item)) return '售后处理中'
  const quantity = getRefundableQuantity(item)
  return quantity > 0 ? `可申请 ${quantity} 件` : '暂无可退数量'
}

function getRefundItemDisabledMessage(item) {
  return hasActiveRefund(item) ? '该商品已有进行中的售后申请' : '该商品暂无可退数量'
}

function getRefundableAmount(item) {
  const amount = item?.refundable_amount
  return amount !== undefined && amount !== null && amount !== ''
    ? String(amount)
    : ''
}
</script>

<style lang="scss" scoped>
.mb-refund-sheet {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: flex-end;
  background: rgba(0, 0, 0, 0.45);
}

.mb-refund-sheet__panel {
  width: 100%;
  max-height: 82vh;
  display: flex;
  flex-direction: column;
  background: var(--color-bg, #ffffff);
  border-radius: 28rpx 28rpx 0 0;
  transform: translateY(100%);
  transition: transform 0.24s ease-out;
}

.mb-refund-sheet__panel--show {
  transform: translateY(0);
}

.mb-refund-sheet__handle {
  width: 72rpx;
  height: 8rpx;
  margin: 18rpx auto 8rpx;
  border-radius: $mb-radius-full;
  background: var(--color-border, #d8dce6);
}

.mb-refund-sheet__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: $mb-spacing-md;
  padding: 16rpx $mb-spacing-lg 14rpx;
}

.mb-refund-sheet__title-wrap {
  flex: 1;
  min-width: 0;
}

.mb-refund-sheet__title {
  display: block;
  font-size: $mb-font-lg;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.mb-refund-sheet__desc {
  display: block;
  margin-top: 6rpx;
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

.mb-refund-sheet__close {
  flex-shrink: 0;
  width: 48rpx;
  height: 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: $mb-radius-full;
  background: var(--color-bg-surface, #f3f5f9);
}

.mb-refund-sheet__close-icon {
  font-size: $mb-font-md;
  color: var(--color-text-tertiary, #737686);
}

.mb-refund-sheet__tools {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  padding: 0 $mb-spacing-lg $mb-spacing-sm;
}

.mb-refund-sheet__search {
  flex: 1;
  min-width: 0;
  height: 64rpx;
  display: flex;
  align-items: center;
  gap: $mb-spacing-xs;
  padding: 0 $mb-spacing-md;
  border-radius: $mb-radius-full;
  background: var(--color-bg-surface, #f5f7fb);
}

.mb-refund-sheet__search-icon {
  flex-shrink: 0;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
}

.mb-refund-sheet__search-input {
  flex: 1;
  min-width: 0;
  height: 64rpx;
  font-size: $mb-font-sm;
  color: var(--color-text, #191b23);
}

.mb-refund-sheet__select-all {
  flex-shrink: 0;
  height: 64rpx;
  display: flex;
  align-items: center;
  padding: 0 $mb-spacing-md;
  border-radius: $mb-radius-full;
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
}

.mb-refund-sheet__select-all-text {
  font-size: $mb-font-sm;
  font-weight: 600;
  color: var(--color-primary, #0d50d5);
}

.mb-refund-sheet__list {
  flex: 1;
  min-height: 320rpx;
  max-height: 760rpx;
  padding: 0 $mb-spacing-lg;
}

.mb-refund-sheet__item {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  padding: $mb-spacing-md;
  margin-bottom: $mb-spacing-md;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
  background: var(--color-bg, #fbfcff);
}

.mb-refund-sheet__item--active {
  border-color: var(--color-primary-border, rgba(13, 80, 213, 0.42));
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.04));
}

.mb-refund-sheet__item--disabled {
  opacity: 0.52;
}

.mb-refund-sheet__checkbox {
  flex-shrink: 0;
  width: 36rpx;
  height: 36rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2rpx solid var(--color-border, #cbd2df);
  border-radius: $mb-radius-full;
  background: var(--color-bg, #ffffff);
}

.mb-refund-sheet__checkbox--active {
  border-color: var(--color-primary, #0d50d5);
  background: var(--color-primary, #0d50d5);
}

.mb-refund-sheet__checkbox-dot {
  width: 14rpx;
  height: 14rpx;
  border-radius: $mb-radius-full;
  background: var(--color-text-inverse, #ffffff);
}

.mb-refund-sheet__image {
  flex-shrink: 0;
  width: 112rpx;
  height: 112rpx;
  border-radius: $mb-radius-md;
  background: var(--color-bg-surface, #f3f5f9);
}

.mb-refund-sheet__image--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.mb-refund-sheet__placeholder-box {
  width: 52rpx;
  height: 40rpx;
  border-radius: $mb-radius-sm;
  background: linear-gradient(
    135deg,
    var(--color-primary-soft, rgba(13, 80, 213, 0.14)),
    var(--color-divider, rgba(25, 27, 35, 0.06))
  );
}

.mb-refund-sheet__info {
  flex: 1;
  min-width: 0;
}

.mb-refund-sheet__name {
  display: -webkit-box;
  overflow: hidden;
  font-size: $mb-font-md;
  font-weight: 600;
  line-height: 1.38;
  color: var(--color-text-title, #191b23);
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.mb-refund-sheet__spec {
  display: inline-block;
  max-width: 100%;
  margin-top: 6rpx;
  padding: 4rpx 10rpx;
  overflow: hidden;
  border-radius: $mb-radius-sm;
  background: var(--color-bg-surface, #f5f7fb);
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.mb-refund-sheet__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-sm;
  margin-top: 8rpx;
}

.mb-refund-sheet__quantity,
.mb-refund-sheet__amount {
  font-size: $mb-font-xs;
  color: var(--color-text-secondary, #434654);
}

.mb-refund-sheet__amount {
  color: var(--color-primary, #0d50d5);
  font-weight: 600;
}

.mb-refund-sheet__stepper {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  height: 52rpx;
  overflow: hidden;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-full;
  background: var(--color-bg, #ffffff);
}

.mb-refund-sheet__stepper-btn {
  width: 52rpx;
  height: 52rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

.mb-refund-sheet__stepper-btn--disabled {
  opacity: 0.35;
}

.mb-refund-sheet__stepper-text {
  font-size: $mb-font-lg;
  line-height: 1;
  color: var(--color-primary, #0d50d5);
}

.mb-refund-sheet__stepper-value {
  min-width: 42rpx;
  text-align: center;
  font-size: $mb-font-sm;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.mb-refund-sheet__empty {
  padding: 80rpx 0;
  text-align: center;
}

.mb-refund-sheet__empty-text {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

.mb-refund-sheet__footer {
  flex-shrink: 0;
  padding: $mb-spacing-sm $mb-spacing-lg;
  padding-bottom: calc(#{$mb-spacing-sm} + env(safe-area-inset-bottom));
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
  background: var(--color-bg, #ffffff);
}

.mb-refund-sheet__summary {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: $mb-spacing-sm;
}

.mb-refund-sheet__summary-main {
  font-size: $mb-font-sm;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.mb-refund-sheet__summary-sub {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
}

.mb-refund-sheet__actions {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
}

.mb-refund-sheet__cancel,
.mb-refund-sheet__confirm {
  height: 80rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: $mb-radius-full;
}

.mb-refund-sheet__cancel {
  flex: 1;
  border: 1rpx solid var(--color-primary-border, rgba(13, 80, 213, 0.32));
  background: var(--color-bg, #ffffff);
}

.mb-refund-sheet__confirm {
  flex: 1.4;
  background: var(--color-primary, #0d50d5);
}

.mb-refund-sheet__confirm--disabled {
  opacity: 0.45;
}

.mb-refund-sheet__cancel-text,
.mb-refund-sheet__confirm-text {
  font-size: $mb-font-md;
  font-weight: 600;
}

.mb-refund-sheet__cancel-text {
  color: var(--color-primary, #0d50d5);
}

.mb-refund-sheet__confirm-text {
  color: var(--color-text-inverse, #ffffff);
}
</style>
