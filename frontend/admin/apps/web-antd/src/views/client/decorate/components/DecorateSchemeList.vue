<script lang="ts" setup>
import type { ClientDecorateApi } from '#/api/client';
import type { GoodsApi, GoodsCategoryApi } from '#/api/goods';

import ClientPhonePreview from '../../components/ClientPhonePreview.vue';
import {
  isFloatingPresetIcon,
  resolveFloatingMainIconUrl,
  resolveFloatingPresetIconUrl,
  resolvePreviewImageUrl,
} from '../utils/previewImage';

type ModuleItem = Record<string, any>;
type PreviewKind = 'floating' | 'home' | 'profile' | 'tabbar';

type FloatingPreviewConfig = {
  enabled: boolean;
  mode: 'expand' | 'single' | 'vertical';
  offsetBottom: number;
  offsetX: number;
  position: 'left-bottom' | 'right-bottom';
  singleItemId: string;
  style: {
    backgroundColor: string;
    color: string;
    radius: number;
    shadowBlur: number;
    shadowColor: string;
    shadowEnabled: boolean;
    shadowOffsetX: number;
    shadowOffsetY: number;
    shadowOpacity: number;
    shadowSpread: number;
    size: number;
  };
};

type SchemePreviewMeta = {
  cardDesc: string;
  currentPath: string;
  path: string;
  previewKind: PreviewKind;
};

defineProps<{
  activeTypeLabel: string;
  currentThemeTokens: Record<string, string>;
  getOverviewSchemeModules: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => ModuleItem[];
  getOverviewSchemeModuleSummary: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => string;
  getOverviewSchemeModuleTitle: (
    scheme: ClientDecorateApi.SchemeItem,
  ) => string;
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
  isReadonlyOverviewScheme: (scheme: ClientDecorateApi.SchemeItem) => boolean;
  overviewActiveSchemes: ClientDecorateApi.SchemeItem[];
  overviewKeyword: string;
  overviewLoading: boolean;
  overviewPage: number;
  overviewPageSize: number;
  overviewPageSizeOptions: string[];
  overviewTotal: number;
  previewCategoryTree: GoodsCategoryApi.CategoryItem[];
  previewGoods: GoodsApi.GoodsItem | null;
  previewGoodsList: GoodsApi.GoodsItem[];
}>();

const emit = defineEmits<{
  activate: [scheme: ClientDecorateApi.SchemeItem];
  copy: [scheme: ClientDecorateApi.SchemeItem];
  create: [];
  delete: [scheme: ClientDecorateApi.SchemeItem];
  edit: [scheme: ClientDecorateApi.SchemeItem];
  'page-change': [page: number, pageSize: number];
  reset: [];
  refresh: [];
  search: [keyword: string];
  'update:overviewKeyword': [keyword: string];
}>();

const handleKeywordUpdate = (keyword: string) => {
  emit('update:overviewKeyword', keyword);
};

const handleSearch = (keyword: string) => {
  emit('search', keyword);
};

const handleReset = () => {
  emit('reset');
};

const handlePageChange = (page: number, pageSize: number) => {
  emit('page-change', page, pageSize);
};

const renderPaginationTotal = (total: number) => `共 ${total} 个方案`;

const getFloatingConfig = (scheme: ClientDecorateApi.SchemeItem) =>
  normalizeFloatingPreviewConfig(
    scheme.type === 'floating' &&
      scheme.schema &&
      !Array.isArray(scheme.schema)
      ? (scheme.schema as Record<string, any>)
      : {},
  );

