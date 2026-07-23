const HANDOFF_STATUSES = new Set(['AVAILABLE', 'QUEUED', 'ASSIGNED', 'UNAVAILABLE'])
const RESOURCE_AVAILABILITY = new Set(['ENABLED', 'LOGIN_REQUIRED'])

function isRecord(value) {
  return Boolean(value && typeof value === 'object' && !Array.isArray(value))
}

function isText(value) {
  return typeof value === 'string'
}

function isRequiredText(value) {
  return isText(value) && value.trim() !== ''
}

function isOptionalText(value) {
  return value === undefined || isText(value)
}

function isMessageAction(action) {
  return action.kind === 'MESSAGE'
    && ['message.text', 'message.emoji'].includes(action.code)
    && ['CORE', 'INLINE'].includes(action.placement)
    && typeof action.enabled === 'boolean'
}

function isAttachmentAction(action) {
  const pair = `${action.code}:${action.messageType}`
  return action.kind === 'ATTACHMENT'
    && ['attachment.image:IMAGE', 'attachment.audio:AUDIO'].includes(pair)
    && ['MORE', 'INLINE'].includes(action.placement)
    && Array.isArray(action.accept)
    && action.accept.every(isRequiredText)
    && Number.isInteger(action.maxBytes)
    && action.maxBytes > 0
}

function isResourceAction(action) {
  return action.kind === 'RESOURCE'
    && /^resource\.[a-z0-9][a-z0-9._-]{0,80}$/i.test(action.code)
    && action.placement === 'MORE'
    && isRequiredText(action.label)
    && isText(action.icon)
    && isRequiredText(action.resourceDefinitionCode)
    && RESOURCE_AVAILABILITY.has(action.availability)
}

function isComposerAction(action) {
  if (!isRecord(action)) return false
  return isMessageAction(action) || isAttachmentAction(action) || isResourceAction(action)
}

export function isCustomerServiceHandoffState(handoff) {
  return isRecord(handoff)
    && typeof handoff.enabled === 'boolean'
    && HANDOFF_STATUSES.has(handoff.status)
    && isOptionalText(handoff.requestId)
    && isOptionalText(handoff.assignedAgentId)
    && (
      handoff.assignmentVersion === undefined
      || (Number.isInteger(handoff.assignmentVersion) && handoff.assignmentVersion >= 0)
    )
}

export function isCustomerServiceComposer(value) {
  return isRecord(value)
    && value.version === 1
    && isRequiredText(value.conversationId)
    && Array.isArray(value.actions)
    && value.actions.every(isComposerAction)
    && isCustomerServiceHandoffState(value.handoff)
}

export function normalizeCustomerServiceComposerActions(composer) {
  const actions = Array.isArray(composer?.actions) ? composer.actions : []
  const messageActions = actions.filter((action) => action?.kind === 'MESSAGE')
  const attachments = actions.filter((action) => action?.kind === 'ATTACHMENT')
  const resources = actions
    .filter(isResourceAction)
    .map((action) => ({
      code: action.code,
      label: action.label.trim(),
      icon: action.icon.trim(),
      resourceDefinitionCode: action.resourceDefinitionCode.trim(),
      availability: action.availability,
    }))

  return {
    textEnabled: messageActions.some((action) => action.code === 'message.text' && action.enabled),
    emojiEnabled: messageActions.some((action) => action.code === 'message.emoji' && action.enabled),
    imageAttachment: attachments.find((action) => action.code === 'attachment.image') || null,
    audioAttachment: attachments.find((action) => action.code === 'attachment.audio') || null,
    resources,
    handoff: isCustomerServiceHandoffState(composer?.handoff)
      ? { ...composer.handoff }
      : { enabled: false, status: 'UNAVAILABLE' },
  }
}

function isDisplayField(field) {
  return isRecord(field)
    && isRequiredText(field.key)
    && isRequiredText(field.label)
    && isText(field.value)
    && isRequiredText(field.valueType)
}

function isResourceDisplay(display) {
  return isRecord(display)
    && isText(display.title)
    && isText(display.status)
    && isText(display.summary)
    && Array.isArray(display.fields)
    && display.fields.every(isDisplayField)
    && Array.isArray(display.sections)
    && display.sections.every(isRecord)
    && (display.canvas === undefined || isRecord(display.canvas))
}

function isResourceCandidate(item) {
  return isRecord(item)
    && isRequiredText(item.candidateToken)
    && isRequiredText(item.resourceDefinitionCode)
    && isResourceDisplay(item.display)
}

export function isCustomerServiceResourceCandidateResult(value) {
  if (!isRecord(value) || !Array.isArray(value.items) || !value.items.every(isResourceCandidate)) {
    return false
  }
  if (!isRecord(value.page) || typeof value.page.hasMore !== 'boolean') return false
  if (value.page.nextCursor !== undefined && !isRequiredText(value.page.nextCursor)) return false
  return !value.page.hasMore || isRequiredText(value.page.nextCursor)
}

export function createCustomerServiceClientId(prefix = 'client') {
  const normalizedPrefix = String(prefix || 'client')
    .replace(/[^A-Za-z0-9._-]/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 32) || 'client'
  let entropy = ''
  try {
    entropy = globalThis.crypto?.randomUUID?.() || ''
  } catch {}
  if (!entropy) {
    entropy = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`
  }
  return `${normalizedPrefix}:${entropy}`.slice(0, 120)
}
