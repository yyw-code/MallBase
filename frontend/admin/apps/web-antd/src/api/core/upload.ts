import { requestClient } from '#/api/request';

export namespace UploadApi {
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
