import { useAppStore } from '@/store/app'

function normalizePhone(phone) {
  return String(phone || '').replace(/[\s-]/g, '')
}

export async function openCustomerService() {
  const appStore = useAppStore()
  const config = await appStore.fetchBasicConfig({ force: true }) || appStore.siteConfig || {}
  const phone = normalizePhone(config.client_customer_service_phone)

  if (!phone) {
    uni.showToast({ title: '未配置客服手机号', icon: 'none' })
    return false
  }

  uni.makePhoneCall({
    phoneNumber: phone,
    fail() {
      uni.showToast({ title: '拨号失败', icon: 'none' })
    },
  })
  return true
}
