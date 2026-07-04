<template>
  <view
    class="confirm-page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
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
          <text v-if="hasItemMemberDiscount(item)" class="goods-item__benefit">
            会员优惠 -¥{{ itemMemberDiscountAmount(item) }}
          </text>
          <text v-if="itemRewardPoints(item) > 0" class="goods-item__benefit">
            预计赠送 {{ itemRewardPoints(item) }} 积分
          </text>
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
        <text class="delivery-card__value">
          {{ !previewResult || hasFreight ? '快递配送' : '快递配送（免运费）' }}
        </text>
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

    <view v-if="pointsDeduction" class="card points-card">
      <view class="points-card__main">
        <view class="points-card__title-row">
          <text class="points-card__title">积分抵扣</text>
          <text v-if="hasPointsDiscount" class="points-card__discount">-¥{{ pointsDiscountAmount }}</text>
        </view>
        <text class="points-card__meta">
          可用 {{ pointsDeduction.available_points || 0 }}，本单最多可用 {{ pointsDeduction.usable_points || 0 }}
        </text>
      </view>
      <switch
        :checked="usePoints"
        :disabled="!canUsePoints"
        color="var(--color-primary, #0d50d5)"
        @change="onUsePointsChange"
      />
    </view>

    <view v-if="hasPointsReward" class="card points-card reward-card">
      <view class="points-card__main">
        <view class="points-card__title-row">
          <text class="points-card__title">积分赠送</text>
          <text class="points-card__reward">+{{ rewardPoints }} 积分</text>
        </view>
        <text class="points-card__meta">{{ rewardMetaText }}</text>
      </view>
    </view>

    <!-- 价格明细 -->
    <view class="card summary-card">
      <view class="summary-row">
        <text class="summary-row__label">商品金额</text>
        <mb-price :value="displayGoodsTotal" size="sm" color="var(--color-text)" />
      </view>
      <view class="summary-row">
        <text class="summary-row__label">运费</text>
        <text v-if="!previewResult" class="summary-row__free">待计算</text>
        <text v-else-if="isFreeFreight" class="summary-row__free">免运费</text>
        <mb-price v-else :value="displayFreight" size="sm" color="var(--color-text)" />
      </view>
      <view v-if="hasPointsDiscount" class="summary-row">
        <text class="summary-row__label">积分抵扣</text>
        <text class="summary-row__discount">-¥{{ pointsDiscountAmount }}</text>
      </view>
      <view v-if="hasMemberDiscount" class="summary-row">
        <text class="summary-row__label">会员优惠</text>
        <text class="summary-row__discount">-¥{{ memberDiscountAmount }}</text>
      </view>
      <view class="summary-divider" />
      <view class="summary-row summary-row--total">
        <text class="summary-row__label">合计</text>
        <mb-price :value="displayPayTotal" size="md" color="var(--color-text-title)" />
      </view>
    </view>

    <!-- 底部提交栏 -->
    <view class="submit-bar">
      <view class="submit-bar__inner">
        <view class="submit-bar__price">
          <text class="submit-bar__sup">TOTAL AMOUNT</text>
          <mb-price :value="displayPayTotal" size="lg" color="var(--color-primary, #0d50d5)" />
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

    <!-- 支付方式选择 -->
    <mb-pay-method-sheet
      :visible="sheetVisible"
      :methods="payMethods"
      :loading="payLoading"
      :amount="displayPayTotal"
      @select="onPayMethodSelect"
      @close="onPayMethodClose"
    />
    <mb-floating-action />
  </view>
</template>

<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, computed, watch, onUnmounted } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { useCartStore } from '@/store/cart'
import { getAddressList } from '@/api/user/address'
import { createOrder, previewOrder } from '@/api/order/order'
import { isPointsEnabled as fetchPointsFeatureEnabled } from '@/utils/points-feature'
import { usePayFlow } from '@/utils/usePayFlow'
import { isPositivePrice, isZeroPrice, multiplyPrice, normalizePrice, sumPrices } from '@/utils/price'
const decorateStore = useDecorateStore()

