import { requestClient } from '#/api/request';

export namespace SmsTemplateApi {
  export type AuditStatus = 'local_only' | 'passed' | 'pending' | 'rejected';

  export interface TemplateItem {
    id: number;
    provider_id: number;
    template_name: string;
    template_code: string;
    template_type: number;
    template_content: string;
    audit_status: AuditStatus;
    audit_reason?: string;
    remark?: string;
    last_synced_at?: string;
    create_time?: string;
    update_time?: string;
  }

  export interface ListParams {
    keyword?: string;
    provider_id?: number;
    audit_status?: AuditStatus;
    page?: number;
    limit?: number;
  }

  export interface SaveParams {
    provider_id: number;
    template_name: string;
    template_type: number;
    template_content: string;
    remark?: string;
  }
}

export async function getSmsTemplateListApi(params?: SmsTemplateApi.ListParams) {
  return requestClient.get<{
    list: SmsTemplateApi.TemplateItem[];
    total: number;
  }>('/sms/template/list', { params });
}

export async function getSmsTemplateInfoApi(id: number) {
  return requestClient.get<SmsTemplateApi.TemplateItem>(
    `/sms/template/info/${id}`,
  );
}

export async function createSmsTemplateApi(data: SmsTemplateApi.SaveParams) {
  return requestClient.post<{ id: number }>('/sms/template/create', data);
}

export async function updateSmsTemplateApi(
  id: number,
  data: SmsTemplateApi.SaveParams,
) {
  return requestClient.put(`/sms/template/update/${id}`, data);
}

export async function deleteSmsTemplateApi(id: number) {
  return requestClient.delete(`/sms/template/delete/${id}`);
}

export async function syncSmsTemplateStatusApi(id: number) {
  return requestClient.post<SmsTemplateApi.TemplateItem>(
    `/sms/template/syncStatus/${id}`,
  );
}

export async function syncAllSmsTemplateApi(providerId: number) {
  return requestClient.post<{ failed: number; success: number }>(
    '/sms/template/syncAll',
    { provider_id: providerId },
  );
}
