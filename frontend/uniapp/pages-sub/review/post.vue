<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { postReview, uploadReviewImage } from '@/api/goods/review'
import { getOrderDetail } from '@/api/order/order'
import { getUploadConfig, getUploadedAssetValue } from '@/api/upload'
const decorateStore = useDecorateStore()

// ---------- query params ----------
const orderId = ref('')
const orderItemId = ref('')
const goodsId = ref('')
const goodsName = ref('')
const goodsImage = ref('')
const skuSpecText = ref('')

onLoad((query) => {
  orderId.value = query?.order_id || ''
  orderItemId.value = query?.order_item_id || ''
  selectedOrderItemId.value = orderItemId.value
  goodsId.value = query?.goods_id || ''
  goodsName.value = safeDecode(query?.goods_name || '')
  goodsImage.value = safeDecode(query?.goods_image || '')
  skuSpecText.value = safeDecode(query?.sku_spec_text || '')
  fetchOrderItems()
  fetchUploadTips()
})

// ---------- state ----------
const orderItems = ref([])
const selectedOrderItemId = ref('')
const loadingOrder = ref(false)
const rating = ref(0)
const content = ref('')
const images = ref([])
const uploadTips = ref([])
const isAnonymous = ref(false)
const submitting = ref(false)

const MAX_CONTENT = 500
const MAX_IMAGES = 6

// ---------- computed ----------
const contentLength = computed(() => content.value.length)

const selectedItem = computed(() => {
  if (!selectedOrderItemId.value) return null
  return (
    orderItems.value.find((item) => String(item.id) === String(selectedOrderItemId.value)) || null
  )
})

const displayGoodsName = computed(() => selectedItem.value?.goodsName || goodsName.value || '商品')
const displayGoodsImage = computed(() => selectedItem.value?.goodsImage || goodsImage.value)
const displaySkuSpecText = computed(() => selectedItem.value?.skuSpecText || skuSpecText.value)
const hasMultipleItems = computed(() => orderItems.value.length > 1)
const submitText = computed(() => (submitting.value ? '发布中...' : '发布评价'))

const canSubmit = computed(
  () => rating.value > 0 && !!selectedOrderItemId.value && !submitting.value && !loadingOrder.value
)

// ---------- order item ----------
async function fetchOrderItems() {
  if (!orderId.value) return

  loadingOrder.value = true

  try {
    const detail = await getOrderDetail(orderId.value)
    orderItems.value = normalizeOrderItems(detail)

    const hasSelected = orderItems.value.some(
      (item) => String(item.id) === String(selectedOrderItemId.value)
    )
    if (!hasSelected && orderItems.value.length === 1) {
      selectedOrderItemId.value = orderItems.value[0].id
    } else if (!hasSelected && orderItemId.value) {
      selectedOrderItemId.value = orderItemId.value
    }
  } catch {
    // 旧入口兜底：继续使用 query 参数展示，提交时仍以后端校验 order_item_id 为准。
  } finally {
    loadingOrder.value = false
  }
}

function normalizeOrderItems(detail) {
  const source = Array.isArray(detail?.items)
    ? detail.items
    : Array.isArray(detail?.order_items)
      ? detail.order_items
      : []

  return source
    .map((item) => {
      const id = item?.id || item?.order_item_id || ''
      return {
        id: String(id),
        goodsId: item?.goods_id || '',
        goodsName: item?.goods_name || item?.name || '商品',
        goodsImage: normalizeImageUrl(
          item?.goods_image_full_url ||
            item?.goods_image_url ||
            item?.main_image_full_url ||
            item?.image_full_url ||
            item?.cover_full_url ||
            item?.goods_image ||
            item?.main_image ||
            item?.cover ||
            ''
        ),
        skuSpecText: item?.sku_spec_text || item?.sku_spec || item?.spec_text || item?.spec || '',
        quantity: Number(item?.quantity || 1)
      }
    })
    .filter((item) => item.id)
}

function selectOrderItem(item) {
  selectedOrderItemId.value = item.id
}

function safeDecode(value) {
  try {
    return decodeURIComponent(String(value || ''))
  } catch {
    return String(value || '')
  }
}

