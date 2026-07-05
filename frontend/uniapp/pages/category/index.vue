<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import config from '@/config/index'
import { getCategoryTree } from '@/api/goods/category'
import { useDecorateStore } from '@/store/decorate'
import { normalizeAssetPath } from '@/utils/decorate'

const decorateStore = useDecorateStore()
const categoryTree = ref([])
const activeIndex = ref(0)
const loading = ref(true)
const loadError = ref(false)
const contentScrollTop = ref(0)

const accentPalette = [
  { color: '#0d50d5', darkSoft: 'rgba(56, 107, 239, 0.18)', soft: '#eaf0ff' },
  { color: '#16805f', darkSoft: 'rgba(74, 222, 128, 0.14)', soft: '#e8f5ef' },
  { color: '#9a5b14', darkSoft: 'rgba(251, 191, 36, 0.16)', soft: '#fff3df' },
  { color: '#b23636', darkSoft: 'rgba(255, 107, 107, 0.15)', soft: '#fff0ef' },
  { color: '#535a1f', darkSoft: 'rgba(163, 177, 80, 0.15)', soft: '#f2f5df' },
]

const activeCategory = computed(() => categoryTree.value[activeIndex.value] || null)

const activeAccent = computed(() => {
  return accentPalette[activeIndex.value % accentPalette.length]
})

const activeCategoryStyle = computed(() => ({
  '--category-accent': activeAccent.value.color,
  '--category-accent-soft':
    decorateStore.resolvedThemeMode === 'dark'
      ? activeAccent.value.darkSoft
      : activeAccent.value.soft,
}))

const activeCategoryHeroImage = computed(() => getCategoryHeroImage(activeCategory.value))

const categoryDesc = computed(() => {
  const category = activeCategory.value
  if (!category) return ''
  return category.description || ''
})

const subcategories = computed(() => {
  const children = activeCategory.value?.children
  return Array.isArray(children) ? children : []
})

const visibleCategories = computed(() => {
  if (subcategories.value.length > 0) return subcategories.value
  return activeCategory.value ? [activeCategory.value] : []
})

const categoryGroups = computed(() => {
  const list = visibleCategories.value
  if (list.length === 0) return []

  if (list.length <= 6) {
    return [
      {
        key: 'primary',
        items: list,
      },
    ]
  }

  return [
    { key: 'primary', items: list.slice(0, 6) },
    { key: 'more', items: list.slice(6) },
  ]
})

function buildBackendStaticUrl(path) {
  if (!path) return ''
  const normalized = path.startsWith('/') ? path : `/${path}`
  const baseUrl = String(config.baseUrl || '').replace(/\/$/, '')
  return baseUrl ? `${baseUrl}${normalized}` : normalized
}

function normalizeCategoryImageUrl(value) {
  if (typeof value === 'number' || /^\d+$/.test(String(value || '').trim())) {
    return ''
  }
  const normalized = normalizeAssetPath(value)
  if (!normalized) return ''
  if (/^(?:https?:)?\/\//.test(normalized) || normalized.startsWith('data:image/')) {
    return normalized
  }
  return buildBackendStaticUrl(normalized)
}

function getRawCategoryImage(category) {
  if (!category) return ''
  return (
    category.image_full_url ||
      category.imageFullUrl ||
      category.full_url ||
      category.fullUrl ||
      category.image_url ||
      category.imageUrl ||
      category.cover ||
      category.image ||
      ''
  )
}

function resolveCategoryImage(category) {
  if (!category) return ''
  return normalizeCategoryImageUrl(getRawCategoryImage(category))
}

function getCategoryHeroImage(category) {
  return resolveCategoryImage(category)
}

function getCategoryImage(category) {
  return resolveCategoryImage(category)
}

function getCategoryInitial(category) {
  const name = category?.name || ''
  return name ? name.charAt(0) : '分'
}

