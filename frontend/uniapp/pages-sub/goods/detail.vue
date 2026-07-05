<template>
  <view
    class="goods-detail"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
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
      icon=""
      text="商品不存在或已下架"
      action-text="返回首页"
      @action="goHome"
    />

    <!-- Main content -->
    <scroll-view
      v-else
      scroll-y
      class="goods-detail__scroll"
    >
      <!-- Media swiper -->
      <view
        class="goods-detail__swiper-wrap"
        :class="{ 'goods-detail__swiper-wrap--video': activeMediaIsVideo }"
        :style="{ height: `${currentMediaHeight}rpx` }"
        @touchstart="onMediaTouchStart"
        @touchend="onMediaTouchEnd"
      >
        <view class="goods-detail__float-actions">
          <view class="goods-detail__float-btn" @tap="goBack">
            <view class="goods-detail__back-icon" />
          </view>
          <view class="goods-detail__float-btn goods-detail__float-btn--share" @tap="onShare">
            <view class="goods-detail__share-icon" />
          </view>
        </view>
        <swiper
          v-if="mediaStage !== 'spec'"
          class="goods-detail__swiper"
          :style="{ height: `${currentMediaHeight}rpx` }"
          :current="goodsSwiperIndex"
          :indicator-dots="false"
          :autoplay="false"
          :circular="false"
          :duration="280"
          :skip-hidden-item-layout="true"
          easing-function="easeOutCubic"
          @change="onGoodsSwiperChange"
        >
          <swiper-item
            v-for="(media, idx) in goodsMediaList"
            :key="media.key"
            class="goods-detail__swiper-item"
            :style="{ height: `${currentMediaHeight}rpx` }"
          >
            <view class="goods-detail__swiper-media" :style="{ height: `${currentMediaHeight}rpx` }">
              <video
                v-if="media.type === 'video'"
                :id="GOODS_VIDEO_ID"
                class="goods-detail__swiper-video"
                :src="media.url"
                :poster="goodsPosterImageUrl"
                preload="metadata"
                controls
                object-fit="contain"
              />
              <image
                v-else
                class="goods-detail__swiper-img"
                :src="media.url"
                :style="{ height: `${currentMediaHeight}rpx` }"
                mode="aspectFill"
                @tap="previewGoodsImage(idx)"
              />
            </view>
          </swiper-item>
        </swiper>
        <swiper
          v-else
          class="goods-detail__swiper goods-detail__swiper--spec"
          :style="{ height: `${currentMediaHeight}rpx` }"
          :current="specSwiperIndex"
          :indicator-dots="false"
          :autoplay="false"
          :circular="false"
          :duration="280"
          :skip-hidden-item-layout="true"
          easing-function="easeOutCubic"
          @change="onSpecSwiperChange"
        >
          <swiper-item
            v-for="(media, idx) in specMediaList"
            :key="media.key"
            class="goods-detail__swiper-item"
            :style="{ height: `${currentMediaHeight}rpx` }"
          >
            <view class="goods-detail__swiper-media" :style="{ height: `${currentMediaHeight}rpx` }">
              <image
                class="goods-detail__swiper-img"
                :src="media.url"
                :style="{ height: `${currentMediaHeight}rpx` }"
                mode="aspectFill"
                @tap="previewSpecImage(idx)"
              />
            </view>
          </swiper-item>
        </swiper>
        <view v-if="hasMediaStageSwitch" class="goods-detail__media-tabs">
          <view
            class="goods-detail__media-tab"
            :class="{ 'goods-detail__media-tab--active': mediaStage === 'goods' }"
            @tap.stop="switchMediaStage('goods')"
          >
            <text class="goods-detail__media-tab-text">商品图</text>
          </view>
          <view
            class="goods-detail__media-tab"
            :class="{ 'goods-detail__media-tab--active': mediaStage === 'spec' }"
            @tap.stop="switchMediaStage('spec')"
          >
            <text class="goods-detail__media-tab-text">规格图</text>
          </view>
        </view>
        <view v-if="activeMediaList.length > 1" class="goods-detail__counter">
          <text class="goods-detail__counter-text">
            {{ mediaStageText }} {{ activeSwiperIndex + 1 }}/{{ activeMediaList.length }}
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
        </view>
        <mb-benefit-strip
          v-if="hasSkuBenefit"
          class="goods-detail__benefits"
          :items="benefitItems"
          variant="card"
        />
      </view>

      <!-- Title section -->
      <view class="goods-detail__title-section">
        <text class="goods-detail__name">{{ goods.name }}</text>
        <text v-if="goods.subtitle" class="goods-detail__subtitle">{{ goods.subtitle }}</text>
        <view class="goods-detail__metrics">
          <view
            v-for="item in goodsMetrics"
            :key="item.label"
            class="goods-detail__metric"
          >
            <text class="goods-detail__metric-value">{{ item.value }}</text>
            <text class="goods-detail__metric-label">{{ item.label }}</text>
          </view>
        </view>
      </view>

      <!-- Guarantees -->
      <view v-if="guarantees.length > 0" class="goods-detail__guarantees">
        <view
          v-for="item in guarantees"
          :key="item.title"
          class="goods-detail__guarantee"
        >
          <view class="goods-detail__guarantee-dot" />
          <text class="goods-detail__guarantee-title">{{ item.title }}</text>
        </view>
      </view>

      <!-- Divider -->
      <view class="goods-detail__divider" />

      <!-- Spec selector trigger -->
      <view v-if="shouldShowSpecSection" class="goods-detail__spec-section" @tap="showSpec = true">
        <view class="goods-detail__spec-header">
          <text class="goods-detail__spec-title">选择规格</text>
          <view class="goods-detail__spec-right">
            <text class="goods-detail__spec-current">{{ specDisplayText }}</text>
            <text class="goods-detail__cell-arrow">&#10095;</text>
          </view>
        </view>
        <scroll-view
          v-if="specPreviewItems.length > 0"
          scroll-x
          class="goods-detail__spec-preview-scroll"
          :scroll-left="specPreviewScrollLeft"
          scroll-with-animation
          :show-scrollbar="false"
        >
          <view class="goods-detail__spec-preview">
            <view
              v-for="item in specPreviewItems"
              :key="item.key"
              class="goods-detail__spec-preview-item"
              :class="{ 'goods-detail__spec-preview-item--active': item.active }"
              @tap.stop="selectSkuFromPreview(item.sku)"
            >
              <image
                v-if="item.image"
                class="goods-detail__spec-preview-img"
                :src="item.image"
                mode="aspectFill"
              />
              <text
                class="goods-detail__spec-preview-text"
                :class="{ 'goods-detail__spec-preview-text--overlay': item.image }"
              >
                {{ item.value }}
              </text>
            </view>
          </view>
        </scroll-view>
      </view>

      <!-- Divider -->
      <view v-if="shouldShowSpecSection" class="goods-detail__divider" />

      <!-- Reviews -->
      <view class="goods-detail__review-section">
        <view class="goods-detail__review-header">
          <view class="goods-detail__review-title-wrap">
            <text class="goods-detail__review-title">评价</text>
            <text v-if="reviewTotal > 0" class="goods-detail__review-total">({{ reviewTotal }})</text>
          </view>
          <view
            v-if="reviewTotal > 0"
            class="goods-detail__review-view-all"
            @tap="onViewAllReviews"
          >
            <text class="goods-detail__review-view-all-text">查看全部</text>
            <text class="goods-detail__review-view-all-arrow">&#10095;</text>
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
            v-for="review in reviewList"
            :key="review.id"
            class="goods-detail__review-item"
          >
            <view class="goods-detail__review-user-row">
              <view class="goods-detail__review-avatar">
                <image
                  v-if="review.avatarUrl"
                  class="goods-detail__review-avatar-img"
                  :src="review.avatarUrl"
                  mode="aspectFill"
                  @error="onReviewAvatarError(review)"
                />
                <text v-else class="goods-detail__review-avatar-text">{{ review.userInitial }}</text>
              </view>
              <view class="goods-detail__review-user-main">
                <view class="goods-detail__review-user-line">
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
                <text class="goods-detail__review-meta">{{ review.metaText }}</text>
              </view>
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
            <view
              v-if="review.appendContent || review.appendImages.length > 0"
              class="goods-detail__review-append"
            >
              <view class="goods-detail__review-append-header">
                <text class="goods-detail__review-append-label">追评</text>
                <text v-if="review.appendTimeText" class="goods-detail__review-append-time">
                  {{ review.appendTimeText }}
                </text>
              </view>
              <text v-if="review.appendContent" class="goods-detail__review-append-content">
                {{ review.appendContent }}
              </text>
              <view v-if="review.appendImages.length > 0" class="goods-detail__review-images">
                <image
                  v-for="(image, imageIndex) in review.appendImages"
                  :key="image"
                  class="goods-detail__review-image"
                  :src="image"
                  mode="aspectFill"
                  @tap="previewReviewAppendImage(review, imageIndex)"
                />
              </view>
            </view>
            <view v-if="review.replyContent" class="goods-detail__review-reply">
              <text class="goods-detail__review-reply-prefix">商家回复：</text>
              <text class="goods-detail__review-reply-text">{{ review.replyContent }}</text>
            </view>
          </view>
        </block>
      </view>

      <!-- Divider -->
      <view class="goods-detail__divider" />

      <!-- Product detail / rich text -->
      <view class="goods-detail__content-section">
        <view class="goods-detail__content-card">
          <view class="goods-detail__content-header">
            <view class="goods-detail__content-heading">
              <text class="goods-detail__content-title">商品详情</text>
              <text
                v-if="usingSkuDescription && selectedSpecText"
                class="goods-detail__content-spec"
              >
                {{ selectedSpecText }}
              </text>
            </view>
          </view>
          <view class="goods-detail__content-body">
            <rich-text v-if="descriptionNodes" :nodes="descriptionNodes" class="goods-detail__rich-text" />
            <view v-else class="goods-detail__content-empty">
              <text class="goods-detail__content-empty-text">暂无详情</text>
            </view>
          </view>
        </view>
      </view>

      <!-- Bottom spacer for fixed bar -->
      <mb-copyright-footer />
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
      :points-enabled="pointsEnabled"
      :member-enabled="memberEnabled"
      @change="onSpecChange"
      @close="showSpec = false"
      @add-to-cart="onAddToCart"
      @buy-now="onBuyNow"
    />
      <mb-floating-action />
