<template>
  <view
    class="login-page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <view class="nebula-bg" />
    <view class="blob blob--1" />
    <view class="blob blob--2" />
    <view class="blob blob--3" />
    <view class="stars" />

    <mb-navbar title="" bgColor="transparent" textColor="#ffffff" />

    <view class="login-content">
      <view class="brand">
        <view class="brand-mark">
          <view class="brand-mark__sheen" />
          <image v-if="loginLogo" class="brand-mark__image" :src="loginLogo" mode="aspectFit" />
          <view v-else class="brand-mark__bag">
            <view class="brand-mark__bag-body" />
            <view class="brand-mark__bag-handle" />
          </view>
        </view>
        <text class="brand-title">{{ brandName }}</text>
        <text class="brand-subtitle">{{ brandSubtitle }}</text>
      </view>

      <!-- #ifdef MP-WEIXIN -->
      <view v-if="wechatBindStep === 'none'" class="form-section">
        <button
          class="btn-wechat"
          :class="{ 'btn-wechat--loading': loading }"
          @tap="handleWechatMiniLogin"
        >
          <text class="btn-wechat-label">微信一键登录</text>
        </button>
      </view>

      <view v-else-if="wechatBindStep === 'bind'" class="form-section">
        <text class="bind-hint">请绑定手机号以完成登录</text>
        <view v-if="wechatNeedUserInfo" class="profile-card">
          <button
            class="avatar-picker"
            open-type="chooseAvatar"
            @chooseavatar="onChooseAvatar"
            :disabled="wechatAvatarUploading"
          >
            <image
              v-if="wechatAvatarPreview || wechatAvatar"
              class="avatar-image"
              :src="wechatAvatarPreview || wechatAvatar"
              mode="aspectFill"
            />
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
          <input
            v-model="phone"
            class="line-input"
            type="number"
            maxlength="11"
            placeholder="请输入手机号"
            placeholder-class="placeholder"
          />
        </view>
        <view v-if="!wechatForcePhone" class="input-line">
          <text class="input-label">验证码</text>
          <input
            v-model="smsCode"
            class="line-input"
            type="number"
            maxlength="6"
            placeholder=""
            placeholder-class="placeholder"
          />
          <text
            class="sms-btn"
            :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode('bind_mobile')"
          >
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <text v-if="smsExpireText" class="sms-expire-hint">{{ smsExpireText }}</text>
        <view
          v-if="!wechatForcePhone"
          class="primary-btn"
          :class="{ 'primary-btn--loading': loading }"
          @tap="handleBindMobile"
        >
          <text class="primary-btn-text">{{ loading ? '绑定中...' : '绑 定' }}</text>
        </view>
      </view>

      <view v-else-if="wechatBindStep === 'profile'" class="form-section">
        <text class="bind-hint">请完善头像昵称以完成登录</text>
        <view class="profile-card">
          <button
            class="avatar-picker"
            open-type="chooseAvatar"
            @chooseavatar="onChooseAvatar"
            :disabled="wechatAvatarUploading"
          >
            <image
              v-if="wechatAvatarPreview || wechatAvatar"
              class="avatar-image"
              :src="wechatAvatarPreview || wechatAvatar"
              mode="aspectFill"
            />
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
        <view
          class="primary-btn"
          :class="{ 'primary-btn--loading': loading }"
          @tap="handleBindUserInfo"
        >
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
          <input
            v-model="phone"
            class="line-input"
            type="number"
            maxlength="11"
            placeholder="请输入手机号"
            placeholder-class="placeholder"
          />
        </view>
        <view class="input-line">
          <text class="input-label">验证码</text>
          <input
            v-model="smsCode"
            class="line-input"
            type="number"
            maxlength="6"
            placeholder=""
            placeholder-class="placeholder"
          />
          <text
            class="sms-btn"
            :class="{ 'sms-btn--off': countdown > 0 }"
            @tap="handleSendCode('wechat_official_bind')"
          >
            {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
          </text>
        </view>
        <text v-if="smsExpireText" class="sms-expire-hint">{{ smsExpireText }}</text>
        <view
          class="primary-btn"
          :class="{ 'primary-btn--loading': loading }"
          @tap="handleOfficialBindMobile"
        >
          <text class="primary-btn-text">{{ loading ? '绑定中...' : '绑 定' }}</text>
        </view>
      </view>

      <view v-else class="form-section auth-panel">
        <view class="login-tabs">
          <view
            class="login-tabs__item"
            :class="{ 'login-tabs__item--active': loginMode === 'sms' }"
            @tap="loginMode = 'sms'"
          >
            <text class="login-tabs__text">手机号登录</text>
          </view>
          <view
            class="login-tabs__item"
            :class="{ 'login-tabs__item--active': loginMode === 'password' }"
            @tap="loginMode = 'password'"
          >
            <text class="login-tabs__text">账号密码</text>
          </view>
        </view>

        <view v-if="loginMode === 'sms'" class="login-fields">
          <view class="input-line input-line--icon">
            <view class="field-icon field-icon--phone" />
            <input
              v-model="phone"
              class="line-input"
              type="number"
              maxlength="11"
              placeholder="请输入手机号"
              placeholder-class="placeholder"
            />
          </view>
          <view class="input-line input-line--icon">
            <view class="field-icon field-icon--code" />
            <input
              v-model="smsCode"
              class="line-input"
              type="number"
              maxlength="6"
              placeholder="验证码"
              placeholder-class="placeholder"
            />
            <text
              class="sms-btn"
              :class="{ 'sms-btn--off': countdown > 0 }"
              @tap="handleSendCode('login')"
            >
              {{ countdown > 0 ? `${countdown}s` : '获取验证码' }}
            </text>
          </view>
          <text v-if="smsExpireText" class="sms-expire-hint">{{ smsExpireText }}</text>
        </view>

        <view v-else class="login-fields">
          <view class="input-line input-line--icon">
            <view class="field-icon field-icon--user" />
            <input
              v-model="account"
              class="line-input line-input--full"
              type="text"
              placeholder="手机号 / 用户名"
              placeholder-class="placeholder"
            />
          </view>
          <view class="input-line input-line--icon">
            <view class="field-icon field-icon--lock" />
            <input
              v-model="password"
              class="line-input line-input--full"
              :type="showPassword ? 'text' : 'password'"
              placeholder="密码"
              placeholder-class="placeholder"
            />
            <view class="eye-toggle" @tap="showPassword = !showPassword">
              <view class="eye-shape" />
              <view v-if="!showPassword" class="eye-slash" />
            </view>
          </view>
        </view>

        <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleLogin">
          <text class="primary-btn-text">{{ loading ? '登录中...' : '登录' }}</text>
        </view>

        <view class="secondary-actions">
          <text class="secondary-action" @tap="handleForgotPassword">忘记密码</text>
        </view>

        <view class="methods-divider">
          <view class="divider-line" />
          <text class="divider-label">其他方式登录</text>
          <view class="divider-line" />
        </view>

        <!-- #ifdef MP-WEIXIN -->
        <view class="wechat-entry" @tap="handleWechatMiniLogin">
          <view class="wechat-entry-circle">
            <view class="wechat-entry__icon">
              <view class="wechat-entry__bubble" />
              <view class="wechat-entry__bubble wechat-entry__bubble--r" />
            </view>
          </view>
          <text class="wechat-entry__text">微信一键登录</text>
        </view>
        <!-- #endif -->
        <!-- #ifndef MP-WEIXIN -->
        <view v-if="isWechatH5" class="wechat-entry" @tap="handleWechatOfficialLogin">
          <view class="wechat-entry-circle">
            <view class="wechat-entry__icon">
              <view class="wechat-entry__bubble" />
              <view class="wechat-entry__bubble wechat-entry__bubble--r" />
            </view>
          </view>
          <text class="wechat-entry__text">微信一键登录</text>
        </view>
        <!-- #endif -->
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
          <text class="agree-link" @tap.stop="openAgreement('service')">用户协议</text>
          <text class="agree-sep">与</text>
          <text class="agree-link" @tap.stop="openAgreement('privacy')">隐私政策</text>
        </view>
      </view>
    </view>
  </view>
</template>

<script setup>
import { useDecorateStore } from '@/store/decorate'
import { computed, ref, onMounted } from 'vue'
import { onLoad, onUnload } from '@dcloudio/uni-app'
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
  uploadWechatBindAvatar,
  getWechatOfficialOauthUrl,
  wechatOfficialLogin,
  wechatOfficialBindMobile
} from '@/api/user/auth'
import { getUploadedAssetValue, getUploadedPreviewUrl } from '@/api/upload'
const decorateStore = useDecorateStore()

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
const smsExpireCountdown = ref(0)
const wechatBindStep = ref('none')
const wechatBindToken = ref('')
const wechatForcePhone = ref(false)
const wechatNeedUserInfo = ref(false)
const wechatNickname = ref('')
const wechatAvatar = ref('')
const wechatAvatarPreview = ref('')
const wechatAvatarUploading = ref(false)
const isWechatH5 = ref(false)
const redirectUrl = ref('')

