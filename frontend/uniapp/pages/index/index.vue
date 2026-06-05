<script setup>
import { computed, ref } from 'vue'
import { onPullDownRefresh, onReachBottom, onShareAppMessage, onShareTimeline } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'
import { useDecorateStore } from '@/store/decorate'

const appStore = useAppStore()
const decorateStore = useDecorateStore()

const rendererRef = ref(null)
const systemInfo = uni.getSystemInfoSync()
const statusBarHeight = systemInfo.statusBarHeight || 0

function getModuleList(module) {
  const props = module?.props || {}
  const value = props.list || props.items || props.images || []
  return Array.isArray(value) ? value : []
}

const homeModules = computed(() => {
  const banners = appStore.siteConfig?.client_home_banners
  if (!Array.isArray(banners) || banners.length === 0) {
    return decorateStore.homeModules
  }
  return decorateStore.homeModules.map((module) => {
    if (module.type !== 'banner') return module
    const configuredList = getModuleList(module)
    const list = configuredList.length > 0 ? configuredList : banners
    return {
      ...module,
      props: {
        ...module.props,
        list,
      },
    }
  })
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
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-splash />
    <view class="home" :style="{ paddingTop: statusBarHeight + 'px' }">
      <view class="header">
        <view class="brand">
          <view class="brand__icon">
            <view class="brand__icon-line" />
          </view>
          <text class="brand__text">MallBase</text>
        </view>
      </view>

      <mb-decorate-renderer ref="rendererRef" :modules="homeModules" />

      <view class="bottom-spacer" />
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
  padding-left: 28rpx;
  padding-right: 28rpx;
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
