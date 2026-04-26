<template>
  <view class="confirm-page">
    <mb-navbar title="确认订单" />

    <!-- 收货地址 -->
    <view class="card address-card" @tap="goSelectAddress">
      <view class="address-card__icon">
        <view class="pin">
          <view class="pin__head" />
          <view class="pin__body" />
        </view>
      </view>
      <view v-if="address" class="address-card__info">
        <view class="address-card__top">
          <text class="address-card__name">{{ address.receiver_name }}</text>
          <text class="address-card__phone">{{ maskPhone(address.receiver_mobile) }}</text>
        </view>
        <text class="address-card__detail">{{ fullAddress }}</text>
        <text v-if="!isAddressValid" class="address-card__warn">地区已失效，请重新选择地址</text>
      </view>
      <view v-else class="address-card__empty">
        <text class="address-card__empty-text">请添加收货地址</text>
      </view>
      <text class="address-card__arrow">&#10095;</text>
    </view>
    <view class="address-stripe" />

    <!-- 商品列表 -->
    <view class="card goods-card">
      <view class="section-label">
        <text class="section-label__text">商品信息</text>
      </view>
      <view
        v-for="item in orderItems"
        :key="item.id || item.sku_id"
        class="goods-item"
      >
        <image
          class="goods-item__img"
          :src="item.goods_image"
          mode="aspectFill"
          lazy-load
        />
        <view class="goods-item__info">
          <text class="goods-item__name">{{ item.goods_name || item.name }}</text>
          <text v-if="item.sku_spec" class="goods-item__spec">{{ item.sku_spec }}</text>
          <view class="goods-item__bottom">
            <mb-price :value="item.unit_price" size="sm" color="var(--color-text-title)" />
            <text class="goods-item__qty">&times;{{ item.quantity }}</text>
          </view>
        </view>
      </view>
    </view>

    <!-- 配送方式 -->
    <view class="card delivery-card">
      <text class="delivery-card__label">配送方式</text>
      <view class="delivery-card__right">
        <text class="delivery-card__value">快递配送（免运费）</text>
      </view>
    </view>

    <!-- 备注 -->
    <view class="card remark-card">
      <text class="remark-card__label">订单备注</text>
      <input
        v-model="remark"
        class="remark-card__input"
        type="text"
        :maxlength="200"
        placeholder="建议留言前先与商家沟通确认"
        placeholder-class="remark-placeholder"
      />
    </view>

    <!-- 价格明细 -->
    <view class="card summary-card">
      <view class="summary-row">
        <text class="summary-row__label">商品金额</text>
        <mb-price :value="goodsTotal" size="sm" color="var(--color-text)" />
      </view>
      <view class="summary-row">
        <text class="summary-row__label">运费</text>
        <text class="summary-row__free">免运费</text>
      </view>
      <view class="summary-divider" />
      <view class="summary-row summary-row--total">
        <text class="summary-row__label">合计</text>
        <mb-price :value="goodsTotal" size="md" color="var(--color-text-title)" />
      </view>
    </view>

    <!-- 底部提交栏 -->
    <view class="submit-bar">
      <view class="submit-bar__inner">
        <view class="submit-bar__price">
          <text class="submit-bar__sup">TOTAL AMOUNT</text>
          <mb-price :value="goodsTotal" size="lg" color="var(--color-text-title, #131b2e)" />
        </view>
        <view
          class="submit-bar__btn"
          :class="{ 'submit-bar__btn--disabled': submitting || !address || !isAddressValid }"
          @tap="handleSubmit"
        >
          <text class="submit-bar__btn-text">{{ submitting ? '提交中...' : '提交订单' }}</text>
        </view>
      </view>
    </view>

    <!-- 底部占位 -->
    <view class="bottom-spacer" />
  </view>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { useCartStore } from '@/store/cart'
import { getAddressList } from '@/api/user/address'
import { createOrder, payOrder } from '@/api/order/order'

const cartStore = useCartStore()

const source = ref('')
const skuId = ref('')
const quantity = ref(1)
const address = ref(null)
const remark = ref('')
const submitting = ref(false)
const idempotencyKey = ref('')
const orderItems = ref([])

/**
 * 生成幂等 key，防止重复提交
 */
function generateKey() {
  const seg = () => Math.random().toString(16).slice(2, 10)
  return `${seg()}-${seg()}-${seg()}-${seg()}`
}

