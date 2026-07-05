import { requestClient } from '#/api/request';
import type { SettingApi } from '#/api/setting';

export namespace DistributionApi {
  export interface OverviewTrend {
    amount: number[];
    labels: string[];
    orders: number[];
  }

  export interface RegionDistributionItem {
    amount: number;
    commission_count: number;
    name: string;
    order_count: number;
  }

  export interface StatusDistributionItem {
    amount: number;
    name: string;
    status: number;
    value: number;
  }

  export interface Overview {
    available_commission: string;
    commission_total: number;
    distributor_total: number;
    enabled_distributor_total: number;
    frozen_commission: string;
    pending_withdraw: string;
    region_distribution: RegionDistributionItem[];
    status_distribution: StatusDistributionItem[];
    trend: OverviewTrend;
  }

  export interface LevelItem {
    id: number;
    name: string;
    first_rate: string;
    second_rate: string;
    sort: number;
    status: number;
    remark?: string;
    create_time: string;
  }

  export interface DistributorItem {
    id: number;
    user_id: number;
    user?: { id: number; mobile?: string; nickname?: string };
    level_id: number;
    level_name: string;
    invite_code: string;
    status: number;
    available_commission: string;
    frozen_commission: string;
    pending_withdraw: string;
    withdrawn_commission: string;
    debt_commission: string;
    direct_user_count: number;
    indirect_user_count: number;
    order_count: number;
    remark?: string;
    create_time: string;
  }

  export interface RuleItem {
    id: number;
    target_type: string;
    target_type_text: string;
    target_id: number;
    name: string;
    first_rate: string;
    second_rate: string;
    status: number;
    remark?: string;
    create_time: string;
  }

  export interface CommissionItem {
    id: number;
    order_sn: string;
    buyer_user_id: number;
    distributor_user_id: number;
    relation_level: number;
    base_amount: string;
    rate: string;
    amount: string;
    recovered_amount: string;
    status: number;
    status_text: string;
    release_time?: string;
    create_time: string;
  }

  export interface LogItem {
    id: number;
    user_id: number;
    biz_type: string;
    biz_type_text: string;
    biz_id: string;
    account_type: string;
    direction: string;
    change_amount: string;
    before_amount: string;
    after_amount: string;
    remark?: string;
    create_time: string;
  }

  export interface WithdrawItem {
    id: number;
    sn: string;
    user_id: number;
    amount: string;
    account_type: string;
    account_name: string;
    account_no: string;
    status: number;
    status_text: string;
    admin_remark?: string;
    reviewed_at?: string;
    create_time: string;
  }

  export interface ListParams {
    [key: string]: number | string | undefined;
    limit?: number;
    page?: number;
  }
}

export const getDistributionOverviewApi = () =>
  requestClient.get<DistributionApi.Overview>('/distribution/overview');

export const getDistributionSettingsApi = () =>
  requestClient.get<SettingApi.ConfigResponse>('/distribution/settings/info');

export const saveDistributionSettingsApi = (data: SettingApi.SaveConfigParams) =>
  requestClient.put('/distribution/settings/save', data);

export const releaseDistributionDueApi = (limit = 500) =>
  requestClient.post('/distribution/releaseDue', { limit });

export const getDistributionDistributorListApi = (params?: DistributionApi.ListParams) =>
  requestClient.get<{ list: DistributionApi.DistributorItem[]; total: number }>(
    '/distribution/distributor/list',
    { params },
  );

export const openDistributionDistributorApi = (data: {
  level_id: number;
  remark?: string;
  user_id: number;
}) => requestClient.post<{ id: number }>('/distribution/distributor/open', data);

export const updateDistributionDistributorStatusApi = (userId: number, status: number) =>
  requestClient.put(`/distribution/distributor/status/${userId}`, { status });

export const getDistributionLevelListApi = (params?: DistributionApi.ListParams) =>
  requestClient.get<{ list: DistributionApi.LevelItem[]; total: number }>(
    '/distribution/level/list',
    { params },
  );

export const getDistributionLevelInfoApi = (id: number) =>
  requestClient.get<DistributionApi.LevelItem>(`/distribution/level/info/${id}`);

export const createDistributionLevelApi = (data: Partial<DistributionApi.LevelItem>) =>
  requestClient.post<{ id: number }>('/distribution/level/create', data);

export const updateDistributionLevelApi = (
  id: number,
  data: Partial<DistributionApi.LevelItem>,
) => requestClient.put(`/distribution/level/update/${id}`, data);

export const deleteDistributionLevelApi = (id: number) =>
  requestClient.delete(`/distribution/level/delete/${id}`);

export const updateDistributionLevelStatusApi = (id: number, status: number) =>
  requestClient.put(`/distribution/level/updateStatus/${id}`, { status });

export const getDistributionRuleListApi = (params?: DistributionApi.ListParams) =>
  requestClient.get<{ list: DistributionApi.RuleItem[]; total: number }>(
    '/distribution/rule/list',
    { params },
  );

export const getDistributionRuleInfoApi = (id: number) =>
  requestClient.get<DistributionApi.RuleItem>(`/distribution/rule/info/${id}`);

export const createDistributionRuleApi = (data: Partial<DistributionApi.RuleItem>) =>
  requestClient.post<{ id: number }>('/distribution/rule/create', data);

export const updateDistributionRuleApi = (
  id: number,
  data: Partial<DistributionApi.RuleItem>,
) => requestClient.put(`/distribution/rule/update/${id}`, data);

export const deleteDistributionRuleApi = (id: number) =>
  requestClient.delete(`/distribution/rule/delete/${id}`);

export const updateDistributionRuleStatusApi = (id: number, status: number) =>
  requestClient.put(`/distribution/rule/updateStatus/${id}`, { status });

export const getDistributionCommissionListApi = (params?: DistributionApi.ListParams) =>
  requestClient.get<{ list: DistributionApi.CommissionItem[]; total: number }>(
    '/distribution/commission/list',
    { params },
  );

export const getDistributionCommissionLogsApi = (params?: DistributionApi.ListParams) =>
  requestClient.get<{ list: DistributionApi.LogItem[]; total: number }>(
    '/distribution/commission/logs',
    { params },
  );

export const adjustDistributionCommissionApi = (data: {
  amount: string;
  direction: string;
  remark: string;
  user_id: number;
}) => requestClient.post('/distribution/commission/adjust', data);

export const getDistributionWithdrawListApi = (params?: DistributionApi.ListParams) =>
  requestClient.get<{ list: DistributionApi.WithdrawItem[]; total: number }>(
    '/distribution/withdraw/list',
    { params },
  );

export const approveDistributionWithdrawApi = (id: number, admin_remark = '') =>
  requestClient.post(`/distribution/withdraw/approve/${id}`, { admin_remark });

export const rejectDistributionWithdrawApi = (id: number, admin_remark: string) =>
  requestClient.post(`/distribution/withdraw/reject/${id}`, { admin_remark });
