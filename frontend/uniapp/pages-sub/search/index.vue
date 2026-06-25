<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, computed, onMounted, nextTick } from 'vue'
import { useAppStore } from '@/store/app'
import { getGoodsRecommend } from '@/api/goods/goods'
import { getHotSearch, recordSearch } from '@/api/search/search'
import { getPlatform } from '@/utils/platform'
const decorateStore = useDecorateStore()

const STORAGE_KEY = 'search_history'
const MAX_HISTORY = 20

const appStore = useAppStore()
const brandName = computed(() => appStore.siteConfig?.site_name || 'MallBase')

const DEFAULT_HOT_TOPICS = [
  { title: '高级成衣系列', subtitle: '探索极简主义与舒适的完美平衡' },
  { title: '基础松软面料', subtitle: '柔软触感，四季百搭' },
  { title: '手工匠心配饰', subtitle: '精工细作，彰显品位' },
]

const quickCategories = [
  { label: '新品上架', icon: 'new' },
  { label: '国货精选', icon: 'star' },
  { label: '全部分类', icon: 'grid' },
]

const keyword = ref('')
const historyList = ref([])
const featuredList = ref([])
const hotTopics = ref([...DEFAULT_HOT_TOPICS])

onMounted(() => {
  loadHistory()
  fetchHotSearch()
  fetchFeatured()
  nextTick(() => {})
})

function loadHistory() {
  try {
    const raw = uni.getStorageSync(STORAGE_KEY)
    historyList.value = Array.isArray(raw) ? raw : []
  } catch {
    historyList.value = []
  }
}

function saveHistory(word) {
  const trimmed = word.trim()
  if (!trimmed) return
  const filtered = historyList.value.filter((item) => item !== trimmed)
  const updated = [trimmed, ...filtered].slice(0, MAX_HISTORY)
  historyList.value = updated
  uni.setStorageSync(STORAGE_KEY, updated)
}

function clearHistory() {
  uni.showModal({
    title: '提示',
    content: '确定清除搜索历史吗？',
    success(res) {
      if (!res.confirm) return
      historyList.value = []
      uni.removeStorageSync(STORAGE_KEY)
    },
  })
}

function doSearch(word) {
  const trimmed = (word || keyword.value).trim()
  if (!trimmed) {
    uni.showToast({ title: '请输入搜索内容', icon: 'none' })
    return
  }
  saveHistory(trimmed)
  recordSearch(trimmed, getPlatform()).catch(() => {})
  uni.navigateTo({
    url: `/pages-sub/goods/list?keyword=${encodeURIComponent(trimmed)}`,
  })
}

function onConfirm() {
  doSearch(keyword.value)
}

function onTagTap(tag) {
  keyword.value = tag
  doSearch(tag)
}

function goBack() {
  uni.navigateBack({ fail: () => {} })
}

async function fetchFeatured() {
  try {
    const data = await getGoodsRecommend(6)
    featuredList.value = Array.isArray(data) ? data : []
  } catch {
    featuredList.value = []
  }
}

async function fetchHotSearch() {
  try {
    const data = await getHotSearch(6)
    const list = Array.isArray(data?.list) ? data.list : []
    if (list.length === 0) {
      hotTopics.value = [...DEFAULT_HOT_TOPICS]
      return
    }
    hotTopics.value = list.map((item, index) => ({
      title: item.keyword,
      subtitle: `近 7 天热度 ${item.search_count || index + 1}`,
    }))
  } catch {
    hotTopics.value = [...DEFAULT_HOT_TOPICS]
  }
}

function getFirstImage(item) {
  if (item.cover) return item.cover
  if (Array.isArray(item.images) && item.images.length > 0) {
    const first = item.images[0]
    if (typeof first === 'string') return first
    return first.full_url || first.url || ''
  }
  return ''
}

function goGoodsDetail(id) {
  uni.navigateTo({ url: `/pages-sub/goods/detail?id=${id}` })
}

function goGoodsList(query = '') {
  uni.navigateTo({ url: `/pages-sub/goods/list${query}` })
}

/** Primary hero item (first featured) */
const heroItem = computed(() => featuredList.value[0] || null)

