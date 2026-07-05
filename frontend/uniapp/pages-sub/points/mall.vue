<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { getPointsMallGoodsList } from '@/api/points/mall'
import { isPointsEnabled, leavePointsPage } from '@/utils/points-feature'

const decorateStore = useDecorateStore()

const keyword = ref('')
const list = ref([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const finished = ref(false)
const pointsEnabled = ref(true)

onLoad(async () => {
  if (await ensurePointsEnabled()) {
    fetchList(true)
  }
})

onPullDownRefresh(async () => {
  if (await ensurePointsEnabled()) {
    await fetchList(true)
  }
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  if (!pointsEnabled.value || loading.value || finished.value) return
  fetchList(false)
})

async function ensurePointsEnabled() {
  pointsEnabled.value = await isPointsEnabled()
  if (!pointsEnabled.value) {
    leavePointsPage()
    return false
  }
  return true
}

async function fetchList(reset) {
  if (loading.value) return
  if (reset) {
    page.value = 1
    finished.value = false
  }

  loading.value = true
  try {
    const data = await getPointsMallGoodsList({
      keyword: keyword.value,
      page: page.value,
      limit: 10,
    })
    const rows = Array.isArray(data?.list) ? data.list : []
    total.value = Number(data?.total || rows.length || 0)
    list.value = reset ? rows : list.value.concat(rows)
    finished.value = list.value.length >= total.value || rows.length === 0
    if (!finished.value) page.value += 1
  } catch {
    if (reset) list.value = []
    finished.value = true
  } finally {
    loading.value = false
  }
}

function onSearch() {
  fetchList(true)
}

function clearSearch() {
  keyword.value = ''
  fetchList(true)
}

function goDetail(item) {
  uni.navigateTo({ url: `/pages-sub/points/mall-detail?id=${item.id}` })
}

function imageUrl(item) {
  return item.goods_image_full_url || item.goods_image || ''
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="积分商城" bg-color="var(--color-bg, #ffffff)" />

    <view class="search-capsule">
      <view class="search-capsule__field">
        <input
          v-model="keyword"
          class="search-capsule__input"
          confirm-type="search"
          placeholder="搜索积分商品"
          placeholder-class="search-capsule__placeholder"
          type="text"
          @confirm="onSearch"
        />
        <text
          v-if="keyword"
          class="search-capsule__clear"
          @tap.stop="clearSearch"
        >
          ×
        </text>
      </view>
      <mb-button
        class="search-capsule__button"
        type="primary"
        size="small"
        @click="onSearch"
      >
        搜索
      </mb-button>
    </view>

    <view v-if="list.length" class="goods-list">
      <view
        v-for="item in list"
        :key="item.id"
        class="goods-card"
        @tap="goDetail(item)"
      >
        <image
          class="goods-card__image"
          :src="imageUrl(item)"
          mode="aspectFill"
          lazy-load
        />
        <view class="goods-card__main">
          <text class="goods-card__name">{{ item.goods_name || '-' }}</text>
          <text v-if="item.goods_subtitle" class="goods-card__subtitle">
            {{ item.goods_subtitle }}
          </text>
          <text class="goods-card__spec">
            {{ item.sku_spec || '默认规格' }}
          </text>
          <view class="goods-card__bottom">
            <view class="points-price">
              <text class="points-price__value">{{ item.points_price }}</text>
              <text class="points-price__unit">积分</text>
            </view>
            <text class="goods-card__stock">
              库存 {{ item.available_stock || 0 }}
            </text>
          </view>
        </view>
      </view>

      <view class="load-state">
        <text class="load-state__text">
          {{ finished ? '没有更多商品了' : '加载中...' }}
        </text>
      </view>
    </view>

    <view v-else class="empty">
      <text class="empty__icon">P</text>
      <text class="empty__title">暂无积分商品</text>
      <text class="empty__desc">后台上架积分商品后会显示在这里</text>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 48rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.search-capsule {
  display: flex;
  align-items: center;
  gap: 10rpx;
  margin-top: $mb-spacing-md;
  padding: 10rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-full;
}

.search-capsule__field {
  display: flex;
  align-items: center;
  flex: 1;
  min-width: 0;
  height: 64rpx;
  padding: 0 8rpx 0 24rpx;
  background: var(--color-bg-surface, #f8fafc);
  border-radius: $mb-radius-full;
}

.search-capsule__input {
  flex: 1;
  min-width: 0;
  height: 64rpx;
  color: var(--color-text, #111827);
  font-size: 26rpx;
}

.search-capsule__placeholder,
.load-state__text,
.empty__desc,
.goods-card__subtitle,
.goods-card__spec,
.goods-card__stock {
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.search-capsule__clear {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 44rpx;
  height: 44rpx;
  color: var(--color-text-muted, #6b7280);
  font-size: 34rpx;
  line-height: 1;
}

.search-capsule__button {
  flex-shrink: 0;
  min-width: 96rpx;
}

.goods-list {
  margin-top: 20rpx;
}

.goods-card {
  display: flex;
  gap: 22rpx;
  padding: 22rpx;
  margin-bottom: 18rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.goods-card__image {
  flex-shrink: 0;
  width: 176rpx;
  height: 176rpx;
  background: var(--color-bg-surface, #f8fafc);
  border-radius: $mb-radius-md;
}

.goods-card__main {
  display: flex;
  flex: 1;
  min-width: 0;
  flex-direction: column;
}

.goods-card__name {
  color: var(--color-text, #111827);
  font-size: 30rpx;
  font-weight: 800;
  line-height: 1.35;
}

.goods-card__subtitle,
.goods-card__spec {
  display: block;
  margin-top: 8rpx;
}

.goods-card__bottom {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  margin-top: auto;
}

.points-price {
  display: flex;
  align-items: baseline;
  gap: 6rpx;
}

.points-price__value {
  color: var(--color-primary, #0d50d5);
  font-size: 38rpx;
  font-weight: 900;
}

.points-price__unit {
  color: var(--color-primary, #0d50d5);
  font-size: 24rpx;
  font-weight: 700;
}

.load-state {
  padding: 24rpx 0;
  text-align: center;
}

.empty {
  display: flex;
  align-items: center;
  flex-direction: column;
  margin-top: 140rpx;
  text-align: center;
}

.empty__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 92rpx;
  height: 92rpx;
  color: #ffffff;
  font-size: 40rpx;
  font-weight: 900;
  background: var(--color-primary, #0d50d5);
  border-radius: 50%;
}

.empty__title {
  margin-top: 22rpx;
  color: var(--color-text, #111827);
  font-size: 30rpx;
  font-weight: 800;
}

.empty__desc {
  margin-top: 8rpx;
}
</style>
