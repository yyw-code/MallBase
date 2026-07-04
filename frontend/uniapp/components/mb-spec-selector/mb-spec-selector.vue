<template>
  <view v-if="visible" class="mb-spec" @tap.stop="close">
    <view class="mb-spec__panel" :class="{ 'mb-spec__panel--show': show }" @tap.stop>
      <!-- header -->
      <view class="mb-spec__header">
        <image
          v-if="selectedSku?.image_full_url || goods.main_image_full_url"
          class="mb-spec__thumb"
          :src="selectedSku?.image_full_url || goods.main_image_full_url"
          mode="aspectFill"
        />
        <view class="mb-spec__header-info">
          <mb-price :value="currentPrice" size="lg" color="var(--color-primary, #0d50d5)" />
          <text class="mb-spec__stock">库存 {{ currentStock }}</text>
          <text v-if="selectedSpecText" class="mb-spec__selected">已选：{{ selectedSpecText }}</text>
          <view v-if="hasSkuBenefit" class="mb-spec__benefits">
            <text v-if="hasSelectedMemberPrice" class="mb-spec__benefit">会员价 ¥{{ selectedMemberPriceText }}</text>
            <text v-if="pointsRewardText" class="mb-spec__benefit">{{ pointsRewardText }}</text>
            <text v-if="memberGrowthText" class="mb-spec__benefit">{{ memberGrowthText }}</text>
          </view>
        </view>
        <view class="mb-spec__close" @tap.stop="close">
          <text class="mb-spec__close-icon">✕</text>
        </view>
      </view>

      <!-- spec groups -->
      <scroll-view scroll-y class="mb-spec__body">
        <view v-for="group in specGroups" :key="group.name" class="mb-spec__group">
          <text class="mb-spec__group-title">{{ group.name }}</text>
          <view class="mb-spec__tags">
            <view
              v-for="val in group.values"
              :key="val"
              class="mb-spec__tag"
              :class="{
                'mb-spec__tag--color': isColorGroup(group.name),
                'mb-spec__tag--active': selectedSpecs[group.name] === val,
                'mb-spec__tag--disabled': isSpecDisabled(group.name, val),
              }"
              @tap.stop="selectSpec(group.name, val)"
            >
              <text class="mb-spec__tag-text">{{ val }}</text>
            </view>
          </view>
        </view>

        <!-- quantity -->
        <view class="mb-spec__quantity">
          <text class="mb-spec__quantity-label">数量</text>
          <mb-quantity-stepper
            v-model="quantity"
            class="mb-spec__quantity-stepper"
            :max="quantityMax"
          />
        </view>
      </scroll-view>

      <!-- actions -->
      <view class="mb-spec__footer">
        <view v-if="mode === 'both'" class="mb-spec__actions mb-spec__actions--dual">
          <view class="mb-spec__btn mb-spec__btn--cart" @tap.stop="onAddToCart">
            <text class="mb-spec__btn-text">加入购物车</text>
          </view>
          <view class="mb-spec__btn mb-spec__btn--buy" @tap.stop="onBuyNow">
            <text class="mb-spec__btn-text mb-spec__btn-text--light">立即购买</text>
          </view>
        </view>
        <view v-else class="mb-spec__actions">
          <view
            class="mb-spec__btn"
            :class="mode === 'cart' ? 'mb-spec__btn--cart-full' : 'mb-spec__btn--buy-full'"
            @tap.stop="mode === 'cart' ? onAddToCart() : onBuyNow()"
          >
            <text class="mb-spec__btn-text" :class="{ 'mb-spec__btn-text--light': mode === 'buy' }">
              {{ mode === 'cart' ? '加入购物车' : '立即购买' }}
            </text>
          </view>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  goods: { type: Object, default: () => ({}) },
  skuList: { type: Array, default: () => [] },
  mode: { type: String, default: 'both' },
  selectedSpecs: { type: Object, default: () => ({}) },
  selectedSkuId: { type: [Number, String], default: null },
  pointsEnabled: { type: Boolean, default: true },
  memberEnabled: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'change', 'addToCart', 'buyNow'])

const show = ref(false)
const quantity = ref(1)

const specGroups = computed(() => {
  const meta = props.goods.spec_meta
  if (!Array.isArray(meta) || meta.length === 0) return []
  return meta.map((group) => ({
    name: group.name,
    values: Array.isArray(group.values) ? group.values.map((v) => v.value) : [],
  }))
})

const selectedSpecs = computed(() => props.selectedSpecs || {})

const selectedSku = computed(() => {
  if (specGroups.value.length === 0 && props.skuList.length === 1) {
    return props.skuList[0]
  }

  if (props.selectedSkuId) {
    const found = props.skuList.find((sku) => String(sku.id) === String(props.selectedSkuId))
    if (found) return found
  }

  return findSkuBySpecs(selectedSpecs.value)
})

