<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { applyRefund, getRefundReasonOptions } from '@/api/order/refund'

const orderId = ref('')
const goodsName = ref('')
const goodsImage = ref('')
const skuSpecText = ref('')
const price = ref(0)
const quantity = ref(1)

const reasonOptions = ref([])
const selectedReason = ref('')
const description = ref('')
const images = ref([])
const submitting = ref(false)

const MAX_IMAGES = 3
const MAX_DESC_LENGTH = 200

onLoad((query) => {
  orderId.value = query?.order_id || ''
  goodsName.value = decodeURIComponent(query?.goods_name || '')
  goodsImage.value = decodeURIComponent(query?.goods_image || '')
  skuSpecText.value = decodeURIComponent(query?.sku_spec_text || '')
  price.value = Number(query?.price) || 0
  quantity.value = Number(query?.quantity) || 1
  fetchReasonOptions()
})

async function fetchReasonOptions() {
  try {
    const data = await getRefundReasonOptions()
    reasonOptions.value = Array.isArray(data) ? data : []
  } catch {
    reasonOptions.value = []
  }
}

const refundAmount = computed(() => {
  const total = price.value * quantity.value
  return total.toFixed(2)
})

function formatPrice(val) {
  const num = Number(val)
  if (Number.isNaN(num)) return '0.00'
  return num.toFixed(2)
}

