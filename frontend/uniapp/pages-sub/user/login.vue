<template>
  <view class="login-page">
    <mb-navbar title="登录" />

    <view class="login-content">
      <view class="brand">
        <view class="logo-box">
          <image v-if="loginLogo" class="logo-image" :src="loginLogo" mode="aspectFit" />
          <view v-else class="logo-bag">
            <view class="bag-body" />
            <view class="bag-handle" />
          </view>
        </view>
        <text class="brand-title">{{ brandName }}</text>
        <text v-if="brandSubtitle" class="brand-subtitle">{{ brandSubtitle }}</text>
      </view>

      <!-- #ifdef MP-WEIXIN -->
      <view v-if="wechatBindStep === 'none'" class="form-section">
        <button class="btn-wechat" :class="{ 'btn-wechat--loading': loading }" @tap="handleWechatMiniLogin">
          <text class="btn-wechat-label">微信一键登录</text>
        </button>
      </view>

      <view v-else-if="wechatBindStep === 'bind'" class="form-section">
        <text class="bind-hint">请绑定手机号以完成登录</text>
        <view v-if="wechatNeedUserInfo" class="profile-card">
          <button class="avatar-picker" open-type="chooseAvatar" @chooseavatar="onChooseAvatar">
            <image v-if="wechatAvatar" class="avatar-image" :src="wechatAvatar" mode="aspectFill" />
            <text v-else class="avatar-plus">+</text>
          </button>
          <input
            v-model="wechatNickname"
            class="nickname-input"
            type="nickname"
            placeholder="请输入微信昵称"
            placeholder-class="placeholder"
          />
        </view>
        <button
          v-if="wechatForcePhone"
          class="btn-wechat"
          open-type="getPhoneNumber"
          @getphonenumber="onBindPhoneNumber"
        >
          <text class="btn-wechat-label">授权手机号快捷绑定</text>
        </button>
        <view v-if="!wechatForcePhone" class="methods-divider">
          <view class="divider-line" />
          <text class="divider-label">或手动输入</text>
          <view class="divider-line" />
        </view>
        <view v-if="!wechatForcePhone" class="input-line">
          <text class="area-code">+86</text>
          <text class="chevron">&#x25BE;</text>
          <input v-model="phone" class="line-input" type="number" maxlength="11"
            placeholder="请输入手机号" placeholder-class="placeholder" />
        </view>
        <view v-if="!wechatForcePhone" class="input-line">
          <text class="input-label">验证码</text>
          <input v-model="smsCode" class="line-input" type="number" maxlength="6"
            placeholder="" placeholder-class="placeholder" />
          <text class="sms-btn" :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode('bind_mobile')">
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <view v-if="!wechatForcePhone" class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleBindMobile">
          <text class="primary-btn-text">{{ loading ? '绑定中...' : '绑 定' }}</text>
        </view>
      </view>

      <view v-else-if="wechatBindStep === 'profile'" class="form-section">
        <text class="bind-hint">请完善头像昵称以完成登录</text>
        <view class="profile-card">
          <button class="avatar-picker" open-type="chooseAvatar" @chooseavatar="onChooseAvatar">
            <image v-if="wechatAvatar" class="avatar-image" :src="wechatAvatar" mode="aspectFill" />
            <text v-else class="avatar-plus">+</text>
          </button>
          <input
            v-model="wechatNickname"
            class="nickname-input"
            type="nickname"
            placeholder="请输入微信昵称"
            placeholder-class="placeholder"
          />
        </view>
        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleBindUserInfo">
          <text class="primary-btn-text">{{ loading ? '登录中...' : '完 成' }}</text>
        </view>
      </view>
      <!-- #endif -->

      <!-- #ifndef MP-WEIXIN -->
      <view v-if="wechatBindStep === 'bind'" class="form-section">
        <text class="bind-hint">请绑定手机号以完成登录</text>
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
            @tap="handleSendCode('wechat_official_bind')">
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleOfficialBindMobile">
          <text class="primary-btn-text">{{ loading ? '绑定中...' : '绑 定' }}</text>
        </view>
      </view>

      <view v-else-if="loginMode === 'sms'" class="form-section">
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
        <view class="agree-toggle" @tap="agreed = !agreed">
          <view class="agree-box" :class="{ 'agree-box--checked': agreed }">
            <text v-if="agreed" class="agree-mark">✓</text>
          </view>
          <text class="agree-text">我已阅读并同意</text>
        </view>
        <view class="agree-links">
          <text class="agree-link" @tap.stop="openAgreement('service')">服务协议</text>
          <text class="agree-sep">与</text>
          <text class="agree-link" @tap.stop="openAgreement('privacy')">隐私权政策</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useAppStore } from '@/store/app'
