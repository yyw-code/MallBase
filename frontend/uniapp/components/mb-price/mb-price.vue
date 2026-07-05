<template>
  <view class="mb-price" :class="[`mb-price--${size}`]">
    <text class="mb-price__symbol" :style="{ color }">¥</text>
    <text class="mb-price__integer" :style="{ color }">{{ integer }}</text>
    <text v-if="showDecimal && decimal !== '00'" class="mb-price__decimal" :style="{ color }">.{{ decimal }}</text>
  </view>
</template>

<script setup>
import { computed } from 'vue'
import { splitPrice } from '@/utils/price'

const props = defineProps({
  value: { type: [Number, String], default: 0 },
  size: { type: String, default: 'md' },
  color: { type: String, default: 'var(--color-text-title, #191b23)' },
  showDecimal: { type: Boolean, default: true },
})

const priceParts = computed(() => splitPrice(props.value))
const integer = computed(() => {
  return priceParts.value.integer
})

const decimal = computed(() => {
  return priceParts.value.decimal
})
</script>

<style scoped>
.mb-price {
  display: inline-flex;
  align-items: baseline;
  flex-shrink: 0;
  line-height: 1;
  white-space: nowrap;
}

.mb-price__symbol {
  display: inline-block;
  font-weight: 600;
  line-height: 1;
  white-space: nowrap;
}

.mb-price__integer {
  display: inline-block;
  font-weight: 700;
  line-height: 1;
  white-space: nowrap;
}

.mb-price__decimal {
  display: inline-block;
  font-weight: 600;
  line-height: 1;
  white-space: nowrap;
}

.mb-price--sm .mb-price__symbol { font-size: 20rpx; }
.mb-price--sm .mb-price__integer { font-size: 24rpx; }
.mb-price--sm .mb-price__decimal { font-size: 20rpx; }

.mb-price--md .mb-price__symbol { font-size: 24rpx; }
.mb-price--md .mb-price__integer { font-size: 32rpx; }
.mb-price--md .mb-price__decimal { font-size: 24rpx; }

.mb-price--lg .mb-price__symbol { font-size: 28rpx; }
.mb-price--lg .mb-price__integer { font-size: 44rpx; }
.mb-price--lg .mb-price__decimal { font-size: 28rpx; }

.mb-price--xl .mb-price__symbol { font-size: 32rpx; }
.mb-price--xl .mb-price__integer { font-size: 56rpx; }
.mb-price--xl .mb-price__decimal { font-size: 32rpx; }
</style>
