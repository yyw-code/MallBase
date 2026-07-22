import { useAppStore } from '@/store/app'
import { createCustomerServiceContextToken } from '@/api/customer-service'
import { isLoggedIn, requireLogin } from '@/utils/auth'

const CUSTOMER_SERVICE_LAUNCH_KEY = 'mallbase_customer_service_launch:current'
const LEGACY_CUSTOMER_SERVICE_URL_KEY = 'mallbase_customer_service_url:current'
const CUSTOMER_SERVICE_LAUNCH_MAX_AGE = 5 * 60 * 1000

function normalizePhone(phone) {
  return String(phone || '').replace(/[\s-]/g, '')
}

function currentRouteUrl() {
  const pages = getCurrentPages()
  const current = pages[pages.length - 1]
  if (!current?.route) return ''
  const query = Object.entries(current.options || {})
    .map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
    .join('&')
  return query ? `/${current.route}?${query}` : `/${current.route}`
}

function normalizeResource(resource) {
  if (!resource || !resource.type || resource.id === undefined || resource.id === null || resource.id === '') {
    return null
  }
  return {
    type: String(resource.type),
    id: String(resource.id),
  }
}

function buildContextPayload(options = {}) {
  const resources = []
  if (Array.isArray(options.resources)) {
    options.resources.forEach((item) => {
      const resource = normalizeResource(item)
      if (resource) resources.push(resource)
    })
  }
  if (options.product) {
    const resource = normalizeResource({
      type: 'product',
      id: options.product.id,
      title: options.product.title || options.product.name,
      url: options.product.url,
      summary: options.product.summary,
      metadata: options.product.metadata,
    })
    if (resource) resources.push(resource)
  }
  if (options.order) {
    const resource = normalizeResource({
      type: 'order',
      id: options.order.id,
      title: options.order.title || options.order.sn,
      url: options.order.url,
      summary: options.order.summary,
      metadata: options.order.metadata,
    })
    if (resource) resources.push(resource)
  }

  return {
    resources,
  }
}

function requireCustomerServiceLogin() {
  if (isLoggedIn()) return true

  const redirectUrl = currentRouteUrl()
  if (redirectUrl.startsWith('/pages-sub/user/login')) {
    uni.showToast({ title: '请先完成登录', icon: 'none' })
    return false
  }
  return requireLogin(redirectUrl)
}

function buildLaunchPayload(payload) {
  const contextToken = String(payload?.context_token || '').trim()
  const apiBase = String(payload?.api_base || '').trim().replace(/\/+$/, '')
  const socketBase = String(payload?.socket_base || '').trim().replace(/\/+$/, '')
  if (!contextToken || !apiBase || !socketBase) return null

  const createdAt = Date.now()
  const rawExpiresIn = Number(payload?.expires_in || 300)
  const expiresIn = Number.isFinite(rawExpiresIn)
    ? Math.max(60, Math.min(300, rawExpiresIn))
    : 300
  return {
    context_token: contextToken,
    api_base: apiBase,
    socket_base: socketBase,
    platform_code: String(payload?.platform_code || 'mallbase'),
    created_at: createdAt,
    expires_at: createdAt + expiresIn * 1000,
  }
}

function openOnlineCustomerService(payload) {
  const launch = buildLaunchPayload(payload)
  if (!launch) return Promise.resolve(false)

  try {
    uni.removeStorageSync(LEGACY_CUSTOMER_SERVICE_URL_KEY)
    uni.setStorageSync(CUSTOMER_SERVICE_LAUNCH_KEY, launch)
  } catch {
    return Promise.resolve(false)
  }

  return new Promise((resolve) => {
    uni.navigateTo({
      url: '/pages-sub/customer-service/index',
      success() {
        resolve(true)
      },
      fail() {
        clearCustomerServiceLaunch()
        resolve(false)
      },
    })
  })
}

function callPhoneCustomerService(phone) {
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

function showOnlineCustomerServiceUnavailable() {
  uni.showToast({ title: '客服暂不可用', icon: 'none' })
}

export function consumeCustomerServiceLaunch() {
  try {
    const launch = uni.getStorageSync(CUSTOMER_SERVICE_LAUNCH_KEY)
    uni.removeStorageSync(CUSTOMER_SERVICE_LAUNCH_KEY)
    if (!launch || typeof launch !== 'object') return null

    const createdAt = Number(launch.created_at || 0)
    const expiresAt = Number(launch.expires_at || 0)
    const now = Date.now()
    if (
      !createdAt
      || now - createdAt > CUSTOMER_SERVICE_LAUNCH_MAX_AGE
      || !expiresAt
      || expiresAt <= now
    ) {
      return null
    }

    const contextToken = String(launch.context_token || '').trim()
    const apiBase = String(launch.api_base || '').trim()
    const socketBase = String(launch.socket_base || '').trim()
    if (!contextToken || !apiBase || !socketBase) return null

    return {
      contextToken,
      apiBase,
      socketBase,
      platformCode: String(launch.platform_code || 'mallbase'),
    }
  } catch {
    clearCustomerServiceLaunch()
    return null
  }
}

export function clearCustomerServiceLaunch() {
  try {
    uni.removeStorageSync(CUSTOMER_SERVICE_LAUNCH_KEY)
    uni.removeStorageSync(LEGACY_CUSTOMER_SERVICE_URL_KEY)
  } catch {}
}

export async function openCustomerService(options = {}) {
  const appStore = useAppStore()
  const config = await appStore.fetchBasicConfig({ force: true }) || appStore.siteConfig || {}
  const phone = normalizePhone(config.client_customer_service_phone)
  const mode = config.client_customer_service_mode === 'system' ? 'system' : 'phone'

  if (mode === 'phone') {
    return callPhoneCustomerService(phone)
  }

  clearCustomerServiceLaunch()
  if (!requireCustomerServiceLogin()) return false

  try {
    const payload = await createCustomerServiceContextToken(buildContextPayload(options))
    if (payload?.enabled && await openOnlineCustomerService(payload)) return true
  } catch (error) {
    if (!isLoggedIn()) return false
    console.warn('[customer-service] online entry unavailable', error)
  }

  showOnlineCustomerServiceUnavailable()
  return false
}
