import { get } from '@/api/request'

export const getDecorateConfig = () => get('/client/api/decorate/config')

export const getDecorateThemes = () => get('/client/api/decorate/themes')
