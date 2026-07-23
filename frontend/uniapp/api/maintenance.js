import { request } from '@/api/request'

export async function fetchMaintenanceStatus() {
  await request({
    url: '/client/api/setting/basic',
    method: 'GET',
    redirectOnUnauthorized: false,
    showErrorToast: false,
  })
  return {
    maintenance: false,
    state: 'normal',
  }
}
