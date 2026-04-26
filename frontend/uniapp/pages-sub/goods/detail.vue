<template>
  <view class="goods-detail">
    <!-- Navbar -->
    <mb-navbar title="MALLBASE">
      <template #right>
        <view class="goods-detail__share-btn" @tap="onShare">
          <view class="goods-detail__share-icon" />
        </view>
      </template>
    </mb-navbar>

    <!-- Loading skeleton -->
    <view v-if="loading" class="goods-detail__loading">
      <view class="goods-detail__skeleton-swiper" />
      <view class="goods-detail__skeleton-body">
        <mb-skeleton type="card" />
        <mb-skeleton type="lines" :count="4" />
      </view>
    </view>

    <!-- Error / empty -->
    <mb-empty-state
      v-else-if="!goods"
      icon="📦"
      text="商品不存在或已下架"
      action-text="返回首页"
      @action="goHome"
    />

    <!-- Main content -->
    <scroll-view v-else scroll-y class="goods-detail__scroll">
      <!-- Image swiper -->
      <view class="goods-detail__swiper-wrap">
        <swiper
          class="goods-detail__swiper"
          :current="swiperIndex"
          :indicator-dots="false"
          :autoplay="false"
          circular
          @change="onSwiperChange"
        >
          <swiper-item v-for="(img, idx) in images" :key="idx">
            <image
              class="goods-detail__swiper-img"
              :src="img"
              mode="aspectFill"
              @tap="previewImage(idx)"
            />
          </swiper-item>
        </swiper>
        <view class="goods-detail__counter">
          <text class="goods-detail__counter-text">
            {{ swiperIndex + 1 }}/{{ images.length }}
          </text>
        </view>
      </view>

      <!-- Price section -->
      <view class="goods-detail__price-section">
        <view class="goods-detail__price-row">
          <view class="goods-detail__price-left">
            <text class="goods-detail__price-symbol">¥</text>
            <text class="goods-detail__price-value">{{ formattedPrice }}</text>
            <view
              v-if="goods.market_price && Number(goods.market_price) > Number(displayPrice)"
              class="goods-detail__original-price"
            >
              <text class="goods-detail__original-price-text">¥{{ goods.market_price }}</text>
            </view>
          </view>
          <view class="goods-detail__stock-tag">
            <text class="goods-detail__stock-text">库存 {{ displayStock }}</text>
          </view>
        </view>
      </view>

      <!-- Title section -->
      <view class="goods-detail__title-section">
        <text class="goods-detail__name">{{ goods.name }}</text>
        <text v-if="goods.subtitle" class="goods-detail__subtitle">{{ goods.subtitle }}</text>
      </view>

      <!-- Divider -->
      <view class="goods-detail__divider" />

      <!-- Spec selector trigger -->
      <view class="goods-detail__cell" @tap="showSpec = true">
        <text class="goods-detail__cell-label">规格</text>
        <view class="goods-detail__cell-right">
          <text class="goods-detail__cell-value">{{ specDisplayText }}</text>
          <text class="goods-detail__cell-arrow">&#10095;</text>
        </view>
      </view>

      <!-- Params trigger -->
      <view class="goods-detail__cell" @tap="onShowParams">
        <text class="goods-detail__cell-label">主要参数</text>
        <view class="goods-detail__cell-right">
          <text class="goods-detail__cell-arrow">&#10095;</text>
        </view>
      </view>

      <!-- Divider -->
      <view class="goods-detail__divider" />

      <!-- Product detail / rich text -->
      <view class="goods-detail__content-section">
        <view class="goods-detail__content-header">
          <view class="goods-detail__content-line" />
          <text class="goods-detail__content-title">商品详情</text>
          <view class="goods-detail__content-line" />
        </view>
        <rich-text v-if="goods.content" :nodes="goods.content" class="goods-detail__rich-text" />
        <view v-else class="goods-detail__content-empty">
          <text class="goods-detail__content-empty-text">暂无详情</text>
        </view>
      </view>

      <!-- Bottom spacer for fixed bar -->
      <view class="goods-detail__bottom-spacer" />
    </scroll-view>

    <!-- Bottom action bar -->
    <view v-if="goods" class="goods-detail__bar">
      <view class="goods-detail__bar-inner">
        <view class="goods-detail__bar-icons">
          <view class="goods-detail__bar-icon-btn" @tap="contactService">
            <view class="goods-detail__icon-service" />
            <text class="goods-detail__bar-icon-label">客服</text>
          </view>
          <view class="goods-detail__bar-icon-btn" @tap="goCart">
            <view class="goods-detail__bar-badge-wrap">
              <view class="goods-detail__icon-cart" />
              <view v-if="cartCount > 0" class="goods-detail__badge">
                <text class="goods-detail__badge-text">{{ cartCount > 99 ? '99+' : cartCount }}</text>
              </view>
            </view>
            <text class="goods-detail__bar-icon-label">购物车</text>
          </view>
        </view>
        <view class="goods-detail__bar-actions">
          <view class="goods-detail__bar-btn goods-detail__bar-btn--cart" @tap="onOpenSpec('cart')">
            <text class="goods-detail__bar-btn-text">加入购物车</text>
          </view>
          <view class="goods-detail__bar-btn goods-detail__bar-btn--buy" @tap="onOpenSpec('buy')">
            <text class="goods-detail__bar-btn-text goods-detail__bar-btn-text--light">立即购买</text>
          </view>
        </view>
      </view>
    </view>

    <!-- Spec selector popup -->
    <mb-spec-selector
      :visible="showSpec"
      :goods="goods || {}"
      :sku-list="goods?.skus || []"
      :mode="specMode"
      @close="showSpec = false"
      @add-to-cart="onAddToCart"
      @buy-now="onBuyNow"
    />
  </view>
