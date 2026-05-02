<template>
  <view class="goods-detail">
    <!-- Navbar -->
    <mb-navbar title="商品详情">
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
      <!-- Media swiper -->
      <view class="goods-detail__swiper-wrap" :style="{ height: `${currentMediaHeight}rpx` }">
        <swiper
          class="goods-detail__swiper"
          :style="{ height: `${currentMediaHeight}rpx` }"
          :current="swiperIndex"
          :indicator-dots="false"
          :autoplay="false"
          :circular="false"
          @change="onSwiperChange"
        >
          <swiper-item
            v-for="(media, idx) in mediaList"
            :key="media.key"
            class="goods-detail__swiper-item"
            :style="{ height: `${currentMediaHeight}rpx` }"
          >
            <view class="goods-detail__swiper-media" :style="{ height: `${currentMediaHeight}rpx` }">
              <video
                v-if="media.type === 'video'"
                class="goods-detail__swiper-video"
                :src="media.url"
                controls
                object-fit="contain"
              />
              <image
                v-else
                class="goods-detail__swiper-img"
                :src="media.url"
                :style="{ height: `${currentMediaHeight}rpx` }"
                mode="aspectFit"
                @tap="previewImage(idx)"
              />
            </view>
          </swiper-item>
        </swiper>
        <view v-if="mediaList.length > 0" class="goods-detail__counter">
          <text class="goods-detail__counter-text">
            {{ swiperIndex + 1 }}/{{ mediaList.length }}
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
              v-if="displayMarketPrice && Number(displayMarketPrice) > Number(displayPrice)"
              class="goods-detail__original-price"
            >
              <text class="goods-detail__original-price-text">¥{{ displayMarketPrice }}</text>
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

      <!-- Divider -->
      <view class="goods-detail__divider" />

      <!-- Reviews -->
      <view class="goods-detail__review-section">
        <view class="goods-detail__review-header">
          <view class="goods-detail__review-title-wrap">
            <text class="goods-detail__review-title">商品评论</text>
            <text v-if="reviewTotal > 0" class="goods-detail__review-total">共{{ reviewTotal }}条</text>
          </view>
        </view>
        <view v-if="reviewLoading" class="goods-detail__review-loading">
          <text class="goods-detail__review-empty-text">评论加载中...</text>
        </view>
        <view v-else-if="reviewList.length === 0" class="goods-detail__review-empty">
          <text class="goods-detail__review-empty-text">暂无评论</text>
        </view>
        <block v-else>
          <view
            v-for="(review, index) in reviewList"
            :key="review.id"
            class="goods-detail__review-item"
            :class="{ 'goods-detail__review-item--first': index === 0 }"
          >
            <view class="goods-detail__review-user-row">
              <view class="goods-detail__review-avatar">
                <text class="goods-detail__review-avatar-text">{{ review.userInitial }}</text>
              </view>
              <view class="goods-detail__review-user-main">
                <text class="goods-detail__review-user-name">{{ review.userName }}</text>
                <view class="goods-detail__review-star-row">
                  <text
                    v-for="star in 5"
                    :key="star"
                    class="goods-detail__review-star"
                    :class="{ 'goods-detail__review-star--active': star <= review.rating }"
                  >★</text>
                </view>
              </view>
              <text class="goods-detail__review-time">{{ review.createTimeText }}</text>
            </view>
            <text v-if="review.content" class="goods-detail__review-content">{{ review.content }}</text>
            <view v-if="review.images.length > 0" class="goods-detail__review-images">
              <image
                v-for="(image, imageIndex) in review.images"
                :key="image"
                class="goods-detail__review-image"
                :src="image"
                mode="aspectFill"
                @tap="previewReviewImage(review, imageIndex)"
              />
            </view>
            <view v-if="review.replyContent" class="goods-detail__review-reply">
              <text class="goods-detail__review-reply-text">商家回复：{{ review.replyContent }}</text>
            </view>
          </view>
        </block>
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
        <rich-text v-if="descriptionNodes" :nodes="descriptionNodes" class="goods-detail__rich-text" />
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
      :sku-list="skuList"
      :mode="specMode"
      :selected-specs="selectedSpecs"
      :selected-sku-id="selectedSku?.id || null"
      @change="onSpecChange"
      @close="showSpec = false"
      @add-to-cart="onAddToCart"
      @buy-now="onBuyNow"
    />
  </view>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getGoodsDetail } from '@/api/goods/goods'
import { getReviewList } from '@/api/goods/review'
import { useCartStore } from '@/store/cart'

const MEDIA_HEIGHT = 422
const REVIEW_PREVIEW_LIMIT = 3

const cartStore = useCartStore()

