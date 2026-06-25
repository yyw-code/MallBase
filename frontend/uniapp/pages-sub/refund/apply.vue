<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { applyRefundBatch, getRefundReasonOptions } from '@/api/order/refund'
import { getOrderDetail } from '@/api/order/order'
const decorateStore = useDecorateStore()

const orderId = ref('')
const selectedItemInputs = ref([])
const refundItems = ref([])
const refundableAmount = ref('')
const refundableLoading = ref(false)
const refundableLoaded = ref(false)
const orderStatus = ref(null)
const receiveStatus = ref('not_received')
const refundType = ref(0)

const reasonOptions = ref([])
const selectedReason = ref('')
const description = ref('')
const images = ref([])
const submitting = ref(false)

const MAX_IMAGES = 3
const MAX_DESC_LENGTH = 200

onLoad((query) => {
  orderId.value = query?.order_id || ''
  selectedItemInputs.value = parseSelectedItems(query)
  fetchReasonOptions()
  fetchRefundableInfo()
})

async function fetchReasonOptions() {
  try {
    const data = await getRefundReasonOptions()
    reasonOptions.value = Array.isArray(data) ? data : []
  } catch {
    reasonOptions.value = []
  }
}

async function fetchRefundableInfo() {
  if (!orderId.value || selectedItemInputs.value.length === 0) return
  refundableLoading.value = true
  try {
    const detail = await getOrderDetail(orderId.value)
    orderStatus.value = Number(detail?.status)
    const items = Array.isArray(detail?.items)
      ? detail.items
      : Array.isArray(detail?.order_items)
        ? detail.order_items
        : []
    const itemMap = {}
    items.forEach((row) => {
      const id = getOrderItemId(row)
      if (id) itemMap[String(id)] = row
    })

    const nextItems = []
    for (const input of selectedItemInputs.value) {
      const item = itemMap[String(input.order_item_id)]
      if (!item) continue

      const refundableQuantity = getRefundableQuantity(item)
      if (refundableQuantity <= 0) continue

      nextItems.push({
        order_item_id: getOrderItemId(item),
        goods_name: item.goods_name || item.name || '商品',
        goods_image: normalizeImageUrl(
          item.goods_image_full_url
            || item.goods_image_url
            || item.goods_image
            || '',
        ),
        sku_spec_text: item.sku_spec_text || item.sku_spec || item.spec_text || '',
        price: Number(item.unit_price) || 0,
        quantity: Math.min(refundableQuantity, Math.max(1, Number(input.quantity || 1))),
        refundable_quantity: refundableQuantity,
        refundable_amount: normalizeAmount(item.refundable_amount || ''),
      })
    }

    if (nextItems.length === 0) {
      uni.showToast({ title: '售后商品信息不存在或暂无可退数量', icon: 'none' })
      return
    }

    refundItems.value = nextItems
    refundableAmount.value = exactBackendAmount(nextItems)
    initRefundScenario()
    refundableLoaded.value = true
  } catch {
    refundableLoaded.value = false
    uni.showToast({ title: '退款金额获取失败，请稍后重试', icon: 'none' })
  } finally {
    refundableLoading.value = false
  }
}

const receiveStatusOptions = computed(() => {
  if (orderStatus.value === 10) {
    return [{ label: '未收到货', value: 'not_received' }]
  }
  if (orderStatus.value === 30 || orderStatus.value === 40) {
    return [{ label: '已收到货', value: 'received' }]
  }
  return [
    { label: '未收到货', value: 'not_received' },
    { label: '已收到货', value: 'received' },
  ]
})

const refundTypeOptions = computed(() => {
  if (receiveStatus.value !== 'received') {
    return [{ label: '仅退款', value: 0 }]
  }
  return [
    { label: '仅退款', value: 0 },
    { label: '退货退款', value: 1 },
  ]
})

const refundAmountText = computed(() => {
  if (refundableLoading.value) return '计算中'
  if (!refundableLoaded.value) return '以提交后计算为准'
  return refundableAmount.value || '提交后计算'
})

