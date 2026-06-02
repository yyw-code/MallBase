import { get } from '@/api/request'

export const getWalletInfo = () => get('/client/api/user/wallet/info')

export const getWalletLogs = (params) => get('/client/api/user/wallet/logs', params)
