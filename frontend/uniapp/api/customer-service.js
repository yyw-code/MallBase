import { post } from '@/api/request'

export const createCustomerServiceContextToken = (data) =>
  post('/client/api/customer-service/context-token', data)
