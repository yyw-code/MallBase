import { get, post } from '@/api/request'

export const createOrder = (data) => post('/client/api/order/create', data)

export const payOrder = (sn) => post(`/client/api/order/pay/${sn}`)

export const cancelOrder = (id) => post(`/client/api/order/cancel/${id}`)

export const confirmReceive = (id) => post(`/client/api/order/confirmReceive/${id}`)

export const getOrderList = (params) => get('/client/api/order/list', params)

export const getOrderDetail = (id) => get(`/client/api/order/detail/${id}`)
