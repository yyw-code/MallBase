import { get } from '@/api/request'

export const getBasicConfig = () => get('/client/api/setting/basic')

/**
 * 已启用的支付方式
 * 后端按 mb_setting.payment_*_enabled 返回，未启用则不出现在列表里。
 * 出参：Array<{ code: number, name: string, icon: string }>
 *   - code=1 微信支付
 *   - code=3 余额支付
 */
export const getPayMethods = () => get('/client/api/setting/payMethods')

export const getRechargeMethods = () => get('/client/api/setting/rechargeMethods')
