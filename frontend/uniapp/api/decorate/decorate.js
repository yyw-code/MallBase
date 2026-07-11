import { get } from '@/api/request'

export const getDecorateConfig = () => get('/client/api/decorate/config')

export const getDecorateThemes = (params) =>
  get('/client/api/decorate/themes', params)
