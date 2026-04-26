<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useAppStore } from '@/store/app'
import { getGoodsRecommend } from '@/api/goods/goods'

const STORAGE_KEY = 'search_history'
const MAX_HISTORY = 20

const appStore = useAppStore()
const brandName = computed(() => appStore.siteConfig?.site_name || 'MallBase')

const systemInfo = uni.getSystemInfoSync()
const statusBarHeight = systemInfo.statusBarHeight || 0

const hotSearchTags = ['手机', '耳机', '手表', '笔记本', '平板']

const hotTopics = [
  { title: '高级成衣系列', subtitle: '探索极简主义与舒适的完美平衡' },
  { title: '基础松软面料', subtitle: '柔软触感，四季百搭' },
  { title: '手工匠心配饰', subtitle: '精工细作，彰显品位' },
]

const quickCategories = [
  { label: '午参之旅', icon: '☕' },
  { label: '国货精选', icon: '✨' },
  { label: '全部分类', icon: '☰' },
]

const keyword = ref('')
const historyList = ref([])
const featuredList = ref([])

onMounted(() => {
  loadHistory()
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

function getFirstImage(item) {
  if (item.cover) return item.cover
  if (Array.isArray(item.images) && item.images.length > 0) return item.images[0]
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
  <view class="page">
    <!-- ========== Top Bar ========== -->
    <view class="top-bar" :style="{ paddingTop: statusBarHeight + 'px' }">
      <view class="top-bar__inner">
        <text class="top-bar__brand">{{ brandName }}</text>
        <view class="top-bar__search-wrap">
          <text class="top-bar__search-icon">&#x1F50D;</text>
          <input
            v-model="keyword"
            class="top-bar__input"
            type="text"
            placeholder="搜索商品、品牌"
            :focus="true"
            confirm-type="search"
            @confirm="onConfirm"
          />
        </view>
        <view class="top-bar__cancel" @tap="goBack">
          <text class="top-bar__cancel-text">取消</text>
        </view>
      </view>
    </view>

    <!-- ========== Scrollable Content ========== -->
    <view class="content" :style="{ paddingTop: (statusBarHeight + 56) + 'px' }">

      <!-- Search History -->
      <view v-if="historyList.length > 0" class="section">
        <view class="section__header">
          <text class="section__title">搜索历史</text>
          <view class="section__action" @tap="clearHistory">
            <text class="section__action-icon">&#x1F5D1;</text>
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
            <text class="quick-cat__icon">{{ cat.icon }}</text>
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
  background: $mb-color-bg;
}

/* ===========================
   Top Bar (Search)
   =========================== */
.top-bar {
  position: fixed;
  left: 0;
  right: 0;
  top: 0;
  z-index: 999;
  background: $mb-color-bg;
}

.top-bar__inner {
  display: flex;
  align-items: center;
  padding: $mb-spacing-sm $mb-spacing-page;
  gap: $mb-spacing-sm;
}

.top-bar__brand {
  font-size: 30rpx;
  font-weight: 700;
  color: $mb-color-text-tertiary;
  flex-shrink: 0;
  letter-spacing: 1rpx;
  opacity: 0.5;
}

.top-bar__search-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  height: 72rpx;
  padding: 0 $mb-spacing-md;
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-full;
}

.top-bar__search-icon {
  font-size: 26rpx;
  margin-right: $mb-spacing-sm;
  flex-shrink: 0;
  opacity: 0.5;
}

.top-bar__input {
  flex: 1;
  font-size: $mb-font-md;
  color: $mb-color-text;
  height: 72rpx;
}

.top-bar__cancel {
  flex-shrink: 0;
  padding: $mb-spacing-xs $mb-spacing-xs;
}

.top-bar__cancel-text {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
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
  color: $mb-color-text;
  letter-spacing: 1rpx;
}

.section__action {
  padding: $mb-spacing-xs;
}

.section__action-icon {
  font-size: 32rpx;
  opacity: 0.35;
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
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-full;
  transition: opacity 0.15s;

  &:active {
    opacity: 0.6;
  }
}

.tag__text {
  font-size: 26rpx;
  color: $mb-color-text-secondary;
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
  color: $mb-color-text-inverse;
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
  color: $mb-color-text-tertiary;
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
  color: $mb-color-text;
  line-height: 1.5;
}

.topic-item__sub {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
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
  background: $mb-color-bg-secondary;
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
  background: $mb-color-bg-secondary;
}

.curated-card__name {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
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
  border-radius: 50%;
  background: $mb-color-bg-secondary;
  display: flex;
  align-items: center;
  justify-content: center;
}

.quick-cat__icon {
  font-size: 36rpx;
}

.quick-cat__label {
  font-size: $mb-font-xs;
  color: $mb-color-text-secondary;
  white-space: nowrap;
}

/* ===========================
   Bottom Spacer
   =========================== */
.bottom-spacer {
  height: 120rpx;
}
</style>
