<script setup>
import { computed, onMounted, ref } from 'vue'
import { useDecorateStore } from '@/store/decorate'
import { useAppStore } from '@/store/app'
import { getCategoryList } from '@/api/goods/category'
import { getHotSearch, recordSearch } from '@/api/search/search'
import { getPlatform } from '@/utils/platform'

const decorateStore = useDecorateStore()
const appStore = useAppStore()

const STORAGE_KEY = 'search_history'
const MAX_HISTORY = 20
const HOT_LIMIT = 10
const CATEGORY_LIMIT = 10

const quickFilterCandidates = [
  { label: '新品上架', value: 'is_new', icon: 'new' },
  { label: '热卖商品', value: 'is_hot', icon: 'hot' },
  { label: '推荐商品', value: 'is_recommend', icon: 'recommend' },
  { label: '全部分类', value: 'category', icon: 'category' },
]

const keyword = ref('')
const historyList = ref([])
const hotList = ref([])
const categoryList = ref([])
const hotLoading = ref(false)
const categoryLoading = ref(false)

const siteConfig = computed(() => appStore.siteConfig || {})
const brandName = computed(() =>
  siteConfig.value.client_site_name || siteConfig.value.site_name || 'MallBase',
)
const selectedQuickFilterValues = computed(() =>
  parseConfigArray(
    siteConfig.value.client_search_quick_filters,
    ['is_new', 'is_hot', 'is_recommend', 'category'],
  ),
)
const selectedCategoryIds = computed(() =>
  parseConfigArray(
    siteConfig.value.client_search_category_ids,
    [],
  ).map((id) => Number(id)).filter((id) => Number.isFinite(id)),
)
const visibleQuickFilters = computed(() => {
  const selected = new Set(selectedQuickFilterValues.value)
  return quickFilterCandidates.filter((item) => selected.has(item.value))
})
const showSearchHistory = computed(() =>
  configFlag('client_search_history_enabled', true),
)
const showQuickFilters = computed(() =>
  configFlag('client_search_quick_filter_enabled', true) &&
  visibleQuickFilters.value.length > 0,
)
const showHotSearch = computed(() =>
  configFlag('client_search_hot_enabled', true),
)
const showCommonCategories = computed(() =>
  configFlag('client_search_category_enabled', true),
)

const visibleCategories = computed(() => {
  const list = categoryList.value
  if (selectedCategoryIds.value.length > 0) {
    const map = new Map(list.map((item) => [Number(item.id), item]))
    return selectedCategoryIds.value
      .map((id) => map.get(id))
      .filter((item) => item?.id && item?.name)
  }
  const parentIds = new Set(
    list
      .map((item) => Number(item.pid || 0))
      .filter((id) => id > 0),
  )
  const leafCategories = list.filter((item) => !parentIds.has(Number(item.id)))
  const source = leafCategories.length > 0 ? leafCategories : list
  return source.slice(0, CATEGORY_LIMIT)
})