</template>

<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getGoodsDetail } from '@/api/goods/goods'
import { useCartStore } from '@/store/cart'

const cartStore = useCartStore()

const loading = ref(true)
const goods = ref(null)
const swiperIndex = ref(0)
const showSpec = ref(false)
const specMode = ref('both')

onLoad((query) => {
  if (query?.id) {
    fetchDetail(query.id)
  } else {
    loading.value = false
  }
})

async function fetchDetail(id) {
  loading.value = true
  try {
    const res = await getGoodsDetail(id)
    goods.value = res?.data ?? res ?? null
  } catch {
    goods.value = null
  } finally {
    loading.value = false
  }
}

const images = computed(() => {
  if (!goods.value) return []
  const list = Array.isArray(goods.value.images) ? goods.value.images : []
  if (list.length > 0) return list
  return goods.value.main_image_full_url ? [goods.value.main_image_full_url] : []
})

const displayPrice = computed(() => goods.value?.price ?? '0')

const formattedPrice = computed(() => {
  const num = Number(displayPrice.value)
  if (Number.isNaN(num)) return '0'
  const int = Math.floor(num).toLocaleString('zh-CN')
  const dec = num.toFixed(2).split('.')[1]
  return dec === '00' ? int : `${int}.${dec}`
})

const displayStock = computed(() => goods.value?.stock ?? 0)

const cartCount = computed(() => cartStore.count)

const specDisplayText = computed(() => {
  const meta = goods.value?.spec_meta
  if (!Array.isArray(meta) || meta.length === 0) return '默认规格'
  return `已选：${meta.map((g) => g.name).join('、')}`
})

function onSwiperChange(e) {
  swiperIndex.value = e.detail.current
}

function previewImage(idx) {
  uni.previewImage({
    urls: images.value,
    current: idx,
  })
}

function onShare() {
  // #ifdef MP-WEIXIN
  // WeChat mini-program share is handled by onShareAppMessage
  // #endif
  // #ifndef MP-WEIXIN
  uni.showToast({ title: '分享功能开发中', icon: 'none' })
  // #endif
}

function contactService() {
  // #ifdef MP-WEIXIN
  // handled by button open-type="contact"
  // #endif
  // #ifndef MP-WEIXIN
  uni.showToast({ title: '请联系在线客服', icon: 'none' })
  // #endif
}

function onShowParams() {
  uni.showToast({ title: '参数详情开发中', icon: 'none' })
}

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

function goCart() {
  uni.switchTab({ url: '/pages/cart/index' })
}

function onOpenSpec(mode) {
  const meta = goods.value?.spec_meta
  if (!Array.isArray(meta) || meta.length === 0) {
    specMode.value = 'both'
  } else {
    specMode.value = mode === 'buy' ? 'buy' : 'cart'
  }
  showSpec.value = true
}

async function onAddToCart({ sku, quantity }) {
  try {
    await cartStore.add(sku.id, quantity)
    showSpec.value = false
    uni.showToast({ title: '已加入购物车', icon: 'success' })
  } catch {
    uni.showToast({ title: '加入失败，请重试', icon: 'none' })
  }
}

