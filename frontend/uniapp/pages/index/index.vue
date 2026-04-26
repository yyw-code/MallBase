<script setup>
import { ref, computed, onMounted } from 'vue'
import { onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { getGoodsList, getGoodsRecommend } from '@/api/goods/goods'
import { useAppStore } from '@/store/app'

const appStore = useAppStore()
const brandName = computed(() => appStore.siteConfig?.site_name || '商城')

// ---------- system info ----------
const systemInfo = uni.getSystemInfoSync()
const statusBarHeight = systemInfo.statusBarHeight || 0

// ---------- state ----------
const recommendList = ref([])
const goodsList = ref([])
const page = ref(1)
const limit = 10
const loading = ref(false)
const noMore = ref(false)
const refreshing = ref(false)

// ---------- static data ----------
const categoryTabs = [
  { label: '精品', query: '?tag=recommend' },
  { label: '热门', query: '?sort=sales' },
  { label: '活动', query: '?tag=promo' },
  { label: '新品', query: '?tag=new' },
]

// ---------- banners ----------
const banners = computed(() => {
  const raw = appStore.siteConfig?.client_home_banners
  if (Array.isArray(raw) && raw.length > 0) return raw
  return []
})
const hasBanners = computed(() => banners.value.length > 0)
const swiperCurrent = ref(0)

// ---------- computed ----------
const bentoGroups = computed(() => {
  const groups = []
  for (let i = 0; i < goodsList.value.length; i += 3) {
    groups.push({
      items: goodsList.value.slice(i, i + 3),
      reversed: (i / 3) % 2 === 1,
    })
  }
  return groups
})

// ---------- data fetching ----------
async function fetchRecommend() {
  try {
    const data = await getGoodsRecommend(6)
    recommendList.value = Array.isArray(data) ? data : []
  } catch {
    recommendList.value = []
  }
}

async function fetchGoodsList(reset = false) {
  if (loading.value) return
  if (!reset && noMore.value) return

  loading.value = true

  if (reset) {
    page.value = 1
    noMore.value = false
  }

  try {
    const data = await getGoodsList({ page: page.value, limit })
    const list = Array.isArray(data?.list)
      ? data.list
      : (Array.isArray(data) ? data : [])

    if (reset) {
      goodsList.value = list
    } else {
      goodsList.value = [...goodsList.value, ...list]
    }

    if (list.length < limit) {
      noMore.value = true
    } else {
      page.value += 1
    }
  } catch {
    if (reset) goodsList.value = []
  } finally {
    loading.value = false
  }
}

async function refresh() {
  refreshing.value = true
  await Promise.all([fetchRecommend(), fetchGoodsList(true)])
  refreshing.value = false
}

// ---------- lifecycle ----------
onMounted(() => {
  refresh()
})

onPullDownRefresh(async () => {
  await refresh()
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  fetchGoodsList(false)
})

// ---------- helpers ----------
function formatPrice(price) {
  const num = Number(price)
  if (Number.isNaN(num)) return '0'
  return num.toLocaleString('zh-CN', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  })
}

function getFirstImage(item) {
  if (item.cover) return item.cover
  if (Array.isArray(item.images) && item.images.length > 0) return item.images[0]
  return ''
}

// ---------- navigation ----------
function goSearch() {
  uni.navigateTo({ url: '/pages-sub/search/index' })
}

function goGoodsDetail(id) {
  uni.navigateTo({ url: `/pages-sub/goods/detail?id=${id}` })
}

function goGoodsList(query = '') {
  uni.navigateTo({ url: `/pages-sub/goods/list${query}` })
}

function onTabTap(tab) {
  goGoodsList(tab.query)
}
</script>

