<template>
  <view
    class="goods-list"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="商品列表" />

    <!-- Search bar -->
    <view class="goods-list__search" @tap="goSearch">
      <view class="goods-list__search-icon" />
      <text class="goods-list__search-text">
        {{ query.keyword || '搜索商品、品牌等...' }}
      </text>
    </view>

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
      <view class="goods-list__filter-action" @tap="openFilterSheet">
        <text class="goods-list__filter-text">筛选</text>
        <view class="goods-list__filter-arrow" />
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
        <view class="goods-card" @tap="goDetail(item.id)">
          <view class="goods-card__image-wrap">
            <image
              v-if="getGoodsCover(item)"
              class="goods-card__image"
              :src="getGoodsCover(item)"
              mode="aspectFill"
              lazy-load
            />
            <view v-else class="goods-card__image-placeholder">
              <view class="goods-card__placeholder-icon" />
            </view>
            <view
              v-if="showGoodsBadge && getGoodsBadge(item)"
              class="goods-card__badge"
              :style="goodsBadgeStyle"
            >
              <text class="goods-card__badge-text" :style="goodsBadgeTextStyle">
                {{ getGoodsBadge(item) }}
              </text>
            </view>
          </view>
          <view class="goods-card__body">
            <text class="goods-card__name">{{ item.name }}</text>
            <text v-if="showGoodsSubtitle" class="goods-card__sub">
              {{ getGoodsSubtitle(item) }}
            </text>
            <view class="goods-card__bottom">
              <view class="goods-card__price-main">
                <mb-price
                  :value="item.price"
                  size="md"
                  color="var(--color-primary, #0d50d5)"
                />
                <text
                  v-if="
                    showMarketPrice &&
                    item.market_price &&
                    Number(item.market_price) > Number(item.price)
                  "
                  class="goods-card__origin"
                >
                  ¥{{ Number(item.market_price).toFixed(0) }}
                </text>
              </view>
              <view
                v-if="showCartButton"
                class="goods-card__add"
                :class="{ 'goods-card__add--loading': addingGoodsId === item.id }"
                @tap.stop="quickAddCart(item)"
              >
                <text class="goods-card__add-symbol">+</text>
              </view>
            </view>
            <text v-if="showGoodsSales" class="goods-card__sales">
              {{ formatSales(item) }}
            </text>
          </view>
        </view>
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
        <view class="goods-row" @tap="goDetail(item.id)">
          <view class="goods-row__image-wrap">
            <image
              v-if="getGoodsCover(item)"
              class="goods-row__image"
              :src="getGoodsCover(item)"
              mode="aspectFill"
              lazy-load
            />
            <view v-else class="goods-row__image-placeholder">
              <view class="goods-card__placeholder-icon" />
            </view>
            <view
              v-if="showGoodsBadge && getGoodsBadge(item)"
              class="goods-card__badge"
              :style="goodsBadgeStyle"
            >
              <text class="goods-card__badge-text" :style="goodsBadgeTextStyle">
                {{ getGoodsBadge(item) }}
              </text>
            </view>
          </view>
          <view class="goods-row__body">
            <view class="goods-row__top">
              <text class="goods-row__name">{{ item.name }}</text>
              <text v-if="showGoodsSubtitle" class="goods-row__sub">
                {{ getGoodsSubtitle(item) }}
              </text>
            </view>
            <view class="goods-row__bottom">
              <view class="goods-row__price-main">
                <mb-price
                  :value="item.price"
                  size="md"
                  color="var(--color-primary, #0d50d5)"
                />
                <text v-if="showGoodsSales" class="goods-row__sales">
                  {{ formatSales(item) }}
                </text>
              </view>
              <view
                v-if="showCartButton"
                class="goods-card__add"
                :class="{ 'goods-card__add--loading': addingGoodsId === item.id }"
                @tap.stop="quickAddCart(item)"
              >
                <text class="goods-card__add-symbol">+</text>
              </view>
            </view>
          </view>
        </view>
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
      <mb-floating-action />
</view>
</template>

<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref, reactive } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { getGoodsDetail, getGoodsList } from '@/api/goods/goods'
import { useCartStore } from '@/store/cart'
import { requireLogin } from '@/utils/auth'
import { useAppStore } from '@/store/app'
import {
  getGoodsBadgeBoxStyle,
  getGoodsBadgeText,
  getGoodsBadgeTextStyle,
  normalizeGoodsBadgeConfig,
} from '@/utils/goods-badge'
const decorateStore = useDecorateStore()

const cartStore = useCartStore()
const appStore = useAppStore()

// ---------- query params ----------
const query = reactive({
  brand_id: '',
  category_id: '',
  ids: '',
  is_hot: '',
  is_new: '',
  is_recommend: '',
  keyword: '',
  sort_by: '',
  tag_id: '',
  tag_ids: '',
})