import { useUserStore } from '@/store/user'
import {
  sendSmsCode,
  loginBySms,
  loginByPassword,
  loginByUsername,
  wechatLogin,
  wechatBindMobile,
  wechatBindByPhoneCode,
  wechatBindUserInfo,
  getWechatOfficialOauthUrl,
  wechatOfficialLogin,
  wechatOfficialBindMobile,
} from '@/api/user/auth'

const userStore = useUserStore()
const appStore = useAppStore()

const loginMode = ref('sms')
const phone = ref('')
const smsCode = ref('')
const account = ref('')
const password = ref('')
const showPassword = ref(false)
const agreed = ref(false)
const loading = ref(false)
const countdown = ref(0)
const wechatBindStep = ref('none')
const wechatBindToken = ref('')
const wechatForcePhone = ref(false)
const wechatNeedUserInfo = ref(false)
const wechatNickname = ref('')
const wechatAvatar = ref('')
const isWechatH5 = ref(false)
const redirectUrl = ref('')

let countdownTimer = null

const brandName = computed(() => (
  appStore.siteConfig?.client_auth_name
  || appStore.siteConfig?.client_site_name
  || appStore.siteConfig?.site_name
  || 'MallBase'
))
const brandSubtitle = computed(() => appStore.siteConfig?.site_slogan || '探索极致生活美学')
const loginLogo = computed(() => appStore.siteConfig?.client_auth_logo || appStore.siteConfig?.client_logo || '')

onLoad((query) => {
  if (query?.redirect) {
    redirectUrl.value = decodeURIComponent(query.redirect)
  }
})