const currentPrice = computed(() => selectedSku.value?.price ?? props.goods.price ?? 0)
const currentStock = computed(() => selectedSku.value?.stock ?? props.goods.stock ?? 0)
const quantityMax = computed(() => Math.max(1, Number(currentStock.value) || 0))
const selectedMemberPrice = computed(() => {
  if (!props.memberEnabled || props.goods.member_benefit_mode !== 'sku_price') return ''
  const price = Number(selectedSku.value?.price ?? props.goods.price ?? 0)
  const memberPrice = Number(selectedSku.value?.member_price ?? 0)
  if (!memberPrice || memberPrice >= price) return ''
  return selectedSku.value?.member_price
})
const hasSelectedMemberPrice = computed(() => selectedMemberPrice.value !== '')
const selectedMemberPriceText = computed(() => formatAmount(selectedMemberPrice.value))
const pointsRewardText = computed(() => {
  const previewText = selectedSku.value?.points_reward_preview_text || props.goods.points_reward_preview_text
  if (previewText) return previewText
  return legacyPointsRewardText.value
})
const memberGrowthText = computed(() => {
  if (!props.memberEnabled) return ''
  return selectedSku.value?.member_growth_preview_text || props.goods.member_growth_preview_text || ''
})
const legacyPointsRewardText = computed(() => {
  if (!props.pointsEnabled) return ''
  const mode = props.goods.points_reward_mode || 'global'
  if (mode === 'disabled') return ''
  if (mode === 'ratio') return rewardRatioText(props.goods.points_reward_ratio)
  if (mode === 'fixed') return rewardFixedText(props.goods.points_reward_fixed)

  if (mode === 'sku') {
    const skuMode = selectedSku.value?.points_reward_mode || 'inherit'
    if (skuMode === 'disabled') return ''
    if (skuMode === 'ratio') return rewardRatioText(selectedSku.value?.points_reward_ratio)
    if (skuMode === 'fixed') return rewardFixedText(selectedSku.value?.points_reward_fixed)
  }

  return '按全局规则赠送积分'
})
const hasSkuBenefit = computed(() => hasSelectedMemberPrice.value || !!pointsRewardText.value || !!memberGrowthText.value)

const selectedSpecText = computed(() =>
  specGroups.value
    .map((g) => selectedSpecs.value[g.name])
    .filter(Boolean)
    .join(' / '),
)

function findSkuBySpecs(specs) {
  if (specGroups.value.length === 0) return props.skuList[0] || null
  if (Object.keys(specs).length < specGroups.value.length) return null

  const selectedValues = specGroups.value.map((group) => specs[group.name] || '')
  if (selectedValues.some((value) => value === '')) return null

  const selectedStr = selectedValues.join(',')
  return props.skuList.find((sku) => sku.spec_values === selectedStr) || null
}

function isColorGroup(name) {
  if (!name) return false
  const s = String(name).toLowerCase()
  return s.includes('颜色') || s.includes('color') || s.includes('款式')
}

function formatAmount(value) {
  const num = Number(value)
  if (Number.isNaN(num)) return '0'
  const int = Math.floor(num).toLocaleString('zh-CN')
  const dec = num.toFixed(2).split('.')[1]
  return dec === '00' ? int : `${int}.${dec}`
}

function rewardRatioText(value) {
  const ratio = Number(value || 0)
  return ratio > 0 ? `每消费 1 元赠 ${ratio} 积分` : ''
}

function rewardFixedText(value) {
  const fixed = Number(value || 0)
  return fixed > 0 ? `每件赠 ${fixed} 积分` : ''
}

function isSpecDisabled(groupName, value) {
  const trial = { ...selectedSpecs.value, [groupName]: value }
  return !props.skuList.some((sku) => {
    if (!sku.spec_values || Number(sku.stock) <= 0) return false
    const skuValues = String(sku.spec_values).split(',')
    return specGroups.value.every((group, idx) => {
      const trialVal = trial[group.name]
      if (!trialVal) return true
      return skuValues[idx] === trialVal
    })
  })
}

function selectSpec(groupName, value) {
  if (isSpecDisabled(groupName, value)) return

  const next = { ...selectedSpecs.value }
  if (next[groupName] === value) {
    delete next[groupName]
  } else {
    next[groupName] = value
  }

  emit('change', {
    selectedSpecs: next,
    sku: findSkuBySpecs(next),
  })
}

function validate() {
  if (specGroups.value.length > 0 && !selectedSku.value) {
    uni.showToast({ title: '请选择规格', icon: 'none' })
    return false
  }
  if (Number(currentStock.value) <= 0) {
    uni.showToast({ title: '库存不足', icon: 'none' })
    return false
  }
  return true
}

function onAddToCart() {
  if (!validate()) return
  emit('addToCart', { sku: selectedSku.value, quantity: quantity.value })
}

function onBuyNow() {
  if (!validate()) return
  emit('buyNow', { sku: selectedSku.value, quantity: quantity.value })
}

