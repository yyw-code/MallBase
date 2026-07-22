import { WebSocket as EngineWebSocketTransport } from 'engine.io-client'
import { io } from 'socket.io-client'

class UniAppSocketTask {
  constructor(url, protocols) {
    this.binaryType = 'arraybuffer'
    this.readyState = 0
    this.onopen = null
    this.onclose = null
    this.onmessage = null
    this.onerror = null

    const options = {
      url,
      success: () => {},
      fail: (error) => this.notifyError(error),
    }
    if (Array.isArray(protocols) && protocols.length > 0) {
      options.protocols = protocols
    }

    this.task = uni.connectSocket(options)
    if (!this.task) {
      this.notifyError(new Error('小程序 SocketTask 创建失败'))
      return
    }

    this.task.onOpen(() => {
      this.readyState = 1
      this.onopen?.()
    })
    this.task.onClose((event) => {
      this.readyState = 3
      this.onclose?.(event)
    })
    this.task.onMessage((event) => {
      this.onmessage?.({ data: event.data })
    })
    this.task.onError((error) => this.notifyError(error))
  }

  send(data) {
    if (!this.task || this.readyState !== 1) {
      throw new Error('客服实时连接尚未就绪')
    }
    this.task.send({
      data,
      fail: (error) => this.notifyError(error),
    })
  }

  close() {
    if (!this.task || this.readyState >= 2) return
    this.readyState = 2
    this.task.close({
      fail: (error) => this.notifyError(error),
    })
  }

  notifyError(error) {
    setTimeout(() => this.onerror?.(error), 0)
  }
}

class UniAppWebSocketTransport extends EngineWebSocketTransport {
  createSocket(uri, protocols) {
    return new UniAppSocketTask(uri, protocols)
  }

  doWrite(_packet, data) {
    this.ws.send(data)
  }
}

export function normalizeCustomerServiceSocketBase(socketBase) {
  const value = String(socketBase || '').trim().replace(/\/+$/, '')
  if (!/^(?:https?|wss?):\/\/[^/\s?#]+$/i.test(value)) {
    return ''
  }
  return value
    .replace(/^ws:/i, 'http:')
    .replace(/^wss:/i, 'https:')
}

function socketTransports() {
  // #ifdef H5
  return ['websocket']
  // #endif

  // #ifndef H5
  return [UniAppWebSocketTransport]
  // #endif
}

export function createCustomerServiceSocket(socketBase) {
  const baseUrl = normalizeCustomerServiceSocketBase(socketBase)
  if (!baseUrl) {
    throw new Error('客服 Socket 地址无效')
  }

  return io(baseUrl, {
    path: '/socket.io',
    transports: socketTransports(),
    autoConnect: false,
    forceBase64: true,
    forceNew: true,
    reconnection: true,
    reconnectionAttempts: Infinity,
    reconnectionDelay: 1000,
    reconnectionDelayMax: 8000,
    timeout: 15000,
  })
}