const showRefundAmountSymbol = computed(() => Boolean(refundableAmount.value) && !refundableLoading.value)

const receiveStatusText = computed(() =>
  receiveStatusOptions.value.find((item) => item.value === receiveStatus.value)?.label || '未收到货',
)

const refundTypeText = computed(() =>
  refundTypeOptions.value.find((item) => item.value === refundType.value)?.label || '仅退款',
)

const selectedGoodsCount = computed(() => refundItems.value.length)

const selectedQuantityTotal = computed(() =>
  refundItems.value.reduce((sum, item) => sum + Number(item.quantity || 0), 0),
)

function initRefundScenario() {
  const firstReceive = receiveStatusOptions.value[0]?.value || 'not_received'
  receiveStatus.value = firstReceive
  refundType.value = refundTypeOptions.value[0]?.value || 0
}

function setReceiveStatus(value) {
  if (!receiveStatusOptions.value.some((item) => item.value === value)) return
  receiveStatus.value = value
  if (!refundTypeOptions.value.some((item) => item.value === refundType.value)) {
    refundType.value = refundTypeOptions.value[0]?.value || 0
  }
}

function setRefundType(value) {
  if (!refundTypeOptions.value.some((item) => item.value === value)) return
  refundType.value = value
}

const selectedReasonLabel = computed(() => {
  const option = reasonOptions.value.find((item) => {
    if (typeof item === 'string') return item === selectedReason.value
    return item?.value === selectedReason.value
  })
  if (!option) return ''
  return typeof option === 'string' ? option : option.label || option.name || option.value || ''
})

function parseSelectedItems(query) {
  const raw = query?.selected_items || ''
  if (raw) {
    try {
      const decoded = decodeURIComponent(String(raw))
      const rows = JSON.parse(decoded)
      if (Array.isArray(rows)) {
        const parsed = rows
          .map((row) => ({
            order_item_id: row?.order_item_id || row?.id || '',
            quantity: Math.max(1, Number(row?.quantity || 1)),
          }))
          .filter((row) => row.order_item_id)
        if (parsed.length > 0) return parsed
      }
    } catch {
      // fallback below
    }
  }

  const fallbackId = query?.order_item_id || ''
  if (!fallbackId) return []
  return [{
    order_item_id: fallbackId,
    quantity: Math.max(1, Number(query?.quantity || 1)),
  }]
}

function getOrderItemId(item) {
  return item?.id || item?.order_item_id || ''
}

function getRefundableQuantity(item) {
  const explicit = Number(item?.refundable_quantity)
  if (Number.isFinite(explicit)) return Math.max(0, explicit)
  return Math.max(0, Number(item?.quantity || 0) - Number(item?.refunded_quantity || 0))
}

function formatPrice(val) {
  const num = Number(val)
  if (Number.isNaN(num)) return '0.00'
  return num.toFixed(2)
}

function normalizeAmount(val) {
  const decoded = decodeURIComponent(String(val || ''))
  if (!/^\d+(\.\d{1,2})?$/.test(decoded)) return ''
  return Number(decoded).toFixed(2)
}

function exactBackendAmount(items) {
  let cents = 0
  for (const item of items) {
    if (Number(item.quantity) !== Number(item.refundable_quantity)) return ''
    const amountCents = decimalToCents(item.refundable_amount)
    if (amountCents === null) return ''
    cents += amountCents
  }
  return centsToDecimal(cents)
}

function decimalToCents(amount) {
  const value = String(amount || '').trim()
  if (!/^\d+(\.\d{1,2})?$/.test(value)) return null
  const [yuan, cent = '0'] = value.split('.')
  return Number(yuan) * 100 + Number(cent.padEnd(2, '0').slice(0, 2))
}

function centsToDecimal(cents) {
  const value = Math.max(0, Number(cents || 0))
  return `${Math.floor(value / 100)}.${String(value % 100).padStart(2, '0')}`
}