const normalizeFloatingPreviewConfig = (
  schema: Record<string, any>,
): FloatingPreviewConfig => {
  const style =
    schema.style && typeof schema.style === 'object' ? schema.style : {};
  const mode = (
    ['expand', 'single', 'vertical'].includes(schema.mode)
      ? schema.mode
      : 'expand'
  ) as FloatingPreviewConfig['mode'];
  const position = (
    ['left-bottom', 'right-bottom'].includes(schema.position)
      ? schema.position
      : 'right-bottom'
  ) as FloatingPreviewConfig['position'];
  return {
    enabled: schema.enabled !== false,
    mode,
    offsetBottom: clampNumber(schema.offsetBottom ?? schema.offset_bottom, 160),
    offsetX: clampNumber(schema.offsetX ?? schema.offset_x, 24),
    position,
    singleItemId: String(schema.singleItemId ?? schema.single_item_id ?? ''),
    style: {
      backgroundColor: String(
        style.backgroundColor ?? style.background_color ?? '',
      ),
      color: String(style.color ?? ''),
      radius: clampNumber(style.radius, 44),
      shadowBlur: clampNumber(style.shadowBlur ?? style.shadow_blur, 30),
      shadowColor: String(style.shadowColor ?? style.shadow_color ?? '#0f172a'),
      shadowEnabled:
        (style.shadowEnabled ?? style.shadow_enabled ?? true) ? true : false,
      shadowOffsetX: clampNumber(
        style.shadowOffsetX ?? style.shadow_offset_x,
        0,
      ),
      shadowOffsetY: clampNumber(
        style.shadowOffsetY ?? style.shadow_offset_y,
        12,
      ),
      shadowOpacity: clampNumber(
        style.shadowOpacity ?? style.shadow_opacity,
        14,
      ),
      shadowSpread: clampNumber(style.shadowSpread ?? style.shadow_spread, 0),
      size: clampNumber(style.size, 88),
    },
  };
};

const getFloatingPreviewItems = (items: ModuleItem[]) =>
  items.filter((item) => item && item.enabled !== false);

const getFloatingPreviewMainItem = (
  items: ModuleItem[],
  config?: FloatingPreviewConfig,
) => {
  const visibleItems = getFloatingPreviewItems(items);
  if (config?.mode === 'single' && config.singleItemId) {
    return (
      visibleItems.find((item) => item.id === config.singleItemId) ||
      visibleItems[0] ||
      null
    );
  }
  return visibleItems[0] || null;
};

const getFloatingPreviewPositionStyle = (
  config: FloatingPreviewConfig,
): Record<string, string> => {
  const position = config.position === 'left-bottom' ? 'left' : 'right';
  const size = toPreviewPx(config.style.size, 88, 28, 64);
  const radius = toPreviewPx(config.style.radius, Math.round(size * 2), 0, 60);
  return {
    '--floating-preview-bg':
      config.style.backgroundColor || 'hsl(var(--primary))',
    '--floating-preview-color':
      config.style.color || 'hsl(var(--primary-foreground))',
    '--floating-preview-radius': `${radius}px`,
    '--floating-preview-shadow': getFloatingPreviewShadow(config.style),
    '--floating-preview-size': `${size}px`,
    [position]: `${toPreviewPx(config.offsetX, 24, 0, 80)}px`,
    bottom: `${toPreviewPx(config.offsetBottom, 160, 0, 120)}px`,
  };
};

const getFloatingPreviewShadow = (style: FloatingPreviewConfig['style']) => {
  if (style.shadowEnabled === false) return 'none';
  const offsetX = toPreviewPx(style.shadowOffsetX, 0, -80, 80);
  const offsetY = toPreviewPx(style.shadowOffsetY, 12, -80, 80);
  const blur = toPreviewPx(style.shadowBlur, 30, 0, 160);
  const spread = toPreviewPx(style.shadowSpread, 0, -80, 80);
  return `${offsetX}px ${offsetY}px ${blur}px ${spread}px ${floatingShadowColor(
    style.shadowColor,
    style.shadowOpacity,
  )}`;
};

const floatingShadowColor = (value: unknown, opacity: unknown) => {
  const color =
    typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value)
      ? value
      : '#0f172a';
  const alpha = Math.max(
    0,
    Math.min(100, Number.isFinite(Number(opacity)) ? Number(opacity) : 14),
  );
  const red = Number.parseInt(color.slice(1, 3), 16);
  const green = Number.parseInt(color.slice(3, 5), 16);
  const blue = Number.parseInt(color.slice(5, 7), 16);
  return `rgba(${red}, ${green}, ${blue}, ${alpha / 100})`;
};

const getFloatingPreviewIcon = (item: ModuleItem | null) =>
  item
    ? resolvePreviewImageUrl(item.icon) || resolveFloatingPresetIconUrl(item)
    : '';

const getFloatingPreviewSide = (config: FloatingPreviewConfig) =>
  config.position === 'left-bottom' ? 'left' : 'right';

const getFloatingPreviewMainIcon = (config: FloatingPreviewConfig) =>
  resolveFloatingMainIconUrl(getFloatingPreviewSide(config));

const isFloatingPreviewSingleMode = (
  config: FloatingPreviewConfig,
  items: ModuleItem[],
) => config.mode === 'single' && getFloatingPreviewItems(items).length > 0;

