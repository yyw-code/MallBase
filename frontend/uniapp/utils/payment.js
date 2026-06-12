/**
 * 微信支付触发器
 *
 * 调用方式：在已创建订单的页面调用 triggerPay(orderId, payMethod)，内部按支付方式和运行平台分流：
 *  - balance    → 后端同步扣减余额并返回支付结果
 *  - mp-weixin   → JSAPI（scene=mini），调 uni.requestPayment
 *  - h5-wechat   → JSAPI（scene=offi），调 WeixinJSBridge.invoke('getBrandWCPayRequest')
 *  - h5          → MWEB（scene=h5），拿到 mweb_url 后 window.location.href 跳转
 *
 * 不在前端传 openid：后端从已登录 user 表取，避免泄露 + 防篡改。
 */

import { payOrder } from '@/api/order/order'
import { getPlatform } from '@/utils/platform'

const PAY_METHOD_WECHAT = 1
const PAY_METHOD_ALIPAY = 2
const PAY_METHOD_BALANCE = 3

/**
 * 根据当前 platform 决定 scene
 * @returns {'mini'|'offi'|'h5'|null}
 */
function detectScene() {
  const platform = getPlatform()
  if (platform === 'mp-weixin') return 'mini'
  if (platform === 'h5-wechat') return 'offi'
  if (platform === 'h5') return 'h5'
  return null
}

/**
 * 调起 JSAPI 五元组（小程序）
 * @param {{ appId: string, timeStamp: string, nonceStr: string, package: string, signType: string, paySign: string }} payload
 */
function invokeMiniRequestPayment(payload) {
  return new Promise((resolve, reject) => {
    uni.requestPayment({
      provider: 'wxpay',
      timeStamp: payload.timeStamp,
      nonceStr: payload.nonceStr,
      package: payload.package,
      signType: payload.signType,
      paySign: payload.paySign,
      success: (res) => resolve(res),
      fail: (err) => reject(err),
    })
  })
}

/**
 * 调起公众号 JSAPI（WeixinJSBridge）
 */
function invokeOffiRequestPayment(payload) {
  return new Promise((resolve, reject) => {
    const launch = () => {
      // eslint-disable-next-line no-undef
      WeixinJSBridge.invoke(
        'getBrandWCPayRequest',
        {
          appId: payload.appId,
          timeStamp: payload.timeStamp,
          nonceStr: payload.nonceStr,
          package: payload.package,
          signType: payload.signType,
          paySign: payload.paySign,
        },
        (res) => {
          if (res && res.err_msg === 'get_brand_wcpay_request:ok') {
            resolve(res)
          } else {
            reject(res)
          }
        }
      )
    }
    // eslint-disable-next-line no-undef
    if (typeof WeixinJSBridge === 'undefined') {
      document.addEventListener('WeixinJSBridgeReady', launch, false)
    } else {
      launch()
    }
  })
}

/**
 * 跳转 MWEB
 */
function redirectMweb(mwebUrl) {
  // #ifdef H5
  window.location.href = mwebUrl
  // #endif
}

function normalizePayError(error) {
  const errMsg = String(error?.errMsg || error?.err_msg || '')
  const errDesc = String(error?.err_desc || error?.message || '')
  const raw = (errDesc || errMsg).trim()
  const lower = `${errMsg} ${errDesc}`.toLowerCase()

  if (lower.includes('cancel')) {
    return '支付已取消'
  }

  if (!raw) {
    return '支付被取消'
  }

  if (raw === errMsg) {
    return `支付调起失败：${raw}`
  }

  return `支付调起失败：${raw}`
}

/**
 * 发起支付（统一入口）
 *
 * @param {number|string} orderId 订单 ID（mb_order.id）
 * @param {number} [payMethod] 支付方式 code（1=微信，3=余额）。默认微信。
 * @returns {Promise<{status: 'success'|'pending'|'fail', message?: string}>}
 *   - success：余额支付已由后端同步确认完成
 *   - pending：JSAPI 控件返回 ok / MWEB 已跳转，最终结果等待后端订单状态确认
 *   - fail：调起失败 / 用户取消 / 当前环境不支持
 */
export async function triggerPay(orderId, payMethod = PAY_METHOD_WECHAT) {
  if (Number(payMethod) === PAY_METHOD_BALANCE) {
    try {
      await payOrder(orderId, { pay_method: PAY_METHOD_BALANCE })
      return { status: 'success' }
    } catch (e) {
      return { status: 'fail', message: e?.message || '余额支付失败' }
    }
  }

  if (Number(payMethod) === PAY_METHOD_ALIPAY) {
    return { status: 'fail', message: '支付宝支付暂未接入' }
  }

  if (Number(payMethod) !== PAY_METHOD_WECHAT) {
    return { status: 'fail', message: '该支付方式暂不可用' }
  }

  const scene = detectScene()
  if (!scene) {
    return { status: 'fail', message: '当前环境不支持微信支付' }
  }

  let prepay
  try {
    prepay = await payOrder(orderId, { scene, pay_method: payMethod })
  } catch (e) {
    return { status: 'fail', message: e?.message || '获取支付参数失败' }
  }

  const payload = prepay?.payload || {}

  try {
    if (scene === 'mini') {
      await invokeMiniRequestPayment(payload)
      return { status: 'pending', message: '正在确认支付结果' }
    }
    if (scene === 'offi') {
      await invokeOffiRequestPayment(payload)
      return { status: 'pending', message: '正在确认支付结果' }
    }
    if (scene === 'h5') {
      const mwebUrl = payload.mweb_url || prepay?.mweb_url
      if (!mwebUrl) {
        return { status: 'fail', message: '未拿到 MWEB 跳转地址' }
      }
      redirectMweb(mwebUrl)
      return { status: 'pending' }
    }
  } catch (e) {
    return {
      status: 'fail',
      message: normalizePayError(e),
    }
  }

  return { status: 'fail', message: '未知支付场景' }
}
