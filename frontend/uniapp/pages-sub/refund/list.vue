<script setup>
import { ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { getRefundList } from '@/api/order/refund'

const STATUS_CONFIG = {
  0: { label: '待审核', color: '#e08a00', bg: 'rgba(224, 138, 0, 0.08)' },
  1: { label: '已同意', color: '#25a350', bg: 'rgba(37, 163, 80, 0.08)' },
  2: { label: '已拒绝', color: '#ba1a1a', bg: 'rgba(186, 26, 26, 0.08)' },
  3: { label: '已退款', color: '#848484', bg: 'rgba(132, 132, 132, 0.08)' },
  4: { label: '已取消', color: '#848484', bg: 'rgba(132, 132, 132, 0.08)' },
}

const list = ref([])
const page = ref(1)
const limit = 10
const loading = ref(false)
const noMore = ref(false)
const initialized = ref(false)

onLoad(() => {
  fetchList(true)
})

onPullDownRefresh(async () => {
  await fetchList(true)
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  fetchList(false)
})

async function fetchList(reset = false) {
  if (loading.value) return
  if (!reset && noMore.value) return

  loading.value = true

  if (reset) {
    page.value = 1
    noMore.value = false
  }

  try {
    const data = await getRefundList({ page: page.value, limit })
    const items = Array.isArray(data?.list)
      ? data.list
      : (Array.isArray(data) ? data : [])

    if (reset) {
      list.value = items
    } else {
      list.value = [...list.value, ...items]
    }

    if (items.length < limit) {
      noMore.value = true
    } else {
      page.value += 1
    }
  } catch {
    if (reset) list.value = []
  } finally {
    loading.value = false
    initialized.value = true
  }
}

function getStatusConfig(status) {
  return STATUS_CONFIG[status] || { label: '未知', color: '#848484', bg: 'rgba(132, 132, 132, 0.08)' }
}

function formatPrice(val) {
  const num = Number(val)
  if (Number.isNaN(num)) return '0.00'
  return num.toFixed(2)
}

function goDetail(id) {
  uni.navigateTo({ url: `/pages-sub/refund/detail?id=${id}` })
}
</script>

<template>
  <view class="page">
    <!-- Refund list -->
    <view v-if="list.length > 0" class="list">
      <view
        v-for="item in list"
        :key="item.id"
        class="refund-card"
        @tap="goDetail(item.id)"
      >
        <!-- Card header -->
        <view class="refund-card__header">
          <text class="refund-card__sn">{{ item.refund_sn || item.sn || '-' }}</text>
          <view
            class="status-badge"
            :style="{
              color: getStatusConfig(item.status).color,
              background: getStatusConfig(item.status).bg,
            }"
          >
            <text class="status-badge__text" :style="{ color: getStatusConfig(item.status).color }">
              {{ getStatusConfig(item.status).label }}
            </text>
          </view>
        </view>

        <!-- Product info -->
        <view class="refund-card__product">
          <image
            v-if="item.goods_image"
            class="refund-card__image"
            :src="item.goods_image"
            mode="aspectFill"
            lazy-load
          />
          <view v-else class="refund-card__image refund-card__image--placeholder">
            <text class="refund-card__placeholder-icon">&#x1F4E6;</text>
          </view>
          <view class="refund-card__info">
            <text class="refund-card__name">{{ item.goods_name || '商品' }}</text>
            <text v-if="item.sku_spec_text || item.sku_spec" class="refund-card__spec">
              {{ item.sku_spec_text || item.sku_spec }}
            </text>
          </view>
        </view>

        <!-- Refund amount -->
        <view class="refund-card__footer">
          <text class="refund-card__amount-label">退款金额</text>
          <text class="refund-card__amount">{{ '¥' }}{{ formatPrice(item.refund_amount || item.amount) }}</text>
        </view>
      </view>
    </view>

    <!-- Loading / No More -->
    <view v-if="list.length > 0" class="load-state">
      <text v-if="loading" class="load-state__text">加载中...</text>
      <view v-else-if="noMore" class="load-state__divider">
        <view class="load-state__line" />
        <text class="load-state__text">没有更多了</text>
        <view class="load-state__line" />
      </view>
    </view>

    <!-- Empty state -->
    <mb-empty-state
      v-if="initialized && !loading && list.length === 0"
      icon="&#x1F4CB;"
      text="暂无退款记录"
    />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: $mb-color-bg-secondary;
  padding: $mb-spacing-sm $mb-spacing-page $mb-spacing-xl;
}

// ---- List ----
.list {
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-md;
}

// ---- Refund card ----
.refund-card {
  background: $mb-color-bg;
  border-radius: $mb-radius-xl;
  padding: $mb-spacing-lg;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.03);
  transition: opacity 0.15s;

  &:active {
    opacity: 0.85;
  }
}

.refund-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: $mb-spacing-md;
}

.refund-card__sn {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  flex: 1;
  margin-right: $mb-spacing-sm;
}

.status-badge {
  flex-shrink: 0;
  padding: 8rpx 20rpx;
  border-radius: $mb-radius-sm;
}

.status-badge__text {
  font-size: 22rpx;
  font-weight: 500;
}

// ---- Product in card ----
.refund-card__product {
  display: flex;
  gap: $mb-spacing-md;
  padding: $mb-spacing-sm 0;
}

.refund-card__image {
  flex-shrink: 0;
  width: 120rpx;
  height: 120rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
}

.refund-card__image--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.refund-card__placeholder-icon {
  font-size: 40rpx;
}

.refund-card__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-width: 0;
}

.refund-card__name {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text-title;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.refund-card__spec {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  margin-top: 8rpx;
}

// ---- Footer ----
.refund-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: $mb-spacing-md;
  padding-top: $mb-spacing-md;
  border-top: 1rpx solid $mb-color-divider;
}

.refund-card__amount-label {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
}

.refund-card__amount {
  font-size: $mb-font-lg;
  font-weight: 700;
  color: $mb-color-text-title;
}

// ---- Load state ----
.load-state {
  padding: $mb-spacing-xl 0 $mb-spacing-sm;
  display: flex;
  justify-content: center;
}

.load-state__text {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  padding: 0 $mb-spacing-md;
}

.load-state__divider {
  display: flex;
  align-items: center;
  width: 60%;
}

.load-state__line {
  flex: 1;
  height: 1rpx;
  background: $mb-color-border;
}
</style>
