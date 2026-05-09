<template>
  <view class="page">
    <view class="nebula-bg" />
    <view class="blob blob--1" />
    <view class="blob blob--2" />
    <view class="blob blob--3" />
    <view class="stars" />

    <mb-navbar title="注册" bgColor="transparent" textColor="#ffffff" />

    <view class="page-content">
      <!-- Brand header -->
      <view class="brand">
        <view class="logo-box">
          <view class="logo-box__sheen" />
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
$glass-primary: #0d50d5;
$glass-accent: #4fe3d7;

.page {
  position: relative;
  min-height: 100vh;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  background: #0b1a4d;
  overflow: hidden;
}

.nebula-bg {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(135deg, #0b1a4d 0%, #0d50d5 50%, #1e2a6b 100%);
  z-index: 0;
}

.blob {
  position: absolute;
  border-radius: 50%;
  pointer-events: none;
  z-index: 0;
}

.blob--1 {
  top: -200rpx;
  left: -180rpx;
  width: 800rpx;
  height: 800rpx;
  background: radial-gradient(circle at center, rgba(255, 92, 182, 0.55) 0%, rgba(255, 92, 182, 0) 70%);
}

.blob--2 {
  top: 80rpx;
  right: -240rpx;
  width: 760rpx;
  height: 760rpx;
  background: radial-gradient(circle at center, rgba(79, 227, 215, 0.55) 0%, rgba(79, 227, 215, 0) 70%);
}

.blob--3 {
  bottom: 200rpx;
  right: -120rpx;
  width: 840rpx;
  height: 840rpx;
  background: radial-gradient(circle at center, rgba(124, 92, 255, 0.32) 0%, rgba(124, 92, 255, 0) 70%);
}

.stars {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-image: radial-gradient(rgba(255, 255, 255, 0.55) 1rpx, transparent 0);
  background-size: 160rpx 160rpx;
  background-position: 0 0;
  opacity: 0.18;
  z-index: 0;
  pointer-events: none;
}

.page-content {
  width: 100%;
  max-width: 750rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  z-index: 1;
  padding: 24rpx 48rpx calc(80rpx + env(safe-area-inset-bottom));
}

// ---- Brand ----
.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-top: 32rpx;
  margin-bottom: 56rpx;
}

.logo-box {
  position: relative;
  width: 168rpx;
  height: 168rpx;
  border-radius: 48rpx;
  background: rgba(255, 255, 255, 0.14);
  border: 1rpx solid rgba(255, 255, 255, 0.3);
  box-shadow: 0 16rpx 40rpx rgba(0, 0, 0, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 32rpx;
  overflow: hidden;
  /* #ifdef H5 */
  backdrop-filter: blur(30px);
  -webkit-backdrop-filter: blur(30px);
  /* #endif */
}

.logo-box__sheen {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 50%;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.28) 0%, rgba(255, 255, 255, 0) 100%);
  pointer-events: none;
}

.logo-bag {
  position: relative;
  width: 76rpx;
  height: 84rpx;
  z-index: 1;
}

.bag-body {
  position: absolute;
  bottom: 0;
  left: 4rpx;
  right: 4rpx;
  height: 60rpx;
  border: 3rpx solid #ffffff;
  border-radius: 10rpx 10rpx 16rpx 16rpx;
  background: rgba(255, 255, 255, 0.08);
}

.bag-handle {
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 36rpx;
  height: 28rpx;
  border: 3rpx solid #ffffff;
  border-bottom: none;
  border-radius: 18rpx 18rpx 0 0;
}

.brand-title {
  font-size: 56rpx;
  font-weight: 700;
  letter-spacing: -1rpx;
  color: #ffffff;
  line-height: 1.15;
  margin-bottom: 16rpx;
  text-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.18);
}

.brand-subtitle {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.65);
  letter-spacing: 0;
}

