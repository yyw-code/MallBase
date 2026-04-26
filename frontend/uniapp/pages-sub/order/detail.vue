<template>
  <view class="detail-page">
    <mb-navbar title="订单详情" />

    <!-- Loading skeleton -->
    <view v-if="loading" class="detail-page__loading">
      <view class="card">
        <mb-skeleton type="lines" :count="2" />
      </view>
      <view class="card">
        <mb-skeleton type="avatar-lines" />
      </view>
      <view class="card">
        <mb-skeleton type="card" />
      </view>
      <view class="card">
        <mb-skeleton type="lines" :count="4" />
      </view>
    </view>

    <!-- Error / empty -->
    <mb-empty-state
      v-else-if="!order"
      text="订单不存在"
      action-text="返回订单列表"
      @action="goBack"
    />

    <!-- Main content -->
    <template v-else>
      <!-- Status section -->
      <view class="status-section">
        <text class="status-section__title">{{ statusText }}</text>
      </view>

      <!-- Logistics preview (when shipped) -->
      <view v-if="order.status === 20 && order.logistics_info" class="logistics-preview">
        <view class="logistics-preview__icon">
          <view class="truck-icon">
            <view class="truck-icon__body" />
            <view class="truck-icon__cab" />
            <view class="truck-icon__wheel truck-icon__wheel--front" />
            <view class="truck-icon__wheel truck-icon__wheel--rear" />
          </view>
        </view>
        <view class="logistics-preview__content">
          <text class="logistics-preview__text">{{ order.logistics_info.latest_desc || '物流信息加载中' }}</text>
          <text class="logistics-preview__time">{{ order.logistics_info.latest_time || '' }}</text>
        </view>
      </view>

      <!-- Address card -->
      <view class="card address-card">
        <view class="address-card__icon">
          <view class="pin">
            <view class="pin__head" />
            <view class="pin__body" />
          </view>
        </view>
        <view class="address-card__info">
          <view class="address-card__top">
            <text class="address-card__name">{{ order.receiver_name }}</text>
            <text class="address-card__phone">{{ maskPhone(order.receiver_mobile) }}</text>
          </view>
          <text class="address-card__detail">{{ fullAddress }}</text>
        </view>
      </view>
      <view class="address-divider">
        <view v-for="i in 20" :key="i" class="address-divider__dot" />
      </view>

      <!-- Goods list -->
      <view class="card goods-card">
        <view
          v-for="item in order.items"
          :key="item.id"
          class="goods-item"
        >
          <image
            class="goods-item__img"
            :src="item.goods_image"
            mode="aspectFill"
            lazy-load
          />
          <view class="goods-item__info">
            <text class="goods-item__name">{{ item.goods_name }}</text>
            <text v-if="item.sku_spec" class="goods-item__spec">{{ item.sku_spec }}</text>
            <view class="goods-item__bottom">
              <mb-price :value="item.unit_price" size="sm" color="var(--color-text-title)" />
              <text class="goods-item__qty">&times;{{ item.quantity }}</text>
            </view>
          </view>
        </view>
      </view>

      <!-- Buyer remark -->
      <view v-if="order.buyer_remark" class="card remark-card">
        <text class="remark-card__label">买家留言</text>
        <text class="remark-card__value">{{ order.buyer_remark }}</text>
      </view>

      <!-- Price summary -->
      <view class="card summary-card">
        <view class="summary-row">
          <text class="summary-row__label">商品总额</text>
          <mb-price :value="goodsTotal" size="sm" color="var(--color-text)" />
        </view>
        <view class="summary-row">
          <text class="summary-row__label">运费</text>
          <mb-price :value="order.shipping_fee || 0" size="sm" color="var(--color-text)" />
        </view>
        <view class="summary-divider" />
        <view class="summary-row summary-row--total">
          <text class="summary-row__label">实付金额</text>
          <mb-price :value="order.total_amount" size="md" color="var(--color-text-title)" />
        </view>
      </view>

      <!-- Order info -->
      <view class="card info-card">
        <view class="info-row">
          <text class="info-row__label">订单编号</text>
          <view class="info-row__value-wrap">
            <text class="info-row__value">{{ order.sn }}</text>
            <view class="info-row__copy" @tap="copySn">
              <!-- CSS clipboard icon -->
              <view class="clipboard-icon">
                <view class="clipboard-icon__board" />
                <view class="clipboard-icon__clip" />
              </view>
            </view>
          </view>
        </view>
        <view v-if="order.pay_type_text" class="info-row">
          <text class="info-row__label">支付方式</text>
          <text class="info-row__value">{{ order.pay_type_text }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">下单时间</text>
          <text class="info-row__value">{{ order.create_time || '-' }}</text>
        </view>
      </view>

      <!-- Bottom spacer -->
      <view v-if="actions.length > 0" class="bottom-spacer" />
    </template>

    <!-- Bottom action bar -->
    <view v-if="order && actions.length > 0" class="action-bar">
      <view class="action-bar__inner">
        <view
          v-for="act in actions"
          :key="act.key"
          class="action-bar__btn"
          :class="{ 'action-bar__btn--primary': act.primary }"
          @tap="handleAction(act.key)"
        >
          <text
            class="action-bar__btn-text"
            :class="{ 'action-bar__btn-text--primary': act.primary }"
          >{{ act.label }}</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getOrderDetail, payOrder, cancelOrder, confirmReceive } from '@/api/order/order'

