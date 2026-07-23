import assert from 'node:assert/strict'
import {
  readFileSync,
  readdirSync,
  statSync,
} from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const utilsDir = dirname(fileURLToPath(import.meta.url))
const uniappDir = dirname(utilsDir)
const pickerPath = join(utilsDir, 'image-picker.js')
const pickerSource = readFileSync(pickerPath, 'utf8')

function source(path) {
  return readFileSync(path, 'utf8')
}

function applicationSourceFiles(directory = uniappDir) {
  const files = []
  for (const entry of readdirSync(directory)) {
    if (['dist', 'node_modules', 'unpackage'].includes(entry)) continue
    const path = join(directory, entry)
    if (statSync(path).isDirectory()) {
      files.push(...applicationSourceFiles(path))
      continue
    }
    if (/\.(?:js|mjs|vue|scss)$/.test(entry) && !entry.endsWith('.test.mjs')) {
      files.push(path)
    }
  }
  return files
}

async function importPicker() {
  const encoded = Buffer.from(pickerSource).toString('base64')
  return import(`data:text/javascript;base64,${encoded}#${Date.now()}-${Math.random()}`)
}

test('image picker normalizes WeChat and H5 result shapes', async () => {
  const { normalizeImagePickerResult } = await importPicker()
  assert.deepEqual(
    normalizeImagePickerResult({
      tempFiles: [
        { tempFilePath: 'wx://image-one', size: 120 },
        { path: 'blob:h5-image-two', size: 240 },
      ],
    }, 2),
    [
      { path: 'wx://image-one', size: 120 },
      { path: 'blob:h5-image-two', size: 240 },
    ],
  )
  assert.deepEqual(
    normalizeImagePickerResult({
      tempFilePaths: ['file://image-one', '', 'file://image-three'],
    }, 3),
    [
      { path: 'file://image-one', size: 0 },
      { path: 'file://image-three', size: 0 },
    ],
  )

  const h5File = new Blob(['mallbase'], { type: 'image/png' })
  assert.deepEqual(
    normalizeImagePickerResult({
      tempFiles: [h5File],
      tempFilePaths: ['blob:h5-file'],
    }, 1),
    [
      { path: 'blob:h5-file', size: 8, file: h5File },
    ],
  )
})

test('WeChat image selection is isolated behind a cross-platform adapter', () => {
  assert.match(pickerSource, /\/\/ #ifdef MP-WEIXIN/)
  assert.match(pickerSource, /uni\.chooseMedia/)
  assert.match(pickerSource, /mediaType:\s*\['image'\]/)
  assert.match(pickerSource, /sourceType,\s*\n\s*sizeType,/)
  assert.match(pickerSource, /\/\/ #ifndef MP-WEIXIN/)
  assert.match(pickerSource, /uni\.chooseImage/)

  const directCalls = applicationSourceFiles()
    .filter((path) => path !== pickerPath)
    .filter((path) => /\buni\.chooseImage\s*\(/.test(source(path)))
    .map((path) => relative(uniappDir, path))
  assert.deepEqual(directCalls, [])
})

test('application source avoids deprecated system APIs and redundant uni.scss imports', () => {
  const deprecated = []
  const redundantImports = []

  for (const path of applicationSourceFiles()) {
    const content = source(path)
    const name = relative(uniappDir, path)
    if (/\buni\.getSystemInfo(?:Sync)?\s*\(/.test(content)) deprecated.push(name)
    if (/@import\s+['"]@\/uni\.scss['"]\s*;/.test(content)) redundantImports.push(name)
  }

  assert.deepEqual(deprecated, [])
  assert.deepEqual(redundantImports, [])
  assert.doesNotMatch(
    source(join(uniappDir, 'vite.config.js')),
    /additionalData/,
  )
})
