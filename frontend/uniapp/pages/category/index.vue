<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getCategoryTree } from '@/api/goods/category'
import { useDecorateStore } from '@/store/decorate'

// ---------- state ----------
const decorateStore = useDecorateStore()
const categoryTree = ref([])
const activeIndex = ref(0)
const loading = ref(true)
const loadError = ref(false)

// ---------- banner slogans ----------
const bannerSlogans = [
  { en: 'NEW ARRIVAL', zh: '智联未来系列' },
  { en: 'HOT PICKS', zh: '品质生活甄选' },
  { en: 'TRENDING', zh: '潮流风尚前沿' },
  { en: 'BEST VALUE', zh: '超值精选好物' },
  { en: 'CLASSIC', zh: '经典品味之选' },
  { en: 'ESSENTIALS', zh: '日常优选好物' },
  { en: 'TOP STYLE', zh: '时尚穿搭指南' },
  { en: 'LIFESTYLE', zh: '品味生活美学' },
]

// ---------- category descriptions ----------
const categoryDescMap = {
  '手机数码': '探索最新科技与前沿设计之美',
  '家居生活': '打造温馨舒适的理想空间',
  '服装配饰': '时尚穿搭彰显个性品味',
  '美妆护肤': '科学护肤绽放自然之美',
  '运动户外': '畅享运动活力自由生活',
  '日用百货': '精选品质日常生活好物',
  '母婴童装': '用心守护成长每一步',
  '食品饮料': '健康美味品质生活之选',
}

// ---------- subcategory icon map ----------
const subIconMap = {
  '智能手机': 'phone',
  '手机': 'phone',
  '笔记本': 'laptop',
  '电脑': 'laptop',
  '影音娱乐': 'headphone',
  '耳机': 'headphone',
  '智能穿戴': 'watch',
  '手表': 'watch',
  '平板电脑': 'tablet',
  '平板': 'tablet',
  '数码相机': 'camera',
  '相机': 'camera',
  '电视': 'screen',
  '音响': 'speaker',
  '键盘': 'keyboard',
  '鼠标': 'mouse',
  '充电器': 'charger',
  '沙发': 'home',
  '床品': 'home',
  '灯具': 'lamp',
  '厨具': 'home',
  '收纳': 'box',
  '装饰': 'home',
  '男装': 'shirt',
  '女装': 'shirt',
  '鞋靴': 'shoe',
  '包袋': 'bag',
  '配饰': 'watch',
  '护肤': 'beauty',
  '彩妆': 'beauty',
  '香水': 'beauty',
  '面膜': 'beauty',
  '洗护': 'beauty',
  '美发': 'beauty',
}

// ---------- derived ----------
const activeCategory = computed(() => categoryTree.value[activeIndex.value] || null)

const bannerSlogan = computed(() => {
  const idx = activeIndex.value % bannerSlogans.length
  return bannerSlogans[idx]
})

const categoryDesc = computed(() => {
  const name = activeCategory.value?.name || ''
  return categoryDescMap[name] || '发现更多精选好物'
})

const subcategories = computed(() => {
  const children = activeCategory.value?.children
  return Array.isArray(children) ? children : []
})

/**
 * Group subcategories: first 6 as "热门分类", rest grouped by 3 as named sections.
 * If total <= 6, show as single "热门分类" group.
 */
const subcategoryGroups = computed(() => {
  const all = subcategories.value
  if (all.length === 0) return []

  const groups = []
  const hotCount = Math.min(6, all.length)
  groups.push({ title: '热门分类', items: all.slice(0, hotCount) })

  if (all.length > hotCount) {
    const rest = all.slice(hotCount)
    // Group remaining into a single section with the parent name suffix
    const parentName = activeCategory.value?.name || ''
    groups.push({
      title: parentName ? `${parentName}配件` : '更多分类',
      items: rest,
    })
  }

  return groups
})

// ---------- icon helpers ----------
function getSubIcon(name) {
  // Try exact match, then partial match
  if (subIconMap[name]) return subIconMap[name]
  for (const key of Object.keys(subIconMap)) {
    if (name.includes(key) || key.includes(name)) return subIconMap[key]
  }
  // Fallback: first character inside a styled container
  return null
}

// ---------- data fetching ----------
async function fetchCategories() {
  loading.value = true
  loadError.value = false
  try {
    const data = await getCategoryTree()
    categoryTree.value = Array.isArray(data?.list)
      ? data.list
      : (Array.isArray(data) ? data : [])
    activeIndex.value = 0
  } catch {
    categoryTree.value = []
    loadError.value = true
  } finally {
    loading.value = false
  }
}

