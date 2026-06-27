import { requestClient } from '#/api/request';

export namespace ArticleCategoryApi {
  export interface CategoryItem {
    create_time?: string;
    description?: null | string;
    id: number;
    name: string;
    sort: number;
    status: number;
    update_time?: string;
  }

  export interface ListParams {
    keyword?: string;
    limit?: number;
    page?: number;
    status?: number;
  }

  export interface SaveParams {
    description?: null | string;
    name: string;
    sort?: number;
    status?: number;
  }
}

export async function getArticleCategoryListApi(
  params?: ArticleCategoryApi.ListParams,
) {
  return requestClient.get<{
    list: ArticleCategoryApi.CategoryItem[];
    total: number;
  }>('/content/article-category/list', { params });
}

export async function getAllArticleCategoriesApi() {
  return requestClient.get<ArticleCategoryApi.CategoryItem[]>(
    '/content/article-category/all',
  );
}

export async function getArticleCategoryInfoApi(id: number) {
  return requestClient.get<ArticleCategoryApi.CategoryItem>(
    `/content/article-category/info/${id}`,
  );
}

export async function createArticleCategoryApi(
  data: ArticleCategoryApi.SaveParams,
) {
  return requestClient.post<{ id: number }>(
    '/content/article-category/create',
    data,
  );
}

export async function updateArticleCategoryApi(
  id: number,
  data: ArticleCategoryApi.SaveParams,
) {
  return requestClient.put(`/content/article-category/update/${id}`, data);
}

export async function deleteArticleCategoryApi(id: number) {
  return requestClient.delete(`/content/article-category/delete/${id}`);
}

export async function updateArticleCategoryStatusApi(
  id: number,
  status: number,
) {
  return requestClient.put(`/content/article-category/updateStatus/${id}`, {
    status,
  });
}
