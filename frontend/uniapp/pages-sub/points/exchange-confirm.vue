<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, onUnmounted, ref, watch } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { getAddressList } from '@/api/user/address'
import { exchangePointsGoods, getPointsMallGoodsDetail } from '@/api/points/mall'
import { isPointsEnabled, leavePointsPage } from '@/utils/points-feature'

const decorateStore = useDecorateStore()

const id = ref('')
const detail = ref(null)
const quantity = ref(1)
const address = ref(null)
const remark = ref('')
const loading = ref(true)
const submitting = ref(false)
const idempotencyKey = ref('')

const availableStock = computed(() => Number(detail.value?.available_stock || 0))
const totalPoints = computed(() => Number(detail.value?.points_price || 0) * quantity.value)
const fullAddress = computed(() => {
  if (!address.value) return ''
  const a = address.value
  return [a.province_name, a.city_name, a.district_name, a.street_name, a.address_detail]
    .filter(Boolean)
    .join(' ')
})
const isAddressValid = computed(() => address.value && address.value.region_status !== 0)

onLoad(async (query) => {
  id.value = query?.id || ''
  quantity.value = Math.max(1, Number(query?.quantity || 1))
  idempotencyKey.value = generateKey()
  if (!(await isPointsEnabled())) {
    leavePointsPage()
    return
  }
  await Promise.all([fetchDetail(), fetchDefaultAddress()])
})

onShow(() => {
  const selected = uni.getStorageSync('selected_address')
  if (selected) {
    address.value = selected
    uni.removeStorageSync('selected_address')
  }
})

function onAddressSelected(addr) {
  address.value = addr
}
uni.$on('selectAddress', onAddressSelected)
onUnmounted(() => {
  uni.$off('selectAddress', onAddressSelected)
})

watch(
  () => availableStock.value,
  (stock) => {
    if (stock > 0 && quantity.value > stock) quantity.value = stock
  },
)

async function fetchDetail() {
  loading.value = true
  try {
    detail.value = await getPointsMallGoodsDetail(id.value)
    if (availableStock.value > 0 && quantity.value > availableStock.value) {
      quantity.value = availableStock.value
    }
  } catch {
    detail.value = null
  } finally {
    loading.value = false
  }
}

async function fetchDefaultAddress() {
  try {
    const data = await getAddressList()
    const list = Array.isArray(data?.list) ? data.list : (Array.isArray(data) ? data : [])
    const validList = list.filter((item) => item.region_status !== 0)
    address.value = validList.find((item) => item.is_default) || validList[0] || null
  } catch {
    address.value = null
  }
}

function imageUrl() {
  return detail.value?.goods_image_full_url || detail.value?.goods_image || ''
}

function generateKey() {
  const seg = () => Math.random().toString(16).slice(2, 10)
  return `${seg()}-${seg()}-${seg()}-${seg()}`
}

function goSelectAddress() {
  uni.navigateTo({ url: '/pages-sub/address/list?select=1' })
}

function goBack() {
  uni.navigateBack()
}

function increase() {
  if (quantity.value >= availableStock.value) return
  quantity.value += 1
}

function decrease() {
  if (quantity.value <= 1) return
  quantity.value -= 1
}

