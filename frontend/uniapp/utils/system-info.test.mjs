import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const source = readFileSync(new URL('./system-info.js', import.meta.url), 'utf8')

async function importSystemInfo() {
  const encoded = Buffer.from(source).toString('base64')
  return import(`data:text/javascript;base64,${encoded}#${Date.now()}-${Math.random()}`)
}

test('system information uses the focused non-deprecated UniApp APIs', async () => {
  const calls = []
  globalThis.uni = {
    getWindowInfo() {
      calls.push('window')
      return { statusBarHeight: 24, windowWidth: 390 }
    },
    getAppBaseInfo() {
      calls.push('app')
      return { theme: 'dark' }
    },
  }

  try {
    const { getUniAppBaseInfo, getUniWindowInfo } = await importSystemInfo()
    assert.deepEqual(getUniWindowInfo(), { statusBarHeight: 24, windowWidth: 390 })
    assert.deepEqual(getUniAppBaseInfo(), { theme: 'dark' })
    assert.deepEqual(calls, ['window', 'app'])
    assert.doesNotMatch(source, /getSystemInfoSync/)
  } finally {
    delete globalThis.uni
  }
})

test('system information falls back to empty records when APIs are unavailable', async () => {
  globalThis.uni = {}
  try {
    const { getUniAppBaseInfo, getUniWindowInfo } = await importSystemInfo()
    assert.deepEqual(getUniWindowInfo(), {})
    assert.deepEqual(getUniAppBaseInfo(), {})
  } finally {
    delete globalThis.uni
  }
})