const clampNumber = (value: unknown, fallback: number) => {
  const numberValue = Number(value ?? fallback);
  return Number.isFinite(numberValue) ? numberValue : fallback;
};

const toPreviewPx = (
  value: unknown,
  fallback: number,
  min: number,
  max: number,
) => Math.max(min, Math.min(max, Math.round(clampNumber(value, fallback) / 2)));
</script>

<template>
  <a-card>
    <template #title>
      <div class="decorate-card-title">
        <span>{{ activeTypeLabel }}方案列表</span>
        <a-space>
          <a-button @click="$emit('refresh')">刷新</a-button>
          <a-button type="primary" @click="$emit('create')">新建方案</a-button>
        </a-space>
      </div>
    </template>

    <a-form layout="inline" class="mb-4 decorate-overview-form">
      <a-form-item label="关键词">
        <a-input
          :value="overviewKeyword"
          allow-clear
          placeholder="搜索方案名称 / 描述"
          style="width: 220px"
          @press-enter="handleSearch(overviewKeyword)"
          @update:value="handleKeywordUpdate"
        />
      </a-form-item>
      <a-form-item>
        <a-button
          type="primary"
          :loading="overviewLoading"
          @click="handleSearch(overviewKeyword)"
        >
          搜索
        </a-button>
        <a-button class="ml-2" @click="handleReset">重置</a-button>
      </a-form-item>
    </a-form>

    <a-spin :spinning="overviewLoading">
      <section
        v-if="overviewActiveSchemes.length > 0"
        class="decorate-overview"
      >
        <article
          v-for="scheme in overviewActiveSchemes"
          :key="scheme.id"
          class="decorate-overview-card"
          :class="[
            `decorate-overview-card--${scheme.type}`,
            { 'decorate-overview-card--active': scheme.is_active === 1 },
          ]"
        >
          <div class="decorate-overview-card__head">
            <div>
              <div class="decorate-overview-card__tags">
                <a-tag
                  v-if="scheme.is_active === 1"
                  class="decorate-overview-card__active-tag"
                  color="blue"
                >
                  当前使用
                </a-tag>
                <a-tag>{{ getSchemeTypeLabel(scheme.type) }}</a-tag>
                <a-tag
                  v-if="scheme.is_active !== 1"
                  :color="getSchemeStatusColor(scheme)"
                >
                  {{ getSchemeStatusLabel(scheme) }}
                </a-tag>
              </div>
              <strong>{{ scheme.name }}</strong>
              <span>{{ getOverviewSchemeUpdateLabel(scheme) }}</span>
            </div>
          </div>

          <div class="decorate-overview-card__preview">
            <div
              class="decorate-overview-card__preview-phone"
              :class="{
                'decorate-overview-card__preview-phone--tabbar':
                  scheme.type === 'tabbar',
                'decorate-overview-card__preview-phone--floating':
                  scheme.type === 'floating',
              }"
            >
              <div
                v-if="scheme.type === 'floating'"
                class="floating-component-preview floating-component-preview--overview"
              >
                <div class="floating-component-preview__surface">
                  <div
                    v-if="
                      getFloatingConfig(scheme).enabled !== false &&
                      getFloatingPreviewItems(getOverviewSchemeModules(scheme))
                        .length > 0
                    "
                    class="floating-component-preview__cluster"
                    :class="[
                      `floating-component-preview__cluster--${getFloatingPreviewSide(getFloatingConfig(scheme))}`,
                      {
                        'floating-component-preview__cluster--open':
                          !isFloatingPreviewSingleMode(
                            getFloatingConfig(scheme),
                            getOverviewSchemeModules(scheme),
                          ),
                        'floating-component-preview__cluster--single':
                          isFloatingPreviewSingleMode(
                            getFloatingConfig(scheme),
                            getOverviewSchemeModules(scheme),
                          ),
                      },
                    ]"
                    :style="
                      getFloatingPreviewPositionStyle(getFloatingConfig(scheme))
                    "
                  >
                    <div
                      v-if="
                        !isFloatingPreviewSingleMode(
                          getFloatingConfig(scheme),
                          getOverviewSchemeModules(scheme),
                        )
                      "
                      class="floating-component-preview__menu"
                    >
                      <button
                        v-for="item in getFloatingPreviewItems(
                          getOverviewSchemeModules(scheme),
                        )"
                        :key="item.id || item.path || item.text"
                        class="floating-component-preview__item"
                        type="button"
                      >
                        <img
                          v-if="getFloatingPreviewIcon(item)"
                          alt=""
                          :class="{
                            'floating-component-preview__icon--preset':
                              isFloatingPresetIcon(item),
                          }"
                          :src="getFloatingPreviewIcon(item)"
                        />
                        <span
                          v-else
                          class="floating-component-preview__icon-empty"
                        >
                          未传
                        </span>
                      </button>
                    </div>

                    <button
                      class="floating-component-preview__trigger"
                      type="button"
                    >
                      <template
                        v-if="
                          isFloatingPreviewSingleMode(
                            getFloatingConfig(scheme),
                            getOverviewSchemeModules(scheme),
                          ) &&
                          getFloatingPreviewMainItem(
                            getOverviewSchemeModules(scheme),
                            getFloatingConfig(scheme),
                          )
                        "
                      >
                        <img
                          v-if="
                            getFloatingPreviewIcon(
                              getFloatingPreviewMainItem(
                                getOverviewSchemeModules(scheme),
                                getFloatingConfig(scheme),
                              ),
                            )
                          "
                          alt=""
                          :class="{
                            'floating-component-preview__icon--preset':
                              isFloatingPresetIcon(
                                getFloatingPreviewMainItem(
                                  getOverviewSchemeModules(scheme),
                                  getFloatingConfig(scheme),
                                ),
                              ),
                          }"
                          :src="
                            getFloatingPreviewIcon(
                              getFloatingPreviewMainItem(
                                getOverviewSchemeModules(scheme),
                                getFloatingConfig(scheme),
                              ),
                            )
                          "
                        />
                        <span
                          v-else
                          class="floating-component-preview__icon-empty"
                        >
                          未传
                        </span>
                      </template>
                      <img
                        v-else
                        alt=""
                        :src="
                          getFloatingPreviewMainIcon(getFloatingConfig(scheme))
                        "
                      />
                    </button>
                  </div>

                  <a-empty
                    v-else
                    class="floating-component-preview__empty"
                    :description="
                      getFloatingConfig(scheme).enabled === false
                        ? '悬浮按钮已停用'
                        : '暂无可展示入口'
                    "
                  />
                </div>
              </div>

              <ClientPhonePreview
                v-else
                :category-tree="previewCategoryTree"
                :current-path="getSchemePreviewMeta(scheme).currentPath"
                :goods="previewGoods"
                :goods-list="previewGoodsList"
                :kind="getSchemePreviewMeta(scheme).previewKind"
                :modules="getOverviewSchemeModules(scheme)"
                :tabbar-items="getOverviewSchemeTabbarItems(scheme)"
                :theme-tokens="currentThemeTokens"
                :title="getSchemeTypeLabel(scheme.type)"
              />
            </div>
          </div>

          <div class="decorate-overview-card__foot">
            <div class="decorate-overview-card__meta">
              <p>{{ getSchemePreviewMeta(scheme).cardDesc }}</p>
              <p :title="getOverviewSchemeModuleTitle(scheme)">
                模块：{{ getOverviewSchemeModuleSummary(scheme) }}
              </p>
            </div>
            <div class="decorate-overview-card__actions">
              <span
                v-if="isReadonlyOverviewScheme(scheme)"
                class="decorate-overview-card__readonly"
              >
                系统内置
              </span>
              <a-button
                v-if="!isReadonlyOverviewScheme(scheme)"
                type="link"
                size="small"
                @click="$emit('edit', scheme)"
              >
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
                v-if="!isReadonlyOverviewScheme(scheme)"
                danger
                type="link"
                size="small"
                @click="$emit('delete', scheme)"
              >
                删除
              </a-button>
            </div>
          </div>
        </article>
      </section>

      <div v-else class="decorate-overview-empty">
        <a-empty
          :description="
            overviewKeyword.trim()
              ? '没有找到匹配的方案'
              : `${activeTypeLabel}暂无方案`
          "
        >
          <a-button type="primary" @click="$emit('create')">新建方案</a-button>
        </a-empty>
      </div>
    </a-spin>

    <div v-if="overviewTotal > 0" class="decorate-overview-pagination">
      <a-pagination
        :current="overviewPage"
        :page-size="overviewPageSize"
        :page-size-options="overviewPageSizeOptions"
        show-size-changer
        :show-total="renderPaginationTotal"
        :total="overviewTotal"
        @change="handlePageChange"
        @show-size-change="handlePageChange"
      />
    </div>
  </a-card>
</template>