let countdownTimer = null
let smsExpireTimer = null

const brandName = computed(
  () =>
    appStore.siteConfig?.client_auth_name ||
    appStore.siteConfig?.client_site_name ||
    appStore.siteConfig?.site_name ||
    'MallBase'
)
const brandSubtitle = computed(
  () => appStore.siteConfig?.site_slogan || '欢迎回来，继续你的品质购物体验'
)
const loginLogo = computed(
  () =>
    appStore.siteConfig?.client_auth_logo ||
    appStore.siteConfig?.client_logo ||
    '/static/logo-light.png'
)

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

function handleForgotPassword() {
  uni.showToast({ title: '请先登录后再修改密码', icon: 'none' })
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
  if (wechatAvatarUploading.value) {
    uni.showToast({ title: '头像上传中，请稍后', icon: 'none' })
    return false
  }
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
    nickname: wechatNickname.value.trim()
  }
}

async function handleSendCode(scene = 'login') {
  if (countdown.value > 0) return
  if (!validatePhone()) return
  try {
    const data = await sendSmsCode(phone.value, scene)
    startCountdown()
    startSmsExpireCountdown(data?.code_ttl || 300)
    uni.showToast({ title: '验证码已发送', icon: 'none' })
  } catch (_) {
    /* request.js shows toast */
  }
}

