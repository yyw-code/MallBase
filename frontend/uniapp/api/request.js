import config from '@/config/index'

const TOKEN_KEY = 'mb_access_token'
const REQUEST_TIMEOUT = 15000

function getToken() {
  return uni.getStorageSync(TOKEN_KEY) || ''
}

function getClientType() {
  // #ifdef MP-WEIXIN
  return 'wechat_miniapp'
  // #endif
  return 'uniapp'
}

function handleUnauthorized(message = '请重新登录') {
  uni.removeStorageSync(TOKEN_KEY)
  uni.removeStorageSync('mb_refresh_token')
  let loginUrl = '/pages-sub/user/login'
  const pages = getCurrentPages()
  const current = pages[pages.length - 1]
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

function request(options) {
  const { url, method = 'GET', data, header = {} } = options
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
      success(res) {
        const body = res.data
        if (body.code === 200) {
          resolve(body.data)
        } else if (body.code === 401) {
          reject(handleUnauthorized(body.message || '请重新登录'))
        } else {
          uni.showToast({ title: body.message || '请求失败', icon: 'none' })
          reject(new Error(body.message || '请求失败'))
        }
      },
      fail(err) {
        console.error('[request:fail]', {
          url: requestUrl,
          method,
          data,
          err
        })
        uni.showToast({ title: '网络异常', icon: 'none' })
        reject(err)
      }
    })
  })
}

export const get = (url, params) => request({ url, method: 'GET', data: params })
export const post = (url, data) => request({ url, method: 'POST', data })
export const put = (url, data) => request({ url, method: 'PUT', data })
export const del = (url, data) => request({ url, method: 'DELETE', data })

export function uploadFile(url, filePath, name = 'file', formData = {}) {
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
      success(res) {
        let body = res.data
        if (typeof body === 'string') {
          try {
            body = JSON.parse(body)
          } catch (e) {
            reject(e)
            return
          }
        }

        if (body.code === 200) {
          resolve(body.data)
        } else if (body.code === 401) {
          reject(handleUnauthorized(body.message || '请重新登录'))
        } else {
          uni.showToast({ title: body.message || '上传失败', icon: 'none' })
          reject(new Error(body.message || '上传失败'))
        }
      },
      fail(err) {
        uni.showToast({ title: '网络异常', icon: 'none' })
        reject(err)
      },
    })
  })
}
