<template>
  <view class="mb-skeleton">
    <view
      v-for="row in rows"
      :key="row.id"
      class="mb-skeleton__row"
      :style="{
        width: row.width,
        height: row.height,
        borderRadius: row.round ? '50%' : radius,
      }"
    />
  </view>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  type: { type: String, default: 'lines' },
  count: { type: Number, default: 3 },
  radius: { type: String, default: '8rpx' },
})

const rows = computed(() => {
  if (props.type === 'card') {
    return [
      { id: 'img', width: '100%', height: '300rpx', round: false },
      { id: 'title', width: '70%', height: '28rpx', round: false },
      { id: 'sub', width: '40%', height: '24rpx', round: false },
    ]
  }
  if (props.type === 'avatar-lines') {
    return [
      { id: 'avatar', width: '80rpx', height: '80rpx', round: true },
      { id: 'l1', width: '60%', height: '28rpx', round: false },
      { id: 'l2', width: '40%', height: '24rpx', round: false },
    ]
  }
  return Array.from({ length: props.count }, (_, i) => ({
    id: `line-${i}`,
    width: i === props.count - 1 ? '60%' : '100%',
    height: '28rpx',
    round: false,
  }))
})
</script>

<style scoped>
.mb-skeleton {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
  padding: 24rpx;
}

.mb-skeleton__row {
  background: linear-gradient(
    90deg,
    var(--color-bg-secondary, #f7f9fb) 25%,
    #eef0f3 50%,
    var(--color-bg-secondary, #f7f9fb) 75%
  );
  background-size: 200% 100%;
  animation: mb-shimmer 1.5s infinite ease-in-out;
}

@keyframes mb-shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
</style>