function onPickReason() {
  if (reasonOptions.value.length === 0) {
    uni.showToast({ title: '暂无可选原因', icon: 'none' })
    return
  }
  const names = reasonOptions.value.map((item) =>
    typeof item === 'string' ? item : item.name || item.label || String(item),
  )
  uni.showActionSheet({
    itemList: names,
    success(res) {
      selectedReason.value = names[res.tapIndex]
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
  if (!selectedReason.value) {
    uni.showToast({ title: '请选择退款原因', icon: 'none' })
    return
  }
  if (submitting.value) return
  submitting.value = true

  try {
    await applyRefund({
      order_id: orderId.value,
      reason: selectedReason.value,
      description: description.value,
      images: images.value,
    })
    uni.showToast({ title: '退款申请已提交', icon: 'success' })
    setTimeout(() => {
      uni.navigateBack()
    }, 1500)
  } catch {
    // error handled by request interceptor
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <view class="page">
    <mb-navbar title="申请退款" />

    <!-- Product info card -->
    <view class="product-card">
      <image
        v-if="goodsImage"
        class="product-card__image"
        :src="goodsImage"
        mode="aspectFill"
      />
      <view v-else class="product-card__image product-card__image--placeholder">
        <text class="product-card__placeholder-text">&#x1F4E6;</text>
      </view>
      <view class="product-card__info">
        <text class="product-card__name">{{ goodsName || '商品' }}</text>
        <text v-if="skuSpecText" class="product-card__spec">{{ skuSpecText }}</text>
        <view class="product-card__bottom">
          <text class="product-card__price">{{ '¥' }}{{ formatPrice(price) }}</text>
          <text class="product-card__qty">{{ '×' }}{{ quantity }}</text>
        </view>
      </view>
    </view>

    <!-- Form fields -->
    <view class="form-section">
      <!-- Reason picker -->
      <view class="form-item" @tap="onPickReason">
        <text class="form-item__label">退款原因</text>
        <view class="form-item__value-wrap">
          <text
            class="form-item__value"
            :class="{ 'form-item__value--placeholder': !selectedReason }"
          >{{ selectedReason || '请选择退款原因' }}</text>
          <text class="form-item__arrow">&#x203A;</text>
        </view>
      </view>

      <!-- Description -->
      <view class="form-item form-item--column">
        <text class="form-item__label">退款说明</text>
        <textarea
          v-model="description"
          class="form-textarea"
          placeholder="请详细描述您遇到的问题..."
          :maxlength="MAX_DESC_LENGTH"
          placeholder-style="color: #848484"
        />
        <text class="form-textarea__count">
          {{ description.length }}/{{ MAX_DESC_LENGTH }}
        </text>
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

    <!-- Refund amount display -->
    <view class="amount-card">
      <text class="amount-card__label">预计退款金额</text>
      <view class="amount-card__value-wrap">
        <text class="amount-card__symbol">{{ '¥' }}</text>
        <text class="amount-card__value">{{ refundAmount }}</text>
      </view>
    </view>

    <!-- Submit button -->
    <view class="submit-wrap">
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
  background: $mb-color-bg;
  padding: 0 $mb-spacing-page $mb-spacing-xl;
}

// ---- Product card ----
.product-card {
  display: flex;
  gap: $mb-spacing-md;
  padding: $mb-spacing-lg;
  margin: $mb-spacing-sm (-$mb-spacing-page) 0;
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-xl;
  margin-left: 0;
  margin-right: 0;
}

.product-card__image {
  flex-shrink: 0;
  width: 120rpx;
  height: 120rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg;
}

.product-card__image--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.product-card__placeholder-text {
  font-size: 48rpx;
}

.product-card__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-width: 0;
}

.product-card__name {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text-title;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.product-card__spec {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  margin-top: 6rpx;
}

.product-card__bottom {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-top: 8rpx;
}

.product-card__price {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text-title;
}

.product-card__qty {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

// ---- Form section ----
.form-section {
  margin-top: $mb-spacing-lg;
}

.form-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $mb-spacing-lg 0;
  border-bottom: 1rpx solid $mb-color-divider;
}

.form-item--column {
  flex-direction: column;
  align-items: flex-start;
}

.form-item__label {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text;
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
  font-size: $mb-font-md;
  color: $mb-color-text;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.form-item__value--placeholder {
  color: $mb-color-text-tertiary;
}

.form-item__arrow {
  font-size: $mb-font-lg;
  color: $mb-color-text-tertiary;
  font-weight: 300;
}

// ---- Textarea ----
.form-textarea {
  width: 100%;
  height: 200rpx;
  padding: $mb-spacing-md;
  font-size: $mb-font-md;
  color: $mb-color-text;
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-md;
  line-height: 1.6;
  box-sizing: border-box;
}

.form-textarea__count {
  display: block;
  text-align: right;
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
  margin-top: $mb-spacing-xs;
  width: 100%;
}

// ---- Image grid ----
.image-grid {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-md;
  width: 100%;
}

.image-grid__item {
  position: relative;
  width: 200rpx;
  height: 200rpx;
  border-radius: $mb-radius-md;
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
  color: $mb-color-text-inverse;
}

.image-grid__add {
  width: 200rpx;
  height: 200rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-md;
  border: 2rpx dashed $mb-color-border;
}

.image-grid__add-icon {
  font-size: 64rpx;
  color: $mb-color-text-tertiary;
  font-weight: 300;
  line-height: 1;
}

// ---- Amount card ----
.amount-card {
  margin-top: $mb-spacing-xl;
  padding: $mb-spacing-lg;
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-xl;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.amount-card__label {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
}

.amount-card__value-wrap {
  display: flex;
  align-items: baseline;
}

.amount-card__symbol {
  font-size: $mb-font-md;
  font-weight: 700;
  color: $mb-color-text-title;
  margin-right: 4rpx;
}

.amount-card__value {
  font-size: $mb-font-xxl;
  font-weight: 700;
  color: $mb-color-text-title;
  line-height: 1.2;
}

// ---- Submit button ----
.submit-wrap {
  margin-top: $mb-spacing-xl;
  padding-bottom: calc(#{$mb-spacing-xl} + env(safe-area-inset-bottom));
}

.submit-btn {
  width: 100%;
  height: 96rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #000000;
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
  font-size: $mb-font-lg;
  font-weight: 600;
  color: $mb-color-text-inverse;
}
</style>
