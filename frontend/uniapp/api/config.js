import { get } from '@/api/request'

export const getBasicConfig = () => get('/client/api/setting/basic')

/**
 * 已启用的支付方式
 * 后端按 mb_setting.payment_*_enabled 返回，未启用则不出现在列表里。
 * 出参：Array<{ code: number, name: string, icon: string }>
 *   - code=1 微信支付
 *   - code=9 Mock 支付（仅测试）
 */
export const getPayMethods = () => get('/client/api/setting/payMethods')
