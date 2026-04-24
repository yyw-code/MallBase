<script lang="ts" setup>
import { computed } from 'vue';

import { AuthPageLayout } from '@vben/layouts';
import { preferences } from '@vben/preferences';

import { $t } from '#/locales';
import { loginPageMetaState } from '#/modules/app-meta';

const appName = computed(() => preferences.app.name);
const logo = computed(() => preferences.logo.source);
const logoDark = computed(() => preferences.logo.sourceDark);
const pageTitle = computed(
  () => loginPageMetaState.pageTitle || $t('authentication.pageTitle'),
);
const pageDescription = computed(
  () => loginPageMetaState.pageDescription || $t('authentication.pageDesc'),
);
const sloganImage = computed(() => loginPageMetaState.sloganImage || '');
</script>

<template>
  <AuthPageLayout
    :app-name="appName"
    :logo="logo"
    :logo-dark="logoDark"
    :page-description="pageDescription"
    :page-title="pageTitle"
    :slogan-image="sloganImage"
  >
    <!-- 自定义工具栏 -->
    <!-- <template #toolbar></template> -->
  </AuthPageLayout>
</template>
