import type { UserGroupApi } from './group';
import type { UserTagApi } from './tag';

import { requestClient } from '#/api/request';

export namespace ClientUserApi {
  export interface DistributionUserInfo {
    avatar?: null | number | string;
    email?: string;
    id: number;
    mobile?: string;
    nickname?: string;
  }

  export interface UserDistributionAccount {
    available_commission: string;
    debt_commission: string;
    direct_user_count: number;
    frozen_commission: string;
    id: number;
    indirect_user_count: number;
    invite_code: string;
    level_id: number;
    level_name: string;
    open_source: string;
    open_source_text: string;
    opened_at?: string;
    order_count: number;
    pending_withdraw: string;
    remark?: string;
    status: number;
    status_text: string;
    total_commission: string;
    user_id: number;
    withdrawn_commission: string;
  }

  export interface UserDistributionRelation {
    attribution_page?: string;
    attribution_scene?: string;
    attribution_target_id?: number;
    attribution_target_type?: string;
    create_time: string;
    expire_time?: string;
    grandparent_user?: DistributionUserInfo | null;
    grandparent_user_id: number;
    id: number;
    invite_code: string;
    invite_reward_amount: string;
    invite_reward_at?: string;
    invite_reward_status: number;
    is_valid: boolean;
    parent_user?: DistributionUserInfo | null;
    parent_user_id: number;
    source: string;
    source_text: string;
  }

  export interface UserDistributionSummary {
    distributor?: null | UserDistributionAccount;
    enabled: boolean;
    is_distributor: boolean;
    relation?: null | UserDistributionRelation;
  }

  /** 用户信息 */
  export interface UserItem {
    id: number;
    mobile?: string;
    email?: string;
    nickname?: string;
    avatar?: string;
    avatar_full_url?: string;
    real_name?: string;
    gender?: number;
    birthday?: string;
    province?: string;
    city?: string;
    district?: string;
    bio?: string;
    mobile_verified: number;
    last_login_time?: string;
    last_login_ip?: string;
    status: number;
    register_type?: string;
    register_ip?: string;
    remark?: string;
    create_time: string;
    update_time: string;
    wallet?: {
      balance: string;
      frozen_amount: string;
    };
    points?: {
      balance_points: number;
      total_expense_points: number;
      total_income_points: number;
    };
    member?: {
      growth_value: number;
      level_id: number;
      level_name: string;
      level_lock_until?: null | string;
      level_remark?: string;
      level_source?: 'auto' | 'manual' | string;
      total_growth_value: number;
    };
    groups?: UserGroupApi.GroupItem[];
    tags?: UserTagApi.TagItem[];
    distribution?: UserDistributionSummary;
  }

  /** 列表参数 */
  export interface ListParams {
    keyword?: string;
    status?: number;
    register_type?: string;
    page?: number;
    limit?: number;
    group_ids?: number[];
    tag_ids?: number[];
  }

  export interface StatsTab {
    key: string;
    label: string;
    count: number;
  }

  export interface StatsResponse {
    tabs: StatsTab[];
    total: number;
  }

  /** 创建参数 */
  export interface CreateParams {
    mobile?: string;
    email?: string;
    password: string;
    nickname?: string;
    real_name?: string;
    gender?: number;
    birthday?: string;
    status?: number;
    remark?: string;
    group_ids?: number[];
    tag_ids?: number[];
  }

  /** 更新参数 */
  export interface UpdateParams {
    mobile?: string;
    email?: string;
    nickname?: string;
    real_name?: string;
    gender?: number;
    birthday?: string;
    province?: string;
    city?: string;
    district?: string;
    bio?: string;
    status?: number;
    remark?: string;
    group_ids?: number[];
    tag_ids?: number[];
  }

  /** 登录参数 */
  export interface LoginParams {
    account: string; // 手机号或邮箱
    password: string;
  }

  /** 注册参数 */
  export interface RegisterParams {
    mobile?: string;
    email?: string;
    password: string;
    nickname?: string;
  }

  /** 登录响应 */
  export interface LoginResponse {
    access_token: string;
    refresh_token: string;
    expires_in: number;
    user_info: UserItem;
  }

  /** 修改密码参数 */
  export interface ChangePasswordParams {
    old_password: string;
    password: string;
  }

  export interface WalletLogItem {
    id: number;
    user_id: number;
    biz_type: string;
    biz_id: string;
    direction: 'income' | 'expense';
    change_amount: string;
    before_amount: string;
    after_amount: string;
    operator_type: number;
    operator_id?: number | null;
    remark?: string;
    biz_type_text?: string;
    create_time: string;
  }

  export interface WalletLogParams {
    user_id?: number;
    type?: 'income' | 'expense';
    biz_type?: string;
    page?: number;
    limit?: number;
  }

  export interface WalletAdjustParams {
    user_id: number;
    direction: 'income' | 'expense';
    amount: string;
    remark: string;
  }

  export interface PointsLogItem {
    id: number;
    user_id: number;
    biz_type: string;
    biz_id: string;
    direction: 'income' | 'expense';
    change_points: number;
    before_points: number;
    after_points: number;
    operator_type: number;
    operator_id?: number | null;
    remark?: string;
    biz_type_text?: string;
    create_time: string;
  }