// ---------- interaction ----------
function onSelectCategory(index) {
  if (index === activeIndex.value) return
  activeIndex.value = index
}

function onTapSubcategory(sub) {
  uni.navigateTo({
    url: `/pages-sub/goods/list?category_id=${sub.id}`,
  })
}

// ---------- lifecycle ----------
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

    <!-- Loading skeleton -->
    <view v-if="loading" class="skeleton-layout">
      <view class="skeleton-sidebar">
        <view
          v-for="i in 8"
          :key="i"
          class="skeleton-sidebar__item"
        />
      </view>
      <view class="skeleton-content">
        <view class="skeleton-banner" />
        <view class="skeleton-content__grid">
          <view
            v-for="i in 6"
            :key="i"
            class="skeleton-content__cell"
          >
            <view class="skeleton-content__square" />
            <view class="skeleton-content__label" />
          </view>
        </view>
      </view>
    </view>

    <!-- Main layout -->
    <view v-else-if="categoryTree.length > 0" class="layout">
      <!-- Left sidebar -->
      <scroll-view
        scroll-y
        class="sidebar"
        :show-scrollbar="false"
      >
        <view
          v-for="(cat, index) in categoryTree"
          :key="cat.id"
          class="sidebar__item"
          :class="{ 'sidebar__item--active': index === activeIndex }"
          @tap="onSelectCategory(index)"
        >
          <view
            v-if="index === activeIndex"
            class="sidebar__indicator"
          />
          <text
            class="sidebar__text"
            :class="{ 'sidebar__text--active': index === activeIndex }"
          >{{ cat.name }}</text>
        </view>
      </scroll-view>

      <!-- Right content area -->
      <scroll-view
        scroll-y
        class="content"
        :show-scrollbar="false"
      >
        <!-- Promotional Banner -->
        <view class="banner">
          <view class="banner__bg">
            <view class="banner__accent" />
          </view>
          <view class="banner__text">
            <text class="banner__en">{{ bannerSlogan.en }}</text>
            <text class="banner__zh">{{ bannerSlogan.zh }}</text>
          </view>
        </view>

        <!-- Category Header -->
        <view v-if="activeCategory" class="content__header">
          <text class="content__title">{{ activeCategory.name }}</text>
          <text class="content__desc">{{ categoryDesc }}</text>
        </view>

        <!-- Subcategory Groups -->
        <view
          v-for="(group, gi) in subcategoryGroups"
          :key="gi"
          class="sub-group"
        >
          <view class="sub-group__header">
            <text class="sub-group__title">{{ group.title }}</text>
            <view class="sub-group__line" />
          </view>

          <view class="grid">
            <view
              v-for="sub in group.items"
              :key="sub.id"
              class="grid__item"
              @tap="onTapSubcategory(sub)"
            >
              <view class="grid__icon-wrap">
                <image
                  v-if="sub.image_full_url || sub.image"
                  class="grid__icon-img"
                  :src="sub.image_full_url || sub.image"
                  mode="aspectFill"
                  lazy-load
                />
                <view
                  v-else-if="getSubIcon(sub.name)"
                  class="grid__line-icon"
                  :class="'grid__line-icon--' + getSubIcon(sub.name)"
                />
                <text v-else class="grid__icon-char">{{ sub.name.charAt(0) }}</text>
              </view>
              <text class="grid__name">{{ sub.name }}</text>
            </view>
          </view>
        </view>

        <!-- Empty subcategories -->
        <view v-if="subcategories.length === 0" class="empty-sub">
          <mb-empty-state
            icon=""
            text="暂无子分类"
            padding-top="160rpx"
          />
        </view>

        <!-- Bottom safe area -->
        <view v-if="decorateStore.tabbarMode === 'custom'" class="bottom-spacer" />
      </scroll-view>
    </view>

    <!-- Empty state: no categories at all -->
    <view v-else class="empty-root">
      <mb-empty-state
        icon=""
        :text="loadError ? '加载失败，点击重试' : '暂无分类'"
        :action-text="loadError ? '重新加载' : ''"
        @action="fetchCategories"
      />
    </view>
    <mb-custom-tabbar current="/pages/category/index" />
  </view>
</template>

<style lang="scss" scoped>
/* ===========================
   Page
   =========================== */