function normalizeImageUrl(url) {
  const value = String(url || '')
  if (!value) return ''
  if (/^(https?:)?\/\//.test(value) || value.startsWith('data:') || value.startsWith('blob:')) {
    return value.startsWith('//') ? `https:${value}` : value
  }
  return value
}

// ---------- star rating ----------
function setRating(star) {
  rating.value = star
}

// ---------- image upload ----------
function chooseImage() {
  const remaining = MAX_IMAGES - images.value.length
  if (remaining <= 0) {
    uni.showToast({ title: `最多上传${MAX_IMAGES}张图片`, icon: 'none' })
    return
  }

  uni.chooseImage({
    count: remaining,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
    success(res) {
      const newImages = [...images.value, ...res.tempFilePaths].slice(0, MAX_IMAGES)
      images.value = newImages
    }
  })
}

function removeImage(index) {
  images.value = images.value.filter((_, i) => i !== index)
}

async function fetchUploadTips() {
  try {
    const config = await getUploadConfig('image')
    uploadTips.value = Array.isArray(config?.tips) ? config.tips : []
  } catch {
    uploadTips.value = []
  }
}

// ---------- submit ----------
async function handleSubmit() {
  if (!canSubmit.value) {
    if (rating.value === 0) {
      uni.showToast({ title: '请选择评分', icon: 'none' })
    } else if (!selectedOrderItemId.value) {
      uni.showToast({ title: '请选择要评价的商品', icon: 'none' })
    }
    return
  }

  submitting.value = true
  uni.showLoading({ title: '发布中...', mask: true })

  try {
    const uploadedImages = await uploadSelectedImages()
    await postReview({
      order_item_id: selectedOrderItemId.value,
      rating: rating.value,
      content: content.value,
      images: uploadedImages,
      is_anonymous: isAnonymous.value ? 1 : 0
    })

    uni.showToast({ title: '评价发布成功', icon: 'success' })

    setTimeout(() => {
      uni.navigateBack()
    }, 1500)
  } catch {
    uni.showToast({ title: '发布失败，请重试', icon: 'none' })
  } finally {
    uni.hideLoading()
    submitting.value = false
  }
}

async function uploadSelectedImages() {
  const result = []
  for (const filePath of images.value) {
    const uploaded = await uploadReviewImage(filePath)
    const url = normalizeUploadedPath(uploaded)
    if (!url) {
      throw new Error('图片上传返回为空')
    }
    result.push(url)
  }
  return result
}

