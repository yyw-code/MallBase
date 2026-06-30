<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="编辑资料" />

    <scroll-view class="content" scroll-y>
      <view class="profile-card">
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
        <view class="profile-card__body">
          <text class="profile-card__title">{{ form.nickname || '完善资料' }}</text>
          <text class="profile-card__desc">
            {{ avatarUploading ? '头像上传中...' : '点击头像更换展示形象' }}
          </text>
        </view>
      </view>

      <view class="form-section">
        <view class="form-item">
          <text class="form-label">昵称</text>
          <view class="input-capsule">
            <input
              v-model="form.nickname"
              class="form-input"
              type="text"
              maxlength="20"
              placeholder="请输入昵称"
              placeholder-class="placeholder"
            />
          </view>
        </view>

        <view class="form-item">
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

        <view class="form-item">
          <text class="form-label">生日</text>
          <picker mode="date" :value="form.birthday" :end="today" @change="onBirthdayChange">
            <view class="input-capsule input-capsule--picker">
              <text :class="['capsule-value', { 'capsule-value--empty': !form.birthday }]">
                {{ form.birthday || '请选择生日' }}
              </text>
              <view class="picker-arrow" />
            </view>
          </picker>
        </view>

        <view class="form-item">
          <view class="label-row">
            <text class="form-label">个性签名</text>
            <text class="char-count">{{ form.bio.length }}/100</text>
          </view>
          <view class="textarea-card">
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

      <view class="primary-btn" :class="{ 'primary-btn--loading': loading }" @tap="handleSave">
        <text class="primary-btn-text">{{ loading ? '保存中...' : '保存资料' }}</text>
      </view>

      <view class="bottom-spacer" />
    </scroll-view>
      <mb-floating-action />
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
  background: var(--color-bg-secondary, #faf8ff);
}

.content {
  box-sizing: border-box;
  height: calc(100vh - 96rpx);
  padding: $mb-spacing-md $mb-spacing-page 0;
}

.profile-card {
  display: flex;
  align-items: center;
  gap: 28rpx;
  padding: 32rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.avatar-wrapper {
  position: relative;
  flex-shrink: 0;
}

.avatar-picker-button {
  position: absolute;
  left: 0;
  top: 0;
  width: 132rpx;
  height: 132rpx;
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
  width: 132rpx;
  height: 132rpx;
  border-radius: $mb-radius-full;
}

.avatar--placeholder {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
  display: flex;
  align-items: center;
  justify-content: center;
}

.avatar-icon {
  position: relative;
  width: 48rpx;
  height: 56rpx;
}

.avatar-icon__head {
  position: absolute;
  top: 0;
  left: 50%;
  width: 30rpx;
  height: 30rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary-on-page, var(--color-primary, #0d50d5));
  transform: translateX(-50%);
}

.avatar-icon__body {
  position: absolute;
  bottom: 0;
  left: 50%;
  width: 44rpx;
  height: 26rpx;
  border-radius: 22rpx 22rpx 0 0;
  background: var(--color-primary-on-page, var(--color-primary, #0d50d5));
  transform: translateX(-50%);
}

.avatar-badge {
  position: absolute;
  right: -2rpx;
  bottom: -2rpx;
  width: 44rpx;
  height: 44rpx;
  border-radius: $mb-radius-full;
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;
  border: 4rpx solid var(--color-bg, #ffffff);
}

.avatar-badge-text {
  font-size: 22rpx;
  font-weight: 600;
  color: var(--color-text-on-primary, #ffffff);
}

.profile-card__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 10rpx;
}

.profile-card__title {
  overflow: hidden;
  font-size: $mb-font-xl;
  font-weight: 700;
  line-height: 1.3;
  color: var(--color-text-title-on-bg, var(--color-text-title, #191b23));
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-card__desc {
  font-size: $mb-font-sm;
  line-height: 1.5;
  color: var(--color-text-secondary-on-bg, var(--color-text-secondary, #434654));
}

.form-section {
  display: flex;
  flex-direction: column;
  gap: 30rpx;
  margin-top: $mb-spacing-lg;
  padding: 30rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.form-item {
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}

.label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.form-label {
  padding-left: 4rpx;
  font-size: $mb-font-md;
  font-weight: 600;
  line-height: 1.4;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
}

.char-count {
  padding-right: 4rpx;
  font-size: $mb-font-sm;
  line-height: 1.4;
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
}

.input-capsule {
  display: flex;
  align-items: center;
  min-height: 104rpx;
  padding: 0 30rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-full;
  box-shadow: inset 0 0 0 1rpx var(--color-bg-secondary, #faf8ff);
}

.input-capsule--picker {
  justify-content: space-between;
}

.form-input {
  flex: 1;
  min-width: 0;
  height: 104rpx;
  font-size: $mb-font-md;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
}

.capsule-value {
  font-size: $mb-font-md;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
}

.capsule-value--empty,
.placeholder {
  color: var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
}

.picker-arrow {
  width: 16rpx;
  height: 16rpx;
  border-right: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  border-bottom: 3rpx solid var(--color-text-tertiary-on-bg, var(--color-text-tertiary, #737686));
  transform: rotate(-45deg);
  flex-shrink: 0;
}

.gender-row {
  display: flex;
  gap: 16rpx;
}

.gender-chip {
  flex: 1;
  height: 88rpx;
  border-radius: $mb-radius-full;
  background: var(--color-bg-secondary, #faf8ff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  align-items: center;
  justify-content: center;
}

.gender-chip--active {
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.05));
  border-color: var(--color-primary-border, rgba(13, 80, 213, 0.24));
}

.gender-chip-text {
  font-size: $mb-font-md;
  line-height: 1.4;
  color: var(--color-text-secondary-on-bg, var(--color-text-secondary, #434654));
}

.gender-chip--active .gender-chip-text {
  color: var(--color-primary-on-bg, var(--color-primary, #0d50d5));
  font-weight: 600;
}

.textarea-card {
  min-height: 192rpx;
  padding: 26rpx 30rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 36rpx;
  box-shadow: inset 0 0 0 1rpx var(--color-bg-secondary, #faf8ff);
}

.bio-textarea {
  width: 100%;
  height: 150rpx;
  font-size: $mb-font-md;
  line-height: 1.6;
  color: var(--color-text-on-bg, var(--color-text, #191b23));
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
