<script setup>
import { ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { getRefundList } from '@/api/order/refund'
import config from '@/config/index'

const STATUS_CONFIG = {
  0: { label: '待审核', color: '#0d50d5', bg: 'rgba(13, 80, 213, 0.08)' },
  1: { label: '待退货', color: '#8a5a00', bg: 'rgba(224, 138, 0, 0.10)' },
  2: { label: '退款中', color: '#0d50d5', bg: 'rgba(13, 80, 213, 0.08)' },
  10: { label: '已完成', color: '#168a43', bg: 'rgba(22, 138, 67, 0.08)' },
  20: { label: '已拒绝', color: '#ba1a1a', bg: 'rgba(186, 26, 26, 0.08)' },
  90: { label: '已关闭', color: '#737686', bg: 'rgba(115, 118, 134, 0.08)' },
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

    list.value = reset ? items : [...list.value, ...items]
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

function normalizeImageUrl(url) {
  if (!url) return ''
  const value = String(url)
  if (/^(https?:)?\/\//.test(value) || value.startsWith('data:') || value.startsWith('blob:')) {
    return value.startsWith('//') ? `https:${value}` : value
  }
  if (value.startsWith('/') && config.baseUrl) {
    return `${config.baseUrl}${value}`
  }
  return value
}

function getStatusConfig(status) {
  return STATUS_CONFIG[Number(status)] || { label: '未知', color: '#737686', bg: 'rgba(115, 118, 134, 0.08)' }
}

function formatPrice(val) {
  const num = Number(val)
  if (Number.isNaN(num)) return '0.00'
  return num.toFixed(2)
}

function getOrderItem(item) {
  return item?.order_item || {}
}

function getOrderSn(item) {
  return item?.order?.sn || item?.order_sn || '-'
}

function getGoodsName(item) {
  const goods = getOrderItem(item)
  return goods.goods_name || item.goods_name || '商品信息'
}

function getGoodsImage(item) {
  const goods = getOrderItem(item)
  return normalizeImageUrl(
    goods.goods_image_full_url
      || goods.goods_image
      || item.goods_image_full_url
      || item.goods_image
      || '',
  )
}

function getGoodsSpec(item) {
  const goods = getOrderItem(item)
  return goods.sku_spec_text || goods.sku_spec || item.sku_spec_text || item.sku_spec || ''
}

function getQuantity(item) {
  return Number(item.quantity || getOrderItem(item).quantity || 1)
}

function getTypeText(item) {
  return item.type_text || (Number(item.type) === 1 ? '退货退款' : '仅退款')
}

function getReceiveText(item) {
  return item.receive_status_text || (Number(item.receive_status) === 1 ? '已收到货' : '未收到货')
}

function getProcessText(item) {
  if (Number(item.type) === 1) {
    if (item.return_tracking_no) {
      return `${item.return_company || '退货物流'} ${item.return_tracking_no}`
    }
    if (Number(item.status) === 1) {
      return '商家已同意，请填写退货物流'
    }
    return item.return_receiver_address ? '待商家确认退货信息' : ''
  }

  if (Number(item.receive_status) === 0 && item.intercept_status_text) {
    if (item.intercept_status === 'exception') {
      return '物流异常/丢件，商家核实处理中'
    }
    if (['success', 'returned'].includes(item.intercept_status || '')) {
      return `商家已确认${item.intercept_status_text}`
    }
    return `物流拦截：${item.intercept_status_text}`
  }
  return ''
}

function goDetail(id) {
  uni.navigateTo({ url: `/pages-sub/refund/detail?id=${id}` })
}
</script>

<template>
  <view class="page">
    <mb-navbar title="售后订单" />

    <view v-if="list.length > 0" class="list">
      <view
        v-for="item in list"
        :key="item.id"
        class="refund-card"
        @tap="goDetail(item.id)"
      >
        <view class="refund-card__header">
          <view class="refund-card__header-main">
            <text class="refund-card__label">售后单</text>
            <text class="refund-card__sn">{{ item.sn || '-' }}</text>
          </view>
          <view
            class="status-badge"
            :style="{ color: getStatusConfig(item.status).color, background: getStatusConfig(item.status).bg }"
          >
            <text class="status-badge__text" :style="{ color: getStatusConfig(item.status).color }">
              {{ item.status_text || getStatusConfig(item.status).label }}
            </text>
          </view>
        </view>

        <view class="refund-card__order-row">
          <text class="refund-card__order-no">订单号 {{ getOrderSn(item) }}</text>
          <text class="refund-card__order-status">{{ item.order?.status_text || '' }}</text>
        </view>

        <view class="refund-card__product">
          <image
            v-if="getGoodsImage(item)"
            class="refund-card__image"
            :src="getGoodsImage(item)"
            mode="aspectFill"
            lazy-load
          />
          <view v-else class="refund-card__image refund-card__image--placeholder">
            <view class="refund-card__placeholder-box" />
          </view>
          <view class="refund-card__info">
            <text class="refund-card__name">{{ getGoodsName(item) }}</text>
            <text v-if="getGoodsSpec(item)" class="refund-card__spec">{{ getGoodsSpec(item) }}</text>
            <view class="refund-card__meta">
              <text>{{ getTypeText(item) }}</text>
              <text>{{ getReceiveText(item) }}</text>
              <text>x{{ getQuantity(item) }}</text>
            </view>
          </view>
        </view>

        <view class="refund-card__summary">
          <view class="refund-card__summary-item">
            <text class="refund-card__summary-label">退款金额</text>
            <text class="refund-card__amount">¥{{ formatPrice(item.refund_amount || item.amount) }}</text>
          </view>
          <view v-if="item.reason_text || item.reason" class="refund-card__summary-item">
            <text class="refund-card__summary-label">申请原因</text>
            <text class="refund-card__summary-value">{{ item.reason_text || item.reason }}</text>
          </view>
          <view v-if="getProcessText(item)" class="refund-card__summary-item refund-card__summary-item--full">
            <text class="refund-card__summary-label">处理进度</text>
            <text class="refund-card__summary-value">{{ getProcessText(item) }}</text>
          </view>
        </view>

        <view class="refund-card__footer">
          <text class="refund-card__time">{{ item.create_time || '' }}</text>
          <text class="refund-card__link">查看详情</text>
        </view>
      </view>
    </view>

    <view v-if="list.length > 0" class="load-state">
      <text v-if="loading" class="load-state__text">加载中...</text>
      <view v-else-if="noMore" class="load-state__divider">
        <view class="load-state__line" />
        <text class="load-state__text">没有更多了</text>
        <view class="load-state__line" />
      </view>
    </view>

    <mb-empty-state
      v-if="initialized && !loading && list.length === 0"
      icon=""
      text="暂无售后订单"
    />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: #faf8ff;
  padding: $mb-spacing-sm $mb-spacing-page $mb-spacing-xl;
}

.list {
  display: flex;
  flex-direction: column;
  gap: 18rpx;
}

.refund-card {
  background: $mb-color-bg;
  border-radius: 16rpx;
  padding: 18rpx;
  border: 1rpx solid rgba(25, 27, 35, 0.06);

  &:active {
    opacity: 0.85;
  }
}

.refund-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-sm;
}

