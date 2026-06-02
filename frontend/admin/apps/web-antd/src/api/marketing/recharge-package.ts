import { requestClient } from '#/api/request';

export namespace RechargePackageApi {
  export interface PackageItem {
    id: number;
    name: string;
    pay_amount: string;
    gift_amount: string;
    balance_amount: string;
    background_image?: string;
    background_image_full_url?: string;
    sort: number;
    status: number;
    remark?: string;
    create_time: string;
    update_time: string;
  }

  export interface ListParams {
    name?: string;
    status?: number;
    page?: number;
    limit?: number;
  }

  export interface SaveParams {
    name: string;
    pay_amount: string;
    gift_amount?: string;
    background_image?: string;
    sort?: number;
    status?: number;
    remark?: string;
  }
}

export async function getRechargePackageListApi(
  params?: RechargePackageApi.ListParams,
) {
  return requestClient.get<{
    list: RechargePackageApi.PackageItem[];
    total: number;
  }>('/marketing/recharge-package/list', { params });
}

export async function getRechargePackageInfoApi(id: number) {
  return requestClient.get<RechargePackageApi.PackageItem>(
    `/marketing/recharge-package/info/${id}`,
  );
}

export async function createRechargePackageApi(
  data: RechargePackageApi.SaveParams,
) {
  return requestClient.post<{ id: number }>(
    '/marketing/recharge-package/create',
    data,
  );
}

export async function updateRechargePackageApi(
  id: number,
  data: RechargePackageApi.SaveParams,
) {
  return requestClient.put(`/marketing/recharge-package/update/${id}`, data);
}

export async function deleteRechargePackageApi(id: number) {
  return requestClient.delete(`/marketing/recharge-package/delete/${id}`);
}

export async function updateRechargePackageStatusApi(
  id: number,
  status: number,
) {
  return requestClient.put(`/marketing/recharge-package/updateStatus/${id}`, {
    status,
  });
}
