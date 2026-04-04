import { requestClient } from '#/api/request';

export namespace AdminApi {
  /** 管理员列表项 */
  export interface AdminItem {
    id: number;
    username: string;
    nickname: string;
    avatar?: string;
    email?: string;
    mobile?: string;
    status: number;
    role_ids?: number[];
    roles?: RoleItem[];
    last_login_time?: string;
    last_login_ip?: string;
    remark?: string;
    create_time?: string;
    update_time?: string;
  }

  /** 角色信息 */
  export interface RoleItem {
    id: number;
    name: string;
    code: string;
  }

  /** 获取列表参数 */
  export interface ListParams {
    keyword?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  /** 创建管理员参数 */
  export interface CreateParams {
    username: string;
    password: string;
    nickname?: string;
    avatar?: string;
    email?: string;
    mobile?: string;
    status?: number;
    remark?: string;
    role_ids?: number[];
  }

  /** 更新管理员参数 */
  export interface UpdateParams extends CreateParams {
    id: number;
  }

  /** 更新状态 */
  export interface UpdateStatus {
    status: 0 | 1;
  }

  /** 更新状态 */
  export interface ChangePassword {
    old_password: string;
    password: string;
    password_confirm: string;
  }
}

/**
 * 获取管理员列表（分页）
 */
export async function getAdminListApi(params?: AdminApi.ListParams) {
  return requestClient.get<{
    list: AdminApi.AdminItem[];
    total: number;
  }>('/auth/admin/list', { params });
}

/**
 * 获取管理员详情
 */
export async function getAdminInfoApi(id: number) {
  return requestClient.get<AdminApi.AdminItem>(`/auth/admin/info/${id}`);
}

/**
 * 创建管理员
 */
export async function createAdminApi(data: AdminApi.CreateParams) {
  return requestClient.post<{ id: number }>('/auth/admin/create', data);
}

/**
 * 更新管理员
 */
export async function updateAdminApi(
  id: number,
  data: Omit<AdminApi.UpdateParams, 'id'>,
) {
  return requestClient.put(`/auth/admin/update/${id}`, data);
}

/**
 * 更新管理员状态
 */
export async function updateAdminStatusApi(
  id: number,
  data: Omit<AdminApi.UpdateStatus, 'id'>,
) {
  return requestClient.put(`/auth/admin/changeStatus/${id}`, data);
}

/**
 * 删除管理员
 */
export async function deleteAdminApi(id: number) {
  return requestClient.delete(`/auth/admin/delete/${id}`);
}

/**
 * 重置密码
 */
export async function resetPasswordApi(id: number, password: string) {
  return requestClient.post(`/auth/admin/resetPassword/${id}`, { password });
}

/**
 * 修改密码
 */
export async function changePasswordApi(data: AdminApi.ChangePassword) {
  return requestClient.post('/auth/admin/changePassword', data);
}
