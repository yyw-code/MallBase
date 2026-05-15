<template>
  <view class="mb-empty" :style="{ paddingTop }">
    <view class="mb-empty__icon" :class="iconClass">
      <text v-if="normalizedIcon" class="mb-empty__icon-text">{{ normalizedIcon }}</text>
      <view v-else class="mb-empty__box-icon">
        <view class="mb-empty__box-lid" />
        <view class="mb-empty__box-body" />
      </view>
    </view>
    <text class="mb-empty__text">{{ text }}</text>
    <view v-if="actionText" class="mb-empty__action" @tap="$emit('action')">
      <text class="mb-empty__action-text">{{ actionText }}</text>
    </view>
  </view>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  icon: { type: String, default: '' },
  text: { type: String, default: '暂无数据' },
  actionText: { type: String, default: '' },
  paddingTop: { type: String, default: '240rpx' },
})

defineEmits(['action'])

const emojiLike = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u

const normalizedIcon = computed(() => {
  const value = String(props.icon || '').trim()
  if (!value || emojiLike.test(value) || value.startsWith('&#')) return ''
  return value.slice(0, 2)
})

const iconClass = computed(() => (normalizedIcon.value ? 'mb-empty__icon--text' : 'mb-empty__icon--box'))
</script>

<style scoped>
.mb-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-left: 48rpx;
  padding-right: 48rpx;
}

.mb-empty__icon {
  width: 112rpx;
  height: 112rpx;
  border-radius: var(--radius-lg, 20rpx);
  margin-bottom: 24rpx;
  background: rgba(13, 80, 213, 0.06);
  border: 1rpx solid rgba(13, 80, 213, 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
}

.mb-empty__icon-text {
  font-size: 34rpx;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
}

.mb-empty__box-icon {
  position: relative;
  width: 52rpx;
  height: 44rpx;
}

.mb-empty__box-lid {
  position: absolute;
  left: 5rpx;
  top: 0;
  width: 42rpx;
  height: 14rpx;
  border: 4rpx solid var(--color-primary, #0d50d5);
  border-bottom: 0;
  border-radius: 8rpx 8rpx 0 0;
}

.mb-empty__box-body {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 52rpx;
  height: 34rpx;
  border: 4rpx solid var(--color-primary, #0d50d5);
  border-radius: 8rpx;
  background: rgba(255, 255, 255, 0.65);
}

.mb-empty__box-body::after {
  content: '';
  position: absolute;
  left: 14rpx;
  right: 14rpx;
  top: 10rpx;
  height: 4rpx;
  border-radius: 999rpx;
  background: var(--color-primary, #0d50d5);
  opacity: 0.5;
}

.mb-empty__text {
  font-size: 28rpx;
  color: var(--color-text-tertiary, #737686);
  margin-bottom: 32rpx;
  text-align: center;
  line-height: 1.5;
}

.mb-empty__action {
  min-width: 224rpx;
  height: 80rpx;
  padding: 0 48rpx;
  border-radius: 999rpx;
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;
}

.mb-empty__action-text {
  font-size: 26rpx;
  font-weight: 600;
  color: #ffffff;
}
</style>
