import { publicRequestClient, requestClient } from '#/api/request';

export namespace ConfigApi {
  /** 颜色选项 */
  export interface ColorOption {
    value: string;
    label: string;
    color: string;
  }

  /** 颜色选项响应 */
  export interface ColorOptionsResponse {
    options: ColorOption[];
  }

  /**
   * 后台应用元数据（appMeta）
   *
   * 字段来自 mb_setting 的 SystemBasic + SystemCopyright 两组。
   * 后端 SystemSettingService 已把图片字段转为 full_url，其余为原字符串/开关值。
   */
  export interface AppMeta {
    // SystemBasic
    site_name?: string;
    site_slogan?: string;
    site_url?: string;
    default_avatar?: string;
    admin_logo?: string;
    admin_favicon?: string;
    admin_slogan_image?: string;
    admin_login_title?: string;
    admin_login_subtitle?: string;
    admin_login_welcome?: string;
    admin_login_welcome_desc?: string;
    // SystemCopyright（后台与 Client 共用）
    copyright_enabled?: string;
    copyright_company?: string;
    copyright_company_url?: string;
    copyright_date?: string;
    copyright_icp?: string;
    copyright_icp_url?: string;
    copyright_psb?: string;
    copyright_psb_url?: string;
    // 允许未来新增字段
    [key: string]: unknown;
  }
}

/**
 * 获取颜色选项列表
 */
export async function getColorOptionsApi() {
  return requestClient.get<ConfigApi.ColorOptionsResponse>(
    '/config/colorOptions',
  );
}

/**
 * 获取后台应用元数据（bootstrap 阶段调用；公开接口，无需登录）
 * 用返回值覆盖 vben preferences.app.name / logo / copyright，替换 favicon
 */
export async function getAppMetaApi() {
  return requestClient.get<ConfigApi.AppMeta>('/config/appMeta');
}

/**
 * 获取后台应用元数据（启动阶段公开请求，无需依赖鉴权状态）
 */
export async function getPublicAppMetaApi() {
  return publicRequestClient.get<ConfigApi.AppMeta>('/config/appMeta');
}