const queryValueKeys = [
  'category_id',
  'brand_id',
  'ids',
  'tag_id',
  'tag_ids',
  'keyword',
]
const queryFlagKeys = ['is_recommend', 'is_new', 'is_hot']

// ---------- sort state ----------
const sortOptions = [
  { key: 'default', label: '综合', field: '', order: '' },
  { key: 'sales', label: '销量', field: 'sales', order: 'desc' },
  { key: 'price', label: '价格', field: 'price', order: 'asc' },
]

const activeSortKey = ref('default')
const sortField = ref('')
const sortOrder = ref('')
const activeFilter = ref('')

// ---------- view mode ----------
const viewMode = ref('grid')

// ---------- list state ----------
const goodsList = ref([])
const page = ref(1)
const limit = 10
const loading = ref(false)
const initialLoading = ref(true)
const noMore = ref(false)
const addingGoodsId = ref(null)

const showCartButton = computed(() =>
  configFlag('client_goods_card_show_cart_button', true),
)
const showGoodsSales = computed(() =>
  configFlag('client_goods_card_show_sales', true),
)
const showMarketPrice = computed(() =>
  configFlag('client_goods_card_show_market_price', true),
)
const showGoodsSubtitle = computed(() =>
  configFlag('client_goods_card_show_subtitle', true),
)
const showGoodsBadge = computed(() =>
  configFlag('client_goods_card_show_badge', true),
)
const goodsBadgeConfig = computed(() =>
  normalizeGoodsBadgeConfig(appStore.siteConfig?.client_goods_badge_config),
)
const goodsBadgeStyle = computed(() => {
  return getGoodsBadgeBoxStyle(goodsBadgeConfig.value)
})
const goodsBadgeTextStyle = computed(() => {
  return getGoodsBadgeTextStyle(goodsBadgeConfig.value)
})

