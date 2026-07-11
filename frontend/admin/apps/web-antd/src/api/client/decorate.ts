import type { ClientPageApi } from './page';

import { requestClient } from '#/api/request';

export namespace ClientDecorateApi {
  export type ComponentType =
    | 'banner'
    | 'categoryEntry'
    | 'divider'
    | 'entryCard'
    | 'imageCube'
    | 'navGrid'
    | 'productGroup'
    | 'richText'
    | 'search'
    | 'spacing'
    | 'title';
  export type SchemeType = 'floating' | 'home' | 'profile' | 'tabbar';
  export type TabbarMode = 'custom' | 'native';

  export interface DecorationModule {
    config: Record<string, any>;
    enabled?: boolean;
    id: string;
    title: string;
    type: ComponentType | string;
  }

  export interface ProfileModule {
    config: Record<string, any>;
    enabled?: boolean;
    id: string;
    items?: ProfileModuleItem[];
    title: string;
    type: string;
  }

  export interface ProfileModuleItem {
    icon?: string;
    id: string;
    path?: string;
    title: string;
  }

  export interface TabbarItem {
    activeIcon?: any;
    icon?: any;
    iconPath?: any;
    icon_mode?: 'icon' | 'upload';
    id: string;
    path: string;
    selected_icon?: any;
    selected_icon_mode?: 'icon' | 'upload';
    selectedIconPath?: any;
    text: string;
  }

  export interface TabbarSchemeSchema {
    items: TabbarItem[];
  }

  export interface FloatingItem {
    enabled?: boolean;
    icon?: any;
    id?: string;
    path?: string;
    text: string;
    type: 'customerService' | 'page';
  }

  export interface FloatingSchemeSchema {
    enabled: boolean;
    hiddenPages?: string[];
    items: FloatingItem[];
    mode: 'expand' | 'single' | 'vertical';
    offsetBottom: number;
    offsetX: number;
    position: 'left-bottom' | 'right-bottom';
    singleItemId?: string;
    style: Record<string, any>;
  }

  export interface HomeSchemeSchema {
    components?: DecorationModule[];
    modules?: DecorationModule[];
    pageStyle?: Record<string, any>;
  }

  export type SchemeSchema =
    | DecorationModule[]
    | FloatingSchemeSchema
    | HomeSchemeSchema
    | ProfileModule[]
    | TabbarItem[]
    | TabbarSchemeSchema;

  export interface SchemeItem {
    id: number;
    name: string;
    type: SchemeType;
    description?: null | string;
    schema: SchemeSchema;
    tabbar_mode?: TabbarMode;
    sort: number;
    status: number;
    is_active?: number;
    is_system?: number;
    create_time?: string;
    update_time?: string;
  }

  export interface ListParams {
    keyword?: string;
    page?: number;
    limit?: number;
    status?: number;
    type?: SchemeType;
  }

  export interface SaveParams {
    name: string;
    type: SchemeType;
    description?: null | string;
    schema: SchemeSchema;
    tabbar_mode?: TabbarMode;
    sort?: number;
    status?: number;
  }

  export interface ProductPickerGoodsItem {
    id: number;
    category_id?: number;
    brand_id?: number;
    name: string;
    subtitle?: null | string;
    main_image?: null | number | string;
    main_image_full_url?: string;
    price: number | string;
    market_price?: null | number | string;
    sales?: number;
    is_recommend?: number;
    is_new?: number;
    is_hot?: number;
    category_name?: string;
    brand_name?: string;
  }

  export interface ProductPickerCategoryItem {
    id: number;
    pid: number;
    name: string;
    icon?: string;
    image?: string;
    image_full_url?: string;
    sort?: number;
    status?: number;
  }

  export interface ProductPickerBrandItem {
    id: number;
    name: string;
    logo?: string;
    logo_full_url?: string;
    sort?: number;
    status?: number;
  }

  export interface ProductPickerTagItem {
    id: number;
    name: string;
    color?: string;
    sort?: number;
    status?: number;
  }

  export interface ProductSourcePickerResult {
    goods: ProductPickerGoodsItem[];
    categories: ProductPickerCategoryItem[];
    brands: ProductPickerBrandItem[];
    tags: ProductPickerTagItem[];
  }

  export interface TargetPickerItem {
    desc?: string;
    image?: number | string;
    key?: number | string;
    label?: string;
    path: string;
    tags?: string[];
    title: string;
  }

  export interface TargetPickerGroup {
    count?: number;
    items: TargetPickerItem[];
    key: string;
    label: string;
  }

  export interface TargetPickerSection {
    count?: number;
    groups: TargetPickerGroup[];
    key: string;
    label: string;
  }

  export interface TargetPickerResult extends ProductSourcePickerResult {
    pages: {
      groups: ClientPageApi.PagePickerGroup[];
      total: number;
    };
    sections?: TargetPickerSection[];
  }
}

export async function getClientDecorateSchemeListApi(
  params?: ClientDecorateApi.ListParams,
) {
  return requestClient.get<{
    list: ClientDecorateApi.SchemeItem[];
    total: number;
  }>('/client/decorate/scheme/list', { params });
}

export async function getClientDecorateSchemeInfoApi(id: number) {
  return requestClient.get<ClientDecorateApi.SchemeItem>(
    `/client/decorate/scheme/info/${id}`,
  );
}

export async function createClientDecorateSchemeApi(
  data: ClientDecorateApi.SaveParams,
) {
  return requestClient.post<{ id: number }>(
    '/client/decorate/scheme/create',
    data,
  );
}

export async function updateClientDecorateSchemeApi(
  id: number,
  data: ClientDecorateApi.SaveParams,
) {
  return requestClient.put(`/client/decorate/scheme/update/${id}`, data);
}

export async function copyClientDecorateSchemeApi(id: number) {
  return requestClient.post<{ id: number }>(
    `/client/decorate/scheme/copy/${id}`,
  );
}

export async function activateClientDecorateSchemeApi(id: number) {
  return requestClient.put(`/client/decorate/scheme/activate/${id}`);
}

export async function deleteClientDecorateSchemeApi(id: number) {
  return requestClient.delete(`/client/decorate/scheme/delete/${id}`);
}

export async function getClientDecorateProductSourcesApi(params?: {
  keyword?: string;
}) {
  return requestClient.get<ClientDecorateApi.ProductSourcePickerResult>(
    '/client/decorate/scheme/product-sources',
    { params },
  );
}

export async function getClientDecorateTargetPickerApi(params?: {
  keyword?: string;
}) {
  return requestClient.get<ClientDecorateApi.TargetPickerResult>(
    '/client/decorate/scheme/target-picker',
    { params },
  );
}
