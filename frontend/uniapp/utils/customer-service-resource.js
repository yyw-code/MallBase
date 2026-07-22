const RESOURCE_ID_PATTERN = /^[1-9]\d{0,18}$/
const MAX_RESOURCE_CARD_BODY_LENGTH = 50 * 1024

function normalizeText(value, maxLength) {
  return String(value || '').trim().slice(0, maxLength)
}

function normalizeResourceType(value) {
  const type = normalizeText(value, 40).toLowerCase()
  if (type === 'goods') return 'product'
  if (type === 'product' || type === 'order') return type
  return ''
}

function normalizeResourceId(value) {
  const id = normalizeText(value, 20)
  return RESOURCE_ID_PATTERN.test(id) ? id : ''
}

function resourceLabel(type) {
  if (type === 'product') return '商品'
  if (type === 'order') return '订单'
  return '资源'
}

function safeImageUrl(value) {
  const url = normalizeText(value, 2048)
  return /^https?:\/\/[^\s]+$/i.test(url) ? url : ''
}

export function buildCustomerServiceResourceRoute(typeValue, idValue) {
  const type = normalizeResourceType(typeValue)
  const id = normalizeResourceId(idValue)
  if (!type || !id) return ''
  if (type === 'product') return `/pages-sub/goods/detail?id=${id}`
  return `/pages-sub/order/detail?id=${id}`
}

export function normalizeCustomerServiceConversationResource(resource) {
  if (!resource || typeof resource !== 'object' || Array.isArray(resource)) return null
  const type = normalizeResourceType(resource.type)
  const externalId = normalizeResourceId(resource.externalId)
  const route = buildCustomerServiceResourceRoute(type, externalId)
  if (!route) return null

  return {
    type,
    externalId,
    label: resourceLabel(type),
    title: normalizeText(resource.title, 160) || `${resourceLabel(type)} #${externalId}`,
    summary: normalizeText(resource.summary, 300),
    route,
  }
}

export function parseCustomerServiceResourceCard(body) {
  const source = String(body || '')
  if (!source || source.length > MAX_RESOURCE_CARD_BODY_LENGTH) return null

  let value
  try {
    value = JSON.parse(source)
  } catch {
    return null
  }
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null

  const action = value.action && typeof value.action === 'object' && !Array.isArray(value.action)
    ? value.action
    : null
  const params = action?.params && typeof action.params === 'object' && !Array.isArray(action.params)
    ? action.params
    : null
  const type = normalizeResourceType(value.resourceType || value.resourceCode || action?.resourceCode)
  const externalId = normalizeResourceId(value.externalId || params?.externalId)
  const route = buildCustomerServiceResourceRoute(type, externalId)

  return {
    type,
    externalId,
    label: resourceLabel(type),
    title: normalizeText(value.title, 160) || '客服资源卡片',
    summary: normalizeText(value.summary, 300),
    price: normalizeText(value.price, 60),
    imageUrl: safeImageUrl(value.imageUrl),
    route,
  }
}
