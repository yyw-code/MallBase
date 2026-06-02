import { get } from '@/api/request'

export const getRechargePackages = () => get('/client/api/recharge/package/list')