const {
  sheetVisible,
  methods: payMethods,
  loading: payLoading,
  startPay,
  invokePay,
  closeSheet,
} = usePayFlow()

const pendingPayContext = ref(null)

function redirectToPayResult(sn, orderId, payResult) {
  const status = payResult.status === 'success'
    ? 'success'
    : payResult.status === 'pending'
      ? 'pending'
      : 'fail'
  const query = [
    `sn=${encodeURIComponent(sn || '')}`,
    `order_id=${encodeURIComponent(orderId || '')}`,
    `status=${status}`,
  ]
  const message = String(payResult.message || '').trim()
  if (message) {
    query.push(`message=${encodeURIComponent(message.slice(0, 160))}`)
  }
  uni.redirectTo({
    url: `/pages-sub/order/pay-result?${query.join('&')}`,
  })
}

async function onPayMethodSelect(code) {
  const ctx = pendingPayContext.value
  if (!ctx) return
  const payResult = await invokePay(code)
  if (payResult) redirectToPayResult(ctx.sn, ctx.orderId, payResult)
}

function onPayMethodClose() {
  closeSheet()
  const ctx = pendingPayContext.value
  if (ctx) {
    redirectToPayResult(ctx.sn, ctx.orderId, { status: 'fail' })
  }
}

const cartStore = useCartStore()

const source = ref('')
const skuId = ref('')
const quantity = ref(1)
const address = ref(null)
const remark = ref('')
const submitting = ref(false)
const idempotencyKey = ref('')
const orderItems = ref([])
const selectedCartIds = ref([])
// 后端订单试算结果（含权威运费），为 null 时回退本地兜底
const previewResult = ref(null)
const usePoints = ref(false)
const pointsFeatureEnabled = ref(false)

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
  refreshPointsFeatureState()

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
        unit_price: normalizePrice(skuInfo.unit_price),
        quantity: quantity.value,
      }]
    }
  } else {
    // 购物车模式
    const selectedItems = cartStore.selectedItems.map((item) => ({
      ...item,
      quantity: item.quantity || 1,
    }))
    selectedCartIds.value = selectedItems.map((item) => item.id)
    orderItems.value = selectedItems
  }

  fetchDefaultAddress()
})

onShow(() => {
  refreshPointsFeatureState()
  // 返回时检查是否有选中的地址
  const selected = uni.getStorageSync('selected_address')
  if (selected) {
    address.value = selected
    uni.removeStorageSync('selected_address')
  }
})

async function refreshPointsFeatureState() {
  const enabled = await fetchPointsFeatureEnabled()
  pointsFeatureEnabled.value = enabled
  if (!enabled) {
    usePoints.value = false
  }
}

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
  return sumPrices(orderItems.value.map((item) => multiplyPrice(item.unit_price, item.quantity)))
})

// 金额一律以后端试算结果为准，未返回前用本地求和兜底首屏渲染
const displayGoodsTotal = computed(() =>
  previewResult.value ? normalizePrice(previewResult.value.total_amount) : goodsTotal.value,
)
const displayFreight = computed(() =>
  previewResult.value ? normalizePrice(previewResult.value.freight_amount) : '0.00',
)
const displayPayTotal = computed(() =>
  previewResult.value ? normalizePrice(previewResult.value.pay_amount) : goodsTotal.value,
)
const hasFreight = computed(() => isPositivePrice(displayFreight.value))
const isFreeFreight = computed(() => previewResult.value && isZeroPrice(displayFreight.value))
const pointsDeduction = computed(() =>
  pointsFeatureEnabled.value ? previewResult.value?.points_deduction || null : null,
)
const pointsReward = computed(() =>
  pointsFeatureEnabled.value ? previewResult.value?.points_reward || null : null,
)
const memberDiscount = computed(() => previewResult.value?.member_discount || null)
const canUsePoints = computed(() => {
  if (!pointsFeatureEnabled.value) return false
  const deduction = pointsDeduction.value
  return !!deduction && deduction.enabled !== false && Number(deduction.usable_points || 0) > 0
})
const shouldUsePoints = computed(() => pointsFeatureEnabled.value && usePoints.value && canUsePoints.value)
const pointsDiscountAmount = computed(() => normalizePrice(pointsDeduction.value?.discount_amount || '0.00'))
const hasPointsDiscount = computed(() => shouldUsePoints.value && isPositivePrice(pointsDiscountAmount.value))
const memberDiscountAmount = computed(() => normalizePrice(memberDiscount.value?.discount_amount || '0.00'))
const hasMemberDiscount = computed(() => isPositivePrice(memberDiscountAmount.value))
const rewardPoints = computed(() => Number(pointsReward.value?.reward_points || 0))
const hasPointsReward = computed(() =>
  !!pointsReward.value && pointsReward.value.enabled !== false && rewardPoints.value > 0,
)
const rewardMetaText = computed(() => {
  const freezeDays = Number(pointsReward.value?.freeze_days || 0)
  return freezeDays > 0
    ? `订单完成后冻结 ${freezeDays} 天，售后期结束后发放`
    : '订单完成后发放'
})