// ---- Glass card ----
.form-section {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 24rpx;
  padding: 56rpx 40rpx 48rpx;
  position: relative;
  border-radius: 56rpx;
  background: rgba(255, 255, 255, 0.12);
  border: 1rpx solid rgba(255, 255, 255, 0.25);
  box-shadow: 0 28rpx 80rpx rgba(0, 0, 0, 0.3);
  overflow: hidden;
  /* #ifdef H5 */
  backdrop-filter: blur(40px);
  -webkit-backdrop-filter: blur(40px);
  /* #endif */
  /* #ifndef H5 */
  background: rgba(255, 255, 255, 0.2);
  /* #endif */

  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 35%;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.22) 0%, rgba(255, 255, 255, 0) 100%);
    pointer-events: none;
  }
}

// ---- Inputs ----
.input-pill {
  position: relative;
  display: flex;
  align-items: center;
  height: 104rpx;
  border-radius: 28rpx;
  background: rgba(255, 255, 255, 0.1);
  border: 1rpx solid rgba(255, 255, 255, 0.2);
  padding: 0 28rpx;
  transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s;

  &:focus-within {
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.16);
    box-shadow: 0 0 0 4rpx rgba(255, 255, 255, 0.08);
  }
}

.area-code {
  font-size: 30rpx;
  font-weight: 500;
  color: #ffffff;
  flex-shrink: 0;
}

.pill-sep {
  width: 1rpx;
  height: 36rpx;
  background: rgba(255, 255, 255, 0.22);
  margin: 0 24rpx;
  flex-shrink: 0;
}

.pill-input {
  flex: 1;
  font-size: 30rpx;
  color: #ffffff;
  height: 100%;
}

.pill-input--full {
  padding-left: 8rpx;
}

.placeholder {
  color: rgba(255, 255, 255, 0.4);
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
  border: 2rpx solid rgba(255, 255, 255, 0.65);
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
    background: rgba(255, 255, 255, 0.85);
  }
}

.eye-slash {
  position: absolute;
  top: 12rpx;
  left: 50%;
  width: 2rpx;
  height: 32rpx;
  background: rgba(255, 255, 255, 0.65);
  transform: translateX(-50%) rotate(45deg);
}

// ---- Primary button ----
.primary-btn {
  position: relative;
  height: 104rpx;
  border-radius: 32rpx;
  background: rgba(255, 255, 255, 0.96);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 16rpx;
  box-shadow: 0 0 32rpx rgba(255, 255, 255, 0.25), 0 12rpx 32rpx rgba(0, 0, 0, 0.18);
  overflow: hidden;
  transition: transform 0.15s, opacity 0.15s, box-shadow 0.15s;

  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: 12rpx;
    right: 12rpx;
    height: 1rpx;
    background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.95) 50%, transparent 100%);
  }

  &:active {
    transform: scale(0.98);
    opacity: 0.92;
  }
}

.primary-btn--loading {
  opacity: 0.7;
  pointer-events: none;
}

.primary-btn-text {
  font-size: 32rpx;
  font-weight: 700;
  color: $glass-primary;
  letter-spacing: 4rpx;
}

.mode-switch {
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 8rpx 0;
  gap: 8rpx;
}

.link-text-plain {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.7);
}

.link-text {
  font-size: 26rpx;
  color: #ffffff;
  font-weight: 600;
}

// ---- Agreement ----
.agreement {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  margin-top: 48rpx;
  padding: 0 24rpx;
  gap: 6rpx;
  position: relative;
  z-index: 1;
}

.agree-check {
  width: 32rpx;
  height: 32rpx;
  border-radius: 50%;
  border: 1rpx solid rgba(255, 255, 255, 0.4);
  background: rgba(255, 255, 255, 0.06);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 8rpx;
  transition: background-color 0.15s, border-color 0.15s;
  box-sizing: border-box;
}

.agree-check--on {
  background: rgba(255, 255, 255, 0.92);
  border-color: rgba(255, 255, 255, 0.92);
}

.check-mark {
  font-size: 22rpx;
  color: $glass-primary;
  line-height: 1;
  font-weight: 700;
}

.agree-text {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.55);
}

.agree-link {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.95);
  font-weight: 500;
  text-decoration: underline;
  text-decoration-color: rgba(255, 255, 255, 0.3);
  text-underline-offset: 4rpx;
}
</style>
