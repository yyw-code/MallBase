export const CUSTOMER_SERVICE_MIN_RECORDING_MS = 800
export const CUSTOMER_SERVICE_MAX_RECORDING_MS = 60000

function noop() {}

function recordingErrorMessage(error) {
  const name = String(error?.name || '')
  if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
    return '请允许使用麦克风后重试'
  }
  if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
    return '未检测到可用麦克风'
  }
  return String(error?.errMsg || error?.message || '录音失败，请稍后重试')
}

function stopStream(stream) {
  stream?.getTracks?.().forEach((track) => track.stop())
}

// #ifndef H5
let nativeRecorderManager = null
let nativeRecorderSession = null
let nativeRecorderListenersReady = false

function getNativeRecorderManager() {
  if (!nativeRecorderManager) {
    nativeRecorderManager = uni.getRecorderManager()
  }
  if (nativeRecorderListenersReady) return nativeRecorderManager

  nativeRecorderListenersReady = true
  nativeRecorderManager.onStart(() => {
    if (!nativeRecorderSession) return
    nativeRecorderSession.startedAt = Date.now()
    nativeRecorderSession.onStart()
  })
  nativeRecorderManager.onStop((result) => {
    const session = nativeRecorderSession
    nativeRecorderSession = null
    if (!session || session.cancelled) return
    const filePath = String(result?.tempFilePath || '')
    if (!filePath) {
      session.onError('录音文件生成失败')
      return
    }
    session.onStop({
      file: null,
      filePath,
      durationMs: Math.max(0, Date.now() - session.startedAt),
      fileName: `voice-${Date.now()}.mp3`,
    })
  })
  nativeRecorderManager.onError((error) => {
    const session = nativeRecorderSession
    nativeRecorderSession = null
    session?.onError(recordingErrorMessage(error))
  })

  return nativeRecorderManager
}
// #endif

export function createCustomerServiceRecorder(handlers = {}) {
  const onStart = typeof handlers.onStart === 'function' ? handlers.onStart : noop
  const onStop = typeof handlers.onStop === 'function' ? handlers.onStop : noop
  const onError = typeof handlers.onError === 'function' ? handlers.onError : noop

  // #ifdef H5
  let h5Stream = null
  let h5Recorder = null
  let h5Chunks = []
  let h5StartedAt = 0
  let h5Starting = false
  let h5Cancelled = false
  let h5StartId = 0

  const h5Supported = Boolean(
    typeof navigator !== 'undefined'
      && navigator.mediaDevices?.getUserMedia
      && typeof MediaRecorder !== 'undefined',
  )

  function cleanupH5() {
    stopStream(h5Stream)
    h5Stream = null
    h5Recorder = null
    h5Chunks = []
    h5StartedAt = 0
  }

  async function startH5() {
    if (!h5Supported || h5Starting || h5Recorder?.state === 'recording') return false

    h5Starting = true
    h5Cancelled = false
    const startId = ++h5StartId
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
      if (h5Cancelled || startId !== h5StartId) {
        stopStream(stream)
        return false
      }

      h5Stream = stream
      h5Chunks = []
      const mimeType = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/mp4',
      ].find((value) => MediaRecorder.isTypeSupported?.(value)) || ''
      h5Recorder = mimeType
        ? new MediaRecorder(stream, { mimeType })
        : new MediaRecorder(stream)
      h5Recorder.ondataavailable = (event) => {
        if (event.data?.size) h5Chunks.push(event.data)
      }
      h5Recorder.onerror = (event) => {
        onError(recordingErrorMessage(event?.error || event))
        cleanupH5()
      }
      h5Recorder.onstart = () => {
        h5StartedAt = Date.now()
        onStart()
      }
      h5Recorder.onstop = () => {
        const cancelled = h5Cancelled
        const durationMs = Math.max(0, Date.now() - h5StartedAt)
        const type = h5Recorder?.mimeType || mimeType || 'audio/webm'
        const uploadType = type.split(';')[0].trim() || 'audio/webm'
        const extension = uploadType.includes('mp4') ? 'mp4' : 'webm'
        const fileName = `voice-${Date.now()}.${extension}`
        const file = typeof File === 'function'
          ? new File(h5Chunks, fileName, { type: uploadType })
          : new Blob(h5Chunks, { type: uploadType })
        cleanupH5()
        if (cancelled || file.size === 0) return
        onStop({
          file,
          filePath: '',
          durationMs,
          fileName,
        })
      }
      h5Recorder.start()
      return true
    } catch (error) {
      cleanupH5()
      onError(recordingErrorMessage(error))
      return false
    } finally {
      h5Starting = false
    }
  }

  function stopH5() {
    if (h5Starting) {
      h5Cancelled = true
      h5StartId += 1
      return
    }
    if (h5Recorder?.state === 'recording') h5Recorder.stop()
  }

  function cancelH5() {
    h5Cancelled = true
    stopH5()
  }

  function destroyH5() {
    h5Cancelled = true
    h5StartId += 1
    if (h5Recorder?.state === 'recording') {
      h5Recorder.stop()
      return
    }
    cleanupH5()
  }

  return {
    isSupported: h5Supported,
    start: startH5,
    stop: stopH5,
    cancel: cancelH5,
    destroy: destroyH5,
  }
  // #endif

  // #ifndef H5
  const owner = Symbol('customer-service-recorder')
  const nativeSupported = typeof uni !== 'undefined' && typeof uni.getRecorderManager === 'function'

  function startNative() {
    if (!nativeSupported || nativeRecorderSession?.owner === owner) return Promise.resolve(false)
    try {
      const manager = getNativeRecorderManager()
      nativeRecorderSession = {
        owner,
        cancelled: false,
        startedAt: Date.now(),
        onStart,
        onStop,
        onError,
      }
      manager.start({
        duration: CUSTOMER_SERVICE_MAX_RECORDING_MS,
        sampleRate: 16000,
        numberOfChannels: 1,
        encodeBitRate: 48000,
        format: 'mp3',
      })
      return Promise.resolve(true)
    } catch (error) {
      nativeRecorderSession = null
      onError(recordingErrorMessage(error))
      return Promise.resolve(false)
    }
  }

  function stopNative() {
    if (nativeRecorderSession?.owner !== owner) return
    nativeRecorderManager?.stop()
  }

  function cancelNative() {
    if (nativeRecorderSession?.owner !== owner) return
    nativeRecorderSession.cancelled = true
    nativeRecorderManager?.stop()
  }

  function destroyNative() {
    cancelNative()
  }

  return {
    isSupported: nativeSupported,
    start: startNative,
    stop: stopNative,
    cancel: cancelNative,
    destroy: destroyNative,
  }
  // #endif
}
