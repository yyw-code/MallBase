<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <view class="nebula-bg" />
    <view class="blob blob--1" />
    <view class="blob blob--2" />
    <view class="blob blob--3" />
    <view class="stars" />

    <mb-navbar title="绑定手机号" bgColor="transparent" textColor="#ffffff" />

    <view class="page-content">
      <!-- Header -->
      <view class="header-section">
        <text class="page-title">绑定手机号</text>
        <text class="page-hint">请绑定手机号以完成登录</text>
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

        <!-- SMS Code -->
        <view class="input-pill">
          <input
            v-model="smsCode"
            class="pill-input"
            type="number"
            maxlength="6"
            placeholder="验证码"
            placeholder-class="placeholder"
          />
          <view class="pill-sep" />
          <text
            class="sms-btn"
            :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode"
          >
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <text v-if="smsExpireText" class="sms-expire-hint">{{ smsExpireText }}</text>

        <!-- Bind button -->
        <view
          class="primary-btn"
          :class="{ 'primary-btn--loading': loading }"
          @tap="handleBind"
        >
          <text class="primary-btn-text">{{ loading ? '绑定中...' : '绑 定' }}</text>
        </view>
      </view>
    </view>
      <mb-copyright-footer />
      <mb-floating-action />
</view>
</template>

<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { sendSmsCode, wechatBindMobile } from '@/api/user/auth'
const decorateStore = useDecorateStore()

const userStore = useUserStore()

const openid = ref('')
const phone = ref('')
const smsCode = ref('')
const loading = ref(false)
const countdown = ref(0)
const smsExpireCountdown = ref(0)

let countdownTimer = null
let smsExpireTimer = null

onLoad((query) => {
  if (query?.openid) {
    openid.value = query.openid
  }
})

onUnload(() => {
  if (countdownTimer) {
    clearInterval(countdownTimer)
    countdownTimer = null
  }
  if (smsExpireTimer) {
    clearInterval(smsExpireTimer)
    smsExpireTimer = null
  }
})

function startCountdown() {
  if (countdownTimer) {
    clearInterval(countdownTimer)
    countdownTimer = null
  }
  countdown.value = 60
  countdownTimer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0) {
      clearInterval(countdownTimer)
      countdownTimer = null
    }
  }, 1000)
}

function startSmsExpireCountdown(ttl) {
  if (smsExpireTimer) {
    clearInterval(smsExpireTimer)
    smsExpireTimer = null
  }
  smsExpireCountdown.value = Math.max(0, Number(ttl) || 0)
  if (smsExpireCountdown.value <= 0) return

  smsExpireTimer = setInterval(() => {
    smsExpireCountdown.value -= 1
    if (smsExpireCountdown.value <= 0) {
      clearInterval(smsExpireTimer)
      smsExpireTimer = null
    }
  }, 1000)
}

function formatSmsExpire(seconds) {
  const minute = Math.floor(seconds / 60)
  const second = seconds % 60
  return `${String(minute).padStart(2, '0')}:${String(second).padStart(2, '0')}`
}

const smsExpireText = computed(() => {
  if (smsExpireCountdown.value > 0) {
    return `验证码 ${formatSmsExpire(smsExpireCountdown.value)} 内有效`
  }
  return smsCode.value ? '验证码已过期，请重新获取' : ''
})

function validatePhone() {
  if (!/^1\d{10}$/.test(phone.value)) {
    uni.showToast({ title: '请输入正确的手机号', icon: 'none' })
    return false
  }
  return true
}

async function handleSendCode() {
  if (countdown.value > 0) return
  if (!validatePhone()) return

  try {
    const data = await sendSmsCode(phone.value, 'bind_mobile')
    startCountdown()
    startSmsExpireCountdown(data?.code_ttl || 300)
    uni.showToast({ title: '验证码已发送', icon: 'none' })
  } catch (_) {
    /* request.js shows toast */
  }
}

async function handleBind() {
  if (loading.value) return
  if (!validatePhone()) return
  if (!smsCode.value) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  if (!openid.value) {
    uni.showToast({ title: '登录态已过期，请重新登录', icon: 'none' })
    return
  }

  loading.value = true
  try {
    const data = await wechatBindMobile(openid.value, phone.value, smsCode.value)
    userStore.setToken(data.access_token, data.refresh_token)
    await userStore.fetchUserInfo()
    await decorateStore.fetchMyThemePreference({ force: true })

    uni.showToast({ title: '绑定成功', icon: 'success' })
    setTimeout(() => {
      uni.switchTab({ url: '/pages/index/index' })
    }, 800)
  } catch (_) {
    /* request.js shows toast */
  } finally {
    loading.value = false
  }
}
</script>

<style lang="scss" scoped>
$glass-primary: #0d50d5;
$glass-accent: #4fe3d7;

.page {
  position: relative;
  min-height: 100vh;
  background: #0b1a4d;
  display: flex;
  flex-direction: column;
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
  position: relative;
  z-index: 1;
  padding: 24rpx 48rpx calc(140rpx + env(safe-area-inset-bottom));
}

// ---- Header ----
.header-section {
  padding: 64rpx 0 24rpx;
  margin-bottom: 16rpx;
}

.page-title {
  display: block;
  font-size: 56rpx;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: -1rpx;
  line-height: 1.2;
  margin-bottom: 16rpx;
  text-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.18);
}

.page-hint {
  display: block;
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.65);
  line-height: 1.5;
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

.placeholder {
  color: rgba(255, 255, 255, 0.4);
}

.sms-btn {
  flex-shrink: 0;
  font-size: 26rpx;
  font-weight: 600;
  color: $glass-accent;
  white-space: nowrap;
}

.sms-btn--off {
  color: rgba(255, 255, 255, 0.35);
}

.sms-expire-hint {
  display: block;
  margin-top: -16rpx;
  padding: 0 12rpx;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.58);
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
  margin-top: 24rpx;
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
</style>