onLoad((query) => {
  source.value = query.source || 'cart'
  idempotencyKey.value = generateKey()

  if (source.value === 'sku') {
    skuId.value = query.sku_id || ''
    quantity.value = Number(query.quantity) || 1

    // 直接购买模式：从 storage 读取临时商品信息
    const skuInfo = uni.getStorageSync('buy_now_sku')
    if (skuInfo && String(skuInfo.sku_id) === String(skuId.value)) {
      orderItems.value = [{
        id: skuInfo.sku_id,
        sku_id: skuInfo.sku_id,
        goods_name: skuInfo.goods_name || '',
        goods_image: skuInfo.goods_image || '',
        sku_spec: skuInfo.sku_spec || '',
        unit_price: Number(skuInfo.unit_price) || 0,
        quantity: quantity.value,
      }]
    }
  } else {
    // 购物车模式
    orderItems.value = cartStore.selectedItems.map((item) => ({
      ...item,
      quantity: item.quantity || 1,
    }))
  }

  fetchDefaultAddress()
})

onShow(() => {
  // 返回时检查是否有选中的地址
  const selected = uni.getStorageSync('selected_address')
  if (selected) {
    address.value = selected
    uni.removeStorageSync('selected_address')
  }
})

// 监听地址选择事件（兼容事件模式）
function onAddressSelected(addr) {
  address.value = addr
}
uni.$on('selectAddress', onAddressSelected)
onUnmounted(() => {
  uni.$off('selectAddress', onAddressSelected)
})

/**
 * 获取默认收货地址
 */
async function fetchDefaultAddress() {
  try {
    const data = await getAddressList()
    const list = Array.isArray(data?.list) ? data.list : (Array.isArray(data) ? data : [])
    if (list.length === 0) return
    const validList = list.filter((a) => a.region_status !== 0)
    if (validList.length === 0) return
    const defaultAddr = validList.find((a) => a.is_default) || validList[0]
    if (!address.value) {
      address.value = defaultAddr
    }
  } catch {
    // 地址获取失败不阻塞页面
  }
}

const isAddressValid = computed(() => {
  if (!address.value) return false
  return address.value.region_status !== 0
})

const fullAddress = computed(() => {
  if (!address.value) return ''
  const a = address.value
  return [a.province_name, a.city_name, a.district_name, a.address_detail].filter(Boolean).join(' ')
})

const goodsTotal = computed(() => {
  return orderItems.value.reduce(
    (sum, item) => sum + Number(item.unit_price) * item.quantity,
    0,
  )
})

function maskPhone(phone) {
  if (!phone || phone.length < 7) return phone || ''
  return phone.slice(0, 3) + '****' + phone.slice(-4)
}

function goSelectAddress() {
  uni.navigateTo({ url: '/pages-sub/address/list?select=1' })
}

async function handleSubmit() {
  if (submitting.value) return

  if (!address.value) {
    uni.showToast({ title: '请选择收货地址', icon: 'none' })
    return
  }

  if (!isAddressValid.value) {
    uni.showToast({ title: '当前地址已失效，请重新选择', icon: 'none' })
    return
  }

  if (orderItems.value.length === 0) {
    uni.showToast({ title: '请选择商品', icon: 'none' })
    return
  }

  submitting.value = true

  const payload =
    source.value === 'sku'
      ? {
          source: 'sku',
          address_id: address.value.id,
          sku_id: skuId.value,
          quantity: quantity.value,
          buyer_remark: remark.value,
          idempotency_key: idempotencyKey.value,
        }
      : {
          source: 'cart',
          address_id: address.value.id,
          cart_ids: orderItems.value.map((item) => item.id),
          buyer_remark: remark.value,
          idempotency_key: idempotencyKey.value,
        }

  try {
    const order = await createOrder(payload)
    const sn = order.sn
    const orderId = order.order_id

    // 调用支付
    try {
      await payOrder(sn)
      uni.redirectTo({ url: `/pages-sub/order/pay-result?sn=${sn}&order_id=${orderId}&status=success` })
    } catch {
      uni.redirectTo({ url: `/pages-sub/order/pay-result?sn=${sn}&order_id=${orderId}&status=fail` })
    }
  } catch {
    // 创建订单失败，刷新幂等 key 以允许重试
    idempotencyKey.value = generateKey()
  } finally {
    submitting.value = false
  }
}
</script>

<style lang="scss" scoped>
.confirm-page {
  min-height: 100vh;
  background-color: var(--color-bg-secondary, #f7f9fb);
  padding: 0 $mb-spacing-page $mb-spacing-lg;
}

// ---- Card base ----
.card {
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.03);
}

// ---- Address card ----
.address-card {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  margin-top: $mb-spacing-md;
  margin-bottom: 0;
  border-radius: $mb-radius-lg $mb-radius-lg 0 0;
  padding-bottom: $mb-spacing-md;
}

