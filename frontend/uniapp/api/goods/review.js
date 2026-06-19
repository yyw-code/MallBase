import { get, post } from '@/api/request'
import { uploadImage } from '@/api/upload'

export const postReview = (data) => post('/client/api/review/create', data)

export const getReviewList = (goodsId, params) =>
  get('/client/api/review/list', { goods_id: goodsId, ...params })

export const uploadReviewImage = (filePath) => uploadImage(filePath, { module: 'review' })
