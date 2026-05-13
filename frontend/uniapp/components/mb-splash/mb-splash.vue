<template>
  <view v-if="visible" class="mb-splash" @tap="skip">
    <image class="mb-splash__img" :src="launchImage" mode="aspectFill" />
  </view>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useAppStore } from '@/store/app'

// 仅在 App 本次冷启动后展示一次：切回首页 tab 不再弹
let shownThisRun = false

const STORAGE_KEY = 'mb_splash_enabled'
const AUTO_CLOSE_MS = 2500
const CONFIG_WAIT_MS = 2000

const appStore = useAppStore()
const visible = ref(false)
const launchImage = computed(() => appStore.siteConfig?.client_launch_image || '')

let autoCloseTimer = null
let configWaitTimer = null
let stopWatch = null

function isEnabled() {
  // 未设置时默认开启
  return uni.getStorageSync(STORAGE_KEY) !== false
}

function clearConfigWait() {
  if (configWaitTimer) {
    clearTimeout(configWaitTimer)
    configWaitTimer = null
  }
  if (stopWatch) {
    stopWatch()
    stopWatch = null
  }
}

function show() {
  clearConfigWait()
  visible.value = true
  autoCloseTimer = setTimeout(skip, AUTO_CLOSE_MS)
}

function skip() {
  if (autoCloseTimer) {
    clearTimeout(autoCloseTimer)
    autoCloseTimer = null
  }
  clearConfigWait()
  visible.value = false
}

onMounted(() => {
  if (shownThisRun || !isEnabled()) return
  shownThisRun = true

  if (launchImage.value) {
    show()
    return
  }

  // 配置可能还在异步拉取中，短暂等待；超时仍无图则放弃
  if (appStore.siteConfig === null) {
    stopWatch = watch(launchImage, (url) => {
      if (url) show()
    })
    configWaitTimer = setTimeout(clearConfigWait, CONFIG_WAIT_MS)
  }
})

onBeforeUnmount(() => {
  if (autoCloseTimer) clearTimeout(autoCloseTimer)
  clearConfigWait()
})
</script>

<style scoped>
.mb-splash {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 99999;
  background: #ffffff;
  padding-bottom: env(safe-area-inset-bottom);
}

.mb-splash__img {
  width: 100%;
  height: 100%;
}
</style>
