<template>
  <view class="page">
    <mb-navbar title="设置" />

    <!-- User card -->
    <view v-if="isLoggedIn" class="user-card" @tap="goEditProfile">
      <image
        v-if="avatar"
        class="user-card__avatar"
        :src="avatar"
        mode="aspectFill"
      />
      <view v-else class="user-card__avatar user-card__avatar--placeholder">
        <view class="avatar-icon">
          <view class="avatar-icon__head" />
          <view class="avatar-icon__body" />
        </view>
      </view>
      <view class="user-card__info">
        <text class="user-card__name">{{ nickname || '用户' }}</text>
        <text class="user-card__uid">UID: {{ uid }}</text>
      </view>
      <view class="arrow-icon" />
    </view>

    <!-- Divider -->
    <view class="divider" />

    <!-- Cell list -->
    <view class="cell-group">
      <view class="cell" @tap="goChangePassword">
        <text class="cell__label">账户与安全</text>
        <view class="cell__right">
          <view class="arrow-icon" />
        </view>
      </view>

      <view class="cell" @tap="toggleTheme">
        <text class="cell__label">外观设置</text>
        <view class="cell__right">
          <text class="cell__value">{{ themeLabel }}</text>
          <view class="arrow-icon" />
        </view>
      </view>

      <view class="cell" @tap="clearCache">
        <text class="cell__label">清除缓存</text>
        <view class="cell__right">
          <text class="cell__value">{{ cacheSize }}</text>
          <view class="arrow-icon" />
        </view>
      </view>

      <view v-if="splashRemoteEnabled" class="cell">
        <text class="cell__label">启动页</text>
        <view class="cell__right">
          <switch
            :checked="splashEnabled"
            color="#0d50d5"
            style="transform: scale(0.82)"
            @change="onSplashChange"
          />
        </view>
      </view>

      <view class="cell" @tap="goAbout">
        <text class="cell__label">关于我们</text>
        <view class="cell__right">
          <view class="arrow-icon" />
        </view>
      </view>

      <view class="cell cell--last">
        <text class="cell__label">版本更新</text>
        <view class="cell__right">
          <text class="cell__value">v0.2.1</text>
          <view class="arrow-icon" />
        </view>
      </view>
    </view>

    <!-- Logout button -->
    <view v-if="isLoggedIn" class="logout-section">
      <view
        class="logout-btn"
        :class="{ 'logout-btn--loading': logoutLoading }"
        @tap="handleLogout"
      >
        <text class="logout-btn-text">退出登录</text>
      </view>
    </view>

    <!-- Bottom safe area -->
    <view class="bottom-spacer" />
  </view>
</template>

<script setup>
import { ref, computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { useAppStore } from '@/store/app'

const userStore = useUserStore()
const appStore = useAppStore()

const splashRemoteEnabled = computed(() => {
  const v = appStore.siteConfig?.client_splash_enabled
  if (v === undefined || v === null || v === '') return true
  return Number(v) === 1 || v === true || v === '1' || v === 'true'
})

const logoutLoading = ref(false)
const cacheSize = ref('0 KB')
const themeMode = ref('system')
const splashEnabled = ref(true)

const themeOptions = ['light', 'dark', 'system']
const themeLabelMap = {
  light: '浅色',
  dark: '深色',
  system: '跟随系统',
}

const isLoggedIn = computed(() => userStore.isLoggedIn)
const nickname = computed(() => userStore.userInfo?.nickname || '')
const avatar = computed(() => userStore.userInfo?.avatar_full_url || userStore.userInfo?.avatar || '')
const uid = computed(() => userStore.userInfo?.id || '-----')
const themeLabel = computed(() => themeLabelMap[themeMode.value])

onShow(() => {
  calculateCacheSize()
  const saved = uni.getStorageSync('mb_theme_mode')
  if (saved && themeOptions.includes(saved)) {
    themeMode.value = saved
  }
  splashEnabled.value = uni.getStorageSync('mb_splash_enabled') !== false
})

function onSplashChange(e) {
  const enabled = !!e.detail.value
  splashEnabled.value = enabled
  uni.setStorageSync('mb_splash_enabled', enabled)
  uni.showToast({ title: enabled ? '已开启启动页' : '已关闭启动页', icon: 'none' })
}

function calculateCacheSize() {
  try {
    const res = uni.getStorageInfoSync()
    const kb = res.currentSize || 0
    if (kb >= 1024) {
      cacheSize.value = `${(kb / 1024).toFixed(1)} MB`
    } else {
      cacheSize.value = `${kb} KB`
    }
  } catch (_) {
    cacheSize.value = '0 KB'
  }
}

function toggleTheme() {
  const idx = themeOptions.indexOf(themeMode.value)
  const next = themeOptions[(idx + 1) % themeOptions.length]
  themeMode.value = next
  uni.setStorageSync('mb_theme_mode', next)
  uni.showToast({ title: `已切换为${themeLabelMap[next]}`, icon: 'none' })
}

function clearCache() {
  uni.showModal({
    title: '清除缓存',
    content: `确定清除本地缓存（${cacheSize.value}）吗？`,
    success(res) {
      if (res.confirm) {
        try {
          // Preserve auth tokens
          const token = uni.getStorageSync('mb_access_token')
          const refresh = uni.getStorageSync('mb_refresh_token')

          uni.clearStorageSync()

          // Restore auth tokens
          if (token) uni.setStorageSync('mb_access_token', token)
          if (refresh) uni.setStorageSync('mb_refresh_token', refresh)

          calculateCacheSize()
          uni.showToast({ title: '缓存已清除', icon: 'success' })
        } catch (_) {
          uni.showToast({ title: '清除失败', icon: 'none' })
        }
      }
    },
  })
}

async function handleLogout() {
  if (logoutLoading.value) return

  uni.showModal({
    title: '退出登录',
    content: '确定退出当前账号吗？',
    async success(res) {
      if (res.confirm) {
        logoutLoading.value = true
        try {
          await userStore.logout()
          uni.reLaunch({ url: '/pages/index/index' })
        } catch (_) {
          /* ignore */
        } finally {
          logoutLoading.value = false
        }
      }
    },
  })
}

function goEditProfile() {
  uni.navigateTo({ url: '/pages-sub/user/edit-profile' })
}

function goChangePassword() {
  uni.navigateTo({ url: '/pages-sub/user/change-password' })
}

function goAbout() {
  uni.showToast({ title: '即将开放', icon: 'none' })
}
</script>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: $mb-color-bg-secondary;
  padding: $mb-spacing-md $mb-spacing-page 0;
}

