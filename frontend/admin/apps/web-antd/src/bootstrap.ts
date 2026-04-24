import { createApp, watchEffect } from 'vue';

import { registerAccessDirective } from '@vben/access';
import { registerLoadingDirective } from '@vben/common-ui/es/loading';
import { preferences, updatePreferences } from '@vben/preferences';
import { initStores } from '@vben/stores';
import '@vben/styles';
import '@vben/styles/antd';

import { useTitle } from '@vueuse/core';
import Antd from 'ant-design-vue';

import { getPublicAppMetaApi } from '#/api/core/config';
import { $t, setupI18n } from '#/locales';
import { updateLoginPageMeta } from '#/modules/app-meta';

import { initComponentAdapter } from './adapter/component';
import { initSetupVbenForm } from './adapter/form';
import App from './app.vue';
import { router } from './router';

import 'ant-design-vue/dist/reset.css';

/**
 * 从后端拉取应用元数据并覆盖 preferences / favicon。
 *
 * 约束：
 * - 失败不阻断首屏（走 try/catch，只记录到 console.warn）。
 * - 只覆盖"确有非空值"的字段，避免后端一项没配就把前端默认值清空。
 * - favicon 用 DOM 动态替换，不依赖构建期产物。
 */
async function applyAppMetaFromBackend(): Promise<void> {
  try {
    const meta = await getPublicAppMetaApi();
    if (!meta) {
      return;
    }

    const appPatch: Record<string, string> = {};
    if (meta.site_name) {
      appPatch.name = meta.site_name;
    }

    const logoPatch: { source?: string } = {};
    if (meta.admin_logo) {
      logoPatch.source = meta.admin_logo;
    }

    updateLoginPageMeta({
      loginSubtitle: meta.admin_login_welcome_desc
        ? String(meta.admin_login_welcome_desc)
        : '',
      loginTitle: meta.admin_login_welcome
        ? String(meta.admin_login_welcome)
        : '',
      pageDescription: meta.admin_login_subtitle
        ? String(meta.admin_login_subtitle)
        : '',
      pageTitle: meta.admin_login_title ? String(meta.admin_login_title) : '',
      sloganImage: meta.admin_slogan_image
        ? String(meta.admin_slogan_image)
        : '',
    });

    const copyrightPatch: {
      companyName?: string;
      companySiteLink?: string;
      date?: string;
      enable?: boolean;
      icp?: string;
      icpLink?: string;
    } = {};
    if (meta.copyright_enabled !== undefined) {
      copyrightPatch.enable = String(meta.copyright_enabled) === '1';
    }
    if (meta.copyright_company) {
      copyrightPatch.companyName = meta.copyright_company;
    }
    if (meta.copyright_company_url) {
      copyrightPatch.companySiteLink = meta.copyright_company_url;
    }
    if (meta.copyright_date) {
      copyrightPatch.date = meta.copyright_date;
    }
    if (meta.copyright_icp) {
      copyrightPatch.icp = meta.copyright_icp;
    }
    if (meta.copyright_icp_url) {
      copyrightPatch.icpLink = meta.copyright_icp_url;
    }

    // 批量更新，单次响应式触发
    const patch: Record<string, unknown> = {};
    if (Object.keys(appPatch).length > 0) {
      patch.app = appPatch;
    }
    if (Object.keys(logoPatch).length > 0) {
      patch.logo = logoPatch;
    }
    if (Object.keys(copyrightPatch).length > 0) {
      patch.copyright = copyrightPatch;
    }
    if (Object.keys(patch).length > 0) {
      updatePreferences(patch);
    }

    // 动态替换 favicon
    if (meta.admin_favicon) {
      const head = document.querySelector('head');
      if (head) {
        let link =
          head.querySelector<HTMLLinkElement>('link[rel*="icon"]') ?? null;
        if (!link) {
          link = document.createElement('link');
          link.rel = 'icon';
          head.append(link);
        }
        link.href = meta.admin_favicon;
      }
    }
  } catch (error: unknown) {
    // 后端未安装 / 接口异常不阻断前端启动，使用 vben 默认 preferences
    // 不用 console.error 以免在正常未装场景污染控制台
    console.warn('[bootstrap] fetch app meta failed, use defaults', error);
  }
}

async function bootstrap(namespace: string) {
  // 先拉取后端应用元数据（覆盖 preferences / favicon）；失败不阻塞
  await applyAppMetaFromBackend();

  // 初始化组件适配器
  await initComponentAdapter();

  // 初始化表单组件
  await initSetupVbenForm();

  // // 设置弹窗的默认配置
  // setDefaultModalProps({
  //   fullscreenButton: false,
  // });
  // // 设置抽屉的默认配置
  // setDefaultDrawerProps({
  //   zIndex: 1020,
  // });

  const app = createApp(App);

  // 全局注册 ant-design-vue
  app.use(Antd);

  // 注册v-loading指令
  registerLoadingDirective(app, {
    loading: 'loading', // 在这里可以自定义指令名称，也可以明确提供false表示不注册这个指令
    spinning: 'spinning',
  });

  // 国际化 i18n 配置
  await setupI18n(app);

  // 配置 pinia-tore
  await initStores(app, { namespace });

  // 安装权限指令
  registerAccessDirective(app);

  // 初始化 tippy
  const { initTippy } = await import('@vben/common-ui/es/tippy');
  initTippy(app);

  // 配置路由及路由守卫
  app.use(router);

  // 配置Motion插件
  const { MotionPlugin } = await import('@vben/plugins/motion');
  app.use(MotionPlugin);

  // 动态更新标题
  watchEffect(() => {
    if (preferences.app.dynamicTitle) {
      const routeTitle = router.currentRoute.value.meta?.title;
      const pageTitle =
        (routeTitle ? `${$t(routeTitle)} - ` : '') + preferences.app.name;
      useTitle(pageTitle);
    }
  });

  app.mount('#app');
}

export { bootstrap };