const loading = ref(true)
const goods = ref(null)
const swiperIndex = ref(0)
const showSpec = ref(false)
const specMode = ref('both')
const selectedSpecs = ref({})
const selectedSkuId = ref(null)
const reviewLoading = ref(false)
const reviewTotal = ref(0)
const reviewList = ref([])

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
    resetSelection()
    await fetchReviews(id)
  } catch {
    goods.value = null
    resetReviews()
  } finally {
    loading.value = false
  }
}

const skuList = computed(() => (Array.isArray(goods.value?.skus) ? goods.value.skus : []))

const specGroups = computed(() => {
  const meta = goods.value?.spec_meta
  if (!Array.isArray(meta) || meta.length === 0) return []
  return meta.map((group) => ({
    name: group.name,
    addPic: Number(group.add_pic || 0) === 1,
    values: Array.isArray(group.values) ? group.values.map((item) => item.value) : [],
    items: Array.isArray(group.values) ? group.values : [],
  }))
})

const hasMultiSpec = computed(() => specGroups.value.length > 0)
const specImageGroup = computed(() => specGroups.value.find((group) => group.addPic) || null)

const selectedSku = computed(() => {
  if (!hasMultiSpec.value && skuList.value.length === 1) {
    return skuList.value[0]
  }

  if (selectedSkuId.value) {
    const found = skuList.value.find((sku) => String(sku.id) === String(selectedSkuId.value))
    if (found) return found
  }

  const sku = findSkuBySpecs(selectedSpecs.value)
  return sku || null
})

const mediaList = computed(() => {
  if (!goods.value) return []

  const list = []
  const imageIndexMap = new Map()
  const videoUrl = goods.value.main_video_full_url || goods.value.main_video || ''
  if (videoUrl) {
    list.push({
      key: `video:${videoUrl}`,
      type: 'video',
      url: videoUrl,
    })
  }

  const appendImage = (url, source = 'goods', specName = '', specValue = '') => {
    if (!url) return
    const existedIndex = imageIndexMap.get(url)
    if (existedIndex !== undefined) {
      if (source === 'spec') addSpecValueToMedia(list[existedIndex], specName, specValue)
      return
    }

    const item = {
      key: `${source}:${url}`,
      type: 'image',
      url,
      source,
      specName,
      specValues: source === 'spec' && specValue ? [specValue] : [],
    }
    imageIndexMap.set(url, list.length)
    list.push(item)
  }

  const goodsImages = Array.isArray(goods.value.images) ? goods.value.images : []
  goodsImages.forEach((image) => appendImage(normalizeImageUrl(image), 'goods'))
  if (list.filter((item) => item.type === 'image').length === 0) {
    appendImage(goods.value.main_image_full_url || goods.value.main_image || '', 'goods')
  }

  if (specImageGroup.value) {
    specImageGroup.value.items.forEach((item) => {
      appendImage(item.pic_full_url || item.pic || '', 'spec', specImageGroup.value.name, item.value)
    })
  }

  return list
})

const currentMediaHeight = computed(() => MEDIA_HEIGHT)

const imagePreviewUrls = computed(() => mediaList.value
  .filter((item) => item.type === 'image')
  .map((item) => item.url))

const descriptionNodes = computed(() => normalizeDescriptionHtml(goods.value?.description || ''))

const displayPrice = computed(() => selectedSku.value?.price ?? goods.value?.price ?? '0')
const displayMarketPrice = computed(() => selectedSku.value?.market_price ?? goods.value?.market_price ?? '')
const displayStock = computed(() => selectedSku.value?.stock ?? goods.value?.stock ?? 0)

const formattedPrice = computed(() => {
  const num = Number(displayPrice.value)
  if (Number.isNaN(num)) return '0'
  const int = Math.floor(num).toLocaleString('zh-CN')
  const dec = num.toFixed(2).split('.')[1]
  return dec === '00' ? int : `${int}.${dec}`
})

const cartCount = computed(() => cartStore.count)

const selectedSpecText = computed(() => specGroups.value
  .map((group) => selectedSpecs.value[group.name])
  .filter(Boolean)
  .join(' / '))

const specDisplayText = computed(() => {
  if (!hasMultiSpec.value) return '默认规格'
  if (selectedSpecText.value) return `已选：${selectedSpecText.value}`
  return `请选择 ${specGroups.value.map((group) => group.name).join(' / ')}`
})

function resetSelection() {
  swiperIndex.value = 0
  selectedSpecs.value = {}
  selectedSkuId.value = null

  if (!hasMultiSpec.value && skuList.value.length === 1) {
    selectedSkuId.value = skuList.value[0].id
  }
}

function resetReviews() {
  reviewTotal.value = 0
  reviewList.value = []
}

