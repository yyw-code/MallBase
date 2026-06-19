import { requestClient } from '#/api/request';

export namespace UploadApi {
  export interface UploadSystemLimits {
    php_upload_max_filesize_mb: null | number;
    php_post_max_size_mb: null | number;
    php_max_file_uploads: null | number;
    effective_max_size_mb: null | number;
    effective_max_count: null | number;
  }

  /** 上传验证配置（后端返回，按类型） */
  export interface UploadRuleConfig {
    max_size: number;
    max_count: number;
    accept_types: string[];
    system_limits?: UploadSystemLimits;
    warnings?: string[];
  }

  export interface UploadTypeOption {
    label: string;
    value: 'file' | 'files' | 'image' | 'images' | 'video' | 'videos';
    asset_type: 'file' | 'image' | 'video';
    multiple: boolean;
  }

  export interface AssetTypeOption {
    label: string;
    value: 'file' | 'image' | 'video';
  }

  export interface UploadDriverOption {
    label: string;
    value: string;
    enabled: boolean;
  }

  export interface UploadOptions {
    upload_types: UploadTypeOption[];
    asset_types: AssetTypeOption[];
    upload_drivers: UploadDriverOption[];
  }

  /** 上传响应 */
  export interface UploadResponse {
    asset_id?: number;
    category_id?: number;
    driver?: string;
    url: string;
    full_url: string;
    path: string;
    name: string;
    original_name?: string;
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
  /** 素材分类 ID */
  category_id?: number | string;
}

/**
 * 获取上传验证配置（按类型）
 */
export async function getUploadConfigApi(type: string) {
  return requestClient.get<UploadApi.UploadRuleConfig>('/config/uploadConfig', {
    params: { type },
  });
}

/**
 * 获取上传公共选项（后台 Upload / 素材选择器使用）
 */
export async function getUploadOptionsApi() {
  return requestClient.get<UploadApi.UploadOptions>('/config/uploadOptions');
}

/** 将上传参数追加到 FormData */
function appendUploadParams(formData: FormData, params?: UploadParams) {
  if (!params) return;
  if (params.type) formData.append('type', params.type);
  if (params.module) formData.append('module', params.module);
  if (params.related_id !== undefined && params.related_id !== null)
    formData.append('related_id', String(params.related_id));
  if (params.category_id !== undefined && params.category_id !== null)
    formData.append('category_id', String(params.category_id));
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
