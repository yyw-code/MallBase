import { requestClient } from '#/api/request';

export namespace PointsLogApi {
  export interface LogItem {
    id: number;
    user_id: number;
    biz_type: string;
    biz_type_text?: string;
    biz_id: string;
    direction: 'expense' | 'income';
    change_points: number;
    before_points: number;
    after_points: number;
    operator_type: number;
    operator_id?: null | number;
    remark?: string;
    create_time: string;
  }

  export interface ListParams {
    user_id?: number;
    type?: 'expense' | 'income';
    biz_type?: string;
    page?: number;
    limit?: number;
  }
}

export async function getPointsLogListApi(params?: PointsLogApi.ListParams) {
  return requestClient.get<{
    list: PointsLogApi.LogItem[];
    total: number;
  }>('/points/log/list', { params });
}
