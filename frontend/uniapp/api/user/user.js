import { get, put } from '@/api/request'

export const getMyInfo = () => get('/client/api/user/my/info')

export const updateMyInfo = (data) => put('/client/api/user/my/info', data)

export const updateMyPassword = (data) => put('/client/api/user/my/password', data)