function close() {
  show.value = false
  setTimeout(() => emit('close'), 300)
}

watch(() => props.visible, (val) => {
  if (val) {
    quantity.value = 1
    nextTick(() => { show.value = true })
  }
})

watch(currentStock, (stock) => {
  const max = Math.max(1, Number(stock) || 0)
  if (quantity.value > max) quantity.value = max
})
</script>

<style scoped>
.mb-spec {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: flex-end;
}

.mb-spec__panel {
  width: 100%;
  max-height: 80vh;
  background: var(--color-bg, #ffffff);
  border-radius: var(--radius-lg, 20rpx) var(--radius-lg, 20rpx) 0 0;
  display: flex;
  flex-direction: column;
  transform: translateY(100%);
  transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.mb-spec__panel--show {
  transform: translateY(0);
}

.mb-spec__header {
  display: flex;
  padding: 32rpx;
  gap: 24rpx;
  border-bottom: 1rpx solid var(--color-border, #e0e4e8);
}

.mb-spec__thumb {
  width: 160rpx;
  height: 160rpx;
  border-radius: var(--radius-md, 12rpx);
  flex-shrink: 0;
  background: var(--color-bg-secondary, #faf8ff);
}

.mb-spec__header-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 8rpx;
}

.mb-spec__stock {
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.mb-spec__selected {
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
}

.mb-spec__benefits {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8rpx;
}

.mb-spec__benefit {
  max-width: 100%;
  padding: 4rpx 10rpx;
  border-radius: 999rpx;
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
  color: var(--color-primary, #0d50d5);
  font-size: 22rpx;
  font-weight: 600;
}

.mb-spec__close {
  width: 48rpx;
  height: 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.mb-spec__close-icon {
  font-size: 28rpx;
  color: var(--color-text-tertiary, #737686);
}

.mb-spec__body {
  flex: 1;
  padding: 32rpx;
  max-height: 50vh;
  box-sizing: border-box;
}

.mb-spec__group {
  margin-bottom: 32rpx;
}

.mb-spec__group-title {
  font-size: 26rpx;
  font-weight: 600;
  color: var(--color-text, #191b23);
  margin-bottom: 20rpx;
}

.mb-spec__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}

.mb-spec__tag {
  min-width: 96rpx;
  height: 64rpx;
  padding: 0 28rpx;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 16rpx;
  background: var(--color-bg-secondary, #faf8ff);
  border: 2rpx solid transparent;
  box-sizing: border-box;
}

.mb-spec__tag--color {
  border-radius: 999rpx;
  padding: 0 36rpx;
}

.mb-spec__tag--active {
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
  border-color: var(--color-primary, #0d50d5);
}

.mb-spec__tag--disabled {
  opacity: 0.35;
  pointer-events: none;
}

.mb-spec__tag-text {
  font-size: 26rpx;
  color: var(--color-text, #191b23);
  line-height: 1;
}

.mb-spec__tag--active .mb-spec__tag-text {
  color: var(--color-primary, #0d50d5);
  font-weight: 500;
}

.mb-spec__quantity {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24rpx;
  width: 100%;
  padding-top: 24rpx;
  border-top: 1rpx solid var(--color-border, #e0e4e8);
  box-sizing: border-box;
}

.mb-spec__quantity-label {
  flex: 1;
  min-width: 0;
  font-size: 26rpx;
  font-weight: 600;
  color: var(--color-text, #191b23);
}

.mb-spec__quantity-stepper {
  flex-shrink: 0;
}

.mb-spec__footer {
  padding: 24rpx 32rpx;
  padding-bottom: calc(24rpx + env(safe-area-inset-bottom));
  border-top: 1rpx solid var(--color-border, #e0e4e8);
}

.mb-spec__actions {
  display: flex;
  gap: 20rpx;
}

.mb-spec__actions--dual .mb-spec__btn {
  flex: 1;
}

.mb-spec__btn {
  height: 88rpx;
  border-radius: 999rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.15s;
}

.mb-spec__btn:active {
  opacity: 0.85;
}

.mb-spec__btn--cart {
  background: var(--color-bg, #ffffff);
  border: 2rpx solid var(--color-primary, #0d50d5);
}

.mb-spec__btn--buy {
  background: var(--color-primary, #0d50d5);
}

.mb-spec__btn--cart-full {
  width: 100%;
  background: var(--color-primary, #0d50d5);
}

.mb-spec__btn--buy-full {
  width: 100%;
  background: var(--color-primary, #0d50d5);
}

.mb-spec__btn-text {
  font-size: 28rpx;
  font-weight: 600;
  color: var(--color-primary, #0d50d5);
}

.mb-spec__btn-text--light {
  color: var(--color-text-inverse, #ffffff);
}

.mb-spec__btn--cart-full .mb-spec__btn-text {
  color: var(--color-text-inverse, #ffffff);
}
</style>
