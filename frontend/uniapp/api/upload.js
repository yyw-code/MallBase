import { get, uploadFile } from '@/api/request'

export const getUploadConfig = (type = 'image') => get('/client/api/setting/uploadConfig', { type })

export const uploadImage = (filePath, extra = {}) =>
  uploadFile('/client/api/upload/single', filePath, 'file', {
    type: 'image',
    module: 'client',
    ...extra
  })

export const uploadWechatAvatar = (bindToken, filePath) =>
  uploadFile('/client/api/upload/wechat-avatar', filePath, 'file', {
    bind_token: bindToken
  })

export const getUploadedAssetValue = (uploaded) => {
  if (typeof uploaded === 'string') return uploaded
  return (
    uploaded?.asset_id ||
    uploaded?.url ||
    uploaded?.path ||
    uploaded?.full_url ||
    uploaded?.fullUrl ||
    ''
  )
}

export const getUploadedPreviewUrl = (uploaded, fallback = '') => {
  if (typeof uploaded === 'string') return uploaded || fallback
  return uploaded?.full_url || uploaded?.fullUrl || uploaded?.url || fallback
}