/** Grid items (remaining featured items, up to 4) */
const gridItems = computed(() => featuredList.value.slice(1, 5))
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="搜索" />

    <!-- ========== Search Header ========== -->
    <view class="search-head">
      <view class="search-head__brand">
        <view class="search-head__brand-mark" />
        <text class="search-head__brand-text">{{ brandName }}</text>
      </view>
      <view class="search-head__row">
        <view class="search-head__input-wrap">
          <view class="search-head__search-icon" />
          <input
            v-model="keyword"
            class="search-head__input"
            type="text"
            placeholder="搜索商品、品牌"
            :focus="true"
            confirm-type="search"
            @confirm="onConfirm"
          />
        </view>
        <view class="search-head__cancel" @tap="goBack">
          <text class="search-head__cancel-text">取消</text>
        </view>
      </view>
    </view>

    <!-- ========== Scrollable Content ========== -->
    <view class="content">

      <!-- Search History -->
      <view v-if="historyList.length > 0" class="section">
        <view class="section__header">
          <text class="section__title">搜索历史</text>
          <view class="section__action" @tap="clearHistory">
            <view class="section__trash-icon" />
          </view>
        </view>
        <view class="tag-flow">
          <view
            v-for="(item, idx) in historyList"
            :key="idx"
            class="tag"
            @tap="onTagTap(item)"
          >
            <text class="tag__text">{{ item }}</text>
          </view>
        </view>
      </view>

      <!-- Hot Discovery — Bento Layout -->
      <view class="section">
        <view class="section__header">
          <text class="section__title">热门发现</text>
        </view>

        <!-- Hero Card -->
        <view class="hero-card" @tap="heroItem ? goGoodsDetail(heroItem.id) : goGoodsList('?tag=recommend')">
          <image
            v-if="heroItem"
            class="hero-card__img"
            :src="getFirstImage(heroItem)"
            mode="aspectFill"
            lazy-load
          />
          <view v-else class="hero-card__placeholder" />
          <view class="hero-card__overlay">
            <text class="hero-card__label">{{ heroItem ? heroItem.name : hotTopics[0].title }}</text>
          </view>
        </view>

        <!-- Topic List -->
        <view class="topic-list">
          <view
            v-for="(topic, ti) in hotTopics.slice(0, 2)"
            :key="ti"
            class="topic-item"
            @tap="onTagTap(topic.title)"
          >
            <text class="topic-item__num">{{ String(ti + 1).padStart(2, '0') }}</text>
            <view class="topic-item__body">
              <text class="topic-item__title">{{ topic.title }}</text>
              <text class="topic-item__sub">{{ topic.subtitle }}</text>
            </view>
          </view>
        </view>

        <!-- Image Grid (2x2 bento) -->
        <view v-if="gridItems.length > 0" class="bento-grid">
          <view
            v-for="(item, gi) in gridItems"
            :key="gi"
            class="bento-cell"
            :class="{ 'bento-cell--tall': gi === 0 }"
            @tap="goGoodsDetail(item.id)"
          >
            <image
              class="bento-cell__img"
              :src="getFirstImage(item)"
              mode="aspectFill"
              lazy-load
            />
          </view>
        </view>
      </view>

      <!-- Curated Section -->
      <view v-if="featuredList.length > 3" class="section">
        <view class="section__header">
          <text class="section__title">精选专题</text>
        </view>
        <view class="curated-row">
          <view
            v-for="(item, ci) in featuredList.slice(3, 6)"
            :key="ci"
            class="curated-card"
            @tap="goGoodsDetail(item.id)"
          >
            <image
              class="curated-card__img"
              :src="getFirstImage(item)"
              mode="aspectFill"
              lazy-load
            />
            <text class="curated-card__name">{{ item.name }}</text>
          </view>
        </view>
      </view>

      <!-- Quick Category Shortcuts -->
      <view class="quick-cats">
        <view
          v-for="cat in quickCategories"
          :key="cat.label"
          class="quick-cat"
          @tap="goGoodsList('?keyword=' + cat.label)"
        >
          <view class="quick-cat__circle">
            <view class="quick-cat__icon" :class="'quick-cat__icon--' + cat.icon" />
          </view>
          <text class="quick-cat__label">{{ cat.label }}</text>
        </view>
      </view>

      <view class="bottom-spacer" />
    </view>
  </view>
</template>

<style lang="scss" scoped>
/* ===========================
   Page
   =========================== */
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

/* ===========================
   Top Bar (Search)
   =========================== */
