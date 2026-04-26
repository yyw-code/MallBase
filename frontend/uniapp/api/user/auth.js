import { post } from '@/api/request'

export const sendSmsCode = (mobile, scene = 'login') =>
  post('/client/api/user/auth/sms/send', { mobile, scene })

export const loginBySms = (mobile, code) =>
  post('/client/api/user/auth/login/sms', { mobile, code })

export const loginByPassword = (account, password) =>
  post('/client/api/user/auth/login', { account, password })

export const loginByUsername = (username, password) =>
  post('/client/api/user/auth/login/username', { username, password })

export const register = (mobile, password, nickname) =>
  post('/client/api/user/auth/register', { mobile, password, nickname })

export const wechatLogin = (code) =>
  post('/client/api/user/auth/wechat', { code })

export const wechatBindMobile = (openid, mobile, code) =>
  post('/client/api/user/auth/wechat/bindMobile', { openid, mobile, code })

export const wechatBindByPhoneCode = (openid, phoneCode) =>
  post('/client/api/user/auth/wechat/bindMobileByPhoneCode', { openid, phone_code: phoneCode })

export const wechatOfficialLogin = (code) =>
  post('/client/api/user/auth/wechat/official', { code })

export const wechatOfficialBindMobile = (openid, mobile, code) =>
  post('/client/api/user/auth/wechat/official/bindMobile', { openid, mobile, code })
