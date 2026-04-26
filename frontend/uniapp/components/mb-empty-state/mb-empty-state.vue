<template>
  <view class="mb-empty" :style="{ paddingTop }">
    <text class="mb-empty__icon">{{ icon }}</text>
    <text class="mb-empty__text">{{ text }}</text>
    <view v-if="actionText" class="mb-empty__action" @tap="$emit('action')">
      <text class="mb-empty__action-text">{{ actionText }}</text>
    </view>
  </view>
</template>

<script setup>
defineProps({
  icon: { type: String, default: '📋' },
  text: { type: String, default: '暂无数据' },
  actionText: { type: String, default: '' },
  paddingTop: { type: String, default: '240rpx' },
})

defineEmits(['action'])
</script>

<style scoped>
.mb-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.mb-empty__icon {
  font-size: 80rpx;
  margin-bottom: 24rpx;
}

.mb-empty__text {
  font-size: 28rpx;
  color: var(--color-text-tertiary, #848484);
  margin-bottom: 32rpx;
}

.mb-empty__action {
  padding: 16rpx 48rpx;
  border-radius: 999rpx;
  border: 2rpx solid var(--color-primary, #0d50d5);
}

.mb-empty__action-text {
  font-size: 26rpx;
  font-weight: 500;
  color: var(--color-primary, #0d50d5);
}
</style>