.page {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background-color: var(--color-bg-secondary, #faf8ff);
}

/* ===========================
   Main Split Layout
   =========================== */
.layout {
  flex: 1;
  display: flex;
  overflow: hidden;
}

/* ===========================
   Left Sidebar
   =========================== */
.sidebar {
  width: 168rpx;
  flex-shrink: 0;
  background-color: #f0f0ff;
  height: 100%;
}

.sidebar__item {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 108rpx;
  padding: 0 $mb-spacing-sm;
  transition: background-color 0.2s ease;
}

.sidebar__item--active {
  background-color: var(--color-bg-surface, #f3f3fe);
}

.sidebar__indicator {
  position: absolute;
  left: 0;
  top: 50%;
  transform: translateY(-50%);
  width: 6rpx;
  height: 44rpx;
  border-radius: 0 $mb-radius-sm $mb-radius-sm 0;
  background-color: var(--color-primary, #0d50d5);
}

.sidebar__text {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.4;
  transition: color 0.2s ease, font-weight 0.2s ease;
}

.sidebar__text--active {
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

/* ===========================
   Right Content Area
   =========================== */
.content {
  flex: 1;
  height: 100%;
  background-color: var(--color-bg, #ffffff);
}

/* ===========================
   Promotional Banner
   =========================== */
.banner {
  position: relative;
  margin: $mb-spacing-md $mb-spacing-md 0;
  height: 200rpx;
  border-radius: $mb-radius-lg;
  overflow: hidden;
  border: 1rpx solid var(--color-divider, #f0f2f5);
}

.banner__bg {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(circle at 78% 48%, rgba(255, 255, 255, 0.14) 0 16%, transparent 17%),
    linear-gradient(135deg, #11263f 0%, #08182c 56%, #07111f 100%);
}

.banner__accent {
  position: absolute;
  top: 0;
  left: 48%;
  width: 2rpx;
  height: 100%;
  background: rgba(255, 255, 255, 0.18);
  box-shadow: 68rpx 0 0 rgba(255, 255, 255, 0.12), -68rpx 0 0 rgba(255, 255, 255, 0.1);
}

.banner__text {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  padding-left: $mb-spacing-lg;
  z-index: 1;
}

.banner__en {
  font-size: $mb-font-xs;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.7);
  letter-spacing: 1rpx;
  text-transform: uppercase;
  margin-bottom: $mb-spacing-xs;
}

.banner__zh {
  font-size: $mb-font-md;
  font-weight: 700;
  color: var(--color-text-inverse, #ffffff);
  letter-spacing: 0;
}

/* ===========================
   Content Header
   =========================== */
.content__header {
  padding: $mb-spacing-lg $mb-spacing-md $mb-spacing-xs;
}

.content__title {
  display: block;
  font-size: $mb-font-lg;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  line-height: 1.3;
}

.content__desc {
  display: block;
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.4;
}

/* ===========================
   Subcategory Group
   =========================== */
.sub-group {
  margin-top: $mb-spacing-md;
}

.sub-group__header {
  display: flex;
  align-items: center;
  padding: 0 $mb-spacing-md;
  margin-bottom: $mb-spacing-sm;
}

.sub-group__title {
  font-size: $mb-font-xs;
  font-weight: 600;
  color: var(--color-text-secondary, #434654);
  white-space: nowrap;
  margin-right: $mb-spacing-sm;
}

.sub-group__line {
  flex: 1;
  height: 1rpx;
  background-color: var(--color-divider, #f0f2f5);
}

/* ===========================
   Subcategory Grid (3 columns)
   =========================== */
.grid {
  display: flex;
  flex-wrap: wrap;
  padding: 0 $mb-spacing-sm;
}

.grid__item {
  width: 33.333%;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: $mb-spacing-sm $mb-spacing-xs;
}

.grid__icon-wrap {
  width: 104rpx;
  height: 104rpx;
  border-radius: $mb-radius-lg;
  background-color: #fafbff;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  transition: transform 0.2s ease;
}

.grid__item:active .grid__icon-wrap {
  transform: scale(0.95);
}

.grid__icon-img {
  width: 100%;
  height: 100%;
}

.grid__icon-char {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
  line-height: 1;
}

.grid__line-icon {
  position: relative;
  width: 42rpx;
  height: 42rpx;
  color: var(--color-primary, #0d50d5);
}

.grid__line-icon::before,
.grid__line-icon::after {
  content: '';
  position: absolute;
  box-sizing: border-box;
}

.grid__line-icon--phone::before { inset: 2rpx 10rpx; border: 4rpx solid currentColor; border-radius: 8rpx; }
.grid__line-icon--phone::after { left: 18rpx; bottom: 8rpx; width: 6rpx; height: 4rpx; border-radius: 999rpx; background: currentColor; }
.grid__line-icon--laptop::before { left: 4rpx; top: 8rpx; width: 34rpx; height: 22rpx; border: 4rpx solid currentColor; border-radius: 4rpx; }
.grid__line-icon--laptop::after { left: 0; bottom: 4rpx; width: 42rpx; height: 5rpx; border-radius: 999rpx; background: currentColor; }
.grid__line-icon--headphone::before { left: 4rpx; top: 6rpx; width: 34rpx; height: 30rpx; border: 4rpx solid currentColor; border-bottom: 0; border-radius: 20rpx 20rpx 0 0; }
.grid__line-icon--headphone::after { left: 2rpx; bottom: 4rpx; width: 38rpx; height: 16rpx; border-left: 8rpx solid currentColor; border-right: 8rpx solid currentColor; border-radius: 6rpx; }
.grid__line-icon--watch::before { left: 10rpx; top: 10rpx; width: 22rpx; height: 22rpx; border: 4rpx solid currentColor; border-radius: 50%; }
.grid__line-icon--watch::after { left: 16rpx; top: 0; width: 10rpx; height: 42rpx; border-top: 8rpx solid currentColor; border-bottom: 8rpx solid currentColor; }
.grid__line-icon--tablet::before { inset: 4rpx 7rpx; border: 4rpx solid currentColor; border-radius: 6rpx; }
.grid__line-icon--tablet::after { left: 18rpx; bottom: 8rpx; width: 6rpx; height: 4rpx; border-radius: 999rpx; background: currentColor; }
.grid__line-icon--camera::before { left: 4rpx; top: 12rpx; width: 34rpx; height: 24rpx; border: 4rpx solid currentColor; border-radius: 6rpx; }
.grid__line-icon--camera::after { left: 15rpx; top: 17rpx; width: 12rpx; height: 12rpx; border: 4rpx solid currentColor; border-radius: 50%; background: #fafbff; }
.grid__line-icon--screen::before { left: 4rpx; top: 8rpx; width: 34rpx; height: 24rpx; border: 4rpx solid currentColor; border-radius: 5rpx; }
.grid__line-icon--screen::after { left: 14rpx; bottom: 4rpx; width: 14rpx; height: 4rpx; background: currentColor; box-shadow: 0 -4rpx 0 currentColor; }
.grid__line-icon--speaker::before { left: 10rpx; top: 4rpx; width: 22rpx; height: 34rpx; border: 4rpx solid currentColor; border-radius: 8rpx; }
.grid__line-icon--speaker::after { left: 17rpx; top: 11rpx; width: 8rpx; height: 8rpx; background: currentColor; border-radius: 50%; box-shadow: 0 14rpx 0 currentColor; }
.grid__line-icon--keyboard::before { left: 3rpx; top: 12rpx; width: 36rpx; height: 22rpx; border: 4rpx solid currentColor; border-radius: 6rpx; }
.grid__line-icon--keyboard::after { left: 10rpx; top: 21rpx; width: 22rpx; height: 4rpx; background: currentColor; box-shadow: 0 -7rpx 0 currentColor; }
.grid__line-icon--mouse::before { left: 12rpx; top: 4rpx; width: 18rpx; height: 34rpx; border: 4rpx solid currentColor; border-radius: 12rpx; }
.grid__line-icon--mouse::after { left: 20rpx; top: 8rpx; width: 3rpx; height: 10rpx; background: currentColor; }
.grid__line-icon--charger::before { left: 12rpx; top: 12rpx; width: 18rpx; height: 20rpx; border: 4rpx solid currentColor; border-radius: 4rpx; }
.grid__line-icon--charger::after { left: 15rpx; top: 2rpx; width: 4rpx; height: 14rpx; background: currentColor; box-shadow: 9rpx 0 0 currentColor; }
.grid__line-icon--home::before { left: 5rpx; top: 15rpx; width: 32rpx; height: 22rpx; border: 4rpx solid currentColor; border-top: 0; border-radius: 0 0 6rpx 6rpx; }
.grid__line-icon--home::after { left: 8rpx; top: 5rpx; width: 26rpx; height: 26rpx; border-left: 4rpx solid currentColor; border-top: 4rpx solid currentColor; transform: rotate(45deg); }
.grid__line-icon--lamp::before { left: 9rpx; top: 6rpx; width: 24rpx; height: 18rpx; border: 4rpx solid currentColor; border-radius: 16rpx 16rpx 4rpx 4rpx; }
.grid__line-icon--lamp::after { left: 19rpx; top: 24rpx; width: 4rpx; height: 16rpx; background: currentColor; box-shadow: -8rpx 14rpx 0 0 currentColor, 8rpx 14rpx 0 0 currentColor; }
.grid__line-icon--box::before { left: 5rpx; top: 12rpx; width: 32rpx; height: 24rpx; border: 4rpx solid currentColor; border-radius: 6rpx; }
.grid__line-icon--box::after { left: 5rpx; top: 20rpx; width: 32rpx; height: 4rpx; background: currentColor; }
.grid__line-icon--shirt::before { left: 6rpx; top: 8rpx; width: 30rpx; height: 30rpx; border: 4rpx solid currentColor; border-radius: 8rpx 8rpx 4rpx 4rpx; }
.grid__line-icon--shirt::after { left: 4rpx; top: 8rpx; width: 34rpx; height: 12rpx; border-left: 8rpx solid currentColor; border-right: 8rpx solid currentColor; }
.grid__line-icon--shoe::before { left: 4rpx; bottom: 8rpx; width: 34rpx; height: 16rpx; border: 4rpx solid currentColor; border-radius: 2rpx 12rpx 6rpx 6rpx; }
.grid__line-icon--shoe::after { left: 8rpx; bottom: 24rpx; width: 16rpx; height: 8rpx; border-top: 4rpx solid currentColor; transform: rotate(25deg); }
.grid__line-icon--bag::before { left: 7rpx; top: 14rpx; width: 28rpx; height: 24rpx; border: 4rpx solid currentColor; border-radius: 5rpx; }
.grid__line-icon--bag::after { left: 14rpx; top: 4rpx; width: 14rpx; height: 16rpx; border: 4rpx solid currentColor; border-bottom: 0; border-radius: 10rpx 10rpx 0 0; }
.grid__line-icon--beauty::before { left: 13rpx; top: 8rpx; width: 16rpx; height: 30rpx; border: 4rpx solid currentColor; border-radius: 8rpx; }
.grid__line-icon--beauty::after { left: 18rpx; top: 2rpx; width: 6rpx; height: 12rpx; background: currentColor; border-radius: 999rpx; }

.grid__name {
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-xs;
  color: var(--color-text, #191b23);
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 100%;
  line-height: 1.5;
}

/* ===========================
   Empty States
   =========================== */
.empty-sub {
  padding: $mb-spacing-xl 0;
}

.empty-root {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ===========================
   Skeleton Loading
   =========================== */
.skeleton-layout {
  flex: 1;
  display: flex;
  overflow: hidden;
}

.skeleton-sidebar {
  width: 176rpx;
  flex-shrink: 0;
  background-color: var(--color-bg, #ffffff);
  padding: $mb-spacing-sm 0;
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-xs;
}

.skeleton-sidebar__item {
  height: 40rpx;
  margin: $mb-spacing-lg $mb-spacing-md;
  border-radius: $mb-radius-sm;
  background: linear-gradient(
    90deg,
    var(--color-bg-secondary, #faf8ff) 25%,
    var(--color-bg-surface, #f3f3fe) 50%,
    var(--color-bg-secondary, #faf8ff) 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite ease-in-out;
}

.skeleton-content {
  flex: 1;
  background-color: var(--color-bg-secondary, #faf8ff);
  padding: $mb-spacing-md;
}

.skeleton-banner {
  height: 200rpx;
  border-radius: $mb-radius-lg;
  background: linear-gradient(
    90deg,
    #e8e8e8 25%,
    #f0f0f0 50%,
    #e8e8e8 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite ease-in-out;
  margin-bottom: $mb-spacing-lg;
}

.skeleton-content__grid {
  display: flex;
  flex-wrap: wrap;
}

.skeleton-content__cell {
  width: 33.333%;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: $mb-spacing-sm $mb-spacing-xs;
}

.skeleton-content__square {
  width: 112rpx;
  height: 112rpx;
  border-radius: $mb-radius-lg;
  background: linear-gradient(
    90deg,
    var(--color-bg, #ffffff) 25%,
    var(--color-bg-surface, #f3f3fe) 50%,
    var(--color-bg, #ffffff) 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite ease-in-out;
}

.skeleton-content__label {
  width: 80rpx;
  height: 20rpx;
  margin-top: $mb-spacing-xs;
  border-radius: $mb-radius-sm;
  background: linear-gradient(
    90deg,
    var(--color-bg, #ffffff) 25%,
    var(--color-bg-surface, #f3f3fe) 50%,
    var(--color-bg, #ffffff) 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite ease-in-out;
}

@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* ===========================
   Bottom Spacer
   =========================== */
.bottom-spacer {
  height: 120rpx;
}
</style>
