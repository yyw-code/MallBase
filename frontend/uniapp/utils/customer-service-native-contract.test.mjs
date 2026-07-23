import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import test from 'node:test'

function source(relativeUrl) {
  return readFileSync(new URL(relativeUrl, import.meta.url), 'utf8')
}

async function importSource(relativeUrl) {
  const encoded = Buffer.from(source(relativeUrl)).toString('base64')
  return import(`data:text/javascript;base64,${encoded}`)
}

function createUnauthorizedHarness(currentRoute = 'pages/profile/index') {
  const start = requestSource.indexOf('const TOKEN_KEY')
  const end = requestSource.indexOf('function refreshAccessToken')
  const sourceBlock = requestSource.slice(start, end)
  const navigateCalls = []
  const removedKeys = []
  const authClearedEvents = []
  const uni = {
    removeStorageSync(key) {
      removedKeys.push(key)
    },
    navigateTo(options) {
      navigateCalls.push(options)
    },
  }
  const getCurrentPages = () => [{ route: currentRoute, options: {} }]
  const notifyAuthCleared = () => authClearedEvents.push('cleared')
  const create = new Function(
    'uni',
    'getCurrentPages',
    'notifyAuthCleared',
    `${sourceBlock}; return handleUnauthorized`,
  )
  return {
    handleUnauthorized: create(uni, getCurrentPages, notifyAuthCleared),
    authClearedEvents,
    navigateCalls,
    removedKeys,
  }
}

const entrySource = source('./customer-service.js')
const authSource = source('./auth.js')
const pageSource = source('../pages-sub/customer-service/index.vue')
const resourceSheetSource = source('../components/mb-customer-service-resource-sheet/mb-customer-service-resource-sheet.vue')
const socketSource = source('./customer-service-socket.js')
const apiSource = source('../api/customer-service.js')
const requestSource = source('../api/request.js')
const pagesSource = source('../pages.json')
const adminSource = source('../../admin/apps/web-antd/src/views/client/config/index.vue')
const serviceSource = source('../../../backend/app/service/client/CustomerServiceContextService.php')
const designSource = source('../../../docs/development/customer-service-native-page.md')

