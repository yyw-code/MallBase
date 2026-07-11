import { getBasicConfig } from '@/api/config'

export function settingSwitchEnabled(value, fallback = true) {
  if (value === undefined || value === null || value === '') return fallback
  return ['1', 'true', 'on'].includes(String(value).toLowerCase())
}

export async function isPointsEnabled() {
  try {
    const data = await getBasicConfig()
    return settingSwitchEnabled(data?.points_enabled, true)
  } catch {
    return true
  }
}

export function leavePointsPage() {
  uni.showToast({ title: '积分功能未开启', icon: 'none' })
  setTimeout(() => {
    if (getCurrentPages().length > 1) {
      uni.navigateBack()
      return
    }
    uni.switchTab({ url: '/pages/profile/index' })
  }, 300)
}