onMounted(() => {
  if (!appStore.siteConfig) {
    appStore.fetchBasicConfig()
  }

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

function validateWechatProfile() {
  if (!wechatNeedUserInfo.value) return true
  if (!wechatAvatar.value) {
    uni.showToast({ title: '请先选择微信头像', icon: 'none' })
    return false
  }
  if (!wechatNickname.value.trim()) {
    uni.showToast({ title: '请输入微信昵称', icon: 'none' })
    return false
  }
  return true
}

function getWechatProfilePayload() {
  if (!wechatNeedUserInfo.value) return {}
  return {
    avatar: wechatAvatar.value,
    nickname: wechatNickname.value.trim(),
  }
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

async function onLoginSuccess(data) {
  userStore.setToken(data.access_token, data.refresh_token)
  await userStore.fetchUserInfo()
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
    await onLoginSuccess(await loginBySms(phone.value, smsCode.value))
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
    await onLoginSuccess(data)
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
    if (data.need_mobile || data.need_userinfo) {
      wechatBindToken.value = data.bind_token || ''
      wechatForcePhone.value = !!data.force_phone_number
      wechatNeedUserInfo.value = !!data.need_userinfo
      wechatBindStep.value = data.need_mobile ? 'bind' : 'profile'
    } else {
      await onLoginSuccess(data)
    }
  } catch (_) { /* request.js shows toast */ }
  finally { loading.value = false }
}

async function onBindPhoneNumber(e) {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!validateWechatProfile()) return
  if (e.detail.errMsg !== 'getPhoneNumber:ok') {
    uni.showToast({ title: '未完成手机号授权', icon: 'none' })
    return
  }
  if (!e.detail.code) {
    uni.showToast({ title: '获取手机号失败,请重试', icon: 'none' })
    return
  }
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  loading.value = true
  try {
    await onLoginSuccess(await wechatBindByPhoneCode(wechatBindToken.value, e.detail.code, getWechatProfilePayload()))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

function onChooseAvatar(e) {
  wechatAvatar.value = e.detail.avatarUrl || ''
}
// #endif

async function handleBindMobile() {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!validateWechatProfile()) return
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  if (!validatePhone()) return
  if (!smsCode.value) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    await onLoginSuccess(await wechatBindMobile(wechatBindToken.value, phone.value, smsCode.value, getWechatProfilePayload()))
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

async function handleBindUserInfo() {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  if (!validateWechatProfile()) return
  loading.value = true
  try {
    const data = await wechatBindUserInfo(wechatBindToken.value, getWechatProfilePayload())
    if (data.need_mobile) {
      wechatBindToken.value = data.bind_token || wechatBindToken.value
      wechatForcePhone.value = !!data.force_phone_number
      wechatNeedUserInfo.value = !!data.need_userinfo
      wechatBindStep.value = 'bind'
    } else {
      await onLoginSuccess(data)
    }
  } catch (_) { /* handled */ }
  finally { loading.value = false }
}

async function handleOfficialBindMobile() {
  if (loading.value) return
  if (!checkAgreement()) return
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期,请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }
  if (!validatePhone()) return
  if (!smsCode.value) {
    uni.showToast({ title: '请输入验证码', icon: 'none' })
    return
  }
  loading.value = true
  try {
    await onLoginSuccess(await wechatOfficialBindMobile(wechatBindToken.value, phone.value, smsCode.value))
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
        wechatBindToken.value = data.bind_token || ''
        wechatBindStep.value = 'bind'
        wechatForcePhone.value = false
      } else {
        return onLoginSuccess(data)
      }
    })
    .catch(() => {})
    .finally(() => { loading.value = false })
}
// #endif

async function handleWechatOfficialLogin() {
  if (!checkAgreement()) return
  // #ifdef H5
  if (isWechatH5.value) {
    loading.value = true
    try {
      const redirectUri = window.location.href.split('?')[0]
      const data = await getWechatOfficialOauthUrl(redirectUri, 'login')
      if (data.url) {
        window.location.href = data.url
      }
    } catch (_) { /* request.js shows toast */ }
    finally { loading.value = false }
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

.logo-image { width: 120rpx; height: 120rpx; border-radius: $mb-radius-lg; }

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

.profile-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  margin-bottom: 36rpx;
  padding: 24rpx 0;
  border-bottom: 2rpx solid $mb-color-border;
}

.avatar-picker {
  width: 96rpx;
  height: 96rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-bg-secondary;
  border: 2rpx solid $mb-color-border;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  margin: 0;
  overflow: hidden;

  &::after { display: none; }
}

.avatar-image { width: 96rpx; height: 96rpx; }
.avatar-plus { font-size: 44rpx; color: $mb-color-text-tertiary; line-height: 1; }
.nickname-input { flex: 1; height: 80rpx; font-size: 30rpx; color: $mb-color-text; }

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
.agree-toggle { display: flex; align-items: center; gap: 10rpx; margin-bottom: 8rpx; }
.agree-box {
  width: 28rpx;
  height: 28rpx;
  border: 2rpx solid $mb-color-border;
  border-radius: $mb-radius-sm;
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}
.agree-box--checked {
  background: $mb-color-text;
  border-color: $mb-color-text;
}
.agree-mark {
  font-size: 20rpx;
  line-height: 1;
  color: $mb-color-text-inverse;
}
.agree-text { font-size: $mb-font-xs; color: $mb-color-text-tertiary; }
.agree-links { display: flex; align-items: center; gap: 8rpx; }
.agree-link { font-size: $mb-font-xs; color: $mb-color-text; font-weight: 500; }
.agree-sep { font-size: $mb-font-xs; color: $mb-color-text-tertiary; }
</style>
