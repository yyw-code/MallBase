<template>
  <view class="goods-list">
    <mb-navbar title="商品列表" />

    <!-- Filter bar -->
    <view class="goods-list__filter-bar">
      <scroll-view scroll-x class="goods-list__sort-scroll" :show-scrollbar="false">
        <view class="goods-list__sort-group">
          <view
            v-for="item in sortOptions"
            :key="item.key"
            class="goods-list__sort-item"
            :class="{ 'goods-list__sort-item--active': activeSortKey === item.key }"
            @tap="onSortTap(item)"
          >
            <text class="goods-list__sort-text">{{ item.label }}</text>
            <view
              v-if="item.key === 'price'"
              class="goods-list__sort-arrows"
            >
              <view
                class="goods-list__arrow goods-list__arrow--up"
                :class="{ 'goods-list__arrow--active': activeSortKey === 'price' && sortOrder === 'asc' }"
              />
              <view
                class="goods-list__arrow goods-list__arrow--down"
                :class="{ 'goods-list__arrow--active': activeSortKey === 'price' && sortOrder === 'desc' }"
              />
            </view>
            <view
              v-if="activeSortKey === item.key"
              class="goods-list__sort-indicator"
            />
          </view>
        </view>
      </scroll-view>
      <view class="goods-list__view-toggle" @tap="toggleViewMode">
        <view v-if="viewMode === 'grid'" class="goods-list__icon-list">
          <view class="goods-list__icon-bar" />
          <view class="goods-list__icon-bar" />
          <view class="goods-list__icon-bar" />
        </view>
        <view v-else class="goods-list__icon-grid">
          <view class="goods-list__icon-cell" />
          <view class="goods-list__icon-cell" />
          <view class="goods-list__icon-cell" />
          <view class="goods-list__icon-cell" />
        </view>
      </view>
    </view>

    <!-- Loading skeleton -->
    <view v-if="initialLoading" class="goods-list__skeleton">
      <view
        v-for="n in 4"
        :key="n"
        class="goods-list__skeleton-card"
      >
        <mb-skeleton type="card" />
      </view>
    </view>

    <!-- Empty state -->
    <mb-empty-state
      v-else-if="!initialLoading && goodsList.length === 0"
      icon=""
      text="暂无商品"
    />

    <!-- Goods grid -->
    <view
      v-else-if="viewMode === 'grid'"
      class="goods-list__grid"
    >
      <view
        v-for="item in goodsList"
        :key="item.id"
        class="goods-list__grid-item"
      >
        <mb-product-card
          :goods="normalizeGoods(item)"
          mode="grid"
          @tap="goDetail(item.id)"
        />
      </view>
    </view>

    <!-- Goods list -->
    <view
      v-else
      class="goods-list__rows"
    >
      <view
        v-for="item in goodsList"
        :key="item.id"
        class="goods-list__row-item"
      >
        <mb-product-card
          :goods="normalizeGoods(item)"
          mode="list"
          @tap="goDetail(item.id)"
        />
      </view>
    </view>

    <!-- Bottom status -->
    <view v-if="!initialLoading && goodsList.length > 0" class="goods-list__bottom">
      <view v-if="loading" class="goods-list__loading-indicator">
        <view class="goods-list__spinner" />
        <text class="goods-list__loading-text">加载中...</text>
      </view>
      <text v-else-if="noMore" class="goods-list__no-more">没有更多了</text>
    </view>
  </view>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { getGoodsList } from '@/api/goods/goods'

// ---------- query params ----------
const query = reactive({
  category_id: '',
  keyword: '',
})

// ---------- sort state ----------
const sortOptions = [
  { key: 'default', label: '综合', field: '', order: '' },
  { key: 'sales', label: '销量', field: 'sales', order: 'desc' },
  { key: 'price', label: '价格', field: 'price', order: 'asc' },
]

const activeSortKey = ref('default')
const sortField = ref('')
const sortOrder = ref('')

// ---------- view mode ----------
const viewMode = ref('grid')

// ---------- list state ----------
const goodsList = ref([])
const page = ref(1)
const limit = 10
const loading = ref(false)
const initialLoading = ref(true)
const noMore = ref(false)

// ---------- lifecycle ----------
onLoad((params) => {
  if (params?.category_id) {
    query.category_id = params.category_id
  }
  if (params?.keyword) {
    query.keyword = params.keyword
  }
  fetchGoods(true)
})

