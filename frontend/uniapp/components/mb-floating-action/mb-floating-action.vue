<template>
  <view
    v-if="visible && positionReady"
    class="mb-floating-action"
    :class="[
      `mb-floating-action--${currentSide}`,
      {
        'mb-floating-action--dragging': dragging,
        'mb-floating-action--open': menuOpened,
        'mb-floating-action--position-settled': positionSettled,
        'mb-floating-action--single': isSingleMode,
      },
    ]"
    :style="rootStyle"
  >
    <view v-if="!isSingleMode" class="mb-floating-action__menu">
      <view
        v-for="item in actionItems"
        :key="item.id"
        class="mb-floating-action__item"
        @tap.stop="handleItemTap(item)"
      >
        <image
          v-if="getUploadedIcon(item)"
          class="mb-floating-action__icon"
          :src="getUploadedIcon(item)"
          mode="aspectFit"
        />
        <image
          v-else-if="getPresetIcon(item)"
          class="mb-floating-action__icon mb-floating-action__icon--preset"
          :src="getPresetIcon(item)"
          mode="aspectFit"
        />
      </view>
    </view>

    <view
      class="mb-floating-action__main"
      @tap.stop="handleMainTap"
      @touchstart.stop="handleTouchStart"
      @touchmove.stop.prevent="handleTouchMove"
      @touchend.stop="handleTouchEnd"
      @touchcancel.stop="handleTouchEnd"
      @mousedown.stop.prevent="handleMouseStart"
    >
      <template v-if="isSingleMode && mainItem">
        <image
          v-if="getUploadedIcon(mainItem)"
          class="mb-floating-action__icon"
          :src="getUploadedIcon(mainItem)"
          mode="aspectFit"
        />
        <image
          v-else-if="getPresetIcon(mainItem)"
          class="mb-floating-action__icon mb-floating-action__icon--preset"
          :src="getPresetIcon(mainItem)"
          mode="aspectFit"
        />
      </template>
      <image
        v-else
        class="mb-floating-action__icon mb-floating-action__icon--preset mb-floating-action__icon--main"
        :src="getMainIcon()"
        mode="aspectFit"
      />
    </view>
  </view>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useDecorateStore } from '@/store/decorate'
import appConfig from '@/config'
import {
  normalizeAssetPath,
  normalizeRoutePathForMatch,
  openDecorateLink,
} from '@/utils/decorate'
import { openCustomerService } from '@/utils/customer-service'

const POSITION_STORAGE_KEY = 'mb_floating_action_position'
const FLOATING_ICON_BASE = '/static/decorate/floating'
const LEGACY_FLOATING_ICON_BASES = [
  '/static/client/floating',
  '/static/images/floating',
]
const POSITION_SETTLE_DELAY_MS = 32

const decorateStore = useDecorateStore()
const opened = ref(false)
const currentPath = ref('')
const currentSide = ref('right')
const dragging = ref(false)
const dragMoved = ref(false)
const ignoreNextTap = ref(false)
const positionReady = ref(false)
const positionSettled = ref(false)
const dragPosition = ref({ x: 0, y: 0 })
const viewport = ref({ height: 667, safeBottom: 0, width: 375 })
const touchState = ref({
  startClientX: 0,
  startClientY: 0,
  startX: 0,
  startY: 0,
})
let dragFrame = 0
let pendingDragPosition = null
let positionSettleTimer = 0

const config = computed(() => decorateStore.floatingConfig || {})

const actionItems = computed(() => {
  const items = Array.isArray(config.value.items) ? config.value.items : []
  return items.filter(
    (item) =>
      item &&
      item.enabled !== false &&
      (getUploadedIcon(item) || getPresetIcon(item)),
  )
})

const isSingleMode = computed(() => config.value.mode === 'single')

const menuOpened = computed(() => !isSingleMode.value && opened.value)

const visible = computed(() => {
  if (config.value.enabled === false) return false
  if (actionItems.value.length === 0) return false
  const hiddenPages = Array.isArray(config.value.hiddenPages)
    ? config.value.hiddenPages
    : []
  const currentRoutePath = normalizeRoutePathForMatch(currentPath.value)
  return !hiddenPages
    .map((path) => normalizeRoutePathForMatch(path))
    .filter(Boolean)
    .includes(currentRoutePath)
})

