<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="设置" />

    <scroll-view class="content" scroll-y>
      <view v-if="isLoggedIn" class="profile-card" @tap="goEditProfile">
        <image
          v-if="avatar"
          class="profile-card__avatar"
          :src="avatar"
          mode="aspectFill"
        />
        <view v-else class="profile-card__avatar profile-card__avatar--empty">
          <view class="avatar-icon">
            <view class="avatar-icon__head" />
            <view class="avatar-icon__body" />
          </view>
        </view>
        <view class="profile-card__body">
          <text class="profile-card__name">{{ nickname || '用户' }}</text>
          <text class="profile-card__meta">UID: {{ uid }}</text>
        </view>
        <view class="arrow-icon" />
      </view>

      <view v-else class="profile-card profile-card--guest" @tap="goLogin">
        <view class="profile-card__avatar profile-card__avatar--empty">
          <view class="avatar-icon">
            <view class="avatar-icon__head" />
            <view class="avatar-icon__body" />
          </view>
        </view>
        <view class="profile-card__body">
          <text class="profile-card__name">未登录</text>
          <text class="profile-card__meta">登录后管理账号资料</text>
        </view>
        <view class="arrow-icon" />
      </view>

      <view class="section">
        <text class="section__title">账号</text>
        <view class="cell-group">
          <view class="cell" @tap="goChangePassword">
            <view class="cell__main">
              <view class="cell__icon cell__icon--security">
                <view class="lock-icon" />
              </view>
              <text class="cell__label">账户与安全</text>
            </view>
            <view class="cell__right">
              <text class="cell__value">修改密码</text>
              <view class="arrow-icon" />
            </view>
          </view>
        </view>
      </view>

      <view class="section">
        <text class="section__title">偏好</text>
        <view class="cell-group">
          <view v-if="decorateStore.allowUserThemeSelect" class="cell" @tap="goTheme">
            <view class="cell__main">
              <view class="cell__icon cell__icon--theme">
                <view class="theme-icon" />
              </view>
              <text class="cell__label">主题设置</text>
            </view>
            <view class="cell__right">
              <text class="cell__value">{{ themeLabel }}</text>
              <view class="arrow-icon" />
            </view>
          </view>

          <view class="cell" @tap="clearCache">
            <view class="cell__main">
              <view class="cell__icon cell__icon--cache">
                <view class="cache-icon" />
              </view>
              <text class="cell__label">清除缓存</text>
            </view>
            <view class="cell__right">
              <text class="cell__value">{{ cacheSize }}</text>
              <view class="arrow-icon" />
            </view>
          </view>
        </view>
      </view>

      <view class="section">
        <text class="section__title">信息</text>
        <view class="cell-group">
          <view class="cell" @tap="goAbout">
            <view class="cell__main">
              <view class="cell__icon cell__icon--about">
                <text class="about-icon">i</text>
              </view>
              <text class="cell__label">关于我们</text>
            </view>
            <view class="cell__right">
              <view class="arrow-icon" />
            </view>
          </view>

          <view class="cell cell--static">
            <view class="cell__main">
              <view class="cell__icon cell__icon--version">
                <view class="version-icon" />
              </view>
              <text class="cell__label">当前版本</text>
            </view>
            <view class="cell__right">
              <text class="cell__value">v{{ appVersion }}</text>
            </view>
          </view>
        </view>
      </view>

      <view v-if="isLoggedIn" class="logout-section">
        <view
          class="logout-btn"
          :class="{ 'logout-btn--loading': logoutLoading }"
          @tap="handleLogout"
        >
          <view class="logout-btn__icon">
            <view class="logout-icon" />
          </view>
          <text class="logout-btn-text">{{ logoutLoading ? '退出中...' : '退出登录' }}</text>
        </view>
      </view>

      <view class="bottom-spacer" />
    </scroll-view>
      <mb-floating-action />
</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import config from '@/config'
import { useDecorateStore } from '@/store/decorate'
import { useUserStore } from '@/store/user'

const userStore = useUserStore()
const decorateStore = useDecorateStore()

const logoutLoading = ref(false)
const cacheSize = ref('0 KB')

const isLoggedIn = computed(() => userStore.isLoggedIn)
const nickname = computed(() => userStore.userInfo?.nickname || '')
const avatar = computed(() => userStore.userInfo?.avatar_full_url || userStore.userInfo?.avatar || '')
const uid = computed(() => userStore.userInfo?.id || '-----')
const themeLabel = computed(() => decorateStore.themeLabel)
const appVersion = computed(() => config.version || '1.0.0')

