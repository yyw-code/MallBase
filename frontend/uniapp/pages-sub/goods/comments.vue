<template>
  <view class="goods-comments">
    <mb-navbar title="全部评价" />

    <view v-if="goodsTitle" class="goods-comments__goods-row">
      <text class="goods-comments__goods-name">{{ goodsTitle }}</text>
    </view>

    <view class="goods-comments__summary">
      <text class="goods-comments__summary-text">
        共 {{ total }} 条评价
      </text>
    </view>

    <scroll-view
      scroll-y
      class="goods-comments__scroll"
      :refresher-enabled="true"
      :refresher-triggered="refreshing"
      @refresherrefresh="onRefresh"
      @scrolltolower="onLoadMore"
    >
      <view v-if="loading && list.length === 0" class="goods-comments__state">
        <text class="goods-comments__state-text">加载中…</text>
      </view>

      <mb-empty-state
        v-else-if="!loading && list.length === 0"
        icon=""
        text="暂无评价"
        action-text="返回详情"
        @action="goBack"
      />

      <block v-else>
        <view
          v-for="review in list"
          :key="review.id"
          class="goods-comments__item"
        >
          <view class="goods-comments__user-row">
            <view class="goods-comments__avatar">
              <image
                v-if="review.avatarUrl"
                class="goods-comments__avatar-img"
                :src="review.avatarUrl"
                mode="aspectFill"
                @error="onAvatarError(review)"
              />
              <text v-else class="goods-comments__avatar-text">{{ review.userInitial }}</text>
            </view>
            <view class="goods-comments__user-main">
              <view class="goods-comments__user-line">
                <text class="goods-comments__user-name">{{ review.userName }}</text>
                <view class="goods-comments__star-row">
                  <text
                    v-for="i in 5"
                    :key="i"
                    class="goods-comments__star"
                    :class="{ 'goods-comments__star--active': i <= review.rating }"
                  >★</text>
                </view>
              </view>
              <text class="goods-comments__meta">{{ review.metaText }}</text>
            </view>
          </view>

          <text class="goods-comments__content">{{ review.content }}</text>

          <view v-if="review.images.length > 0" class="goods-comments__images">
            <image
              v-for="(img, idx) in review.images"
              :key="img + idx"
              class="goods-comments__img"
              :src="img"
              mode="aspectFill"
              @tap="previewImages(review.images, idx)"
            />
          </view>

          <view
            v-if="review.appendContent || review.appendImages.length > 0"
            class="goods-comments__append"
          >
            <view class="goods-comments__append-header">
              <text class="goods-comments__append-label">追评</text>
              <text v-if="review.appendTimeText" class="goods-comments__append-time">
                {{ review.appendTimeText }}
              </text>
            </view>
            <text v-if="review.appendContent" class="goods-comments__append-content">
              {{ review.appendContent }}
            </text>
            <view v-if="review.appendImages.length > 0" class="goods-comments__images">
              <image
                v-for="(img, idx) in review.appendImages"
                :key="img + idx"
                class="goods-comments__img"
                :src="img"
                mode="aspectFill"
                @tap="previewImages(review.appendImages, idx)"
              />
            </view>
          </view>

          <view v-if="review.replyContent" class="goods-comments__reply">
            <text class="goods-comments__reply-prefix">商家回复：</text>
            <text class="goods-comments__reply-text">{{ review.replyContent }}</text>
          </view>
        </view>

        <view v-if="loadingMore" class="goods-comments__state">
          <text class="goods-comments__state-text">加载中…</text>
        </view>
        <view v-else-if="!hasMore" class="goods-comments__state">
          <text class="goods-comments__state-text">没有更多评价</text>
        </view>
      </block>
    </scroll-view>
  </view>
</template>

<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getReviewList } from '@/api/goods/review'

const PAGE_SIZE = 10