async function onLoginSuccess(data) {
  if (!data?.access_token || !data?.refresh_token) {
    uni.showToast({ title: '登录结果异常，请重试', icon: 'none' })
    throw new Error('登录结果缺少令牌')
  }
  userStore.setToken(data.access_token, data.refresh_token)
  await userStore.fetchUserInfo()
  await decorateStore.fetchMyThemePreference({ force: true })
  if (redirectUrl.value) {
    const url = redirectUrl.value
    uni.redirectTo({
      url,
      fail() {
        uni.switchTab({ url: url.split('?')[0] })
      }
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
  } catch (_) {
    /* handled */
  } finally {
    loading.value = false
  }
}

function handleLogin() {
  if (loginMode.value === 'sms') {
    handleSmsLogin()
  } else {
    handlePasswordLogin()
  }
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
  } catch (_) {
    /* handled */
  } finally {
    loading.value = false
  }
}

// #ifdef MP-WEIXIN
function loginWithWechatMini() {
  return new Promise((resolve, reject) => {
    uni.login({
      provider: 'weixin',
      success: resolve,
      fail: reject
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
      wechatAvatar.value = ''
      wechatAvatarPreview.value = ''
      wechatAvatarUploading.value = false
      wechatBindStep.value = data.need_mobile ? 'bind' : 'profile'
    } else {
      await onLoginSuccess(data)
    }
  } catch (_) {
    /* request.js shows toast */
  } finally {
    loading.value = false
  }
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
    await onLoginSuccess(
      await wechatBindByPhoneCode(wechatBindToken.value, e.detail.code, getWechatProfilePayload())
    )
  } catch (_) {
    /* handled */
  } finally {
    loading.value = false
  }
}

async function onChooseAvatar(e) {
  const avatarUrl = e.detail?.avatarUrl || ''
  if (!avatarUrl) return
  if (!wechatBindToken.value) {
    uni.showToast({ title: '登录态已过期，请重新登录', icon: 'none' })
    wechatBindStep.value = 'none'
    return
  }

  wechatAvatarPreview.value = avatarUrl
  wechatAvatar.value = ''
  wechatAvatarUploading.value = true
  try {
    const uploadRes = await uploadWechatBindAvatar(wechatBindToken.value, avatarUrl)
    const submitValue = getUploadedAssetValue(uploadRes)
    if (!submitValue) {
      throw new Error('上传结果缺少头像路径')
    }
    wechatAvatar.value = String(submitValue)
    wechatAvatarPreview.value = getUploadedPreviewUrl(uploadRes, avatarUrl)
  } catch (_) {
    wechatAvatarPreview.value = ''
    wechatAvatar.value = ''
  } finally {
    wechatAvatarUploading.value = false
  }
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
    await onLoginSuccess(
      await wechatBindMobile(
        wechatBindToken.value,
        phone.value,
        smsCode.value,
        getWechatProfilePayload()
      )
    )
  } catch (_) {
    /* handled */
  } finally {
    loading.value = false
  }
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
  } catch (_) {
    /* handled */
  } finally {
    loading.value = false
  }
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
    await onLoginSuccess(
      await wechatOfficialBindMobile(wechatBindToken.value, phone.value, smsCode.value)
    )
  } catch (_) {
    /* handled */
  } finally {
    loading.value = false
  }
}

