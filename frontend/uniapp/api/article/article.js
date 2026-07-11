import { get } from '@/api/request'

export const getArticleCategories = () => get('/client/api/article/category/list')
export const getArticleList = (params) => get('/client/api/article/list', params)
export const getArticleDetail = (id) => get(`/client/api/article/info/${id}`)