// ---- User Card ----
.user-card {
  display: flex;
  align-items: center;
  padding: $mb-spacing-lg;
  gap: 24rpx;
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  border: 1rpx solid $mb-color-divider;

  &:active {
    background: $mb-color-bg-surface;
  }
}

.user-card__avatar {
  width: 108rpx;
  height: 108rpx;
  border-radius: $mb-radius-full;
  flex-shrink: 0;
}

.user-card__avatar--placeholder {
  background: $mb-color-bg-secondary;
  display: flex;
  align-items: center;
  justify-content: center;
}

.avatar-icon {
  position: relative;
  width: 44rpx;
  height: 52rpx;
}

.avatar-icon__head {
  width: 26rpx;
  height: 26rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-border;
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
}

.avatar-icon__body {
  width: 40rpx;
  height: 22rpx;
  border-radius: 20rpx 20rpx 0 0;
  background: $mb-color-border;
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
}

.user-card__info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.user-card__name {
  font-size: $mb-font-xl;
  font-weight: 600;
  color: $mb-color-text;
  line-height: 1.3;
}

.user-card__uid {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  line-height: 1.4;
}

// ---- Divider ----
.divider {
  display: none;
}

// ---- Cell Group ----
.cell-group {
  margin-top: $mb-spacing-md;
  padding: 0 $mb-spacing-lg;
  background: $mb-color-bg;
  border-radius: $mb-radius-lg;
  border: 1rpx solid $mb-color-divider;
}

.cell {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 112rpx;
  position: relative;

  &:not(.cell--last)::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 1rpx;
    background: $mb-color-divider;
  }

  &:active {
    opacity: 0.7;
  }
}

.cell__label {
  font-size: $mb-font-md;
  color: $mb-color-text;
  font-weight: 500;
}

.cell__right {
  display: flex;
  align-items: center;
  gap: 12rpx;
}

.cell__value {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

// ---- Arrow ----
.arrow-icon {
  width: 16rpx;
  height: 16rpx;
  border-right: 3rpx solid $mb-color-text-tertiary;
  border-bottom: 3rpx solid $mb-color-text-tertiary;
  transform: rotate(-45deg);
  flex-shrink: 0;
}

// ---- Logout ----
.logout-section {
  padding: $mb-spacing-xl 0 0;
}

.logout-btn {
  height: 100rpx;
  border-radius: $mb-radius-sm;
  border: 0;
  background: rgba(186, 26, 26, 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.15s, transform 0.15s;

  &:active {
    opacity: 0.7;
    transform: scale(0.98);
  }
}

.logout-btn--loading {
  opacity: 0.5;
  pointer-events: none;
}

.logout-btn-text {
  font-size: 32rpx;
  font-weight: 600;
  color: $mb-color-error;
  letter-spacing: 0;
}

// ---- Bottom safe ----
.bottom-spacer {
  height: 120rpx;
}
</style>