<template>
  <view class="page">
    <!-- ========== Floating Top App Bar ========== -->
    <view
      class="top-bar"
      :style="{ top: statusBarHeight + 8 + 'px' }"
    >
      <view class="top-bar__inner">
        <text class="top-bar__brand">{{ brandName }}</text>

        <view class="top-bar__search" @tap="goSearch">
          <text class="top-bar__search-icon">&#x1F50D;</text>
          <text class="top-bar__search-text">搜索商品</text>
        </view>

        <text
          class="top-bar__cart-icon"
          @tap="() => uni.switchTab({ url: '/pages/cart/index' })"
        >&#x1F6CD;&#xFE0F;</text>
      </view>
    </view>

    <!-- ========== Main Content ========== -->
    <view class="main" :style="{ paddingTop: statusBarHeight + 68 + 'px' }">

      <!-- Banner Swiper (real data from siteConfig) -->
      <swiper
        v-if="hasBanners"
        class="banner-swiper"
        :autoplay="true"
        :interval="4000"
        :duration="500"
        :circular="true"
        :indicator-dots="banners.length > 1"
        indicator-color="rgba(255,255,255,0.4)"
        indicator-active-color="#ffffff"
        @change="e => swiperCurrent = e.detail.current"
      >
        <swiper-item
          v-for="(src, idx) in banners"
          :key="idx"
        >
          <view class="banner" @tap="goGoodsList()">
            <image
              class="banner__image"
              :src="src"
              mode="aspectFill"
            />
          </view>
        </swiper-item>
      </swiper>

      <!-- Banner Fallback (no remote banners) -->
      <view v-else class="banner banner--placeholder">
        <view class="banner__gradient" />
        <view class="banner__overlay">
          <text class="banner__title">{{ brandName }}</text>
          <text class="banner__subtitle">{{ appStore.siteConfig?.site_slogan || '发现好物，品质生活' }}</text>
        </view>
      </view>

      <!-- Category Tabs -->
      <scroll-view scroll-x class="category-tabs" :show-scrollbar="false">
        <view class="category-tabs__track">
          <view
            v-for="tab in categoryTabs"
            :key="tab.label"
            class="category-tab"
            @tap="onTabTap(tab)"
          >
            <text class="category-tab__text">{{ tab.label }}</text>
          </view>
        </view>
      </scroll-view>

      <!-- Recommend Section -->
      <view v-if="recommendList.length > 0" class="section">
        <view class="section__header">
          <text class="section__title">精品推荐</text>
          <text class="section__more" @tap="goGoodsList('?tag=recommend')">查看全部</text>
        </view>

        <scroll-view scroll-x class="recommend-scroll" :show-scrollbar="false">
          <view class="recommend-scroll__track">
            <view
              v-for="item in recommendList"
              :key="item.id"
              class="recommend-card"
              @tap="goGoodsDetail(item.id)"
            >
              <view class="recommend-card__img-wrap">
                <image
                  class="recommend-card__img"
                  :src="getFirstImage(item)"
                  mode="aspectFill"
                  lazy-load
                />
              </view>
              <text class="recommend-card__name">{{ item.name }}</text>
              <text class="recommend-card__price">{{ '¥' }}{{ formatPrice(item.price) }}</text>
            </view>
          </view>
        </scroll-view>
      </view>

      <!-- Bento Section ("猜你喜欢") -->
      <view v-if="goodsList.length > 0" class="section">
        <view class="section__header">
          <text class="section__title">猜你喜欢</text>
        </view>

        <view class="bento">
          <view
            v-for="(group, gi) in bentoGroups"
            :key="gi"
            class="bento-row"
            :class="{ 'bento-row--reversed': group.reversed }"
          >
            <!-- Main (large) card -->
            <view
              class="bento-card bento-card--main"
              @tap="goGoodsDetail(group.items[0].id)"
            >
              <image
                class="bento-card__img"
                :src="getFirstImage(group.items[0])"
                mode="aspectFill"
                lazy-load
              />
              <view class="bento-card__info">
                <text class="bento-card__name">{{ group.items[0].name }}</text>
                <text class="bento-card__price">{{ '¥' }}{{ formatPrice(group.items[0].price) }}</text>
              </view>
            </view>

            <!-- Side column (2 small cards) -->
            <view class="bento-col">
              <view
                v-if="group.items[1]"
                class="bento-card bento-card--small"
                @tap="goGoodsDetail(group.items[1].id)"
              >
                <image
                  class="bento-card__img"
                  :src="getFirstImage(group.items[1])"
                  mode="aspectFill"
                  lazy-load
                />
                <view class="bento-card__info">
                  <text class="bento-card__name">{{ group.items[1].name }}</text>
                  <text class="bento-card__price">{{ '¥' }}{{ formatPrice(group.items[1].price) }}</text>
                </view>
              </view>

              <view
                v-if="group.items[2]"
                class="bento-card bento-card--small"
                @tap="goGoodsDetail(group.items[2].id)"
              >
                <image
                  class="bento-card__img"
                  :src="getFirstImage(group.items[2])"
                  mode="aspectFill"
                  lazy-load
                />
                <view class="bento-card__info">
                  <text class="bento-card__name">{{ group.items[2].name }}</text>
                  <text class="bento-card__price">{{ '¥' }}{{ formatPrice(group.items[2].price) }}</text>
                </view>
              </view>
            </view>
          </view>
        </view>
      </view>

      <!-- Loading / No More indicator -->
      <view v-if="goodsList.length > 0" class="load-state">
        <text v-if="loading" class="load-state__text">加载中...</text>
        <view v-else-if="noMore" class="load-state__divider">
          <view class="load-state__line" />
          <text class="load-state__text">已经到底了</text>
          <view class="load-state__line" />
        </view>
      </view>

      <!-- Empty state (before any data arrives) -->
      <view
        v-if="!loading && !refreshing && goodsList.length === 0 && recommendList.length === 0"
        class="empty-state"
      >
        <text class="empty-state__icon">&#x1F6D2;</text>
        <text class="empty-state__text">暂无商品</text>
      </view>

      <!-- Bottom safe area padding for native tabBar -->
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
  background-color: var(--color-bg-secondary, #f7f9fb);
}

