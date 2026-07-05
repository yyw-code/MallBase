<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { getPointsMallGoodsDetail } from '@/api/points/mall'
import { isPointsEnabled, leavePointsPage } from '@/utils/points-feature'

const decorateStore = useDecorateStore()

const id = ref('')
const detail = ref(null)
const quantity = ref(1)
const loading = ref(true)

const availableStock = computed(() => Number(detail.value?.available_stock || 0))
const totalPoints = computed(() => Number(detail.value?.points_price || 0) * quantity.value)

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
    detail.value = await getPointsMallGoodsDetail(id.value)
  } catch {
    detail.value = null
  } finally {
    loading.value = false
  }
}

function imageUrl() {
  return detail.value?.goods_image_full_url || detail.value?.goods_image || ''
}

function increase() {
  if (quantity.value >= availableStock.value) return
  quantity.value += 1
}

function decrease() {
  if (quantity.value <= 1) return
  quantity.value -= 1
}

function goBack() {
  uni.navigateBack()
}

function goConfirm() {
  if (!detail.value) return
  if (availableStock.value <= 0) {
    uni.showToast({ title: '库存不足', icon: 'none' })
    return
  }
  uni.navigateTo({
    url: `/pages-sub/points/exchange-confirm?id=${detail.value.id}&quantity=${quantity.value}`,
  })
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="积分商品" bg-color="var(--color-bg, #ffffff)" />

    <view v-if="loading" class="loading">
      <mb-skeleton type="card" />
      <mb-skeleton type="lines" :count="4" />
    </view>

    <mb-empty-state
      v-else-if="!detail"
      icon=""
      text="积分商品不存在或已下架"
      action-text="返回"
      @action="goBack"
    />

    <view v-else class="content">
      <image class="hero-image" :src="imageUrl()" mode="aspectFill" />

      <view class="goods-panel">
        <view class="price-row">
          <view class="points-price">
            <text class="points-price__value">{{ detail.points_price }}</text>
            <text class="points-price__unit">积分</text>
          </view>
          <text class="stock">库存 {{ availableStock }}</text>
        </view>
        <text class="goods-name">{{ detail.goods_name }}</text>
        <text v-if="detail.goods_subtitle" class="goods-subtitle">
          {{ detail.goods_subtitle }}
        </text>
      </view>

      <view class="info-panel">
        <view class="info-row">
          <text class="info-row__label">规格</text>
          <text class="info-row__value">{{ detail.sku_spec || '默认规格' }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">原售价</text>
          <text class="info-row__value">¥{{ detail.sku_price || detail.goods_price }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">每人限兑</text>
          <text class="info-row__value">
            {{ detail.limit_per_user > 0 ? `${detail.limit_per_user} 件` : '不限制' }}
          </text>
        </view>
      </view>

      <view class="quantity-panel">
        <text class="quantity-panel__label">兑换数量</text>
        <view class="stepper">
          <view
            class="stepper__btn"
            :class="{ 'stepper__btn--disabled': quantity <= 1 }"
            @tap="decrease"
          >
            <text class="stepper__text">-</text>
          </view>
          <text class="stepper__value">{{ quantity }}</text>
          <view
            class="stepper__btn"
            :class="{ 'stepper__btn--disabled': quantity >= availableStock }"
            @tap="increase"
          >
            <text class="stepper__text">+</text>
          </view>
        </view>
      </view>
    </view>

    <mb-copyright-footer />

    <view v-if="detail" class="submit-bar">
      <view class="submit-bar__summary">
        <text class="submit-bar__label">合计</text>
        <text class="submit-bar__points">{{ totalPoints }} 积分</text>
      </view>
      <view
        class="submit-bar__btn"
        :class="{ 'submit-bar__btn--disabled': availableStock <= 0 }"
        @tap="goConfirm"
      >
        <text class="submit-bar__btn-text">
          {{ availableStock > 0 ? '立即兑换' : '库存不足' }}
        </text>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding-bottom: 148rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.loading {
  padding: $mb-spacing-md $mb-spacing-page;
}

.content {
  padding: 0 $mb-spacing-page;
}

.hero-image {
  width: 100%;
  height: 560rpx;
  margin-top: $mb-spacing-md;
  background: var(--color-bg-surface, #f8fafc);
  border-radius: $mb-radius-lg;
}

.goods-panel,
.info-panel,
.quantity-panel {
  margin-top: 18rpx;
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.price-row,
.info-row,
.quantity-panel,
.stepper,
.submit-bar,
.submit-bar__summary {
  display: flex;
  align-items: center;
}

.price-row,
.info-row,
.quantity-panel,
.submit-bar {
  justify-content: space-between;
}

.points-price {
  display: flex;
  align-items: baseline;
  gap: 6rpx;
}

.points-price__value {
  color: var(--color-primary, #0d50d5);
  font-size: 46rpx;
  font-weight: 900;
}

.points-price__unit,
.submit-bar__points {
  color: var(--color-primary, #0d50d5);
  font-size: 26rpx;
  font-weight: 800;
}

.stock,
.goods-subtitle,
.info-row__label,
.submit-bar__label {
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.goods-name {
  display: block;
  margin-top: 14rpx;
  color: var(--color-text, #111827);
  font-size: 34rpx;
  font-weight: 900;
  line-height: 1.35;
}

.goods-subtitle {
  display: block;
  margin-top: 8rpx;
}

.info-row {
  padding: 14rpx 0;
}

.info-row__value,
.quantity-panel__label {
  color: var(--color-text, #111827);
  font-size: 26rpx;
  font-weight: 700;
}

.stepper {
  overflow: hidden;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-md;
}

.stepper__btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 72rpx;
  height: 64rpx;
  background: var(--color-bg-surface, #f8fafc);
}

.stepper__btn--disabled {
  opacity: 0.45;
}

.stepper__text,
.stepper__value {
  color: var(--color-text, #111827);
  font-size: 30rpx;
  font-weight: 800;
}

.stepper__value {
  min-width: 86rpx;
  text-align: center;
}

.submit-bar {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 9;
  padding: 18rpx $mb-spacing-page calc(18rpx + env(safe-area-inset-bottom));
  background: var(--color-bg, #ffffff);
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
}

.submit-bar__summary {
  gap: 10rpx;
}

.submit-bar__points {
  font-size: 34rpx;
}

.submit-bar__btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 248rpx;
  height: 78rpx;
  background: var(--color-primary, #0d50d5);
  border-radius: 999rpx;
}

.submit-bar__btn--disabled {
  background: #cbd5e1;
}

.submit-bar__btn-text {
  color: #ffffff;
  font-size: 28rpx;
  font-weight: 800;
}
</style>