const mainItem = computed(() => {
  if (isSingleMode.value && config.value.singleItemId) {
    return (
      actionItems.value.find((item) => item.id === config.value.singleItemId) ||
      actionItems.value[0] ||
      null
    )
  }
  return actionItems.value[0] || null
})

const visualStyle = computed(() => {
  const style = config.value.style || {}
  const size = Number(style.size || 88)
  const radius = Number(style.radius ?? size / 2)
  return {
    background: style.backgroundColor || 'var(--color-primary, #0d50d5)',
    color: style.color || 'var(--color-text-on-primary, #ffffff)',
    radius,
    shadow: floatingShadowStyle(style),
    size,
  }
})

const rootStyle = computed(() => {
  const style = {
    '--mb-floating-bg': visualStyle.value.background,
    '--mb-floating-color': visualStyle.value.color,
    '--mb-floating-radius': `${visualStyle.value.radius}rpx`,
    '--mb-floating-shadow': visualStyle.value.shadow,
    '--mb-floating-size': `${visualStyle.value.size}rpx`,
  }
  if (positionReady.value) {
    return {
      ...style,
      left: '0px',
      top: '0px',
      transform: `translate3d(${Math.round(dragPosition.value.x)}px, ${Math.round(dragPosition.value.y)}px, 0)`,
    }
  }
  const side = config.value.position === 'left-bottom' ? 'left' : 'right'
  return {
    ...style,
    [side]: `${Number(config.value.offsetX || 24)}rpx`,
    bottom: `calc(${Number(config.value.offsetBottom || 160)}rpx + env(safe-area-inset-bottom))`,
  }
})

watch(
  () => [
    visible.value,
    config.value.offsetBottom,
    config.value.offsetX,
    config.value.position,
    config.value.style?.size,
  ],
  () => {
    if (!visible.value) {
      opened.value = false
      positionReady.value = false
      positionSettled.value = false
      cancelPositionSettle()
      return
    }
    nextTick(() => syncPosition(true))
  },
  { immediate: true },
)

onShow(() => {
  syncCurrentPath()
  opened.value = false
  nextTick(() => {
    if (visible.value) syncPosition(true)
  })
})

onMounted(() => {
  syncCurrentPath()
  syncViewport()
  if (visible.value) syncPosition(true)
})

function syncCurrentPath() {
  const pages = getCurrentPages()
  const current = pages[pages.length - 1]
  currentPath.value = current?.$page?.fullPath || current?.route || ''
}

function syncViewport() {
  const info = uni.getSystemInfoSync()
  const width = Number(info.windowWidth || info.screenWidth || 375)
  const height = Number(info.windowHeight || info.screenHeight || 667)
  const safeBottom =
    Number(info.safeAreaInsets?.bottom || 0) ||
    Math.max(
      0,
      Number(info.screenHeight || height) - Number(info.safeArea?.bottom || height),
    )
  viewport.value = { height, safeBottom, width }
}

function syncPosition(preferStorage = true) {
  cancelPositionSettle()
  positionSettled.value = false
  syncViewport()
  const stored = preferStorage ? readStoredPosition() : null
  const nextPosition = stored || getDefaultPosition()
  applyPosition(nextPosition.x, nextPosition.y)
  currentSide.value =
    dragPosition.value.x + mainButtonSizePx() / 2 < viewport.value.width / 2
      ? 'left'
      : 'right'
  positionReady.value = true
  schedulePositionSettle()
}

function getDefaultPosition() {
  const size = mainButtonSizePx()
  const edge = edgeOffsetPx()
  const bottom = bottomGuardPx()
  const side = config.value.position === 'left-bottom' ? 'left' : 'right'
  currentSide.value = side
  return {
    x: side === 'left' ? edge : viewport.value.width - size - edge,
    y: viewport.value.height - size - bottom,
  }
}

function applyPosition(x, y) {
  dragPosition.value = clampPosition(x, y)
}

