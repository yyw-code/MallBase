import { useAppStore } from '@/store/app'
import { createCustomerServiceContextToken } from '@/api/customer-service'

const CUSTOMER_SERVICE_URL_CACHE_KEY = 'mallbase_customer_service_url:current'

function normalizePhone(phone) {
  return String(phone || '').replace(/[\s-]/g, '')
}

function currentPageUrl() {
  const pages = getCurrentPages()
  const current = pages[pages.length - 1]
  if (!current?.route) return ''
  const query = Object.entries(current.options || {})
    .map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
    .join('&')
  return normalizePageUrl(query ? `/${current.route}?${query}` : `/${current.route}`)
}

function normalizePageUrl(url) {
  const value = String(url || '')
  if (!value || /^https?:\/\//i.test(value)) return value
  // #ifdef H5
  if (typeof window !== 'undefined') {
    const path = value.startsWith('/') ? value : `/${value}`
    return `${window.location.origin}${window.location.pathname}#${path}`
  }
  // #endif
  return value
}

function normalizeResource(resource) {
  if (!resource || !resource.type || resource.id === undefined || resource.id === null || resource.id === '') {
    return null
  }
  return {
    type: String(resource.type),
    id: String(resource.id),
    title: String(resource.title || resource.name || ''),
    url: normalizePageUrl(resource.url || currentPageUrl()),
    summary: String(resource.summary || ''),
    metadata: resource.metadata && typeof resource.metadata === 'object' ? resource.metadata : {},
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
    source: options.source || 'mallbase',
    conversation_key: options.conversationKey || '',
    resources,
  }
}

function appendQuery(url, query) {
  const params = Object.entries(query)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
    .join('&')
  if (!params) return url
  return `${url}${url.includes('?') ? '&' : '?'}${params}`
}

function buildCustomerServiceUrl(payload) {
  if (!payload?.widget_url || !payload?.context_token) return ''
  const query = {
    embed: '1',
    contextToken: payload.context_token,
    platform: payload.platform_code || 'mallbase',
  }
  // #ifdef H5
  query.resourceUrlTemplates = JSON.stringify(buildHostResourceUrlTemplates())
  // #endif
  return appendQuery(payload.widget_url, query)
}

// #ifdef H5
function buildHostResourceUrlTemplates() {
  const clientBaseUrl = `${window.location.origin}${window.location.pathname}`
  return {
    product: `${clientBaseUrl}#/pages-sub/goods/detail?id={externalId}`,
    order: `${clientBaseUrl}#/pages-sub/order/detail?id={externalId}`,
  }
}
// #endif

function openOnlineCustomerService(url) {
  if (!url) return false
  uni.setStorageSync(CUSTOMER_SERVICE_URL_CACHE_KEY, {
    url,
    created_at: Date.now(),
  })
  uni.navigateTo({
    url: '/pages-sub/customer-service/webview',
  })
  return true
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

export async function openCustomerService(options = {}) {
  const appStore = useAppStore()
  const config = await appStore.fetchBasicConfig({ force: true }) || appStore.siteConfig || {}
  const phone = normalizePhone(config.client_customer_service_phone)
  const mode = config.client_customer_service_mode === 'system' ? 'system' : 'phone'

  if (mode === 'phone') {
    return callPhoneCustomerService(phone)
  }

  try {
    const payload = await createCustomerServiceContextToken(buildContextPayload(options))
    const url = payload?.enabled ? buildCustomerServiceUrl(payload) : ''
    if (openOnlineCustomerService(url)) return true
  } catch (error) {
    console.warn('[customer-service] online entry unavailable', error)
  }

  showOnlineCustomerServiceUnavailable()
  return false
}
