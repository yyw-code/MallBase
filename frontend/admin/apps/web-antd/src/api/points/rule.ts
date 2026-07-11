import { requestClient } from '#/api/request';

export namespace PointsRuleApi {
  export interface RuleItem {
    id: number;
    scene: string;
    scene_text: string;
    name: string;
    description?: string;
    points_per_yuan: number;
    fixed_points: number;
    max_points: number;
    sort: number;
    status: number;
    remark?: string;
    create_time: string;
    update_time: string;
  }

  export interface ListParams {
    keyword?: string;
    scene?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  export interface SaveParams {
    scene: string;
    name: string;
    description?: string;
    points_per_yuan?: number;
    fixed_points?: number;
    max_points?: number;
    sort?: number;
    status?: number;
    remark?: string;
  }

  export interface SceneOption {
    label: string;
    value: string;
  }
}

export async function getPointsRuleListApi(params?: PointsRuleApi.ListParams) {
  return requestClient.get<{
    list: PointsRuleApi.RuleItem[];
    total: number;
  }>('/points/rule/list', { params });
}

export async function getPointsRuleInfoApi(id: number) {
  return requestClient.get<PointsRuleApi.RuleItem>(`/points/rule/info/${id}`);
}

export async function getPointsRuleScenesApi() {
  return requestClient.get<PointsRuleApi.SceneOption[]>('/points/rule/scenes');
}

export async function createPointsRuleApi(data: PointsRuleApi.SaveParams) {
  return requestClient.post<{ id: number }>('/points/rule/create', data);
}

export async function updatePointsRuleApi(
  id: number,
  data: PointsRuleApi.SaveParams,
) {
  return requestClient.put(`/points/rule/update/${id}`, data);
}

export async function deletePointsRuleApi(id: number) {
  return requestClient.delete(`/points/rule/delete/${id}`);
}

export async function updatePointsRuleStatusApi(id: number, status: number) {
  return requestClient.put(`/points/rule/updateStatus/${id}`, { status });
}
