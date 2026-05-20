import { get, post } from '@/api/request'

export const createOrder = (data) => post('/client/api/order/create', data)

/**
 * 订单试算
 * @param {object} data 与 createOrder 同源入参（source / cart_ids|items / address_id）
 * @returns 含运费的服务端权威金额：{ items, total_amount, freight_amount, discount_amount, pay_amount, address }
 */
export const previewOrder = (data) => post('/client/api/order/preview', data)

/**
 * 发起支付（底层接口，业务页面请优先调用 utils/payment.js 中的 triggerPay）
 * @param {number|string} id 订单 ID（mb_order.id）
 * @param {{ pay_method: number, scene?: 'mini'|'offi'|'h5' }} body
 *   - pay_method=1 + scene：走真实微信支付，返回 { out_trade_no, prepay_id, mweb_url, payload }
 *   - pay_method=9：Mock 支付入口（需后台「Mock 支付」开关启用，e2e/本地测试用）
 *   故意不设默认值——避免误用导致订单直接转 PAID 绕过真实支付。
 */
export const payOrder = (id, body) => post(`/client/api/order/pay/${id}`, body)

export const cancelOrder = (id) => post(`/client/api/order/cancel/${id}`)

export const confirmReceive = (id) => post(`/client/api/order/confirmReceive/${id}`)

export const getOrderList = (params) => get('/client/api/order/list', params)

export const getOrderDetail = (id) => get(`/client/api/order/detail/${id}`)