function normalizeImageUrl(image) {
  if (!image) return ''
  if (typeof image === 'string') return image
  return image.full_url || image.url || image.image_full_url || image.image || image.src || ''
}

function addSpecValueToMedia(media, specName, specValue) {
  if (!media.specName) media.specName = specName
  if (!media.specValues) media.specValues = []
  if (specValue && !media.specValues.includes(specValue)) {
    media.specValues.push(specValue)
  }
}

function findSkuBySpecs(specs) {
  if (!hasMultiSpec.value) return skuList.value[0] || null
  if (Object.keys(specs).length < specGroups.value.length) return null

  const specValues = specGroups.value.map((group) => specs[group.name] || '')
  if (specValues.some((value) => value === '')) return null

  const specText = specValues.join(',')
  return skuList.value.find((sku) => sku.spec_values === specText) || null
}

function findSpecMediaIndex(specName, specValue) {
  if (!specName || !specValue) return -1

  return mediaList.value.findIndex((media) => (
    media.type === 'image' &&
    media.source === 'spec' &&
    media.specName === specName &&
    Array.isArray(media.specValues) &&
    media.specValues.includes(specValue)
  ))
}

function jumpToSpecMedia(specName, specValue) {
  const index = findSpecMediaIndex(specName, specValue)
  if (index < 0) return
  nextTick(() => {
    swiperIndex.value = index
  })
}

function normalizeDescriptionHtml(html) {
  if (!html) return ''

  return String(html)
    .replace(/<(p|div|section|span|table|tbody|thead|tr|td|th)\b([^>]*)>/gi, (match, tag, attrs) => {
      const cleanedAttrs = normalizeRichTextAttrs(attrs)
      return `<${tag}${cleanedAttrs}>`
    })
    .replace(/<img\b([^>]*)>/gi, (match, attrs) => {
      const cleanedAttrs = normalizeRichTextAttrs(attrs)

      return `<img${cleanedAttrs} style="max-width:100%;width:100%;height:auto;display:block;box-sizing:border-box;" />`
    })
}

async function fetchReviews(goodsId) {
  reviewLoading.value = true
  try {
    const res = await getReviewList(goodsId, {
      page: 1,
      limit: REVIEW_PREVIEW_LIMIT,
    })
    reviewTotal.value = Number(res?.total || 0)
    reviewList.value = Array.isArray(res?.list) ? res.list.map(normalizeReview) : []
  } catch {
    resetReviews()
  } finally {
    reviewLoading.value = false
  }
}

function normalizeReview(review) {
  const userName = normalizeReviewUserName(review)

  return {
    id: review.id,
    userName,
    userInitial: userName.slice(0, 1),
    rating: Math.max(1, Math.min(5, Number(review.rating || 5))),
    content: review.content || '',
    images: normalizeReviewImages(review.images_full_urls || review.images),
    replyContent: review.reply_content || '',
    createTimeText: formatReviewTime(review.create_time),
  }
}

function normalizeReviewUserName(review) {
  if (Number(review.is_anonymous || 0) === 1) return '匿名用户'
  return review.user_nickname || review.nickname || '用户'
}

function normalizeReviewImages(images) {
  if (Array.isArray(images)) return images.filter(Boolean)
  if (!images) return []

  try {
    const parsed = JSON.parse(images)
    return Array.isArray(parsed) ? parsed.filter(Boolean) : []
  } catch {
    return String(images)
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean)
  }
}

function formatReviewTime(time) {
  if (!time) return ''
  return String(time).slice(0, 10)
}

