<template>
  <view class="login-page">
    <mb-navbar title="登录" />

    <view class="login-content">
      <view class="brand">
        <view class="logo-box">
          <!-- #ifdef MP-WEIXIN -->
          <text class="logo-icon">&#x1F6CD;</text>
          <!-- #endif -->
          <!-- #ifndef MP-WEIXIN -->
          <view class="logo-bag">
            <view class="bag-body" />
            <view class="bag-handle" />
          </view>
          <!-- #endif -->
        </view>
        <text class="brand-title">MallBase</text>
        <text class="brand-subtitle">探索极致生活美学</text>
      </view>

      <!-- #ifdef MP-WEIXIN -->
      <view v-if="wechatBindStep === 'none'" class="form-section">
        <button class="btn-wechat" :class="{ 'btn-wechat--loading': loading }" @tap="handleWechatMiniLogin">
          <text class="btn-wechat-label">微信一键登录</text>
        </button>
        <view class="mode-switch" @tap="wechatBindStep = 'sms'">
          <text class="link-text">使用手机号登录</text>
        </view>
      </view>

      <view v-else-if="wechatBindStep === 'bind'" class="form-section">
        <text class="bind-hint">请绑定手机号以完成登录</text>
        <button
          v-if="wechatForcePhone"
          class="btn-wechat"
          open-type="getPhoneNumber"
          @getphonenumber="onBindPhoneNumber"
        >
          <text class="btn-wechat-label">授权手机号快捷绑定</text>
        </button>
        <view class="methods-divider">
          <view class="divider-line" />
          <text class="divider-label">或手动输入</text>
          <view class="divider-line" />
        </view>
        <view class="input-line">
          <text class="area-code">+86</text>
          <text class="chevron">&#x25BE;</text>
          <input v-model="phone" class="line-input" type="number" maxlength="11"
            placeholder="请输入手机号" placeholder-class="placeholder" />
        </view>
        <view class="input-line">
          <text class="input-label">验证码</text>
          <input v-model="smsCode" class="line-input" type="number" maxlength="6"
            placeholder="" placeholder-class="placeholder" />
          <text class="sms-btn" :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode('bind_mobile')">
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleBindMobile">
          <text class="primary-btn-text">{{ loading ? '绑定中...' : '绑 定' }}</text>
        </view>
      </view>

      <view v-else class="form-section">
        <view class="input-line">
          <text class="area-code">+86</text>
          <text class="chevron">&#x25BE;</text>
          <input v-model="phone" class="line-input" type="number" maxlength="11"
            placeholder="请输入手机号" placeholder-class="placeholder" />
        </view>
        <view class="input-line">
          <text class="input-label">验证码</text>
          <input v-model="smsCode" class="line-input" type="number" maxlength="6"
            placeholder="" placeholder-class="placeholder" />
          <text class="sms-btn" :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode('login')">
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleSmsLogin">
          <text class="primary-btn-text">{{ loading ? '登录中...' : '登 录' }}</text>
        </view>
        <view class="mode-switch" @tap="wechatBindStep = 'none'">
          <text class="link-text">返回微信登录</text>
        </view>
      </view>
      <!-- #endif -->

      <!-- #ifndef MP-WEIXIN -->
      <view v-if="loginMode === 'sms'" class="form-section">
        <view class="input-line">
          <text class="area-code">+86</text>
          <text class="chevron">&#x25BE;</text>
          <input v-model="phone" class="line-input" type="number" maxlength="11"
            placeholder="请输入手机号" placeholder-class="placeholder" />
        </view>
        <view class="input-line">
          <text class="input-label">验证码</text>
          <input v-model="smsCode" class="line-input" type="number" maxlength="6"
            placeholder="" placeholder-class="placeholder" />
          <text class="sms-btn" :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode('login')">
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleSmsLogin">
          <text class="primary-btn-text">{{ loading ? '登录中...' : '登 录' }}</text>
        </view>
        <view class="mode-switch" @tap="loginMode = 'password'">
          <text class="link-text">密码登录</text>
        </view>
      </view>

      <view v-else class="form-section">
        <view class="input-line">
          <input v-model="account" class="line-input line-input--full" type="text"
            placeholder="手机号 / 用户名" placeholder-class="placeholder" />
        </view>
        <view class="input-line">
          <input v-model="password" class="line-input line-input--full"
            :type="showPassword ? 'text' : 'password'"
            placeholder="密码" placeholder-class="placeholder" />
          <view class="eye-toggle" @tap="showPassword = !showPassword">
            <view class="eye-shape" />
            <view v-if="!showPassword" class="eye-slash" />
          </view>
        </view>
        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handlePasswordLogin">
          <text class="primary-btn-text">{{ loading ? '登录中...' : '登 录' }}</text>
        </view>
        <view class="mode-switch" @tap="loginMode = 'sms'">
          <text class="link-text">验证码登录</text>
        </view>
      </view>

      <view class="other-methods">
        <text class="other-methods-label">其他登录方式</text>
        <view class="social-row">
          <view class="social-btn" @tap="handleWechatOfficialLogin">
            <view class="wechat-icon">
              <view class="wc-bubble" />
              <view class="wc-bubble wc-bubble--r" />
            </view>
          </view>
          <view v-if="!isWechatH5" class="social-btn">
            <view class="fp-icon">
              <view class="fp-oval" />
            </view>
          </view>
        </view>
      </view>
      <!-- #endif -->

      <view class="agreement">
        <text class="agree-text">登录即代表您已阅读并同意</text>
        <view class="agree-links">
          <text class="agree-link" @tap="openAgreement('service')">服务协议</text>
          <text class="agree-sep">与</text>
          <text class="agree-link" @tap="openAgreement('privacy')">隐私权政策</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import {
  sendSmsCode,
  loginBySms,
  loginByPassword,
  loginByUsername,
  wechatLogin,
  wechatBindMobile,
  wechatBindByPhoneCode,
  wechatOfficialLogin,
} from '@/api/user/auth'

