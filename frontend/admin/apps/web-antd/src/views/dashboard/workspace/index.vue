<script lang="ts" setup>
import type { WorkspaceApi } from '#/api/workspace';

import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import { useAccess } from '@vben/access';
import { WorkbenchHeader } from '@vben/common-ui';
import { IconifyIcon } from '@vben/icons';
import { preferences } from '@vben/preferences';
import { useUserStore } from '@vben/stores';
import { openWindow } from '@vben/utils';

import { message, Modal } from 'ant-design-vue';

import {
  getPublicDemoResetStatusApi,
  resetDemoDataApi,
} from '#/api/demo/reset';
import {
  getWorkspaceMenuOptionsApi,
  getWorkspaceShortcutsApi,
  getWorkspaceTodoLogisticsConfigApi,
  getWorkspaceTodoPendingShipmentApi,
  getWorkspaceTodoRefundPendingApi,
  getWorkspaceTodoSmsProviderConfigApi,
  getWorkspaceTodoStockWarningApi,
  updateWorkspaceShortcutsApi,
} from '#/api/workspace';
import { useAccessStore } from '#/modules/access';

const MAX_SHORTCUTS = 8;

const userStore = useUserStore();
const accessStore = useAccessStore();
const { hasAccessByCodes } = useAccess();
const router = useRouter();

const loading = ref(false);
const saving = ref(false);
const resetting = ref(false);
const shortcutModalOpen = ref(false);
const hasTodoAccess = ref(false);
const hasShortcutAccess = ref(false);
const hasMenuOptionsAccess = ref(false);
const hasShortcutUpdateAccess = ref(false);
const todos = ref<WorkspaceApi.Todo[]>([]);
const shortcuts = ref<WorkspaceApi.Shortcut[]>([]);
const menuOptions = ref<WorkspaceApi.Shortcut[]>([]);
const shortcutDraftPaths = ref<string[]>([]);

const canResetDemo = computed(() =>
  hasAccessByCodes(['SystemDemoResetExecute']),
);
const pendingTodoCount = computed(() =>
  todos.value.reduce((total, item) => total + Number(item.count || 0), 0),
);
const canEditShortcuts = computed(
  () =>
    hasShortcutAccess.value &&
    hasMenuOptionsAccess.value &&
    hasShortcutUpdateAccess.value,
);
const hasWorkspaceBlocks = computed(
  () => hasTodoAccess.value || hasShortcutAccess.value,
);
const workspaceStats = computed(() => {
  const stats: Array<{ label: string; value: number | string }> = [];

  if (hasTodoAccess.value) {
    stats.push({
      label: '待办',
      value: `${pendingTodoCount.value} 项`,
    });
  }

  if (hasShortcutAccess.value) {
    stats.push({
      label: '快捷入口',
      value: shortcuts.value.length,
    });
  }

  return stats;
});
const headerDescription = computed(() => {
  if (hasTodoAccess.value) {
    return `当前有 ${pendingTodoCount.value} 项待处理，建议优先处理订单、售后和库存风险。`;
  }

  if (hasShortcutAccess.value) {
    return '可以通过快捷导航进入常用功能。';
  }

  return '暂无可访问的工作台模块。';
});

const menuSelectOptions = computed(() =>
  menuOptions.value.map((item) => ({
    label: `${item.title}（${item.path}）`,
    value: item.path,
  })),
);

const levelClassMap: Record<WorkspaceApi.Todo['level'], string> = {
  danger:
    'border-red-200 bg-red-50 text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-300',
  info: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/30 dark:text-blue-300',
  warning:
    'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300',
};

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

function hasAccess(code: string): boolean {
  return hasAccessByCodes([code]);
}