const goodsId = ref(0)
const goodsTitle = ref('')
const list = ref([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const refreshing = ref(false)

const hasMore = computed(() => list.value.length < total.value)

onLoad((options = {}) => {
  goodsId.value = Number(options.goods_id || options.id || 0)
  goodsTitle.value = options.title ? decodeURIComponent(options.title) : ''
  if (goodsId.value > 0) {
    fetchPage(1, { replace: true })
  }
})

async function fetchPage(targetPage, { replace = false } = {}) {
  if (goodsId.value <= 0) return
  if (replace) {
    loading.value = true
  } else {
    loadingMore.value = true
  }

  try {
    const res = await getReviewList(goodsId.value, {
      page: targetPage,
      limit: PAGE_SIZE,
    })
    const incoming = Array.isArray(res?.list) ? res.list.map(normalizeReview) : []
    total.value = Number(res?.total || 0)
    list.value = replace ? incoming : [...list.value, ...incoming]
    page.value = targetPage
  } catch {
    if (replace) {
      list.value = []
      total.value = 0
    }
  } finally {
    loading.value = false
    loadingMore.value = false
    refreshing.value = false
  }
}

function onRefresh() {
  refreshing.value = true
  fetchPage(1, { replace: true })
}

function onLoadMore() {
  if (loading.value || loadingMore.value || !hasMore.value) return
  fetchPage(page.value + 1)
}

function onAvatarError(review) {
  if (review) review.avatarUrl = ''
}

function previewImages(images, current) {
  uni.previewImage({ urls: images, current })
}

function goBack() {
  uni.navigateBack({ delta: 1, fail: () => uni.switchTab({ url: '/pages/index/index' }) })
}

function normalizeReview(review) {
  const userName = Number(review.is_anonymous || 0) === 1
    ? '匿名用户'
    : (review.user_nickname || review.nickname || '用户')
  const avatarUrl = Number(review.is_anonymous || 0) === 1
    ? ''
    : (review.user_avatar_full_url || review.avatar_full_url || review.avatar || '')

  const createTimeText = formatTime(review.create_time)
  const skuSpecText = review.sku_spec_text || review.spec_values_text || review.spec_values || ''

  return {
    id: review.id,
    userName,
    userInitial: userName.slice(0, 1),
    avatarUrl,
    rating: Math.max(1, Math.min(5, Number(review.rating || 5))),
    content: review.content || '',
    images: normalizeImages(review.images_full_urls || review.images),
    appendContent: review.append_content || '',
    appendImages: normalizeImages(review.append_images_full_urls || review.append_images),
    appendTimeText: formatTime(review.append_time),
    replyContent: review.reply_content || '',
    createTimeText,
    skuSpecText,
    metaText: skuSpecText ? `${createTimeText} · ${skuSpecText}` : createTimeText,
  }
}

function normalizeImages(images) {
  if (Array.isArray(images)) return images.filter(Boolean)
  if (!images) return []
  try {
    const parsed = JSON.parse(images)
    return Array.isArray(parsed) ? parsed.filter(Boolean) : []
  } catch {
    return String(images).split(',').map((s) => s.trim()).filter(Boolean)
  }
}

function formatTime(input) {
  if (!input) return ''
  const str = String(input)
  // 期望 "2025-04-12 09:24:11" 或 ISO 字符串
  return str.split(' ')[0] || str.split('T')[0] || str
}
</script>

<style lang="scss" scoped>
@import '@/uni.scss';

.goods-comments {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: $mb-color-bg-secondary;
}

.goods-comments__goods-row {
  padding: $mb-spacing-md $mb-spacing-page;
  background: $mb-color-bg;
}

.goods-comments__goods-name {
  font-size: $mb-font-md;
  color: $mb-color-text;
  font-weight: 600;
  line-height: 1.4;
}

.goods-comments__summary {
  padding: $mb-spacing-sm $mb-spacing-page;
}

.goods-comments__summary-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

.goods-comments__scroll {
  flex: 1;
  padding-bottom: env(safe-area-inset-bottom);
}

.goods-comments__state {
  padding: 48rpx 0;
  display: flex;
  justify-content: center;
}

.goods-comments__state-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

.goods-comments__item {
  margin: $mb-spacing-sm $mb-spacing-page;
  padding: $mb-spacing-lg;
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
}

.goods-comments__user-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
}

.goods-comments__avatar {
  width: 80rpx;
  height: 80rpx;
  border-radius: 50%;
  background: $mb-color-primary;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
}

.goods-comments__avatar-img {
  width: 100%;
  height: 100%;
}

.goods-comments__avatar-text {
  color: #ffffff;
  font-size: 28rpx;
  font-weight: 600;
}

.goods-comments__user-main {
  flex: 1;
  min-width: 0;
}

.goods-comments__user-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-md;
}

.goods-comments__user-name {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text;
}

.goods-comments__star-row {
  display: flex;
  gap: 4rpx;
}

.goods-comments__star {
  font-size: 22rpx;
  color: $mb-color-border-light;
  line-height: 1;
}

.goods-comments__star--active {
  color: $mb-color-star;
}

.goods-comments__meta {
  display: block;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: $mb-color-text-tertiary;
}

.goods-comments__content {
  display: block;
  margin-top: $mb-spacing-md;
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1.5;
}

.goods-comments__images {
  margin-top: $mb-spacing-md;
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
}

.goods-comments__img {
  width: 168rpx;
  height: 168rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
}

.goods-comments__append {
  margin-top: $mb-spacing-md;
  padding: $mb-spacing-md;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
}

.goods-comments__append-header {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
}

.goods-comments__append-label {
  font-size: $mb-font-sm;
  font-weight: 700;
  color: $mb-color-text-title;
}

.goods-comments__append-time {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

.goods-comments__append-content {
  display: block;
  margin-top: $mb-spacing-sm;
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
  line-height: 1.6;
  word-break: break-word;
}

.goods-comments__reply {
  margin-top: $mb-spacing-md;
  padding: $mb-spacing-md;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-surface;
}

.goods-comments__reply-prefix {
  font-size: $mb-font-sm;
  font-weight: 700;
  color: $mb-color-primary;
}

.goods-comments__reply-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
  line-height: 1.5;
}
</style>
