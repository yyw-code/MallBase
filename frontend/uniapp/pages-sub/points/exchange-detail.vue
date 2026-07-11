<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { cancelPointsExchangeOrder, getPointsExchangeOrderDetail } from '@/api/points/mall'
import { isPointsEnabled, leavePointsPage } from '@/utils/points-feature'

const decorateStore = useDecorateStore()

const id = ref('')
const detail = ref(null)
const loading = ref(true)
const cancelling = ref(false)

onLoad(async (query) => {
  id.value = query?.id || ''
  if (!(await isPointsEnabled())) {
    leavePointsPage()
    return
  }
  fetchDetail()
})

async function fetchDetail() {
  loading.value = true
  try {
    detail.value = await getPointsExchangeOrderDetail(id.value)
  } catch {
    detail.value = null
  } finally {
    loading.value = false
  }
}

function imageUrl() {
  return detail.value?.goods_image_full_url || detail.value?.goods_image || ''
}

function statusClass(status) {
  if (status === 10) return 'status--pending'
  if (status === 20) return 'status--shipped'
  if (status === 30) return 'status--completed'
  return 'status--closed'
}

function goBack() {
  uni.navigateBack()
}

function confirmCancel() {
  if (!detail.value || detail.value.status !== 10 || cancelling.value) return
  uni.showModal({
    title: '取消兑换',
    content: '确认取消该兑换单？积分和库存会原路返还。',
    confirmText: '确认取消',
    success: async (res) => {
      if (!res.confirm) return
      cancelling.value = true
      try {
        await cancelPointsExchangeOrder(detail.value.id)
        uni.showToast({ title: '已取消', icon: 'success' })
        await fetchDetail()
      } finally {
        cancelling.value = false
      }
    },
  })
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="兑换详情" bg-color="var(--color-bg, #ffffff)" />

    <view v-if="loading" class="loading">
      <mb-skeleton type="card" />
      <mb-skeleton type="lines" :count="4" />
    </view>

    <mb-empty-state
      v-else-if="!detail"
      icon=""
      text="兑换单不存在"
      action-text="返回"
      @action="goBack"
    />

    <view v-else class="content">
      <view class="status-panel">
        <view class="status-panel__top">
          <text class="status-panel__title" :class="statusClass(detail.status)">
            {{ detail.status_text }}
          </text>
          <view
            v-if="detail.status === 10"
            class="cancel-button"
            :class="{ 'cancel-button--disabled': cancelling }"
            @tap="confirmCancel"
          >
            <text class="cancel-button__text">{{ cancelling ? '取消中' : '取消兑换' }}</text>
          </view>
        </view>
        <text class="status-panel__sn">{{ detail.sn }}</text>
      </view>

      <view class="card goods-card">
        <image class="goods-card__image" :src="imageUrl()" mode="aspectFill" />
        <view class="goods-card__main">
          <text class="goods-card__name">{{ detail.goods_name }}</text>
          <text class="goods-card__spec">{{ detail.sku_spec || '默认规格' }}</text>
          <view class="goods-card__bottom">
            <text class="goods-card__points">{{ detail.total_points }} 积分</text>
            <text class="goods-card__qty">x{{ detail.quantity }}</text>
          </view>
        </view>
      </view>

      <view class="card info-card">
        <view class="info-row">
          <text class="info-row__label">收货人</text>
          <text class="info-row__value">
            {{ detail.receiver_name }} {{ detail.receiver_phone }}
          </text>
        </view>
        <view class="info-row">
          <text class="info-row__label">收货地址</text>
          <text class="info-row__value info-row__value--wrap">
            {{ detail.receiver_full_address }}
          </text>
        </view>
        <view class="info-row">
          <text class="info-row__label">下单时间</text>
          <text class="info-row__value">{{ detail.create_time }}</text>
        </view>
      </view>

      <view v-if="detail.delivery_type === 'virtual' || detail.logistics_company || detail.logistics_no" class="card info-card">
        <view class="info-row">
          <text class="info-row__label">发货方式</text>
          <text class="info-row__value">{{ detail.delivery_type_text || '实物快递' }}</text>
        </view>
        <view v-if="detail.delivery_type === 'virtual'" class="info-row">
          <text class="info-row__label">发货说明</text>
          <text class="info-row__value info-row__value--wrap">{{ detail.delivery_note || '虚拟商品已发货' }}</text>
        </view>
        <view v-if="detail.delivery_type !== 'virtual'" class="info-row">
          <text class="info-row__label">物流公司</text>
          <text class="info-row__value">{{ detail.logistics_company || '-' }}</text>
        </view>
        <view v-if="detail.delivery_type !== 'virtual'" class="info-row">
          <text class="info-row__label">物流单号</text>
          <text class="info-row__value">{{ detail.logistics_no || '-' }}</text>
        </view>
        <view v-if="detail.shipped_at" class="info-row">
          <text class="info-row__label">发货时间</text>
          <text class="info-row__value">{{ detail.shipped_at }}</text>
        </view>
      </view>

      <view v-if="detail.buyer_remark || detail.admin_remark" class="card info-card">
        <view v-if="detail.buyer_remark" class="info-row">
          <text class="info-row__label">兑换备注</text>
          <text class="info-row__value info-row__value--wrap">
            {{ detail.buyer_remark }}
          </text>
        </view>
        <view v-if="detail.admin_remark" class="info-row">
          <text class="info-row__label">后台备注</text>
          <text class="info-row__value info-row__value--wrap">
            {{ detail.admin_remark }}
          </text>
        </view>
      </view>
    </view>
    <mb-copyright-footer />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.loading,
.content {
  padding: $mb-spacing-md $mb-spacing-page 48rpx;
}

.status-panel,
.card {
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.status-panel {
  padding: 30rpx;
  margin-bottom: 18rpx;
}

.status-panel__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20rpx;
}

.status-panel__title {
  display: block;
  flex: 1;
  font-size: 34rpx;
  font-weight: 900;
}

.status-panel__sn {
  display: block;
  margin-top: 10rpx;
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.status--pending {
  color: #f97316;
}

.status--shipped {
  color: var(--color-primary, #0d50d5);
}

.status--completed {
  color: #16a34a;
}

.status--closed {
  color: var(--color-text-muted, #6b7280);
}

.cancel-button {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  min-width: 152rpx;
  height: 58rpx;
  padding: 0 20rpx;
  border: 1rpx solid #f97316;
  border-radius: $mb-radius-md;
}

.cancel-button--disabled {
  opacity: 0.55;
}

.cancel-button__text {
  color: #f97316;
  font-size: 24rpx;
  font-weight: 800;
}

.card {
  padding: 28rpx;
  margin-bottom: 18rpx;
}

.goods-card,
.goods-card__bottom,
.info-row {
  display: flex;
  align-items: center;
}

.goods-card {
  gap: 20rpx;
}

.goods-card__bottom,
.info-row {
  justify-content: space-between;
}

.goods-card__image {
  flex-shrink: 0;
  width: 150rpx;
  height: 150rpx;
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
  font-size: 28rpx;
  font-weight: 800;
  line-height: 1.35;
}

.goods-card__spec,
.goods-card__qty,
.info-row__label {
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.goods-card__spec {
  margin-top: 8rpx;
}

.goods-card__bottom {
  margin-top: 26rpx;
}

.goods-card__points {
  color: var(--color-primary, #0d50d5);
  font-size: 30rpx;
  font-weight: 900;
}

.info-row {
  gap: 24rpx;
  padding: 16rpx 0;
}

.info-row:first-child {
  padding-top: 0;
}

.info-row:last-child {
  padding-bottom: 0;
}

.info-row__value {
  flex: 1;
  color: var(--color-text, #111827);
  font-size: 26rpx;
  font-weight: 700;
  text-align: right;
}

.info-row__value--wrap {
  line-height: 1.45;
  white-space: normal;
}
</style>
