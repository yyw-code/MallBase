import { requestClient } from '#/api/request';

export namespace UserAddressApi {
  export interface AddressItem {
    id: number;
    user_id: number;
    user_nickname?: string;
    user_mobile?: string;
    receiver_name: string;
    receiver_mobile: string;
    province_id: number;
    province_name: string;
    city_id: number;
    city_name: string;
    district_id: number;
    district_name: string;
    street_id: number;
    street_name: string;
    region_path_text: string;
    address_detail: string;
    tag?: string;
    is_default: number;
    region_status: number;
    region_invalid_reason?: string;
    create_time: string;
    update_time: string;
  }

  export interface ListParams {
    keyword?: string;
    user_id?: number;
    region_status?: number;
    is_default?: number;
    page?: number;
    limit?: number;
  }

  export interface SaveParams {
    user_id: number;
    receiver_name: string;
    receiver_mobile: string;
    province_id: number;
    city_id: number;
    district_id: number;
    street_id: number;
    address_detail: string;
    tag?: string;
    is_default?: number;
  }
}

export async function getUserAddressListApi(
  params?: UserAddressApi.ListParams,
) {
  return requestClient.get<{
    list: UserAddressApi.AddressItem[];
    total: number;
  }>('/user/address/list', { params });
}

export async function getUserAddressInfoApi(id: number) {
  return requestClient.get<UserAddressApi.AddressItem>(
    `/user/address/info/${id}`,
  );
}

export async function createUserAddressApi(data: UserAddressApi.SaveParams) {
  return requestClient.post<{ id: number }>('/user/address/create', data);
}

export async function updateUserAddressApi(
  id: number,
  data: UserAddressApi.SaveParams,
) {
  return requestClient.put(`/user/address/update/${id}`, data);
}

export async function deleteUserAddressApi(id: number) {
  return requestClient.delete(`/user/address/delete/${id}`);
}

export async function setUserAddressDefaultApi(id: number) {
  return requestClient.put(`/user/address/setDefault/${id}`);
}

export async function refreshUserAddressInvalidApi() {
  return requestClient.put<{
    invalid: number;
    recovered: number;
    total: number;
  }>('/user/address/refreshInvalid');
}
