import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const componentUrl = new URL('./mb-floating-action.vue', import.meta.url)
const source = await readFile(componentUrl, 'utf8')

test('drag handle owns pointer gestures', () => {
  assert.match(
    source,
    /\.mb-floating-action__main\s*\{[^}]*touch-action:\s*none;/s,
  )
  assert.doesNotMatch(
    source,
    /\.mb-floating-action__icon\s*\{[^}]*pointer-events:\s*none;/s,
  )
})

test('H5 does not keep a competing mouse drag path', () => {
  assert.doesNotMatch(source, /@mousedown/)
  assert.doesNotMatch(source, /addEventListener\('mousemove'/)
  assert.doesNotMatch(source, /addEventListener\('mouseup'/)
})

test('pointer drag continues on window when crossing swiper', () => {
  assert.doesNotMatch(source, /@pointerdown/)
  assert.match(
    source,
    /addEventListener\('pointerdown',\s*handlePointerStart,\s*\{[^}]*capture:\s*true[^}]*\}\)/s,
  )
  assert.match(source, /closest\?\.\('\.mb-floating-action__main'\)/)
  assert.match(
    source,
    /function handlePointerStart\(event\)[\s\S]*?event\.preventDefault\?\.\(\)[\s\S]*?event\.stopPropagation\?\.\(\)/,
  )
  assert.match(
    source,
    /if \(activePointerId !== null \|\| event\.isPrimary === false\) return/,
  )
  assert.match(
    source,
    /if \(\s*!Number\.isFinite\(pointerId\) \|\|\s*!Number\.isFinite\(clientX\) \|\|\s*!Number\.isFinite\(clientY\)\s*\) \{\s*return\s*\}/,
  )
  assert.match(
    source,
    /function handlePointerMove\(event\)[\s\S]*?event\.stopPropagation\?\.\(\)[\s\S]*?moveDrag/,
  )
  assert.match(
    source,
    /addEventListener\('pointermove',\s*handlePointerMove,\s*\{[^}]*capture:\s*true[^}]*\}\)/s,
  )
  assert.match(
    source,
    /addEventListener\('pointerup',\s*handlePointerEnd,\s*true\)/,
  )
  assert.match(
    source,
    /addEventListener\('pointercancel',\s*handlePointerEnd,\s*true\)/,
  )
  assert.match(
    source,
    /removeEventListener\('pointermove',\s*handlePointerMove,\s*true\)/,
  )
  assert.match(
    source,
    /removeEventListener\('pointerup',\s*handlePointerEnd,\s*true\)/,
  )
  assert.match(
    source,
    /removeEventListener\('pointercancel',\s*handlePointerEnd,\s*true\)/,
  )
  assert.match(
    source,
    /removeEventListener\('pointerdown',\s*handlePointerStart,\s*true\)/,
  )
  assert.match(
    source,
    /if \(event\.type === 'pointerup' && !wasMoved\) \{\s*activateMainAction\(\)/,
  )
})

test('mini-program touchend owns activation without a competing tap path', () => {
  assert.doesNotMatch(source, /@tap\.stop="handleMainTap"/)
  assert.doesNotMatch(source, /ignoreNextTap/)
  assert.match(source, /@touchcancel\.stop="handleTouchCancel"/)
  assert.match(
    source,
    /function handleTouchStart\(event\)[\s\S]*?startDrag\(touch\.clientX, touch\.clientY\)/,
  )
  assert.match(
    source,
    /function handleTouchEnd\(\) \{[\s\S]*?if \(!dragging\.value\) return[\s\S]*?const wasMoved = dragMoved\.value[\s\S]*?endDrag\(\)[\s\S]*?if \(!wasMoved\) \{[\s\S]*?activateMainAction\(\)/,
  )
  assert.match(
    source,
    /function handleTouchCancel\(\) \{[\s\S]*?endDrag\(\)\s*\}/,
  )
})

test('drag transform preserves subpixel coordinates', () => {
  assert.match(
    source,
    /translate3d\(\$\{dragPosition\.value\.x\}px, \$\{dragPosition\.value\.y\}px, 0\)/,
  )
})

test('H5 does not derive safe bottom from screen coordinates', () => {
  assert.match(source, /const info = uni\.getWindowInfo\(\)/)
  assert.doesNotMatch(source, /uni\.getSystemInfoSync\(\)/)
  assert.match(
    source,
    /let safeBottom = Number\(info\.safeAreaInsets\?\.bottom \|\| 0\)[\s\S]*?\/\/ #ifndef H5[\s\S]*?info\.screenHeight[\s\S]*?info\.safeArea\?\.bottom[\s\S]*?\/\/ #endif/,
  )
})
