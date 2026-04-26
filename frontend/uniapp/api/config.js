import { get } from '@/api/request'
export const getBasicConfig = () => get('/client/api/setting/basic')
