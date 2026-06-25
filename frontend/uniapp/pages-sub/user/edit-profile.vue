<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="编辑资料" />

    <view class="page-content">
      <!-- Avatar -->
      <view class="avatar-section">
        <view class="avatar-wrapper" @tap="chooseAvatarFallback">
          <image v-if="avatarPreview" class="avatar" :src="avatarPreview" mode="aspectFill" />
          <view v-else class="avatar avatar--placeholder">
            <view class="avatar-icon">
              <view class="avatar-icon__head" />
              <view class="avatar-icon__body" />
            </view>
          </view>
          <view class="avatar-badge">
            <text class="avatar-badge-text">换</text>
          </view>
          <!-- #ifdef MP-WEIXIN -->
          <button
            class="avatar-picker-button"
            open-type="chooseAvatar"
            @chooseavatar="chooseWechatAvatar"
          ></button>
          <!-- #endif -->
        </view>
        <text class="avatar-hint">{{ avatarUploading ? '头像上传中...' : '点击头像更换' }}</text>
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
      <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleSave">
        <text class="primary-btn-text">{{ loading ? '保存中...' : '保 存' }}</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { useDecorateStore } from '@/store/decorate'
import { ref, reactive, computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useUserStore } from '@/store/user'
import { updateMyInfo } from '@/api/user/user'
import { getUploadedAssetValue, getUploadedPreviewUrl, uploadImage } from '@/api/upload'
const decorateStore = useDecorateStore()

const userStore = useUserStore()

const genderOptions = [
  { label: '男', value: 1 },
  { label: '女', value: 2 },
  { label: '保密', value: 0 }
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
  avatar_full_url: '',
  nickname: '',
  gender: 0,
  birthday: '',
  bio: ''
})

const loading = ref(false)
const avatarUploading = ref(false)
const avatarTempPreview = ref('')
const avatarUploadPath = ref('')

const avatarPreview = computed(() => {
  return avatarTempPreview.value || form.avatar_full_url || form.avatar
})

onShow(() => {
  const info = userStore.userInfo
  if (info) {
    form.avatar = info.avatar || ''
    form.avatar_full_url = info.avatar_full_url || ''
    form.nickname = info.nickname || ''
    form.gender = info.gender ?? 0
    form.birthday = info.birthday || ''
    form.bio = info.bio || ''
    avatarTempPreview.value = ''
    avatarUploadPath.value = ''
  }
})

function onBirthdayChange(e) {
  form.birthday = e.detail.value
}

function chooseAvatarFallback() {
  // #ifndef MP-WEIXIN
  chooseLocalAvatar()
  // #endif
}

async function uploadAvatarPath(tempPath) {
  if (!tempPath || avatarUploading.value) return
  avatarTempPreview.value = tempPath
  avatarUploadPath.value = ''
  avatarUploading.value = true
  try {
    const uploadRes = await uploadImage(tempPath, { module: 'avatar' })
    const submitValue = getUploadedAssetValue(uploadRes)
    if (!submitValue) {
      throw new Error('上传结果缺少头像路径')
    }
    avatarUploadPath.value = String(submitValue)
    avatarTempPreview.value = getUploadedPreviewUrl(uploadRes, tempPath)
  } catch (_) {
    avatarTempPreview.value = ''
    avatarUploadPath.value = ''
  } finally {
    avatarUploading.value = false
  }
}

function chooseLocalAvatar() {
  uni.chooseImage({
    count: 1,
    sizeType: ['compressed'],
    sourceType: ['album', 'camera'],
    success(res) {
      const tempPath = res.tempFilePaths[0]
      uploadAvatarPath(tempPath)
    }
  })
}

function chooseWechatAvatar(e) {
  const avatarUrl = e.detail?.avatarUrl || ''
  if (!avatarUrl) return
  uploadAvatarPath(avatarUrl)
}

