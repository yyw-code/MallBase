<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getCategoryTree } from '@/api/goods/category'
import { useAppStore } from '@/store/app'

// ---------- store ----------
const appStore = useAppStore()
const brandName = computed(() => appStore.siteConfig?.site_name || 'MALLBASE')

// ---------- system info ----------
const { statusBarHeight } = uni.getSystemInfoSync()

// ---------- state ----------
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

// ---------- subcategory icon map (Unicode) ----------
const subIconMap = {
  '智能手机': '\u{1F4F1}',
  '笔记本': '\u{1F4BB}',
  '影音娱乐': '\u{1F3A7}',
  '智能穿戴': '⌚',
  '平板电脑': '\u{1F4F1}',
  '数码相机': '\u{1F4F7}',
  '电视': '\u{1F4FA}',
  '音响': '\u{1F50A}',
  '耳机': '\u{1F3A7}',
  '键盘': '⌨',
  '鼠标': '\u{1F5B1}',
  '充电器': '\u{1F50C}',
  '沙发': '\u{1FA91}',
  '床品': '\u{1F6CF}',
  '灯具': '\u{1F4A1}',
  '厨具': '\u{1F373}',
  '收纳': '\u{1F4E6}',
  '装饰': '\u{1F3A8}',
  '男装': '\u{1F454}',
  '女装': '\u{1F457}',
  '鞋靴': '\u{1F45F}',
  '包袋': '\u{1F45C}',
  '配饰': '\u{1F48D}',
  '手表': '⌚',
  '护肤': '✨',
  '彩妆': '\u{1F484}',
  '香水': '\u{1F9F4}',
  '面膜': '\u{1F3AD}',
  '洗护': '\u{1F9F4}',
  '美发': '\u{1F487}',
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
    categoryTree.value = Array.isArray(data) ? data : []
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

function goSearch() {
  uni.navigateTo({ url: '/pages-sub/search/index' })
}

// ---------- lifecycle ----------
onLoad(() => {
  fetchCategories()
})
</script>

<template>
  <view class="page">
    <!-- ========== Custom Top Bar ========== -->
    <view class="top-bar" :style="{ paddingTop: statusBarHeight + 'px' }">
      <view class="top-bar__inner">
        <text class="top-bar__brand">{{ brandName }}</text>
        <view class="top-bar__search" @tap="goSearch">
          <text class="top-bar__search-icon">&#x1F50D;</text>
        </view>
      </view>
    </view>

    <!-- Top bar spacer -->
    <view
      class="top-bar__spacer"
      :style="{ height: (statusBarHeight + 44) + 'px' }"
    />

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
                  v-if="sub.image"
                  class="grid__icon-img"
                  :src="sub.image"
                  mode="aspectFill"
                  lazy-load
                />
                <text
                  v-else-if="getSubIcon(sub.name)"
                  class="grid__icon-emoji"
                >{{ getSubIcon(sub.name) }}</text>
                <text v-else class="grid__icon-char">{{ sub.name.charAt(0) }}</text>
              </view>
              <text class="grid__name">{{ sub.name }}</text>
            </view>
          </view>
        </view>

        <!-- Empty subcategories -->
        <view v-if="subcategories.length === 0" class="empty-sub">
          <mb-empty-state
            icon="&#x1F4C2;"
            text="暂无子分类"
            padding-top="160rpx"
          />
        </view>

        <!-- Bottom safe area -->
        <view class="bottom-spacer" />
      </scroll-view>
    </view>

    <!-- Empty state: no categories at all -->
    <view v-else class="empty-root">
      <mb-empty-state
        icon="&#x1F4C2;"
        :text="loadError ? '加载失败，点击重试' : '暂无分类'"
        :action-text="loadError ? '重新加载' : ''"
        @action="fetchCategories"
      />
    </view>
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
  background-color: $mb-color-bg;
}

/* ===========================
   Custom Top Bar
   =========================== */
.top-bar {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 999;
  background-color: $mb-color-bg;
}

.top-bar__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 88rpx;
  padding: 0 $mb-spacing-md;
}

.top-bar__brand {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: $mb-color-text-title;
  letter-spacing: 2rpx;
  text-transform: uppercase;
}

.top-bar__search {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 64rpx;
  height: 64rpx;
}

.top-bar__search-icon {
  font-size: 36rpx;
  line-height: 1;
}

.top-bar__spacer {
  flex-shrink: 0;
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
  width: 176rpx;
  flex-shrink: 0;
  background-color: $mb-color-bg;
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
  background-color: $mb-color-bg-secondary;
}

.sidebar__indicator {
  position: absolute;
  left: 0;
  top: 50%;
  transform: translateY(-50%);
  width: 6rpx;
  height: 44rpx;
  border-radius: 0 $mb-radius-sm $mb-radius-sm 0;
  background-color: $mb-color-text-title;
}

.sidebar__text {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
  text-align: center;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.4;
  transition: color 0.2s ease, font-weight 0.2s ease;
}

.sidebar__text--active {
  font-weight: 700;
  color: $mb-color-text-title;
}

/* ===========================
   Right Content Area
   =========================== */
.content {
  flex: 1;
  height: 100%;
  background-color: $mb-color-bg-secondary;
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
}

.banner__bg {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, #2a2218 0%, #1a1a1a 40%, #2d2d2d 100%);
}

.banner__accent {
  position: absolute;
  top: 0;
  left: 0;
  width: 55%;
  height: 100%;
  background: linear-gradient(
    135deg,
    rgba(180, 150, 80, 0.5) 0%,
    rgba(160, 120, 50, 0.3) 40%,
    transparent 100%
  );
  border-radius: 0 0 60% 0;
}

.banner__text {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  z-index: 1;
}

.banner__en {
  font-size: $mb-font-xs;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.7);
  letter-spacing: 6rpx;
  text-transform: uppercase;
  margin-bottom: $mb-spacing-xs;
}

.banner__zh {
  font-size: $mb-font-xl;
  font-weight: 700;
  color: $mb-color-text-inverse;
  letter-spacing: 4rpx;
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
  color: $mb-color-text-title;
  line-height: 1.3;
}

.content__desc {
  display: block;
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
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
  color: $mb-color-text-secondary;
  white-space: nowrap;
  margin-right: $mb-spacing-sm;
}

.sub-group__line {
  flex: 1;
  height: 1rpx;
  background-color: $mb-color-divider;
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
  width: 112rpx;
  height: 112rpx;
  border-radius: $mb-radius-lg;
  background-color: #2a2a2a;
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

.grid__icon-emoji {
  font-size: 48rpx;
  line-height: 1;
  // Use CSS filter to make emoji appear more monotone/white-ish
  filter: grayscale(100%) brightness(2);
}

.grid__icon-char {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: $mb-color-text-inverse;
  line-height: 1;
}

.grid__name {
  margin-top: $mb-spacing-xs;
  font-size: $mb-font-xs;
  color: $mb-color-text;
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
  background-color: $mb-color-bg;
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
    $mb-color-bg-secondary 25%,
    #eef0f3 50%,
    $mb-color-bg-secondary 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite ease-in-out;
}

.skeleton-content {
  flex: 1;
  background-color: $mb-color-bg-secondary;
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
    $mb-color-bg 25%,
    #eef0f3 50%,
    $mb-color-bg 75%
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
    $mb-color-bg 25%,
    #eef0f3 50%,
    $mb-color-bg 75%
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
