import { requestClient } from '#/api/request';

export namespace RoleApi {
  /** 角色列表项 */
  export interface RoleItem {
    id: number;
    name: string;
    code: string;
    status: number;
    sort: number;
    remark: string;
    menu_permission_ids?: number[];
    button_permission_ids?: number[];
    create_time?: string;
    update_time?: string;
  }

  /** 权限信息 */
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
    children?: PermissionItem[];
  }

  /** 获取列表参数 */
  export interface ListParams {
    keyword?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建角色参数 */
  export interface CreateParams {
    name: string;
    code: string;
    status?: number;
    sort?: number;
    remark?: string;
    menu_permission_ids?: number[];
    button_permission_ids?: number[];
  }

  /** 更新角色参数 */
  export interface UpdateParams extends CreateParams {
    id: number;
  }

  /** 更新状态 */
  export interface UpdateStatus {
    status: 0 | 1;
  }
}

/**
 * 获取角色列表（分页）
 */
export async function getRoleListApi(params?: RoleApi.ListParams) {
  return requestClient.get<{
    list: RoleApi.RoleItem[];
    total: number;
  }>('/auth/role/list', { params });
}

/**
 * 获取所有角色（不分页）
 */
export async function getAllRolesApi() {
  return requestClient.get<RoleApi.RoleItem[]>('/auth/role/all');
}

/**
 * 获取角色详情
 */
export async function getRoleInfoApi(id: number) {
  return requestClient.get<RoleApi.RoleItem>(`/auth/role/info/${id}`);
}

/**
 * 创建角色
 */
export async function createRoleApi(data: RoleApi.CreateParams) {
  return requestClient.post<{ id: number }>('/auth/role/create', data);
}

/**
 * 更新角色
 */
export async function updateRoleApi(
  id: number,
  data: Omit<RoleApi.UpdateParams, 'id'>,
) {
  return requestClient.put(`/auth/role/update/${id}`, data);
}

/**
 * 更新角色
 */
export async function updateRoleStatusApi(
  id: number,
  data: Omit<RoleApi.UpdateStatus, 'id'>,
) {
  return requestClient.put(`/auth/role/changeStatus/${id}`, data);
}

/**
 * 删除角色
 */
export async function deleteRoleApi(id: number) {
  return requestClient.delete(`/auth/role/delete/${id}`);
}
