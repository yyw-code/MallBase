import {
  post,
  requestExternalJson,
  uploadExternalFile,
} from '@/api/request'

export const createCustomerServiceContextToken = (data) =>
  post('/client/api/customer-service/context-token', data)

function isRecord(value) {
  return value && typeof value === 'object' && !Array.isArray(value)
}

function isConversation(value) {
  return isRecord(value)
    && typeof value.id === 'string'
    && value.id !== ''
    && typeof value.visitorToken === 'string'
    && value.visitorToken !== ''
}

function isMessage(value) {
  return isRecord(value)
    && typeof value.id === 'string'
    && typeof value.conversationId === 'string'
    && typeof value.type === 'string'
    && typeof value.senderType === 'string'
}

function isMessageList(value) {
  return Array.isArray(value) && value.every(isMessage)
}

function isAttachment(value) {
  return isRecord(value)
    && typeof value.id === 'string'
    && typeof value.url === 'string'
}

function visitorHeader(visitorToken) {
  return {
    'x-visitor-token': visitorToken,
  }
}

export const createExternalCustomerServiceConversation = (apiBase, contextToken) =>
  requestExternalJson({
    baseUrl: apiBase,
    url: '/conversations/external',
    method: 'POST',
    data: { contextToken },
    validate: isConversation,
  })

export const getCustomerServiceMessages = (apiBase, conversationId, visitorToken) =>
  requestExternalJson({
    baseUrl: apiBase,
    url: `/conversations/${encodeURIComponent(conversationId)}/messages`,
    header: visitorHeader(visitorToken),
    validate: isMessageList,
  })

export const markCustomerServiceRead = (apiBase, conversationId, visitorToken) =>
  requestExternalJson({
    baseUrl: apiBase,
    url: `/conversations/${encodeURIComponent(conversationId)}/read?side=visitor`,
    method: 'PATCH',
    header: visitorHeader(visitorToken),
    validate: isConversation,
  })

export const uploadCustomerServiceAttachment = (apiBase, filePath, visitorToken) =>
  uploadExternalFile({
    baseUrl: apiBase,
    url: '/uploads',
    filePath,
    header: visitorHeader(visitorToken),
    validate: isAttachment,
  })

export function resolveCustomerServiceAssetUrl(apiBase, url) {
  const value = String(url || '').trim()
  if (!value) return ''
  if (/^https?:\/\//i.test(value)) return value
  const origin = String(apiBase || '')
    .trim()
    .replace(/\/api\/?$/i, '')
    .replace(/\/+$/, '')
  if (!/^https?:\/\//i.test(origin)) return ''
  return `${origin}/${value.replace(/^\/+/, '')}`
}