// #ifdef H5
function getWechatOfficialRedirectUri() {
  const url = new URL(window.location.href)
  url.searchParams.delete('code')
  url.searchParams.delete('state')
  return url.toString()
}

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
        uni.showToast({ title: '请绑定手机号以完成登录', icon: 'none' })
      } else {
        return onLoginSuccess(data)
      }
    })
    .catch(() => {})
    .finally(() => {
      loading.value = false
    })
}
// #endif

async function handleWechatOfficialLogin() {
  if (!checkAgreement()) return
  // #ifdef H5
  if (isWechatH5.value) {
    loading.value = true
    try {
      const redirectUri = getWechatOfficialRedirectUri()
      const data = await getWechatOfficialOauthUrl(redirectUri, 'login')
      if (data.url) {
        window.location.href = data.url
      }
    } catch (_) {
      /* request.js shows toast */
    } finally {
      loading.value = false
    }
  }
  // #endif
}

function openAgreement(type) {
  uni.navigateTo({
    url: `/pages-sub/user/agreement?type=${type === 'privacy' ? 'privacy' : 'service'}`
  })
}
</script>

<style lang="scss" scoped>
$glass-primary: #0d50d5;
$glass-accent: #4fe3d7;

.login-page {
  position: relative;
  min-height: 100vh;
  display: flex;
  background: #0b1a4d;
  overflow: hidden;

  /* #ifdef MP-WEIXIN */
  /* 小程序顶部胶囊会占空间，整页 column 居中以避免内容堆顶 */
  flex-direction: column;
  justify-content: center;
  /* #endif */
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
  background: radial-gradient(
    circle at center,
    rgba(255, 92, 182, 0.55) 0%,
    rgba(255, 92, 182, 0) 70%
  );
}

.blob--2 {
  top: 80rpx;
  right: -240rpx;
  width: 760rpx;
  height: 760rpx;
  background: radial-gradient(
    circle at center,
    rgba(79, 227, 215, 0.55) 0%,
    rgba(79, 227, 215, 0) 70%
  );
}

.blob--3 {
  bottom: 200rpx;
  right: -120rpx;
  width: 840rpx;
  height: 840rpx;
  background: radial-gradient(
    circle at center,
    rgba(124, 92, 255, 0.32) 0%,
    rgba(124, 92, 255, 0) 70%
  );
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

.login-content {
  width: 100%;
  max-width: 750rpx;
  display: flex;
  flex-direction: column;
  position: relative;
  z-index: 1;
  padding: 24rpx 48rpx calc(48rpx + env(safe-area-inset-bottom));

  /* #ifdef MP-WEIXIN */
  /* 小程序下整体居中，去掉顶部多余 padding；mb-navbar 仍按其自身占位 */
  padding-top: 0;
  /* #endif */
}

/* #ifdef MP-WEIXIN */
.brand {
  margin-top: 0 !important;
}
/* #endif */

// ---- Brand ----
.brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  margin-top: 32rpx;
  margin-bottom: 56rpx;
}