/* ===========================
   Floating Top App Bar
   =========================== */
.top-bar {
  position: fixed;
  left: 16rpx;
  right: 16rpx;
  z-index: 999;
}

.top-bar__inner {
  display: flex;
  align-items: center;
  height: 96rpx;
  padding: 0 32rpx;
  background-color: rgba(255, 255, 255, 0.80);
  backdrop-filter: blur(48rpx);
  -webkit-backdrop-filter: blur(48rpx);
  border-radius: 999rpx;
  box-shadow: 0 16rpx 60rpx rgba(15, 23, 42, 0.04);
}

.top-bar__brand {
  font-size: 30rpx;
  font-weight: 700;
  color: var(--color-text, #1b1b1b);
  flex-shrink: 0;
}

.top-bar__search {
  flex: 1;
  display: flex;
  align-items: center;
  height: 64rpx;
  margin: 0 24rpx;
  padding: 0 24rpx;
  background-color: rgba(241, 243, 245, 0.50);
  border-radius: 999rpx;
  overflow: hidden;
}

.top-bar__search-icon {
  font-size: 24rpx;
  margin-right: 12rpx;
  flex-shrink: 0;
}

.top-bar__search-text {
  font-size: 26rpx;
  color: var(--color-text-tertiary, #848484);
  white-space: nowrap;
}

.top-bar__cart-icon {
  font-size: 36rpx;
  flex-shrink: 0;
  padding-left: 4rpx;
}

/* ===========================
   Main Content Area
   =========================== */
.main {
  padding: 0 24rpx;
}

/* ===========================
   Banner Swiper
   =========================== */
.banner-swiper {
  width: 100%;
  height: 0;
  padding-bottom: 48%;
  border-radius: 40rpx;
  overflow: hidden;
}

/* ===========================
   Banner
   =========================== */
.banner {
  position: relative;
  width: 100%;
  height: 100%;
  border-radius: 40rpx;
  overflow: hidden;
  box-shadow: 0 4rpx 24rpx rgba(0, 0, 0, 0.06);
}

.banner--placeholder {
  height: 0;
  padding-bottom: 48%;
}

.banner__image {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}

.banner__gradient {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
}

.banner__overlay {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  padding: 60rpx 40rpx 36rpx;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.45), transparent);
  display: flex;
  flex-direction: column;
}

.banner__title {
  font-size: 48rpx;
  font-weight: 700;
  color: #ffffff;
  line-height: 1.2;
}

.banner__subtitle {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.80);
  margin-top: 8rpx;
  line-height: 1.5;
}

