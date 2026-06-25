<template>
  <view v-if="visible" class="mb-splash">
    <!-- 优先显示后台配置的启动屏图；未配置则显示品牌默认页 -->
    <image
      v-if="launchImage"
      class="mb-splash__img"
      :src="launchImage"
      mode="aspectFill"
    />
    <view v-else class="mb-splash__brand">
      <view class="mb-splash__bg" />
      <image class="mb-splash__logo" src="/static/logo-light.png" mode="aspectFit" />
      <text class="mb-splash__title">{{ brandName }}</text>
      <text class="mb-splash__slogan">{{ brandSlogan }}</text>
      <text class="mb-splash__copyright">© {{ brandName }} · Enjoy the trend</text>
    </view>

    <view
      class="mb-splash__skip"
      :style="skipStyle"
      hover-class="mb-splash__skip--active"
      hover-stay-time="60"
      @tap="skip"
    >
      <text class="mb-splash__skip-text">跳过 {{ remainingSec }}s</text>
    </view>
  </view>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useAppStore } from '@/store/app'

let shownThisRun = false

const DEFAULT_DURATION_MS = 3000
// 启动页展示前等待 siteConfig 的最长时间；超时则进入 brand 兜底视图
// 避免接口慢/失败时启动页永远不出现
const CONFIG_WAIT_MS = 600

const appStore = useAppStore()
const visible = ref(false)
const remainingSec = ref(0)
const skipStyle = ref('')

const launchImage = computed(() => appStore.siteConfig?.client_launch_image || '')

const brandName = computed(() => (
  appStore.siteConfig?.client_site_name || 'MallBase'
))

const brandSlogan = computed(() => (
  appStore.siteConfig?.client_share_desc || '潮流好物 一键到家'
))

const durationMs = computed(() => {
  const raw = Number(appStore.siteConfig?.client_splash_duration)
  return Number.isFinite(raw) && raw >= 1000 ? raw : DEFAULT_DURATION_MS
})

const remoteEnabled = computed(() => {
  const v = appStore.siteConfig?.client_splash_enabled
  if (v === undefined || v === null || v === '') return true
  return Number(v) === 1 || v === true || v === '1' || v === 'true'
})

let autoCloseTimer = null
let tickTimer = null
let configWaitTimer = null
let stopConfigWatch = null

function clearAllTimers() {
  if (autoCloseTimer) {
    clearTimeout(autoCloseTimer)
    autoCloseTimer = null
  }
  if (tickTimer) {
    clearInterval(tickTimer)
    tickTimer = null
  }
}

function clearConfigWait() {
  if (configWaitTimer) {
    clearTimeout(configWaitTimer)
    configWaitTimer = null
  }
  if (stopConfigWatch) {
    stopConfigWatch()
    stopConfigWatch = null
  }
}

function computeSkipPosition() {
  // #ifdef MP-WEIXIN
  try {
    const rect = uni.getMenuButtonBoundingClientRect && uni.getMenuButtonBoundingClientRect()
    if (rect && rect.bottom) {
      const top = rect.bottom + 8
      const right = uni.getSystemInfoSync().windowWidth - rect.right
      skipStyle.value = `top: ${top}px; right: ${right}px;`
      return
    }
  } catch (e) {
    // fall through to default
  }
  // #endif

  skipStyle.value = ''
}

// 小程序原生 tabBar 在 webview 之外，CSS 无法遮盖
// 启动页显示期间需要主动隐藏，结束后恢复
function setNativeTabBar(shown) {
  // #ifdef MP
  const fn = shown ? uni.showTabBar : uni.hideTabBar
  if (typeof fn !== 'function') return
  try {
    fn({ animation: false, fail: () => {} })
  } catch (e) {
    // 非 tabBar 页调用会抛错，忽略
  }
  // #endif
}

function show() {
  visible.value = true
  setNativeTabBar(false)
  computeSkipPosition()

  const total = durationMs.value
  remainingSec.value = Math.ceil(total / 1000)

  tickTimer = setInterval(() => {
    remainingSec.value = Math.max(0, remainingSec.value - 1)
  }, 1000)

  autoCloseTimer = setTimeout(skip, total)
}

function skip() {
  clearAllTimers()
  visible.value = false
  setNativeTabBar(true)
}

onMounted(() => {
  if (shownThisRun) return

  // 等 siteConfig 到位后再展示，避免「品牌兜底 → 后台启动图」二次切换的闪烁
  function start() {
    clearConfigWait()
    if (shownThisRun) return
    if (!remoteEnabled.value) return
    shownThisRun = true
    show()
  }

  if (appStore.siteConfig) {
    start()
    return
  }

  stopConfigWatch = watch(() => appStore.siteConfig, (cfg) => {
    if (cfg) start()
  })
  configWaitTimer = setTimeout(start, CONFIG_WAIT_MS)
})

onBeforeUnmount(() => {
  clearConfigWait()
  clearAllTimers()
  setNativeTabBar(true)
})
</script>

<style lang="scss" scoped>
@import '@/uni.scss';

.mb-splash {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 99999;
  background: #0b1a4d;
  padding-bottom: env(safe-area-inset-bottom);
  overflow: hidden;
}

.mb-splash__img {
  width: 100%;
  height: 100%;
}

/* ---- Brand fallback view ---- */
.mb-splash__brand {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 80rpx 48rpx calc(56rpx + env(safe-area-inset-bottom));
}

.mb-splash__bg {
  position: absolute;
  inset: 0;
  background: linear-gradient(135deg, #0d50d5 0%, #386bef 50%, #1e2a6b 100%);
  z-index: 0;
}

.mb-splash__logo {
  position: relative;
  z-index: 1;
  width: 260rpx;
  height: 260rpx;
  margin-bottom: 56rpx;
}

.mb-splash__title {
  position: relative;
  z-index: 1;
  font-size: 64rpx;
  font-weight: 800;
  letter-spacing: -1rpx;
  color: #ffffff;
  line-height: 1.1;
  text-shadow: 0 4rpx 24rpx rgba(0, 0, 0, 0.25);
}

.mb-splash__slogan {
  position: relative;
  z-index: 1;
  margin-top: 24rpx;
  font-size: 28rpx;
  color: rgba(255, 255, 255, 0.78);
  letter-spacing: 2rpx;
}

.mb-splash__copyright {
  position: absolute;
  z-index: 1;
  bottom: calc(48rpx + env(safe-area-inset-bottom));
  left: 0;
  right: 0;
  text-align: center;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.55);
}

/* ---- Skip button ---- */
.mb-splash__skip {
  position: fixed;
  /* #ifndef MP-WEIXIN */
  top: calc(env(safe-area-inset-top) + 24rpx);
  right: 32rpx;
  /* #endif */
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 132rpx;
  height: 56rpx;
  padding: 0 24rpx;
  background: rgba(0, 0, 0, 0.42);
  color: #ffffff;
  border-radius: $mb-radius-full;
  font-size: $mb-font-sm;
  z-index: 100000;
}

.mb-splash__skip--active {
  opacity: 0.78;
}

.mb-splash__skip-text {
  color: #ffffff;
  line-height: 1;
}
</style>