async function handleSave() {
  if (loading.value) return
  if (avatarUploading.value) {
    uni.showToast({ title: '头像上传中，请稍后', icon: 'none' })
    return
  }
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
      bio: form.bio.trim()
    }

    if (avatarUploadPath.value) {
      payload.avatar = avatarUploadPath.value
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

.avatar-picker-button {
  position: absolute;
  left: 0;
  top: 0;
  width: 160rpx;
  height: 160rpx;
  padding: 0;
  margin: 0;
  border-radius: $mb-radius-full;
  background: transparent;
  opacity: 0;

  &::after {
    border: 0;
  }
}

.avatar {
  width: 160rpx;
  height: 160rpx;
  border-radius: $mb-radius-full;
}

.avatar--placeholder {
  background: var(--color-bg-secondary, #faf8ff);
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
  background: var(--color-border, #e0e4e8);
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
}

.avatar-icon__body {
  width: 48rpx;
  height: 28rpx;
  border-radius: $mb-radius-lg $mb-radius-lg 0 0;
  background: var(--color-border, #e0e4e8);
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
  background: var(--color-text, #191b23);
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
  color: var(--color-text-tertiary, #737686);
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
  color: var(--color-text-secondary, #434654);
  padding-left: 8rpx;
}

.char-count {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
  padding-right: 8rpx;
}

.input-pill {
  display: flex;
  align-items: center;
  height: 100rpx;
  border-radius: $mb-radius-sm;
  background: var(--color-bg-secondary, #faf8ff);
  border: 1rpx solid var(--color-border, #e0e4e8);
  padding: 0 32rpx;
  transition:
    border-color 0.2s,
    background-color 0.2s;

  &:focus-within {
    border-color: rgba(13, 80, 213, 0.3);
    background: var(--color-bg, #ffffff);
  }
}

.input-pill--picker {
  justify-content: space-between;
}

.pill-input {
  flex: 1;
  font-size: 30rpx;
  color: var(--color-text, #191b23);
  height: 100%;
}

.pill-value {
  font-size: 30rpx;
  color: var(--color-text, #191b23);
}

.pill-value--empty {
  color: var(--color-border, #c3c5d7);
}

.picker-arrow {
  width: 16rpx;
  height: 16rpx;
  border-right: 3rpx solid var(--color-text-tertiary, #737686);
  border-bottom: 3rpx solid var(--color-text-tertiary, #737686);
  transform: rotate(-45deg);
  flex-shrink: 0;
}

.placeholder {
  color: var(--color-border, #c3c5d7);
}

// ---- Gender ----
.gender-row {
  display: flex;
  gap: 20rpx;
}

.gender-chip {
  flex: 1;
  height: 80rpx;
  border-radius: $mb-radius-sm;
  background: var(--color-bg-secondary, #faf8ff);
  border: 1rpx solid var(--color-border, #e0e4e8);
  display: flex;
  align-items: center;
  justify-content: center;
  transition:
    background-color 0.2s,
    color 0.2s;
}

.gender-chip--active {
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.08));
  border-color: var(--color-primary-border, rgba(13, 80, 213, 0.3));
}

.gender-chip-text {
  font-size: 28rpx;
  color: var(--color-text-secondary, #434654);
}

.gender-chip--active .gender-chip-text {
  color: var(--color-primary, #0d50d5);
  font-weight: 500;
}

// ---- Textarea ----
.textarea-wrapper {
  background: var(--color-bg-secondary, #faf8ff);
  border-radius: $mb-radius-sm;
  border: 1rpx solid var(--color-border, #e0e4e8);
  padding: 24rpx 32rpx;
  transition:
    border-color 0.2s,
    background-color 0.2s;

  &:focus-within {
    border-color: rgba(13, 80, 213, 0.3);
    background: var(--color-bg, #ffffff);
  }
}

.bio-textarea {
  width: 100%;
  height: 160rpx;
  font-size: 28rpx;
  color: var(--color-text, #191b23);
  line-height: 1.6;
}

// ---- Button ----
.primary-btn {
  height: 100rpx;
  border-radius: $mb-radius-sm;
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: 48rpx;
  transition:
    opacity 0.15s,
    transform 0.15s;

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
  letter-spacing: 0;
}
</style>