.refund-card__header-main {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 10rpx;
}

.refund-card__label {
  flex-shrink: 0;
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

.refund-card__sn {
  min-width: 0;
  font-size: $mb-font-xs;
  color: $mb-color-text-secondary;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.status-badge {
  flex-shrink: 0;
  padding: 8rpx 18rpx;
  border-radius: 999rpx;
}

.status-badge__text {
  font-size: $mb-font-xs;
  font-weight: 700;
}

.refund-card__order-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-sm;
  margin-top: 14rpx;
  padding: 12rpx 14rpx;
  border-radius: 12rpx;
  background: rgba(25, 27, 35, 0.03);
}

.refund-card__order-no,
.refund-card__order-status {
  font-size: $mb-font-xs;
  color: $mb-color-text-secondary;
}

.refund-card__order-no {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.refund-card__order-status {
  flex-shrink: 0;
  color: $mb-color-primary;
  font-weight: 600;
}

.refund-card__product {
  display: flex;
  gap: 18rpx;
  padding: 18rpx 0;
}

.refund-card__image {
  flex-shrink: 0;
  width: 136rpx;
  height: 136rpx;
  border-radius: 10rpx;
  background: #f3f5f9;
}

.refund-card__image--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.refund-card__placeholder-box {
  width: 54rpx;
  height: 38rpx;
  border-radius: 8rpx;
  background: linear-gradient(135deg, rgba(13, 80, 213, 0.14), rgba(25, 27, 35, 0.06));
}

.refund-card__info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 10rpx;
}

.refund-card__name {
  font-size: 27rpx;
  font-weight: 600;
  color: $mb-color-text-title;
  line-height: 1.38;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.refund-card__spec {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.refund-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8rpx;

  text {
    padding: 5rpx 12rpx;
    border-radius: 999rpx;
    background: rgba(25, 27, 35, 0.04);
    font-size: $mb-font-xs;
    color: $mb-color-text-secondary;
  }
}

.refund-card__summary {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  padding: 14rpx 0;
  border-top: 1rpx solid rgba(25, 27, 35, 0.06);
  border-bottom: 1rpx solid rgba(25, 27, 35, 0.06);
}

.refund-card__summary-item {
  flex: 1;
  min-width: 240rpx;
  display: flex;
  flex-direction: column;
  gap: 6rpx;
}

.refund-card__summary-item--full {
  flex-basis: 100%;
}

.refund-card__summary-label {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

.refund-card__summary-value {
  font-size: $mb-font-sm;
  color: $mb-color-text-title;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.refund-card__amount {
  font-size: 34rpx;
  font-weight: 800;
  color: $mb-color-primary;
}

.refund-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-sm;
  margin-top: 14rpx;
}

.refund-card__time {
  min-width: 0;
  flex: 1;
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.refund-card__link {
  flex-shrink: 0;
  font-size: $mb-font-sm;
  font-weight: 600;
  color: $mb-color-primary;
}

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
