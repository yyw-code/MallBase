<template>
  <view class="page">
    <mb-navbar title="绑定手机号" />

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
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { sendSmsCode, wechatBindMobile } from '@/api/user/auth'

const userStore = useUserStore()

const openid = ref('')
const phone = ref('')
const smsCode = ref('')
const loading = ref(false)
const countdown = ref(0)

let countdownTimer = null

onLoad((query) => {
  if (query?.openid) {
    openid.value = query.openid
  }
})

function startCountdown() {
  countdown.value = 60
  countdownTimer = setInterval(() => {
    countdown.value -= 1
    if (countdown.value <= 0) {
      clearInterval(countdownTimer)
      countdownTimer = null
    }
  }, 1000)
}

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
    await sendSmsCode(phone.value, 'bind_mobile')
    startCountdown()
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
    userStore.fetchUserInfo()

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
.page {
  min-height: 100vh;
  background: #ffffff;
  display: flex;
  flex-direction: column;
}

.page-content {
  width: 100%;
  padding: 0 48rpx;
}

// ---- Header ----
.header-section {
  padding-top: 80rpx;
  margin-bottom: 64rpx;
}

.page-title {
  display: block;
  font-size: 52rpx;
  font-weight: 600;
  color: $mb-color-text;
  letter-spacing: -0.02em;
  line-height: 1.2;
  margin-bottom: 16rpx;
}

.page-hint {
  display: block;
  font-size: 28rpx;
  color: $mb-color-text-tertiary;
  line-height: 1.5;
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
  background: $mb-color-bg-secondary;
  padding: 0 32rpx;
  transition: box-shadow 0.2s;

  &:focus-within {
    box-shadow: 0 0 0 3rpx rgba(13, 80, 213, 0.15);
  }
}

.area-code {
  font-size: 30rpx;
  font-weight: 500;
  color: $mb-color-text;
  flex-shrink: 0;
}

.pill-sep {
  width: 2rpx;
  height: 36rpx;
  background: $mb-color-border;
  margin: 0 24rpx;
  flex-shrink: 0;
}

.pill-input {
  flex: 1;
  font-size: 30rpx;
  color: $mb-color-text;
  height: 100%;
}

.placeholder {
  color: $mb-color-border-light;
}

.sms-btn {
  flex-shrink: 0;
  font-size: 26rpx;
  font-weight: 500;
  color: $mb-color-primary;
  white-space: nowrap;
}

.sms-btn--off {
  color: $mb-color-border-light;
}

// ---- Buttons ----
.primary-btn {
  height: 100rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-text;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 24rpx;
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
</style>