function normalizeUploadedPath(uploaded) {
  return getUploadedAssetValue(uploaded)
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="发布评价" />

    <view v-if="loadingOrder" class="order-picker order-picker--loading">
      <text class="order-picker__loading-text">商品加载中...</text>
    </view>

    <view v-else-if="hasMultipleItems" class="order-picker">
      <text class="order-picker__title">选择评价商品</text>
      <view
        v-for="item in orderItems"
        :key="item.id"
        class="order-picker__item"
        :class="{
          'order-picker__item--active': String(item.id) === String(selectedOrderItemId)
        }"
        @tap="selectOrderItem(item)"
      >
        <image
          v-if="item.goodsImage"
          class="order-picker__img"
          :src="item.goodsImage"
          mode="aspectFill"
        />
        <view v-else class="order-picker__img order-picker__img--empty">
          <view class="order-picker__placeholder-box" />
        </view>
        <view class="order-picker__info">
          <text class="order-picker__name">{{ item.goodsName }}</text>
          <text v-if="item.skuSpecText" class="order-picker__spec">{{ item.skuSpecText }}</text>
          <text class="order-picker__qty">x{{ item.quantity }}</text>
        </view>
        <view class="order-picker__radio">
          <view
            v-if="String(item.id) === String(selectedOrderItemId)"
            class="order-picker__radio-dot"
          />
        </view>
      </view>
    </view>

    <!-- Product info card -->
    <view class="product-card">
      <image
        v-if="displayGoodsImage"
        class="product-card__img"
        :src="displayGoodsImage"
        mode="aspectFill"
      />
      <view v-else class="product-card__img product-card__img--empty">
        <text class="product-card__img-placeholder">&#x1F4E6;</text>
      </view>
      <view class="product-card__info">
        <text class="product-card__name">{{ displayGoodsName }}</text>
        <text v-if="displaySkuSpecText" class="product-card__spec">{{ displaySkuSpecText }}</text>
      </view>
    </view>

    <!-- Star rating section -->
    <view class="section">
      <text class="section__title">总体评分</text>
      <view class="star-row">
        <text
          v-for="star in 5"
          :key="star"
          class="star-row__star"
          :class="{ 'star-row__star--active': star <= rating }"
          @tap="setRating(star)"
          >{{ star <= rating ? '★' : '☆' }}</text
        >
      </view>
      <text class="star-row__hint">点击星级进行评分</text>
    </view>

    <!-- Content textarea section -->
    <view class="section">
      <text class="section__title">评价内容</text>
      <view class="textarea-wrap">
        <textarea
          v-model="content"
          class="textarea-wrap__input"
          placeholder="分享您的使用感受，帮助更多消费者"
          placeholder-class="textarea-wrap__placeholder"
          :maxlength="MAX_CONTENT"
          auto-height
        />
        <text class="textarea-wrap__count">{{ contentLength }}/{{ MAX_CONTENT }}</text>
      </view>
    </view>

    <!-- Image upload section -->
    <view class="section">
      <text class="section__title">上传图片 ({{ images.length }}/{{ MAX_IMAGES }})</text>
      <text v-if="uploadTips.length > 0" class="section__hint">{{ uploadTips.join('，') }}</text>
      <view class="image-grid">
        <view v-for="(img, index) in images" :key="index" class="image-grid__item">
          <image class="image-grid__img" :src="img" mode="aspectFill" />
          <view class="image-grid__delete" @tap="removeImage(index)">
            <text class="image-grid__delete-text">&#x2715;</text>
          </view>
        </view>
        <view v-if="images.length < MAX_IMAGES" class="image-grid__add" @tap="chooseImage">
          <text class="image-grid__add-icon">+</text>
        </view>
      </view>
    </view>

    <!-- Anonymous toggle -->
    <view class="anonymous-row">
      <text class="anonymous-row__label">匿名评价</text>
      <switch
        :checked="isAnonymous"
        color="#0d50d5"
        @change="
          (e) => {
            isAnonymous = e.detail.value
          }
        "
      />
    </view>

    <!-- Bottom spacer for fixed button -->
    <view class="bottom-spacer" />

    <!-- Fixed bottom submit bar -->
    <view class="submit-bar">
      <view
        class="submit-bar__btn"
        :class="{ 'submit-bar__btn--disabled': !canSubmit }"
        @tap="handleSubmit"
      >
        <text class="submit-bar__btn-text">{{ submitText }}</text>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg, #ffffff);
  padding: 0 $mb-spacing-page $mb-spacing-lg;
}