.address-card__icon {
  flex-shrink: 0;
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  background: rgba($mb-color-primary, 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
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

.address-card__info {
  flex: 1;
  min-width: 0;
}

.address-card__top {
  display: flex;
  align-items: baseline;
  gap: $mb-spacing-sm;
  margin-bottom: 8rpx;
}

.address-card__name {
  font-size: $mb-font-lg;
  font-weight: 600;
  color: $mb-color-text-title;
}

.address-card__phone {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
}

.address-card__detail {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.address-card__warn {
  display: block;
  margin-top: 8rpx;
  font-size: $mb-font-sm;
  color: $mb-color-error;
}

.address-card__empty {
  flex: 1;
}

.address-card__empty-text {
  font-size: $mb-font-md;
  color: $mb-color-text-tertiary;
}

.address-card__arrow {
  flex-shrink: 0;
  font-size: 24rpx;
  color: $mb-color-text-tertiary;
  margin-left: $mb-spacing-xs;
}

// ---- Address stripe ----
.address-stripe {
  height: 4rpx;
  margin-bottom: $mb-spacing-md;
  border-radius: 0 0 $mb-radius-lg $mb-radius-lg;
  background: repeating-linear-gradient(
    90deg,
    $mb-color-text 0,
    $mb-color-text 16rpx,
    $mb-color-bg-secondary 16rpx,
    $mb-color-bg-secondary 24rpx
  );
  opacity: 0.15;
}

// ---- Goods card ----
.goods-card {
  padding-bottom: $mb-spacing-sm;
}

.section-label {
  margin-bottom: $mb-spacing-md;
}

.section-label__text {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text-title;
}

.goods-item {
  display: flex;
  gap: $mb-spacing-md;
  padding: $mb-spacing-sm 0;

  & + & {
    border-top: 1rpx solid $mb-color-divider;
  }
}

.goods-item__img {
  flex-shrink: 0;
  width: 160rpx;
  height: 160rpx;
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

.goods-item__qty {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

// ---- Delivery card ----
.delivery-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $mb-spacing-md $mb-spacing-lg;
}

.delivery-card__label {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text-title;
  flex-shrink: 0;
}

.delivery-card__right {
  display: flex;
  align-items: center;
}

.delivery-card__value {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
}

// ---- Remark card ----
.remark-card {
  display: flex;
  align-items: center;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md $mb-spacing-lg;
}

.remark-card__label {
  flex-shrink: 0;
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text-title;
}

.remark-card__input {
  flex: 1;
  font-size: $mb-font-md;
  color: $mb-color-text;
  text-align: right;
}

.remark-placeholder {
  color: $mb-color-text-tertiary;
}

// ---- Summary card ----
.summary-card {
  padding: $mb-spacing-md $mb-spacing-lg;
}

.summary-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12rpx 0;
}

.summary-row__label {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
}

.summary-row__free {
  font-size: $mb-font-md;
  color: $mb-color-success;
  font-weight: 500;
}

.summary-divider {
  height: 1rpx;
  background: $mb-color-divider;
  margin: 8rpx 0;
}

.summary-row--total {
  .summary-row__label {
    font-size: $mb-font-lg;
    font-weight: 600;
    color: $mb-color-text-title;
  }
}

// ---- Submit bar ----
.submit-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  background: $mb-color-bg;
  box-shadow: 0 -2rpx 16rpx rgba(0, 0, 0, 0.05);
}

.submit-bar__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $mb-spacing-sm $mb-spacing-page;
  padding-bottom: calc(#{$mb-spacing-sm} + env(safe-area-inset-bottom));
}

.submit-bar__price {
  display: flex;
  flex-direction: column;
  gap: 4rpx;
}

.submit-bar__sup {
  font-size: 20rpx;
  color: $mb-color-text-tertiary;
  letter-spacing: 1rpx;
  font-weight: 500;
}

.submit-bar__btn {
  height: 88rpx;
  min-width: 260rpx;
  border-radius: $mb-radius-full;
  background: #000000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 $mb-spacing-xl;
  box-shadow: 0 8rpx 24rpx rgba(0, 0, 0, 0.18);
  transition: opacity 0.15s, transform 0.15s;

  &:active {
    opacity: 0.85;
    transform: scale(0.98);
  }
}

.submit-bar__btn--disabled {
  opacity: 0.5;
  pointer-events: none;
}

.submit-bar__btn-text {
  font-size: $mb-font-lg;
  font-weight: 600;
  color: $mb-color-text-inverse;
  letter-spacing: 0.1em;
}

// ---- Bottom spacer for fixed bar ----
.bottom-spacer {
  height: calc(140rpx + env(safe-area-inset-bottom));
}
</style>
