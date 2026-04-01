import { requestClient } from '#/api/request';

export namespace SettingApi {
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
  /** 设置分组 */
  export interface SettingGroup {
    id: number;
    parent_id: number;
    permission_id?: number;
    name: string;
    code: string;
    icon?: string;
    description?: string;
    sort: number;
    status: number;
    create_time?: string;
    update_time?: string;
    children?: SettingGroup[];
  }

  /** 设置项 */
  export interface SettingItem {
    id: number;
    group_id: number;
    name: string;
    code: string;
    value: string;
    type: string;
    options?: null | OptionItem[] | string;
    placeholder?: string;
    remark?: string;
    sort: number;
    is_required: number;
  }

  /** 选项项 */
  export interface OptionItem {
    label: string;
    value: number | string;
  }

  /** 分组列表参数 */
  export interface GroupListParams {
    keyword?: string;
    status?: number;
    parent_id?: number;
    page?: number;
    limit?: number;
  }

  /** 创建分组参数 */
  export interface CreateGroupParams {
    parent_id?: number;
    menu_parent_permission_id?: number;
    name: string;
    code: string;
    icon?: string;
    description?: string;
    sort?: number;
    status?: number;
  }

  /** 更新分组参数 */
  export interface UpdateGroupParams extends Partial<CreateGroupParams> {
    id: number;
  }

  /** 创建设置项参数 */
  export interface CreateSettingParams {
    group_id: number;
    name: string;
    code: string;
    value?: string;
    type: string;
    options?: null | OptionItem[] | string;
    placeholder?: string;
    remark?: string;
    sort?: number;
    is_required?: number;
  }

  /** 更新设置项参数 */
  export interface UpdateSettingParams extends Omit<
    Partial<CreateSettingParams>,
    'group_id'
  > {
    id: number;
  }

  /** 配置响应（动态表单页面使用） */
  export interface ConfigResponse {
    group: {
      code: string;
      icon?: string;
      id: number;
      name: string;
    };
    settings: SettingItem[];
  }

  /** 保存配置参数 */
  export type SaveConfigParams = Record<string, any>;
}

// ==================== 权限菜单树 API ====================

/**
 * 获取设置模块可用的权限菜单树
 */
export async function getSettingPermissionTreeApi() {
  return requestClient.get<SettingApi.PermissionItem[]>(
    '/setting/permission/tree',
  );
}

// ==================== 分组管理 API ====================

/**
 * 获取分组列表（分页）
 */
export async function getSettingGroupListApi(
  params?: SettingApi.GroupListParams,
) {
  return requestClient.get<{
    list: SettingApi.SettingGroup[];
    total: number;
  }>('/setting/group/list', { params });
}

/**
 * 获取分组树形列表（不分页）
 */
export async function getSettingGroupTreeApi(params?: {
  keyword?: string;
  status?: number;
}) {
  return requestClient.get<SettingApi.SettingGroup[]>('/setting/group/tree', {
    params,
  });
}

/**
 * 获取所有启用分组（树形，下拉选择用）
 */
export async function getSettingGroupAllApi() {
  return requestClient.get<SettingApi.SettingGroup[]>('/setting/group/all');
}

/**
 * 获取分组详情
 */
export async function getSettingGroupInfoApi(id: number) {
  return requestClient.get<SettingApi.SettingGroup>(
    `/setting/group/info/${id}`,
  );
}

/**
 * 创建分组
 */
export async function createSettingGroupApi(
  data: SettingApi.CreateGroupParams,
) {
  return requestClient.post<{ id: number }>('/setting/group/create', data);
}

/**
 * 更新分组
 */
export async function updateSettingGroupApi(
  id: number,
  data: Omit<SettingApi.UpdateGroupParams, 'id'>,
) {
  return requestClient.put(`/setting/group/update/${id}`, data);
}

/**
 * 删除分组
 */
export async function deleteSettingGroupApi(id: number) {
  return requestClient.delete(`/setting/group/delete/${id}`);
}

// ==================== 设置项管理 API ====================

/**
 * 获取设置项列表（按分组）
 */
export async function getSettingItemListApi(groupId: number) {
  return requestClient.get<SettingApi.SettingItem[]>('/setting/item/list', {
    params: { group_id: groupId },
  });
}

/**
 * 创建设置项
 */
export async function createSettingItemApi(
  data: SettingApi.CreateSettingParams,
) {
  return requestClient.post<{ id: number }>('/setting/item/create', data);
}

/**
 * 更新设置项
 */
export async function updateSettingItemApi(
  id: number,
  data: Omit<SettingApi.UpdateSettingParams, 'id'>,
) {
  return requestClient.put(`/setting/item/update/${id}`, data);
}

/**
 * 删除设置项
 */
export async function deleteSettingItemApi(id: number) {
  return requestClient.delete(`/setting/item/delete/${id}`);
}

// ==================== 配置读取/保存 API（动态表单页面使用） ====================

/**
 * 获取分组配置
 */
export async function getSettingConfigApi(groupCode: string) {
  return requestClient.get<SettingApi.ConfigResponse>(
    `/setting/config/${groupCode}`,
  );
}

/**
 * 保存分组配置
 */
export async function saveSettingConfigApi(
  groupCode: string,
  data: SettingApi.SaveConfigParams,
) {
  return requestClient.post(`/setting/saveConfig/${groupCode}`, data);
}
