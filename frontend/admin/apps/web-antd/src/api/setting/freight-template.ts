import { requestClient } from '#/api/request';

export namespace FreightTemplateApi {
  export type RegionSelectionValue = number | number[];

  export interface FreightRuleItem {
    id?: number;
    region_ids: RegionSelectionValue[];
    region_codes?: string[];
    region_names?: string[];
    region_path_texts?: string[];
    first_amount: number;
    first_fee: number;
    continue_amount: number;
    continue_fee: number;
    region_status?: number;
    region_invalid_reason?: string;
    sort?: number;
  }

  export interface TemplateItem {
    id: number;
    name: string;
    charge_type: 'piece' | 'weight';
    default_first_amount: number;
    default_first_fee: number;
    default_continue_amount: number;
    default_continue_fee: number;
    status: number;
    remark?: string;
    rule_count?: number;
    invalid_rule_count?: number;
    rules?: FreightRuleItem[];
    create_time: string;
    update_time: string;
  }

  export interface ListParams {
    name?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  export interface SaveParams {
    name: string;
    charge_type: 'piece' | 'weight';
    default_first_amount: number;
    default_first_fee: number;
    default_continue_amount: number;
    default_continue_fee: number;
    status?: number;
    remark?: string;
    rules: FreightRuleItem[];
  }
}

export async function getFreightTemplateListApi(
  params?: FreightTemplateApi.ListParams,
) {
  return requestClient.get<{
    list: FreightTemplateApi.TemplateItem[];
    total: number;
  }>('/setting/freight-template/list', { params });
}

export async function getFreightTemplateInfoApi(id: number) {
  return requestClient.get<FreightTemplateApi.TemplateItem>(
    `/setting/freight-template/info/${id}`,
  );
}

export async function createFreightTemplateApi(
  data: FreightTemplateApi.SaveParams,
) {
  return requestClient.post<{ id: number }>(
    '/setting/freight-template/create',
    data,
  );
}

export async function updateFreightTemplateApi(
  id: number,
  data: FreightTemplateApi.SaveParams,
) {
  return requestClient.put(`/setting/freight-template/update/${id}`, data);
}

export async function deleteFreightTemplateApi(id: number) {
  return requestClient.delete(`/setting/freight-template/delete/${id}`);
}

export async function updateFreightTemplateStatusApi(
  id: number,
  status: number,
) {
  return requestClient.put(`/setting/freight-template/updateStatus/${id}`, {
    status,
  });
}

export async function refreshFreightTemplateInvalidApi() {
  return requestClient.put<{
    invalid: number;
    recovered: number;
    total: number;
  }>('/setting/freight-template/refreshInvalid');
}