function onBuyNow({ sku, quantity }) {
  showSpec.value = false
  uni.setStorageSync('buy_now_sku', {
    sku_id: sku.id,
    goods_name: goods.value.name,
    goods_image: sku.image_full_url || goods.value.main_image_full_url,
    sku_spec: sku.spec_values || '',
    unit_price: sku.price,
  })
  uni.navigateTo({
    url: `/pages-sub/order/confirm?source=sku&sku_id=${sku.id}&quantity=${quantity}`,
  })
}
</script>

<style lang="scss" scoped>
.goods-detail {
  min-height: 100vh;
  background: $mb-color-bg;
}

// ---------- Share button ----------
.goods-detail__share-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 64rpx;
  height: 64rpx;
}

.goods-detail__share-icon {
  width: 36rpx;
  height: 36rpx;
  position: relative;

  &::before {
    content: '';
    display: block;
    width: 16rpx;
    height: 16rpx;
    border-radius: 50%;
    border: 3rpx solid $mb-color-text;
    position: absolute;
    top: 0;
    right: 0;
  }

  &::after {
    content: '';
    display: block;
    width: 20rpx;
    height: 3rpx;
    background: $mb-color-text;
    position: absolute;
    top: 8rpx;
    right: 12rpx;
    transform: rotate(-30deg);
    transform-origin: right center;
  }
}

// ---------- Loading skeleton ----------
.goods-detail__loading {
  padding-top: 0;
}

.goods-detail__skeleton-swiper {
  width: 100%;
  height: 750rpx;
  background: linear-gradient(
    90deg,
    $mb-color-bg-secondary 25%,
    #eef0f3 50%,
    $mb-color-bg-secondary 75%
  );
  background-size: 200% 100%;
  animation: detail-shimmer 1.5s infinite ease-in-out;
}

.goods-detail__skeleton-body {
  padding: $mb-spacing-lg;
}

@keyframes detail-shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

// ---------- Scroll ----------
.goods-detail__scroll {
  height: 100vh;
}

// ---------- Swiper ----------
.goods-detail__swiper-wrap {
  position: relative;
  width: 100%;
  background: $mb-color-bg-secondary;
}

.goods-detail__swiper {
  width: 100%;
  height: 750rpx;
}

.goods-detail__swiper-img {
  width: 100%;
  height: 750rpx;
}

.goods-detail__counter {
  position: absolute;
  right: $mb-spacing-lg;
  bottom: $mb-spacing-lg;
  background: rgba(0, 0, 0, 0.4);
  padding: 6rpx 20rpx;
  border-radius: $mb-radius-full;
}

.goods-detail__counter-text {
  font-size: $mb-font-sm;
  color: #ffffff;
  font-weight: 500;
}

// ---------- Price section ----------
.goods-detail__price-section {
  padding: $mb-spacing-lg $mb-spacing-page;
  background: $mb-color-bg;
}

.goods-detail__price-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}

.goods-detail__price-left {
  display: flex;
  align-items: baseline;
  gap: 4rpx;
}

.goods-detail__price-symbol {
  font-size: $mb-font-xl;
  font-weight: 700;
  color: $mb-color-text;
  line-height: 1;
}

.goods-detail__price-value {
  font-size: $mb-font-display;
  font-weight: 800;
  color: $mb-color-text;
  line-height: 1;
  letter-spacing: -2rpx;
}

.goods-detail__original-price {
  margin-left: $mb-spacing-sm;
  align-self: center;
}

.goods-detail__original-price-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  text-decoration: line-through;
}

.goods-detail__stock-tag {
  flex-shrink: 0;
}

.goods-detail__stock-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

// ---------- Title section ----------
.goods-detail__title-section {
  padding: 0 $mb-spacing-page $mb-spacing-lg;
  background: $mb-color-bg;
}

