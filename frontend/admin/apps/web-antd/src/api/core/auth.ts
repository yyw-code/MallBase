import { baseRequestClient, requestClient } from '#/api/request';

export namespace AuthApi {
  /** 登录接口参数 */
  export interface LoginParams {
    password: string;
    username: number | string;
  }

  /** 登录接口返回值 */
  export interface LoginResult {
    access_token: string;
    refresh_token: string;
    token_type?: string;
    expires_in?: number;
  }

  /** 刷新token返回值 */
  export interface RefreshTokenResult {
    access_token: string;
    refresh_token: string;
    token_type?: string;
    expires_in?: number;
  }

  /** 当前登录用户信息 */
  export interface CurrentAdminInfo {
    id: number;
    username: string;
    nickname: string;
    avatar: string;
    email: string;
    mobile: string;
    status: number;
    roles?: Array<{
      code: string;
      id: number;
      name: string;
    }>;
    home_path: string;
  }

  /** 用户权限码返回值 */
  export interface AccessCodesResult {
    access_codes: string[];
  }
}

/**
 * 登录
 * 后端接口路径：/admin/api/auth/admin/login
 * 参数通过 body 传递：{ username, password }
 */
export async function loginApi(data: AuthApi.LoginParams) {
  return requestClient.post<AuthApi.LoginResult>('/auth/admin/login', data);
}

/**
 * 刷新accessToken
 * 使用 refresh_token 换取新的 access_token
 */
export async function refreshTokenApi(refreshToken?: null | string) {
  return baseRequestClient.post<AuthApi.RefreshTokenResult>(
    '/auth/admin/refreshToken',
    {
      refresh_token: refreshToken,
    },
  );
}

/**
 * 退出登录
 * 仅在主动退出时调用（token 有效），使用 requestClient 自动带 token
 */
export async function logoutApi() {
  return requestClient.post('/auth/admin/logout');
}

/**
 * 获取当前登录管理员信息
 */
export async function getCurrentAdminInfoApi() {
  return requestClient.get<AuthApi.CurrentAdminInfo>('/auth/admin/adminInfo');
}

/**
 * 获取用户权限码（按钮级权限）
 */
export async function getAccessCodesApi() {
  return requestClient.get<AuthApi.AccessCodesResult>(
    '/auth/permission/getAccessCodes',
  );
}