.brand-mark {
  position: relative;
  width: 168rpx;
  height: 168rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 32rpx;
  border-radius: 48rpx;
  background: rgba(255, 255, 255, 0.14);
  border: 1rpx solid rgba(255, 255, 255, 0.3);
  box-shadow: 0 16rpx 40rpx rgba(0, 0, 0, 0.25);
  overflow: hidden;
  /* #ifdef H5 */
  backdrop-filter: blur(30px);
  -webkit-backdrop-filter: blur(30px);
  /* #endif */
}

.brand-mark__sheen {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 50%;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.28) 0%, rgba(255, 255, 255, 0) 100%);
  pointer-events: none;
}

.brand-mark__image {
  position: relative;
  width: 168rpx;
  height: 168rpx;
  border-radius: 48rpx;
}

.brand-mark__bag {
  position: relative;
  width: 76rpx;
  height: 84rpx;
  z-index: 1;
}

.brand-mark__bag-body {
  position: absolute;
  bottom: 0;
  left: 4rpx;
  right: 4rpx;
  height: 60rpx;
  border: 3rpx solid #ffffff;
  border-radius: 10rpx 10rpx 16rpx 16rpx;
  background: rgba(255, 255, 255, 0.08);
}

.brand-mark__bag-handle {
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
  max-width: 100%;
  font-size: 56rpx;
  font-weight: 700;
  letter-spacing: -1rpx;
  color: #ffffff;
  line-height: 1.15;
  word-break: break-word;
  text-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.18);
}

.brand-subtitle {
  margin-top: 16rpx;
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.65);
  letter-spacing: 0;
  line-height: 1.45;
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

.auth-panel {
  margin-bottom: 24rpx;
}

// ---- Tabs ----
.login-tabs {
  position: relative;
  display: flex;
  align-items: center;
  padding: 8rpx;
  background: rgba(255, 255, 255, 0.06);
  border: 1rpx solid rgba(255, 255, 255, 0.12);
  border-radius: 999rpx;
}

.login-tabs__item {
  flex: 1;
  height: 80rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 999rpx;
  color: rgba(255, 255, 255, 0.6);
  transition:
    background 0.2s,
    color 0.2s;
}

.login-tabs__item--active {
  background: rgba(255, 255, 255, 0.92);
  color: $glass-primary;
}

.login-tabs__text {
  font-size: 26rpx;
  font-weight: 600;
  letter-spacing: 0;
}

.login-fields {
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}

// ---- Inputs ----
.input-line {
  position: relative;
  display: flex;
  align-items: center;
  min-height: 104rpx;
  border: 1rpx solid rgba(255, 255, 255, 0.2);
  border-radius: 28rpx;
  background: rgba(255, 255, 255, 0.1);
  padding: 0 28rpx;
  transition:
    border-color 0.2s,
    background-color 0.2s,
    box-shadow 0.2s;

  &:focus-within {
    border-color: rgba(255, 255, 255, 0.5);
    background: rgba(255, 255, 255, 0.16);
    box-shadow: 0 0 0 4rpx rgba(255, 255, 255, 0.08);
  }
}

.area-code {
  font-size: 28rpx;
  font-weight: 500;
  color: #ffffff;
  flex-shrink: 0;
}

.chevron {
  font-size: 20rpx;
  color: rgba(255, 255, 255, 0.6);
  margin-left: 6rpx;
  flex-shrink: 0;
}

.input-label {
  font-size: 28rpx;
  color: rgba(255, 255, 255, 0.6);
  flex-shrink: 0;
}

.input-line--icon {
  gap: 20rpx;
}

.field-icon {
  position: relative;
  flex-shrink: 0;
  width: 36rpx;
  height: 36rpx;
  color: rgba(255, 255, 255, 0.7);
}

