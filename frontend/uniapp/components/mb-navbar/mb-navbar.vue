<template>
  <view>
    <view
      class="mb-navbar"
      :class="{ 'mb-navbar--immersive': immersive }"
      :style="{ backgroundColor: bgColor }"
    >
      <view v-if="accentLine" class="mb-navbar__accent" />
      <view class="mb-navbar__status" :style="{ height: statusBarHeight + 'px' }" />
      <view class="mb-navbar__content" :style="contentStyle">
        <view
          v-if="back"
          class="mb-navbar__back"
          hover-class="mb-navbar__back--hover"
          hover-stay-time="80"
          @tap="onBack"
        >
          <view class="mb-navbar__back-icon" :style="{ borderColor: textColor }" />
        </view>
        <text class="mb-navbar__title" :style="titleStyle">{{ title }}</text>
        <view class="mb-navbar__right" :style="rightStyle">
          <slot name="right" />
        </view>
      </view>
    </view>
    <view class="mb-navbar__spacer" :style="{ height: totalHeight + 'px' }" />
  </view>
</template>

<script setup>
import { computed } from 'vue'
import { getUniWindowInfo } from '@/utils/system-info'

const props = defineProps({
  title: { type: String, default: '' },
  back: { type: Boolean, default: true },
  bgColor: { type: String, default: 'var(--color-bg, #ffffff)' },
  textColor: { type: String, default: 'var(--color-text, #191b23)' },
  accentLine: { type: Boolean, default: false },
  immersive: { type: Boolean, default: false },
})

const emit = defineEmits(['back'])

const systemInfo = getUniWindowInfo()
const statusBarHeight = systemInfo.statusBarHeight || 0
const windowWidth = systemInfo.windowWidth || 375
const sidePaddingPx = uni.upx2px(24)
const defaultTitleInsetPx = uni.upx2px(112)
const defaultNavContentPx = uni.upx2px(88)
const accentPx = uni.upx2px(4)
const menuButtonRect = getMenuButtonRect()

const navContentPx = menuButtonRect
  ? Math.max(defaultNavContentPx, (menuButtonRect.top - statusBarHeight) * 2 + menuButtonRect.height)
  : defaultNavContentPx

const menuAvoidPx = menuButtonRect
  ? Math.max(defaultTitleInsetPx, windowWidth - menuButtonRect.left + sidePaddingPx)
  : defaultTitleInsetPx

const rightSlotRightPx = menuButtonRect
  ? Math.max(sidePaddingPx, windowWidth - menuButtonRect.left + sidePaddingPx)
  : sidePaddingPx

const totalHeight = computed(() => statusBarHeight + navContentPx + (props.accentLine ? accentPx : 0))

const contentStyle = computed(() => ({
  height: `${navContentPx}px`,
  paddingLeft: `${sidePaddingPx}px`,
  paddingRight: `${menuAvoidPx}px`,
}))

const titleStyle = computed(() => ({
  color: props.textColor,
  left: `${menuAvoidPx}px`,
  right: `${menuAvoidPx}px`,
  height: `${navContentPx}px`,
  lineHeight: `${navContentPx}px`,
}))

const rightStyle = computed(() => ({
  right: `${rightSlotRightPx}px`,
  height: `${navContentPx}px`,
}))

function getMenuButtonRect() {
  try {
    if (typeof uni.getMenuButtonBoundingClientRect !== 'function') {
      return null
    }

    const rect = uni.getMenuButtonBoundingClientRect()
    if (!rect || !rect.width || !rect.height || !rect.left || !rect.top) {
      return null
    }

    return rect
  } catch {
    return null
  }
}

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
  border-bottom: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.18));
  box-shadow: 0 8rpx 28rpx rgba(15, 23, 42, 0.06);
  -webkit-backdrop-filter: blur(24rpx);
  backdrop-filter: blur(24rpx);
}

.mb-navbar--immersive {
  border-bottom: none;
  box-shadow: none;
  -webkit-backdrop-filter: none;
  backdrop-filter: none;
}

.mb-navbar__accent {
  height: 4rpx;
  background-color: var(--color-primary, #0d50d5);
}

.mb-navbar__content {
  position: relative;
  display: flex;
  align-items: center;
  box-sizing: border-box;
}

.mb-navbar__back {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 64rpx;
  height: 64rpx;
  border-radius: 50%;
  background: var(--color-bg-surface, rgba(148, 163, 184, 0.12));
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.18));
  z-index: 1;
  transition:
    background-color 0.15s ease,
    transform 0.15s ease;
}

.mb-navbar__back--hover {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.12));
  transform: scale(0.96);
}

.mb-navbar__back-icon {
  width: 18rpx;
  height: 18rpx;
  border-left: 4rpx solid currentColor;
  border-bottom: 4rpx solid currentColor;
  border-radius: 2rpx;
  transform: translateX(4rpx) rotate(45deg);
}

.mb-navbar__title {
  position: absolute;
  top: 0;
  text-align: center;
  font-size: 32rpx;
  font-weight: 700;
  letter-spacing: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.mb-navbar__right {
  position: absolute;
  top: 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  z-index: 1;
}

.mb-navbar__spacer {
  flex-shrink: 0;
}
</style>
