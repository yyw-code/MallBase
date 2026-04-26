<template>
  <view v-if="visible" class="mb-spec" @tap.self="close">
    <view class="mb-spec__panel" :class="{ 'mb-spec__panel--show': show }">
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
        </view>
        <view class="mb-spec__close" @tap="close">
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
                'mb-spec__tag--active': selectedSpecs[group.name] === val,
                'mb-spec__tag--disabled': isSpecDisabled(group.name, val),
              }"
              @tap="selectSpec(group.name, val)"
            >
              <text class="mb-spec__tag-text">{{ val }}</text>
            </view>
          </view>
        </view>

        <!-- quantity -->
        <view class="mb-spec__quantity">
          <text class="mb-spec__quantity-label">数量</text>
          <mb-quantity-stepper v-model="quantity" :max="currentStock" />
        </view>
      </scroll-view>

      <!-- actions -->
      <view class="mb-spec__footer">
        <view v-if="mode === 'both'" class="mb-spec__actions mb-spec__actions--dual">
          <view class="mb-spec__btn mb-spec__btn--cart" @tap="onAddToCart">
            <text class="mb-spec__btn-text">加入购物车</text>
          </view>
          <view class="mb-spec__btn mb-spec__btn--buy" @tap="onBuyNow">
            <text class="mb-spec__btn-text mb-spec__btn-text--light">立即购买</text>
          </view>
        </view>
        <view v-else class="mb-spec__actions">
          <view
            class="mb-spec__btn"
            :class="mode === 'cart' ? 'mb-spec__btn--cart-full' : 'mb-spec__btn--buy-full'"
            @tap="mode === 'cart' ? onAddToCart() : onBuyNow()"
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
})

const emit = defineEmits(['close', 'addToCart', 'buyNow'])

const show = ref(false)
const quantity = ref(1)
const selectedSpecs = ref({})

const specGroups = computed(() => {
  const meta = props.goods.spec_meta
  if (!Array.isArray(meta) || meta.length === 0) return []
  return meta.map((group) => ({
    name: group.name,
    values: group.values.map((v) => v.value),
  }))
})

const selectedSku = computed(() => {
  if (specGroups.value.length === 0 && props.skuList.length === 1) {
    return props.skuList[0]
  }
  const selected = selectedSpecs.value
  const groupCount = specGroups.value.length
  const selectedCount = Object.keys(selected).length
  if (selectedCount < groupCount) return null

  const selectedStr = specGroups.value.map((g) => selected[g.name]).join(',')
  return props.skuList.find((sku) => sku.spec_values === selectedStr) || null
})

const currentPrice = computed(() => selectedSku.value?.price ?? props.goods.price ?? 0)
const currentStock = computed(() => selectedSku.value?.stock ?? props.goods.stock ?? 0)

const selectedSpecText = computed(() =>
  specGroups.value
    .map((g) => selectedSpecs.value[g.name])
    .filter(Boolean)
    .join(' / '),
)

function isSpecDisabled(groupName, value) {
  const trial = { ...selectedSpecs.value, [groupName]: value }
  return !props.skuList.some((sku) => {
    if (!sku.spec_values || sku.stock <= 0) return false
    const skuValues = sku.spec_values.split(',')
    return specGroups.value.every((group, idx) => {
      const trialVal = trial[group.name]
      if (!trialVal) return true
      return skuValues[idx] === trialVal
    })
  })
}

function selectSpec(groupName, value) {
  if (isSpecDisabled(groupName, value)) return
  if (selectedSpecs.value[groupName] === value) {
    const next = { ...selectedSpecs.value }
    delete next[groupName]
    selectedSpecs.value = next
  } else {
    selectedSpecs.value = { ...selectedSpecs.value, [groupName]: value }
  }
}

function validate() {
  if (specGroups.value.length > 0 && !selectedSku.value) {
    uni.showToast({ title: '请选择规格', icon: 'none' })
    return false
  }
  if (currentStock.value <= 0) {
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
    if (specGroups.value.length === 0 || props.skuList.length <= 1) {
      selectedSpecs.value = {}
    }
    nextTick(() => { show.value = true })
  }
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
  background: #ffffff;
  border-radius: 32rpx 32rpx 0 0;
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
  border-bottom: 1rpx solid var(--color-border, #e0e3e5);
}

.mb-spec__thumb {
  width: 160rpx;
  height: 160rpx;
  border-radius: 16rpx;
  flex-shrink: 0;
  background: #eef0f3;
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
  color: var(--color-text-tertiary, #848484);
}

.mb-spec__selected {
  font-size: 24rpx;
  color: var(--color-text-secondary, #5e5e5e);
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
  color: var(--color-text-tertiary, #848484);
}

.mb-spec__body {
  flex: 1;
  padding: 32rpx;
  max-height: 50vh;
}

.mb-spec__group {
  margin-bottom: 32rpx;
}

.mb-spec__group-title {
  font-size: 26rpx;
  font-weight: 600;
  color: var(--color-text, #1b1b1b);
  margin-bottom: 20rpx;
}

.mb-spec__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
}

.mb-spec__tag {
  padding: 12rpx 32rpx;
  border-radius: 999rpx;
  background: var(--color-bg-secondary, #f7f9fb);
  border: 2rpx solid transparent;
}

.mb-spec__tag--active {
  background: rgba(13, 80, 213, 0.08);
  border-color: var(--color-primary, #0d50d5);
}

.mb-spec__tag--disabled {
  opacity: 0.35;
  pointer-events: none;
}

.mb-spec__tag-text {
  font-size: 26rpx;
  color: var(--color-text, #1b1b1b);
}

.mb-spec__tag--active .mb-spec__tag-text {
  color: var(--color-primary, #0d50d5);
  font-weight: 500;
}

.mb-spec__quantity {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 24rpx;
  border-top: 1rpx solid var(--color-border, #e0e3e5);
}

.mb-spec__quantity-label {
  font-size: 26rpx;
  font-weight: 600;
  color: var(--color-text, #1b1b1b);
}

.mb-spec__footer {
  padding: 24rpx 32rpx;
  padding-bottom: calc(24rpx + env(safe-area-inset-bottom));
  border-top: 1rpx solid var(--color-border, #e0e3e5);
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
  border-radius: 44rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

.mb-spec__btn--cart {
  background: var(--color-bg-secondary, #f7f9fb);
  border: 2rpx solid var(--color-border, #e0e3e5);
}

.mb-spec__btn--buy {
  background: #000000;
}

.mb-spec__btn--cart-full {
  width: 100%;
  background: var(--color-primary, #0d50d5);
}

.mb-spec__btn--buy-full {
  width: 100%;
  background: #000000;
}

.mb-spec__btn-text {
  font-size: 28rpx;
  font-weight: 600;
  color: var(--color-text, #1b1b1b);
}

.mb-spec__btn-text--light {
  color: #ffffff;
}

.mb-spec__btn--cart-full .mb-spec__btn-text {
  color: #ffffff;
}
</style>