.field-icon--phone::before {
  content: '';
  position: absolute;
  top: 2rpx;
  left: 8rpx;
  right: 8rpx;
  bottom: 2rpx;
  border: 3rpx solid currentColor;
  border-radius: 8rpx;
}

.field-icon--phone::after {
  content: '';
  position: absolute;
  left: 15rpx;
  bottom: 5rpx;
  width: 6rpx;
  height: 4rpx;
  border-radius: 999rpx;
  background: currentColor;
}

.field-icon--user::before {
  content: '';
  position: absolute;
  top: 2rpx;
  left: 9rpx;
  width: 18rpx;
  height: 18rpx;
  border: 3rpx solid currentColor;
  border-radius: 50%;
}

.field-icon--user::after {
  content: '';
  position: absolute;
  left: 6rpx;
  bottom: 4rpx;
  width: 24rpx;
  height: 12rpx;
  border: 3rpx solid currentColor;
  border-top: none;
  border-radius: 0 0 12rpx 12rpx;
}

.field-icon--lock::before {
  content: '';
  position: absolute;
  left: 9rpx;
  top: 14rpx;
  width: 18rpx;
  height: 14rpx;
  border: 3rpx solid currentColor;
  border-radius: 4rpx;
}

.field-icon--lock::after {
  content: '';
  position: absolute;
  left: 13rpx;
  top: 6rpx;
  width: 10rpx;
  height: 10rpx;
  border: 3rpx solid currentColor;
  border-bottom: none;
  border-radius: 8rpx 8rpx 0 0;
}

.field-icon--code::before {
  content: '';
  position: absolute;
  top: 6rpx;
  left: 4rpx;
  right: 4rpx;
  bottom: 6rpx;
  border: 3rpx solid currentColor;
  border-radius: 6rpx;
}

.field-icon--code::after {
  content: '';
  position: absolute;
  left: 12rpx;
  top: 14rpx;
  width: 12rpx;
  height: 3rpx;
  background: currentColor;
  box-shadow: 0 -7rpx 0 currentColor;
}

.line-input {
  flex: 1;
  min-width: 0;
  height: 100%;
  font-size: 30rpx;
  color: #ffffff;
  margin-left: 0;
}

.line-input--full {
  margin-left: 0;
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
  letter-spacing: 0;
  padding-left: 20rpx;
  border-left: 1rpx solid rgba(255, 255, 255, 0.18);
  margin-left: 12rpx;
}

.sms-btn--off {
  color: rgba(255, 255, 255, 0.35);
}

.sms-expire-hint {
  display: block;
  margin-top: -8rpx;
  padding: 0 12rpx;
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.58);
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
  height: 96rpx;
  border-radius: 999rpx;
  background: rgba(255, 255, 255, 0.96);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 16rpx;
  box-shadow:
    0 0 32rpx rgba(255, 255, 255, 0.25),
    0 12rpx 32rpx rgba(0, 0, 0, 0.18);
  overflow: hidden;
  transition:
    transform 0.15s,
    opacity 0.15s,
    box-shadow 0.15s;

  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: 12rpx;
    right: 12rpx;
    height: 1rpx;
    background: linear-gradient(
      90deg,
      transparent 0%,
      rgba(255, 255, 255, 0.95) 50%,
      transparent 100%
    );
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

// ---- WeChat one-tap (full-width) ----
.btn-wechat {
  width: 100%;
  height: 96rpx;
  border-radius: 999rpx;
  background: rgba(255, 255, 255, 0.14);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14rpx;
  border: 1rpx solid rgba(255, 255, 255, 0.28);
  padding: 0;
  margin: 0;
  /* #ifdef H5 */
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  /* #endif */

  &::after {
    display: none;
  }

  &:active {
    opacity: 0.85;
  }
}

.btn-wechat--loading {
  opacity: 0.7;
  pointer-events: none;
}

.btn-wechat-label {
  font-size: 28rpx;
  font-weight: 600;
  color: #ffffff;
  letter-spacing: 0;
}

