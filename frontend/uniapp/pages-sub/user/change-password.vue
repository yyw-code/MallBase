<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="修改密码" />

    <scroll-view class="content" scroll-y>
      <view class="security-panel">
        <view class="security-panel__icon">
          <view class="shield-icon" />
        </view>
        <view class="security-panel__body">
          <text class="security-panel__title">保护账号安全</text>
          <text class="security-panel__desc">修改后请使用新密码重新登录相关设备。</text>
        </view>
      </view>

      <view class="form-section">
        <view class="form-item">
          <text class="form-label">当前密码</text>
          <view class="input-box">
            <input
              v-model="oldPassword"
              class="form-input"
              :type="showOld ? 'text' : 'password'"
              placeholder="请输入当前密码"
              placeholder-class="placeholder"
            />
            <view
              class="eye-button"
              :class="{ 'eye-button--active': showOld }"
              @tap="showOld = !showOld"
            >
              <view class="eye-icon" />
              <view v-if="!showOld" class="eye-icon__slash" />
            </view>
          </view>
        </view>

        <view class="form-item">
          <text class="form-label">新密码</text>
          <view class="input-box">
            <input
              v-model="newPassword"
              class="form-input"
              :type="showNew ? 'text' : 'password'"
              placeholder="至少 6 位"
              placeholder-class="placeholder"
            />
            <view
              class="eye-button"
              :class="{ 'eye-button--active': showNew }"
              @tap="showNew = !showNew"
            >
              <view class="eye-icon" />
              <view v-if="!showNew" class="eye-icon__slash" />
            </view>
          </view>
        </view>

        <view class="form-item">
          <text class="form-label">确认新密码</text>
          <view class="input-box">
            <input
              v-model="confirmPassword"
              class="form-input"
              :type="showConfirm ? 'text' : 'password'"
              placeholder="请再次输入新密码"
              placeholder-class="placeholder"
            />
            <view
              class="eye-button"
              :class="{ 'eye-button--active': showConfirm }"
              @tap="showConfirm = !showConfirm"
            >
              <view class="eye-icon" />
              <view v-if="!showConfirm" class="eye-icon__slash" />
            </view>
          </view>
        </view>
      </view>

      <view
        class="primary-btn"
        :class="{ 'primary-btn--loading': loading }"
        @tap="handleSubmit"
      >
        <text class="primary-btn-text">{{ loading ? '提交中...' : '确认修改' }}</text>
      </view>

      <mb-copyright-footer />
      <view class="bottom-spacer" />
    </scroll-view>
      <mb-floating-action />
</view>
</template>

<script setup>
import { ref } from 'vue'
import { updateMyPassword } from '@/api/user/user'
import { useDecorateStore } from '@/store/decorate'

const decorateStore = useDecorateStore()

const oldPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const showOld = ref(false)
const showNew = ref(false)
const showConfirm = ref(false)
const loading = ref(false)

function validate() {
  if (!oldPassword.value) {
    uni.showToast({ title: '请输入当前密码', icon: 'none' })
    return false
  }
  if (!newPassword.value || newPassword.value.length < 6) {
    uni.showToast({ title: '新密码至少 6 位', icon: 'none' })
    return false
  }
  if (newPassword.value !== confirmPassword.value) {
    uni.showToast({ title: '两次输入的密码不一致', icon: 'none' })
    return false
  }
  if (oldPassword.value === newPassword.value) {
    uni.showToast({ title: '新密码不能与当前密码相同', icon: 'none' })
    return false
  }
  return true
}

async function handleSubmit() {
  if (loading.value) return
  if (!validate()) return

  loading.value = true
  try {
    await updateMyPassword({
      old_password: oldPassword.value,
      password: newPassword.value,
    })
    uni.showToast({ title: '密码修改成功', icon: 'success' })
    setTimeout(() => {
      uni.navigateBack()
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
  background: var(--color-bg-secondary, #faf8ff);
}

.content {
  box-sizing: border-box;
  height: calc(100vh - 96rpx);
  padding: $mb-spacing-md $mb-spacing-page 0;
}

.security-panel {
  display: flex;
  align-items: center;
  gap: 24rpx;
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.security-panel__icon {
  width: 76rpx;
  height: 76rpx;
  border-radius: $mb-radius-lg;
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.shield-icon {
  position: relative;
  width: 34rpx;
  height: 40rpx;
  border: 4rpx solid var(--color-primary-on-page, var(--color-primary, #0d50d5));
  border-top-left-radius: 18rpx;
  border-top-right-radius: 18rpx;
  border-bottom-left-radius: 22rpx;
  border-bottom-right-radius: 22rpx;

  &::after {
    content: '';
    position: absolute;
    top: 11rpx;
    left: 11rpx;
    width: 8rpx;
    height: 14rpx;
    border-right: 4rpx solid var(--color-primary-on-page, var(--color-primary, #0d50d5));
    border-bottom: 4rpx solid var(--color-primary-on-page, var(--color-primary, #0d50d5));
    transform: rotate(45deg);
  }
}

.security-panel__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}

.security-panel__title {
  font-size: $mb-font-lg;
  font-weight: 700;
  line-height: 1.3;
  color: var(--color-text-title-on-bg, var(--color-text-title, #191b23));
}

.security-panel__desc {
  font-size: $mb-font-sm;
  line-height: 1.5;
  color: var(--color-text-secondary-on-bg, var(--color-text-secondary, #434654));
}

.form-section {
  display: flex;
  flex-direction: column;
  gap: 28rpx;
  margin-top: $mb-spacing-lg;
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.form-item {
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}

.form-label {
  padding-left: 4rpx;
  font-size: $mb-font-md;
  font-weight: 600;
  line-height: 1.4;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
}

.input-box {
  display: flex;
  align-items: center;
  min-height: 104rpx;
  gap: 20rpx;
  padding: 0 30rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-full;
  box-shadow: inset 0 0 0 1rpx var(--color-bg-secondary, #faf8ff);
}

.form-input {
  flex: 1;
  min-width: 0;
  height: 104rpx;
  font-size: $mb-font-md;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
}

.placeholder {
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
}

.eye-button {
  position: relative;
  width: 56rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.eye-button--active .eye-icon {
  border-color: var(--color-primary-on-page, var(--color-primary, #0d50d5));

  &::after {
    background: var(--color-primary-on-page, var(--color-primary, #0d50d5));
  }
}

.eye-icon {
  position: relative;
  width: 36rpx;
  height: 24rpx;
  border: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  border-radius: 50%;

  &::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 10rpx;
    height: 10rpx;
    border-radius: $mb-radius-full;
    background: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
    transform: translate(-50%, -50%);
  }
}

.eye-icon__slash {
  position: absolute;
  top: 10rpx;
  left: 50%;
  width: 3rpx;
  height: 36rpx;
  background: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  transform: translateX(-50%) rotate(45deg);
}

.primary-btn {
  height: 104rpx;
  margin-top: $mb-spacing-xl;
  border-radius: $mb-radius-full;
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.15s, transform 0.15s;

  &:active {
    opacity: 0.85;
    transform: scale(0.98);
  }
}

.primary-btn--loading {
  opacity: 0.65;
  pointer-events: none;
}

.primary-btn-text {
  font-size: $mb-font-lg;
  font-weight: 600;
  line-height: 1.3;
  color: var(--color-text-on-primary, #ffffff);
}

.bottom-spacer {
  height: calc(120rpx + env(safe-area-inset-bottom));
}
</style>