async function waitDemoResetDone() {
  for (let i = 0; i < 60; i++) {
    const status = await getPublicDemoResetStatusApi();
    if (status.status === 'success') {
      return status;
    }
    if (status.status === 'error') {
      throw new Error(status.message || '演示数据恢复失败');
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }

  throw new Error('演示数据恢复仍在执行，请稍后刷新后重试');
}

function handleResetDemoData() {
  Modal.confirm({
    title: '恢复演示数据',
    content:
      '该操作会将演示站数据恢复到安装演示状态，当前演示数据会被重置。确认继续？',
    okText: '确认恢复',
    okType: 'danger',
    cancelText: '取消',
    async onOk() {
      resetting.value = true;
      try {
        await resetDemoDataApi();
        await waitDemoResetDone();
        message.success('演示数据已恢复，请重新登录查看最新数据');
      } catch (error) {
        message.error(
          error instanceof Error ? error.message : '演示数据恢复失败',
        );
      } finally {
        resetting.value = false;
      }
    },
  });
}

async function loadWorkspaceData() {
  loading.value = true;
  hasTodoAccess.value = false;
  hasShortcutAccess.value = false;
  hasMenuOptionsAccess.value = false;
  hasShortcutUpdateAccess.value = hasAccess('SystemWorkspaceShortcutsUpdate');
  todos.value = [];
  shortcuts.value = [];
  menuOptions.value = [];

  try {
    if (hasAccess('SystemWorkspaceShortcuts')) {
      hasShortcutAccess.value = true;
      try {
        shortcuts.value = await getWorkspaceShortcutsApi();
      } catch (error) {
        if (!isPermissionDenied(error)) {
          console.error('加载快捷导航失败:', error);
        }
      }
    }

    if (hasAccess('SystemWorkspaceMenuOptions')) {
      hasMenuOptionsAccess.value = true;
      try {
        menuOptions.value = await getWorkspaceMenuOptionsApi();
      } catch (error) {
        if (!isPermissionDenied(error)) {
          console.error('加载菜单选项失败:', error);
        }
      }
    }

    const todoLoaders = [
      {
        code: 'SystemWorkspaceTodos',
        request: getWorkspaceTodoPendingShipmentApi,
      },
      {
        code: 'SystemWorkspaceTodos',
        request: getWorkspaceTodoRefundPendingApi,
      },
      {
        code: 'SystemWorkspaceTodos',
        request: getWorkspaceTodoStockWarningApi,
      },
      {
        code: 'SystemWorkspaceTodos',
        request: getWorkspaceTodoLogisticsConfigApi,
      },
      {
        code: 'SystemWorkspaceTodos',
        request: getWorkspaceTodoSmsProviderConfigApi,
      },
    ].filter((loader) => hasAccess(loader.code));
    hasTodoAccess.value = todoLoaders.length > 0;

    const todoResults = await Promise.allSettled(
      todoLoaders.map((loader) => loader.request()),
    );

    const mergedTodos: WorkspaceApi.Todo[] = [];
    todoResults.forEach((result, index) => {
      if (result.status === 'fulfilled') {
        mergedTodos.push(...(result.value ?? []));
        return;
      }

      const denied = isPermissionDenied(result.reason);
      if (!denied) {
        console.error(`加载待办项(${index})失败:`, result.reason);
      }
    });
    todos.value = mergedTodos;
  } catch (error: any) {
    if (!isPermissionDenied(error)) {
      message.error(error?.message || '工作台数据加载失败');
    }
  } finally {
    loading.value = false;
  }
}

function navTo(path: string, query?: Record<string, number | string>) {
  if (path.startsWith('http')) {
    openWindow(path);
    return;
  }

  if (!accessStore.getMenuByPath(path)) {
    message.warning('暂无访问权限');
    return;
  }

  router.push({ path, query }).catch((error) => {
    console.error('Navigation failed:', error);
  });
}

function openShortcutModal() {
  if (!canEditShortcuts.value) {
    return;
  }

  shortcutDraftPaths.value = shortcuts.value.map((item) => item.path);
  shortcutModalOpen.value = true;
}

async function saveShortcuts() {
  if (!canEditShortcuts.value) {
    return;
  }

  if (shortcutDraftPaths.value.length > MAX_SHORTCUTS) {
    message.warning(`最多选择 ${MAX_SHORTCUTS} 个快捷入口`);
    return;
  }

  saving.value = true;
  try {
    shortcuts.value = await updateWorkspaceShortcutsApi(
      shortcutDraftPaths.value.map((path) => ({ path })),
    );
    shortcutModalOpen.value = false;
    message.success('快捷导航已保存');
  } catch (error: any) {
    message.error(error?.message || '保存失败');
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadWorkspaceData();
});
</script>

<template>
  <div class="p-5">
    <a-card v-if="canResetDemo" class="mb-5" :bordered="false">
      <div
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
      >
        <div>
          <div class="text-base font-medium">演示站维护</div>
          <div class="text-sm text-gray-500">
            将商品、用户、配置和权限恢复到安装演示状态。
          </div>
        </div>
        <a-button danger :loading="resetting" @click="handleResetDemoData">
          一键恢复演示数据
        </a-button>
      </div>
    </a-card>

    <WorkbenchHeader
      :avatar="userStore.userInfo?.avatar || preferences.app.defaultAvatar"
      :stats="workspaceStats"
    >
      <template #title>
        早安, {{ userStore.userInfo?.realName }}, 开始您一天的工作吧！
      </template>
      <template #description>
        {{ headerDescription }}
      </template>
    </WorkbenchHeader>

    <a-spin :spinning="loading">
      <div
        class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(360px,0.65fr)]"
      >
        <section
          v-if="hasTodoAccess"
          class="rounded-lg border border-[hsl(var(--border))] bg-[hsl(var(--card))]"
        >
          <div
            class="flex items-center justify-between border-b border-[hsl(var(--border))] px-5 py-4"
          >
            <div>
              <h2 class="text-base font-semibold text-[hsl(var(--foreground))]">
                待办事项
              </h2>
              <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">
                根据当前业务数据自动生成
              </p>
            </div>
            <a-button size="small" @click="loadWorkspaceData">刷新</a-button>
          </div>

          <div
            v-if="todos.length > 0"
            class="divide-y divide-[hsl(var(--border))]"
          >
            <button
              v-for="item in todos"
              :key="item.key"
              class="grid w-full grid-cols-[44px_minmax(0,1fr)_auto] items-center gap-4 px-5 py-5 text-left transition hover:bg-[hsl(var(--accent))]"
              type="button"
              @click="navTo(item.link, item.query)"
            >
              <span
                class="flex h-11 w-11 items-center justify-center rounded-md border text-base font-semibold"
                :class="levelClassMap[item.level] || levelClassMap.info"
              >
                {{ item.count }}
              </span>
              <span class="min-w-0">
                <span
                  class="block text-sm font-semibold text-[hsl(var(--foreground))]"
                >
                  {{ item.title }}
                </span>
                <span
                  class="mt-1 block text-sm text-[hsl(var(--muted-foreground))]"
                >
                  {{ item.description }}
                </span>
              </span>
              <a-button size="small" type="link">
                {{ item.action_text }}
              </a-button>
            </button>
          </div>

          <div v-else class="px-5 py-12">
            <a-empty description="暂无待处理事项" />
          </div>
        </section>

        <section
          v-if="hasShortcutAccess"
          class="rounded-lg border border-[hsl(var(--border))] bg-[hsl(var(--card))]"
        >
          <div
            class="flex items-center justify-between border-b border-[hsl(var(--border))] px-5 py-4"
          >
            <div>
              <h2 class="text-base font-semibold text-[hsl(var(--foreground))]">
                我的快捷导航
              </h2>
              <p class="mt-1 text-sm text-[hsl(var(--muted-foreground))]">
                从可访问菜单中选择，最多 {{ MAX_SHORTCUTS }} 个
              </p>
            </div>
            <a-button
              v-if="canEditShortcuts"
              v-access:code="'SystemWorkspaceShortcutsUpdate'"
              size="small"
              type="primary"
              @click="openShortcutModal"
            >
              编辑
            </a-button>
          </div>

          <div v-if="shortcuts.length > 0" class="grid grid-cols-2 gap-3 p-5">
            <button
              v-for="item in shortcuts"
              :key="item.path"
              class="flex min-h-24 flex-col items-start justify-between rounded-md border border-[hsl(var(--border))] bg-[hsl(var(--background))] p-4 text-left transition hover:border-[hsl(var(--primary))] hover:bg-[hsl(var(--accent))]"
              type="button"
              @click="navTo(item.path)"
            >
              <IconifyIcon
                :icon="item.icon || 'lucide:circle'"
                class="h-6 w-6 text-[hsl(var(--primary))]"
              />
              <span class="mt-4 min-w-0">
                <span
                  class="block truncate text-sm font-semibold text-[hsl(var(--foreground))]"
                >
                  {{ item.title }}
                </span>
                <span
                  class="mt-1 block truncate text-xs text-[hsl(var(--muted-foreground))]"
                >
                  {{ item.path }}
                </span>
              </span>
            </button>
          </div>

          <div v-else class="px-5 py-12">
            <a-empty description="暂无快捷入口">
              <template #description>
                <span>暂无快捷入口</span>
              </template>
              <a-button
                v-if="canEditShortcuts"
                v-access:code="'SystemWorkspaceShortcutsUpdate'"
                type="primary"
                @click="openShortcutModal"
              >
                设置快捷入口
              </a-button>
            </a-empty>
          </div>
        </section>
      </div>

      <div
        v-if="!hasWorkspaceBlocks && !loading"
        class="mt-5 rounded-lg border border-[hsl(var(--border))] bg-[hsl(var(--card))] px-5 py-12"
      >
        <a-empty description="暂无可访问的工作台模块" />
      </div>
    </a-spin>

    <a-modal
      v-if="canEditShortcuts"
      v-model:open="shortcutModalOpen"
      title="编辑快捷导航"
      :confirm-loading="saving"
      ok-text="保存"
      cancel-text="取消"
      @ok="saveShortcuts"
    >
      <a-select
        v-model:value="shortcutDraftPaths"
        mode="multiple"
        :max-tag-count="4"
        :options="menuSelectOptions"
        placeholder="选择常用菜单"
        class="w-full"
      />
      <p class="mt-3 text-sm text-[hsl(var(--muted-foreground))]">
        已选择 {{ shortcutDraftPaths.length }} / {{ MAX_SHORTCUTS }} 个
      </p>
    </a-modal>
  </div>
</template>