// ---- Secondary actions ----
.secondary-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4rpx 12rpx 0;
}

.secondary-action {
  font-size: 24rpx;
  color: rgba(255, 255, 255, 0.7);
}

.secondary-divider {
  display: none;
}

// ---- Other-method divider ----
.methods-divider {
  display: flex;
  align-items: center;
  gap: 20rpx;
  margin: 32rpx 0 8rpx;
}

.divider-line {
  flex: 1;
  height: 1rpx;
  background: rgba(255, 255, 255, 0.14);
}

.divider-label {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.45);
  letter-spacing: 4rpx;
  white-space: nowrap;
}

// ---- WeChat circular entry ----
.wechat-entry {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14rpx;
  height: auto;
  padding: 8rpx 0;
  border: none;
  background: transparent;
  margin-top: 16rpx;
}

.wechat-entry__icon {
  position: relative;
  width: 44rpx;
  height: 36rpx;
  margin: 12rpx 0;
}

.wechat-entry__bubble {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 28rpx;
  height: 22rpx;
  border-radius: 999rpx;
  background: #07c160;

  &::after {
    content: '';
    position: absolute;
    bottom: -4rpx;
    left: 6rpx;
    width: 10rpx;
    height: 8rpx;
    background: #07c160;
    clip-path: polygon(0 0, 100% 0, 35% 100%);
  }
}

.wechat-entry__bubble--r {
  left: auto;
  right: 0;
  bottom: 8rpx;
  width: 22rpx;
  height: 18rpx;

  &::after {
    left: auto;
    right: 4rpx;
    clip-path: polygon(0 0, 100% 0, 65% 100%);
  }
}

.wechat-entry-circle {
  width: 96rpx;
  height: 96rpx;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.12);
  border: 1rpx solid rgba(255, 255, 255, 0.28);
  display: flex;
  align-items: center;
  justify-content: center;
  /* #ifdef H5 */
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  /* #endif */
}

.wechat-entry__text {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.7);
  font-weight: 500;
  letter-spacing: 1rpx;
}

// ---- WeChat bind helpers ----
.bind-hint {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.7);
  text-align: center;
  margin-bottom: 16rpx;
}

.profile-card {
  display: flex;
  align-items: center;
  gap: 24rpx;
  margin-bottom: 12rpx;
  padding: 16rpx 0;
  border-bottom: 1rpx solid rgba(255, 255, 255, 0.16);
}

.avatar-picker {
  width: 104rpx;
  height: 104rpx;
  border-radius: 999rpx;
  background: rgba(255, 255, 255, 0.12);
  border: 2rpx solid rgba(255, 255, 255, 0.28);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  margin: 0;
  overflow: hidden;

  &::after {
    display: none;
  }
}

.avatar-image {
  width: 104rpx;
  height: 104rpx;
}

.avatar-plus {
  font-size: 48rpx;
  color: rgba(255, 255, 255, 0.65);
  line-height: 1;
}

.nickname-input {
  flex: 1;
  height: 84rpx;
  font-size: 30rpx;
  color: #ffffff;
}

// ---- Agreement ----
.agreement {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-top: 32rpx;
  padding: 0 24rpx;
}

.agree-toggle {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-bottom: 8rpx;
}

.agree-box {
  width: 32rpx;
  height: 32rpx;
  border: 1rpx solid rgba(255, 255, 255, 0.4);
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.06);
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}

.agree-box--checked {
  background: rgba(255, 255, 255, 0.92);
  border-color: rgba(255, 255, 255, 0.92);
}

.agree-mark {
  font-size: 22rpx;
  line-height: 1;
  color: $glass-primary;
  font-weight: 700;
}

.agree-text {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.55);
}

.agree-links {
  display: flex;
  align-items: center;
  gap: 8rpx;
  flex-wrap: wrap;
  justify-content: center;
}

.agree-link {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.95);
  font-weight: 500;
  text-decoration: underline;
  text-decoration-color: rgba(255, 255, 255, 0.3);
  text-underline-offset: 4rpx;
}

.agree-sep {
  font-size: 22rpx;
  color: rgba(255, 255, 255, 0.55);
}
</style>
