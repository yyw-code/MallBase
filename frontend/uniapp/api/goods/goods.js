import { get } from '@/api/request'

export const getGoodsList = (params) => get('/client/api/goods/list', params)
export const getGoodsDetail = (id) => get(`/client/api/goods/info/${id}`)
export const getGoodsRecommend = (limit = 10) => get('/client/api/goods/recommend', { limit })