test('native customer service page replaces Widget and web-view navigation', () => {
  assert.equal(existsSync(new URL('../pages-sub/customer-service/webview.vue', import.meta.url)), false)
  assert.doesNotMatch(pageSource, /<web-view|window\.location|resourceUrlTemplates/)
  assert.match(pageSource, /createExternalCustomerServiceConversation/)
  assert.match(pageSource, /socket\.emit\('conversation:join'/)
  assert.match(pageSource, /sendCustomerServiceVisitorMessage/)
  assert.match(apiSource, /\/visitor-messages`/)
  assert.match(pageSource, /socket\.on\('message:new'/)
  assert.match(pageSource, /uploadCustomerServiceAttachment/)

  const packageStart = pagesSource.indexOf('"root": "pages-sub/customer-service"')
  const packageEnd = pagesSource.indexOf('"root":', packageStart + 10)
  const customerServicePackage = pagesSource.slice(
    packageStart,
    packageEnd > packageStart ? packageEnd : undefined,
  )
  assert.match(customerServicePackage, /"path": "index"/)
  assert.doesNotMatch(customerServicePackage, /"path": "webview"/)
})

test('composer keeps emoji, voice, and more actions in one compact interaction area', () => {
  assert.match(pageSource, /import UniIcons from '@dcloudio\/uni-ui\/lib\/uni-icons\/uni-icons\.vue'/)
  assert.match(resourceSheetSource, /import UniIcons from '@dcloudio\/uni-ui\/lib\/uni-icons\/uni-icons\.vue'/)
  assert.match(pageSource, /<uni-icons/)
  assert.doesNotMatch(pageSource, /'■'|'▶'/)
  assert.match(pageSource, /class="composer__mode-toggle"/)
  assert.match(pageSource, /v-if="!voiceMode"[\s\S]*class="composer__input"/)
  assert.match(pageSource, /v-else[\s\S]*class="composer__hold-to-talk"/)
  assert.match(pageSource, /class="composer-panel composer-panel--emoji"/)
  assert.match(pageSource, /class="composer-panel composer-panel--actions"/)
  assert.match(pageSource, /@focus="closeComposerPanels"/)
  assert.match(pageSource, /@touchcancel\.stop="cancelRecording"/)
  assert.doesNotMatch(pageSource, /@touchcancel\.stop\.prevent="cancelRecording"/)
  assert.match(
    pageSource,
    /\/\* #ifdef H5 \*\/[\s\S]*?\.customer-service-page\s*\{[\s\S]*?height:\s*100dvh;[\s\S]*?\.composer__input\s*\{[\s\S]*?min-height:\s*0;[\s\S]*?\/\* #endif \*\//,
  )

  const actionPanelStart = pageSource.indexOf('composer-panel composer-panel--actions')
  const actionPanelEnd = pageSource.indexOf('class="composer__main"', actionPanelStart)
  const actionPanel = pageSource.slice(actionPanelStart, actionPanelEnd)
  assert.match(actionPanel, /图片/)
  assert.doesNotMatch(actionPanel, /表情|语音|转人工/)
  assert.doesNotMatch(pageSource, /class="composer__voice-row"/)
})

test('voice recording uses platform adapters and external upload accepts H5 blobs', () => {
  const recorderUrl = new URL('./customer-service-recorder.js', import.meta.url)
  assert.equal(existsSync(recorderUrl), true)
  const recorderSource = readFileSync(recorderUrl, 'utf8')

  assert.match(recorderSource, /navigator\.mediaDevices\.getUserMedia/)
  assert.match(recorderSource, /new MediaRecorder/)
  assert.match(recorderSource, /new File\(/)
  assert.match(recorderSource, /split\(';'/)
  assert.match(recorderSource, /uni\.getRecorderManager\(\)/)
  assert.match(recorderSource, /tempFilePath/)
  assert.match(pageSource, /createCustomerServiceRecorder/)
  assert.match(pageSource, /type="AUDIO"|emitMessage\('AUDIO'/)
  assert.match(pageSource, /@touchmove\.stop\.prevent="updateRecordingGesture"/)
  assert.match(pageSource, /recording-overlay/)
  assert.match(pageSource, /上滑可取消/)

  const externalUploadSource = requestSource.slice(requestSource.indexOf('export function uploadExternalFile'))
  assert.match(externalUploadSource, /\bfile\b/)
  assert.match(externalUploadSource, /filePath \|\| file/)
})

test('composer and resource candidates trust only the platform projected contract', async () => {
  const composerUrl = new URL('./customer-service-composer.js', import.meta.url)
  assert.equal(existsSync(composerUrl), true)
  const {
    createCustomerServiceClientId,
    isCustomerServiceComposer,
    isCustomerServiceResourceCandidateResult,
    normalizeCustomerServiceComposerActions,
  } = await importSource('./customer-service-composer.js')

  const composer = {
    version: 1,
    conversationId: 'conversation-1',
    actions: [
      { kind: 'MESSAGE', code: 'message.text', placement: 'CORE', enabled: true },
      { kind: 'MESSAGE', code: 'message.emoji', placement: 'INLINE', enabled: true },
      {
        kind: 'ATTACHMENT',
        code: 'attachment.image',
        placement: 'MORE',
        messageType: 'IMAGE',
        accept: ['image/jpeg'],
        maxBytes: 1024,
      },
      {
        kind: 'RESOURCE',
        code: 'resource.catalog',
        placement: 'MORE',
        label: '商品',
        icon: 'package',
        resourceDefinitionCode: 'catalog.entry',
        availability: 'ENABLED',
      },
    ],
    handoff: { enabled: true, status: 'AVAILABLE' },
  }
  assert.equal(isCustomerServiceComposer(composer), true)
  assert.deepEqual(normalizeCustomerServiceComposerActions(composer).resources, [
    {
      code: 'resource.catalog',
      label: '商品',
      icon: 'package',
      resourceDefinitionCode: 'catalog.entry',
      availability: 'ENABLED',
    },
  ])

  assert.equal(isCustomerServiceComposer({ ...composer, actions: [{ ...composer.actions[3], toolId: 'raw-tool' }] }), true)
  assert.equal(isCustomerServiceComposer({ ...composer, handoff: { enabled: true, status: 'UNKNOWN' } }), false)
  assert.equal(isCustomerServiceResourceCandidateResult({
    items: [{
      candidateToken: 'opaque-token',
      resourceDefinitionCode: 'catalog.entry',
      display: { title: '测试商品', status: '上架', summary: '摘要', fields: [], sections: [] },
    }],
    page: { hasMore: false },
  }), true)
  assert.equal(isCustomerServiceResourceCandidateResult({
    items: [{ externalId: '1', display: { title: '绕过可信候选' } }],
    page: { hasMore: false },
  }), false)
  assert.match(createCustomerServiceClientId('resource-search'), /^resource-search:[A-Za-z0-9._:-]+$/)
})

test('visitor resource APIs pass only action codes and opaque candidate tokens', () => {
  assert.match(apiSource, /getCustomerServiceComposer/)
  assert.match(apiSource, /\/composer`/)
  assert.match(apiSource, /searchCustomerServiceResourceCandidates/)
  assert.match(apiSource, /\/resource-candidates\/search`/)
  assert.match(apiSource, /sendCustomerServiceResourceMessage/)
  assert.match(apiSource, /\/resource-messages`/)

  const resourceApiStart = apiSource.indexOf('export const searchCustomerServiceResourceCandidates')
  const resourceApiSource = apiSource.slice(resourceApiStart)
  assert.match(resourceApiSource, /actionCode/)
  assert.match(resourceApiSource, /candidateToken/)
  assert.match(resourceApiSource, /clientMessageId/)
  assert.doesNotMatch(resourceApiSource, /toolId|externalId/)
})

test('handoff stays in the page header and follows the generic platform state', () => {
  assert.match(apiSource, /requestCustomerServiceHandoff/)
  assert.match(apiSource, /\/handoff-requests`/)
  assert.match(pageSource, /class="customer-header__handoff"/)
  assert.match(pageSource, /requestCustomerServiceHandoff/)
  assert.match(pageSource, /socket\.on\('handoff:updated'/)

  const actionPanelStart = pageSource.indexOf('composer-panel composer-panel--actions')
  const actionPanelEnd = pageSource.indexOf('class="composer__main"', actionPanelStart)
  const actionPanel = pageSource.slice(actionPanelStart, actionPanelEnd)
  assert.doesNotMatch(actionPanel, /转人工|排队中|人工服务中/)
})

test('visitor attachments and fallback messages are conversation scoped and idempotent', () => {
  assert.match(apiSource, /\/conversations\/\$\{encodeURIComponent\(conversationId\)\}\/attachments/)
  assert.doesNotMatch(apiSource, /url: '\/uploads'/)

  const sendStart = pageSource.indexOf('function emitMessage')
  const sendEnd = pageSource.indexOf('function openResource', sendStart)
  const sendSource = pageSource.slice(sendStart, sendEnd)
  assert.match(sendSource, /sendCustomerServiceVisitorMessage/)
  assert.match(sendSource, /clientMessageId: createCustomerServiceClientId\('message'\)/)
  assert.doesNotMatch(sendSource, /socket\.emit/)
})

test('resource selection stays in one generic trusted-candidate sheet', () => {
  const pickerUrl = new URL('../components/mb-customer-service-resource-sheet/mb-customer-service-resource-sheet.vue', import.meta.url)
  assert.equal(existsSync(pickerUrl), true)
  const pickerSource = readFileSync(pickerUrl, 'utf8')

  assert.match(pageSource, /<mb-customer-service-resource-sheet/)
  assert.match(pageSource, /@select/)
  assert.match(pageSource, /function switchContextResource/)
  assert.match(pageSource, /@tap\.stop="switchContextResource"/)
  assert.match(pickerSource, /candidateToken/)
  assert.match(pickerSource, /class="resource-picker"/)
  assert.match(pickerSource, /class="resource-picker__search"/)
  assert.match(pickerSource, /function confirmSelection/)
  assert.match(pickerSource, /发送给客服/)
  assert.match(pickerSource, /emit\('select'/)
  assert.doesNotMatch(pickerSource, /toolId|externalId/)
  assert.doesNotMatch(pagesSource, /customer-service\/(product|order)/)
})

test('REST fallback keeps messages usable while the realtime socket is unavailable', () => {
  const canSendStart = pageSource.indexOf('const canSend = computed')
  const canSendEnd = pageSource.indexOf('const canSendText', canSendStart)
  const canSendSource = pageSource.slice(canSendStart, canSendEnd)

  assert.doesNotMatch(canSendSource, /socketConnected|conversationJoined/)
  assert.match(pageSource, /startMessagePolling/)
  assert.match(pageSource, /loadMessages\(\)/)
  assert.match(socketSource, /reconnectionAttempts: 5/)
})

test('short-lived context handoff never places credentials in the page URL', () => {
  assert.match(entrySource, /mallbase_customer_service_launch:current/)
  assert.match(entrySource, /uni\.removeStorageSync\(CUSTOMER_SERVICE_LAUNCH_KEY\)/)
  assert.match(entrySource, /url: '\/pages-sub\/customer-service\/index'/)
  assert.doesNotMatch(entrySource, /contextToken[^\n]*encodeURIComponent/)
  assert.doesNotMatch(pageSource, /setStorageSync|visitorToken[^\n]*url/)
})

test('system customer service requires login and never accepts a client conversation key', () => {
  assert.match(entrySource, /requireCustomerServiceLogin/)
  assert.match(entrySource, /if \(!requireCustomerServiceLogin\(\)\) return false/)
  assert.match(entrySource, /if \(!isLoggedIn\(\)\) return false/)
  assert.doesNotMatch(entrySource, /conversation_key|options\.conversationKey/)
  assert.match(pageSource, /ensureCustomerServiceLogin/)
  assert.match(pageSource, /clearCustomerServiceLaunch/)
  assert.match(pageSource, /url: '\/pages-sub\/user\/login'/)
  assert.match(requestSource, /UNAUTHORIZED_REDIRECT_DEDUP_MS/)
  assert.match(requestSource, /now - lastUnauthorizedRedirectAt/)
})

test('concurrent unauthorized responses schedule only one login navigation', () => {
  const harness = createUnauthorizedHarness()
  harness.handleUnauthorized()
  harness.handleUnauthorized()

  assert.equal(harness.navigateCalls.length, 1)
  assert.equal(harness.authClearedEvents.length, 2)
  assert.deepEqual(harness.removedKeys, [
    'mb_access_token',
    'mb_refresh_token',
    'mb_access_token',
    'mb_refresh_token',
  ])
})

test('customer service unauthorized redirect never restores an old visitor session', () => {
  const harness = createUnauthorizedHarness('pages-sub/customer-service/index')
  harness.handleUnauthorized()

  assert.equal(harness.navigateCalls.length, 1)
  assert.equal(harness.navigateCalls[0].url, '/pages-sub/user/login')
  assert.equal(harness.authClearedEvents.length, 1)
  assert.match(pageSource, /uni\.\$on\(AUTH_CLEARED_EVENT, handleAuthCleared\)/)
  assert.match(pageSource, /getAuthSessionId\(\) !== authSessionId/)
  assert.match(pageSource, /登录状态已变化，请返回原页面重新进入客服/)
})

test('auth session identity survives token refresh and rotates on a new login', async () => {
  const storage = new Map([['mb_access_token', 'access-token-1']])
  const emitted = []
  globalThis.uni = {
    getStorageSync(key) {
      return storage.get(key) || ''
    },
    setStorageSync(key, value) {
      storage.set(key, value)
    },
    removeStorageSync(key) {
      storage.delete(key)
    },
    $emit(event) {
      emitted.push(event)
    },
  }

  try {
    const {
      getAuthSessionId,
      notifyAuthCleared,
      rotateAuthSessionId,
    } = await importSource('./auth.js')
    const originalSessionId = getAuthSessionId()
    storage.set('mb_access_token', 'refreshed-access-token')
    assert.equal(getAuthSessionId(), originalSessionId)

    const nextSessionId = rotateAuthSessionId()
    assert.notEqual(nextSessionId, originalSessionId)
    notifyAuthCleared()
    assert.equal(storage.has('mb_auth_session_id'), false)
    assert.deepEqual(emitted, ['mallbase:auth-cleared'])
  } finally {
    delete globalThis.uni
  }

  assert.match(authSource, /const AUTH_SESSION_KEY = 'mb_auth_session_id'/)
})

test('socket rejoin reloads messages missed while disconnected', () => {
  const joinedStart = pageSource.indexOf("socket.on('conversation:joined'")
  const joinedEnd = pageSource.indexOf("socket.on('message:new'", joinedStart)
  const joinedHandler = pageSource.slice(joinedStart, joinedEnd)
  assert.match(joinedHandler, /loadMessages\(\)/)
  assert.match(pageSource, /reloadMessagesPending = true/)
  assert.match(pageSource, /if \(reloadMessagesPending && pageVisible && !destroyed && sessionReady\.value\)/)
})

test('hidden customer service page disconnects the visitor socket', () => {
  const hideStart = pageSource.indexOf('onHide(() =>')
  const hideEnd = pageSource.indexOf('onUnload(() =>', hideStart)
  const hideHandler = pageSource.slice(hideStart, hideEnd)
  assert.match(hideHandler, /pageVisible = false/)
  assert.match(hideHandler, /reloadMessagesPending = false/)
  assert.match(hideHandler, /disconnectSocket\(\)/)

  const showStart = pageSource.indexOf('onShow(() =>')
  const showEnd = pageSource.indexOf('onHide(() =>', showStart)
  const showHandler = pageSource.slice(showStart, showEnd)
  assert.match(showHandler, /if \(!socket\) connectSocket\(\)/)
  assert.match(pageSource, /if \(pageVisible\) \{[\s\S]*if \(!socket\) connectSocket\(\)/)
})

test('external protocol uses isolated DTO validation and UniApp Socket transport', () => {
  const externalRequestSource = requestSource.slice(requestSource.indexOf('function normalizeExternalBaseUrl'))
  assert.match(externalRequestSource, /typeof validate !== 'function'/)
  assert.match(externalRequestSource, /error\.statusCode/)
  assert.match(externalRequestSource, /error\.responseBody/)
  assert.doesNotMatch(externalRequestSource, /Authorization|Bearer/)
  assert.match(apiSource, /x-visitor-token/)
  assert.match(socketSource, /uni\.connectSocket/)
  assert.match(socketSource, /path: '\/socket\.io'/)
  assert.match(socketSource, /autoConnect: false/)
})

test('Widget URL is no longer a client configuration requirement', () => {
  const configuredSource = serviceSource.slice(
    serviceSource.indexOf('private function isClientConfigured'),
    serviceSource.indexOf('private function disabledPayload'),
  )
  assert.match(configuredSource, /apiBase\(\) !== ''/)
  assert.match(configuredSource, /socketBase\(\) !== ''/)
  assert.doesNotMatch(configuredSource, /widgetUrl/)
  assert.doesNotMatch(adminSource, /customer_service_widget_url|Widget 地址/)
})

test('resource routes accept only local product and order ids', async () => {
  const {
    buildCustomerServiceResourceRoute,
    normalizeCustomerServiceConversationResource,
    parseCustomerServiceResourceCard,
  } = await importSource('./customer-service-resource.js')

  assert.equal(buildCustomerServiceResourceRoute('goods', '12'), '/pages-sub/goods/detail?id=12')
  assert.equal(buildCustomerServiceResourceRoute('order', 34), '/pages-sub/order/detail?id=34')
  assert.equal(buildCustomerServiceResourceRoute('product', '../settings'), '')
  assert.equal(buildCustomerServiceResourceRoute('url', '12'), '')

  assert.deepEqual(
    normalizeCustomerServiceConversationResource({
      type: 'product',
      externalId: '8',
      title: '测试商品',
      url: 'https://untrusted.example/path',
    }),
    {
      type: 'product',
      externalId: '8',
      label: '商品',
      title: '测试商品',
      summary: '',
      price: '',
      imageUrl: '',
      route: '/pages-sub/goods/detail?id=8',
    },
  )

  const card = parseCustomerServiceResourceCard(JSON.stringify({
    resourceType: 'order',
    externalId: '9',
    title: '测试订单',
    url: 'https://untrusted.example/path',
    action: { code: 'DELETE_ANYTHING', params: { externalId: '9' } },
  }))
  assert.equal(card.route, '/pages-sub/order/detail?id=9')
  assert.equal('url' in card, false)
  assert.equal('action' in card, false)

  const projectedCard = parseCustomerServiceResourceCard(JSON.stringify({
    version: 2,
    resourceType: 'product',
    externalId: '10',
    title: '可信商品卡片',
    display: {
      title: '可信商品卡片',
      status: '上架',
      summary: '平台投影摘要',
      fields: [
        { key: 'price', label: '价格', value: '88.00', valueType: 'money' },
      ],
      sections: [],
      canvas: {
        components: [
          { type: 'image', src: 'https://example.com/product.png' },
        ],
      },
    },
  }))
  assert.equal(projectedCard.price, '88.00')
  assert.equal(projectedCard.imageUrl, 'https://example.com/product.png')
  assert.equal(projectedCard.route, '/pages-sub/goods/detail?id=10')
})

test('native design documents runtime and mini-program boundaries', () => {
  assert.match(designSource, /H5 和微信小程序/)
  assert.match(designSource, /request.*uploadFile.*socket/s)
  assert.match(designSource, /visitorToken.*内存/s)
  assert.match(designSource, /WebRTC/)
})
