<template>
  <view class="page">
    <view class="page-content">
      <!-- Avatar -->
      <view class="avatar-section" @tap="chooseAvatar">
        <view class="avatar-wrapper">
          <image
            v-if="form.avatar"
            class="avatar"
            :src="form.avatar"
            mode="aspectFill"
          />
          <view v-else class="avatar avatar--placeholder">
            <view class="avatar-icon">
              <view class="avatar-icon__head" />
              <view class="avatar-icon__body" />
            </view>
          </view>
          <view class="avatar-badge">
            <text class="avatar-badge-text">换</text>
          </view>
        </view>
        <text class="avatar-hint">更换头像</text>
      </view>

      <!-- Form -->
      <view class="form-section">
        <!-- Nickname -->
        <view class="form-group">
          <text class="form-label">昵称</text>
          <view class="input-pill">
            <input
              v-model="form.nickname"
              class="pill-input"
              type="text"
              maxlength="20"
              placeholder="请输入昵称"
              placeholder-class="placeholder"
            />
          </view>
        </view>

        <!-- Gender -->
        <view class="form-group">
          <text class="form-label">性别</text>
          <view class="gender-row">
            <view
              v-for="item in genderOptions"
              :key="item.value"
              class="gender-chip"
              :class="{ 'gender-chip--active': form.gender === item.value }"
              @tap="form.gender = item.value"
            >
              <text class="gender-chip-text">{{ item.label }}</text>
            </view>
          </view>
        </view>

        <!-- Birthday -->
        <view class="form-group">
          <text class="form-label">生日</text>
          <picker mode="date" :value="form.birthday" :end="today" @change="onBirthdayChange">
            <view class="input-pill input-pill--picker">
              <text :class="['pill-value', { 'pill-value--empty': !form.birthday }]">
                {{ form.birthday || '请选择生日' }}
              </text>
              <view class="picker-arrow" />
            </view>
          </picker>
        </view>

        <!-- Bio -->
        <view class="form-group">
          <view class="label-row">
            <text class="form-label">个性签名</text>
            <text class="char-count">{{ form.bio.length }}/100</text>
          </view>
          <view class="textarea-wrapper">
            <textarea
              v-model="form.bio"
              class="bio-textarea"
              maxlength="100"
              placeholder="介绍一下自己..."
              placeholder-class="placeholder"
              :auto-height="false"
            />
          </view>
        </view>
      </view>

      <!-- Save button -->
      <view
        class="primary-btn"
        :class="{ 'primary-btn--loading': loading }"
        @tap="handleSave"
      >
        <text class="primary-btn-text">{{ loading ? '保存中...' : '保 存' }}</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { updateMyInfo } from '@/api/user/user'

const userStore = useUserStore()

const genderOptions = [
  { label: '男', value: 1 },
  { label: '女', value: 2 },
  { label: '保密', value: 0 },
]

const today = computed(() => {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
})

const form = reactive({
  avatar: '',
  nickname: '',
  gender: 0,
  birthday: '',
  bio: '',
})

const loading = ref(false)
const localAvatarPath = ref('')

onShow(() => {
  const info = userStore.userInfo
  if (info) {
    form.avatar = info.avatar || ''
    form.nickname = info.nickname || ''
    form.gender = info.gender ?? 0
    form.birthday = info.birthday || ''
    form.bio = info.bio || ''
  }
})

function onBirthdayChange(e) {
  form.birthday = e.detail.value
}

function chooseAvatar() {
  uni.chooseImage({
    count: 1,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
    success(res) {
      const tempPath = res.tempFilePaths[0]
      localAvatarPath.value = tempPath
      form.avatar = tempPath
    },
  })
}

