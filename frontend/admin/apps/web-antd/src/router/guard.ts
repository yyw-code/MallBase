import type { Router, RouteRecordRaw } from 'vue-router';

import { LOGIN_PATH } from '@vben/constants';
import { preferences } from '@vben/preferences';
import { useUserStore } from '@vben/stores';
import { startProgress, stopProgress } from '@vben/utils';

import { getAccessCodesApi } from '#/api/core/auth';
import { useAccessStore } from '#/modules/access';
import { accessRoutes, coreRouteNames } from '#/router/routes';
import { useAuthStore } from '#/store';

import { generateAccess } from './access';

function normalizePath(path: string): string {
  return path.length > 1 ? path.replace(/\/+$/, '') : path;
}

function canAccessRoute(
  path: string,
  name: null | string | symbol | undefined,
  routes: RouteRecordRaw[],
): boolean {
  const targetPath = normalizePath(path);
  const targetName = name ? String(name) : '';

  function search(items: RouteRecordRaw[]): boolean {
    return items.some((route) => {
      if (targetName && String(route.name || '') === targetName) {
        return true;
      }

      const routePath = normalizePath(String(route.path || ''));
      if (routePath === targetPath) {
        return true;
      }
      return Array.isArray(route.children) && search(route.children);
    });
  }

  return search(routes);
}

function findFirstAccessiblePath(routes: RouteRecordRaw[]): string | undefined {
  for (const route of routes) {
    const routePath = String(route.path || '');
    if (routePath.startsWith('/')) {
      return routePath;
    }

    if (Array.isArray(route.children)) {
      const childPath = findFirstAccessiblePath(route.children);
      if (childPath) {
        return childPath;
      }
    }
  }
}

/**
 * 通用守卫配置
 * @param router
 */
function setupCommonGuard(router: Router) {
  // 记录已经加载的页面
  const loadedPaths = new Set<string>();

  router.beforeEach((to) => {
    to.meta.loaded = loadedPaths.has(to.path);

    // 页面加载进度条
    if (!to.meta.loaded && preferences.transition.progress) {
      startProgress();
    }
    return true;
  });

  router.afterEach((to) => {
    // 记录页面是否加载,如果已经加载，后续的页面切换动画等效果不在重复执行

    loadedPaths.add(to.path);

    // 关闭页面加载进度条
    if (preferences.transition.progress) {
      stopProgress();
    }
  });
}

/**
 * 权限访问守卫配置
 * @param router
 */
function setupAccessGuard(router: Router) {
  router.beforeEach(async (to, from) => {
    const accessStore = useAccessStore();
    const userStore = useUserStore();
    const authStore = useAuthStore();

    // 基本路由，这些路由不需要进入权限拦截
    if (coreRouteNames.includes(to.name as string)) {
      return true;
    }

    // 登录页特殊处理
    if (to.path === LOGIN_PATH) {
      if (accessStore.accessToken) {
        if (userStore.userInfo) {
          // 有有效的 token 和用户信息，重定向到首页
          return decodeURIComponent(
            (to.query?.redirect as string) ||
              userStore.userInfo?.homePath ||
              preferences.app.defaultHomePath,
          );
        } else {
          // 如果有 token 但没有用户信息，说明 token 可能无效，清除它
          accessStore.setAccessToken(null);
        }
      }
      return true;
    }

    // accessToken 检查
    if (!accessStore.accessToken) {
      // 明确声明忽略权限访问权限，则可以访问
      if (to.meta.ignoreAccess) {
        return true;
      }

      // 没有访问权限，跳转登录页面
      if (to.fullPath !== LOGIN_PATH) {
        return {
          path: LOGIN_PATH,
          // 如不需要，直接删除 query
          query:
            to.fullPath === preferences.app.defaultHomePath
              ? {}
              : { redirect: encodeURIComponent(to.fullPath) },
          // 携带当前跳转的页面，登录后重新跳转该页面
          replace: true,
        };
      }
      // 已经在登录页了，不需要做任何操作
      return true;
    }

    // 是否已经生成过动态路由
    if (accessStore.isAccessChecked) {
      if (!canAccessRoute(to.path, to.name, accessStore.accessRoutes)) {
        const fallbackPath =
          findFirstAccessiblePath(accessStore.accessRoutes) ||
          userStore.userInfo?.homePath ||
          preferences.app.defaultHomePath;

        return normalizePath(fallbackPath) === normalizePath(to.path)
          ? true
          : fallbackPath;
      }
      return true;
    }

    // 生成路由表
    // 当前登录用户拥有的角色标识列表
    let userInfo = userStore.userInfo;
    if (!userInfo) {
      const adminInfo = await authStore.fetchUserInfo();
      if (!adminInfo) {
        // 如果获取用户信息失败，跳转到登录页
        return {
          path: LOGIN_PATH,
          replace: true,
        };
      }
      userInfo = userStore.userInfo;
    }

    // 获取用户权限码（按钮级权限）
    try {
      const permissions = await getAccessCodesApi();
      accessStore.setAccessCodes(
        Array.isArray(permissions?.access_codes)
          ? permissions.access_codes
          : [],
      );
    } catch (error) {
      console.error('获取权限码失败:', error);
      accessStore.setAccessCodes([]);
    }

    const userRoles = userInfo?.roles ?? [];

    // 生成菜单和路由
    const { accessibleMenus, accessibleRoutes } = await generateAccess({
      roles: userRoles,
      router,
      // 则会在菜单中显示，但是访问会被重定向到403
      routes: accessRoutes,
    });

    // 保存菜单信息和路由信息
    accessStore.setAccessMenus(accessibleMenus);
    accessStore.setAccessRoutes(accessibleRoutes);
    accessStore.setIsAccessChecked(true);

    const userHomePath =
      userStore.userInfo?.homePath || preferences.app.defaultHomePath;
    const redirectPath = (from.query.redirect ||
      (to.fullPath === preferences.app.defaultHomePath
        ? userHomePath
        : to.fullPath) ||
      userHomePath) as string;

    return {
      ...router.resolve(decodeURIComponent(redirectPath)),
      replace: true,
    };
  });
}

/**
 * 项目守卫配置
 * @param router
 */
function createRouterGuard(router: Router) {
  /** 通用 */
  setupCommonGuard(router);
  /** 权限访问 */
  setupAccessGuard(router);
}

export { createRouterGuard };