  export interface PointsLogParams {
    user_id?: number;
    type?: 'income' | 'expense';
    biz_type?: string;
    page?: number;
    limit?: number;
  }

  export interface PointsAdjustParams {
    user_id: number;
    direction: 'income' | 'expense';
    points: number;
    remark: string;
  }

  export interface MemberLevelOption {
    discount_percent: string;
    growth_min: number;
    id: number;
    name: string;
  }

  export interface MemberSetParams {
    level_id: number;
    locked?: boolean;
    lock_until?: string;
    remark: string;
  }
}

/**
 * 获取前台用户列表
 */
export async function getClientUserListApi(params?: ClientUserApi.ListParams) {
  return requestClient.get<{
    list: ClientUserApi.UserItem[];
    total: number;
  }>('/user/list', { params });
}

export async function getClientUserStatsApi(params?: ClientUserApi.ListParams) {
  return requestClient.get<ClientUserApi.StatsResponse>('/user/stats', {
    params,
  });
}

export async function exportClientUserCsvApi(
  params?: ClientUserApi.ListParams,
) {
  return requestClient.download<Blob>('/user/export', { params });
}

/**
 * 获取前台用户详情
 */
export async function getClientUserInfoApi(id: number) {
  return requestClient.get<ClientUserApi.UserItem>(`/user/info/${id}`);
}

export async function getClientUserMemberLevelOptionsApi() {
  return requestClient.get<ClientUserApi.MemberLevelOption[]>(
    '/user/memberLevels',
  );
}

/**
 * 创建前台用户
 */
export async function createClientUserApi(data: ClientUserApi.CreateParams) {
  return requestClient.post<{ id: number }>('/user/create', data);
}

/**
 * 更新前台用户
 */
export async function updateClientUserApi(
  id: number,
  data: ClientUserApi.UpdateParams,
) {
  return requestClient.put(`/user/update/${id}`, data);
}

/**
 * 删除前台用户
 */
export async function deleteClientUserApi(id: number) {
  return requestClient.delete(`/user/delete/${id}`);
}

/**
 * 更新前台用户状态
 */
export async function updateClientUserStatusApi(
  id: number,
  data: { status: number },
) {
  return requestClient.put(`/user/status/${id}`, data);
}

export async function setClientUserMemberApi(
  id: number,
  data: ClientUserApi.MemberSetParams,
) {
  return requestClient.put<ClientUserApi.UserItem['member']>(
    `/user/member/${id}`,
    data,
  );
}

/**
 * 重置前台用户密码
 */
export async function resetClientUserPasswordApi(id: number, password: string) {
  return requestClient.put(`/user/resetPassword/${id}`, { password });
}

export async function getClientUserWalletLogsApi(
  params?: ClientUserApi.WalletLogParams,
) {
  return requestClient.get<{
    list: ClientUserApi.WalletLogItem[];
    total: number;
  }>('/user/wallet/logs', { params });
}

export async function adjustClientUserWalletApi(
  data: ClientUserApi.WalletAdjustParams,
) {
  return requestClient.post<{ balance: string }>('/user/wallet/adjust', data);
}

export async function getClientUserPointsLogsApi(
  params?: ClientUserApi.PointsLogParams,
) {
  return requestClient.get<{
    list: ClientUserApi.PointsLogItem[];
    total: number;
  }>('/user/points/logs', { params });
}

export async function adjustClientUserPointsApi(
  data: ClientUserApi.PointsAdjustParams,
) {
  return requestClient.post<{ balance_points: number }>(
    '/user/points/adjust',
    data,
  );
}

/**
 * 前台用户登录
 */
export async function clientUserLoginApi(data: ClientUserApi.LoginParams) {
  return requestClient.post<ClientUserApi.LoginResponse>(
    '/client/api/user/auth/login',
    data,
  );
}

/**
 * 前台用户注册
 */
export async function clientUserRegisterApi(
  data: ClientUserApi.RegisterParams,
) {
  return requestClient.post<ClientUserApi.LoginResponse>(
    '/client/api/user/auth/register',
    data,
  );
}

/**
 * 前台用户登出
 */
export async function clientUserLogoutApi() {
  return requestClient.post('/client/api/user/my/logout');
}

/**
 * 获取当前前台用户信息
 */
export async function getClientMyUserInfoApi() {
  return requestClient.get<ClientUserApi.UserItem>('/client/api/user/my/info');
}

/**
 * 更新当前前台用户信息
 */
export async function updateClientMyUserInfoApi(
  data: Partial<ClientUserApi.UpdateParams>,
) {
  return requestClient.put('/client/api/user/my/info', data);
}

/**
 * 修改当前前台用户密码
 */
export async function changeClientMyPasswordApi(
  data: ClientUserApi.ChangePasswordParams,
) {
  return requestClient.put('/client/api/user/my/password', data);
}

// 导出分组和标签 API
export * from './group';
export * from './tag';
export * from './address';
