<script lang="ts" setup>
import type { VbenFormSchema } from '@vben/common-ui';

import { computed, markRaw } from 'vue';

import { AuthenticationLogin, SliderCaptcha, z } from '@vben/common-ui';
import { $t } from '@vben/locales';

import { loginPageMetaState } from '#/modules/app-meta';
import { useAuthStore } from '#/store';

defineOptions({ name: 'Login' });

const authStore = useAuthStore();
const isE2EByEnv = import.meta.env.VITE_E2E === 'true';
const isE2EByQuery =
  typeof window !== 'undefined' &&
  new URLSearchParams(window.location.search).get('e2e') === '1';
const isE2E = isE2EByEnv || isE2EByQuery;
const loginTitle = computed(
  () =>
    loginPageMetaState.loginTitle || `${$t('authentication.welcomeBack')} 👋🏻`,
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
      fieldName: 'username',
      label: $t('authentication.username'),
      rules: z.string().min(1, { message: $t('authentication.usernameTip') }),
    },
    {
      component: 'VbenInputPassword',
      componentProps: {
        placeholder: $t('authentication.password'),
      },
      fieldName: 'password',
      label: $t('authentication.password'),
      rules: z.string().min(1, { message: $t('authentication.passwordTip') }),
    },
    {
      component: markRaw(SliderCaptcha),
      fieldName: 'captcha',
      rules: z.boolean().refine((value) => value, {
        message: $t('authentication.verifyRequiredTip'),
      }),
    },
  ];

  if (isE2E) {
    return schema.filter((item) => item.fieldName !== 'captcha');
  }

  return schema;
});
</script>

<template>
  <AuthenticationLogin
    :form-schema="formSchema"
    :loading="authStore.loginLoading"
    :show-code-login="false"
    :show-qrcode-login="false"
    :show-third-party-login="false"
    :show-register="false"
    :sub-title="loginSubtitle"
    :title="loginTitle"
    @submit="authStore.authLogin"
  />
</template>
