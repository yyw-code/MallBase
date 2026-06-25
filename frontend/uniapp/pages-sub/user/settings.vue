<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
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
            :color="decorateStore.themeTokens.colorPrimary || '#0d50d5'"
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
import { useDecorateStore } from '@/store/decorate'

const userStore = useUserStore()
const appStore = useAppStore()
const decorateStore = useDecorateStore()

const splashRemoteEnabled = computed(() => {
  const v = appStore.siteConfig?.client_splash_enabled
  if (v === undefined || v === null || v === '') return true
  return Number(v) === 1 || v === true || v === '1' || v === 'true'
})

const logoutLoading = ref(false)
const cacheSize = ref('0 KB')
const splashEnabled = ref(true)

const themeOptions = computed(() => decorateStore.availableThemeOptions)

const isLoggedIn = computed(() => userStore.isLoggedIn)
const nickname = computed(() => userStore.userInfo?.nickname || '')
const avatar = computed(() => userStore.userInfo?.avatar_full_url || userStore.userInfo?.avatar || '')
const uid = computed(() => userStore.userInfo?.id || '-----')
const themeLabel = computed(() => decorateStore.themeLabel)

onShow(async () => {
  await decorateStore.fetchThemes({ force: true })
  await decorateStore.fetchMyThemePreference({ force: true })
  calculateCacheSize()
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
  if (!decorateStore.allowUserThemeSelect) {
    uni.showToast({ title: '主题由管理员统一设置', icon: 'none' })
    return
  }
  uni.showActionSheet({
    itemList: themeOptions.value.map((item) => item.label),
    async success(res) {
      const selected = themeOptions.value[res.tapIndex]
      if (!selected) return
      const changed = await decorateStore.setThemeMode(selected.mode, {
        theme_id: selected.theme_id,
      })
      if (!changed) {
        return
      }
      uni.showToast({ title: `已切换为${selected.label}`, icon: 'none' })
    },
  })
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
          await decorateStore.fetchMyThemePreference({ force: true })
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
  background: var(--color-page-bg, var(--color-bg-secondary, #faf8ff));
  padding: $mb-spacing-md $mb-spacing-page 0;
}

// ---- User Card ----
.user-card {
  display: flex;
  align-items: center;
  padding: $mb-spacing-lg;
  gap: 24rpx;
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-lg;
  border: 1rpx solid var(--color-divider, #f0f2f5);

  &:active {
    background: var(--color-bg-surface, #f3f3fe);
  }
}

.user-card__avatar {
  width: 108rpx;
  height: 108rpx;
  border-radius: $mb-radius-full;
  flex-shrink: 0;
}

.user-card__avatar--placeholder {
  background: var(--color-bg-secondary, #faf8ff);
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
  background: var(--color-text-tertiary-on-page, var(--color-border, #e0e4e8));
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
}

.avatar-icon__body {
  width: 40rpx;
  height: 22rpx;
  border-radius: 20rpx 20rpx 0 0;
  background: var(--color-text-tertiary-on-page, var(--color-border, #e0e4e8));
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
  color: var(--color-text-on-bg, var(--color-text, #191b23));
  line-height: 1.3;
}

.user-card__uid {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
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
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-lg;
  border: 1rpx solid var(--color-divider, #f0f2f5);
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
    background: var(--color-divider, #f0f2f5);
  }

  &:active {
    opacity: 0.7;
  }
}

.cell__label {
  font-size: $mb-font-md;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
  font-weight: 500;
}

.cell__right {
  display: flex;
  align-items: center;
  gap: 12rpx;
}

.cell__value {
  font-size: $mb-font-sm;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
}

// ---- Arrow ----
.arrow-icon {
  width: 16rpx;
  height: 16rpx;
  border-right: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  border-bottom: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
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
  background: var(--color-error-soft, rgba(186, 26, 26, 0.08));
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
  color: var(--color-error-on-page, var(--color-error, #ba1a1a));
  letter-spacing: 0;
}

// ---- Bottom safe ----
.bottom-spacer {
  height: 120rpx;
}
</style>