// ---------- lifecycle ----------
onLoad((params) => {
  syncQueryFromParams(params || {})
  applyInitialSort(query.sort_by)
  applyInitialFilter()
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

  queryValueKeys.forEach((key) => {
    if (query[key]) {
      params[key] = query[key]
    }
  })
  queryFlagKeys.forEach((key) => {
    if (isEnabledFlag(query[key])) {
      params[key] = 1
    }
  })
  if (activeFilter.value) {
    params[activeFilter.value] = 1
  }
  const sortBy = getSortBy() || query.sort_by
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
  query.sort_by = ''
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

function syncQueryFromParams(params) {
  const queryKeys = [...queryValueKeys, ...queryFlagKeys, 'sort_by']
  queryKeys.forEach((key) => {
    if (params?.[key] !== undefined && params[key] !== null) {
      query[key] = String(params[key])
    }
  })
}

function applyInitialSort(sortBy) {
  if (sortBy === 'sales_desc') {
    activeSortKey.value = 'sales'
    sortField.value = 'sales'
    sortOrder.value = 'desc'
    return
  }
  if (sortBy === 'price_asc' || sortBy === 'price_desc') {
    activeSortKey.value = 'price'
    sortField.value = 'price'
    sortOrder.value = sortBy === 'price_desc' ? 'desc' : 'asc'
    return
  }
  activeSortKey.value = 'default'
  sortField.value = ''
  sortOrder.value = ''
}

function applyInitialFilter() {
  activeFilter.value =
    queryFlagKeys.find((key) => isEnabledFlag(query[key])) || ''
}

function isEnabledFlag(value) {
  return value === true || value === 1 || value === '1' || value === 'true'
}

function configFlag(code, fallback = true) {
  const value = appStore.siteConfig?.[code]
  if (value === undefined || value === null || value === '') return fallback
  return isEnabledFlag(value)
}

function clearQueryFlags() {
  queryFlagKeys.forEach((key) => {
    query[key] = ''
  })
}

// ---------- filters ----------
function openFilterSheet() {
  const nextViewText = viewMode.value === 'grid' ? '切换列表展示' : '切换双列展示'
  const options = [
    { label: '全部商品', filter: '' },
    { label: '推荐商品', filter: 'is_recommend' },
    { label: '新品上架', filter: 'is_new' },
    { label: '热卖商品', filter: 'is_hot' },
    { label: nextViewText, action: 'toggle_view' },
  ]

  uni.showActionSheet({
    itemList: options.map((item) => item.label),
    success(res) {
      const option = options[res.tapIndex]
      if (!option) return

      if (option.action === 'toggle_view') {
        viewMode.value = viewMode.value === 'grid' ? 'list' : 'grid'
        return
      }

      clearQueryFlags()
      activeFilter.value = option.filter
      if (activeFilter.value) {
        query[activeFilter.value] = '1'
      }
      fetchGoods(true)
    },
  })
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

function getGoodsSubtitle(item) {
  return item.subtitle || item.description || item.category_name || '精选品质好物'
}

function getGoodsBadge(item) {
  return getGoodsBadgeText(item, goodsBadgeConfig.value)
}

function formatSales(item) {
  const sales = Number(item.sales || item.sales_count || item.virtual_sales || 0)
  if (sales >= 10000) return `月销 ${(sales / 10000).toFixed(1)}万+`
  if (sales > 0) return `月销 ${sales}+`
  return '月销 200+'
}

function getDirectSkuId(item) {
  if (item.sku_id) return item.sku_id
  if (item.default_sku_id) return item.default_sku_id
  if (Array.isArray(item.skus) && item.skus.length === 1) return item.skus[0].id
  return ''
}

async function quickAddCart(item) {
  if (!requireLogin(`/pages-sub/goods/list${buildCurrentQuery()}`)) return
  if (addingGoodsId.value) return

  addingGoodsId.value = item.id
  try {
    let skuId = getDirectSkuId(item)

    if (!skuId) {
      const detail = await getGoodsDetail(item.id)
      const goods = detail?.data ?? detail ?? {}
      const skus = Array.isArray(goods.skus) ? goods.skus : []
      if (skus.length === 1) {
        skuId = skus[0].id
      }
    }

    if (!skuId) {
      uni.showToast({ title: '请选择规格', icon: 'none' })
      setTimeout(() => goDetail(item.id), 500)
      return
    }

    await cartStore.add(skuId, 1)
    uni.showToast({ title: '已加入购物车', icon: 'success' })
  } catch {
    uni.showToast({ title: '加入失败，请重试', icon: 'none' })
  } finally {
    addingGoodsId.value = null
  }
}

// ---------- navigation ----------
function goDetail(id) {
  uni.navigateTo({
    url: `/pages-sub/goods/detail?id=${id}`,
  })
}

function goSearch() {
  uni.navigateTo({ url: '/pages-sub/search/index' })
}

function buildCurrentQuery() {
  const params = []
  const queryKeys = [...queryValueKeys, ...queryFlagKeys]
  queryKeys.forEach((key) => {
    if (query[key]) {
      params.push(`${key}=${encodeURIComponent(query[key])}`)
    }
  })
  const sortBy = getSortBy() || query.sort_by
  if (sortBy) {
    params.push(`sort_by=${encodeURIComponent(sortBy)}`)
  }
  return params.length > 0 ? `?${params.join('&')}` : ''
}
</script>

<style lang="scss" scoped>
.goods-list {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

// ---------- Search ----------
.goods-list__search {
  margin: $mb-spacing-sm $mb-spacing-page 0;
  height: 64rpx;
  border-radius: $mb-radius-sm;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  align-items: center;
  padding: 0 $mb-spacing-md;
  gap: $mb-spacing-sm;
}

.goods-list__search-icon {
  width: 24rpx;
  height: 24rpx;
  border: 3rpx solid var(--color-text-tertiary, #737686);
  border-radius: 50%;
  position: relative;
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

.goods-list__search-text {
  flex: 1;
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

// ---------- Filter bar ----------
.goods-list__filter-bar {
  position: sticky;
  top: 0;
  z-index: 90;
  display: flex;
  align-items: center;
  background: var(--color-bg-secondary, #faf8ff);
  border-bottom: 0;
  padding: 0 $mb-spacing-page;
}

.goods-list__sort-scroll {
  flex: 1;
  white-space: nowrap;
  min-width: 0;
}

.goods-list__sort-group {
  display: inline-flex;
  align-items: center;
  padding: 0;
  gap: $mb-spacing-md;
}

.goods-list__sort-item {
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 5rpx;
  margin: 18rpx 0 14rpx;
  padding: 0;
  border-radius: 0;
  background: transparent;
  flex-shrink: 0;
}

.goods-list__sort-text {
  font-size: $mb-font-md;
  color: var(--color-text-secondary, #434654);
  font-weight: 500;
  transition: color 0.2s;
}

.goods-list__sort-item--active .goods-list__sort-text {
  color: var(--color-primary, #0d50d5);
  font-weight: 600;
}

.goods-list__sort-item--active {
  background: transparent;
}

.goods-list__sort-indicator {
  position: absolute;
  left: 50%;
  bottom: -12rpx;
  width: 28rpx;
  height: 4rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary, #0d50d5);
  transform: translateX(-50%);
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
  border-bottom: 10rpx solid var(--color-text-tertiary, #737686);
}

.goods-list__arrow--down {
  border-top: 10rpx solid var(--color-text-tertiary, #737686);
}

.goods-list__arrow--active {
  &.goods-list__arrow--up {
    border-bottom-color: var(--color-primary, #0d50d5);
  }

  &.goods-list__arrow--down {
    border-top-color: var(--color-primary, #0d50d5);
  }
}

.goods-list__filter-action {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8rpx;
  height: 72rpx;
  padding-left: $mb-spacing-md;
}

.goods-list__filter-text {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
  font-weight: 500;
}

.goods-list__filter-arrow {
  width: 10rpx;
  height: 10rpx;
  border-right: 2rpx solid var(--color-text-tertiary, #737686);
  border-bottom: 2rpx solid var(--color-text-tertiary, #737686);
  transform: rotate(45deg);
  margin-top: -4rpx;
}

.goods-list__filter-action:active,
.goods-list__sort-item:active,
.goods-card__add:active {
  opacity: 0.75;
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
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-lg;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  overflow: hidden;
}

// ---------- Grid mode ----------
.goods-list__grid {
  display: flex;
  flex-wrap: wrap;
  gap: $mb-spacing-sm;
  padding: 0 $mb-spacing-page $mb-spacing-page;
  padding-top: $mb-spacing-sm;
}

.goods-list__grid-item {
  width: calc(50% - 8rpx);
}

// ---------- Design-card grid ----------
.goods-card {
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-sm;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  overflow: hidden;
  min-height: 100%;
}

.goods-card__image-wrap {
  position: relative;
  width: 100%;
  aspect-ratio: 1 / 1;
  background: #f2f4f8;
  overflow: hidden;
}

.goods-card__image,
.goods-card__image-placeholder {
  width: 100%;
  height: 100%;
}

.goods-card__image-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f3f3fe;
}

.goods-card__placeholder-icon {
  width: 52rpx;
  height: 42rpx;
  border: 4rpx solid var(--color-primary, #0d50d5);
  border-radius: $mb-radius-sm;
  position: relative;
  opacity: 0.8;

  &::after {
    content: '';
    position: absolute;
    left: 12rpx;
    right: 12rpx;
    top: 12rpx;
    height: 4rpx;
    border-radius: $mb-radius-full;
    background: var(--color-primary, #0d50d5);
  }
}

.goods-card__badge {
  position: absolute;
  left: 8rpx;
  top: 8rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary, #0d50d5);
}

.goods-card__badge-text {
  font-size: 18rpx;
  color: var(--color-text-inverse, #ffffff);
  font-weight: 700;
  line-height: 1;
}

.goods-card__body {
  padding: 12rpx 12rpx 14rpx;
}

.goods-card__name {
  display: -webkit-box;
  font-size: 22rpx;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  line-height: 1.35;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}

.goods-card__sub {
  display: block;
  margin-top: 4rpx;
  font-size: 19rpx;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.3;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-card__bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8rpx;
  margin-top: 8rpx;
}

.goods-card__price-main {
  min-width: 0;
  display: flex;
  align-items: baseline;
  gap: 6rpx;
}

.goods-card__origin {
  font-size: 18rpx;
  color: var(--color-text-tertiary, #737686);
  text-decoration: line-through;
}

.goods-card__add {
  width: 32rpx;
  height: 32rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.goods-card__add--loading {
  opacity: 0.45;
}

.goods-card__add-symbol {
  font-size: 26rpx;
  line-height: 1;
  color: var(--color-text-inverse, #ffffff);
  font-weight: 600;
  margin-top: -2rpx;
}

.goods-card__sales {
  display: block;
  margin-top: 5rpx;
  font-size: 18rpx;
  color: var(--color-text-tertiary, #737686);
  line-height: 1.3;
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

.goods-row {
  display: flex;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-sm;
}

.goods-row__image-wrap,
.goods-row__image,
.goods-row__image-placeholder {
  width: 164rpx;
  height: 164rpx;
  border-radius: $mb-radius-sm;
  flex-shrink: 0;
}

.goods-row__image-wrap {
  position: relative;
  overflow: hidden;
  background: #f3f3fe;
}

.goods-row__image-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.goods-row__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.goods-row__top {
  min-width: 0;
}

.goods-row__name {
  display: -webkit-box;
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
  line-height: 1.4;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
}

.goods-row__sub {
  display: block;
  margin-top: 6rpx;
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.goods-row__bottom {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: $mb-spacing-sm;
}

.goods-row__price-main {
  min-width: 0;
}

.goods-row__sales {
  display: block;
  margin-top: 4rpx;
  font-size: $mb-font-xs;
  color: var(--color-text-tertiary, #737686);
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
  border: 4rpx solid var(--color-divider, #f0f2f5);
  border-top-color: var(--color-primary, #0d50d5);
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
  color: var(--color-text-tertiary, #737686);
}

.goods-list__no-more {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
}
</style>
