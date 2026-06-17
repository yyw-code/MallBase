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
      const refreshToken = loginResult.refresh_token;

      // 如果成功获取到 accessToken
      if (accessToken) {
        accessStore.setAccessToken(accessToken);
        // 存储 refreshToken 用于双 token 刷新
        if (refreshToken) {
          accessStore.setRefreshToken(refreshToken);
        }
        accessStore.setMustChangePassword(!!loginResult.must_change_password);
        accessStore.setIsAccessChecked(false);
        accessStore.setAccessCodes([]);
        accessStore.setAccessMenus([]);
        accessStore.setAccessRoutes([]);

        // 获取用户信息
        adminInfo = await fetchUserInfo();

        if (accessStore.loginExpired) {
          accessStore.setLoginExpired(false);
        } else if (loginResult.must_change_password) {
          // 首次登录（或从未改过密码）强制跳转到改密页，改密成功后由页面自行进入首页
          await router.push('/auth/change-password');
        } else {
          await (onSuccess
            ? onSuccess?.()
            : router.push(
                userStore.userInfo?.homePath || preferences.app.defaultHomePath,
              ));
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

  /**
   * 退出登录
   * @param redirect 是否跳转到登录页并携带当前路由地址
   * @param callLogoutApi 是否调用后端退出接口（token 过期时不应调用）
   */
  async function logout(
    redirect: boolean = true,
    callLogoutApi: boolean = true,
  ) {
    // 调用后端 API 使服务端 token 失效（仅在 token 有效时调用）
    if (callLogoutApi) {
      try {
        await logoutApi();
      } catch {
        // 接口报错也继续退出
      }
    }
    // 清除本地状态
    resetAllStores();
    accessStore.setLoginExpired(false);
    accessStore.setMustChangePassword(false);

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
          avatar: adminInfo.avatar_full_url || '',
          id: String(adminInfo.id),
          nickname: adminInfo.nickname || '',
          roles: adminInfo.roles?.map((role) => role.code) || [],
          username: adminInfo.username || '',
          email: adminInfo.email || '',
          homePath: adminInfo.home_path || '',
        };
        userStore.setUserInfo(userInfo);
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
