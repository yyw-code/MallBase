/**
 * 该文件可自行根据业务逻辑进行调整
 */
import type { RequestClientOptions } from '@vben/request';

import { useAppConfig } from '@vben/hooks';
import { preferences } from '@vben/preferences';
import {
  authenticateResponseInterceptor,
  defaultResponseInterceptor,
  errorMessageResponseInterceptor,
  RequestClient,
} from '@vben/request';

import { message } from 'ant-design-vue';

import { useAccessStore } from '#/modules/access';
import { useAuthStore } from '#/store';

import { refreshTokenApi } from './core';

const { apiURL } = useAppConfig(import.meta.env, import.meta.env.PROD);

function createRequestClient(baseURL: string, options?: RequestClientOptions) {
  const client = new RequestClient({
    ...options,
    baseURL,
  });

  /**
   * 重新认证逻辑（refresh token 也失效时调用）
   * 不调用后端 logout API，因为 token 已经过期了
   * 只清除本地状态并跳转到登录页
   */
  async function doReAuthenticate() {
    console.warn('Access token or refresh token is invalid or expired. ');
    const accessStore = useAccessStore();
    const authStore = useAuthStore();
    accessStore.setAccessToken(null);
    accessStore.setRefreshToken(null);
    if (
      preferences.app.loginExpiredMode === 'modal' &&
      accessStore.isAccessChecked
    ) {
      accessStore.setLoginExpired(true);
    } else {
      // token 已失效，不调用后端 logout API
      await authStore.logout(true, false);
    }
  }

  /**
   * 刷新token逻辑
   * 使用 refreshToken 换取新的 accessToken
   */
  async function doRefreshToken(): Promise<string> {
    const accessStore = useAccessStore();
    // refreshTokenApi 使用 baseRequestClient，返回原始 Axios 响应
    const response = (await refreshTokenApi(accessStore.refreshToken)) as any;
    const body = response.data;

    // 检查刷新接口是否成功（后端可能返回 code: 400 表示 refresh_token 也过期了）
    if (!body || body.code !== 200 || !body.data?.access_token) {
      const error: any = new Error('REFRESH_TOKEN_FAILED');
      error.backendMessage = body?.message || '刷新令牌无效或已过期';
      throw error;
    }

    const tokenData = body.data;
    accessStore.setAccessToken(tokenData.access_token);
    if (tokenData.refresh_token) {
      accessStore.setRefreshToken(tokenData.refresh_token);
    }
    return tokenData.access_token;
  }

  function formatToken(token: null | string) {
    return token ? `Bearer ${token}` : null;
  }

  // 请求头处理
  client.addRequestInterceptor({
    fulfilled: async (config) => {
      const accessStore = useAccessStore();
      config.headers.Authorization = formatToken(accessStore.accessToken);
      config.headers['Accept-Language'] = preferences.app.locale;
      config.headers['X-MallBase-Client'] = 'admin_web';
      return config;
    },
  });

  // 【关键】将后端 body code:401 转换为 HTTP status 401
  // 后端返回 HTTP 200 + body {code: 401}，需要转换为 HTTP 401
  // 这样后续的 defaultResponseInterceptor 和 authenticateResponseInterceptor 才能正确处理
  client.addResponseInterceptor({
    fulfilled: (response) => {
      const responseData = response.data;
      if (responseData && responseData.code) {
        // 修改 response.status 为 401，让后续拦截器按 HTTP 401 处理
        response.status = responseData.code;
      }
      return response;
    },
  });

  // 使用官方 defaultResponseInterceptor 处理响应数据格式
  // 后端返回格式：{ code: 200, data: {...}, message: "成功" }
  // status 不在 200-399 范围内时（如上面改成 401）会抛错
  client.addResponseInterceptor(
    defaultResponseInterceptor({
      codeField: 'code',
      dataField: 'data',
      successCode: 200,
    }),
  );

  // token 过期处理（authenticateResponseInterceptor 检查 HTTP status === 401）
  client.addResponseInterceptor(
    authenticateResponseInterceptor({
      client,
      doReAuthenticate,
      doRefreshToken,
      enableRefreshToken: preferences.app.enableRefreshToken,
      formatToken,
    }),
  );

  // 通用的错误处理
  client.addResponseInterceptor(
    errorMessageResponseInterceptor((msg: string, error) => {
      // 刷新 token 失败时，显示后端返回的错误消息（跳转登录页已由 doReAuthenticate 处理）
      if (error?.message === 'REFRESH_TOKEN_FAILED') {
        message.error(error.backendMessage || '刷新令牌无效或已过期');
        return;
      }
      const responseData = error?.response?.data;
      const errorMessage =
        responseData && typeof responseData === 'object'
          ? (responseData?.error ?? responseData?.message ?? '')
          : '';
      // 如果没有错误信息，则会根据状态码进行提示

      message.error(errorMessage || msg);
    }),
  );

  // 刷新 token 失败后，阻止错误继续传播到业务层
  // 此时 doReAuthenticate() 已跳转登录页，业务层无需再处理该错误
  client.addResponseInterceptor({
    rejected: (error: any) => {
      if (error?.message === 'REFRESH_TOKEN_FAILED') {
        // 返回永远 pending 的 Promise，阻止错误传播到 useTableCrud 等业务层
        // 页面即将跳转到登录页，该 Promise 会被垃圾回收
        return new Promise(() => {});
      }
      return Promise.reject(error);
    },
  });

  return client;
}

export const requestClient = createRequestClient(apiURL, {
  responseReturn: 'data',
});

export const baseRequestClient = new RequestClient({ baseURL: apiURL });

baseRequestClient.addRequestInterceptor({
  fulfilled: async (config) => {
    config.headers['X-MallBase-Client'] = 'admin_web';
    return config;
  },
});

function createPublicRequestClient(baseURL: string) {
  const client = new RequestClient({
    baseURL,
    responseReturn: 'data',
  });

  client.addRequestInterceptor({
    fulfilled: async (config) => {
      config.headers['Accept-Language'] = preferences.app.locale;
      config.headers['X-MallBase-Client'] = 'admin_web';
      return config;
    },
  });

  client.addResponseInterceptor(
    defaultResponseInterceptor({
      codeField: 'code',
      dataField: 'data',
      successCode: 200,
    }),
  );

  return client;
}

export const publicRequestClient = createPublicRequestClient(apiURL);
