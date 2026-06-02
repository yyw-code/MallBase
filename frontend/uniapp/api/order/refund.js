import { get, post } from '@/api/request'

export const applyRefund = (data) => post('/client/api/refund/apply', data)

export const cancelRefund = (id) => post(`/client/api/refund/cancel/${id}`)

export const submitRefundReturn = (id, data) => post(`/client/api/refund/return/${id}`, data)

export const getRefundList = (params) => get('/client/api/refund/list', params)

export const getRefundDetail = (id) => get(`/client/api/refund/detail/${id}`)

export const getRefundReasonOptions = () => get('/client/api/refund/reasonOptions')
