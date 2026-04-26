import { get, post, put, del } from '@/api/request'

export const getAddressList = () => get('/client/api/user/address/list')

export const getAddressInfo = (id) => get(`/client/api/user/address/info/${id}`)

export const createAddress = (data) => post('/client/api/user/address/create', data)

export const updateAddress = (id, data) => put(`/client/api/user/address/update/${id}`, data)

export const deleteAddress = (id) => del(`/client/api/user/address/delete/${id}`)

export const setDefaultAddress = (id) => put(`/client/api/user/address/setDefault/${id}`)
