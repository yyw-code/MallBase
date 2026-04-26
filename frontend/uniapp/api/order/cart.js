import { get, post, put, del } from '@/api/request'

export const getCartList = () => get('/client/api/cart/list')

export const addToCart = (data) => post('/client/api/cart/add', data)

export const updateCartItem = (id, data) => put(`/client/api/cart/update/${id}`, data)

export const deleteCartItems = (cartIds) => del('/client/api/cart/delete', { cart_ids: cartIds })

export const toggleCartSelected = (cartIds, selected) =>
  post('/client/api/cart/toggleSelected', { cart_ids: cartIds, selected })