function hasItemMemberDiscount(item) {
  return isPositivePrice(item?.member_discount_amount || '0.00')
}

function itemMemberDiscountAmount(item) {
  return normalizePrice(item?.member_discount_amount || '0.00')
}

function itemRewardPoints(item) {
  return Number(item?.points_reward_points || 0)
}

function onUsePointsChange(event) {
  if (!canUsePoints.value) {
    usePoints.value = false
    return
  }
  usePoints.value = !!event.detail.value
}

/**
 * 调用后端订单试算，获取含运费的权威金额
 */
async function fetchPreview() {
  if (!address.value || !isAddressValid.value || orderItems.value.length === 0) {
    previewResult.value = null
    return
  }
  const payload =
    source.value === 'sku'
      ? {
          source: 'sku',
          address_id: address.value.id,
          use_points: shouldUsePoints.value ? 1 : 0,
          items: [{ sku_id: Number(skuId.value), quantity: Number(quantity.value) || 1 }],
        }
      : {
          source: 'cart',
          address_id: address.value.id,
          use_points: shouldUsePoints.value ? 1 : 0,
          cart_ids: selectedCartIds.value,
        }
  try {
    const result = await previewOrder(payload)
    previewResult.value = result
    if (Array.isArray(result?.items) && result.items.length > 0) {
      orderItems.value = result.items
    }
    const deduction = result?.points_deduction
    if (usePoints.value && (!deduction || Number(deduction.used_points || 0) <= 0)) {
      usePoints.value = false
    }
  } catch {
    previewResult.value = null
    usePoints.value = false
  }
}