function clampPosition(x, y) {
  const size = mainButtonSizePx()
  const edge = edgeOffsetPx()
  const top = rpxToPx(24)
  const maxX = Math.max(edge, viewport.value.width - size - edge)
  const maxY = Math.max(
    top,
    viewport.value.height - size - bottomGuardPx(),
  )
  return {
    x: Math.max(edge, Math.min(Number(x || edge), maxX)),
    y: Math.max(top, Math.min(Number(y || top), maxY)),
  }
}

function snapToEdge() {
  const size = mainButtonSizePx()
  const edge = edgeOffsetPx()
  const nextSide =
    dragPosition.value.x + size / 2 < viewport.value.width / 2
      ? 'left'
      : 'right'
  currentSide.value = nextSide
  const x = nextSide === 'left' ? edge : viewport.value.width - size - edge
  applyPosition(x, dragPosition.value.y)
  savePosition()
}

function readStoredPosition() {
  try {
    const value = uni.getStorageSync(POSITION_STORAGE_KEY)
    if (!value || typeof value !== 'object') return null
    if (!Number.isFinite(Number(value.x)) || !Number.isFinite(Number(value.y))) {
      return null
    }
    return {
      side: value.side === 'left' ? 'left' : 'right',
      x: Number(value.x),
      y: Number(value.y),
    }
  } catch (error) {
    void error
    return null
  }
}

function savePosition() {
  try {
    uni.setStorageSync(POSITION_STORAGE_KEY, {
      side: currentSide.value,
      x: dragPosition.value.x,
      y: dragPosition.value.y,
    })
  } catch (error) {
    void error
  }
}

function mainButtonSizePx() {
  return rpxToPx(visualStyle.value.size)
}

function edgeOffsetPx() {
  return Math.max(rpxToPx(16), rpxToPx(Number(config.value.offsetX || 24)))
}

function bottomGuardPx() {
  const configuredBottom =
    rpxToPx(Number(config.value.offsetBottom || 160)) + viewport.value.safeBottom
  const tabbarBottom = rpxToPx(128) + viewport.value.safeBottom
  return Math.max(configuredBottom, tabbarBottom)
}

function rpxToPx(value) {
  return (Number(value || 0) * viewport.value.width) / 750
}

function clampNumber(value, fallback, min, max) {
  const number = Number(value ?? fallback)
  const normalized = Number.isFinite(number) ? number : fallback
  return Math.max(min, Math.min(max, normalized))
}

function hexToRgba(value, opacity) {
  const color =
    typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value)
      ? value
      : '#0f172a'
  const alpha = clampNumber(opacity, 14, 0, 100) / 100
  const red = Number.parseInt(color.slice(1, 3), 16)
  const green = Number.parseInt(color.slice(3, 5), 16)
  const blue = Number.parseInt(color.slice(5, 7), 16)
  return `rgba(${red}, ${green}, ${blue}, ${alpha})`
}

function floatingShadowStyle(style) {
  if (style.shadowEnabled === false) return 'none'
  const offsetX = clampNumber(
    style.shadowOffsetX ?? style.shadow_offset_x,
    0,
    -80,
    80,
  )
  const offsetY = clampNumber(
    style.shadowOffsetY ?? style.shadow_offset_y,
    12,
    -80,
    80,
  )
  const blur = clampNumber(style.shadowBlur ?? style.shadow_blur, 30, 0, 160)
  const spread = clampNumber(
    style.shadowSpread ?? style.shadow_spread,
    0,
    -80,
    80,
  )
  const color = hexToRgba(
    style.shadowColor ?? style.shadow_color,
    style.shadowOpacity ?? style.shadow_opacity,
  )
  return `${offsetX}rpx ${offsetY}rpx ${blur}rpx ${spread}rpx ${color}`
}

