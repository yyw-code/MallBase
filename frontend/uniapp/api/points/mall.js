import { get, post } from '@/api/request'

export const getPointsMallGoodsList = (params) => get('/client/api/points/mall/list', params)

export const getPointsMallGoodsDetail = (id) => get(`/client/api/points/mall/detail/${id}`)

export const exchangePointsGoods = (data) => post('/client/api/points/mall/exchange', data)

export const getPointsExchangeOrders = (params) => get('/client/api/points/mall/orders', params)

export const getPointsExchangeOrderDetail = (id) => get(`/client/api/points/mall/order/${id}`)

export const cancelPointsExchangeOrder = (id) => post(`/client/api/points/mall/order/${id}/cancel`)
