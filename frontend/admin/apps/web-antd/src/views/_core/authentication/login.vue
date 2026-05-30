<script lang="ts" setup>
import type { VbenFormSchema } from '@vben/common-ui';

import { computed, ref } from 'vue';

import { AuthenticationLogin, z } from '@vben/common-ui';
import { $t } from '@vben/locales';

import { message, Modal } from 'ant-design-vue';

import {
  getPublicDemoResetStatusApi,
  startPublicDemoResetApi,
} from '#/api/demo/reset';
import { loginPageMetaState } from '#/modules/app-meta';
import { useAuthStore } from '#/store';

defineOptions({ name: 'Login' });

const authStore = useAuthStore();
const resettingDemo = ref(false);
const loginTitle = computed(
  () => loginPageMetaState.loginTitle || $t('authentication.welcomeBack'),
);
const loginSubtitle = computed(
  () => loginPageMetaState.loginSubtitle || $t('authentication.loginSubtitle'),
);

const formSchema = computed((): VbenFormSchema[] => {
  const schema: VbenFormSchema[] = [
    {
      component: 'VbenInput',
      componentProps: {
        placeholder: $t('authentication.usernameTip'),
      },
      defaultValue: 'admin',
      fieldName: 'username',
      label: $t('authentication.username'),
      rules: z.string().min(1, { message: $t('authentication.usernameTip') }),
    },
    {
      component: 'VbenInputPassword',
      componentProps: {
        placeholder: $t('authentication.password'),
      },
      defaultValue: 'admin123',
      fieldName: 'password',
      label: $t('authentication.password'),
      rules: z.string().min(1, { message: $t('authentication.passwordTip') }),
    },
  ];

  return schema;
});

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

  throw new Error('演示数据恢复仍在执行，请稍后再试');
}

function handleResetDemoData() {
  Modal.confirm({
    title: '恢复演示数据',
    content:
      '该操作会重置演示站数据，并恢复 admin / admin123 登录账号。确认继续？',
    okText: '确认恢复',
    okType: 'danger',
    cancelText: '取消',
    async onOk() {
      resettingDemo.value = true;
      try {
        const started = await startPublicDemoResetApi();
        message.info(started.message || '演示数据恢复任务已开始');
        const status = await waitDemoResetDone();
        message.success(status.message || '演示数据已恢复，可直接登录');
      } catch (error) {
        message.error(
          error instanceof Error ? error.message : '演示数据恢复失败',
        );
      } finally {
        resettingDemo.value = false;
      }
    },
  });
}
</script>

<template>
  <div>
    <AuthenticationLogin
      :form-schema="formSchema"
      :loading="authStore.loginLoading"
      :show-code-login="false"
      :show-forget-password="false"
      :show-qrcode-login="false"
      :show-remember-me="false"
      :show-third-party-login="false"
      :show-register="false"
      :sub-title="loginSubtitle"
      :title="loginTitle"
      @submit="authStore.authLogin"
    />
    <div class="mx-auto mt-4 flex max-w-[360px] justify-center">
      <a-button
        danger
        type="link"
        :loading="resettingDemo"
        @click="handleResetDemoData"
      >
        恢复演示数据并重置登录账号
      </a-button>
    </div>
  </div>
</template>
