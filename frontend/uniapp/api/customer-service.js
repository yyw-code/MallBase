import {
  post,
  requestExternalJson,
  uploadExternalFile,
} from '@/api/request'
import {
  isCustomerServiceComposer,
  isCustomerServiceHandoffState,
  isCustomerServiceResourceCandidateResult,
} from '@/utils/customer-service-composer'

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

export const sendCustomerServiceVisitorMessage = (
  apiBase,
  conversationId,
  visitorToken,
  input,
) => requestExternalJson({
  baseUrl: apiBase,
  url: `/conversations/${encodeURIComponent(conversationId)}/visitor-messages`,
  method: 'POST',
  header: visitorHeader(visitorToken),
  data: {
    type: String(input?.type || ''),
    body: String(input?.body || ''),
    attachmentId: input?.attachmentId ? String(input.attachmentId) : undefined,
    clientMessageId: String(input?.clientMessageId || ''),
  },
  validate: isMessage,
})

export const getCustomerServiceComposer = (apiBase, conversationId, visitorToken) =>
  requestExternalJson({
    baseUrl: apiBase,
    url: `/conversations/${encodeURIComponent(conversationId)}/composer`,
    header: visitorHeader(visitorToken),
    validate: isCustomerServiceComposer,
  })

export const requestCustomerServiceHandoff = (
  apiBase,
  conversationId,
  visitorToken,
  clientRequestId,
) => requestExternalJson({
  baseUrl: apiBase,
  url: `/conversations/${encodeURIComponent(conversationId)}/handoff-requests`,
  method: 'POST',
  header: visitorHeader(visitorToken),
  data: {
    clientRequestId: String(clientRequestId || ''),
  },
  validate: isCustomerServiceHandoffState,
})

export const searchCustomerServiceResourceCandidates = (
  apiBase,
  conversationId,
  visitorToken,
  input,
) => requestExternalJson({
  baseUrl: apiBase,
  url: `/conversations/${encodeURIComponent(conversationId)}/resource-candidates/search`,
  method: 'POST',
  header: visitorHeader(visitorToken),
  data: {
    actionCode: String(input?.actionCode || ''),
    query: String(input?.query || ''),
    cursor: input?.cursor ? String(input.cursor) : undefined,
    limit: Number(input?.limit) || 10,
    clientRequestId: String(input?.clientRequestId || ''),
  },
  validate: isCustomerServiceResourceCandidateResult,
})

export const sendCustomerServiceResourceMessage = (
  apiBase,
  conversationId,
  visitorToken,
  candidateToken,
  clientMessageId,
) => requestExternalJson({
  baseUrl: apiBase,
  url: `/conversations/${encodeURIComponent(conversationId)}/resource-messages`,
  method: 'POST',
  header: visitorHeader(visitorToken),
  data: {
    resourceRef: {
      kind: 'CANDIDATE',
      token: String(candidateToken || ''),
    },
    clientMessageId: String(clientMessageId || ''),
  },
  validate: isMessage,
})

export const uploadCustomerServiceAttachment = (
  apiBase,
  conversationId,
  upload,
  visitorToken,
) => {
  const source = typeof upload === 'string' ? { filePath: upload } : (upload || {})
  return uploadExternalFile({
    baseUrl: apiBase,
    url: `/conversations/${encodeURIComponent(conversationId)}/attachments`,
    file: source.file,
    filePath: source.filePath,
    header: visitorHeader(visitorToken),
    validate: isAttachment,
  })
}

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
