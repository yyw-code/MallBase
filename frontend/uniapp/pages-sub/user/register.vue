<template>
  <view class="page">
    <mb-navbar title="注册" />

    <view class="page-content">
      <!-- Brand header -->
      <view class="brand">
        <view class="logo-box">
          <view class="logo-bag">
            <view class="bag-body" />
            <view class="bag-handle" />
          </view>
        </view>
        <text class="brand-title">创建账号</text>
        <text class="brand-subtitle">加入 MallBase，发现更多好物</text>
      </view>

      <!-- Form -->
      <view class="form-section">
        <!-- Phone -->
        <view class="input-pill">
          <text class="area-code">+86</text>
          <view class="pill-sep" />
          <input
            v-model="phone"
            class="pill-input"
            type="number"
            maxlength="11"
            placeholder="手机号"
            placeholder-class="placeholder"
          />
        </view>

        <!-- Password -->
        <view class="input-pill">
          <input
            v-model="password"
            class="pill-input pill-input--full"
            :type="showPassword ? 'text' : 'password'"
            placeholder="设置密码"
            placeholder-class="placeholder"
          />
          <view class="eye-toggle" @tap="showPassword = !showPassword">
            <view class="eye-shape" />
            <view v-if="!showPassword" class="eye-slash" />
          </view>
        </view>

        <!-- Nickname -->
        <view class="input-pill">
          <input
            v-model="nickname"
            class="pill-input pill-input--full"
            type="text"
            maxlength="20"
            placeholder="昵称（选填）"
            placeholder-class="placeholder"
          />
        </view>

        <!-- Register button -->
        <view
          class="primary-btn"
          :class="{ 'primary-btn--loading': loading }"
          @tap="handleRegister"
        >
          <text class="primary-btn-text">{{ loading ? '注册中...' : '注 册' }}</text>
        </view>

        <!-- Go to login -->
        <view class="mode-switch" @tap="goLogin">
          <text class="link-text-plain">已有账号？</text>
          <text class="link-text">去登录</text>
        </view>
      </view>

      <!-- Agreement -->
      <view class="agreement">
        <view
          class="agree-check"
          :class="{ 'agree-check--on': agreed }"
          @tap="agreed = !agreed"
        >
          <text v-if="agreed" class="check-mark">&#10003;</text>
        </view>
        <text class="agree-text">注册即代表您已阅读并同意</text>
        <text class="agree-link" @tap="openAgreement('service')">服务协议</text>
        <text class="agree-text">和</text>
        <text class="agree-link" @tap="openAgreement('privacy')">隐私权政策</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { useUserStore } from '@/store/user'
import { register, loginByPassword } from '@/api/user/auth'

const userStore = useUserStore()

const phone = ref('')
const password = ref('')
const nickname = ref('')
const showPassword = ref(false)
const agreed = ref(false)
const loading = ref(false)

function validatePhone() {
  if (!/^1\d{10}$/.test(phone.value)) {
    uni.showToast({ title: '请输入正确的手机号', icon: 'none' })
    return false
  }
  return true
}

function validatePassword() {
  if (!password.value || password.value.length < 6) {
    uni.showToast({ title: '密码至少 6 位', icon: 'none' })
    return false
  }
  return true
}

function checkAgreement() {
  if (!agreed.value) {
    uni.showToast({ title: '请先同意服务协议与隐私政策', icon: 'none' })
    return false
  }
  return true
}

async function handleRegister() {
  if (loading.value) return
  if (!checkAgreement() || !validatePhone() || !validatePassword()) return

  loading.value = true
  try {
    await register(phone.value, password.value, nickname.value || undefined)

    const loginData = await loginByPassword(phone.value, password.value)
    userStore.setToken(loginData.access_token, loginData.refresh_token)
    userStore.fetchUserInfo()

    uni.showToast({ title: '注册成功', icon: 'success' })

    setTimeout(() => {
      const pages = getCurrentPages()
      if (pages.length > 1) {
        uni.navigateBack()
      } else {
        uni.switchTab({ url: '/pages/index/index' })
      }
    }, 800)
  } catch (_) {
    /* request.js shows toast */
  } finally {
    loading.value = false
  }
}

function goLogin() {
  uni.navigateBack({
    fail() {
      uni.redirectTo({ url: '/pages-sub/user/login' })
    },
  })
}

function openAgreement(type) {
  uni.navigateTo({
    url: `/pages-sub/user/agreement?type=${type === 'privacy' ? 'privacy' : 'service'}`,
  })
}
</script>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: $mb-color-bg-secondary;
  position: relative;

  &::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
      radial-gradient(ellipse 80% 50% at 50% 0%, rgba(13, 80, 213, 0.04) 0%, transparent 60%),
      radial-gradient(ellipse 60% 40% at 80% 100%, rgba(13, 80, 213, 0.03) 0%, transparent 50%);
    pointer-events: none;
  }
}

.page-content {
  width: 100%;
  max-width: 660rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  z-index: 1;
  padding: 0 48rpx;
}

// ---- Brand ----
.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 64rpx;
}

