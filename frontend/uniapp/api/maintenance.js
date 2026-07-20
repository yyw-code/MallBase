import { request } from '@/api/request'

export function fetchMaintenanceStatus() {
  return request({
    url: '/upgrade/api/maintenance',
    method: 'GET',
    allowMaintenanceResponse: true,
    redirectOnUnauthorized: false,
    showErrorToast: false,
  })
}