onShow(async () => {
  await decorateStore.fetchThemes({ force: true })
  await decorateStore.fetchMyThemePreference({ force: true })
  calculateCacheSize()
})

function calculateCacheSize() {
  try {
    const res = uni.getStorageInfoSync()
    const kb = res.currentSize || 0
    cacheSize.value = kb >= 1024 ? `${(kb / 1024).toFixed(1)} MB` : `${kb} KB`
  } catch (_) {
    cacheSize.value = '0 KB'
  }
}

function clearCache() {
  uni.showModal({
    title: '清除缓存',
    content: `确定清除本地缓存（${cacheSize.value}）吗？`,
    success(res) {
      if (!res.confirm) return
      try {
        const token = uni.getStorageSync('mb_access_token')
        const refresh = uni.getStorageSync('mb_refresh_token')

        uni.clearStorageSync()

        if (token) uni.setStorageSync('mb_access_token', token)
        if (refresh) uni.setStorageSync('mb_refresh_token', refresh)

        calculateCacheSize()
        uni.showToast({ title: '缓存已清除', icon: 'success' })
      } catch (_) {
        uni.showToast({ title: '清除失败', icon: 'none' })
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
      if (!res.confirm) return
      logoutLoading.value = true
      try {
        await userStore.logout()
        await decorateStore.fetchMyThemePreference({ force: true })
        uni.reLaunch({ url: '/pages/index/index' })
      } catch (_) {
        /* request.js handles errors */
      } finally {
        logoutLoading.value = false
      }
    },
  })
}

function goEditProfile() {
  uni.navigateTo({ url: '/pages-sub/user/edit-profile' })
}

function goLogin() {
  uni.navigateTo({ url: '/pages-sub/user/login' })
}

function goChangePassword() {
  uni.navigateTo({ url: '/pages-sub/user/change-password' })
}

async function goTheme() {
  await decorateStore.openThemeSelector()
}

function goAbout() {
  uni.navigateTo({ url: '/pages-sub/user/about' })
}
</script>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.content {
  box-sizing: border-box;
  height: calc(100vh - 96rpx);
  padding: $mb-spacing-md $mb-spacing-page 0;
}

.profile-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;

  &:active {
    background: var(--color-bg-surface, #f3f3fe);
  }
}

.profile-card--guest {
  border-style: dashed;
}

.profile-card__avatar {
  width: 104rpx;
  height: 104rpx;
  border-radius: $mb-radius-full;
  flex-shrink: 0;
}

.profile-card__avatar--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
}