function normalizeImageUrl(url) {
  const value = String(url || '')
  if (!value) return ''
  if (/^(https?:)?\/\//.test(value) || value.startsWith('data:') || value.startsWith('blob:')) {
    return value.startsWith('//') ? `https:${value}` : value
  }
  return value
}

function onPickReason() {
  if (reasonOptions.value.length === 0) {
    uni.showToast({ title: '暂无可选原因', icon: 'none' })
    return
  }
  const names = reasonOptions.value.map((item) =>
    typeof item === 'string' ? item : item.label || item.name || String(item.value || item),
  )
  uni.showActionSheet({
    itemList: names,
    success(res) {
      const option = reasonOptions.value[res.tapIndex]
      selectedReason.value = typeof option === 'string' ? option : option?.value || ''
    },
  })
}

function onChooseImage() {
  const remaining = MAX_IMAGES - images.value.length
  if (remaining <= 0) {
    uni.showToast({ title: `最多上传${MAX_IMAGES}张`, icon: 'none' })
    return
  }
  uni.chooseImage({
    count: remaining,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
    success(res) {
      images.value = [...images.value, ...res.tempFilePaths].slice(0, MAX_IMAGES)
    },
  })
}

function onRemoveImage(idx) {
  images.value = images.value.filter((_, i) => i !== idx)
}

function onPreviewImage(idx) {
  uni.previewImage({
    current: images.value[idx],
    urls: images.value,
  })
}

async function onSubmit() {
  if (refundItems.value.length === 0) {
    uni.showToast({ title: '请选择要申请售后的商品', icon: 'none' })
    return
  }
  if (!selectedReason.value) {
    uni.showToast({ title: '请选择退款原因', icon: 'none' })
    return
  }
  if (refundableLoading.value || !refundableLoaded.value) {
    uni.showToast({ title: '退款信息加载中，请稍后', icon: 'none' })
    return
  }
  if (submitting.value) return
  submitting.value = true

  try {
    const result = await applyRefundBatch({
      items: refundItems.value.map((item) => ({
        order_item_id: item.order_item_id,
        quantity: item.quantity,
      })),
      type: refundType.value,
      receive_status: receiveStatus.value === 'received' ? 1 : 0,
      reason: selectedReason.value,
      remark: description.value,
    })
    uni.showToast({ title: '退款申请已提交', icon: 'success' })
    setTimeout(() => {
      redirectAfterSubmit(result)
    }, 900)
  } catch {
    // error handled by request interceptor
  } finally {
    submitting.value = false
  }
}

