import { requestClient } from '#/api/request';

export namespace UploadApi {
  /** 上传验证配置（后端返回，按类型） */
  export interface UploadRuleConfig {
    max_size: number;
    max_count: number;
    accept_types: string[];
  }

  /** 上传响应 */
  export interface UploadResponse {
    url: string;
    full_url: string;
    path: string;
    name: string;
    size: number;
    mime: string;
    modified: string;
  }

  /** 批量上传响应 */
  export interface BatchUploadResponse {
    results: UploadResponse[];
    errors: any[];
  }
}

/** 上传接口参数（module + related_id + type） */
export interface UploadParams {
  /** 上传类型：image / images / file / files / video / videos */
  type?: string;
  /** 模块名：dynamic_form / admin 等，不传则后端使用默认验证 */
  module?: string;
  /** 关联 ID（如设置项 ID），配合 module 使用 */
  related_id?: number | string;
}

/**
 * 获取上传验证配置（按类型）
 */
export async function getUploadConfigApi(type: string) {
  return requestClient.get<UploadApi.UploadRuleConfig>('/config/uploadConfig', {
    params: { type },
  });
}

/** 将上传参数追加到 FormData */
function appendUploadParams(formData: FormData, params?: UploadParams) {
  if (!params) return;
  if (params.type) formData.append('type', params.type);
  if (params.module) formData.append('module', params.module);
  if (params.related_id !== undefined && params.related_id !== null)
    formData.append('related_id', String(params.related_id));
}

/**
 * 单文件上传（图片/文件统一接口）
 * POST /upload/single?type=image|file|video&module=dynamic_form&related_id=123
 */
export async function uploadSingleApi(file: File, params?: UploadParams) {
  const formData = new FormData();
  formData.append('file', file);
  appendUploadParams(formData, params);

  return requestClient.post<UploadApi.UploadResponse>(
    '/upload/single',
    formData,
    {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    },
  );
}

/**
 * 批量上传
 * POST /upload/batch?type=images|files|videos&module=dynamic_form&related_id=123
 */
export async function uploadBatchApi(files: File[], params?: UploadParams) {
  const formData = new FormData();
  files.forEach((file) => {
    formData.append('files[]', file);
  });
  appendUploadParams(formData, params);

  return requestClient.post<UploadApi.BatchUploadResponse>(
    '/upload/batch',
    formData,
    {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    },
  );
}
