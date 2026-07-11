<template>
  <view
    v-if="isCustom"
    class="mb-custom-tabbar"
    :style="decorateStore.themeStyle"
  >
    <view
      v-for="item in items"
      :key="item.key"
      class="mb-custom-tabbar__item"
      :class="{ 'mb-custom-tabbar__item--active': isActive(item) }"
      @tap="goTab(item)"
    >
      <image
        v-if="getIcon(item)"
        class="mb-custom-tabbar__icon"
        :src="getIcon(item)"
        mode="aspectFit"
      />
      <view v-else class="mb-custom-tabbar__fallback">
        <text class="mb-custom-tabbar__fallback-text">{{ item.text.slice(0, 1) }}</text>
      </view>
      <text class="mb-custom-tabbar__text">{{ item.text }}</text>
    </view>
  </view>
</template>

<script setup>
import { computed, onMounted, watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useDecorateStore } from '@/store/decorate'
import { isTabbarPage, normalizePath, openDecorateLink } from '@/utils/decorate'

const props = defineProps({
  current: {
    type: String,
    default: '',
  },
})

const decorateStore = useDecorateStore()

const isCustom = computed(() => decorateStore.tabbarMode === 'custom')
const items = computed(() => decorateStore.tabbarItems.slice(0, 5))

watch(
  () => [
    isCustom.value,
    decorateStore.themeStyle,
    decorateStore.resolvedThemeMode,
  ],
  syncNativeTabbar,
  { immediate: true },
)
onMounted(syncNativeTabbar)
onShow(syncNativeTabbar)

function syncNativeTabbar() {
  if (isCustom.value) {
    uni.hideTabBar({ animation: false, fail: () => {} })
    return
  }

  uni.showTabBar({ animation: false, fail: () => {} })
  if (typeof uni.setTabBarStyle === 'function') {
    const tokens = decorateStore.themeTokens || {}
    uni.setTabBarStyle({
      color: tokens.colorTextTertiaryOnBg || tokens.colorTextTertiary || '#737686',
      selectedColor: tokens.colorPrimaryOnBg || tokens.colorPrimary || '#0d50d5',
      backgroundColor: tokens.colorBg || '#ffffff',
      borderStyle:
        decorateStore.resolvedThemeMode === 'dark' ? 'black' : 'white',
      fail: () => {},
    })
  }
  if (typeof uni.setTabBarItem === 'function') {
    syncNativeTabbarItems()
  }
}

function normalizeNativeTabbarPath(path) {
  if (!path) return ''
  return String(path).replace(/^\/+/, '')
}

function normalizeNativeTabbarAsset(path) {
  if (!path) return ''
  const value = String(path)
  return /^https?:\/\//.test(value) ? value : value.replace(/^\/+/, '')
}

function syncNativeTabbarItems() {
  items.value.forEach((item, index) => {
    const options = {
      index,
      text: item.text,
      pagePath: normalizeNativeTabbarPath(item.pagePath),
      visible: true,
      fail: () => {},
    }
    const iconPath = normalizeNativeTabbarAsset(item.iconPath)
    const selectedIconPath = normalizeNativeTabbarAsset(item.selectedIconPath)
    if (iconPath) options.iconPath = iconPath
    if (selectedIconPath) options.selectedIconPath = selectedIconPath
    uni.setTabBarItem(options)
  })

  for (let index = items.value.length; index < 5; index += 1) {
    uni.setTabBarItem({ index, visible: false, fail: () => {} })
  }
}

function getCurrentPath() {
  if (props.current) return normalizePath(props.current)
  const pages = getCurrentPages()
  const current = pages[pages.length - 1]
  return current?.route ? normalizePath(current.route) : ''
}

function isActive(item) {
  return normalizePath(item.pagePath) === getCurrentPath()
}

function getIcon(item) {
  return isActive(item)
    ? (item.selectedIconPath || item.iconPath)
    : item.iconPath
}

function goTab(item) {
  const path = normalizePath(item.pagePath)
  if (!path || path === getCurrentPath()) return
  if (isTabbarPage(path)) {
    uni.switchTab({ url: path })
    return
  }
  openDecorateLink(path)
}
</script>

<style lang="scss" scoped>
.mb-custom-tabbar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 99;
  min-height: 104rpx;
  padding: 10rpx 12rpx calc(10rpx + env(safe-area-inset-bottom));
  background: var(--color-bg, #ffffff);
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  box-shadow: var(--shadow-bar, 0 -1rpx 0 rgba(224, 228, 232, 0.9));
}

.mb-custom-tabbar__item {
  flex: 1;
  min-width: 0;
  height: 84rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6rpx;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
}

.mb-custom-tabbar__item--active {
  color: var(--color-primary-on-bg, var(--color-primary, #0d50d5));
}

.mb-custom-tabbar__icon {
  width: 44rpx;
  height: 44rpx;
}

.mb-custom-tabbar__fallback {
  width: 44rpx;
  height: 44rpx;
  border-radius: 16rpx;
  background: var(--color-bg-surface, #f3f3fe);
  display: flex;
  align-items: center;
  justify-content: center;
}

.mb-custom-tabbar__fallback-text {
  font-size: 22rpx;
  font-weight: 700;
  color: var(--color-text-on-surface, currentColor);
}

.mb-custom-tabbar__text {
  max-width: 100%;
  font-size: 20rpx;
  line-height: 1.2;
  color: currentColor;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