function redirectAfterSubmit(result) {
  const list = Array.isArray(result?.list) ? result.list : []
  if (list.length === 1 && list[0]?.id) {
    uni.redirectTo({ url: `/pages-sub/refund/detail?id=${list[0].id}` })
    return
  }
  uni.redirectTo({ url: '/pages-sub/refund/list' })
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar :title="refundType === 1 ? '申请退货退款' : '申请退款'" />

    <!-- Product info card -->
    <view class="product-card">
      <view class="product-card__header">
        <text class="product-card__title">售后商品</text>
        <text class="product-card__summary">已选 {{ selectedGoodsCount }} 种，共 {{ selectedQuantityTotal }} 件</text>
      </view>
      <view
        v-for="item in refundItems"
        :key="item.order_item_id"
        class="product-card__item"
      >
        <image
          v-if="item.goods_image"
          class="product-card__image"
          :src="item.goods_image"
          mode="aspectFill"
        />
        <view v-else class="product-card__image product-card__image--placeholder">
          <view class="product-card__placeholder-box" />
        </view>
        <view class="product-card__info">
          <text class="product-card__name">{{ item.goods_name || '商品' }}</text>
          <text v-if="item.sku_spec_text" class="product-card__spec">{{ item.sku_spec_text }}</text>
          <view class="product-card__bottom">
            <text class="product-card__price">{{ '¥' }}{{ formatPrice(item.price) }}</text>
            <text class="product-card__qty">{{ '×' }}{{ item.quantity }}</text>
          </view>
        </view>
      </view>
    </view>

    <!-- Form fields -->
    <view class="form-section">
      <view class="form-section__header">
        <text class="form-section__title">申请信息</text>
      </view>
      <view class="form-item form-item--column">
        <text class="form-item__label">收货状态</text>
        <view v-if="receiveStatusOptions.length <= 1" class="readonly-box">
          <text class="readonly-box__value">{{ receiveStatusText }}</text>
          <text class="readonly-box__hint">由当前订单状态确定</text>
        </view>
        <view v-else class="option-grid">
          <view
            v-for="item in receiveStatusOptions"
            :key="item.value"
            class="option-grid__item"
            :class="{ 'option-grid__item--active': receiveStatus === item.value }"
            @tap="setReceiveStatus(item.value)"
          >
            <text class="option-grid__text">{{ item.label }}</text>
          </view>
        </view>
      </view>
      <view class="form-item form-item--column">
        <text class="form-item__label">售后类型</text>
        <view v-if="refundTypeOptions.length <= 1" class="readonly-box">
          <text class="readonly-box__value">{{ refundTypeText }}</text>
          <text class="readonly-box__hint">由收货状态自动匹配</text>
        </view>
        <view v-else class="option-grid">
          <view
            v-for="item in refundTypeOptions"
            :key="item.value"
            class="option-grid__item"
            :class="{ 'option-grid__item--active': refundType === item.value }"
            @tap="setRefundType(item.value)"
          >
            <text class="option-grid__text">{{ item.label }}</text>
          </view>
        </view>
      </view>
      <!-- Reason picker -->
      <view class="form-item" @tap="onPickReason">
        <text class="form-item__label">退款原因</text>
        <view class="form-item__value-wrap">
          <text
            class="form-item__value"
            :class="{ 'form-item__value--placeholder': !selectedReason }"
          >{{ selectedReasonLabel || '请选择退款原因' }}</text>
          <text class="form-item__arrow">&#x203A;</text>
        </view>
      </view>

      <!-- Description -->
      <view class="form-item form-item--column">
        <text class="form-item__label">退款说明</text>
        <view class="textarea-wrap">
        <textarea
          v-model="description"
          class="form-textarea"
          placeholder="请详细描述您遇到的问题..."
          :maxlength="MAX_DESC_LENGTH"
          placeholder-style="color: #737686"
        />
          <text class="form-textarea__count">
            {{ description.length }}/{{ MAX_DESC_LENGTH }}
          </text>
        </view>
      </view>

      <!-- Image upload -->
      <view class="form-item form-item--column">
        <text class="form-item__label">上传凭证（最多{{ MAX_IMAGES }}张）</text>
        <view class="image-grid">
          <view
            v-for="(img, idx) in images"
            :key="idx"
            class="image-grid__item"
          >
            <image
              class="image-grid__img"
              :src="img"
              mode="aspectFill"
              @tap="onPreviewImage(idx)"
            />
            <view class="image-grid__remove" @tap.stop="onRemoveImage(idx)">
              <text class="image-grid__remove-text">&#x2715;</text>
            </view>
          </view>
          <view
            v-if="images.length < MAX_IMAGES"
            class="image-grid__add"
            @tap="onChooseImage"
          >
            <text class="image-grid__add-icon">+</text>
          </view>
        </view>
      </view>
    </view>

    <view class="bottom-spacer" />
    <view class="submit-bar">
      <view class="submit-bar__amount">
        <text class="submit-bar__label">预计退款</text>
        <view class="submit-bar__value-wrap">
          <text v-if="showRefundAmountSymbol" class="submit-bar__symbol">{{ '¥' }}</text>
          <text
            class="submit-bar__value"
            :class="{ 'submit-bar__value--pending': !showRefundAmountSymbol }"
          >{{ refundAmountText }}</text>
        </view>
        <text class="submit-bar__hint">以后端实时计算为准</text>
      </view>
      <view
        class="submit-btn"
        :class="{ 'submit-btn--disabled': submitting }"
        @tap="onSubmit"
      >
        <text class="submit-btn__text">
          {{ submitting ? '提交中...' : '提交退款申请' }}
        </text>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: #f6f8fb;
  padding: 0 24rpx;
}