.logo-box {
  width: 120rpx;
  height: 120rpx;
  border-radius: 40rpx;
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(24px);
  -webkit-backdrop-filter: blur(24px);
  border: 2rpx solid rgba(255, 255, 255, 0.6);
  box-shadow: 0 8rpx 32rpx rgba(0, 0, 0, 0.06), 0 2rpx 8rpx rgba(0, 0, 0, 0.04);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 32rpx;
}

.logo-bag {
  position: relative;
  width: 48rpx;
  height: 56rpx;
}

.bag-body {
  position: absolute;
  bottom: 0;
  left: 4rpx;
  right: 4rpx;
  height: 40rpx;
  border: 3rpx solid #191c1e;
  border-radius: 6rpx 6rpx 12rpx 12rpx;
}

.bag-handle {
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 24rpx;
  height: 22rpx;
  border: 3rpx solid #191c1e;
  border-bottom: none;
  border-radius: 12rpx 12rpx 0 0;
}

.brand-title {
  font-size: 52rpx;
  font-weight: 600;
  letter-spacing: -0.02em;
  color: #000000;
  line-height: 1.2;
  margin-bottom: 12rpx;
}

.brand-subtitle {
  font-size: 26rpx;
  color: $mb-color-text-tertiary;
  opacity: 0.8;
  letter-spacing: 0.04em;
}

// ---- Form ----
.form-section {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

.input-pill {
  display: flex;
  align-items: center;
  height: 100rpx;
  border-radius: $mb-radius-full;
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(24px);
  -webkit-backdrop-filter: blur(24px);
  border: 2rpx solid rgba(255, 255, 255, 0.6);
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.04), 0 1rpx 4rpx rgba(0, 0, 0, 0.02);
  padding: 0 36rpx;
  transition: border-color 0.2s, box-shadow 0.2s;

  &:focus-within {
    border-color: rgba(13, 80, 213, 0.3);
    box-shadow: 0 4rpx 16rpx rgba(13, 80, 213, 0.08), 0 1rpx 4rpx rgba(0, 0, 0, 0.02);
  }
}

.area-code {
  font-size: 30rpx;
  font-weight: 500;
  color: #191c1e;
  flex-shrink: 0;
}

.pill-sep {
  width: 2rpx;
  height: 36rpx;
  background: rgba(198, 198, 205, 0.5);
  margin: 0 24rpx;
  flex-shrink: 0;
}

.pill-input {
  flex: 1;
  font-size: 30rpx;
  color: #191c1e;
  height: 100%;
}

.pill-input--full {
  padding-left: 8rpx;
}

.placeholder {
  color: #c6c6cd;
}

// ---- Eye toggle ----
.eye-toggle {
  flex-shrink: 0;
  width: 56rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.eye-shape {
  width: 36rpx;
  height: 24rpx;
  border: 2rpx solid $mb-color-text-tertiary;
  border-radius: 50%;
  position: relative;

  &::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 10rpx;
    height: 10rpx;
    border-radius: 50%;
    background: $mb-color-text-tertiary;
  }
}

.eye-slash {
  position: absolute;
  top: 12rpx;
  left: 50%;
  width: 2rpx;
  height: 32rpx;
  background: $mb-color-text-tertiary;
  transform: translateX(-50%) rotate(45deg);
}

// ---- Buttons ----
.primary-btn {
  height: 100rpx;
  border-radius: $mb-radius-full;
  background: #000000;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 16rpx;
  box-shadow: 0 8rpx 24rpx rgba(0, 0, 0, 0.2), 0 2rpx 8rpx rgba(0, 0, 0, 0.1);
  transition: opacity 0.15s, transform 0.15s;

  &:active {
    opacity: 0.85;
    transform: scale(0.98);
  }
}

.primary-btn--loading {
  opacity: 0.7;
  pointer-events: none;
}

.primary-btn-text {
  font-size: 32rpx;
  font-weight: 600;
  color: #ffffff;
  letter-spacing: 0.2em;
}

.mode-switch {
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 8rpx 0;
  gap: 4rpx;
}

.link-text-plain {
  font-size: 26rpx;
  color: $mb-color-text-tertiary;
}

.link-text {
  font-size: 26rpx;
  color: $mb-color-primary;
  font-weight: 500;
}

// ---- Agreement ----
.agreement {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  margin-top: 60rpx;
  padding: 0 20rpx;
  gap: 4rpx;
}

.agree-check {
  width: 32rpx;
  height: 32rpx;
  border-radius: $mb-radius-full;
  border: 2rpx solid $mb-color-border-light;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 8rpx;
  transition: background-color 0.15s, border-color 0.15s;
}

.agree-check--on {
  background: $mb-color-primary;
  border-color: $mb-color-primary;
}

.check-mark {
  font-size: 20rpx;
  color: #ffffff;
  line-height: 1;
}

.agree-text {
  font-size: 22rpx;
  color: $mb-color-text-tertiary;
}

.agree-link {
  font-size: 22rpx;
  color: $mb-color-primary;
  text-decoration: underline;
}
</style>