/* ===========================
   Category Tabs
   =========================== */
.category-tabs {
  white-space: nowrap;
  padding-top: 32rpx;
}

.category-tabs__track {
  display: inline-flex;
  gap: 16rpx;
  padding: 0 8rpx;
}

.category-tab {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 72rpx;
  padding: 0 40rpx;
  border-radius: 999rpx;
  background-color: #ffffff;
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.03);
  transition: background-color 0.2s;

  &:active {
    background-color: var(--color-text, #1b1b1b);

    .category-tab__text {
      color: #ffffff;
    }
  }
}

.category-tab__text {
  font-size: 28rpx;
  font-weight: 600;
  color: var(--color-text, #1b1b1b);
  letter-spacing: 2rpx;
}

/* ===========================
   Section Generic
   =========================== */
.section {
  margin-top: 48rpx;
}

.section__header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding: 0 8rpx;
  margin-bottom: 24rpx;
}

.section__title {
  font-size: 40rpx;
  font-weight: 700;
  color: var(--color-text-title, #131b2e);
  line-height: 1.3;
}

.section__more {
  font-size: 22rpx;
  font-weight: 600;
  color: var(--color-primary, #0d50d5);
  letter-spacing: 2rpx;
}

/* ===========================
   Horizontal Recommend Scroll
   =========================== */
.recommend-scroll {
  white-space: nowrap;
  width: 100%;
}

.recommend-scroll__track {
  display: inline-flex;
  gap: 24rpx;
  padding: 0 8rpx 16rpx;
}

.recommend-card {
  flex-shrink: 0;
  width: 320rpx;
  display: inline-flex;
  flex-direction: column;
}

.recommend-card__img-wrap {
  width: 320rpx;
  height: 426rpx;
  border-radius: 32rpx;
  overflow: hidden;
  background-color: #eef0f3;
}

.recommend-card__img {
  width: 100%;
  height: 100%;
}

.recommend-card__name {
  font-size: 26rpx;
  font-weight: 500;
  color: var(--color-text, #1b1b1b);
  margin-top: 16rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.recommend-card__price {
  font-size: 26rpx;
  font-weight: 700;
  color: var(--color-text-title, #131b2e);
  margin-top: 6rpx;
}

/* ===========================
   Bento Layout
   =========================== */
.bento {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.bento-row {
  display: flex;
  gap: 16rpx;
  height: 560rpx;
}

.bento-row--reversed {
  flex-direction: row-reverse;
}

.bento-card {
  border-radius: 24rpx;
  overflow: hidden;
  background-color: #ffffff;
  display: flex;
  flex-direction: column;
}

.bento-card--main {
  flex: 1.3;
}

.bento-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.bento-card--small {
  flex: 1;
}

.bento-card__img {
  width: 100%;
  flex: 1;
  min-height: 0;
}

.bento-card__info {
  padding: 12rpx 16rpx 16rpx;
  flex-shrink: 0;
}

.bento-card__name {
  font-size: 24rpx;
  font-weight: 500;
  color: #45464d;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.4;
}

.bento-card__price {
  display: block;
  font-size: 28rpx;
  font-weight: 700;
  color: var(--color-text-title, #131b2e);
  margin-top: 4rpx;
}

/* ===========================
   Load State
   =========================== */
.load-state {
  padding: 48rpx 0 16rpx;
  display: flex;
  justify-content: center;
}

.load-state__text {
  font-size: 24rpx;
  color: var(--color-text-tertiary, #848484);
  padding: 0 24rpx;
}

.load-state__divider {
  display: flex;
  align-items: center;
  width: 60%;
}

.load-state__line {
  flex: 1;
  height: 1rpx;
  background-color: var(--color-border, #e0e3e5);
}

/* ===========================
   Empty State
   =========================== */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 240rpx 0 120rpx;
}

.empty-state__icon {
  font-size: 100rpx;
  margin-bottom: 24rpx;
}

.empty-state__text {
  font-size: 28rpx;
  color: var(--color-text-tertiary, #848484);
}

/* ===========================
   Bottom spacer (native tabBar)
   =========================== */
.bottom-spacer {
  height: 200rpx;
}
</style>
