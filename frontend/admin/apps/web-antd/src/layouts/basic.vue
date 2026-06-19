<script lang="ts" setup>
import type { NotificationItem } from '@vben/layouts';

import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { useAccess } from '@vben/access';
import { AuthenticationLoginExpiredModal } from '@vben/common-ui';
import { useWatermark } from '@vben/hooks';
import {
  BasicLayout,
  LockScreen,
  Notification,
  UserDropdown,
} from '@vben/layouts';
import { preferences } from '@vben/preferences';
import { useUserStore } from '@vben/stores';

import {
  getNotificationLogisticsConfigApi,
  getNotificationPendingShipmentApi,
  getNotificationRefundPendingApi,
  getNotificationSmsProviderConfigApi,
  getNotificationStockWarningApi,
} from '#/api/notification';
import { $t } from '#/locales';
import { useAccessStore } from '#/modules/access';
import { useAuthStore } from '#/store';
import LoginForm from '#/views/_core/authentication/login.vue';

const router = useRouter();
const userStore = useUserStore();
const authStore = useAuthStore();
const accessStore = useAccessStore();
const { hasAccessByCodes } = useAccess();
const { destroyWatermark, updateWatermark } = useWatermark();
const notifications = ref<NotificationItem[]>([]);
const hasNotificationAccess = ref(false);
const showDot = computed(() =>
  notifications.value.some((item) => !item.isRead),
);

const notificationLoaders = [
  {
    code: 'SystemNotificationBell',
    request: getNotificationPendingShipmentApi,
  },
  {
    code: 'SystemNotificationBell',
    request: getNotificationRefundPendingApi,
  },
  {
    code: 'SystemNotificationBell',
    request: getNotificationStockWarningApi,
  },
  {
    code: 'SystemNotificationBell',
    request: getNotificationLogisticsConfigApi,
  },
  {
    code: 'SystemNotificationBell',
    request: getNotificationSmsProviderConfigApi,
  },
];

function isPermissionDenied(error: unknown): boolean {
  const response = (error as any)?.response;
  const code = (error as any)?.code;
  const errorMessage = (error as any)?.message || response?.data?.message;
  return (
    response?.status === 403 ||
    response?.data?.code === 403 ||
    code === 403 ||
    errorMessage === '没有权限访问该接口'
  );
}

function normalizeNotificationList(items: any[]): NotificationItem[] {
  const map = new Map<number | string, NotificationItem>();

  for (const item of items) {
    if (!item || item.id === undefined) {
      continue;
    }

    const id = item.id as number | string;
    const existing = map.get(id);
    if (!existing) {
      map.set(id, {
        avatar: avatar.value,
        date: item.date,
        id,
        isRead: item.is_read ?? false,
        link: item.link,
        message: item.message,
        query: item.query,
        title: item.title,
      });
      continue;
    }

    if (!existing.isRead && item.is_read) {
      existing.isRead = true;
    }
  }

  return [...map.values()];
}

const menus = computed(() => [
  {
    handler: () => {
      router.push({ name: 'Profile' });
    },
    icon: 'lucide:user',
    text: $t('page.auth.profile'),
  },
]);

const avatar = computed(() => {
  return userStore.userInfo?.avatar ?? preferences.app.defaultAvatar;
});

async function handleLogout() {
  await authStore.logout(false);
}

async function loadNotifications() {
  const loaders = notificationLoaders.filter((loader) =>
    hasAccessByCodes([loader.code]),
  );
  hasNotificationAccess.value = loaders.length > 0;
  notifications.value = [];

  if (loaders.length === 0) {
    return;
  }

  const results = await Promise.allSettled(
    loaders.map((loader) => loader.request()),
  );
  const merged: NotificationItem[] = [];

  for (const result of results) {
    if (result.status === 'fulfilled') {
      merged.push(...normalizeNotificationList(result.value || []));
      continue;
    }

    const denied = isPermissionDenied(result.reason);
    if (!denied) {
      console.error('加载通知失败：', result.reason);
    }
  }

  notifications.value = merged;
}

function handleNoticeClear() {
  notifications.value = [];
}

function markRead(id: number | string) {
  const item = notifications.value.find((item) => item.id === id);
  if (item) {
    item.isRead = true;
  }
}

function remove(id: number | string) {
  notifications.value = notifications.value.filter((item) => item.id !== id);
}

function handleMakeAll() {
  notifications.value.forEach((item) => (item.isRead = true));
}

function handleViewAll() {
  router.push('/workspace');
}

watch(
  () => accessStore.accessCodes,
  () => {
    loadNotifications();
  },
  {
    immediate: true,
  },
);

watch(
  () => ({
    enable: preferences.app.watermark,
    content: preferences.app.watermarkContent,
  }),
  async ({ enable, content }) => {
    if (enable) {
      await updateWatermark({
        content:
          content ||
          `${userStore.userInfo?.username} - ${userStore.userInfo?.nickname}`,
      });
    } else {
      destroyWatermark();
    }
  },
  {
    immediate: true,
  },
);
</script>

<template>
  <BasicLayout @clear-preferences-and-logout="handleLogout">
    <template #user-dropdown>
      <UserDropdown
        :avatar
        :menus
        :text="userStore.userInfo?.username"
        :description="userStore.userInfo?.nickname"
        @logout="handleLogout"
      />
    </template>
    <template #notification>
      <Notification
        v-if="hasNotificationAccess"
        :dot="showDot"
        :notifications="notifications"
        @clear="handleNoticeClear"
        @read="(item) => item.id && markRead(item.id)"
        @remove="(item) => item.id && remove(item.id)"
        @make-all="handleMakeAll"
        @view-all="handleViewAll"
      />
    </template>
    <template #extra>
      <AuthenticationLoginExpiredModal
        v-model:open="accessStore.loginExpired"
        :avatar
      >
        <LoginForm />
      </AuthenticationLoginExpiredModal>
    </template>
    <template #lock-screen>
      <LockScreen :avatar @to-login="handleLogout" />
    </template>
  </BasicLayout>
</template>