// ---- Product card ----
.product-card {
  margin-top: 18rpx;
  background: var(--color-bg, #ffffff);
  border-radius: 16rpx;
  border: 1rpx solid rgba(25, 27, 35, 0.06);
  overflow: hidden;
}

.product-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-md;
  padding: 22rpx 24rpx;
  background: #fff;
  border-bottom: 1rpx solid rgba(25, 27, 35, 0.06);
}

.product-card__title {
  font-size: 30rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.product-card__summary {
  flex-shrink: 0;
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
}

.product-card__item {
  display: flex;
  gap: 20rpx;
  padding: 22rpx 24rpx;
  border-top: 1rpx solid rgba(25, 27, 35, 0.06);
}

.product-card__header + .product-card__item {
  border-top: 0;
}

.product-card__image {
  flex-shrink: 0;
  width: 104rpx;
  height: 104rpx;
  border-radius: 12rpx;
  background: #f2f5fa;
}

.product-card__image--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.product-card__placeholder-box {
  width: 44rpx;
  height: 32rpx;
  border-radius: 8rpx;
  background: rgba(13, 80, 213, 0.12);
  position: relative;

  &::after {
    content: '';
    position: absolute;
    left: 10rpx;
    right: 10rpx;
    top: 10rpx;
    height: 4rpx;
    border-radius: $mb-radius-full;
    background: var(--color-primary, #0d50d5);
    opacity: 0.32;
  }
}

.product-card__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-width: 0;
}

.product-card__name {
  font-size: 28rpx;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  line-height: 1.36;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.product-card__spec {
  display: inline-block;
  max-width: 100%;
  margin-top: 8rpx;
  padding: 4rpx 10rpx;
  overflow: hidden;
  border-radius: 8rpx;
  background: #f4f6fa;
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
  white-space: nowrap;
  text-overflow: ellipsis;
}

.product-card__bottom {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-top: 8rpx;
}

.product-card__price {
  font-size: 26rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.product-card__qty {
  padding: 3rpx 12rpx;
  border-radius: $mb-radius-full;
  background: #f4f6fa;
  font-size: 22rpx;
  color: var(--color-text-secondary, #434654);
}

// ---- Form section ----
.form-section {
  margin-top: 18rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid rgba(25, 27, 35, 0.06);
  border-radius: 16rpx;
  padding: 0 24rpx;
}

.form-section__header {
  padding: 24rpx 0 6rpx;
}

.form-section__title {
  font-size: 30rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.form-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 24rpx 0;
  border-bottom: 1rpx solid rgba(25, 27, 35, 0.06);
}

.form-item:last-child {
  border-bottom: 0;
}

.form-item--column {
  flex-direction: column;
  align-items: flex-start;
}

.form-item__label {
  font-size: 28rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  flex-shrink: 0;
  margin-bottom: 0;
}

.form-item--column .form-item__label {
  margin-bottom: $mb-spacing-md;
}

.form-item__value-wrap {
  display: flex;
  align-items: center;
  gap: $mb-spacing-xs;
  flex: 1;
  justify-content: flex-end;
  min-width: 0;
}

.form-item__value {
  font-size: 28rpx;
  color: var(--color-text, #191b23);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.form-item__value--placeholder {
  color: var(--color-text-tertiary, #737686);
}

.form-item__arrow {
  font-size: 34rpx;
  color: var(--color-text-tertiary, #737686);
  font-weight: 300;
}

.readonly-box {
  width: 100%;
  min-height: 64rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-md;
  padding: 0 18rpx;
  border-radius: 14rpx;
  background: #f7f9fc;
}

.readonly-box__value {
  font-size: 28rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.readonly-box__hint {
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
}

// ---- Option buttons ----
.option-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220rpx, 1fr));
  gap: 16rpx;
  width: 100%;
}

.option-grid__item {
  height: 72rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 14rpx;
  border: 1rpx solid rgba(25, 27, 35, 0.08);
  background: #f7f9fc;
  transition: opacity 0.15s;

  &:active {
    opacity: 0.82;
  }
}

.option-grid__item--active {
  border-color: rgba(13, 80, 213, 0.5);
  background: rgba(13, 80, 213, 0.09);
}

.option-grid__text {
  font-size: 26rpx;
  font-weight: 600;
  color: var(--color-text-secondary, #434654);
}

.option-grid__item--active .option-grid__text {
  color: var(--color-primary, #0d50d5);
}

// ---- Textarea ----
.textarea-wrap {
  position: relative;
  width: 100%;
}

.form-textarea {
  width: 100%;
  height: 176rpx;
  padding: 20rpx 20rpx 44rpx;
  font-size: 26rpx;
  color: var(--color-text, #191b23);
  background: #f7f9fc;
  border-radius: 14rpx;
  line-height: 1.6;
  box-sizing: border-box;
}

.form-textarea__count {
  position: absolute;
  right: 20rpx;
  bottom: 14rpx;
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
}

// ---- Image grid ----
.image-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
  width: 100%;
}

.image-grid__item {
  position: relative;
  width: 144rpx;
  height: 144rpx;
  border-radius: 14rpx;
  overflow: hidden;
}

.image-grid__img {
  width: 100%;
  height: 100%;
}

.image-grid__remove {
  position: absolute;
  top: 0;
  right: 0;
  width: 44rpx;
  height: 44rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.5);
  border-radius: 0 0 0 $mb-radius-sm;
}

.image-grid__remove-text {
  font-size: $mb-font-xs;
  color: var(--color-text-inverse, #ffffff);
}

.image-grid__add {
  width: 144rpx;
  height: 144rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f7f9fc;
  border-radius: 14rpx;
  border: 2rpx dashed var(--color-border, #e0e4e8);
}

.image-grid__add-icon {
  font-size: 48rpx;
  color: var(--color-text-tertiary, #737686);
  font-weight: 300;
  line-height: 1;
}

// ---- Submit bar ----
.bottom-spacer {
  height: 180rpx;
}

.submit-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 16rpx 24rpx;
  padding-bottom: calc(16rpx + env(safe-area-inset-bottom));
  border-top: 1rpx solid rgba(25, 27, 35, 0.08);
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 -8rpx 24rpx rgba(25, 27, 35, 0.06);
}

.submit-bar__amount {
  flex: 1;
  min-width: 0;
}

.submit-bar__label {
  display: block;
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
}

.submit-bar__value-wrap {
  display: flex;
  align-items: baseline;
  min-height: 44rpx;
}

.submit-bar__symbol {
  margin-right: 3rpx;
  font-size: 24rpx;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
}

.submit-bar__value {
  overflow: hidden;
  font-size: 38rpx;
  font-weight: 800;
  line-height: 1.1;
  color: var(--color-primary, #0d50d5);
  white-space: nowrap;
  text-overflow: ellipsis;
}

.submit-bar__value--pending {
  font-size: 24rpx;
  font-weight: 600;
  color: var(--color-text-secondary, #434654);
}

.submit-bar__hint {
  display: block;
  margin-top: 2rpx;
  font-size: 20rpx;
  color: var(--color-text-tertiary, #737686);
}

.submit-btn {
  flex-shrink: 0;
  width: 248rpx;
  height: 84rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary, #0d50d5);
  border-radius: $mb-radius-full;
  transition: opacity 0.15s;

  &:active {
    opacity: 0.85;
  }
}

.submit-btn--disabled {
  opacity: 0.5;
  pointer-events: none;
}

.submit-btn__text {
  font-size: 28rpx;
  font-weight: 600;
  color: var(--color-text-inverse, #ffffff);
}
</style>
