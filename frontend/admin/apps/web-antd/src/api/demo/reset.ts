import { publicRequestClient, requestClient } from '#/api/request';

export namespace DemoResetApi {
  export type ResetStatus = 'error' | 'idle' | 'running' | 'success';

  export interface ResetResult {
    admin_username: string;
    duration: number;
    regions: number;
    static: {
      copied: number;
      errors: string[];
      overwritten: number;
      source_missing: boolean;
    };
  }

  export interface ResetJobStatus {
    finished_at: null | string;
    job_id: null | string;
    message: string;
    result: null | ResetResult;
    started_at: null | string;
    status: ResetStatus;
  }
}

export async function resetDemoDataApi() {
  return requestClient.post<DemoResetApi.ResetJobStatus>('/demo/reset');
}

export async function startPublicDemoResetApi() {
  return publicRequestClient.post<DemoResetApi.ResetJobStatus>(
    '/demo/reset/start',
  );
}

export async function getPublicDemoResetStatusApi() {
  return publicRequestClient.get<DemoResetApi.ResetJobStatus>(
    '/demo/reset/status',
  );
}
