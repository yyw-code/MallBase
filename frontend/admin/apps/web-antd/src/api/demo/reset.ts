import { requestClient } from '#/api/request';

export namespace DemoResetApi {
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
}

export async function resetDemoDataApi() {
  return requestClient.post<DemoResetApi.ResetResult>('/demo/reset');
}