// 收货地址变化（默认地址加载完成 / 用户重新选择）后重新试算
watch(address, fetchPreview)
watch(usePoints, fetchPreview)
watch(pointsFeatureEnabled, fetchPreview)

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
          items: [
            {
              sku_id: Number(skuId.value),
              quantity: Number(quantity.value) || 1,
            },
          ],
          buyer_remark: remark.value,
          idempotency_key: idempotencyKey.value,
          use_points: shouldUsePoints.value ? 1 : 0,
          points_used: shouldUsePoints.value ? Number(pointsDeduction.value?.used_points || 0) : 0,
        }
      : {
          source: 'cart',
          address_id: address.value.id,
          cart_ids: selectedCartIds.value,
          buyer_remark: remark.value,
          idempotency_key: idempotencyKey.value,
          use_points: shouldUsePoints.value ? 1 : 0,
          points_used: shouldUsePoints.value ? Number(pointsDeduction.value?.used_points || 0) : 0,
        }

  try {
    const order = await createOrder(payload)
    const sn = order.sn
    const orderId = order.order_id

    // 暂存订单上下文，sheet 关闭/选完支付方式后跳转 pay-result
    pendingPayContext.value = { sn, orderId }

    const payResult = await startPay(orderId)
    if (payResult) redirectToPayResult(sn, orderId, payResult)
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
  background-color: var(--color-bg-secondary, #faf8ff);
  padding: 0 $mb-spacing-page $mb-spacing-lg;
}

// ---- Card base ----
.card {
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-lg;
  padding: $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  border: 1rpx solid var(--color-divider, #f0f2f5);
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
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
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
  background: var(--color-primary, #0d50d5);
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
    background: var(--color-bg, #ffffff);
  }
}

.pin__body {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 4rpx;
  height: 10rpx;
  background: var(--color-primary, #0d50d5);
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
  color: var(--color-text-title, #191b23);
}

.address-card__phone {
  font-size: $mb-font-sm;
  color: var(--color-text-secondary, #434654);
}

.address-card__detail {
  font-size: $mb-font-md;
  color: var(--color-text-secondary, #434654);
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
  color: var(--color-error, #ba1a1a);
}

.address-card__empty {
  flex: 1;
}

.address-card__empty-text {
  font-size: $mb-font-md;
  color: var(--color-text-tertiary, #737686);
}

.address-card__arrow {
  flex-shrink: 0;
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
  margin-left: $mb-spacing-xs;
}

// ---- Address stripe ----
.address-stripe {
  height: 4rpx;
  margin-bottom: $mb-spacing-md;
  border-radius: 0 0 $mb-radius-lg $mb-radius-lg;
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.12));
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
  color: var(--color-text-title, #191b23);
}

.goods-item {
  display: flex;
  gap: $mb-spacing-md;
  padding: $mb-spacing-sm 0;

  & + & {
    border-top: 1rpx solid var(--color-divider, #f0f2f5);
  }
}

.goods-item__img {
  flex-shrink: 0;
  width: 160rpx;
  height: 160rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-bg-surface, #f3f3fe);
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
  color: var(--color-text-title, #191b23);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.goods-item__spec {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-sm;
  padding: 4rpx 12rpx;
  align-self: flex-start;
  margin-top: 8rpx;
}

.goods-item__benefit {
  margin-top: 6rpx;
  font-size: 22rpx;
  line-height: 1.35;
  color: var(--color-primary, #0d50d5);
}

.goods-item__bottom {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-top: auto;
}

.goods-item__qty {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
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
  color: var(--color-text-title, #191b23);
  flex-shrink: 0;
}

.delivery-card__right {
  display: flex;
  align-items: center;
}

.delivery-card__value {
  font-size: $mb-font-md;
  color: var(--color-text-secondary, #434654);
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
  color: var(--color-text-title, #191b23);
}

.remark-card__input {
  flex: 1;
  font-size: $mb-font-md;
  color: var(--color-text, #191b23);
  text-align: right;
}

.remark-placeholder {
  color: var(--color-text-tertiary, #737686);
}

// ---- Points card ----
.points-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-md;
  padding: $mb-spacing-md $mb-spacing-lg;
}

.points-card__main {
  flex: 1;
  min-width: 0;
}

.points-card__title-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  margin-bottom: 6rpx;
}

.points-card__title {
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-text-title, #191b23);
}

.points-card__discount,
.points-card__reward,
.summary-row__discount {
  font-size: $mb-font-md;
  font-weight: 600;
  color: var(--color-primary, #0d50d5);
}

.points-card__meta {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary, #737686);
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
  color: var(--color-text-secondary, #434654);
}

.summary-row__free {
  font-size: $mb-font-md;
  color: var(--color-success, #34c759);
  font-weight: 500;
}

.summary-divider {
  height: 1rpx;
  background: var(--color-divider, #f0f2f5);
  margin: 8rpx 0;
}

.summary-row--total {
  .summary-row__label {
    font-size: $mb-font-lg;
    font-weight: 600;
    color: var(--color-text-title, #191b23);
  }
}

// ---- Submit bar ----
.submit-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  background: var(--color-bg, #ffffff);
  box-shadow: $mb-shadow-bar;
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
  color: var(--color-text-tertiary, #737686);
  letter-spacing: 1rpx;
  font-weight: 500;
}

.submit-bar__btn {
  height: 88rpx;
  min-width: 260rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 $mb-spacing-xl;
  box-shadow: none;
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
  color: var(--color-text-inverse, #ffffff);
  letter-spacing: 0;
}

// ---- Bottom spacer for fixed bar ----
.bottom-spacer {
  height: calc(140rpx + env(safe-area-inset-bottom));
}
</style>