async function fetchCategories() {
  loading.value = true
  loadError.value = false
  try {
    const data = await getCategoryTree()
    const list = Array.isArray(data?.list)
      ? data.list
      : (Array.isArray(data) ? data : [])
    categoryTree.value = list
    activeIndex.value = list.length > 0 ? Math.min(activeIndex.value, list.length - 1) : 0
  } catch {
    categoryTree.value = []
    activeIndex.value = 0
    loadError.value = true
  } finally {
    loading.value = false
  }
}

function onSelectCategory(index) {
  if (index === activeIndex.value) return
  activeIndex.value = index
  contentScrollTop.value = contentScrollTop.value === 0 ? 1 : 0
}

function onTapCategory(category) {
  if (!category?.id) return
  uni.navigateTo({
    url: `/pages-sub/goods/list?category_id=${category.id}`,
  })
}

function goSearch() {
  uni.navigateTo({ url: '/pages-sub/search/index' })
}

onLoad(() => {
  fetchCategories()
})
</script>

<template>
  <view
    class="page"
    :class="[
      `theme-${decorateStore.resolvedThemeMode}`,
      { 'page--custom-tabbar': decorateStore.tabbarMode === 'custom' },
    ]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="分类" :back="false" />

    <view class="search-bar" @tap="goSearch">
      <view class="search-bar__icon" />
      <text class="search-bar__text">搜索商品或分类</text>
    </view>

    <view v-if="loading" class="skeleton-layout">
      <view class="skeleton-sidebar">
        <view
          v-for="i in 7"
          :key="i"
          class="skeleton-sidebar__item"
        />
      </view>
      <view class="skeleton-content">
        <view class="skeleton-hero" />
        <view class="skeleton-grid">
          <view
            v-for="i in 6"
            :key="i"
            class="skeleton-card"
          />
        </view>
      </view>
    </view>

    <view
      v-else-if="categoryTree.length > 0"
      class="layout"
      :style="activeCategoryStyle"
    >
      <scroll-view
        scroll-y
        class="sidebar"
        :show-scrollbar="false"
      >
        <view class="sidebar__panel">
          <view
            v-for="(cat, index) in categoryTree"
            :key="cat.id"
            class="sidebar__item"
            :class="{ 'sidebar__item--active': index === activeIndex }"
            @tap="onSelectCategory(index)"
          >
            <view class="sidebar__mark" />
            <text class="sidebar__text">{{ cat.name }}</text>
          </view>
        </view>
      </scroll-view>

      <scroll-view
        scroll-y
        class="content"
        :scroll-top="contentScrollTop"
        :show-scrollbar="false"
      >
        <view
          v-if="activeCategory"
          class="hero"
        >
          <image
            v-if="activeCategoryHeroImage"
            class="hero__image"
            :src="activeCategoryHeroImage"
            mode="aspectFill"
          />
          <view class="hero__shade" />
          <view class="hero__copy">
            <text class="hero__title">{{ activeCategory.name }}</text>
            <text v-if="categoryDesc" class="hero__desc">{{ categoryDesc }}</text>
          </view>
          <view v-if="!activeCategoryHeroImage" class="hero__placeholder">
            <text class="hero__placeholder-text">{{ getCategoryInitial(activeCategory) }}</text>
          </view>
        </view>

        <view
          v-for="group in categoryGroups"
          :key="group.key"
          class="category-section"
        >
          <view class="category-grid">
            <view
              v-for="item in group.items"
              :key="item.id"
              class="category-grid__cell"
            >
              <view
                class="category-card"
                @tap="onTapCategory(item)"
              >
                <view class="category-card__image-wrap">
                  <image
                    v-if="getCategoryImage(item)"
                    class="category-card__image"
                    :src="getCategoryImage(item)"
                    mode="aspectFill"
                    lazy-load
                  />
                  <view v-else class="category-card__placeholder">
                    <text class="category-card__placeholder-text">{{ getCategoryInitial(item) }}</text>
                  </view>
                </view>
                <view class="category-card__body">
                  <text class="category-card__name">{{ item.name }}</text>
                </view>
              </view>
            </view>
          </view>
        </view>

        <view v-if="visibleCategories.length === 0" class="empty-sub">
          <mb-empty-state
            icon=""
            text="暂无分类"
            padding-top="120rpx"
          />
        </view>

        <mb-copyright-footer />
        <view v-if="decorateStore.tabbarMode === 'custom'" class="bottom-spacer" />
      </scroll-view>
    </view>

    <view v-else class="empty-root">
      <mb-empty-state
        icon=""
        :text="loadError ? '加载失败，点击重试' : '暂无分类'"
        :action-text="loadError ? '重新加载' : ''"
        @action="fetchCategories"
      />
    </view>

    <mb-custom-tabbar current="/pages/category/index" />
      <mb-floating-action />