.search-head {
  padding: $mb-spacing-sm $mb-spacing-page $mb-spacing-lg;
  background: var(--color-bg-secondary, #faf8ff);
}

.search-head__brand {
  display: flex;
  align-items: center;
  gap: $mb-spacing-xs;
  margin-bottom: $mb-spacing-sm;
}

.search-head__brand-mark {
  width: 24rpx;
  height: 24rpx;
  border-radius: 6rpx;
  border: 4rpx solid var(--color-primary, #0d50d5);
  position: relative;

  &::after {
    content: '';
    position: absolute;
    left: 6rpx;
    top: 6rpx;
    width: 4rpx;
    height: 4rpx;
    border-radius: $mb-radius-full;
    background: var(--color-primary, #0d50d5);
    box-shadow: 9rpx 0 0 var(--color-primary, #0d50d5), 0 9rpx 0 var(--color-primary, #0d50d5), 9rpx 9rpx 0 var(--color-primary, #0d50d5);
  }
}

.search-head__brand-text {
  font-size: $mb-font-md;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.search-head__row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
}

.search-head__input-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  height: 72rpx;
  padding: 0 $mb-spacing-md;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-sm;
  min-width: 0;
}

.search-head__search-icon {
  width: 24rpx;
  height: 24rpx;
  border: 3rpx solid var(--color-text-tertiary, #737686);
  border-radius: 50%;
  position: relative;
  margin-right: $mb-spacing-sm;
  flex-shrink: 0;

  &::after {
    content: '';
    position: absolute;
    width: 10rpx;
    height: 3rpx;
    border-radius: $mb-radius-full;
    background: var(--color-text-tertiary, #737686);
    right: -7rpx;
    bottom: -3rpx;
    transform: rotate(45deg);
  }
}

.search-head__input {
  flex: 1;
  font-size: $mb-font-md;
  color: var(--color-text, #191b23);
  height: 72rpx;
}

.search-head__cancel {
  flex-shrink: 0;
  padding: $mb-spacing-xs 0 $mb-spacing-xs $mb-spacing-xs;
}

.search-head__cancel-text {
  font-size: $mb-font-md;
  color: var(--color-primary, #0d50d5);
  font-weight: 600;
}

/* ===========================
   Content
   =========================== */
.content {
  padding: 0 $mb-spacing-page;
}

/* ===========================
   Section
   =========================== */
.section {
  margin-top: $mb-spacing-xl;
}

.section__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: $mb-spacing-md;
}

.section__title {
  font-size: 34rpx;
  font-weight: 700;
  color: var(--color-text, #191b23);
  letter-spacing: 1rpx;
}

.section__action {
  padding: $mb-spacing-xs;
}

.section__trash-icon {
  position: relative;
  width: 32rpx;
  height: 34rpx;
  opacity: 0.45;
}

.section__trash-icon::before {
  content: '';
  position: absolute;
  left: 6rpx;
  top: 10rpx;
  width: 20rpx;
  height: 20rpx;
  border: 3rpx solid var(--color-text-tertiary, #737686);
  border-top: 0;
  border-radius: 0 0 5rpx 5rpx;
}

.section__trash-icon::after {
  content: '';
  position: absolute;
  left: 4rpx;
  top: 5rpx;
  width: 24rpx;
  height: 4rpx;
  border-radius: $mb-radius-full;
  background: var(--color-text-tertiary, #737686);
  box-shadow: 8rpx -5rpx 0 -1rpx var(--color-text-tertiary, #737686);
}

/* ===========================
   Tag Flow (History)
   =========================== */
.tag-flow {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
}

.tag {
  padding: 14rpx 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-sm;
  transition: opacity 0.15s;

  &:active {
    opacity: 0.6;
  }
}

.tag__text {
  font-size: 26rpx;
  color: var(--color-text-secondary, #434654);
  line-height: 1.4;
  white-space: nowrap;
}

/* ===========================
   Hero Card
   =========================== */
.hero-card {
  position: relative;
  width: 100%;
  height: 0;
  padding-bottom: 65%;
  border-radius: $mb-radius-lg;
  overflow: hidden;
  margin-bottom: $mb-spacing-md;
  border: 1rpx solid var(--color-divider, #f0f2f5);
}

.hero-card__img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}

.hero-card__placeholder {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(135deg, #d5cdc0 0%, #b8ad9e 100%);
}

.hero-card__overlay {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 48rpx 32rpx 28rpx;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.45), transparent);
}

.hero-card__label {
  font-size: 36rpx;
  font-weight: 700;
  color: var(--color-text-inverse, #ffffff);
  line-height: 1.3;
  letter-spacing: 2rpx;
}

/* ===========================
   Topic List
   =========================== */
.topic-list {
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-md;
  margin-bottom: $mb-spacing-lg;
}

.topic-item {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
  padding: $mb-spacing-sm 0;

  &:active {
    opacity: 0.6;
  }
}

.topic-item__num {
  font-size: 26rpx;
  font-weight: 700;
  color: var(--color-text-tertiary, #737686);
  flex-shrink: 0;
  line-height: 1.6;
  letter-spacing: 1rpx;
}

.topic-item__body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4rpx;
}

.topic-item__title {
  font-size: 28rpx;
  font-weight: 600;
  color: var(--color-text, #191b23);
  line-height: 1.5;
}

.topic-item__sub {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.5;
}

/* ===========================
   Bento Grid (2x2 Mixed)
   =========================== */
.bento-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  grid-template-rows: auto auto;
  gap: $mb-spacing-sm;
}

.bento-cell {
  border-radius: $mb-radius-lg;
  overflow: hidden;
  height: 240rpx;
  background: var(--color-bg-secondary, #faf8ff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
}

.bento-cell--tall {
  grid-row: span 2;
  height: auto;
  min-height: 496rpx;
}

.bento-cell__img {
  width: 100%;
  height: 100%;
}

/* ===========================
   Curated Row
   =========================== */
.curated-row {
  display: flex;
  gap: $mb-spacing-sm;
}

.curated-card {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;

  &:active {
    opacity: 0.7;
  }
}

.curated-card__img {
  width: 100%;
  height: 280rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-bg-secondary, #faf8ff);
}

.curated-card__name {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
  margin-top: $mb-spacing-xs;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: center;
}

/* ===========================
   Quick Category Shortcuts
   =========================== */
.quick-cats {
  display: flex;
  justify-content: center;
  gap: $mb-spacing-xl;
  margin-top: $mb-spacing-xl;
  padding: $mb-spacing-lg 0;
}

.quick-cat {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $mb-spacing-xs;

  &:active {
    opacity: 0.6;
  }
}

.quick-cat__circle {
  width: 96rpx;
  height: 96rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  align-items: center;
  justify-content: center;
}

.quick-cat__icon {
  position: relative;
  width: 34rpx;
  height: 34rpx;
  color: var(--color-primary, #0d50d5);
}

.quick-cat__icon::before,
.quick-cat__icon::after {
  content: '';
  position: absolute;
  box-sizing: border-box;
}

.quick-cat__icon--new::before {
  inset: 3rpx;
  border: 4rpx solid currentColor;
  border-radius: 8rpx;
}

.quick-cat__icon--new::after {
  left: 10rpx;
  top: 15rpx;
  width: 14rpx;
  height: 4rpx;
  border-radius: $mb-radius-full;
  background: currentColor;
  box-shadow: 0 -8rpx 0 currentColor, 0 8rpx 0 currentColor;
}

.quick-cat__icon--star::before {
  left: 5rpx;
  top: 2rpx;
  width: 24rpx;
  height: 24rpx;
  border: 4rpx solid currentColor;
  transform: rotate(45deg);
  border-radius: 4rpx;
}

.quick-cat__icon--star::after {
  left: 14rpx;
  top: 14rpx;
  width: 6rpx;
  height: 6rpx;
  border-radius: $mb-radius-full;
  background: currentColor;
}

.quick-cat__icon--grid::before {
  left: 3rpx;
  top: 3rpx;
  width: 10rpx;
  height: 10rpx;
  background: currentColor;
  border-radius: 3rpx;
  box-shadow: 18rpx 0 0 currentColor, 0 18rpx 0 currentColor, 18rpx 18rpx 0 currentColor;
}

.quick-cat__label {
  font-size: $mb-font-xs;
  color: var(--color-text-secondary, #434654);
  white-space: nowrap;
}

/* ===========================
   Bottom Spacer
   =========================== */
.bottom-spacer {
  height: 120rpx;
}
</style>
