<template>
  <view>
    <view class="mb-navbar" :style="{ backgroundColor: bgColor }">
      <view v-if="accentLine" class="mb-navbar__accent" />
      <view class="mb-navbar__status" :style="{ height: statusBarHeight + 'px' }" />
      <view class="mb-navbar__content">
        <view v-if="back" class="mb-navbar__back" @tap="onBack">
          <text class="mb-navbar__back-icon" :style="{ color: textColor }">&#10094;</text>
        </view>
        <text class="mb-navbar__title" :style="{ color: textColor }">{{ title }}</text>
        <view class="mb-navbar__right">
          <slot name="right" />
        </view>
      </view>
    </view>
    <view class="mb-navbar__spacer" :style="{ height: totalHeight + 'px' }" />
  </view>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
  title: { type: String, default: '' },
  back: { type: Boolean, default: true },
  bgColor: { type: String, default: '#ffffff' },
  textColor: { type: String, default: 'var(--color-text, #1b1b1b)' },
  accentLine: { type: Boolean, default: true },
})

const emit = defineEmits(['back'])

const { statusBarHeight } = uni.getSystemInfoSync()
const navContentPx = uni.upx2px(88)
const accentPx = uni.upx2px(4)
const totalHeight = ref(statusBarHeight + navContentPx + accentPx)

function onBack() {
  emit('back')
  uni.navigateBack({ fail: () => {} })
}
</script>

<style scoped>
.mb-navbar {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 999;
}

.mb-navbar__accent {
  height: 4rpx;
  background-color: var(--color-primary, #0d50d5);
}

.mb-navbar__content {
  position: relative;
  display: flex;
  align-items: center;
  height: 88rpx;
  padding: 0 24rpx;
}

.mb-navbar__back {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 64rpx;
  height: 64rpx;
  z-index: 1;
}

.mb-navbar__back-icon {
  font-size: 36rpx;
  font-weight: 600;
  line-height: 1;
}

.mb-navbar__title {
  position: absolute;
  left: 96rpx;
  right: 96rpx;
  text-align: center;
  font-size: 32rpx;
  font-weight: 600;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.mb-navbar__right {
  margin-left: auto;
  display: flex;
  align-items: center;
  z-index: 1;
}

.mb-navbar__spacer {
  flex-shrink: 0;
}
</style>
