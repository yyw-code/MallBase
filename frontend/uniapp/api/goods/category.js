import { get } from '@/api/request'

export const getCategoryTree = () => get('/client/api/goods/category/tree')

export const getCategoryList = () => get('/client/api/goods/category/list')