const STATUS_MAP = {
  0: { label: '待付款' },
  10: { label: '待发货' },
  20: { label: '待收货' },
  30: { label: '已收货' },
  40: { label: '已完成' },
  90: { label: '已关闭' },
}

const loading = ref(true)
const order = ref(null)

onLoad((query) => {
  const id = query?.id || ''
  if (id) {
    fetchDetail(id)
  } else {
    loading.value = false
  }
})

async function fetchDetail(id) {
  loading.value = true
  try {
    const res = await getOrderDetail(id)
    order.value = res ?? null
  } catch {
    order.value = null
  } finally {
    loading.value = false
  }
}

// --- Computed ---

const statusText = computed(() => {
  if (!order.value) return ''
  return STATUS_MAP[order.value.status]?.label || '未知'
})

const fullAddress = computed(() => {
  if (!order.value) return ''
  const o = order.value
  return [o.province_name, o.city_name, o.district_name, o.address_detail]
    .filter(Boolean)
    .join(' ')
})

const goodsTotal = computed(() => {
  if (!order.value?.items) return 0
  return order.value.items.reduce(
    (sum, item) => sum + Number(item.unit_price) * Number(item.quantity),
    0,
  )
})

const actions = computed(() => {
  if (!order.value) return []
  const list = []
  if (order.value.status === 0) {
    list.push({ key: 'cancel', label: '取消订单', primary: false })
    list.push({ key: 'pay', label: '去付款', primary: true })
  } else if (order.value.status === 20) {
    list.push({ key: 'extend', label: '延长收货', primary: false })
    list.push({ key: 'logistics', label: '查看物流', primary: true })
  } else if (order.value.status === 30 || order.value.status === 40) {
    list.push({ key: 'review', label: '去评价', primary: false })
  }
  return list
})

// --- Helpers ---

function maskPhone(phone) {
  if (!phone || phone.length < 7) return phone || ''
  return phone.slice(0, 3) + '****' + phone.slice(-4)
}

function copySn() {
  if (!order.value?.sn) return
  uni.setClipboardData({
    data: order.value.sn,
    success() {
      uni.showToast({ title: '已复制', icon: 'success' })
    },
  })
}

// --- Actions ---

async function handleAction(key) {
  if (key === 'cancel') {
    uni.showModal({
      title: '提示',
      content: '确定要取消该订单吗？',
      success: async (res) => {
        if (!res.confirm) return
        try {
          await cancelOrder(order.value.id)
          uni.showToast({ title: '已取消', icon: 'none' })
          fetchDetail(order.value.id)
        } catch { /* handled by request interceptor */ }
      },
    })
  } else if (key === 'pay') {
    try {
      await payOrder(order.value.sn)
      uni.redirectTo({
        url: `/pages-sub/order/pay-result?sn=${order.value.sn}&order_id=${order.value.id}&status=success`,
      })
    } catch {
      uni.redirectTo({
        url: `/pages-sub/order/pay-result?sn=${order.value.sn}&order_id=${order.value.id}&status=fail`,
      })
    }
  } else if (key === 'confirm') {
    uni.showModal({
      title: '提示',
      content: '确认已收到商品？',
      success: async (res) => {
        if (!res.confirm) return
        try {
          await confirmReceive(order.value.id)
          uni.showToast({ title: '已确认收货', icon: 'none' })
          fetchDetail(order.value.id)
        } catch { /* handled by request interceptor */ }
      },
    })
  } else if (key === 'extend') {
    uni.showToast({ title: '已延长收货', icon: 'none' })
  } else if (key === 'logistics') {
    uni.navigateTo({
      url: `/pages-sub/order/logistics?order_id=${order.value.id}`,
    })
  } else if (key === 'review') {
    uni.navigateTo({
      url: `/pages-sub/review/post?order_id=${order.value.id}`,
    })
  }
}