</view>
</template>

<style lang="scss" scoped>
.page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  min-height: 100vh;
  background: var(--color-page-bg, var(--color-bg-secondary, #faf8ff));
}

.theme-dark {
  background: linear-gradient(180deg, var(--color-bg, #10131a) 0%, var(--color-page-bg, #151923) 36%);
}

.search-bar {
  display: flex;
  align-items: center;
  height: 72rpx;
  margin: 14rpx $mb-spacing-md 18rpx;
  padding: 0 $mb-spacing-md;
  border: 1rpx solid var(--color-border, #e0e4e8);
  border-radius: $mb-radius-full;
  background: var(--color-bg-surface, #f3f3fe);
  box-shadow: 0 10rpx 26rpx rgba(15, 23, 42, 0.06);
}

.theme-dark .search-bar {
  border-color: rgba(201, 209, 223, 0.22);
  background: rgba(255, 255, 255, 0.06);
  box-shadow: 0 12rpx 32rpx rgba(0, 0, 0, 0.2), inset 0 1rpx 0 rgba(255, 255, 255, 0.04);
}

.search-bar__icon {
  position: relative;
  width: 28rpx;
  height: 28rpx;
  margin-right: $mb-spacing-sm;
  border: 4rpx solid var(--color-text-tertiary-on-surface, var(--color-text-tertiary, #737686));
  border-radius: 50%;
  box-sizing: border-box;
}

.search-bar__icon::after {
  content: '';
  position: absolute;
  right: -9rpx;
  bottom: -6rpx;
  width: 14rpx;
  height: 4rpx;
  border-radius: $mb-radius-full;
  background: var(--color-text-tertiary-on-surface, var(--color-text-tertiary, #737686));
  transform: rotate(45deg);
}

.search-bar__text {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary-on-surface, var(--color-text-tertiary, #737686));
  line-height: 1.4;
}

.layout,
.skeleton-layout {
  position: relative;
  flex: 1;
  min-height: 0;
  display: flex;
  overflow: hidden;
}

.sidebar,
.skeleton-sidebar {
  width: 168rpx;
  height: 100%;
  flex-shrink: 0;
  background: var(--color-bg-secondary, #faf8ff);
}

.theme-dark .sidebar,
.theme-dark .skeleton-sidebar {
  border-right: 1rpx solid var(--color-divider, #262c38);
  background: linear-gradient(180deg, rgba(18, 24, 36, 0.96) 0%, rgba(12, 18, 28, 0.9) 100%);
}

.sidebar {
  position: relative;
  z-index: 2;
  padding: 0;
  border-right: 1rpx solid rgba(13, 80, 213, 0.08);
  box-sizing: border-box;
  box-shadow: 10rpx 0 28rpx rgba(15, 23, 42, 0.06);
}

.theme-dark .sidebar {
  border-right-color: rgba(201, 209, 223, 0.14);
  box-shadow: 12rpx 0 38rpx rgba(0, 0, 0, 0.28), inset -1rpx 0 0 rgba(255, 255, 255, 0.04);
}

.sidebar__panel {
  padding: 8rpx 0 20rpx;
  box-sizing: border-box;
}

.sidebar__item {
  position: relative;
  display: flex;
  align-items: center;
  min-height: 92rpx;
  padding: 0 10rpx 0 14rpx;
  margin: 6rpx 8rpx;
  border: 1rpx solid transparent;
  border-radius: $mb-radius-lg;
  box-sizing: border-box;
  transition: background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.sidebar__item--active {
  background: linear-gradient(90deg, var(--category-accent-soft, var(--color-bg-surface, #f3f3fe)) 0%, var(--color-bg-surface, #ffffff) 100%);
  border-color: var(--category-accent, var(--color-primary-border, var(--color-border, #e0e4e8)));
  box-shadow: 0 12rpx 28rpx rgba(13, 80, 213, 0.12);
}

.theme-dark .sidebar__item--active {
  background: linear-gradient(90deg, var(--category-accent-soft, rgba(56, 107, 239, 0.16)) 0%, rgba(17, 24, 39, 0.82) 100%);
  border-color: var(--category-accent, rgba(56, 107, 239, 0.34));
  box-shadow: 0 14rpx 34rpx rgba(0, 0, 0, 0.22);
}

.sidebar__mark {
  width: 6rpx;
  height: 30rpx;
  margin-right: 8rpx;
  border-radius: $mb-radius-full;
  background: transparent;
  flex-shrink: 0;
}

.sidebar__item--active .sidebar__mark {
  background: var(--category-accent, var(--color-primary-on-surface, var(--color-primary, #0d50d5)));
  box-shadow: 0 0 18rpx var(--category-accent-soft, rgba(13, 80, 213, 0.25));
}

.sidebar__text {
  flex: 1;
  font-size: 26rpx;
  color: var(--color-text-tertiary-on-page, var(--color-text-tertiary, #737686));
  line-height: 1.35;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar__item--active .sidebar__text {
  color: var(--color-text-title-on-surface, var(--color-text-title, #191b23));
  font-weight: 700;
}

.content,
.skeleton-content {
  position: relative;
  flex: 1;
  height: 100%;
  min-width: 0;
  background: linear-gradient(180deg, var(--color-bg-surface, #ffffff) 0%, var(--color-page-bg, var(--color-bg-secondary, #faf8ff)) 72%);
}

.theme-dark .content,
.theme-dark .skeleton-content {
  background: linear-gradient(180deg, rgba(18, 27, 45, 0.78) 0%, rgba(13, 18, 29, 0.72) 62%, rgba(13, 18, 29, 0.42) 100%);
}

.hero {
  position: relative;
  display: flex;
  height: 232rpx;
  margin: 0 $mb-spacing-md 30rpx;
  padding: 0;
  border: 1rpx solid var(--color-border, #e0e4e8);
  border-radius: $mb-radius-xl;
  background: var(--color-bg-surface, #ffffff);
  box-sizing: border-box;
  overflow: hidden;
  box-shadow: 0 20rpx 44rpx rgba(15, 23, 42, 0.12);
}

.theme-dark .hero {
  border-color: rgba(201, 209, 223, 0.18);
  background: #111827;
  box-shadow: 0 22rpx 56rpx rgba(0, 0, 0, 0.34), inset 0 1rpx 0 rgba(255, 255, 255, 0.05);
}

.hero__image {
  position: absolute;
  inset: 0;
  z-index: 0;
  width: 100%;
  height: 100%;
}

.hero__shade {
  position: absolute;
  inset: 0;
  z-index: 1;
  background: linear-gradient(90deg, rgba(255, 255, 255, 0.92) 0%, rgba(255, 255, 255, 0.78) 43%, rgba(255, 255, 255, 0.1) 100%);
}

.theme-dark .hero__shade {
  background: linear-gradient(90deg, rgba(16, 19, 26, 0.96) 0%, rgba(16, 26, 46, 0.82) 43%, rgba(16, 19, 26, 0.08) 100%);
}

.hero__copy {
  position: relative;
  z-index: 2;
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  max-width: 58%;
  padding: 0 0 0 34rpx;
}

.hero__title {
  font-size: 36rpx;
  color: var(--color-text-title-on-surface, var(--color-text-title, #191b23));
  font-weight: 800;
  line-height: 1.25;
}

.hero__desc {
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-xs;
  color: var(--color-text-secondary-on-surface, var(--color-text-secondary, #434654));
  line-height: 1.45;
}

.hero__placeholder,
.category-card__placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--category-accent-soft, var(--color-primary-softer, #f3f3fe));
}

.hero__placeholder-text,
.category-card__placeholder-text {
  color: var(--category-accent, #0d50d5);
  font-weight: 800;
  line-height: 1;
}

.hero__placeholder-text {
  font-size: $mb-font-xxl;
}

.hero__placeholder {
  position: absolute;
  right: $mb-spacing-md;
  top: 50%;
  z-index: 1;
  width: 132rpx;
  height: 132rpx;
  border-radius: $mb-radius-lg;
  transform: translateY(-50%);
}

.category-section {
  margin: 0 $mb-spacing-md $mb-spacing-lg;
}

.category-grid {
  display: flex;
  flex-wrap: wrap;
  margin: 0 -7rpx;
}

.category-grid__cell {
  width: 33.333%;
  padding: 0 7rpx 16rpx;
  box-sizing: border-box;
}

.category-card {
  min-height: 0;
  padding: 0;
  border: 0;
  background: transparent;
  box-sizing: border-box;
  transition: transform 0.18s ease;
}

.theme-dark .category-card {
  background: transparent;
}

.category-card:active {
  transform: scale(0.98);
}

.category-card__image-wrap {
  position: relative;
  width: 100%;
  height: auto;
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1rpx solid var(--color-border, #e0e4e8);
  border-radius: $mb-radius-lg;
  background: var(--color-bg-surface, #ffffff);
  overflow: hidden;
  box-shadow: 0 14rpx 30rpx rgba(15, 23, 42, 0.1);
}

.theme-dark .category-card__image-wrap {
  border-color: rgba(201, 209, 223, 0.18);
  background: rgba(21, 29, 43, 0.9);
  box-shadow: 0 16rpx 38rpx rgba(0, 0, 0, 0.28), inset 0 1rpx 0 rgba(255, 255, 255, 0.04);
}

.category-card__image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.category-card__placeholder {
  position: absolute;
  inset: 0;
}

.category-card__placeholder-text {
  font-size: $mb-font-lg;
}

.category-card__body {
  margin-top: 12rpx;
}

.category-card__name {
  display: block;
  font-size: 24rpx;
  color: var(--color-text-title-on-surface, var(--color-text-title, #191b23));
  font-weight: 800;
  line-height: 1.35;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: center;
}

.empty-sub,
.empty-root {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}

.skeleton-sidebar {
  padding: $mb-spacing-sm 0;
  box-sizing: border-box;
}

.skeleton-sidebar__item {
  height: 52rpx;
  margin: 18rpx $mb-spacing-md;
  border-radius: $mb-radius-md;
  background: linear-gradient(90deg, var(--color-bg-surface, #f3f3fe) 25%, var(--color-bg, #ffffff) 50%, var(--color-bg-surface, #f3f3fe) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s infinite ease-in-out;
}

.skeleton-content {
  padding: 0 $mb-spacing-md;
  box-sizing: border-box;
}

.skeleton-hero {
  height: 232rpx;
  margin-bottom: $mb-spacing-lg;
  border-radius: $mb-radius-xl;
  background: linear-gradient(90deg, var(--color-bg-surface, #f3f3fe) 25%, var(--color-bg, #ffffff) 50%, var(--color-bg-surface, #f3f3fe) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s infinite ease-in-out;
}

.skeleton-grid {
  display: flex;
  flex-wrap: wrap;
  margin: 0 -7rpx;
}

.skeleton-card {
  width: calc(33.333% - 14rpx);
  height: 230rpx;
  margin: 0 7rpx 16rpx;
  border-radius: $mb-radius-lg;
  background: linear-gradient(90deg, var(--color-bg-surface, #ffffff) 25%, var(--color-bg, #f3f3fe) 50%, var(--color-bg-surface, #ffffff) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s infinite ease-in-out;
}

@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

.bottom-spacer {
  height: 132rpx;
}
</style>