async function submitExchange() {
  if (submitting.value) return
  if (!detail.value) {
    uni.showToast({ title: '积分商品不存在', icon: 'none' })
    return
  }
  if (!address.value) {
    uni.showToast({ title: '请选择收货地址', icon: 'none' })
    return
  }
  if (!isAddressValid.value) {
    uni.showToast({ title: '当前地址已失效，请重新选择', icon: 'none' })
    return
  }
  if (quantity.value < 1 || quantity.value > availableStock.value) {
    uni.showToast({ title: '兑换数量不合法', icon: 'none' })
    return
  }

  submitting.value = true
  try {
    const result = await exchangePointsGoods({
      points_goods_id: Number(detail.value.id),
      address_id: Number(address.value.id),
      quantity: quantity.value,
      buyer_remark: remark.value,
      idempotency_key: idempotencyKey.value,
    })
    uni.showToast({ title: '兑换成功', icon: 'success' })
    setTimeout(() => {
      uni.redirectTo({ url: `/pages-sub/points/exchange-detail?id=${result.id}` })
    }, 300)
  } catch {
    idempotencyKey.value = generateKey()
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="确认兑换" bg-color="var(--color-bg, #ffffff)" />

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
      <view class="card address-card" @tap="goSelectAddress">
        <view v-if="address" class="address-card__info">
          <view class="address-card__top">
            <text class="address-card__name">{{ address.receiver_name }}</text>
            <text class="address-card__phone">{{ address.receiver_mobile }}</text>
          </view>
          <text class="address-card__detail">{{ fullAddress }}</text>
          <text v-if="!isAddressValid" class="address-card__warn">
            地区已失效，请重新选择地址
          </text>
        </view>
        <text v-else class="address-card__empty">请选择收货地址</text>
        <text class="address-card__arrow">&#10095;</text>
      </view>

      <view class="card goods-card">
        <image class="goods-card__image" :src="imageUrl()" mode="aspectFill" />
        <view class="goods-card__main">
          <text class="goods-card__name">{{ detail.goods_name }}</text>
          <text class="goods-card__spec">{{ detail.sku_spec || '默认规格' }}</text>
          <view class="goods-card__bottom">
            <text class="goods-card__points">{{ detail.points_price }} 积分</text>
            <text class="goods-card__stock">库存 {{ availableStock }}</text>
          </view>
        </view>
      </view>

      <view class="card quantity-card">
        <text class="quantity-card__label">兑换数量</text>
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

      <view class="card remark-card">
        <text class="remark-card__label">兑换备注</text>
        <input
          v-model="remark"
          class="remark-card__input"
          :maxlength="200"
          placeholder="可填写配送备注"
          placeholder-class="remark-placeholder"
          type="text"
        />
      </view>
    </view>

    <mb-copyright-footer />

    <view v-if="detail" class="submit-bar">
      <view class="submit-bar__summary">
        <text class="submit-bar__label">需消耗</text>
        <text class="submit-bar__points">{{ totalPoints }} 积分</text>
      </view>
      <view
        class="submit-bar__btn"
        :class="{
          'submit-bar__btn--disabled':
            submitting || !address || !isAddressValid || availableStock <= 0,
        }"
        @tap="submitExchange"
      >
        <text class="submit-bar__btn-text">
          {{ submitting ? '提交中...' : '确认兑换' }}
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

.loading,
.content {
  padding: $mb-spacing-md $mb-spacing-page 0;
}

.card {
  padding: 28rpx;
  margin-bottom: 18rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.address-card,
.address-card__top,
.goods-card,
.goods-card__bottom,
.quantity-card,
.stepper,
.submit-bar,
.submit-bar__summary {
  display: flex;
  align-items: center;
}

.address-card,
.goods-card__bottom,
.quantity-card,
.submit-bar {
  justify-content: space-between;
}

.address-card__info {
  flex: 1;
  min-width: 0;
}

.address-card__top {
  gap: 16rpx;
}

.address-card__name,
.quantity-card__label {
  color: var(--color-text, #111827);
  font-size: 28rpx;
  font-weight: 800;
}

.address-card__phone,
.address-card__detail,
.address-card__empty,
.goods-card__spec,
.goods-card__stock,
.remark-card__label,
.submit-bar__label {
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.address-card__detail {
  display: block;
  margin-top: 8rpx;
  line-height: 1.4;
}

.address-card__warn {
  display: block;
  margin-top: 8rpx;
  color: #f97316;
  font-size: 24rpx;
}

.address-card__arrow {
  margin-left: 16rpx;
  color: var(--color-text-muted, #6b7280);
  font-size: 28rpx;
}

.goods-card {
  gap: 20rpx;
}

.goods-card__image {
  flex-shrink: 0;
  width: 154rpx;
  height: 154rpx;
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

.goods-card__spec {
  margin-top: 8rpx;
}

.goods-card__bottom {
  margin-top: 28rpx;
}

.goods-card__points,
.submit-bar__points {
  color: var(--color-primary, #0d50d5);
  font-size: 30rpx;
  font-weight: 900;
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

.remark-card {
  display: flex;
  align-items: center;
  gap: 22rpx;
}

.remark-card__input {
  flex: 1;
  height: 64rpx;
  color: var(--color-text, #111827);
  font-size: 26rpx;
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