function goBack() {
  const pages = getCurrentPages()
  if (pages.length > 1) {
    uni.navigateBack()
  } else {
    uni.switchTab({ url: '/pages/order/index' })
  }
}
</script>

<style lang="scss" scoped>
.detail-page {
  min-height: 100vh;
  background-color: $mb-color-bg-secondary;
  padding: 0 $mb-spacing-page $mb-spacing-lg;
}

// ---- Loading ----
.detail-page__loading {
  padding-top: $mb-spacing-md;
}

// ---- Card base ----
.card {
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.03);
}

// ---- Status section ----
.status-section {
  padding: $mb-spacing-xl 0 $mb-spacing-md;
}

.status-section__title {
  display: block;
  font-size: 56rpx;
  font-weight: 700;
  color: $mb-color-text-title;
  letter-spacing: 2rpx;
  line-height: 1.2;
}

// ---- Logistics preview ----
.logistics-preview {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.03);
}

.logistics-preview__icon {
  flex-shrink: 0;
  width: 64rpx;
  height: 64rpx;
  border-radius: $mb-radius-md;
  background: rgba($mb-color-primary, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}

// ---- Truck icon (pure CSS) ----
.truck-icon {
  position: relative;
  width: 32rpx;
  height: 24rpx;

  &__body, &__cab, &__wheel { position: absolute; background: $mb-color-primary; }
  &__body { left: 0; bottom: 6rpx; width: 20rpx; height: 14rpx; border-radius: 2rpx; }
  &__cab { right: 0; bottom: 6rpx; width: 12rpx; height: 10rpx; border-radius: 0 3rpx 3rpx 0; }
  &__wheel { bottom: 0; width: 8rpx; height: 8rpx; border-radius: 50%; box-shadow: inset 0 0 0 2rpx rgba(255, 255, 255, 0.8); }
  &__wheel--rear { left: 4rpx; }
  &__wheel--front { right: 4rpx; }
}

.logistics-preview__content { flex: 1; min-width: 0; }

.logistics-preview__text {
  font-size: $mb-font-md;
  color: $mb-color-text;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.logistics-preview__time {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  margin-top: 8rpx;
}

// ---- Address card ----
.address-card {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
  margin-bottom: 0;
  border-radius: $mb-radius-lg $mb-radius-lg 0 0;
  padding-bottom: $mb-spacing-md;
}

.address-card__icon {
  flex-shrink: 0;
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: rgba($mb-color-primary, 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 4rpx;
}

.pin {
  position: relative;
  width: 28rpx;
  height: 36rpx;
}

.pin__head {
  width: 28rpx;
  height: 28rpx;
  border-radius: 50% 50% 50% 0;
  transform: rotate(-45deg);
  background: $mb-color-primary;
  position: absolute;
  top: 0;
  left: 0;

  &::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 10rpx;
    height: 10rpx;
    border-radius: 50%;
    background: $mb-color-bg;
  }
}

.pin__body {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 4rpx;
  height: 10rpx;
  background: $mb-color-primary;
  border-radius: 0 0 2rpx 2rpx;
}

.address-card__info { flex: 1; min-width: 0; }

.address-card__top {
  display: flex;
  align-items: baseline;
  gap: $mb-spacing-sm;
  margin-bottom: 8rpx;
}

.address-card__name { font-size: $mb-font-lg; font-weight: 600; color: $mb-color-text-title; }
.address-card__phone { font-size: $mb-font-sm; color: $mb-color-text-secondary; }

.address-card__detail {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

// ---- Address divider ----
.address-divider {
  display: flex;
  height: 6rpx;
  margin-bottom: $mb-spacing-md;
  overflow: hidden;
  border-radius: 0 0 $mb-radius-lg $mb-radius-lg;
}

.address-divider__dot {
  flex: 1;

  &:nth-child(odd) {
    background: $mb-color-primary;
  }

  &:nth-child(even) {
    background: $mb-color-error;
  }
}

// ---- Goods card ----
.goods-card {
  padding-bottom: $mb-spacing-sm;
}

.goods-item {
  display: flex;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md 0;

  &:first-child {
    padding-top: $mb-spacing-xs;
  }

  & + & {
    border-top: 1rpx solid $mb-color-divider;
  }
}

.goods-item__img {
  flex-shrink: 0;
  width: 180rpx;
  height: 180rpx;
  border-radius: $mb-radius-md;
  background: $mb-color-bg-secondary;
}

.goods-item__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-width: 0;
  padding: 4rpx 0;
}

.goods-item__name {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text-title;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.goods-item__spec {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-sm;
  padding: 4rpx 12rpx;
  align-self: flex-start;
  margin-top: 8rpx;
}

.goods-item__bottom {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-top: auto;
}

.goods-item__qty { font-size: $mb-font-sm; color: $mb-color-text-tertiary; }

// ---- Remark card ----
.remark-card {
  display: flex;
  align-items: flex-start;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md $mb-spacing-lg;
}

.remark-card__label {
  flex-shrink: 0;
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text-title;
}

.remark-card__value {
  flex: 1;
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1.5;
  text-align: right;
}

// ---- Summary card ----
.summary-card {
  padding: $mb-spacing-md $mb-spacing-lg;
}

.summary-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14rpx 0;
}

.summary-row__label { font-size: $mb-font-md; color: $mb-color-text-secondary; }

.summary-divider { height: 1rpx; background: $mb-color-divider; margin: 8rpx 0; }

.summary-row--total {
  padding-top: 20rpx;

  .summary-row__label {
    font-size: $mb-font-lg;
    font-weight: 600;
    color: $mb-color-text-title;
  }
}

// ---- Order info card ----
.info-card {
  padding: $mb-spacing-md $mb-spacing-lg;
}

.info-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14rpx 0;
}

.info-row__label {
  font-size: $mb-font-md;
  color: $mb-color-text-tertiary;
  flex-shrink: 0;
}

.info-row__value-wrap {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  flex: 1;
  justify-content: flex-end;
  min-width: 0;
}

.info-row__value {
  font-size: $mb-font-md;
  color: $mb-color-text;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.info-row__copy {
  flex-shrink: 0;
  width: 40rpx;
  height: 40rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

// ---- Clipboard icon (pure CSS) ----
.clipboard-icon {
  position: relative;
  width: 24rpx;
  height: 28rpx;

  &__board {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 20rpx;
    height: 24rpx;
    border: 2rpx solid $mb-color-primary;
    border-radius: 3rpx;
  }

  &__clip {
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 10rpx;
    height: 6rpx;
    border-radius: 2rpx 2rpx 0 0;
    background: $mb-color-primary;

    &::after {
      content: '';
      position: absolute;
      top: 4rpx;
      left: -3rpx;
      width: 16rpx;
      height: 2rpx;
      background: $mb-color-primary;
    }
  }
}

// ---- Bottom action bar ----
.action-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  background: $mb-color-bg;
  box-shadow: 0 -2rpx 16rpx rgba(0, 0, 0, 0.05);
}

.action-bar__inner {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $mb-spacing-md;
  padding: $mb-spacing-sm $mb-spacing-page;
  padding-bottom: calc(#{$mb-spacing-sm} + env(safe-area-inset-bottom));
}

.action-bar__btn {
  height: 80rpx;
  min-width: 200rpx;
  border-radius: $mb-radius-full;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 $mb-spacing-xl;
  border: 2rpx solid $mb-color-border;
  transition: opacity 0.15s, transform 0.15s;

  &:active {
    opacity: 0.85;
    transform: scale(0.98);
  }
}

.action-bar__btn--primary {
  background: $mb-color-text-title;
  border-color: $mb-color-text-title;
}

.action-bar__btn-text { font-size: $mb-font-md; font-weight: 600; color: $mb-color-text; }
.action-bar__btn-text--primary { color: $mb-color-text-inverse; }

// ---- Bottom spacer ----
.bottom-spacer {
  height: calc(140rpx + env(safe-area-inset-bottom));
}
</style>
