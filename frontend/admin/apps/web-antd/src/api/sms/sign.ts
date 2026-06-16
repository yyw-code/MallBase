import { requestClient } from '#/api/request';

export namespace SmsSignApi {
  export type AuditStatus = 'local_only' | 'passed' | 'pending' | 'rejected';

  export interface SignItem {
    id: number;
    provider_id: number;
    sign_name: string;
    sign_source: number;
    sign_type: number;
    remark?: string;
    qualification_id?: number;
    audit_status: AuditStatus;
    audit_reason?: string;
    last_synced_at?: string;
    create_time?: string;
    update_time?: string;
  }

  export interface ListParams {
    keyword?: string;
    provider_id?: number;
    page?: number;
    limit?: number;
  }

  export interface ImportParams {
    provider_id?: number;
    remark?: string;
    sign_name: string;
  }
}

export async function getSmsSignListApi(params?: SmsSignApi.ListParams) {
  return requestClient.get<{
    list: SmsSignApi.SignItem[];
    total: number;
  }>('/sms/sign/list', { params });
}

export async function getSmsSignInfoApi(id: number) {
  return requestClient.get<SmsSignApi.SignItem>(`/sms/sign/info/${id}`);
}

export async function importSmsSignApi(data: SmsSignApi.ImportParams) {
  return requestClient.post<{ id: number }>('/sms/sign/import', data);
}

export async function deleteSmsSignApi(id: number) {
  return requestClient.delete(`/sms/sign/delete/${id}`);
}