function normalizeRichTextAttrs(attrs = '') {
  return String(attrs)
    .replace(/\/\s*$/g, '')
    .replace(/\s(width|height)=["'][^"']*["']/gi, '')
    .replace(/\s(width|height)=[^\s>]*/gi, '')
    .replace(/\sstyle=(["'])(.*?)\1/gi, (match, quote, style) => {
      const rules = style
        .split(';')
        .map((rule) => rule.trim())
        .filter(Boolean)
        .filter((rule) => !/^(width|min-width|max-width|height|min-height|max-height|left|right|margin-left|margin-right|position|transform)\s*:/i.test(rule))

      if (rules.length === 0) return ''
      return ` style="${rules.join(';')}"`
    })
}

function onSwiperChange(event) {
  const index = event.detail.current
  swiperIndex.value = index

  const media = mediaList.value[index]
  if (
    !media ||
    media.type !== 'image' ||
    media.source !== 'spec' ||
    !media.specName ||
    !Array.isArray(media.specValues) ||
    media.specValues.length === 0
  ) {
    return
  }

  const nextSpecs = {
    ...selectedSpecs.value,
    [media.specName]: media.specValues[0],
  }
  const sku = findSkuBySpecs(nextSpecs)
  selectedSpecs.value = nextSpecs
  selectedSkuId.value = sku?.id || null
}

function previewImage(mediaIndex) {
  const media = mediaList.value[mediaIndex]
  if (!media || media.type !== 'image') return

  uni.previewImage({
    urls: imagePreviewUrls.value,
    current: media.url,
  })
}

function previewReviewImage(review, imageIndex) {
  if (!review?.images?.length) return

  uni.previewImage({
    urls: review.images,
    current: review.images[imageIndex],
  })
}

function onSpecChange(payload) {
  selectedSpecs.value = { ...(payload?.selectedSpecs || {}) }
  selectedSkuId.value = payload?.sku?.id || null

  const group = specImageGroup.value
  if (group && selectedSpecs.value[group.name]) {
    jumpToSpecMedia(group.name, selectedSpecs.value[group.name])
  }
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

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

function goCart() {
  uni.switchTab({ url: '/pages/cart/index' })
}

function onOpenSpec(mode) {
  specMode.value = hasMultiSpec.value ? (mode === 'buy' ? 'buy' : 'cart') : 'both'
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
  transition: height 0.2s ease;
}

.goods-detail__swiper {
  width: 100%;
  transition: height 0.2s ease;
}

.goods-detail__swiper-item {
  width: 100%;
  overflow: hidden;
}

.goods-detail__swiper-media {
  width: 100%;
  overflow: hidden;
}

.goods-detail__swiper-img,
.goods-detail__swiper-video {
  width: 100%;
  height: 100%;
  display: block;
}

.goods-detail__swiper-img {
  background: $mb-color-bg-secondary;
}

.goods-detail__swiper-video {
  height: 100%;
  background: #000000;
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

// ---------- Review section ----------
.goods-detail__review-section {
  background: $mb-color-bg;
  padding: $mb-spacing-lg $mb-spacing-page;
}

.goods-detail__review-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: $mb-spacing-md;
}

.goods-detail__review-title-wrap {
  display: flex;
  align-items: baseline;
  gap: $mb-spacing-sm;
}

.goods-detail__review-title {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: $mb-color-text-title;
}

.goods-detail__review-total {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

.goods-detail__review-loading,
.goods-detail__review-empty {
  padding: $mb-spacing-lg 0;
  text-align: center;
}

.goods-detail__review-empty-text {
  font-size: $mb-font-md;
  color: $mb-color-text-tertiary;
}

.goods-detail__review-item {
  padding: $mb-spacing-md 0;
  border-top: 1rpx solid $mb-color-divider;
}

.goods-detail__review-item--first {
  border-top: none;
  padding-top: 0;
}

.goods-detail__review-user-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
}

.goods-detail__review-avatar {
  width: 56rpx;
  height: 56rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-bg-secondary;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.goods-detail__review-avatar-text {
  font-size: $mb-font-sm;
  font-weight: 600;
  color: $mb-color-text-secondary;
}

.goods-detail__review-user-main {
  flex: 1;
  min-width: 0;
}

.goods-detail__review-user-name {
  display: block;
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-detail__review-star-row {
  display: flex;
  gap: 2rpx;
  margin-top: 2rpx;
}

.goods-detail__review-star {
  font-size: 22rpx;
  color: $mb-color-border-light;
  line-height: 1;
}

.goods-detail__review-star--active {
  color: $mb-color-warning;
}

.goods-detail__review-time {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
  flex-shrink: 0;
}

.goods-detail__review-content {
  display: block;
  margin-top: $mb-spacing-sm;
  font-size: $mb-font-md;
  color: $mb-color-text;
  line-height: 1.6;
  word-break: break-all;
}

.goods-detail__review-images {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
  margin-top: $mb-spacing-sm;
}

.goods-detail__review-image {
  width: 144rpx;
  height: 144rpx;
  border-radius: $mb-radius-sm;
  background: $mb-color-bg-secondary;
}

.goods-detail__review-reply {
  margin-top: $mb-spacing-sm;
  padding: $mb-spacing-sm;
  border-radius: $mb-radius-sm;
  background: $mb-color-bg-secondary;
}

.goods-detail__review-reply-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
  line-height: 1.5;
}

// ---------- Content section ----------
.goods-detail__content-section {
  background: $mb-color-bg;
  padding: $mb-spacing-xl 0 $mb-spacing-xl;
  width: 100%;
  max-width: 100vw;
  box-sizing: border-box;
  overflow: hidden;
}

.goods-detail__content-header {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  margin-bottom: $mb-spacing-lg;
  padding: 0 $mb-spacing-page;
  box-sizing: border-box;
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
  display: block;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  font-size: $mb-font-md;
  line-height: 1.7;
  color: $mb-color-text;
  word-break: break-all;
  overflow: hidden;
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
