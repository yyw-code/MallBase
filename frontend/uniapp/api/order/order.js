import { get, post } from '@/api/request'

export const createOrder = (data) => post('/client/api/order/create', data)

/**
 * 发起支付
 * @param {string} sn 订单号
 * @param {{ pay_method?: number, scene?: 'mini'|'offi'|'h5' }} body
 *   - pay_method=9（默认）：走后端 Mock 入口（调用即转 PAID，e2e 用）
 *   - pay_method=1 + scene：走真实微信支付，返回 { out_trade_no, prepay_id, mweb_url, payload }
 */
export const payOrder = (sn, body = {}) => post(`/client/api/order/pay/${sn}`, { pay_method: 9, ...body })

export const cancelOrder = (id) => post(`/client/api/order/cancel/${id}`)

export const confirmReceive = (id) => post(`/client/api/order/confirmReceive/${id}`)

export const getOrderList = (params) => get('/client/api/order/list', params)

export const getOrderDetail = (id) => get(`/client/api/order/detail/${id}`)