// ---- Order item picker ----
.order-picker {
  margin-top: $mb-spacing-md;
  padding: $mb-spacing-lg;
  background: var(--color-bg-secondary, #faf8ff);
  border-radius: $mb-radius-lg;
}

.order-picker--loading {
  min-height: 96rpx;
  display: flex;
  align-items: center;
}

.order-picker__loading-text {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

.order-picker__title {
  display: block;
  margin-bottom: $mb-spacing-md;
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
}

.order-picker__item {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md 0;
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
}

.order-picker__item:first-of-type {
  border-top: 0;
  padding-top: 0;
}

.order-picker__item--active .order-picker__name {
  color: var(--color-primary, #0d50d5);
}

.order-picker__img {
  flex-shrink: 0;
  width: 104rpx;
  height: 104rpx;
  border-radius: $mb-radius-md;
  background: var(--color-bg, #ffffff);
}

.order-picker__img--empty {
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-picker__placeholder-box {
  width: 44rpx;
  height: 44rpx;
  border-radius: $mb-radius-sm;
  background: var(--color-divider, #f0f2f5);
}

.order-picker__info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}

.order-picker__name {
  font-size: $mb-font-sm;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.order-picker__spec,
.order-picker__qty {
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.4;
}

.order-picker__radio {
  flex-shrink: 0;
  width: 36rpx;
  height: 36rpx;
  border-radius: 50%;
  border: 2rpx solid var(--color-border, #e0e4e8);
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-picker__radio-dot {
  width: 20rpx;
  height: 20rpx;
  border-radius: 50%;
  background: var(--color-primary, #0d50d5);
}

// ---- Product card ----
.product-card {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  padding: $mb-spacing-lg;
  margin-top: $mb-spacing-md;
  background: var(--color-bg-secondary, #faf8ff);
  border-radius: $mb-radius-lg;
}

.product-card__img {
  flex-shrink: 0;
  width: 120rpx;
  height: 120rpx;
  border-radius: $mb-radius-md;
  background: var(--color-bg-secondary, #faf8ff);
}

.product-card__img--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-divider, #f0f2f5);
}

.product-card__img-placeholder {
  font-size: 48rpx;
}

.product-card__info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.product-card__name {
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.product-card__spec {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.4;
}

// ---- Section ----
.section {
  margin-top: $mb-spacing-xl;
}

.section__title {
  display: block;
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  margin-bottom: $mb-spacing-md;
}

.section__hint {
  display: block;
  margin-top: -8rpx;
  margin-bottom: $mb-spacing-md;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.4;
}

// ---- Star rating ----
.star-row {
  display: inline-flex;
  align-items: center;
  gap: 16rpx;
}

.star-row__star {
  font-size: 56rpx;
  line-height: 1;
  color: var(--color-border, #e0e4e8);
  transition: color 0.15s;

  &--active {
    color: var(--color-text, #191b23);
  }
}

.star-row__hint {
  display: block;
  margin-top: $mb-spacing-sm;
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

// ---- Textarea ----
.textarea-wrap {
  position: relative;
  background: var(--color-bg-secondary, #faf8ff);
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-md;
}

.textarea-wrap__input {
  width: 100%;
  min-height: 200rpx;
  font-size: $mb-font-md;
  color: var(--color-text, #191b23);
  line-height: 1.6;
  background: transparent;
}

.textarea-wrap__placeholder {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-md;
}

.textarea-wrap__count {
  display: block;
  text-align: right;
  margin-top: $mb-spacing-sm;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
}

// ---- Image grid ----
.image-grid {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-md;
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

.image-grid__delete {
  position: absolute;
  top: 0;
  right: 0;
  width: 44rpx;
  height: 44rpx;
  background: rgba(0, 0, 0, 0.5);
  border-radius: 0 0 0 $mb-radius-sm;
  display: flex;
  align-items: center;
  justify-content: center;
}

.image-grid__delete-text {
  font-size: $mb-font-xs;
  color: var(--color-text-inverse, #ffffff);
  line-height: 1;
}

.image-grid__add {
  width: 200rpx;
  height: 200rpx;
  border-radius: $mb-radius-md;
  background: var(--color-bg-secondary, #faf8ff);
  border: 2rpx dashed var(--color-border, #e0e4e8);
  display: flex;
  align-items: center;
  justify-content: center;
}

.image-grid__add-icon {
  font-size: 64rpx;
  color: var(--color-text-tertiary, #737686);
  line-height: 1;
  font-weight: 300;
}

// ---- Anonymous row ----
.anonymous-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: $mb-spacing-xl;
  padding: $mb-spacing-md 0;
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
}

.anonymous-row__label {
  font-size: $mb-font-md;
  color: var(--color-text, #191b23);
}

// ---- Bottom submit bar ----
.submit-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 24rpx 48rpx;
  padding-bottom: calc(24rpx + env(safe-area-inset-bottom));
  background: var(--color-bg, #ffffff);
  box-shadow: $mb-shadow-bar;
  z-index: 100;
}

.submit-bar__btn {
  height: 88rpx;
  border-radius: $mb-radius-sm;
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;
  transition:
    opacity 0.15s,
    transform 0.15s;

  &:active {
    opacity: 0.85;
    transform: scale(0.98);
  }

  &--disabled {
    opacity: 0.4;
  }
}

.submit-bar__btn-text {
  font-size: $mb-font-lg;
  font-weight: 600;
  color: var(--color-text-inverse, #ffffff);
}

// ---- Bottom spacer ----
.bottom-spacer {
  height: calc(140rpx + env(safe-area-inset-bottom));
}
</style>