async function handleSave() {
  if (loading.value) return
  if (!form.nickname.trim()) {
    uni.showToast({ title: '请输入昵称', icon: 'none' })
    return
  }

  loading.value = true
  try {
    const payload = {
      nickname: form.nickname.trim(),
      gender: form.gender,
      birthday: form.birthday || undefined,
      bio: form.bio.trim() || undefined,
    }

    // If user picked a local image, upload it first
    if (localAvatarPath.value) {
      const uploadRes = await new Promise((resolve, reject) => {
        uni.uploadFile({
          url: '/client/api/common/upload',
          filePath: localAvatarPath.value,
          name: 'file',
          success: (res) => {
            try {
              const data = JSON.parse(res.data)
              resolve(data)
            } catch (e) {
              reject(e)
            }
          },
          fail: reject,
        })
      })
      if (uploadRes?.data?.url) {
        payload.avatar = uploadRes.data.url
      }
    }

    await updateMyInfo(payload)
    await userStore.fetchUserInfo()

    uni.showToast({ title: '保存成功', icon: 'success' })
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
  padding-bottom: 120rpx;
}

// ---- Avatar ----
.avatar-section {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-top: 48rpx;
  margin-bottom: 48rpx;
}

.avatar-wrapper {
  position: relative;
  margin-bottom: 16rpx;
}

.avatar {
  width: 160rpx;
  height: 160rpx;
  border-radius: $mb-radius-full;
}

.avatar--placeholder {
  background: $mb-color-bg-secondary;
  display: flex;
  align-items: center;
  justify-content: center;
}

.avatar-icon {
  position: relative;
  width: 56rpx;
  height: 64rpx;
}

.avatar-icon__head {
  width: 32rpx;
  height: 32rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-border;
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
}

.avatar-icon__body {
  width: 48rpx;
  height: 28rpx;
  border-radius: 24rpx 24rpx 0 0;
  background: $mb-color-border;
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
}

.avatar-badge {
  position: absolute;
  right: 0;
  bottom: 0;
  width: 48rpx;
  height: 48rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-text;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 4rpx solid #ffffff;
}

.avatar-badge-text {
  font-size: 22rpx;
  color: #ffffff;
  font-weight: 500;
}

.avatar-hint {
  font-size: 24rpx;
  color: $mb-color-text-tertiary;
}

// ---- Form ----
.form-section {
  display: flex;
  flex-direction: column;
  gap: 36rpx;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}

.label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.form-label {
  font-size: 26rpx;
  font-weight: 500;
  color: $mb-color-text-secondary;
  padding-left: 8rpx;
}

.char-count {
  font-size: 22rpx;
  color: $mb-color-text-tertiary;
  padding-right: 8rpx;
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

.input-pill--picker {
  justify-content: space-between;
}

.pill-input {
  flex: 1;
  font-size: 30rpx;
  color: $mb-color-text;
  height: 100%;
}

.pill-value {
  font-size: 30rpx;
  color: $mb-color-text;
}

.pill-value--empty {
  color: $mb-color-border-light;
}

.picker-arrow {
  width: 16rpx;
  height: 16rpx;
  border-right: 3rpx solid $mb-color-text-tertiary;
  border-bottom: 3rpx solid $mb-color-text-tertiary;
  transform: rotate(-45deg);
  flex-shrink: 0;
}

.placeholder {
  color: $mb-color-border-light;
}

// ---- Gender ----
.gender-row {
  display: flex;
  gap: 20rpx;
}

.gender-chip {
  flex: 1;
  height: 80rpx;
  border-radius: $mb-radius-full;
  background: $mb-color-bg-secondary;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color 0.2s, color 0.2s;
}

.gender-chip--active {
  background: $mb-color-text;
}

.gender-chip-text {
  font-size: 28rpx;
  color: $mb-color-text-secondary;
}

.gender-chip--active .gender-chip-text {
  color: #ffffff;
  font-weight: 500;
}

// ---- Textarea ----
.textarea-wrapper {
  background: $mb-color-bg-secondary;
  border-radius: $mb-radius-lg;
  padding: 24rpx 32rpx;
  transition: box-shadow 0.2s;

  &:focus-within {
    box-shadow: 0 0 0 3rpx rgba(13, 80, 213, 0.15);
  }
}

.bio-textarea {
  width: 100%;
  height: 160rpx;
  font-size: 28rpx;
  color: $mb-color-text;
  line-height: 1.6;
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
