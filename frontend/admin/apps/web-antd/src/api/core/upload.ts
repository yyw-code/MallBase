import { requestClient } from '#/api/request';

export namespace UploadApi {
  /** 文件类型图标映射项（后端返回） */
  export interface FileIconItem {
    /** 文件扩展名 */
    ext: string;
    /** 图标名称（Ant Design 图标） */
    icon: string;
  }

  /** 上传验证配置（后端返回，按类型） */
  export interface UploadRuleConfig {
    /** 最大文件大小（MB） */
    max_size: number;
    /** 最大上传数量 */
    max_count: number;
    /** 允许的文件 MIME 类型 */
    accept_types: string[];
    /** 文件类型图标映射 */
    file_icons: FileIconItem[];
  }

  /** 上传响应 */
  export interface UploadResponse {
    /** 相对路径，用于提交保存 */
    url: string;
    /** 完整 URL（含域名），用于前端回显 */
    full_url: string;
    /** 相对路径 */
    path: string;
    /** 文件名 */
    name: string;
    /** 文件大小（字节） */
    size: number;
    /** MIME 类型 */
    mime: string;
    /** 修改时间 */
    modified: string;
  }

  /** 批量上传响应 */
  export interface BatchUploadResponse {
    data: UploadResponse[];
  }
}

/**
 * 获取上传验证配置（按类型，含图标映射）
 * @param type 上传类型：image / images / file / files
 */
export async function getUploadConfigApi(type: string) {
  return requestClient.get<UploadApi.UploadRuleConfig>(
    '/upload/config',
    { params: { type } },
  );
}

/**
 * 上传图片
 */
export async function uploadImageApi(file: File) {
  const formData = new FormData();
  formData.append('file', file);

  return requestClient.post<UploadApi.UploadResponse>(
    '/upload/image',
    formData,
    {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    },
  );
}

/**
 * 上传文件
 */
export async function uploadFileApi(file: File) {
  const formData = new FormData();
  formData.append('file', file);

  return requestClient.post<UploadApi.UploadResponse>(
    '/upload/file',
    formData,
    {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    },
  );
}

/**
 * 批量上传图片
 */
export async function batchUploadImagesApi(files: File[]) {
  const formData = new FormData();
  files.forEach((file) => {
    formData.append('files[]', file);
  });

  return requestClient.post<UploadApi.BatchUploadResponse>(
    '/upload/batchImage',
    formData,
    {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    },
  );
}