onPullDownRefresh(async () => {
  await fetchGoods(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  if (!loading.value && !noMore.value) {
    fetchGoods(false)
  }
})

// ---------- data fetching ----------
async function fetchGoods(reset) {
  if (loading.value) return
  if (!reset && noMore.value) return

  loading.value = true

  if (reset) {
    page.value = 1
    noMore.value = false
  }

  const params = {
    page: page.value,
    limit,
  }

  if (query.category_id) {
    params.category_id = query.category_id
  }
  if (query.keyword) {
    params.keyword = query.keyword
  }
  const sortBy = getSortBy()
  if (sortBy) {
    params.sort_by = sortBy
  }

  try {
    const data = await getGoodsList(params)
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
    if (reset) {
      goodsList.value = []
    }
  } finally {
    loading.value = false
    initialLoading.value = false
  }
}

// ---------- sort ----------
function onSortTap(item) {
  if (item.key === 'price') {
    if (activeSortKey.value === 'price') {
      sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
    } else {
      activeSortKey.value = 'price'
      sortField.value = 'price'
      sortOrder.value = 'asc'
    }
  } else {
    activeSortKey.value = item.key
    sortField.value = item.field
    sortOrder.value = item.order
  }
  fetchGoods(true)
}

function getSortBy() {
  if (activeSortKey.value === 'sales') return 'sales_desc'
  if (activeSortKey.value === 'price') {
    return sortOrder.value === 'desc' ? 'price_desc' : 'price_asc'
  }
  return ''
}

// ---------- view toggle ----------
function toggleViewMode() {
  viewMode.value = viewMode.value === 'grid' ? 'list' : 'grid'
}

// ---------- normalize goods for card component ----------
function normalizeGoods(item) {
  return {
    ...item,
    cover: getGoodsCover(item),
    original_price: item.market_price,
  }
}

function getGoodsCover(item) {
  if (item.main_image_full_url) return item.main_image_full_url
  if (item.main_image) return item.main_image
  if (item.cover) return item.cover
  if (Array.isArray(item.images) && item.images.length > 0) {
    const first = item.images[0]
    if (typeof first === 'string') return first
    return first.full_url || first.url || ''
  }
  return ''
}

// ---------- navigation ----------
function goDetail(id) {
  uni.navigateTo({
    url: `/pages-sub/goods/detail?id=${id}`,
  })
}
</script>

<style lang="scss" scoped>
.goods-list {
  min-height: 100vh;
  background: $mb-color-bg-secondary;
}

// ---------- Filter bar ----------
.goods-list__filter-bar {
  position: sticky;
  top: 0;
  z-index: 90;
  display: flex;
  align-items: center;
  background: $mb-color-bg;
  border-bottom: 1rpx solid $mb-color-divider;
  padding-right: 0;
}

.goods-list__sort-scroll {
  flex: 1;
  white-space: nowrap;
  min-width: 0;
}

.goods-list__sort-group {
  display: inline-flex;
  align-items: center;
  padding: 0 $mb-spacing-sm;
  gap: $mb-spacing-xs;
}

.goods-list__sort-item {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 6rpx;
  padding: $mb-spacing-md $mb-spacing-sm;
  flex-shrink: 0;
}

.goods-list__sort-text {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  font-weight: 500;
  transition: color 0.2s;
}

.goods-list__sort-item--active .goods-list__sort-text {
  color: $mb-color-primary;
  font-weight: 600;
}

.goods-list__sort-indicator {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 40rpx;
  height: 4rpx;
  border-radius: 2rpx;
  background: $mb-color-primary;
}

// ---------- Price sort arrows ----------
.goods-list__sort-arrows {
  display: flex;
  flex-direction: column;
  gap: 4rpx;
  margin-left: 2rpx;
}

.goods-list__arrow {
  width: 0;
  height: 0;
  border-left: 8rpx solid transparent;
  border-right: 8rpx solid transparent;
}

.goods-list__arrow--up {
  border-bottom: 10rpx solid $mb-color-text-tertiary;
}

.goods-list__arrow--down {
  border-top: 10rpx solid $mb-color-text-tertiary;
}

.goods-list__arrow--active {
  &.goods-list__arrow--up {
    border-bottom-color: $mb-color-primary;
  }

  &.goods-list__arrow--down {
    border-top-color: $mb-color-primary;
  }
}

// ---------- View toggle ----------
.goods-list__view-toggle {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 88rpx;
  height: 88rpx;
  border-left: 1rpx solid $mb-color-divider;
}

// List icon (3 horizontal bars)
.goods-list__icon-list {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  width: 36rpx;
}

.goods-list__icon-bar {
  width: 100%;
  height: 4rpx;
  border-radius: 2rpx;
  background: $mb-color-text-secondary;
}

// Grid icon (2x2 cells)
.goods-list__icon-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 4rpx;
  width: 32rpx;
  height: 32rpx;
}

.goods-list__icon-cell {
  width: 14rpx;
  height: 14rpx;
  border-radius: 3rpx;
  background: $mb-color-text-secondary;
}

// ---------- Skeleton ----------
.goods-list__skeleton {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
  padding: $mb-spacing-page;
}

.goods-list__skeleton-card {
  width: calc(50% - 8rpx);
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  overflow: hidden;
}

// ---------- Grid mode ----------
.goods-list__grid {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
  padding: $mb-spacing-page;
  padding-top: $mb-spacing-sm;
}

.goods-list__grid-item {
  width: calc(50% - 8rpx);
}

// ---------- List mode ----------
.goods-list__rows {
  padding: $mb-spacing-sm $mb-spacing-page;
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-sm;
}

.goods-list__row-item {
  width: 100%;
}

// ---------- Bottom status ----------
.goods-list__bottom {
  padding: $mb-spacing-lg 0 $mb-spacing-xl;
  display: flex;
  justify-content: center;
  align-items: center;
}

.goods-list__loading-indicator {
  display: flex;
  align-items: center;
  gap: $mb-spacing-xs;
}

.goods-list__spinner {
  width: 32rpx;
  height: 32rpx;
  border: 4rpx solid $mb-color-divider;
  border-top-color: $mb-color-primary;
  border-radius: 50%;
  animation: goods-spin 0.7s linear infinite;
}

@keyframes goods-spin {
  to {
    transform: rotate(360deg);
  }
}

.goods-list__loading-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

.goods-list__no-more {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}
</style>
