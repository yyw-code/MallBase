<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { postReview } from '@/api/goods/review'

// ---------- query params ----------
const orderId = ref('')
const goodsId = ref('')
const goodsName = ref('')
const goodsImage = ref('')
const skuSpecText = ref('')

onLoad((query) => {
  orderId.value = query?.order_id || ''
  goodsId.value = query?.goods_id || ''
  goodsName.value = decodeURIComponent(query?.goods_name || '')
  goodsImage.value = decodeURIComponent(query?.goods_image || '')
  skuSpecText.value = decodeURIComponent(query?.sku_spec_text || '')
})

// ---------- state ----------
const rating = ref(0)
const content = ref('')
const images = ref([])
const isAnonymous = ref(false)
const submitting = ref(false)

const MAX_CONTENT = 500
const MAX_IMAGES = 6

// ---------- computed ----------
const contentLength = computed(() => content.value.length)

const canSubmit = computed(
  () => rating.value > 0 && !submitting.value,
)

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
    },
  })
}

function removeImage(index) {
  images.value = images.value.filter((_, i) => i !== index)
}

// ---------- submit ----------
async function handleSubmit() {
  if (!canSubmit.value) {
    if (rating.value === 0) {
      uni.showToast({ title: '请选择评分', icon: 'none' })
    }
    return
  }

  submitting.value = true

  try {
    await postReview({
      order_id: orderId.value,
      goods_id: goodsId.value,
      rating: rating.value,
      content: content.value,
      images: images.value,
      is_anonymous: isAnonymous.value ? 1 : 0,
    })

    uni.showToast({ title: '评价发布成功', icon: 'success' })

    setTimeout(() => {
      uni.navigateBack()
    }, 1500)
  } catch {
    uni.showToast({ title: '发布失败，请重试', icon: 'none' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <view class="page">
    <mb-navbar title="发布评价" />

    <!-- Product info card -->
    <view class="product-card">
      <image
        v-if="goodsImage"
        class="product-card__img"
        :src="goodsImage"
        mode="aspectFill"
      />
      <view v-else class="product-card__img product-card__img--empty">
        <text class="product-card__img-placeholder">&#x1F4E6;</text>
      </view>
      <view class="product-card__info">
        <text class="product-card__name">{{ goodsName || '商品' }}</text>
        <text v-if="skuSpecText" class="product-card__spec">{{ skuSpecText }}</text>
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
        >{{ star <= rating ? '★' : '☆' }}</text>
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
      <view class="image-grid">
        <view
          v-for="(img, index) in images"
          :key="index"
          class="image-grid__item"
        >
          <image
            class="image-grid__img"
            :src="img"
            mode="aspectFill"
          />
          <view class="image-grid__delete" @tap="removeImage(index)">
            <text class="image-grid__delete-text">&#x2715;</text>
          </view>
        </view>
        <view
          v-if="images.length < MAX_IMAGES"
          class="image-grid__add"
          @tap="chooseImage"
        >
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
        @change="(e) => { isAnonymous = e.detail.value }"
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
        <text class="submit-bar__btn-text">发布评价</text>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: $mb-color-bg;
  padding: 0 $mb-spacing-page $mb-spacing-lg;
}

// ---- Product card ----
.product-card {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  padding: $mb-spacing-lg;
  margin-top: $mb-spacing-md;
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-lg;
}

.product-card__img {
  flex-shrink: 0;
  width: 120rpx;
  height: 120rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
}

.product-card__img--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: $mb-color-divider;
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
  color: $mb-color-text-title;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.product-card__spec {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
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
  color: $mb-color-text-title;
  margin-bottom: $mb-spacing-md;
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
  color: $mb-color-border;
  transition: color 0.15s;

  &--active {
    color: $mb-color-text;
  }
}

.star-row__hint {
  display: block;
  margin-top: $mb-spacing-sm;
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

// ---- Textarea ----
.textarea-wrap {
  position: relative;
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-md;
}

.textarea-wrap__input {
  width: 100%;
  min-height: 200rpx;
  font-size: $mb-font-md;
  color: $mb-color-text;
  line-height: 1.6;
  background: transparent;
}

.textarea-wrap__placeholder {
  color: $mb-color-text-tertiary;
  font-size: $mb-font-md;
}

.textarea-wrap__count {
  display: block;
  text-align: right;
  margin-top: $mb-spacing-sm;
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
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
  color: $mb-color-text-inverse;
  line-height: 1;
}

.image-grid__add {
  width: 200rpx;
  height: 200rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
  border: 2rpx dashed $mb-color-border;
  display: flex;
  align-items: center;
  justify-content: center;
}

.image-grid__add-icon {
  font-size: 64rpx;
  color: $mb-color-text-tertiary;
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
  border-top: 1rpx solid $mb-color-divider;
}

.anonymous-row__label {
  font-size: $mb-font-md;
  color: $mb-color-text;
}

// ---- Bottom submit bar ----
.submit-bar {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  padding: 24rpx 48rpx;
  padding-bottom: calc(24rpx + env(safe-area-inset-bottom));
  background: $mb-color-bg;
  box-shadow: 0 -2rpx 16rpx rgba(0, 0, 0, 0.05);
  z-index: 100;
}

.submit-bar__btn {
  height: 88rpx;
  border-radius: $mb-radius-full;
  background: #000000;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.15s, transform 0.15s;

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
  color: $mb-color-text-inverse;
}

// ---- Bottom spacer ----
.bottom-spacer {
  height: calc(140rpx + env(safe-area-inset-bottom));
}
</style>
