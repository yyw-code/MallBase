import { requestClient } from '#/api/request';

export namespace ArticleApi {
  export interface ArticleItem {
    category_id: number;
    category_name?: string;
    content?: string;
    cover?: number | string;
    cover_full_url?: string;
    create_time?: string;
    description?: null | string;
    id: number;
    read_count: number;
    sort: number;
    status: number;
    title: string;
    update_time?: string;
  }

  export interface ListParams {
    category_id?: number;
    keyword?: string;
    limit?: number;
    page?: number;
    status?: number;
  }

  export interface SaveParams {
    category_id: number;
    content?: string;
    cover?: number | string;
    description?: null | string;
    sort?: number;
    status?: number;
    title: string;
  }

  export interface ReadRecordItem {
    article_id: number;
    article_title?: string;
    create_time?: string;
    first_read_time: string;
    id: number;
    last_read_time: string;
    read_count: number;
    user_avatar?: null | string;
    user_avatar_full_url?: string;
    user_email?: null | string;
    user_id: number;
    user_mobile?: null | string;
    user_nickname?: string;
  }

  export interface ReadRecordParams {
    article_id?: number;
    end_time?: string;
    keyword?: string;
    limit?: number;
    page?: number;
    start_time?: string;
  }
}

export async function getArticleListApi(params?: ArticleApi.ListParams) {
  return requestClient.get<{
    list: ArticleApi.ArticleItem[];
    total: number;
  }>('/content/article/list', { params });
}

export async function getArticleInfoApi(id: number) {
  return requestClient.get<ArticleApi.ArticleItem>(
    `/content/article/info/${id}`,
  );
}

export async function createArticleApi(data: ArticleApi.SaveParams) {
  return requestClient.post<{ id: number }>('/content/article/create', data);
}

export async function updateArticleApi(
  id: number,
  data: ArticleApi.SaveParams,
) {
  return requestClient.put(`/content/article/update/${id}`, data);
}

export async function deleteArticleApi(id: number) {
  return requestClient.delete(`/content/article/delete/${id}`);
}

export async function updateArticleStatusApi(id: number, status: number) {
  return requestClient.put(`/content/article/updateStatus/${id}`, { status });
}

export async function getArticleReadRecordsApi(
  params?: ArticleApi.ReadRecordParams,
) {
  return requestClient.get<{
    list: ArticleApi.ReadRecordItem[];
    total: number;
  }>('/content/article/read-records', { params });
}