.goods-detail__name {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: $mb-color-text-title;
  line-height: 1.45;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.goods-detail__subtitle {
  display: block;
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

// ---------- Divider ----------
.goods-detail__divider {
  height: 16rpx;
  background: $mb-color-bg-secondary;
}

// ---------- Cell ----------
.goods-detail__cell {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $mb-spacing-lg $mb-spacing-page;
  background: $mb-color-bg;
  border-bottom: 1rpx solid $mb-color-divider;

  &:active {
    background: $mb-color-bg-secondary;
  }
}

.goods-detail__cell-label {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text;
  flex-shrink: 0;
}

.goods-detail__cell-right {
  display: flex;
  align-items: center;
  gap: $mb-spacing-xs;
  flex: 1;
  justify-content: flex-end;
  min-width: 0;
}

.goods-detail__cell-value {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-detail__cell-arrow {
  font-size: 22rpx;
  color: $mb-color-text-tertiary;
  flex-shrink: 0;
}

// ---------- Content section ----------
.goods-detail__content-section {
  background: $mb-color-bg;
  padding: $mb-spacing-xl $mb-spacing-page $mb-spacing-xl;
}

.goods-detail__content-header {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  margin-bottom: $mb-spacing-lg;
}

.goods-detail__content-line {
  flex: 1;
  height: 1rpx;
  background: $mb-color-border;
}

.goods-detail__content-title {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  flex-shrink: 0;
  letter-spacing: 4rpx;
}

.goods-detail__rich-text {
  font-size: $mb-font-md;
  line-height: 1.7;
  color: $mb-color-text;
  word-break: break-all;
}

.goods-detail__content-empty {
  padding: $mb-spacing-xl 0;
  text-align: center;
}

.goods-detail__content-empty-text {
  font-size: $mb-font-md;
  color: $mb-color-text-tertiary;
}

// ---------- Bottom spacer ----------
.goods-detail__bottom-spacer {
  height: 200rpx;
}

// ---------- Bottom bar ----------
.goods-detail__bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 998;
  background: $mb-color-bg;
  border-top: 1rpx solid $mb-color-divider;
  padding-bottom: env(safe-area-inset-bottom);
}

.goods-detail__bar-inner {
  display: flex;
  align-items: center;
  height: 108rpx;
  padding: 0 $mb-spacing-page;
  gap: $mb-spacing-md;
}

.goods-detail__bar-icons {
  display: flex;
  gap: $mb-spacing-lg;
  flex-shrink: 0;
}

.goods-detail__bar-icon-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4rpx;
}

// ---------- CSS icons ----------
.goods-detail__icon-service {
  width: 40rpx;
  height: 40rpx;
  position: relative;

  &::before {
    content: '';
    display: block;
    width: 32rpx;
    height: 24rpx;
    border: 3rpx solid $mb-color-text;
    border-radius: 8rpx 8rpx 0 8rpx;
    position: absolute;
    top: 2rpx;
    left: 4rpx;
  }

  &::after {
    content: '';
    display: block;
    width: 0;
    height: 0;
    border-left: 8rpx solid $mb-color-text;
    border-bottom: 8rpx solid transparent;
    position: absolute;
    bottom: 4rpx;
    right: 6rpx;
  }
}

.goods-detail__icon-cart {
  width: 40rpx;
  height: 40rpx;
  position: relative;

  // Cart body
  &::before {
    content: '';
    display: block;
    width: 28rpx;
    height: 22rpx;
    border: 3rpx solid $mb-color-text;
    border-radius: 0 0 6rpx 6rpx;
    position: absolute;
    top: 4rpx;
    left: 6rpx;
  }

  // Cart handle
  &::after {
    content: '';
    display: block;
    width: 18rpx;
    height: 10rpx;
    border: 3rpx solid $mb-color-text;
    border-bottom: none;
    border-radius: 10rpx 10rpx 0 0;
    position: absolute;
    top: -2rpx;
    left: 11rpx;
  }
}

.goods-detail__bar-icon-label {
  font-size: 20rpx;
  color: $mb-color-text-secondary;
  line-height: 1;
}

.goods-detail__bar-badge-wrap {
  position: relative;
}

.goods-detail__badge {
  position: absolute;
  top: -8rpx;
  right: -14rpx;
  min-width: 28rpx;
  height: 28rpx;
  padding: 0 8rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-error;
  display: flex;
  align-items: center;
  justify-content: center;
}

.goods-detail__badge-text {
  font-size: 18rpx;
  color: #ffffff;
  font-weight: 600;
  line-height: 1;
}

.goods-detail__bar-actions {
  flex: 1;
  display: flex;
  gap: $mb-spacing-sm;
}

.goods-detail__bar-btn {
  flex: 1;
  height: 76rpx;
  border-radius: $mb-radius-full;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.15s;

  &:active {
    opacity: 0.75;
  }
}

.goods-detail__bar-btn--cart {
  background: $mb-color-bg;
  border: 2rpx solid $mb-color-text;
}

.goods-detail__bar-btn--buy {
  background: $mb-color-text;
}

.goods-detail__bar-btn-text {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text;
}

.goods-detail__bar-btn-text--light {
  color: $mb-color-text-inverse;
}
</style>