onMounted(() => {
  loadHistory()
  fetchHotSearch()
  fetchCategories()
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

function clearKeyword() {
  keyword.value = ''
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
    url: `/pages-sub/goods/list?keyword=${trimmed}`,
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
  uni.navigateBack({
    fail() {
      uni.switchTab({ url: '/pages/index/index' })
    },
  })
}

function goFilter(filter) {
  if (filter.value === 'category') {
    goAllCategories()
    return
  }
  uni.navigateTo({
    url: `/pages-sub/goods/list?${filter.value}=1`,
  })
}

function goAllCategories() {
  uni.switchTab({ url: '/pages/category/index' })
}

function goCategory(category) {
  if (!category?.id) return
  uni.navigateTo({
    url: `/pages-sub/goods/list?category_id=${category.id}`,
  })
}

async function fetchHotSearch() {
  hotLoading.value = true
  try {
    const data = await getHotSearch(HOT_LIMIT)
    const list = Array.isArray(data?.list) ? data.list : []
    hotList.value = list
      .filter((item) => String(item.keyword || '').trim() !== '')
      .map((item) => ({
        keyword: String(item.keyword || '').trim(),
        search_count: Number(item.search_count || 0),
      }))
  } catch {
    hotList.value = []
  } finally {
    hotLoading.value = false
  }
}

async function fetchCategories() {
  categoryLoading.value = true
  try {
    const data = await getCategoryList()
    const list = Array.isArray(data?.list)
      ? data.list
      : (Array.isArray(data) ? data : [])
    categoryList.value = list.filter((item) => item?.id && item?.name)
  } catch {
    categoryList.value = []
  } finally {
    categoryLoading.value = false
  }
}

function formatHotCount(count) {
  if (count >= 10000) return `${(count / 10000).toFixed(1)}万次`
  if (count > 0) return `${count}次`
  return '近 7 天'
}

function configFlag(code, fallback = true) {
  const value = siteConfig.value?.[code]
  if (value === undefined || value === null || value === '') return fallback
  return value === true || value === 1 || value === '1' || value === 'true'
}

function parseConfigArray(value, fallback) {
  if (Array.isArray(value)) return value
  if (typeof value !== 'string' || value.trim() === '') return fallback
  try {
    const parsed = JSON.parse(value)
    return Array.isArray(parsed) ? parsed : fallback
  } catch {
    return fallback
  }
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="搜索" />

    <view class="search-head">
      <view class="search-head__caption">
        <text class="search-head__brand">{{ brandName }}</text>
        <text class="search-head__sub">商品搜索</text>
      </view>
      <view class="search-head__row">
        <view class="search-head__input-wrap">
          <view class="search-head__search-icon" />
          <input
            v-model="keyword"
            class="search-head__input"
            type="text"
            placeholder="搜索商品名称"
            :focus="true"
            confirm-type="search"
            @confirm="onConfirm"
          />
          <view
            v-if="keyword"
            class="search-head__clear"
            @tap="clearKeyword"
          >
            <text class="search-head__clear-text">×</text>
          </view>
        </view>
        <view class="search-head__submit" @tap="onConfirm">
          <text class="search-head__submit-text">搜索</text>
        </view>
        <view class="search-head__cancel" @tap="goBack">
          <text class="search-head__cancel-text">取消</text>
        </view>
      </view>
    </view>

    <view class="content">
      <view v-if="showSearchHistory && historyList.length > 0" class="section">
        <view class="section__header">
          <text class="section__title">搜索历史</text>
          <view class="section__action" @tap="clearHistory">
            <view class="section__trash-icon" />
          </view>
        </view>
        <view class="tag-flow">
          <view
            v-for="(item, idx) in historyList"
            :key="`${item}-${idx}`"
            class="tag"
            @tap="onTagTap(item)"
          >
            <text class="tag__text">{{ item }}</text>
          </view>
        </view>
      </view>

      <view v-if="showQuickFilters" class="section">
        <view class="section__header">
          <text class="section__title">快捷筛选</text>
        </view>
        <view class="quick-grid">
          <view
            v-for="filter in visibleQuickFilters"
            :key="filter.value"
            class="quick-item"
            @tap="goFilter(filter)"
          >
            <view
              class="quick-item__icon"
              :class="`quick-item__icon--${filter.icon}`"
            />
            <text class="quick-item__text">{{ filter.label }}</text>
          </view>
        </view>
      </view>

      <view v-if="showHotSearch" class="section">
        <view class="section__header">
          <text class="section__title">热门搜索</text>
        </view>

        <view v-if="hotLoading" class="list-skeleton">
          <view v-for="i in 3" :key="i" class="list-skeleton__row" />
        </view>
        <view v-else-if="hotList.length > 0" class="hot-list">
          <view
            v-for="(item, index) in hotList"
            :key="`${item.keyword}-${index}`"
            class="hot-item"
            @tap="onTagTap(item.keyword)"
          >
            <text class="hot-item__rank">{{ index + 1 }}</text>
            <text class="hot-item__keyword">{{ item.keyword }}</text>
            <text class="hot-item__count">
              {{ formatHotCount(item.search_count) }}
            </text>
          </view>
        </view>
        <view v-else class="empty-line">
          <text class="empty-line__text">暂无热搜</text>
        </view>
      </view>

      <view v-if="showCommonCategories" class="section">
        <view class="section__header">
          <text class="section__title">常用分类</text>
          <view class="section__link" @tap="goAllCategories">
            <text class="section__link-text">全部</text>
          </view>
        </view>

        <view v-if="categoryLoading" class="category-skeleton">
          <view v-for="i in 8" :key="i" class="category-skeleton__item" />
        </view>
        <view v-else-if="visibleCategories.length > 0" class="category-grid">
          <view
            v-for="category in visibleCategories"
            :key="category.id"
            class="category-item"
            @tap="goCategory(category)"
          >
            <text class="category-item__text">{{ category.name }}</text>
          </view>
        </view>
        <view v-else class="empty-line">
          <text class="empty-line__text">暂无分类</text>
        </view>
      </view>

      <view class="bottom-spacer" />
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.search-head {
  padding: $mb-spacing-sm $mb-spacing-page $mb-spacing-lg;
  background: var(--color-bg, #ffffff);
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
}

.search-head__caption {
  display: flex;
  align-items: baseline;
  gap: $mb-spacing-sm;
  margin-bottom: $mb-spacing-sm;
}

.search-head__brand {
  max-width: 360rpx;
  font-size: $mb-font-md;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-head__sub {
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
}

.search-head__row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
}

.search-head__input-wrap {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  height: 72rpx;
  padding: 0 $mb-spacing-md;
  background: var(--color-bg-surface, #f3f3fe);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-full;
}

.search-head__search-icon {
  position: relative;
  flex-shrink: 0;
  width: 24rpx;
  height: 24rpx;
  margin-right: $mb-spacing-sm;
  border: 3rpx solid var(--color-text-tertiary, #737686);
  border-radius: 50%;

  &::after {
    content: '';
    position: absolute;
    right: -7rpx;
    bottom: -3rpx;
    width: 10rpx;
    height: 3rpx;
    border-radius: $mb-radius-full;
    background: var(--color-text-tertiary, #737686);
    transform: rotate(45deg);
  }
}

.search-head__input {
  flex: 1;
  min-width: 0;
  height: 72rpx;
  font-size: $mb-font-md;
  color: var(--color-text, #191b23);
}

.search-head__clear {
  flex-shrink: 0;
  width: 36rpx;
  height: 36rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--color-divider, #f0f2f5);
}

.search-head__clear-text {
  font-size: 30rpx;
  line-height: 1;
  color: var(--color-text-tertiary, #737686);
}

.search-head__submit,
.search-head__cancel {
  flex-shrink: 0;
  height: 72rpx;
  width: 88rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: $mb-radius-full;
}

.search-head__submit {
  background: var(--color-primary, #0d50d5);
}

.search-head__submit-text,
.search-head__cancel-text {
  font-size: $mb-font-sm;
  font-weight: 600;
  line-height: 1;
  white-space: nowrap;
}

.search-head__submit-text {
  color: var(--color-text-on-primary, #ffffff);
}

.search-head__cancel {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
}

.search-head__cancel-text {
  color: var(--color-primary, #0d50d5);
}

.content {
  padding: 0 $mb-spacing-page;
}

.section {
  margin-top: $mb-spacing-lg;
}

.section__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 48rpx;
  margin-bottom: $mb-spacing-md;
}

.section__title {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.section__action,
.section__link {
  flex-shrink: 0;
  padding: $mb-spacing-xs;
}

.section__link-text {
  display: block;
  padding: 8rpx 18rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
  font-size: $mb-font-sm;
  color: var(--color-primary, #0d50d5);
}

.section__trash-icon {
  position: relative;
  width: 32rpx;
  height: 34rpx;
  opacity: 0.5;
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

.tag-flow {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
}

.tag {
  max-width: 100%;
  padding: 14rpx 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-full;

  &:active {
    opacity: 0.65;
  }
}

.tag__text {
  display: block;
  max-width: 560rpx;
  font-size: 26rpx;
  line-height: 1.4;
  color: var(--color-text-secondary, #434654);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.quick-grid {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
}

.quick-item {
  height: 76rpx;
  padding: 0 $mb-spacing-md;
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: center;
  gap: $mb-spacing-xs;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-full;

  &:active {
    opacity: 0.68;
  }
}

.quick-item__icon {
  position: relative;
  flex-shrink: 0;
  width: 30rpx;
  height: 30rpx;
  color: var(--color-primary, #0d50d5);
}

.quick-item__icon::before,
.quick-item__icon::after {
  content: '';
  position: absolute;
  box-sizing: border-box;
}

.quick-item__icon--new::before {
  inset: 3rpx;
  border: 3rpx solid currentColor;
  border-radius: 7rpx;
}

.quick-item__icon--new::after {
  left: 9rpx;
  top: 13rpx;
  width: 12rpx;
  height: 3rpx;
  border-radius: $mb-radius-full;
  background: currentColor;
  box-shadow: 0 -7rpx 0 currentColor, 0 7rpx 0 currentColor;
}

.quick-item__icon--hot::before {
  left: 7rpx;
  top: 2rpx;
  width: 17rpx;
  height: 26rpx;
  border-radius: 18rpx 18rpx 18rpx 4rpx;
  border: 3rpx solid currentColor;
  transform: rotate(28deg);
}

.quick-item__icon--hot::after {
  left: 13rpx;
  top: 13rpx;
  width: 7rpx;
  height: 10rpx;
  border-radius: $mb-radius-full;
  background: currentColor;
}

.quick-item__icon--recommend::before {
  left: 4rpx;
  top: 4rpx;
  width: 22rpx;
  height: 22rpx;
  border: 3rpx solid currentColor;
  border-radius: 50%;
}

.quick-item__icon--recommend::after {
  left: 13rpx;
  top: 10rpx;
  width: 6rpx;
  height: 12rpx;
  border-right: 3rpx solid currentColor;
  border-bottom: 3rpx solid currentColor;
  transform: rotate(45deg);
}

.quick-item__icon--category::before {
  left: 3rpx;
  top: 3rpx;
  width: 8rpx;
  height: 8rpx;
  border-radius: 3rpx;
  background: currentColor;
  box-shadow: 16rpx 0 0 currentColor, 0 16rpx 0 currentColor, 16rpx 16rpx 0 currentColor;
}

.quick-item__text {
  font-size: $mb-font-sm;
  line-height: 1.3;
  color: var(--color-text-secondary, #434654);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hot-list {
  overflow: hidden;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.hot-item {
  display: flex;
  align-items: center;
  min-height: 88rpx;
  padding: 0 $mb-spacing-md;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);

  &:last-child {
    border-bottom: 0;
  }

  &:active {
    background: var(--color-bg-surface, #f3f3fe);
  }
}

.hot-item__rank {
  flex-shrink: 0;
  width: 44rpx;
  font-size: $mb-font-sm;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
}

.hot-item__keyword {
  flex: 1;
  min-width: 0;
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-text, #191b23);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hot-item__count {
  flex-shrink: 0;
  margin-left: $mb-spacing-sm;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
}

.category-grid {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
}

.category-item {
  max-width: 100%;
  padding: 16rpx 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-full;

  &:active {
    opacity: 0.68;
  }
}

.category-item__text {
  display: block;
  max-width: 280rpx;
  font-size: 26rpx;
  color: var(--color-text-secondary, #434654);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.empty-line {
  min-height: 96rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-sm;
}

.empty-line__text {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}

.list-skeleton,
.category-skeleton {
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-sm;
}

.list-skeleton__row,
.category-skeleton__item {
  height: 72rpx;
  border-radius: $mb-radius-sm;
  background: linear-gradient(
    90deg,
    var(--color-bg, #ffffff) 25%,
    var(--color-bg-surface, #f3f3fe) 50%,
    var(--color-bg, #ffffff) 75%
  );
  background-size: 200% 100%;
  animation: search-shimmer 1.5s infinite ease-in-out;
}

.category-skeleton {
  flex-direction: row;
  flex-wrap: wrap;
}

.category-skeleton__item {
  width: 160rpx;
}

.bottom-spacer {
  height: 96rpx;
}

@keyframes search-shimmer {
  0% {
    background-position: 200% 0;
  }

  100% {
    background-position: -200% 0;
  }
}
</style>
