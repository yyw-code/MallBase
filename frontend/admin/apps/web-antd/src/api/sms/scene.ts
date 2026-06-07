import { requestClient } from '#/api/request';

export namespace SmsSceneApi {
  export interface SceneItem {
    id?: null | number;
    scene_code: string;
    scene_name: string;
    provider_id?: number;
    provider_name?: string;
    template_id?: number;
    template_name?: string;
    sign_id?: number;
    sign_name?: string;
    status?: null | number;
    update_time?: string;
    /** 当前场景可用的占位符名称(后端按场景定义,只读下发) */
    available_params?: string[];
  }

  export interface BindParams {
    scene_code: string;
    provider_id: number;
    template_id: number;
    sign_id: number;
    status: number;
  }

  export interface ListParams {
    keyword?: string;
    limit?: number;
    page?: number;
    provider_id?: number;
    status?: number;
  }

  export interface ListResult {
    list: SceneItem[];
    total: number;
  }
}

export async function getSmsSceneListApi(params?: SmsSceneApi.ListParams) {
  return requestClient.get<SmsSceneApi.ListResult>('/sms/scene/list', {
    params,
  });
}

export async function getAllSmsSceneApi() {
  const res = await getSmsSceneListApi({ limit: 100, page: 1 });
  return res.list;
}

export async function bindSmsSceneApi(data: SmsSceneApi.BindParams) {
  return requestClient.post('/sms/scene/bind', data);
}

export async function unbindSmsSceneApi(sceneCode: string) {
  return requestClient.post('/sms/scene/unbind', { scene_code: sceneCode });
}
