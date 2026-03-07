import { requestClient } from '#/api/request';

export namespace PermissionApi {
  /** 权限列表项 */
  export interface PermissionItem {
    id: number;
    parent_id: number;
    name: string;
    code: string;
    type: number; // 1=菜单, 2=按钮
    path?: string;
    icon?: string;
    component?: string;
    sort: number;
    status: number;
    is_show: number;
    remark?: string;
    create_time?: string;
    update_time?: string;
    children?: PermissionItem[];
  }

  /** 获取列表参数 */
  export interface ListParams {
    keyword?: string;
    type?: number;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建权限参数 */
  export interface CreateParams {
    parent_id?: number;
    name: string;
    code: string;
    type?: number;
    path?: string;
    icon?: string;
    component?: string;
    sort?: number;
    status?: number;
    is_show?: number;
    remark?: string;
  }

  /** 更新权限参数 */
  export interface UpdateParams extends CreateParams {
    id: number;
  }
}

/**
 * 获取权限树形列表
 */
export async function getPermissionTreeApi(params?: PermissionApi.ListParams) {
  return requestClient.get<PermissionApi.PermissionItem[]>('/auth/permission/tree', {
    params,
  });
}

/**
 * 获取权限列表（分页）
 */
export async function getPermissionListApi(params?: PermissionApi.ListParams) {
  return requestClient.get<{
    data: PermissionApi.PermissionItem[];
    total: number;
    page: number;
    limit: number;
  }>('/auth/permission/list', { params });
}

/**
 * 获取权限详情
 */
export async function getPermissionInfoApi(id: number) {
  return requestClient.get<PermissionApi.PermissionItem>(
    `/auth/permission/info/${id}`,
  );
}

/**
 * 创建权限
 */
export async function createPermissionApi(data: PermissionApi.CreateParams) {
  return requestClient.post<{ id: number }>('/auth/permission/create', data);
}

/**
 * 更新权限
 */
export async function updatePermissionApi(
  id: number,
  data: Omit<PermissionApi.UpdateParams, 'id'>,
) {
  return requestClient.put(`/auth/permission/update/${id}`, data);
}

/**
 * 删除权限
 */
export async function deletePermissionApi(id: number) {
  return requestClient.delete(`/auth/permission/delete/${id}`);
}