function normalizeFloatingIconPath(value) {
  let normalized = normalizeAssetPath(value)
  if (!normalized) return ''
  const matchedLegacyBase = LEGACY_FLOATING_ICON_BASES.find((base) =>
    normalized.startsWith(base),
  )
  if (matchedLegacyBase) {
    normalized = normalized
      .replace(matchedLegacyBase, FLOATING_ICON_BASE)
      .replace(/\.svg(?:[?#].*)?$/i, '.png')
  }
  if (
    normalized.startsWith('/static/') &&
    appConfig.baseUrl &&
    !/^(?:https?:)?\/\//.test(normalized)
  ) {
    return `${String(appConfig.baseUrl).replace(/\/$/, '')}${normalized}`
  }
  return normalized
}

function isSystemFloatingIcon(value) {
  const normalized = normalizeFloatingIconPath(value)
  return normalized.includes('/static/decorate/floating/')
}

function getUploadedIcon(item) {
  return isSystemFloatingIcon(item?.icon)
    ? ''
    : normalizeFloatingIconPath(item?.icon || '')
}

function getPresetIcon(item) {
  if (isSystemFloatingIcon(item?.icon)) {
    return normalizeFloatingIconPath(item.icon)
  }
  const type = getSystemPresetType(item)
  const map = {
    cart: `${FLOATING_ICON_BASE}/cart.png`,
    home: `${FLOATING_ICON_BASE}/home.png`,
    service: `${FLOATING_ICON_BASE}/service.png`,
  }
  return type ? normalizeFloatingIconPath(map[type] || '') : ''
}

function getMainIcon() {
  return currentSide.value === 'right'
    ? normalizeFloatingIconPath(`${FLOATING_ICON_BASE}/collapse-left.png`)
    : normalizeFloatingIconPath(`${FLOATING_ICON_BASE}/collapse-right.png`)
}

function getSystemPresetType(item) {
  const id = String(item?.id || item?.key || '')
  const map = {
    'floating-cart': 'cart',
    'floating-home': 'home',
    'floating-service': 'service',
  }
  if (map[id]) return map[id]

  const text = String(item?.text || '').trim()
  const path = String(item?.path || '')
    .split(/[?#]/)[0]
    .replace(/\/+$/, '')
  if (item?.type === 'customerService' && text === '客服') return 'service'
  if (item?.type === 'page' && text === '购物车' && path === '/pages/cart/index') {
    return 'cart'
  }
  if (item?.type === 'page' && text === '首页' && path === '/pages/index/index') {
    return 'home'
  }
  return ''
}

function handleTouchStart(event) {
  const touch = event.touches?.[0]
  if (!touch) return
  startDrag(touch.clientX, touch.clientY)
}

function handleTouchMove(event) {
  if (!dragging.value) return
  const touch = event.touches?.[0]
  if (!touch) return
  moveDrag(touch.clientX, touch.clientY)
}

function handleTouchEnd() {
  endDrag()
}

function handleMouseStart(event) {
  if (event.button !== undefined && event.button !== 0) return
  startDrag(event.clientX, event.clientY)
  if (typeof window === 'undefined') return
  window.addEventListener('mousemove', handleMouseMove, { passive: false })
  window.addEventListener('mouseup', handleMouseEnd)
}

function handleMouseMove(event) {
  event.preventDefault?.()
  moveDrag(event.clientX, event.clientY)
}

function handleMouseEnd() {
  removeMouseListeners()
  endDrag()
}

function removeMouseListeners() {
  if (typeof window === 'undefined') return
  window.removeEventListener('mousemove', handleMouseMove)
  window.removeEventListener('mouseup', handleMouseEnd)
}

function startDrag(clientX, clientY) {
  if (!positionReady.value) syncPosition(true)
  dragging.value = true
  dragMoved.value = false
  touchState.value = {
    startClientX: Number(clientX || 0),
    startClientY: Number(clientY || 0),
    startX: dragPosition.value.x,
    startY: dragPosition.value.y,
  }
}

function moveDrag(clientX, clientY) {
  if (!dragging.value) return
  const dx = Number(clientX || 0) - touchState.value.startClientX
  const dy = Number(clientY || 0) - touchState.value.startClientY
  if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
    dragMoved.value = true
    opened.value = false
  }
  scheduleDragPosition(touchState.value.startX + dx, touchState.value.startY + dy)
}

function endDrag() {
  if (!dragging.value) return
  flushDragPosition()
  dragging.value = false
  if (dragMoved.value) {
    snapToEdge()
    ignoreNextTap.value = true
  }
}

onBeforeUnmount(() => {
  removeMouseListeners()
  cancelDragFrame()
  cancelPositionSettle()
})

function schedulePositionSettle() {
  nextTick(() => {
    cancelPositionSettle()
    positionSettleTimer = setTimeout(() => {
      positionSettleTimer = 0
      positionSettled.value = true
    }, POSITION_SETTLE_DELAY_MS)
  })
}

function cancelPositionSettle() {
  if (!positionSettleTimer) return
  clearTimeout(positionSettleTimer)
  positionSettleTimer = 0
}

function scheduleDragPosition(x, y) {
  pendingDragPosition = clampPosition(x, y)
  if (dragFrame) return

  dragFrame = requestDragFrame(() => {
    dragFrame = 0
    if (!pendingDragPosition) return
    dragPosition.value = pendingDragPosition
    pendingDragPosition = null
  })
}

function flushDragPosition() {
  if (dragFrame) {
    cancelDragFrame()
  }
  if (pendingDragPosition) {
    dragPosition.value = pendingDragPosition
    pendingDragPosition = null
  }
}

function cancelDragFrame() {
  if (!dragFrame) return
  if (typeof cancelAnimationFrame === 'function') {
    cancelAnimationFrame(dragFrame)
  } else {
    clearTimeout(dragFrame)
  }
  dragFrame = 0
}

function requestDragFrame(callback) {
  if (typeof requestAnimationFrame === 'function') {
    return requestAnimationFrame(callback)
  }
  return setTimeout(callback, 16)
}

function handleMainTap() {
  if (ignoreNextTap.value) {
    ignoreNextTap.value = false
    return
  }
  if (isSingleMode.value) {
    handleItemTap(mainItem.value)
    return
  }
  opened.value = !opened.value
}

async function handleItemTap(item) {
  if (!item) return
  opened.value = false
  if (item.type === 'customerService') {
    await openCustomerService()
    return
  }
  if (item.path) {
    openDecorateLink(item.path)
  }
}
</script>

<style lang="scss" scoped>
.mb-floating-action {
  position: fixed;
  z-index: 990;
  width: var(--mb-floating-size);
  height: var(--mb-floating-size);
  color: var(--mb-floating-color);
  transition: none;
  will-change: transform;
}

.mb-floating-action--position-settled {
  transition: transform 0.2s ease;
}

.mb-floating-action--dragging {
  transition: none;
}

.mb-floating-action__main,
.mb-floating-action__menu {
  background: var(--mb-floating-bg);
  box-shadow: var(--mb-floating-shadow);
}

.mb-floating-action__main {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  width: var(--mb-floating-size);
  height: var(--mb-floating-size);
  overflow: hidden;
  border-radius: var(--mb-floating-radius);
  cursor: grab;
  user-select: none;
}

.mb-floating-action--dragging .mb-floating-action__main {
  cursor: grabbing;
}

.mb-floating-action__menu {
  position: absolute;
  top: 0;
  display: flex;
  align-items: center;
  gap: 8rpx;
  height: var(--mb-floating-size);
  padding: 0 18rpx;
  pointer-events: none;
  border-radius: 999rpx;
  opacity: 0;
  transform: scaleX(0.78);
  transition:
    opacity 0.18s ease,
    transform 0.18s ease;
}

.mb-floating-action--right .mb-floating-action__menu {
  right: calc(var(--mb-floating-size) + 16rpx);
  transform-origin: right center;
}

.mb-floating-action--left .mb-floating-action__menu {
  left: calc(var(--mb-floating-size) + 16rpx);
  transform-origin: left center;
}

.mb-floating-action--open .mb-floating-action__menu {
  pointer-events: auto;
  opacity: 1;
  transform: scaleX(1);
}

.mb-floating-action__item {
  display: flex;
  align-items: center;
  justify-content: center;
  width: var(--mb-floating-size);
  height: var(--mb-floating-size);
  flex: 0 0 auto;
}

.mb-floating-action__icon {
  width: 48%;
  height: 48%;
}

.mb-floating-action__icon--preset {
  opacity: 0.96;
  filter: brightness(0) invert(1);
}

.mb-floating-action__icon--main {
  width: 54%;
  height: 54%;
}
</style>
