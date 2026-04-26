<template>
  <view class="mb-price" :class="[`mb-price--${size}`]">
    <text class="mb-price__symbol" :style="{ color }">¥</text>
    <text class="mb-price__integer" :style="{ color }">{{ integer }}</text>
    <text v-if="showDecimal && decimal !== '00'" class="mb-price__decimal" :style="{ color }">.{{ decimal }}</text>
  </view>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  value: { type: [Number, String], default: 0 },
  size: { type: String, default: 'md' },
  color: { type: String, default: 'var(--color-text-title, #131b2e)' },
  showDecimal: { type: Boolean, default: true },
})

const integer = computed(() => {
  const num = Number(props.value)
  if (Number.isNaN(num)) return '0'
  return Math.floor(num).toLocaleString('zh-CN')
})

const decimal = computed(() => {
  const num = Number(props.value)
  if (Number.isNaN(num)) return '00'
  return num.toFixed(2).split('.')[1]
})
</script>

<style scoped>
.mb-price {
  display: inline-flex;
  align-items: baseline;
}

.mb-price__symbol {
  font-weight: 600;
}

.mb-price__integer {
  font-weight: 700;
}

.mb-price__decimal {
  font-weight: 600;
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