const userStore = useUserStore()

const loginMode = ref('sms')
const phone = ref('')
const smsCode = ref('')
const account = ref('')
const password = ref('')
const showPassword = ref(false)
const agreed = ref(true)
const loading = ref(false)
const countdown = ref(0)
const wechatBindStep = ref('none')
const wechatOpenid = ref('')
const wechatForcePhone = ref(false)
const isWechatH5 = ref(false)
const redirectUrl = ref('')

let countdownTimer = null

onLoad((query) => {
  if (query?.redirect) {
    redirectUrl.value = decodeURIComponent(query.redirect)
  }
})

onMounted(() => {
  // #ifdef H5
  isWechatH5.value = /micromessenger/i.test(navigator.userAgent)
  handleWechatH5Callback()
  // #endif
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

function checkAgreement() {
  if (!agreed.value) {
    uni.showToast({ title: '请先同意服务协议与隐私政策', icon: 'none' })
    return false
  }
  return true
}

async function handleSendCode(scene = 'login') {
  if (countdown.value > 0) return
  if (!validatePhone()) return
  try {
    await sendSmsCode(phone.value, scene)
    startCountdown()
    uni.showToast({ title: '验证码已发送', icon: 'none' })
  } catch (_) { /* request.js shows toast */ }
}

function onLoginSuccess(data) {
  userStore.setToken(data.access_token, data.refresh_token)
  userStore.fetchUserInfo()
  if (redirectUrl.value) {
    const url = redirectUrl.value
    uni.redirectTo({
      url,
      fail() {
        uni.switchTab({ url: url.split('?')[0] })
      },
    })
    return
  }
  const pages = getCurrentPages()
  if (pages.length > 1) {
    uni.navigateBack()
  } else {
    uni.switchTab({ url: '/pages/index/index' })
  }
}

async function handleSmsLogin() {
  if (loading.value) return
  if (!checkAgreement() || !validatePhone()) return
  if (!smsCode.value) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    onLoginSuccess(await loginBySms(phone.value, smsCode.value))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

async function handlePasswordLogin() {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!account.value) {
    uni.showToast({ title: '请输入手机号或用户名', icon: 'none' })
    return
  }
  if (!password.value) {
    uni.showToast({ title: '请输入密码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    const isPhone = /^1\d{10}$/.test(account.value)
    const data = isPhone
      ? await loginByPassword(account.value, password.value)
      : await loginByUsername(account.value, password.value)
    onLoginSuccess(data)
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

// #ifdef MP-WEIXIN
function loginWithWechatMini() {
  return new Promise((resolve, reject) => {
    uni.login({
      provider: 'weixin',
      success: resolve,
      fail: reject,
    })
  })
}

async function handleWechatMiniLogin() {
  if (loading.value) return
  if (!checkAgreement()) return
  loading.value = true
  try {
    let loginResult
    try {
      loginResult = await loginWithWechatMini()
    } catch (_) {
      uni.showToast({ title: '微信登录失败,请重试', icon: 'none' })
      return
    }
    const { code } = loginResult
    if (!code) {
      uni.showToast({ title: '微信登录失败,请重试', icon: 'none' })
      return
    }
    const data = await wechatLogin(code)
    if (data.need_mobile) {
      wechatOpenid.value = data.openid
      wechatForcePhone.value = !!data.force_phone_number
      wechatBindStep.value = 'bind'
    } else {
      onLoginSuccess(data)
    }
  } catch (_) { /* request.js shows toast */ }
  finally { loading.value = false }
}

async function onBindPhoneNumber(e) {
  if (loading.value) return
  if (e.detail.errMsg !== 'getPhoneNumber:ok') {
    uni.showToast({ title: '未完成手机号授权', icon: 'none' })
    return
  }
  if (!e.detail.code) {
    uni.showToast({ title: '获取手机号失败,请重试', icon: 'none' })
    return
  }
  if (!wechatOpenid.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  loading.value = true
  try {
    onLoginSuccess(await wechatBindByPhoneCode(wechatOpenid.value, e.detail.code))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}
// #endif

async function handleBindMobile() {
  if (loading.value) return
  if (!validatePhone()) return
  if (!smsCode.value) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    onLoginSuccess(await wechatBindMobile(wechatOpenid.value, phone.value, smsCode.value))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

// #ifdef H5
function handleWechatH5Callback() {
  if (!isWechatH5.value) return
  const url = new URL(window.location.href)
  const code = url.searchParams.get('code')
  if (!code) return
  url.searchParams.delete('code')
  url.searchParams.delete('state')
  window.history.replaceState({}, '', url.toString())
  loading.value = true
  wechatOfficialLogin(code)
    .then((data) => {
      if (data.need_mobile) {
        wechatOpenid.value = data.openid
        wechatBindStep.value = 'bind'
        wechatForcePhone.value = false
      } else {
        onLoginSuccess(data)
      }
    })
    .catch(() => {})
    .finally(() => { loading.value = false })
}
// #endif

function handleWechatOfficialLogin() {
  if (!checkAgreement()) return
  // #ifdef H5
  if (isWechatH5.value) {
    const appId = ''
    const redirectUri = encodeURIComponent(window.location.href.split('?')[0])
    window.location.href = `https://open.weixin.qq.com/connect/oauth2/authorize?appid=${appId}&redirect_uri=${redirectUri}&response_type=code&scope=snsapi_userinfo&state=login#wechat_redirect`
  }
  // #endif
}

function openAgreement(type) {
  uni.navigateTo({
    url: `/pages-sub/user/agreement?type=${type === 'privacy' ? 'privacy' : 'service'}`,
  })
}
</script>

<style lang="scss" scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: $mb-color-bg;
  position: relative;
  overflow: hidden;

  // Subtle marble/silk texture via layered radial gradients
  &::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
      radial-gradient(ellipse 120% 80% at 20% 10%, rgba(0, 0, 0, 0.018) 0%, transparent 50%),
      radial-gradient(ellipse 100% 60% at 80% 20%, rgba(0, 0, 0, 0.012) 0%, transparent 40%),
      radial-gradient(ellipse 80% 100% at 90% 80%, rgba(0, 0, 0, 0.02) 0%, transparent 50%),
      radial-gradient(ellipse 60% 80% at 10% 90%, rgba(0, 0, 0, 0.015) 0%, transparent 45%);
    pointer-events: none;
  }

  &::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
      linear-gradient(135deg, rgba(245, 245, 245, 0.4) 0%, transparent 40%),
      linear-gradient(315deg, rgba(240, 240, 240, 0.3) 0%, transparent 35%);
    pointer-events: none;
  }
}

.login-content {
  width: 100%;
  max-width: 620rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
  z-index: 1;
  padding: 0 $mb-spacing-page;
}

// ---- Brand ----
.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 100rpx;
}

.logo-box {
  width: 120rpx;
  height: 120rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 32rpx;
}

.logo-icon { font-size: 72rpx; line-height: 1; }

.logo-bag { position: relative; width: 72rpx; height: 80rpx; }

.bag-body {
  position: absolute; bottom: 0; left: 6rpx; right: 6rpx; height: 56rpx;
  background: $mb-color-text;
  border-radius: 4rpx 4rpx 10rpx 10rpx;
}

.bag-handle {
  position: absolute; top: 0; left: 50%; transform: translateX(-50%);
  width: 34rpx; height: 30rpx;
  border: 4rpx solid $mb-color-text;
  border-bottom: none;
  border-radius: 17rpx 17rpx 0 0;
}

.brand-title { font-size: 72rpx; font-weight: 700; letter-spacing: -0.02em; color: $mb-color-text; line-height: 1.1; margin-bottom: 16rpx; }
.brand-subtitle { font-size: 26rpx; color: $mb-color-text-tertiary; letter-spacing: 0.16em; }

// ---- Form ----
.form-section {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 0;
}

.input-line {
  display: flex;
  align-items: center;
  height: 108rpx;
  border-bottom: 2rpx solid $mb-color-border;
  padding: 0 4rpx;
  transition: border-color 0.2s;

  &:focus-within {
    border-bottom-color: $mb-color-text;
  }
}

.area-code { font-size: 30rpx; font-weight: 500; color: $mb-color-text; flex-shrink: 0; }
.chevron { font-size: 20rpx; color: $mb-color-text-tertiary; margin-left: 4rpx; flex-shrink: 0; }
.input-label { font-size: 30rpx; color: $mb-color-text-tertiary; flex-shrink: 0; }
.line-input { flex: 1; font-size: 30rpx; color: $mb-color-text; height: 100%; margin-left: 24rpx; }
.line-input--full { margin-left: 0; }
.placeholder { color: $mb-color-border-light; }
.sms-btn { flex-shrink: 0; font-size: 26rpx; font-weight: 500; color: $mb-color-text; white-space: nowrap; letter-spacing: 0.04em; }
.sms-btn--off { color: $mb-color-border-light; }

// ---- Eye toggle ----
.eye-toggle { flex-shrink: 0; width: 56rpx; height: 56rpx; display: flex; align-items: center; justify-content: center; position: relative; }

.eye-shape {
  width: 36rpx; height: 24rpx; border: 2rpx solid $mb-color-text-tertiary; border-radius: 50%; position: relative;
  &::after { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 10rpx; height: 10rpx; border-radius: 50%; background: $mb-color-text-tertiary; }
}

.eye-slash { position: absolute; top: 12rpx; left: 50%; width: 2rpx; height: 32rpx; background: $mb-color-text-tertiary; transform: translateX(-50%) rotate(45deg); }

// ---- Buttons ----
.primary-btn {
  height: 100rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-text;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 56rpx;
  transition: opacity 0.15s, transform 0.15s;

  &:active {
    opacity: 0.85;
    transform: scale(0.98);
  }
}

.primary-btn--loading { opacity: 0.7; pointer-events: none; }
.primary-btn-text { font-size: $mb-font-lg; font-weight: 600; color: $mb-color-text-inverse; letter-spacing: 0.4em; }

.btn-wechat {
  width: 100%;
  height: 100rpx;
  border-radius: $mb-radius-full;
  background: #07c160;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: $mb-spacing-sm;
  border: none;
  padding: 0;
  margin: 0;

  &::after { display: none; }
  &:active { opacity: 0.85; }
}

.btn-wechat--loading { opacity: 0.7; pointer-events: none; }
.btn-wechat-label { font-size: $mb-font-lg; font-weight: 500; color: $mb-color-text-inverse; letter-spacing: 0.1em; }
.mode-switch { display: flex; justify-content: center; padding-top: 28rpx; }
.link-text { font-size: $mb-font-sm; color: $mb-color-text-tertiary; font-weight: 400; }
.bind-hint { font-size: $mb-font-md; color: $mb-color-text-secondary; text-align: center; margin-bottom: $mb-spacing-md; }

// ---- Dividers (WeChat bind only) ----
.methods-divider { display: flex; align-items: center; gap: $mb-spacing-md; margin-bottom: 40rpx; }
.divider-line { flex: 1; height: 2rpx; background: $mb-color-divider; }
.divider-label { font-size: $mb-font-sm; color: $mb-color-border-light; white-space: nowrap; }

// ---- Social ----
.other-methods {
  width: 100%;
  margin-top: 72rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.other-methods-label { font-size: $mb-font-sm; color: $mb-color-border-light; margin-bottom: 36rpx; }
.social-row { display: flex; justify-content: center; gap: $mb-spacing-xl; }

.social-btn {
  width: 96rpx;
  height: 96rpx;
  border-radius: $mb-radius-full;
  background: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.15s;

  &:active { transform: scale(0.92); }
}

.wechat-icon { position: relative; width: 44rpx; height: 38rpx; }

.wc-bubble {
  position: absolute; bottom: 0; left: 0;
  width: 30rpx; height: 26rpx;
  background: #07c160;
  border-radius: 50%;

  &::after {
    content: '';
    position: absolute;
    bottom: -4rpx;
    left: 6rpx;
    width: 10rpx;
    height: 8rpx;
    background: #07c160;
    clip-path: polygon(0 0, 100% 0, 30% 100%);
  }
}

.wc-bubble--r {
  left: auto; right: 0; bottom: 8rpx; width: 24rpx; height: 20rpx;
  &::after { left: auto; right: 4rpx; clip-path: polygon(0 0, 100% 0, 70% 100%); }
}

.fp-icon { display: flex; align-items: center; justify-content: center; width: 44rpx; height: 52rpx; }

.fp-oval {
  width: 32rpx; height: 40rpx; border: 3rpx solid $mb-color-text; border-radius: 50%; position: relative;
  &::before { content: ''; position: absolute; top: 6rpx; right: -2rpx; width: 16rpx; height: 22rpx; border: 2rpx solid $mb-color-text; border-left: none; border-radius: 0 50% 50% 0; }
  &::after { content: ''; position: absolute; top: 10rpx; left: -2rpx; width: 12rpx; height: 16rpx; border: 2rpx solid $mb-color-text; border-right: none; border-radius: 50% 0 0 50%; }
}

// ---- Agreement ----
.agreement { display: flex; flex-direction: column; align-items: center; margin-top: 80rpx; padding: 0 20rpx; }
.agree-text { font-size: $mb-font-xs; color: $mb-color-text-tertiary; margin-bottom: 8rpx; }
.agree-links { display: flex; align-items: center; gap: 8rpx; }
.agree-link { font-size: $mb-font-xs; color: $mb-color-text; font-weight: 500; }
.agree-sep { font-size: $mb-font-xs; color: $mb-color-text-tertiary; }
</style>