</view>
</template>

<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, computed, nextTick, watch } from 'vue'
import { onHide, onLoad, onShareAppMessage, onShareTimeline, onUnload } from '@dcloudio/uni-app'
import { getGoodsDetail } from '@/api/goods/goods'
import { getReviewList } from '@/api/goods/review'
import { useCartStore } from '@/store/cart'
import { useAppStore } from '@/store/app'
import { openCustomerService } from '@/utils/customer-service'
import { appendDistributionParams, captureDistributionAttribution } from '@/utils/distribution-attribution'
import {
  buildGoodsDetailBenefitItems,
  formatExtensionAmount,
  isExtensionFeatureEnabled,
} from '@/utils/extension-slots'
const decorateStore = useDecorateStore()

const MEDIA_HEIGHT = 660
const REVIEW_PREVIEW_LIMIT = 3
const SPEC_PREVIEW_ITEM_WIDTH = 160
const SPEC_PREVIEW_ITEM_GAP = 16
const SPEC_PREVIEW_SECTION_PADDING = 64
const GOODS_VIDEO_ID = 'goods-detail-main-video'

const cartStore = useCartStore()
const appStore = useAppStore()

const loading = ref(true)
const goods = ref(null)
const goodsId = ref('')
const mediaStage = ref('goods')
const goodsSwiperIndex = ref(0)
const specSwiperIndex = ref(0)
const specPreviewScrollLeft = ref(0)
const showSpec = ref(false)
const specMode = ref('both')
const selectedSpecs = ref({})
const selectedSkuId = ref(null)
const reviewLoading = ref(false)
const reviewTotal = ref(0)
const reviewList = ref([])
let mediaTouchStartX = 0
const preloadedMediaImages = new Set()

onLoad((query) => {
  captureDistributionAttribution(query || {}, '/pages-sub/goods/detail')
  const id = resolveGoodsId(query)
  if (id) {
    goodsId.value = id
    fetchDetail(id)
  } else {
    loading.value = false
  }
})

onHide(() => {
  pauseGoodsVideo()
})

