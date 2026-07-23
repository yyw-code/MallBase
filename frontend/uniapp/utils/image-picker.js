function normalizeCount(value) {
  const count = Number.parseInt(value, 10)
  return Number.isInteger(count) ? Math.max(1, Math.min(count, 9)) : 1
}

function normalizeOptions(values, allowed, fallback) {
  const items = Array.isArray(values)
    ? values.filter((value) => allowed.includes(value))
    : []
  return items.length ? [...new Set(items)] : fallback
}

function normalizeBrowserFile(value) {
  if (!value || typeof value !== 'object' || typeof Blob === 'undefined') return null
  return value instanceof Blob ? value : null
}

export function normalizeImagePickerResult(result, limit = 9) {
  const tempFiles = Array.isArray(result?.tempFiles) ? result.tempFiles : []
  const tempFilePaths = Array.isArray(result?.tempFilePaths) ? result.tempFilePaths : []
  const count = Math.min(Math.max(tempFiles.length, tempFilePaths.length), normalizeCount(limit))
  const files = []

  for (let index = 0; index < count; index += 1) {
    const source = tempFiles[index]
    const file = normalizeBrowserFile(source?.file) || normalizeBrowserFile(source)
    const path = String(
      source?.tempFilePath
      || source?.path
      || tempFilePaths[index]
      || '',
    ).trim()
    if (!path && !file) continue

    const size = Number(source?.size ?? file?.size)
    const item = {
      path,
      size: Number.isFinite(size) && size > 0 ? size : 0,
    }
    if (file) item.file = file
    files.push(item)
  }

  return files
}

export function chooseImageFiles(options = {}) {
  const count = normalizeCount(options.count)
  const sourceType = normalizeOptions(
    options.sourceType,
    ['album', 'camera'],
    ['album', 'camera'],
  )
  const sizeType = normalizeOptions(
    options.sizeType,
    ['original', 'compressed'],
    ['compressed'],
  )

  return new Promise((resolve) => {
    const success = (result) => resolve(normalizeImagePickerResult(result, count))
    const fail = () => resolve([])
    let picker = null
    let pickerOptions = null

    // #ifdef MP-WEIXIN
    if (typeof uni.chooseMedia === 'function') {
      picker = uni.chooseMedia
      pickerOptions = {
        count,
        mediaType: ['image'],
        sourceType,
        sizeType,
        camera: 'back',
        success,
        fail,
      }
    } else {
      picker = uni.chooseImage
      pickerOptions = {
        count,
        sizeType,
        sourceType,
        success,
        fail,
      }
    }
    // #endif

    // #ifndef MP-WEIXIN
    picker = uni.chooseImage
    pickerOptions = {
      count,
      sizeType,
      sourceType,
      success,
      fail,
    }
    // #endif

    if (typeof picker !== 'function') {
      resolve([])
      return
    }

    try {
      picker.call(uni, pickerOptions)
    } catch {
      resolve([])
    }
  })
}
