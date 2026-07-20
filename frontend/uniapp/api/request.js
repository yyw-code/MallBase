import config from '@/config/index'
import { handleMaintenanceBody } from '@/utils/maintenance'

const TOKEN_KEY = 'mb_access_token'
const REFRESH_KEY = 'mb_refresh_token'
const REQUEST_TIMEOUT = 15000
const REFRESH_URL = '/client/api/user/auth/refreshToken'

let refreshingPromise = null

function getToken() {
  return uni.getStorageSync(TOKEN_KEY) || ''
}

function getRefreshToken() {
  return uni.getStorageSync(REFRESH_KEY) || ''
}

function setStoredTokens(accessToken, refreshToken) {
  if (accessToken) {
    uni.setStorageSync(TOKEN_KEY, accessToken)
  }
  if (refreshToken) {
    uni.setStorageSync(REFRESH_KEY, refreshToken)
  }
}

function getClientType() {
  // #ifdef MP-WEIXIN
  return 'wechat_miniapp'
  // #endif
  return 'uniapp'
}

function parseResponseBody(data) {
  if (typeof data !== 'string') return data
  try {
    return JSON.parse(data)
  } catch (_) {
    return null
  }
}

function isProtocolBody(body) {
  return body && typeof body === 'object' && !Array.isArray(body) && 'code' in body
}

function summarizeResponseData(data) {
  if (typeof data !== 'string') return data
  return data.slice(0, 300)
}

function getResponseMessage(body, fallback) {
  return body?.message || body?.msg || fallback
}

function rejectInvalidResponse(reject, context, showErrorToast = true) {
  console.error('[request:invalid-response]', context)
  if (showErrorToast) {
    uni.showToast({ title: '接口响应异常', icon: 'none' })
  }
  reject(new Error('接口响应异常'))
}

function handleUnauthorized(message = '请重新登录') {
  uni.removeStorageSync(TOKEN_KEY)
  uni.removeStorageSync(REFRESH_KEY)
  let loginUrl = '/pages-sub/user/login'
  const pages = getCurrentPages()
  const current = pages[pages.length - 1]
  if (current?.route === 'pages-sub/user/login') {
    return new Error(message)
  }
  if (current && current.route.startsWith('pages-sub/')) {
    const query = Object.entries(current.options || {})
      .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
      .join('&')
    const fullUrl = query ? `/${current.route}?${query}` : `/${current.route}`
    loginUrl += `?redirect=${encodeURIComponent(fullUrl)}`
  }
  uni.navigateTo({ url: loginUrl })
  return new Error(message)
}

function refreshAccessToken() {
  const refreshToken = getRefreshToken()
  if (!refreshToken) {
    return Promise.reject(new Error('刷新令牌不存在'))
  }

  if (refreshingPromise) {
    return refreshingPromise
  }

  refreshingPromise = new Promise((resolve, reject) => {
    uni.request({
      url: `${config.baseUrl}${REFRESH_URL}`,
      method: 'POST',
      data: {
        refresh_token: refreshToken
      },
      timeout: REQUEST_TIMEOUT,
      header: {
        'Content-Type': 'application/json',
        'X-MallBase-Client': getClientType()
      },
      success(res) {
        const body = parseResponseBody(res.data)
        if (body?.code === 200 && body.data?.access_token) {
          setStoredTokens(body.data.access_token, body.data.refresh_token)
          resolve(body.data.access_token)
          return
        }
        reject(new Error(getResponseMessage(body, '登录态已过期')))
      },
      fail(err) {
        reject(err)
      },
      complete() {
        refreshingPromise = null
      }
    })
  })

  return refreshingPromise
}

export function request(options, allowRefresh = true) {
  const {
    url,
    method = 'GET',
    data,
    header = {},
    redirectOnUnauthorized = true,
    showErrorToast = true,
    allowMaintenanceResponse = false,
  } = options
  const requestUrl = `${config.baseUrl}${url}`
  const token = getToken()
  if (token) {
    header['Authorization'] = `Bearer ${token}`
  }

  return new Promise((resolve, reject) => {
    uni.request({
      url: requestUrl,
      method,
      data,
      timeout: REQUEST_TIMEOUT,
      header: {
        'Content-Type': 'application/json',
        'X-MallBase-Client': getClientType(),
        ...header
      },
      async success(res) {
        const body = parseResponseBody(res.data)
        if (!isProtocolBody(body)) {
          rejectInvalidResponse(reject, {
            url: requestUrl,
            method,
            statusCode: res.statusCode,
            data: summarizeResponseData(res.data)
          }, showErrorToast)
          return
        }

        if (
          !allowMaintenanceResponse &&
          res.statusCode === 503 &&
          handleMaintenanceBody(body)
        ) {
          reject(new Error('SYSTEM_MAINTENANCE'))
          return
        }

        if (body.code === 200) {
          resolve(body.data)
        } else if (body.code === 401) {
          if (allowRefresh) {
            try {
              await refreshAccessToken()
              resolve(request(options, false))
              return
            } catch (_) {
              // 继续走统一登录态失效处理
            }
          }
          if (redirectOnUnauthorized) {
            reject(handleUnauthorized(getResponseMessage(body, '请重新登录')))
          } else {
            reject(new Error(getResponseMessage(body, '请重新登录')))
          }
        } else {
          const message = getResponseMessage(body, '请求失败')
          if (showErrorToast) {
            uni.showToast({ title: message, icon: 'none' })
          }
          reject(new Error(message))
        }
      },
      fail(err) {
        console.error('[request:fail]', {
          url: requestUrl,
          method,
          data,
          err
        })
        if (showErrorToast) {
          uni.showToast({ title: '网络异常', icon: 'none' })
        }
        reject(err)
      }
    })
  })
}

export const get = (url, params) => request({ url, method: 'GET', data: params })
export const post = (url, data) => request({ url, method: 'POST', data })
export const postSilent = (url, data) =>
  request({ url, method: 'POST', data, redirectOnUnauthorized: false, showErrorToast: false })
export const put = (url, data) => request({ url, method: 'PUT', data })
export const del = (url, data) => request({ url, method: 'DELETE', data })

export function uploadFile(url, filePath, name = 'file', formData = {}, allowRefresh = true) {
  const requestUrl = `${config.baseUrl}${url}`
  const token = getToken()
  const header = {}
  header['X-MallBase-Client'] = getClientType()
  if (token) {
    header.Authorization = `Bearer ${token}`
  }

  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: requestUrl,
      filePath,
      name,
      formData,
      header,
      async success(res) {
        const body = parseResponseBody(res.data)
        if (!isProtocolBody(body)) {
          rejectInvalidResponse(reject, {
            url: requestUrl,
            method: 'UPLOAD',
            statusCode: res.statusCode,
            data: summarizeResponseData(res.data)
          })
          return
        }

        if (res.statusCode === 503 && handleMaintenanceBody(body)) {
          reject(new Error('SYSTEM_MAINTENANCE'))
          return
        }

        if (body.code === 200) {
          resolve(body.data)
        } else if (body.code === 401) {
          if (allowRefresh) {
            try {
              await refreshAccessToken()
              resolve(uploadFile(url, filePath, name, formData, false))
              return
            } catch (_) {
              // 继续走统一登录态失效处理
            }
          }
          reject(handleUnauthorized(getResponseMessage(body, '请重新登录')))
        } else {
          const message = getResponseMessage(body, '上传失败')
          uni.showToast({ title: message, icon: 'none' })
          reject(new Error(message))
        }
      },
      fail(err) {
        uni.showToast({ title: '网络异常', icon: 'none' })
        reject(err)
      },
    })
  })
}
