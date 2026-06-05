<script lang="ts" setup>
import type { ClientDecorateApi } from '#/api/client';
import type { GoodsApi, GoodsCategoryApi } from '#/api/goods';

import ClientPhonePreview from '../../components/ClientPhonePreview.vue';

type ModuleItem = Record<string, any>;
type PreviewKind = 'home' | 'profile' | 'tabbar';

type SchemePreviewMeta = {
  cardDesc: string;
  currentPath: string;
  path: string;
  previewKind: PreviewKind;
};

defineProps<{
  activeHelp: string;
  activeTypeLabel: string;
  currentThemeTokens: Record<string, string>;
  getOverviewSchemeModuleSummary: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => string;
  getOverviewSchemeModules: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => ModuleItem[];
  getOverviewSchemeTabbarItems: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => ModuleItem[];
  getOverviewSchemeUpdateLabel: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => string;
  getSchemePreviewMeta: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => SchemePreviewMeta;
  getSchemeStatusColor: (scheme: ClientDecorateApi.SchemeItem) => string;
  getSchemeStatusLabel: (scheme: ClientDecorateApi.SchemeItem) => string;
  getSchemeTypeLabel: (type: ClientDecorateApi.SchemeType) => string;
  isReadonlyOverviewScheme: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => boolean;
  overviewActiveName: string;
  overviewActiveSchemes: ClientDecorateApi.SchemeItem[];
  overviewLoading: boolean;
  previewCategoryTree: GoodsCategoryApi.CategoryItem[];
  previewGoods: GoodsApi.GoodsItem | null;
}>();

defineEmits<{
  activate: [scheme: ClientDecorateApi.SchemeItem];
  copy: [scheme: ClientDecorateApi.SchemeItem];
  create: [];
  delete: [scheme: ClientDecorateApi.SchemeItem];
  edit: [scheme: ClientDecorateApi.SchemeItem];
}>();
</script>

<template>
  <a-spin :spinning="overviewLoading">
    <div class="decorate-overview-head">
      <div>
        <strong>{{ activeTypeLabel }}方案列表</strong>
        <span>{{ activeHelp }}</span>
      </div>
      <a-space>
        <a-tag color="green">当前使用：{{ overviewActiveName }}</a-tag>
        <a-tag>{{ overviewActiveSchemes.length }} 个方案</a-tag>
      </a-space>
    </div>

    <section v-if="overviewActiveSchemes.length > 0" class="decorate-overview">
      <article
        v-for="scheme in overviewActiveSchemes"
        :key="scheme.id"
        class="decorate-overview-card"
        :class="`decorate-overview-card--${scheme.type}`"
      >
        <div class="decorate-overview-card__head">
          <div>
            <div class="decorate-overview-card__tags">
              <a-tag>{{ getSchemeTypeLabel(scheme.type) }}</a-tag>
              <a-tag :color="getSchemeStatusColor(scheme)">
                {{ getSchemeStatusLabel(scheme) }}
              </a-tag>
              <a-tag v-if="scheme.is_system === 1">系统</a-tag>
            </div>
            <strong>{{ scheme.name }}</strong>
            <span>{{ getOverviewSchemeUpdateLabel(scheme) }}</span>
          </div>
          <a-space v-if="isReadonlyOverviewScheme(scheme)">
            <a-tag>系统内置</a-tag>
            <a-button type="link" size="small" @click="$emit('copy', scheme)">
              复制
            </a-button>
            <a-button
              type="link"
              size="small"
              :disabled="scheme.is_active === 1"
              @click="$emit('activate', scheme)"
            >
              设为当前
            </a-button>
          </a-space>
          <a-space v-else>
            <a-button type="link" size="small" @click="$emit('edit', scheme)">
              编辑
            </a-button>
            <a-button type="link" size="small" @click="$emit('copy', scheme)">
              复制
            </a-button>
            <a-button
              type="link"
              size="small"
              :disabled="scheme.is_active === 1"
              @click="$emit('activate', scheme)"
            >
              设为当前
            </a-button>
            <a-button
              danger
              type="link"
              size="small"
              @click="$emit('delete', scheme)"
            >
              删除
            </a-button>
          </a-space>
        </div>

        <div class="decorate-overview-card__preview">
          <ClientPhonePreview
            :category-tree="previewCategoryTree"
            :current-path="getSchemePreviewMeta(scheme).currentPath"
            :goods="previewGoods"
            :kind="getSchemePreviewMeta(scheme).previewKind"
            :modules="getOverviewSchemeModules(scheme)"
            size="compact"
            :tabbar-items="getOverviewSchemeTabbarItems(scheme)"
            :theme-tokens="currentThemeTokens"
            :title="getSchemeTypeLabel(scheme.type)"
          />
        </div>

        <div class="decorate-overview-card__meta">
          <p>{{ getSchemePreviewMeta(scheme).cardDesc }}</p>
          <p>模块：{{ getOverviewSchemeModuleSummary(scheme) }}</p>
        </div>
      </article>
    </section>

    <div v-else class="decorate-overview-empty">
      <a-empty :description="`${activeTypeLabel}暂无方案`">
        <a-button type="primary" @click="$emit('create')">新建方案</a-button>
      </a-empty>
    </div>
  </a-spin>
</template>
