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
    source: number; // 1=手动添加, 2=路由同步, 3=设置模块同步
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
    source?: number;
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
    redirect?: string;
    affix_tab?: number;
    no_basic_layout?: number;
    sort?: number;
    status?: number;
    is_show?: number;
    remark?: string;
  }

  /** 更新权限参数 */
  export interface UpdateParams extends CreateParams {
    id: number;
  }

  /** 批量更新参数 */
  export interface BatchUpdateParams {
    field: 'affix_tab' | 'is_show' | 'no_basic_layout' | 'status';
    value: number;
    include_children?: boolean;
  }

  /** 权限列表响应（规范） */
  export interface ListResult {
    list: PermissionItem[];
    total: number;
  }

  /** 权限列表响应（兼容旧格式） */
  export interface LegacyListResult {
    data: PermissionItem[];
    limit: number;
    page: number;
    total: number;
  }
}

/**
 * 获取权限树形列表
 */
export async function getPermissionTreeApi(params?: PermissionApi.ListParams) {
  return requestClient.get<PermissionApi.PermissionItem[]>(
    '/auth/permission/tree',
    {
      params,
    },
  );
}

/**
 * 获取权限列表（分页）
 */
export async function getPermissionListApi(params?: PermissionApi.ListParams) {
  const result = await requestClient.get<
    PermissionApi.LegacyListResult | PermissionApi.ListResult
  >('/auth/permission/list', { params });

  if ('list' in result) {
    return {
      list: result.list ?? [],
      total: result.total ?? 0,
    };
  }

  return {
    list: result.data ?? [],
    total: result.total ?? 0,
  };
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
 * 批量更新权限字段
 */
export async function batchUpdatePermissionApi(
  id: number,
  params: PermissionApi.BatchUpdateParams,
) {
  return requestClient.put(`/auth/permission/batchUpdate/${id}`, params);
}

/**
 * 删除权限
 */
export async function deletePermissionApi(id: number) {
  return requestClient.delete(`/auth/permission/delete/${id}`);
}
