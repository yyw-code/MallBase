import type { AuthApi } from '#/api/core/auth';

import { ref } from 'vue';
import { useRouter } from 'vue-router';

import { LOGIN_PATH } from '@vben/constants';
import { preferences } from '@vben/preferences';
import { resetAllStores } from '@vben/stores';

import { notification } from 'ant-design-vue';
import { defineStore } from 'pinia';

import { loginApi, logoutApi } from '#/api/core';
import { getCurrentAdminInfoApi } from '#/api/core/auth';
import { $t } from '#/locales';
import { useAccessStore } from '#/modules/access';
import { useUserStore } from '#/modules/user';

export const useAuthStore = defineStore('auth', () => {
  const accessStore = useAccessStore();
  const userStore = useUserStore();
  const router = useRouter();

  const loginLoading = ref(false);

  /**
   * 异步处理登录操作
   * Asynchronously handle login process
   * @param params 登录表单数据
   * @param onSuccess
   */
  async function authLogin(
    params: Record<string, any>,
    onSuccess?: () => Promise<void> | void,
  ) {
    // 异步处理用户登录操作并获取 accessToken
    let adminInfo: AuthApi.CurrentAdminInfo | null = null;
    try {
      loginLoading.value = true;
      const loginResult = await loginApi(params as AuthApi.LoginParams);

      const accessToken = loginResult.access_token;

      // 如果成功获取到 accessToken
      if (accessToken) {
        accessStore.setAccessToken(accessToken);

        // 获取用户信息
        adminInfo = await getCurrentAdminInfoApi();

        // 如果有权限信息，设置权限码
        if (adminInfo?.permissions) {
          accessStore.setAccessCodes(adminInfo.permissions);
        }

        if (accessStore.loginExpired) {
          accessStore.setLoginExpired(false);
        } else {
          onSuccess
            ? await onSuccess?.()
            : await router.push(
                adminInfo?.home_path || preferences.app.defaultHomePath,
              );
        }

        if (adminInfo?.nickname) {
          notification.success({
            description: `${$t('authentication.loginSuccessDesc')}:${adminInfo?.nickname}`,
            duration: 3,
            message: $t('authentication.loginSuccess'),
          });
        }
      }
    } finally {
      loginLoading.value = false;
    }

    return {
      adminInfo,
    };
  }

  async function logout(redirect: boolean = true) {
    try {
      await logoutApi();
    } catch {
      // 不做任何处理
    }
    resetAllStores();
    accessStore.setLoginExpired(false);

    // 回登录页带上当前路由地址
    await router.replace({
      path: LOGIN_PATH,
      query: redirect
        ? {
            redirect: encodeURIComponent(router.currentRoute.value.fullPath),
          }
        : {},
    });
  }

  async function fetchUserInfo() {
    let adminInfo: AuthApi.CurrentAdminInfo | null = null;
    try {
      adminInfo = await getCurrentAdminInfoApi();

      // 转换为 BasicUserInfo 格式
      if (adminInfo) {
        const userInfo = {
          avatar: adminInfo.avatar || '',
          id: String(adminInfo.id),
          nickname: adminInfo.nickname || adminInfo.username,
          roles: adminInfo.roles?.map((role) => role.code) || [],
          username: adminInfo.username,
        };
        userStore.setUserInfo(userInfo);
      }

      // 如果用户信息中有权限，直接设置
      if (adminInfo?.permissions) {
        accessStore.setAccessCodes(adminInfo.permissions);
      }
    } catch (error) {
      console.error('获取用户信息失败:', error);
    }

    return adminInfo;
  }

  function $reset() {
    loginLoading.value = false;
  }

  return {
    $reset,
    authLogin,
    fetchUserInfo,
    loginLoading,
    logout,
  };
});
