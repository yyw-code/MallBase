<template>
  <view
    v-if="normalizedItems.length"
    class="mb-benefit-strip"
    :class="[`mb-benefit-strip--${size}`, `mb-benefit-strip--${variant}`]"
  >
    <view
      v-for="item in normalizedItems"
      :key="item.key"
      class="mb-benefit-strip__item"
      :class="[`mb-benefit-strip__item--${item.tone}`]"
    >
      <text v-if="item.label" class="mb-benefit-strip__label">
        {{ item.label }}
      </text>
      <text class="mb-benefit-strip__value">{{ item.value }}</text>
    </view>
  </view>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
  items: { type: Array, default: () => [] },
  size: { type: String, default: "md" },
  variant: { type: String, default: "default" },
});

const normalizedItems = computed(() =>
  props.items
    .map((item, index) => ({
      key: item.key || `${item.label || ""}:${item.value || ""}:${index}`,
      label: String(item.label || ""),
      value: String(item.value || ""),
      tone: item.tone || "default",
    }))
    .filter((item) => item.value !== ""),
);
</script>

<style scoped>
.mb-benefit-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 10rpx;
}

.mb-benefit-strip--compact {
  gap: 8rpx;
}

.mb-benefit-strip__item {
  display: inline-flex;
  align-items: center;
  max-width: 100%;
  min-height: 38rpx;
  padding: 4rpx 12rpx;
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
  border-radius: 999rpx;
  color: var(--color-primary, #0d50d5);
  box-sizing: border-box;
}

.mb-benefit-strip--card .mb-benefit-strip__item {
  min-height: 44rpx;
  padding: 6rpx 14rpx;
  background: var(--color-bg-surface, #f8fafc);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 12rpx;
}

.mb-benefit-strip--compact .mb-benefit-strip__item {
  min-height: 34rpx;
  padding: 3rpx 10rpx;
}

.mb-benefit-strip__label {
  margin-right: 8rpx;
  color: inherit;
  font-size: 22rpx;
  font-weight: 700;
  white-space: nowrap;
}

.mb-benefit-strip__value {
  min-width: 0;
  color: inherit;
  font-size: 23rpx;
  font-weight: 600;
  line-height: 1.35;
  word-break: break-all;
}

.mb-benefit-strip--compact .mb-benefit-strip__label,
.mb-benefit-strip--compact .mb-benefit-strip__value {
  font-size: 22rpx;
}

.mb-benefit-strip__item--member {
  color: #b45309;
  background: rgba(245, 158, 11, 0.12);
}

.mb-benefit-strip__item--points {
  color: #0d50d5;
}

.mb-benefit-strip__item--growth {
  color: #047857;
  background: rgba(16, 185, 129, 0.12);
}
</style>
