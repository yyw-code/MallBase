import { post } from '@/api/request'
import { uploadWechatAvatar } from '@/api/upload'

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

export const wechatLogin = (code) => post('/client/api/user/auth/wechat', { code })

export const wechatBindMobile = (bindToken, mobile, code, profile = {}) =>
  post('/client/api/user/auth/wechat/bindMobile', {
    bind_token: bindToken,
    mobile,
    code,
    ...profile
  })

export const wechatBindByPhoneCode = (bindToken, phoneCode, profile = {}) =>
  post('/client/api/user/auth/wechat/bindMobileByPhoneCode', {
    bind_token: bindToken,
    phone_code: phoneCode,
    ...profile
  })

export const wechatBindUserInfo = (bindToken, profile = {}) =>
  post('/client/api/user/auth/wechat/bindUserInfo', {
    bind_token: bindToken,
    ...profile
  })

export const uploadWechatBindAvatar = (bindToken, filePath) =>
  uploadWechatAvatar(bindToken, filePath)

export const wechatOfficialLogin = (code) => post('/client/api/user/auth/wechat/official', { code })

export const getWechatOfficialOauthUrl = (redirectUri, state = 'login') =>
  post('/client/api/user/auth/wechat/official/oauthUrl', {
    redirect_uri: redirectUri,
    state
  })

export const wechatOfficialBindMobile = (bindToken, mobile, code) =>
  post('/client/api/user/auth/wechat/official/bindMobile', {
    bind_token: bindToken,
    mobile,
    code
  })
