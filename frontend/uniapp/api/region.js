import { get } from '@/api/request'

export const getRegionChildren = (parentId = 0) => get('/client/api/region/children', { parent_id: parentId })

export const getRegionPath = (id) => get(`/client/api/region/path/${id}`)
