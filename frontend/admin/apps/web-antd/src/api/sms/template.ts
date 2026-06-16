import { requestClient } from '#/api/request';

export namespace SmsTemplateApi {
  export type AuditStatus =
    | 'local_only'
    | 'passed'
    | 'pending'
    | 'rejected'
    | 'submitting';

  export interface TemplateItem {
    id: number;
    provider_id: number;
    /** 关联签名ID */
    sign_id: null | number;
    template_name: string;
    /** 远端模板编码;本地新建/提交中尚未分配时为 null */
    template_code: null | string;
    template_type: number;
    template_content: string;
    audit_status: AuditStatus;
    audit_reason?: string;
    remark?: string;
    last_synced_at?: string;
    create_time?: string;
    update_time?: string;
    /** 后端从 template_content 派生的占位符名称(派生值,不入库) */
    placeholders?: string[];
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
    /** 关联签名ID */
    sign_id?: number;
    template_name: string;
    template_type: number;
    template_content?: string;
    /** 阿里云返回的 SMS_xxx;普通新增时由后端从远端写回 */
    template_code?: string;
    /** 1=提交平台申请,0=仅本地登记平台模板编码 */
    submit_to_platform?: 0 | 1;
    remark?: string;
  }

  export interface CreateByScenesItem {
    scene_code: string;
    template_name: string;
    template_content: string;
    template_type: number;
    template_code?: string;
    remark?: string;
  }

  export interface CreateByScenesParams {
    provider_id: number;
    /** 整批模板共用的关联签名ID */
    sign_id: number;
    /** 1=提交平台申请,0=仅本地登记平台模板编码 */
    submit_to_platform?: 0 | 1;
    items: CreateByScenesItem[];
  }

  export interface CreateByScenesResultItem {
    scene_code: string;
    scene_name: string;
    success: boolean;
    message: string;
    template_id: number;
  }

  /**
   * created/failed 仅反映"本地行落库"是否成功。
   * 阿里云 AddSmsTemplate 异步派发,审核结果用户需稍后刷新列表查看。
   */
  export interface CreateByScenesResult {
    created: number;
    failed: number;
    results: CreateByScenesResultItem[];
  }

  /** 同步类 API 统一返回派发计数 */
  export interface SyncDispatchResult {
    dispatched: number;
    invalid?: number;
    skipped?: number;
  }
}

export async function getSmsTemplateListApi(
  params?: SmsTemplateApi.ListParams,
) {
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

export async function createSmsTemplateByScenesApi(
  data: SmsTemplateApi.CreateByScenesParams,
) {
  return requestClient.post<SmsTemplateApi.CreateByScenesResult>(
    '/sms/template/createByScenes',
    data,
  );
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
  return requestClient.post<SmsTemplateApi.SyncDispatchResult>(
    `/sms/template/syncStatus/${id}`,
  );
}

export async function syncAllSmsTemplateApi(providerId: number) {
  return requestClient.post<SmsTemplateApi.SyncDispatchResult>(
    '/sms/template/syncAll',
    { provider_id: providerId },
  );
}

export async function syncBatchSmsTemplateApi(ids: number[]) {
  return requestClient.post<SmsTemplateApi.SyncDispatchResult>(
    '/sms/template/syncBatch',
    { ids },
  );
}
