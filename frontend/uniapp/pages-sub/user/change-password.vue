<template>
  <view class="page">
    <mb-navbar title="修改密码" />

    <view class="page-content">
      <!-- Form -->
      <view class="form-section">
        <view class="form-group">
          <text class="form-label">当前密码</text>
          <view class="input-pill">
            <input
              v-model="oldPassword"
              class="pill-input"
              :type="showOld ? 'text' : 'password'"
              placeholder="请输入当前密码"
              placeholder-class="placeholder"
            />
            <view class="eye-toggle" @tap="showOld = !showOld">
              <view class="eye-shape" />
              <view v-if="!showOld" class="eye-slash" />
            </view>
          </view>
        </view>

        <view class="form-group">
          <text class="form-label">新密码</text>
          <view class="input-pill">
            <input
              v-model="newPassword"
              class="pill-input"
              :type="showNew ? 'text' : 'password'"
              placeholder="请输入新密码（至少 6 位）"
              placeholder-class="placeholder"
            />
            <view class="eye-toggle" @tap="showNew = !showNew">
              <view class="eye-shape" />
              <view v-if="!showNew" class="eye-slash" />
            </view>
          </view>
        </view>

        <view class="form-group">
          <text class="form-label">确认新密码</text>
          <view class="input-pill">
            <input
              v-model="confirmPassword"
              class="pill-input"
              :type="showConfirm ? 'text' : 'password'"
              placeholder="请再次输入新密码"
              placeholder-class="placeholder"
            />
            <view class="eye-toggle" @tap="showConfirm = !showConfirm">
              <view class="eye-shape" />
              <view v-if="!showConfirm" class="eye-slash" />
            </view>
          </view>
        </view>
      </view>

      <!-- Submit button -->
      <view
        class="primary-btn"
        :class="{ 'primary-btn--loading': loading }"
        @tap="handleSubmit"
      >
        <text class="primary-btn-text">{{ loading ? '提交中...' : '确认修改' }}</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref } from 'vue'
import { updateMyPassword } from '@/api/user/user'

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
  background: #ffffff;
}

.page-content {
  padding: 0 48rpx;
}

// ---- Form ----
.form-section {
  padding-top: 48rpx;
  display: flex;
  flex-direction: column;
  gap: 36rpx;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.form-label {
  font-size: 26rpx;
  font-weight: 500;
  color: $mb-color-text-secondary;
  padding-left: 8rpx;
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

.pill-input {
  flex: 1;
  font-size: 30rpx;
  color: $mb-color-text;
  height: 100%;
}

.placeholder {
  color: $mb-color-border-light;
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

// ---- Button ----
.primary-btn {
  height: 100rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-text;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 48rpx;
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
  letter-spacing: 0.1em;
}
</style>
