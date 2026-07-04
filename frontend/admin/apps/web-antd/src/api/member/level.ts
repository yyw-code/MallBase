import { requestClient } from '#/api/request';

export namespace MemberLevelApi {
  export interface LevelItem {
    id: number;
    name: string;
    growth_min: number;
    discount_percent: string;
    sort: number;
    status: number;
    remark?: string;
    create_time: string;
    update_time: string;
  }

  export interface ListParams {
    keyword?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  export interface SaveParams {
    name: string;
    growth_min?: number;
    discount_percent?: number | string;
    sort?: number;
    status?: number;
    remark?: string;
  }
}

export async function getMemberLevelListApi(
  params?: MemberLevelApi.ListParams,
) {
  return requestClient.get<{
    list: MemberLevelApi.LevelItem[];
    total: number;
  }>('/member/level/list', { params });
}

export async function getMemberLevelInfoApi(id: number) {
  return requestClient.get<MemberLevelApi.LevelItem>(`/member/level/info/${id}`);
}

export async function createMemberLevelApi(data: MemberLevelApi.SaveParams) {
  return requestClient.post<{ id: number }>('/member/level/create', data);
}

export async function updateMemberLevelApi(
  id: number,
  data: MemberLevelApi.SaveParams,
) {
  return requestClient.put(`/member/level/update/${id}`, data);
}

export async function deleteMemberLevelApi(id: number) {
  return requestClient.delete(`/member/level/delete/${id}`);
}

export async function updateMemberLevelStatusApi(id: number, status: number) {
  return requestClient.put(`/member/level/updateStatus/${id}`, { status });
}
