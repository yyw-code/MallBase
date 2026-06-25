<script setup>
import { computed, ref } from 'vue'
import { onPullDownRefresh, onReachBottom, onShareAppMessage, onShareTimeline } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'
import { useDecorateStore } from '@/store/decorate'
import { normalizeAssetPath } from '@/utils/decorate'

const appStore = useAppStore()
const decorateStore = useDecorateStore()

const rendererRef = ref(null)
const systemInfo = uni.getSystemInfoSync()
const statusBarHeight = systemInfo.statusBarHeight || 0

const homeModules = computed(() => decorateStore.homeModules)

function pageBackgroundStyle(pageStyle) {
  const mode = pageStyle.backgroundMode || pageStyle.background_mode || 'color'
  const image = normalizeAssetPath(
    pageStyle.background_image || pageStyle.backgroundImage || '',
  )

  if (mode === 'image' && image) {
    return {
      backgroundImage: `url("${image}")`,
      backgroundPosition: 'center',
      backgroundSize: 'cover',
    }
  }

  const start =
    pageStyle.backgroundColorStart || pageStyle.background_color_start
  const end = pageStyle.backgroundColorEnd || pageStyle.background_color_end
  if (!start && !end) return {}
  if (!end || String(start).toLowerCase() === String(end).toLowerCase()) {
    return { background: start || end }
  }
  const directions = {
    diagonalLeft: '135deg',
    diagonalRight: '45deg',
    horizontal: '90deg',
    vertical: '180deg',
  }
  const direction =
    directions[
      pageStyle.backgroundGradientDirection ||
        pageStyle.background_gradient_direction
    ] ||
    directions.horizontal
  return { background: `linear-gradient(${direction}, ${start}, ${end})` }
}

const homeStyle = computed(() => {
  const pageStyle = decorateStore.homePageStyle || {}
  const paddingX = Number(pageStyle.paddingX ?? pageStyle.padding_x ?? 28)
  const paddingY = Number(pageStyle.paddingY ?? pageStyle.padding_y ?? 0)
  const paddingTop = Number(
    pageStyle.paddingTop ?? pageStyle.padding_top ?? paddingY,
  )
  const paddingRight = Number(
    pageStyle.paddingRight ?? pageStyle.padding_right ?? paddingX,
  )
  const paddingBottom = Number(
    pageStyle.paddingBottom ?? pageStyle.padding_bottom ?? paddingY,
  )
  const paddingLeft = Number(
    pageStyle.paddingLeft ?? pageStyle.padding_left ?? paddingX,
  )
  return {
    ...pageBackgroundStyle(pageStyle),
    paddingTop: `calc(${statusBarHeight}px + ${paddingTop}rpx)`,
    paddingRight: `${paddingRight}rpx`,
    paddingBottom: `${paddingBottom}rpx`,
    paddingLeft: `${paddingLeft}rpx`,
  }
})

onPullDownRefresh(async () => {
  await decorateStore.fetchConfig()
  rendererRef.value?.refresh?.()
  uni.stopPullDownRefresh()
})

onReachBottom(() => {
  rendererRef.value?.loadMore?.()
})

function shareConfig() {
  const config = appStore.siteConfig || {}
  const title = config.client_share_title || config.site_name || 'MallBase'
  const imageUrl = config.client_share_cover || ''
  return { title, imageUrl }
}

onShareAppMessage(() => {
  const { title, imageUrl } = shareConfig()
  return { title, path: '/pages/index/index', imageUrl }
})

onShareTimeline(() => {
  const { title, imageUrl } = shareConfig()
  return { title, imageUrl }
})
</script>

<template>
  <view
    class="page"
    :class="[
      `theme-${decorateStore.resolvedThemeMode}`,
      { 'page--custom-tabbar': decorateStore.tabbarMode === 'custom' },
    ]"
    :style="decorateStore.themeStyle"
  >
    <mb-splash />
    <view class="home" :style="homeStyle">
      <view class="header">
        <view class="brand">
          <view class="brand__icon">
            <view class="brand__icon-line" />
          </view>
          <text class="brand__text">MallBase</text>
        </view>
      </view>

      <mb-decorate-renderer ref="rendererRef" :modules="homeModules" />

      <view v-if="decorateStore.tabbarMode === 'custom'" class="bottom-spacer" />
    </view>
    <mb-custom-tabbar current="/pages/index/index" />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.home {
  min-height: 100vh;
}

.header {
  height: 72rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10rpx;
}

.brand__icon {
  width: 28rpx;
  height: 26rpx;
  border: 4rpx solid var(--color-primary, #0d50d5);
  border-radius: 6rpx;
  position: relative;

  &::before {
    content: '';
    position: absolute;
    left: 3rpx;
    right: 3rpx;
    top: 5rpx;
    height: 3rpx;
    background: var(--color-primary, #0d50d5);
    border-radius: 3rpx;
  }
}

.brand__icon-line {
  position: absolute;
  left: 5rpx;
  top: -8rpx;
  width: 4rpx;
  height: 8rpx;
  background: var(--color-primary, #0d50d5);
  border-radius: 4rpx;

  &::after {
    content: '';
    position: absolute;
    left: 10rpx;
    top: 0;
    width: 4rpx;
    height: 8rpx;
    background: var(--color-primary, #0d50d5);
    border-radius: 4rpx;
  }
}

.brand__text {
  font-size: 32rpx;
  line-height: 1;
  font-weight: 800;
  color: var(--color-primary, #0d50d5);
}

.bottom-spacer {
  height: 144rpx;
}
</style>