onUnload(() => {
  pauseGoodsVideo()
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

function resolveGoodsId(query) {
  const queryId = query?.id
  if (queryId) return String(queryId)

  // #ifdef H5
  if (typeof window !== 'undefined') {
    const hashQuery = String(window.location.hash || '').split('?')[1] || ''
    const id = new URLSearchParams(hashQuery).get('id')
    if (id) return id
  }
  // #endif

  return ''
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
const hasSelectableSpecs = computed(() => specGroups.value.some((group) => (
  Array.isArray(group.items) && group.items.length > 1
)))
const shouldShowSpecSection = computed(() => hasSelectableSpecs.value)
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

const currentSkuImageUrl = computed(() => getSkuImageUrl(selectedSku.value))
const goodsPosterImageUrl = computed(() => (
  goods.value?.main_image_full_url ||
  normalizeImageUrl(Array.isArray(goods.value?.images) ? goods.value.images[0] : '') ||
  ''
))

const goodsMediaList = computed(() => {
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

  const appendImage = (url, source = 'goods') => {
    if (!url) return
    const existedIndex = imageIndexMap.get(url)
    if (existedIndex !== undefined) return

    const item = {
      key: `${source}:${url}`,
      type: 'image',
      url,
      source,
    }
    imageIndexMap.set(url, list.length)
    list.push(item)
  }

  const goodsImages = Array.isArray(goods.value.images) ? goods.value.images : []
  goodsImages
    .map((image) => normalizeImageUrl(image))
    .filter((url) => url)
    .forEach((url) => appendImage(url, 'goods'))

  if (list.filter((item) => item.type === 'image').length === 0) {
    appendImage(goods.value.main_image_full_url || goods.value.main_image || '', 'goods')
  }

  return list
})

const currentMediaHeight = computed(() => MEDIA_HEIGHT)

const specMediaList = computed(() => {
  if (!hasMultiSpec.value) return []

  return skuList.value
    .map((sku, index) => {
      const url = getSkuImageUrl(sku)
      if (!url) return null

      return {
        key: `sku:${sku.id || sku.sku_code || index}`,
        sku,
        skuId: sku.id,
        specs: buildSpecsFromSku(sku),
        specLabel: formatSkuSpecText(sku.spec_values),
        specValue: formatSkuSpecText(sku.spec_values),
        specValues: parseSkuSpecValues(sku),
        type: 'image',
        url,
        source: 'spec',
      }
    })
    .filter(Boolean)
})

const hasSpecMedia = computed(() => specMediaList.value.length > 0)
const hasMediaStageSwitch = computed(() => goodsMediaList.value.length > 0 && hasSpecMedia.value)
const activeMediaList = computed(() => (
  mediaStage.value === 'spec' && hasSpecMedia.value
    ? specMediaList.value
    : goodsMediaList.value
))
const activeSwiperIndex = computed(() => (
  mediaStage.value === 'spec' && hasSpecMedia.value
    ? specSwiperIndex.value
    : goodsSwiperIndex.value
))
const activeMediaIsVideo = computed(() => activeMediaList.value[activeSwiperIndex.value]?.type === 'video')
const mediaStageText = computed(() => (
  mediaStage.value === 'spec' && hasSpecMedia.value ? '规格图' : '商品图'
))

const currentDescriptionHtml = computed(() => {
  if (Number(goods.value?.spec_type || 1) === 2 && Number(goods.value?.sku_detail_enabled || 0) === 1) {
    const skuDescription = selectedSku.value?.description || ''
    if (skuDescription) return skuDescription
  }
  return goods.value?.description || ''
})

const descriptionNodes = computed(() => normalizeDescriptionHtml(currentDescriptionHtml.value))
const usingSkuDescription = computed(() => (
  Number(goods.value?.spec_type || 1) === 2 &&
  Number(goods.value?.sku_detail_enabled || 0) === 1 &&
  !!(selectedSku.value?.description || '').trim()
))

const goodsMetrics = computed(() => [
  {
    label: '已售',
    value: formatCompactNumber(goods.value?.sales ?? 0),
  },
  {
    label: selectedSpecText.value ? '当前库存' : '库存',
    value: formatCompactNumber(displayStock.value),
  },
  {
    label: '单位',
    value: goods.value?.unit || '件',
  },
])

const specPreviewItems = computed(() => (
  specMediaList.value.map((media) => ({
    key: media.key,
    active: selectedSku.value && String(selectedSku.value.id) === String(media.skuId),
    image: media.url,
    sku: media.sku,
    value: media.specLabel,
  }))
))

watch(
  () => [
    ...goodsMediaList.value.map((item) => item.url),
    ...specMediaList.value.map((item) => item.url),
  ].join('|'),
  () => {
    preloadMediaImages()
  },
  { flush: 'post' },
)

const displayPrice = computed(() => selectedSku.value?.price ?? goods.value?.price ?? '0')
const displayMarketPrice = computed(() => selectedSku.value?.market_price ?? goods.value?.market_price ?? '')
const displayStock = computed(() => selectedSku.value?.stock ?? goods.value?.stock ?? 0)
const guarantees = computed(() => (Array.isArray(goods.value?.guarantees) ? goods.value.guarantees : []))
const pointsEnabled = computed(() => isExtensionFeatureEnabled(appStore.siteConfig?.points_enabled, true))
const memberEnabled = computed(() => isExtensionFeatureEnabled(appStore.siteConfig?.member_enabled, false))
const benefitItems = computed(() =>
  buildGoodsDetailBenefitItems({
    goods: goods.value,
    siteConfig: appStore.siteConfig,
    sku: selectedSku.value,
  }),
)
const hasSkuBenefit = computed(() => benefitItems.value.length > 0)

const formattedPrice = computed(() => {
  return formatExtensionAmount(displayPrice.value)
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
  mediaStage.value = 'goods'
  goodsSwiperIndex.value = 0
  specSwiperIndex.value = 0
  specPreviewScrollLeft.value = 0
  selectedSpecs.value = {}
  selectedSkuId.value = null

  if (!hasMultiSpec.value && skuList.value.length === 1) {
    selectedSkuId.value = skuList.value[0].id
    return
  }

  // 多规格：默认选第一个有库存的 SKU（无可用则选首条）
  const defaultSku = skuList.value.find((sku) => Number(sku.stock) > 0) || skuList.value[0]
  if (!defaultSku) return

  selectedSpecs.value = buildSpecsFromSku(defaultSku)
  selectedSkuId.value = defaultSku.id
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

function parseSkuSpecValues(sku) {
  return String(sku?.spec_values || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

function formatSkuSpecText(specValues) {
  const values = Array.isArray(specValues)
    ? specValues
    : String(specValues || '').split(',')

  const text = values
    .map((item) => String(item).trim())
    .filter(Boolean)
    .join(' / ')

  return text || '默认规格'
}

function getSkuSpecGroupImageUrl(sku) {
  const group = specImageGroup.value
  if (!group || !Array.isArray(group.items)) return ''

  const groupIndex = specGroups.value.findIndex((item) => item.name === group.name)
  if (groupIndex < 0) return ''

  const specValue = parseSkuSpecValues(sku)[groupIndex]
  if (!specValue) return ''

  const item = group.items.find((entry) => entry.value === specValue)
  return item?.pic_full_url || ''
}

function getSkuImageUrl(sku) {
  if (!sku) return ''
  return (
    sku.image_full_url ||
    getSkuSpecGroupImageUrl(sku) ||
    goods.value?.main_image_full_url ||
    normalizeImageUrl(Array.isArray(goods.value?.images) ? goods.value.images[0] : '') ||
    ''
  )
}

function normalizeMediaIdentity(url) {
  if (!url) return ''
  return String(url)
}

function preloadMediaImages() {
  if (typeof Image === 'undefined') return

  const urls = [
    ...goodsMediaList.value,
    ...specMediaList.value,
  ]
    .filter((item) => item?.type === 'image' && item.url)
    .map((item) => item.url)

  urls.forEach((url) => {
    if (preloadedMediaImages.has(url)) return
    preloadedMediaImages.add(url)
    const image = new Image()
    image.decoding = 'async'
    image.src = url
  })
}

function pauseGoodsVideo() {
  if (!goodsMediaList.value.some((item) => item.type === 'video')) return

  try {
    uni.createVideoContext(GOODS_VIDEO_ID).pause()
  } catch (error) {
    void error
  }

  if (typeof document !== 'undefined') {
    const video = document.getElementById(GOODS_VIDEO_ID)
    if (video && typeof video.pause === 'function') {
      video.pause()
    }
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

function buildSpecsFromSku(sku) {
  const specValues = String(sku?.spec_values || '').split(',')
  const nextSpecs = {}
  specGroups.value.forEach((group, idx) => {
    const value = specValues[idx]
    if (value) nextSpecs[group.name] = value
  })
  return nextSpecs
}

function findSkuBySpecValue(specName, specValue) {
  const groupIndex = specGroups.value.findIndex((group) => group.name === specName)
  if (groupIndex < 0) return null

  return skuList.value.find((sku) => {
    const specValues = String(sku.spec_values || '').split(',')
    return specValues[groupIndex] === specValue
  }) || null
}

function findCurrentSkuMediaIndex() {
  if (selectedSku.value?.id) {
    const skuIndex = specMediaList.value.findIndex((media) => (
      String(media.skuId) === String(selectedSku.value.id)
    ))
    if (skuIndex >= 0) return skuIndex
  }

  const currentImage = currentSkuImageUrl.value
  if (!currentImage) return -1

  return specMediaList.value.findIndex((media) => (
    media.type === 'image' &&
    normalizeMediaIdentity(media.url) === normalizeMediaIdentity(currentImage)
  ))
}

function focusCurrentSpecMedia() {
  if (!hasSpecMedia.value) return
  nextTick(() => {
    const index = findCurrentSkuMediaIndex()
    const targetIndex = index >= 0 ? index : 0
    specSwiperIndex.value = targetIndex
    syncSpecPreviewScroll(targetIndex)
    mediaStage.value = 'spec'
  })
}

function syncSpecPreviewScroll(index) {
  const currentIndex = Number(index)
  if (!Number.isInteger(currentIndex) || currentIndex <= 0) {
    specPreviewScrollLeft.value = 0
    return
  }

  const itemWidth = uni.upx2px(SPEC_PREVIEW_ITEM_WIDTH)
  const itemGap = uni.upx2px(SPEC_PREVIEW_ITEM_GAP)
  const sectionPadding = uni.upx2px(SPEC_PREVIEW_SECTION_PADDING)
  const viewportWidth = Math.max(0, Number(uni.getSystemInfoSync().windowWidth || 0) - sectionPadding)
  const itemOffset = currentIndex * (itemWidth + itemGap)
  const centeredOffset = viewportWidth > itemWidth
    ? itemOffset - (viewportWidth - itemWidth) / 2
    : itemOffset

  specPreviewScrollLeft.value = Math.max(0, Math.round(centeredOffset))
}

function formatCompactNumber(value) {
  const num = Number(value || 0)
  if (!Number.isFinite(num)) return '0'
  if (num >= 10000) {
    const text = (num / 10000).toFixed(num % 10000 === 0 ? 0 : 1)
    return `${text}万`
  }
  return String(num)
}

function normalizeDescriptionHtml(html) {
  if (!html) return ''

  const tagStyles = {
    div: 'max-width:100%;box-sizing:border-box;',
    section: 'max-width:100%;box-sizing:border-box;',
    p: 'margin:0 0 22rpx;line-height:1.88;font-size:28rpx;color:var(--color-text,#191b23);word-break:break-word;',
    h1: 'margin:0 0 24rpx;font-size:42rpx;line-height:1.3;font-weight:800;color:var(--color-text-title,#191b23);',
    h2: 'margin:36rpx 0 18rpx;font-size:36rpx;line-height:1.35;font-weight:800;color:var(--color-text-title,#191b23);',
    h3: 'margin:34rpx 0 16rpx;font-size:30rpx;line-height:1.42;font-weight:800;color:var(--color-text-title,#191b23);',
    h4: 'margin:28rpx 0 14rpx;font-size:30rpx;line-height:1.4;font-weight:800;color:var(--color-text-title,#191b23);',
    ul: 'margin:2rpx 0 26rpx;padding-left:34rpx;',
    ol: 'margin:2rpx 0 26rpx;padding-left:34rpx;',
    li: 'margin:0 0 10rpx;line-height:1.78;font-size:28rpx;color:var(--color-text,#191b23);word-break:break-word;',
    table: 'width:100%;max-width:100%;margin:24rpx 0;border-collapse:collapse;table-layout:fixed;background:var(--color-bg,#ffffff);',
    th: 'padding:18rpx 16rpx;border:1rpx solid var(--color-divider,#f0f2f5);font-size:26rpx;font-weight:800;line-height:1.5;color:var(--color-text-title,#191b23);background:var(--color-bg-surface,#f3f3fe);word-break:break-word;',
    td: 'padding:18rpx 16rpx;border:1rpx solid var(--color-divider,#f0f2f5);font-size:26rpx;line-height:1.5;color:var(--color-text,#191b23);word-break:break-word;',
  }

  return String(html)
    .replace(/<(h[1-6]|p|div|section|span|table|tbody|thead|tr|td|th|ul|ol|li)\b([^>]*)>/gi, (match, tag, attrs) => {
      const normalizedTag = tag.toLowerCase()
      const cleanedAttrs = mergeRichTextAttrs(attrs, tagStyles[normalizedTag] || '')
      return `<${tag}${cleanedAttrs}>`
    })
    .replace(/<img\b([^>]*)>/gi, (match, attrs) => {
      const cleanedAttrs = mergeRichTextAttrs(
        attrs,
        'max-width:100%;width:100%;height:auto;display:block;margin:24rpx 0;border-radius:20rpx;box-sizing:border-box;',
      )

      return `<img${cleanedAttrs} />`
    })
}

function mergeRichTextAttrs(attrs = '', extraStyle = '') {
  const cleanedAttrs = normalizeRichTextAttrs(attrs)
  if (!extraStyle) return cleanedAttrs

  if (/\sstyle="/i.test(cleanedAttrs)) {
    return cleanedAttrs.replace(/\sstyle="([^"]*)"/i, (match, style) => (
      ` style="${style ? `${style};` : ''}${extraStyle}"`
    ))
  }

  return `${cleanedAttrs} style="${extraStyle}"`
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
  const avatarUrl = Number(review.is_anonymous || 0) === 1
    ? ''
    : (review.user_avatar_full_url || review.avatar_full_url || review.avatar || '')
  const skuSpecText = review.sku_spec_text || review.spec_values_text || review.spec_values || ''
  const createTimeText = formatReviewTime(review.create_time)

  return {
    id: review.id,
    userName,
    userInitial: userName.slice(0, 1),
    avatarUrl,
    rating: Math.max(1, Math.min(5, Number(review.rating || 5))),
    content: review.content || '',
    images: normalizeReviewImages(review.images_full_urls || review.images),
    appendContent: review.append_content || '',
    appendImages: normalizeReviewImages(review.append_images_full_urls || review.append_images),
    appendTimeText: formatReviewTime(review.append_time),
    replyContent: review.reply_content || '',
    createTimeText,
    skuSpecText,
    metaText: skuSpecText ? `${createTimeText} | ${skuSpecText}` : createTimeText,
  }
}

function onReviewAvatarError(review) {
  if (review) review.avatarUrl = ''
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
    .replace(/\sclass=(["'])(.*?)\1/gi, '')
    .replace(/\sclass=[^\s>]*/gi, '')
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

function onGoodsSwiperChange(event) {
  const index = event.detail.current
  const previousMedia = goodsMediaList.value[goodsSwiperIndex.value]
  goodsSwiperIndex.value = index
  if (previousMedia?.type === 'video' && previousMedia !== goodsMediaList.value[index]) {
    pauseGoodsVideo()
  }
}

function onSpecSwiperChange(event) {
  const index = event.detail.current
  specSwiperIndex.value = index
  syncSelectedSpecFromMedia(specMediaList.value[index])
  syncSpecPreviewScroll(index)
}

function onMediaTouchStart(event) {
  const touch = event?.changedTouches?.[0] || event?.touches?.[0]
  mediaTouchStartX = Number(touch?.clientX || touch?.pageX || 0)
}

function onMediaTouchEnd(event) {
  const touch = event?.changedTouches?.[0] || event?.touches?.[0]
  const endX = Number(touch?.clientX || touch?.pageX || 0)
  const deltaX = endX - mediaTouchStartX
  if (Math.abs(deltaX) < 50) return

  if (
    deltaX < 0 &&
    mediaStage.value === 'goods' &&
    hasSpecMedia.value &&
    goodsSwiperIndex.value >= goodsMediaList.value.length - 1
  ) {
    switchMediaStage('spec')
    return
  }

  if (
    deltaX > 0 &&
    mediaStage.value === 'spec' &&
    goodsMediaList.value.length > 0 &&
    specSwiperIndex.value <= 0
  ) {
    switchMediaStage('goods', { alignToEnd: true })
  }
}

function switchMediaStage(stage, options = {}) {
  if (stage === 'spec') {
    if (!hasSpecMedia.value) return
    pauseGoodsVideo()
    mediaStage.value = 'spec'
    focusCurrentSpecMedia()
    return
  }

  if (options.alignToEnd) {
    goodsSwiperIndex.value = Math.max(0, goodsMediaList.value.length - 1)
  }
  mediaStage.value = 'goods'
}

function syncSelectedSpecFromMedia(media) {
  if (media?.sku) {
    selectedSpecs.value = buildSpecsFromSku(media.sku)
    selectedSkuId.value = media.sku.id
    return
  }

  if (!media?.specName || !media?.specValue) return

  const nextSpecs = {
    ...selectedSpecs.value,
    [media.specName]: media.specValue,
  }
  const sku = findSkuBySpecs(nextSpecs) || findSkuBySpecValue(media.specName, media.specValue)
  selectedSpecs.value = sku ? buildSpecsFromSku(sku) : nextSpecs
  selectedSkuId.value = sku?.id || null
}

function selectSkuFromPreview(sku) {
  if (!sku) return
  selectedSpecs.value = buildSpecsFromSku(sku)
  selectedSkuId.value = sku.id
  focusCurrentSpecMedia()
}

function previewGoodsImage(mediaIndex) {
  const media = goodsMediaList.value[mediaIndex]
  if (!media || media.type !== 'image') return

  const urls = goodsMediaList.value
    .filter((item) => item.type === 'image')
    .map((item) => item.url)

  uni.previewImage({
    urls,
    current: media.url,
  })
}

function previewSpecImage(mediaIndex) {
  const media = specMediaList.value[mediaIndex]
  if (!media || media.type !== 'image') return

  uni.previewImage({
    urls: specMediaList.value.map((item) => item.url),
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

function previewReviewAppendImage(review, imageIndex) {
  if (!review?.appendImages?.length) return

  uni.previewImage({
    urls: review.appendImages,
    current: review.appendImages[imageIndex],
  })
}

function onSpecChange(payload) {
  selectedSpecs.value = { ...(payload?.selectedSpecs || {}) }
  selectedSkuId.value = payload?.sku?.id || null
  focusCurrentSpecMedia()
}

function buildShareTarget() {
  const config = appStore.siteConfig || {}
  const title = goods.value?.name || config.client_share_title || config.site_name || 'MallBase'
  const id = goodsId.value
  const path = appendDistributionParams(`/pages-sub/goods/detail?id=${id}`, {
    dist_page: '/pages-sub/goods/detail',
    dist_scene: 'share_link',
    dist_target_id: id,
    dist_target_type: 'goods',
  })
  const query = path.split('?')[1] || `id=${id}`
  const imageUrl =
    goods.value?.main_image_full_url ||
    goods.value?.main_image ||
    config.client_share_cover ||
    ''
  return { title, path, query, imageUrl }
}

function onShare() {
  // #ifdef MP-WEIXIN
  uni.showToast({ title: '点右上角 ··· 转发', icon: 'none' })
  // #endif
  // #ifdef H5
  const target = buildShareTarget()
  const { title } = target
  const url = typeof location !== 'undefined'
    ? `${location.origin}${location.pathname}#${target.path}`
    : ''
  const ua = ((typeof navigator !== 'undefined' && navigator.userAgent) || '').toLowerCase()
  const inWechat = ua.includes('micromessenger')
  if (!inWechat && typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
    navigator.share({ title, text: title, url }).catch(() => {})
    return
  }
  uni.setClipboardData({
    data: url,
    success: () => {
      uni.showToast({
        title: inWechat ? '链接已复制，点右上角 ··· 分享' : '链接已复制',
        icon: 'none',
      })
    },
  })
  // #endif
  // #ifdef APP-PLUS
  uni.showToast({ title: '请使用系统分享', icon: 'none' })
  // #endif
}

onShareAppMessage(() => {
  const { title, path, imageUrl } = buildShareTarget()
  return { title, path, imageUrl }
})

onShareTimeline(() => {
  const { title, query, imageUrl } = buildShareTarget()
  return { title, query, imageUrl }
})

async function contactService() {
  await openCustomerService()
}

function goBack() {
  uni.navigateBack({ fail: () => goHome() })
}

function goHome() {
  uni.switchTab({ url: '/pages/index/index' })
}

function goCart() {
  uni.switchTab({ url: '/pages/cart/index' })
}

function onViewAllReviews() {
  if (!goods.value?.id) return
  const title = encodeURIComponent(goods.value.name || '')
  uni.navigateTo({
    url: `/pages-sub/goods/comments?goods_id=${goods.value.id}&title=${title}`,
  })
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
    goods_image: getSkuImageUrl(sku),
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
  background: var(--color-bg-secondary, #f6f7fb);
}

// ---------- Floating media actions ----------
.goods-detail__float-actions {
  position: absolute;
  left: $mb-spacing-md;
  right: $mb-spacing-md;
  top: calc(24rpx + env(safe-area-inset-top));
  z-index: 10;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.goods-detail__float-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.42);
  color: #ffffff;
  backdrop-filter: blur(12rpx);
  -webkit-backdrop-filter: blur(12rpx);
}

/* Icons sourced from Google Material Symbols Outlined (Apache 2.0)
   per Stitch design v3 spec: arrow_back / share / headset_mic / shopping_cart */
.goods-detail__back-icon {
  width: 40rpx;
  height: 40rpx;
  background-image: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0OCIgaGVpZ2h0PSI0OCIgdmlld0JveD0iMCAtOTYwIDk2MCA5NjAiPjxwYXRoIGZpbGw9IiNmZmZmZmYiIGQ9Im0yNzQtNDUwIDI0OCAyNDgtNDIgNDItMzIwLTMyMCAzMjAtMzIwIDQyIDQyLTI0OCAyNDhoNTI2djYwSDI3NFoiLz48L3N2Zz4=");
  background-size: 100% 100%;
  background-repeat: no-repeat;
}

.goods-detail__share-icon {
  width: 40rpx;
  height: 40rpx;
  background-image: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0OCIgaGVpZ2h0PSI0OCIgdmlld0JveD0iMCAtOTYwIDk2MCA5NjAiPjxwYXRoIGZpbGw9IiNmZmZmZmYiIGQ9Ik02ODYtODBxLTQ3LjUgMC04MC43NS0zMy4yNVQ1NzItMTk0cTAtOCA1LTM0TDI3OC00MDNxLTE2LjI4IDE3LjM0LTM3LjY0IDI3LjE3UTIxOS0zNjYgMTk0LTM2NnEtNDcuNSAwLTgwLjc1LTMzVDgwLTQ4MHEwLTQ4IDMzLjI1LTgxVDE5NC01OTRxMjQgMCA0NSA5LjMgMjEgOS4yOSAzNyAyNS43bDMwMS0xNzNxLTItOC0zLjUtMTYuNVQ1NzItNzY2cTAtNDcuNSAzMy4yNS04MC43NVQ2ODYtODgwcTQ3LjUgMCA4MC43NSAzMy4yNVQ4MDAtNzY2cTAgNDcuNS0zMy4yNSA4MC43NVQ2ODYtNjUycS0yMy4yNyAwLTQzLjY0LTlRNjIyLTY3MCA2MDYtNjg1TDMwMi01MTZxMyA4IDQuNSAxNy41dDEuNSAxOHEwIDguNS0xIDE2dC0zIDE1LjVsMzAzIDE3M3ExNi0xNSAzNi4wOS0yMy41IDIwLjEtOC41IDQzLjA3LTguNVE3MzQtMzA4IDc2Ny0yNzQuNzVUODAwLTE5NHEwIDQ3LjUtMzMuMjUgODAuNzVUNjg2LTgwWm0uMDQtNjBxMjIuOTYgMCAzOC40Ni0xNS41NCAxNS41LTE1LjUzIDE1LjUtMzguNSAwLTIyLjk2LTE1LjU0LTM4LjQ2LTE1LjUzLTE1LjUtMzguNS0xNS41LTIyLjk2IDAtMzguNDYgMTUuNTQtMTUuNSAxNS41My0xNS41IDM4LjUgMCAyMi45NiAxNS41NCAzOC40NiAxNS41MyAxNS41IDM4LjUgMTUuNVptLTQ5Mi0yODZxMjIuOTYgMCAzOC40Ni0xNS41NCAxNS41LTE1LjUzIDE1LjUtMzguNSAwLTIyLjk2LTE1LjU0LTM4LjQ2LTE1LjUzLTE1LjUtMzguNS0xNS41LTIyLjk2IDAtMzguNDYgMTUuNTQtMTUuNSAxNS41My0xNS41IDM4LjUgMCAyMi45NiAxNS41NCAzOC40NiAxNS41MyAxNS41IDM4LjUgMTUuNVpNNzI0LjUtNzI3LjU0cTE1LjUtMTUuNTMgMTUuNS0zOC41IDAtMjIuOTYtMTUuNTQtMzguNDYtMTUuNTMtMTUuNS0zOC41LTE1LjUtMjIuOTYgMC0zOC40NiAxNS41NC0xNS41IDE1LjUzLTE1LjUgMzguNSAwIDIyLjk2IDE1LjU0IDM4LjQ2IDE1LjUzIDE1LjUgMzguNSAxNS41IDIyLjk2IDAgMzguNDYtMTUuNTRaTTY4Ni0xOTRaTTE5NC00ODBabTQ5Mi0yODZaIi8+PC9zdmc+");
  background-size: 100% 100%;
  background-repeat: no-repeat;
}

/* 微信小程序右上角胶囊已自带"转发"，分享按钮重复且会被胶囊遮挡，故隐藏 */
/* #ifdef MP-WEIXIN */
.goods-detail__float-btn--share {
  display: none;
}
/* #endif */

// ---------- Loading skeleton ----------
.goods-detail__loading {
  padding-top: 0;
}

.goods-detail__skeleton-swiper {
  width: 100%;
  height: 660rpx;
  background: linear-gradient(
    90deg,
    var(--color-bg-secondary, #faf8ff) 25%,
    var(--color-bg-surface, #f3f3fe) 50%,
    var(--color-bg-secondary, #faf8ff) 75%
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
  background: var(--color-bg-secondary, #f6f7fb);
}

// ---------- Swiper ----------
.goods-detail__swiper-wrap {
  position: relative;
  width: 100%;
  background: linear-gradient(180deg, #ffffff 0%, #f4f6ff 100%);
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
  position: relative;
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
  background: transparent;
  object-fit: cover;
}

.goods-detail__swiper-video {
  height: 100%;
  background: #000000;
}

.goods-detail__media-tabs {
  position: absolute;
  left: $mb-spacing-lg;
  bottom: 52rpx;
  z-index: 8;
  display: flex;
  align-items: center;
  gap: 6rpx;
  padding: 6rpx;
  border-radius: $mb-radius-full;
  background: rgba(0, 0, 0, 0.38);
  backdrop-filter: blur(12rpx);
  -webkit-backdrop-filter: blur(12rpx);
}

.goods-detail__swiper-wrap--video .goods-detail__media-tabs,
.goods-detail__swiper-wrap--video .goods-detail__counter {
  bottom: 112rpx;
}

.goods-detail__media-tab {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 92rpx;
  height: 42rpx;
  padding: 0 16rpx;
  border-radius: $mb-radius-full;
}

.goods-detail__media-tab--active {
  background: rgba(255, 255, 255, 0.96);
}

.goods-detail__media-tab-text {
  font-size: $mb-font-xs;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.88);
  line-height: 1;
}

.goods-detail__media-tab--active .goods-detail__media-tab-text {
  color: var(--color-text-title, #191b23);
}

.goods-detail__counter {
  position: absolute;
  right: $mb-spacing-lg;
  bottom: 52rpx;
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
  position: relative;
  z-index: 2;
  padding: $mb-spacing-lg $mb-spacing-page $mb-spacing-sm;
  background: var(--color-bg, #ffffff);
  margin: -16rpx 0 0;
  border-radius: 40rpx 40rpx 0 0;
  border: 0;
  border-bottom: 0;
  box-shadow: 0 -18rpx 40rpx rgba(15, 23, 42, 0.08);
  backdrop-filter: blur(18rpx);
  -webkit-backdrop-filter: blur(18rpx);
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
  color: var(--color-price, #ff5a1f);
  line-height: 1;
}

.goods-detail__price-value {
  font-size: 46rpx;
  font-weight: 800;
  color: var(--color-price, #ff5a1f);
  line-height: 1;
  letter-spacing: 0;
}

.goods-detail__original-price {
  margin-left: $mb-spacing-sm;
  align-self: center;
}

.goods-detail__original-price-text {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  text-decoration: line-through;
}

.goods-detail__benefits {
  margin-top: 14rpx;
}

// ---------- Title section ----------
.goods-detail__title-section {
  padding: 0 $mb-spacing-page $mb-spacing-md;
  background: var(--color-bg, #ffffff);
  margin: 0;
  border-left: 0;
  border-right: 0;
}

.goods-detail__name {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
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
  color: var(--color-text-secondary, #434654);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.goods-detail__metrics {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0;
  margin-top: $mb-spacing-md;
  overflow: hidden;
  border-radius: 24rpx;
  background: var(--color-bg-surface, #f3f3fe);
}

.goods-detail__metric {
  min-width: 0;
  padding: 18rpx $mb-spacing-xs;
  text-align: center;
}

.goods-detail__metric-value {
  display: block;
  font-size: $mb-font-md;
  font-weight: 800;
  color: var(--color-text-title, #191b23);
  line-height: 1.2;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-detail__metric-label {
  display: block;
  margin-top: 6rpx;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.2;
}

// ---------- Guarantees ----------
.goods-detail__guarantees {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-sm;
  padding: $mb-spacing-md $mb-spacing-page;
  background: var(--color-bg, #ffffff);
  margin: 0;
  border-top: 0;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 0;
}

.goods-detail__guarantee {
  display: flex;
  align-items: center;
  gap: 8rpx;
  padding: 0;
  background: transparent;
  border-radius: 0;
}

.goods-detail__guarantee-dot {
  width: 10rpx;
  height: 10rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary, #0d50d5);
}

.goods-detail__guarantee-title {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
}

// ---------- Divider ----------
.goods-detail__divider {
  height: $mb-spacing-sm;
  background: var(--color-bg-secondary, #f6f7fb);
}

// ---------- Spec selector ----------
.goods-detail__spec-section {
  padding: $mb-spacing-lg $mb-spacing-page;
  background: var(--color-bg, #ffffff);

  &:active {
    background: var(--color-bg-secondary, #f6f7fb);
  }
}

.goods-detail__spec-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-md;
}

.goods-detail__spec-title {
  flex-shrink: 0;
  font-size: $mb-font-lg;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  line-height: 1.3;
}

.goods-detail__spec-right {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  min-width: 0;
  gap: $mb-spacing-xs;
}

.goods-detail__spec-current {
  min-width: 0;
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-detail__spec-preview-scroll {
  width: 100%;
  margin-top: $mb-spacing-md;
  white-space: nowrap;
}

.goods-detail__spec-preview {
  display: inline-flex;
  gap: $mb-spacing-sm;
  min-width: 100%;
  padding-bottom: 2rpx;
}

.goods-detail__spec-preview-item {
  position: relative;
  flex-shrink: 0;
  width: 160rpx;
  height: 120rpx;
  overflow: hidden;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
  background: var(--color-bg, #ffffff);
  box-sizing: border-box;
}

.goods-detail__spec-preview-item--active {
  border: 3rpx solid var(--color-primary, #0d50d5);
}

.goods-detail__spec-preview-img {
  width: 100%;
  height: 100%;
  display: block;
}

.goods-detail__spec-preview-text {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  padding: 0 10rpx;
  font-size: 20rpx;
  color: var(--color-text-secondary, #434654);
  line-height: 1.2;
  text-align: center;
  box-sizing: border-box;
}

.goods-detail__spec-preview-text--overlay {
  position: absolute;
  left: 6rpx;
  right: 6rpx;
  bottom: 6rpx;
  width: auto;
  min-height: 0;
  height: 32rpx;
  padding: 4rpx 8rpx;
  border-radius: $mb-radius-full;
  background: rgba(13, 80, 213, 0.88);
  color: #ffffff;
  font-size: 18rpx;
  font-weight: 700;
  line-height: 24rpx;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  word-break: normal;
  display: block;
}

// ---------- Cell ----------
.goods-detail__cell {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $mb-spacing-lg $mb-spacing-page;
  background: var(--color-bg, #ffffff);
  border: 0;
  border-radius: 0;
  margin: 0;

  &:active {
    background: var(--color-bg-secondary, #faf8ff);
  }
}

.goods-detail__cell-label {
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-text, #191b23);
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
  color: var(--color-text-secondary, #434654);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-detail__cell-arrow {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
  flex-shrink: 0;
}

// ---------- Review section ----------
.goods-detail__review-section {
  background: var(--color-bg, #ffffff);
  padding: $mb-spacing-sm $mb-spacing-page $mb-spacing-md;
  margin: 0;
  border: 0;
  border-radius: 0;
}

.goods-detail__review-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 0 0 $mb-spacing-sm;
  padding: 0;
}

.goods-detail__review-title-wrap {
  display: flex;
  align-items: baseline;
  gap: $mb-spacing-xs;
}

.goods-detail__review-title {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  letter-spacing: 0;
}

.goods-detail__review-total {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  font-weight: 500;
}

.goods-detail__review-view-all {
  display: flex;
  align-items: center;
  gap: 4rpx;

  &:active {
    opacity: 0.6;
  }
}

.goods-detail__review-view-all-text {
  font-size: $mb-font-sm;
  color: var(--color-primary, #0d50d5);
  font-weight: 600;
}

.goods-detail__review-view-all-arrow {
  font-size: 22rpx;
  color: var(--color-primary, #0d50d5);
  line-height: 1;
}

.goods-detail__review-loading,
.goods-detail__review-empty {
  padding: $mb-spacing-lg 0;
  text-align: center;
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-lg;
}

.goods-detail__review-empty-text {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

.goods-detail__review-item {
  padding: $mb-spacing-md;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
  margin-bottom: $mb-spacing-sm;
  box-shadow: none;
}

.goods-detail__review-user-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-xs;
}

.goods-detail__review-avatar {
  width: 72rpx;
  height: 72rpx;
  border-radius: $mb-radius-full;
  background: linear-gradient(135deg, var(--color-primary, #0d50d5) 0%, var(--color-primary-light, #386bef) 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}

.goods-detail__review-avatar-img {
  width: 100%;
  height: 100%;
  display: block;
}

.goods-detail__review-avatar-text {
  font-size: $mb-font-md;
  font-weight: 700;
  color: #ffffff;
  line-height: 1;
}

.goods-detail__review-user-main {
  flex: 1;
  min-width: 0;
}

.goods-detail__review-user-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-sm;
}

.goods-detail__review-user-name {
  font-size: $mb-font-sm;
  font-weight: 700;
  color: var(--color-text, #191b23);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  flex: 1;
  min-width: 0;
}

.goods-detail__review-star-row {
  display: flex;
  gap: 2rpx;
  flex-shrink: 0;
}

.goods-detail__review-star {
  font-size: 22rpx;
  color: var(--color-border, #c3c5d7);
  line-height: 1;
}

.goods-detail__review-star--active {
  color: var(--color-warning, #f0ad4e);
}

.goods-detail__review-meta {
  display: block;
  margin-top: 4rpx;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.4;
}

.goods-detail__review-content {
  display: block;
  margin-top: $mb-spacing-sm;
  font-size: $mb-font-sm;
  color: var(--color-text, #191b23);
  line-height: 1.62;
  word-break: break-word;
}

.goods-detail__review-images {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-xs;
  margin-top: $mb-spacing-sm;
}

.goods-detail__review-image {
  width: 148rpx;
  height: 148rpx;
  border-radius: $mb-radius-md;
  background: var(--color-bg-secondary, #faf8ff);
}

.goods-detail__review-append {
  margin-top: $mb-spacing-sm;
  padding: $mb-spacing-sm;
  border-radius: $mb-radius-md;
  background: var(--color-bg-secondary, #faf8ff);
}

.goods-detail__review-append-header {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
}

.goods-detail__review-append-label {
  font-size: $mb-font-sm;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  line-height: 1.4;
}

.goods-detail__review-append-time {
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.4;
}

.goods-detail__review-append-content {
  display: block;
  margin-top: $mb-spacing-sm;
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
  line-height: 1.6;
  word-break: break-word;
}

.goods-detail__review-reply {
  margin-top: $mb-spacing-sm;
  padding: $mb-spacing-sm;
  border-radius: $mb-radius-md;
  background: var(--color-bg-surface, #f3f3fe);
}

.goods-detail__review-reply-prefix {
  font-size: $mb-font-sm;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
  line-height: 1.5;
}

.goods-detail__review-reply-text {
  font-size: $mb-font-sm;
  color: var(--color-primary, #0d50d5);
  line-height: 1.5;
}

// ---------- Content section ----------
.goods-detail__content-section {
  background: var(--color-bg, #ffffff);
  padding: 0 $mb-spacing-page $mb-spacing-xl;
  margin: 0;
  border: 0;
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 0;
  width: auto;
  max-width: 100vw;
  box-sizing: border-box;
  overflow: visible;
}

.goods-detail__content-card {
  padding: $mb-spacing-lg 0 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  overflow: visible;
}

.goods-detail__content-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 52rpx;
  margin-bottom: $mb-spacing-sm;
  padding-bottom: $mb-spacing-md;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
}

.goods-detail__content-heading {
  display: flex;
  align-items: center;
  min-width: 0;
  gap: $mb-spacing-sm;
}

.goods-detail__content-title {
  min-width: 0;
  font-size: $mb-font-xl;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  line-height: 1.3;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-detail__content-spec {
  max-width: 320rpx;
  padding: 6rpx 14rpx;
  border-radius: $mb-radius-full;
  background: var(--color-bg-surface, #f3f3fe);
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-xs;
  line-height: 1.3;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-detail__content-body {
  padding-top: 2rpx;
}

.goods-detail__rich-text {
  display: block;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  color: var(--color-text, #191b23);
  overflow: hidden;
  word-break: break-word;
}

.goods-detail__content-empty {
  padding: $mb-spacing-xl 0;
  text-align: center;
}

.goods-detail__content-empty-text {
  font-size: $mb-font-md;
  color: var(--color-text-tertiary, #737686);
}

// ---------- Bottom spacer ----------
.goods-detail__bottom-spacer {
  height: 220rpx;
}

// ---------- Bottom bar ----------
.goods-detail__bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 998;
  background: var(--color-bg, #ffffff);
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
  padding-bottom: env(safe-area-inset-bottom);
  box-shadow: 0 -4rpx 12rpx rgba(25, 27, 35, 0.04);
}

.goods-detail__bar-inner {
  display: flex;
  align-items: center;
  height: 120rpx;
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
  width: 44rpx;
  height: 44rpx;
  background-image: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0OCIgaGVpZ2h0PSI0OCIgdmlld0JveD0iMCAtOTYwIDk2MCA5NjAiPjxwYXRoIGZpbGw9IiM0MzQ2NTQiIGQ9Ik00ODItNDB2LTYwaDI5OHYtNTRINjMydi0yOTZoMTQ4di02OHEwLTEyNC04Ny0yMTMuNVQ0ODItODIxcS0xMjQgMC0yMTMgODkuNVQxODAtNTE4djY4aDE0OHYyOTZIMTgwcS0yNCAwLTQyLTE4dC0xOC00MnYtMzA0cTAtNzQuNzMgMjguNS0xNDAuODhRMTc3LTcyNS4wMyAyMjYtNzc0LjUxIDI3NS04MjQgMzQxLjItODUyLjVxNjYuMjEtMjguNSAxNDEtMjguNSA3NC44IDAgMTQwLjMgMjguNVE2ODgtODI0IDczNi4wNS03NzQuNTFxNDguMDUgNDkuNDggNzYgMTE1LjYzUTg0MC01OTIuNzMgODQwLTUxOHY0MThxMCAyNC0xOCA0MnQtNDIgMThINDgyWk0xODAtMjE0aDg4di0xNzZoLTg4djE3NlptNTEyIDBoODh2LTE3NmgtODh2MTc2Wk0xODAtMzkwaDg4LTg4Wm01MTIgMGg4OC04OFoiLz48L3N2Zz4=");
  background-size: 100% 100%;
  background-repeat: no-repeat;
}

.goods-detail__icon-cart {
  width: 44rpx;
  height: 44rpx;
  background-image: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0OCIgaGVpZ2h0PSI0OCIgdmlld0JveD0iMCAtOTYwIDk2MCA5NjAiPjxwYXRoIGZpbGw9IiM0MzQ2NTQiIGQ9Ik0yMzYtMTAyLjIxcS0yMS0yMS4yMS0yMS01MVQyMzYuMjEtMjA0cTIxLjIxLTIxIDUxLTIxVDMzOC0yMDMuNzlxMjEgMjEuMjEgMjEgNTFUMzM3Ljc5LTEwMnEtMjEuMjEgMjEtNTEgMjFUMjM2LTEwMi4yMVptNDAwIDBxLTIxLTIxLjIxLTIxLTUxVDYzNi4yMS0yMDRxMjEuMjEtMjEgNTEtMjFUNzM4LTIwMy43OXEyMSAyMS4yMSAyMSA1MVQ3MzcuNzktMTAycS0yMS4yMSAyMS01MSAyMVQ2MzYtMTAyLjIxWk0yMzUtNzQxbDExMCAyMjhoMjg4bDEyNS0yMjhIMjM1Wm0tMzAtNjBoNTg5LjA3cTIyLjk3IDAgMzQuOTUgMjEgMTEuOTggMjEtLjAyIDQyTDY5NC00OTVxLTExIDE5LTI4LjU2IDMwLjVUNjI3LTQ1M0gzMjRsLTU2IDEwNGg0OTF2NjBIMjc3cS00MiAwLTYwLjUtMjh0LjUtNjNsNjQtMTE4LTE1Mi0zMjJINTF2LTYwaDExN2wzNyA3OVptMTQwIDI4OGgyODgtMjg4WiIvPjwvc3ZnPg==");
  background-size: 100% 100%;
  background-repeat: no-repeat;
}

.goods-detail__bar-icon-label {
  font-size: 20rpx;
  color: var(--color-text-secondary, #434654);
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
  background: var(--color-error, #ba1a1a);
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
  height: 88rpx;
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
  background: var(--color-bg, #ffffff);
  border: 2rpx solid var(--color-primary, #0d50d5);
}

.goods-detail__bar-btn--buy {
  background: linear-gradient(135deg, var(--color-primary, #0d50d5) 0%, var(--color-primary-light, #386bef) 100%);
  box-shadow: 0 6rpx 16rpx rgba(13, 80, 213, 0.2);
}

.goods-detail__bar-btn-text {
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-primary, #0d50d5);
}

.goods-detail__bar-btn-text--light {
  color: var(--color-text-inverse, #ffffff);
}
</style>