.profile-card__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.profile-card__name {
  font-size: $mb-font-xl;
  font-weight: 600;
  line-height: 1.3;
  color: var(--color-text-title-on-bg, var(--color-text-title, #191b23));
}

.profile-card__meta {
  font-size: $mb-font-sm;
  line-height: 1.4;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
}

.section {
  margin-top: $mb-spacing-lg;
}

.section__title {
  display: block;
  margin: 0 8rpx 14rpx;
  font-size: $mb-font-sm;
  font-weight: 600;
  line-height: 1.4;
  color: var(--color-text-secondary-on-page, var(--color-text-secondary, #434654));
}

.cell-group {
  overflow: hidden;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.cell {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 112rpx;
  gap: 20rpx;
  padding: 0 28rpx;

  &:not(:last-child)::after {
    content: '';
    position: absolute;
    right: 28rpx;
    bottom: 0;
    left: 100rpx;
    height: 1rpx;
    background: var(--color-divider, #f0f2f5);
  }

  &:not(.cell--static):active {
    background: var(--color-bg-surface, #f3f3fe);
  }
}

.cell__main,
.cell__right {
  display: flex;
  align-items: center;
}

.cell__main {
  min-width: 0;
  gap: 20rpx;
}

.cell__right {
  flex-shrink: 0;
  gap: 12rpx;
}

.cell__label {
  font-size: $mb-font-md;
  font-weight: 500;
  line-height: 1.4;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
}

.cell__value {
  max-width: 220rpx;
  overflow: hidden;
  font-size: $mb-font-sm;
  line-height: 1.4;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cell__icon {
  width: 52rpx;
  height: 52rpx;
  border-radius: $mb-radius-md;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.cell__icon--security {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
}

.cell__icon--theme {
  background: var(--color-warning-soft, rgba(240, 173, 78, 0.12));
}

.cell__icon--cache {
  background: var(--color-success-soft, rgba(52, 199, 89, 0.1));
}

.cell__icon--about {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
}

.cell__icon--version {
  background: var(--color-bg-secondary, #faf8ff);
}

.arrow-icon {
  width: 14rpx;
  height: 14rpx;
  border-right: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  border-bottom: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  transform: rotate(-45deg);
  flex-shrink: 0;
}

.avatar-icon {
  position: relative;
  width: 44rpx;
  height: 52rpx;
}

.avatar-icon__head {
  position: absolute;
  top: 0;
  left: 50%;
  width: 26rpx;
  height: 26rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary-on-page, var(--color-primary, #0d50d5));
  transform: translateX(-50%);
}

.avatar-icon__body {
  position: absolute;
  bottom: 0;
  left: 50%;
  width: 40rpx;
  height: 22rpx;
  border-radius: 20rpx 20rpx 0 0;
  background: var(--color-primary-on-page, var(--color-primary, #0d50d5));
  transform: translateX(-50%);
}

.lock-icon {
  position: relative;
  width: 28rpx;
  height: 22rpx;
  border: 3rpx solid var(--color-primary-on-page, var(--color-primary, #0d50d5));
  border-radius: 6rpx;

  &::before {
    content: '';
    position: absolute;
    top: -18rpx;
    left: 5rpx;
    width: 14rpx;
    height: 18rpx;
    border: 3rpx solid var(--color-primary-on-page, var(--color-primary, #0d50d5));
    border-bottom: 0;
    border-radius: 14rpx 14rpx 0 0;
  }
}

.theme-icon {
  width: 28rpx;
  height: 28rpx;
  border-radius: $mb-radius-full;
  background: linear-gradient(135deg, #0d50d5 0%, #34c759 52%, #ff5a1f 100%);
}

.cache-icon {
  width: 30rpx;
  height: 30rpx;
  border: 3rpx solid var(--color-success, #34c759);
  border-radius: $mb-radius-full;
  border-top-color: transparent;
}

.about-icon {
  font-size: 30rpx;
  font-weight: 700;
  line-height: 1;
  color: var(--color-primary-on-page, var(--color-primary, #0d50d5));
}

.version-icon {
  position: relative;
  width: 30rpx;
  height: 30rpx;
  border: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  border-radius: $mb-radius-full;

  &::after {
    content: '';
    position: absolute;
    top: 7rpx;
    left: 7rpx;
    width: 10rpx;
    height: 10rpx;
    border-right: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
    border-bottom: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  }
}

.logout-section {
  padding-top: $mb-spacing-xl;
}

.logout-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14rpx;
  height: 104rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-error-border, rgba(186, 26, 26, 0.24));
  border-radius: $mb-radius-full;
  box-shadow: 0 12rpx 32rpx rgba(15, 23, 42, 0.04);
  transition: opacity 0.15s, transform 0.15s;

  &:active {
    opacity: 0.75;
    transform: scale(0.98);
  }
}

.logout-btn--loading {
  opacity: 0.55;
  pointer-events: none;
}

.logout-btn__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42rpx;
  height: 42rpx;
  background: var(--color-error-soft, rgba(186, 26, 26, 0.1));
  border-radius: $mb-radius-full;
}

.logout-icon {
  position: relative;
  width: 24rpx;
  height: 20rpx;

  &::before {
    content: '';
    position: absolute;
    top: 8rpx;
    left: 0;
    width: 17rpx;
    height: 4rpx;
    background: var(--color-error-on-page, var(--color-error, #ba1a1a));
    border-radius: $mb-radius-full;
  }

  &::after {
    content: '';
    position: absolute;
    top: 3rpx;
    right: 0;
    width: 12rpx;
    height: 12rpx;
    border-top: 4rpx solid var(--color-error-on-page, var(--color-error, #ba1a1a));
    border-right: 4rpx solid var(--color-error-on-page, var(--color-error, #ba1a1a));
    transform: rotate(45deg);
  }
}

.logout-btn-text {
  font-size: $mb-font-lg;
  font-weight: 600;
  line-height: 1.3;
  color: var(--color-error-on-page, var(--color-error, #ba1a1a));
}

.bottom-spacer {
  height: calc(88rpx + env(safe-area-inset-bottom));
}
</style>
