import { get } from '@/api/request'

export const getLogisticsDetail = (orderId) => get(`/client/api/logistics/detail/${orderId}`)
