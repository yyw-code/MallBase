<script lang="ts" setup>
import type { GoodsCategoryApi } from '#/api/goods';
import type { SettingApi } from '#/api/setting';
import type { FileInfo } from '#/components/upload';

import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from 'vue';

import { message } from 'ant-design-vue';

import { getGoodsCategoryTreeApi } from '#/api/goods';
import { getSettingConfigApi, saveSettingConfigApi } from '#/api/setting';
import RichTextEditor from '#/components/rich-text-editor/index.vue';
import Upload from '#/components/upload/index.vue';

defineOptions({ name: 'ClientConfigManagement' });

type ContentCode =
  | 'client_about_content'
  | 'client_after_sale_policy'
  | 'client_agreement'
  | 'client_platform_rules'
  | 'client_privacy';

type ContentItem = {
  code: ContentCode;
  desc: string;
  title: string;
};

type GuaranteeItem = {
  desc: string;
  title: string;
};

type GoodsBadgeConfig = {
  hot: { text: string };
  new: { text: string };
  recommend: { text: string };
  style: {
    backgroundColor: string;
    borderRadius: number;
    fontSize: number;
    height: number;
    paddingX: number;
    textColor: string;
  };
};

type QuickFilterValue = 'category' | 'is_hot' | 'is_new' | 'is_recommend';

type CustomerServiceMode = 'phone' | 'system';

type TreeSelectNode = {
  children: TreeSelectNode[];
  title: string;
  value: number;
};

const DEFAULT_GOODS_BADGE_CONFIG: GoodsBadgeConfig = {
  new: { text: '新品' },
  hot: { text: '热卖' },
  recommend: { text: '推荐' },
  style: {
    backgroundColor: '',
    borderRadius: 999,
    fontSize: 20,
    height: 36,
    paddingX: 14,
    textColor: '',
  },
};

const BADGE_COLOR_PICKER_FALLBACK = {
  backgroundColor: '#0d50d5',
  textColor: '#ffffff',
};

const activeTab = ref('basic');
const loading = ref(false);
const saving = ref(false);
const categoryLoading = ref(false);
const settingsByCode = ref<Record<string, SettingApi.SettingItem>>({});
const categoryTree = ref<GoodsCategoryApi.CategoryItem[]>([]);

const imageValues = reactive<Record<string, FileInfo | string | undefined>>({
  client_launch_image: undefined,
  client_logo: undefined,
  client_share_cover: undefined,
});

const form = reactive({
  client_about_content: '',
  client_after_sale_policy: '',
  client_agreement: '',
  client_customer_service_mode: 'phone' as CustomerServiceMode,
  client_customer_service_phone: '',
  client_goods_badge_config: cloneGoodsBadgeConfig(),
  client_goods_card_show_badge: true,
  client_goods_card_show_cart_button: true,
  client_goods_card_show_market_price: true,
  client_goods_card_show_sales: true,
  client_goods_card_show_subtitle: true,
  client_goods_guarantees: [] as GuaranteeItem[],
  client_platform_rules: '',
  client_privacy: '',
  client_search_category_enabled: true,
  client_search_category_ids: [] as number[],
  client_search_history_enabled: true,
  client_search_hot_enabled: true,
  client_search_quick_filter_enabled: true,
  client_search_quick_filters: [
    'is_new',
    'is_hot',
    'is_recommend',
    'category',
  ] as QuickFilterValue[],
  client_share_desc: '',
  client_share_title: '',
  client_site_name: '',
  client_splash_duration: 3000,
  client_splash_enabled: true,
  customer_service_allowed_ips: '',
  customer_service_api_base: '',
  customer_service_connector_enabled: false,
  customer_service_connector_secret: '',
  customer_service_context_key_id: '',
  customer_service_context_secret: '',
  customer_service_context_ttl: 300,
  customer_service_operator_admin_id: 1,
  customer_service_platform_code: 'mallbase',
  customer_service_socket_base: '',
  customer_service_timestamp_window: 300,
});

const quickFilterOptions: Array<{
  desc: string;
  label: string;
  value: QuickFilterValue;
}> = [
  { label: '新品上架', value: 'is_new', desc: '跳转到新品商品列表' },
  { label: '热卖商品', value: 'is_hot', desc: '跳转到热卖商品列表' },
  { label: '推荐商品', value: 'is_recommend', desc: '跳转到推荐商品列表' },
  { label: '全部分类', value: 'category', desc: '跳转到客户端分类页' },
];

const contentItems: ContentItem[] = [
  {
    code: 'client_about_content',
    title: '关于我们',
    desc: '品牌介绍、平台定位和服务说明',
  },
  {
    code: 'client_agreement',
    title: '用户协议',
    desc: '注册、登录和客户端使用条款',
  },
  {
    code: 'client_privacy',
    title: '隐私政策',
    desc: '用户信息收集、使用和保护说明',
  },
  {
    code: 'client_platform_rules',
    title: '平台规则',
    desc: '交易、评价、违规和平台秩序规则',
  },
  {
    code: 'client_after_sale_policy',
    title: '售后政策',
    desc: '退款、退货、换货和售后展示说明',
  },
];

const editingContent = ref<ContentItem | null>(null);
const contentDrawerOpen = ref(false);
const contentEditorReady = ref(false);
const contentDraft = ref('');
const previewContent = ref<ContentItem | null>(null);
const previewOpen = ref(false);
let contentEditorFrame: number | undefined;

const categoryTreeData = computed(() => toTreeSelectData(categoryTree.value));

const goodsBadgePreviewStyle = computed(() => {
  const style = normalizeGoodsBadgeConfig(form.client_goods_badge_config).style;
  return {
    backgroundColor: style.backgroundColor || 'hsl(var(--primary))',
    borderRadius: `${style.borderRadius}px`,
    color: style.textColor || 'hsl(var(--primary-foreground))',
    fontSize: `${style.fontSize}px`,
    height: `${style.height}px`,
    padding: `0 ${style.paddingX}px`,
  };
});

function isSwitchOn(value: unknown, fallback = true) {
  if (value === undefined || value === null || value === '') return fallback;
  return value === true || value === 1 || value === '1' || value === 'true';
}

function parseJsonArray<T>(value: unknown, fallback: T[]): T[] {
  if (Array.isArray(value)) return value as T[];
  if (typeof value !== 'string' || value.trim() === '') return fallback;
  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? (parsed as T[]) : fallback;
  } catch {
    return fallback;
  }
}

function cloneGoodsBadgeConfig(config = DEFAULT_GOODS_BADGE_CONFIG) {
  return structuredClone(config);
}

function parseJsonObject<T extends object>(value: unknown, fallback: T): T {
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    return value as T;
  }
  if (typeof value !== 'string' || value.trim() === '') return fallback;
  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
      ? (parsed as T)
      : fallback;
  } catch {
    return fallback;
  }
}

function normalizeNumber(
  value: unknown,
  fallback: number,
  min: number,
  max: number,
) {
  const numberValue = Number(value);
  if (!Number.isFinite(numberValue)) return fallback;
  return Math.min(Math.max(numberValue, min), max);
}

function normalizeOptionalHexColor(value: unknown) {
  const color = String(value || '').trim();
  if (color === '') return '';
  return /^#[\da-f]{6}$/i.test(color) ? color : '';
}

function colorPickerValue(
  value: unknown,
  field: 'backgroundColor' | 'textColor',
) {
  const color = normalizeOptionalHexColor(value);
  return color || BADGE_COLOR_PICKER_FALLBACK[field];
}

function normalizeGoodsBadgeConfig(value: unknown): GoodsBadgeConfig {
  const source = parseJsonObject(value, cloneGoodsBadgeConfig());
  const defaults = DEFAULT_GOODS_BADGE_CONFIG;
  return {
    new: {
      text: String(source.new?.text || defaults.new.text).trim(),
    },
    hot: {
      text: String(source.hot?.text || defaults.hot.text).trim(),
    },
    recommend: {
      text: String(source.recommend?.text || defaults.recommend.text).trim(),
    },
    style: {
      backgroundColor: normalizeOptionalHexColor(source.style?.backgroundColor),
      borderRadius: normalizeNumber(
        source.style?.borderRadius,
        defaults.style.borderRadius,
        0,
        999,
      ),
      fontSize: normalizeNumber(
        source.style?.fontSize,
        defaults.style.fontSize,
        16,
        36,
      ),
      height: normalizeNumber(
        source.style?.height,
        defaults.style.height,
        24,
        60,
      ),
      paddingX: normalizeNumber(
        source.style?.paddingX,
        defaults.style.paddingX,
        6,
        40,
      ),
      textColor: normalizeOptionalHexColor(source.style?.textColor),
    },
  };
}

function updateBadgeColor(
  field: 'backgroundColor' | 'textColor',
  event: Event,
) {
  const target = event.target as HTMLInputElement | null;
  if (!target?.value) return;
  form.client_goods_badge_config.style[field] = target.value;
}

function clearBadgeColor(field: 'backgroundColor' | 'textColor') {
  form.client_goods_badge_config.style[field] = '';
}

function settingValue(code: string, fallback = '') {
  const value = settingsByCode.value[code]?.value;
  return value === undefined || value === null ? fallback : String(value);
}

function settingNumberValue(code: string, fallback: number) {
  const value = Number(settingsByCode.value[code]?.value);
  return Number.isFinite(value) ? value : fallback;
}

function settingHasValue(code: string) {
  return Boolean(settingsByCode.value[code]?.has_value);
}

function generateRandomSecret() {
  if (!globalThis.crypto?.getRandomValues) {
    message.error('当前浏览器不支持安全随机数生成');
    return '';
  }

  const bytes = new Uint8Array(32);
  globalThis.crypto.getRandomValues(bytes);
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function fillRandomSecret(field: 'customer_service_connector_secret') {
  const secret = generateRandomSecret();
  if (!secret) return;
  form[field] = secret;
}

function imageValue(code: string) {
  const item = settingsByCode.value[code];
  if (!item?.value) return undefined;
  return {
    url: item.value,
    full_url: item.full_url || '',
    name: item.name || code,
  };
}

function uploadValueToString(value: FileInfo | string | undefined) {
  if (!value) return '';
  return typeof value === 'string' ? value : value.url || '';
}

function contentConfigured(code: ContentCode) {
  return String(form[code] || '').trim() !== '';
}

function contentUpdatedAt(code: ContentCode) {
  const item = settingsByCode.value[code] as
    | (SettingApi.SettingItem & { update_time?: string })
    | undefined;
  return item?.update_time || '未保存';
}

function previewHtml(code: ContentCode) {
  const body = form[code] || '<p style="color:#8c8c8c;">暂无内容</p>';
  return `<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:24px;line-height:1.8;color:#1f2937}img,video{max-width:100%;height:auto}</style></head><body>${body}</body></html>`;
}

function toTreeSelectData(
  list: GoodsCategoryApi.CategoryItem[],
): TreeSelectNode[] {
  return list.map((item) => ({
    title: item.name,
    value: item.id,
    children: item.children?.length ? toTreeSelectData(item.children) : [],
  }));
}

async function loadConfig() {
  loading.value = true;
  try {
    const res = await getSettingConfigApi('ClientConfig');
    const list = res.settings || [];
    settingsByCode.value = Object.fromEntries(
      list.map((item) => [item.code, item]),
    );
    fillFormFromSettings();
  } catch (error) {
    console.error('加载客户端配置失败:', error);
    message.error('加载客户端配置失败');
  } finally {
    loading.value = false;
  }
}

async function loadCategories() {
  categoryLoading.value = true;
  try {
    categoryTree.value = await getGoodsCategoryTreeApi({ status: 1 });
  } catch (error) {
    console.error('加载商品分类失败:', error);
    message.error('加载商品分类失败');
  } finally {
    categoryLoading.value = false;
  }
}

function fillFormFromSettings() {
  form.client_site_name = settingValue('client_site_name');
  form.client_splash_enabled = isSwitchOn(
    settingValue('client_splash_enabled'),
  );
  form.client_splash_duration = settingNumberValue(
    'client_splash_duration',
    3000,
  );
  form.client_share_title = settingValue('client_share_title');
  form.client_share_desc = settingValue('client_share_desc');
  const customerServiceMode = settingValue(
    'client_customer_service_mode',
    'phone',
  );
  form.client_customer_service_mode =
    customerServiceMode === 'system' ? 'system' : 'phone';
  form.client_customer_service_phone = settingValue(
    'client_customer_service_phone',
  );
  form.customer_service_allowed_ips = settingValue(
    'customer_service_allowed_ips',
  );
  form.customer_service_api_base = settingValue('customer_service_api_base');
  form.customer_service_connector_enabled = isSwitchOn(
    settingValue('customer_service_connector_enabled'),
    false,
  );
  form.customer_service_connector_secret = '';
  form.customer_service_context_key_id = settingValue(
    'customer_service_context_key_id',
  );
  form.customer_service_context_secret = '';
  form.customer_service_context_ttl = settingNumberValue(
    'customer_service_context_ttl',
    300,
  );
  form.customer_service_operator_admin_id = settingNumberValue(
    'customer_service_operator_admin_id',
    1,
  );
  form.customer_service_platform_code = settingValue(
    'customer_service_platform_code',
    'mallbase',
  );
  form.customer_service_socket_base = settingValue(
    'customer_service_socket_base',
  );
  form.customer_service_timestamp_window = settingNumberValue(
    'customer_service_timestamp_window',
    300,
  );
  imageValues.client_logo = imageValue('client_logo');
  imageValues.client_launch_image = imageValue('client_launch_image');
  imageValues.client_share_cover = imageValue('client_share_cover');

  form.client_goods_card_show_cart_button = isSwitchOn(
    settingValue('client_goods_card_show_cart_button'),
  );
  form.client_goods_card_show_sales = isSwitchOn(
    settingValue('client_goods_card_show_sales'),
  );
  form.client_goods_card_show_market_price = isSwitchOn(
    settingValue('client_goods_card_show_market_price'),
  );
  form.client_goods_card_show_subtitle = isSwitchOn(
    settingValue('client_goods_card_show_subtitle'),
  );
  form.client_goods_card_show_badge = isSwitchOn(
    settingValue('client_goods_card_show_badge'),
  );
  form.client_goods_badge_config = normalizeGoodsBadgeConfig(
    settingValue('client_goods_badge_config'),
  );
  form.client_goods_guarantees = parseJsonArray<GuaranteeItem>(
    settingValue('client_goods_guarantees'),
    [],
  );

  form.client_search_history_enabled = isSwitchOn(
    settingValue('client_search_history_enabled'),
  );
  form.client_search_quick_filter_enabled = isSwitchOn(
    settingValue('client_search_quick_filter_enabled'),
  );
  form.client_search_hot_enabled = isSwitchOn(
    settingValue('client_search_hot_enabled'),
  );
  form.client_search_category_enabled = isSwitchOn(
    settingValue('client_search_category_enabled'),
  );
  form.client_search_quick_filters = parseJsonArray<QuickFilterValue>(
    settingValue('client_search_quick_filters'),
    ['is_new', 'is_hot', 'is_recommend', 'category'],
  ).filter((value) => quickFilterOptions.some((item) => item.value === value));
  form.client_search_category_ids = parseJsonArray<number>(
    settingValue('client_search_category_ids'),
    [],
  )
    .map(Number)
    .filter((id) => Number.isFinite(id));

  form.client_about_content = settingValue('client_about_content');
  form.client_agreement = settingValue('client_agreement');
  form.client_privacy = settingValue('client_privacy');
  form.client_platform_rules = settingValue('client_platform_rules');
  form.client_after_sale_policy = settingValue('client_after_sale_policy');
}

function addGuarantee() {
  form.client_goods_guarantees.push({
    title: '',
    desc: '',
  });
}

function removeGuarantee(index: number) {
  form.client_goods_guarantees.splice(index, 1);
}

function openContentEditor(item: ContentItem) {
  editingContent.value = item;
  contentDraft.value = form[item.code] || '';
  contentDrawerOpen.value = true;
}

async function saveContentDraft() {
  if (!editingContent.value) return;
  form[editingContent.value.code] = contentDraft.value;
  const saved = await saveConfig([editingContent.value.code]);
  if (saved) {
    contentDrawerOpen.value = false;
  }
}

function openPreview(item: ContentItem) {
  previewContent.value = item;
  previewOpen.value = true;
}

function buildPayload(codes?: string[]) {
  const payload: Record<string, unknown> = {
    client_about_content: form.client_about_content,
    client_after_sale_policy: form.client_after_sale_policy,
    client_agreement: form.client_agreement,
    client_customer_service_mode: form.client_customer_service_mode,
    client_customer_service_phone: form.client_customer_service_phone,
    client_goods_badge_config: JSON.stringify(
      normalizeGoodsBadgeConfig(form.client_goods_badge_config),
    ),
    client_goods_card_show_badge: form.client_goods_card_show_badge ? 1 : 0,
    client_goods_card_show_cart_button: form.client_goods_card_show_cart_button
      ? 1
      : 0,
    client_goods_card_show_market_price:
      form.client_goods_card_show_market_price ? 1 : 0,
    client_goods_card_show_sales: form.client_goods_card_show_sales ? 1 : 0,
    client_goods_card_show_subtitle: form.client_goods_card_show_subtitle
      ? 1
      : 0,
    client_goods_guarantees: JSON.stringify(
      form.client_goods_guarantees
        .map((item) => ({
          title: item.title.trim(),
          desc: item.desc.trim(),
        }))
        .filter((item) => item.title),
    ),
    client_launch_image: uploadValueToString(imageValues.client_launch_image),
    client_logo: uploadValueToString(imageValues.client_logo),
    client_platform_rules: form.client_platform_rules,
    client_privacy: form.client_privacy,
    client_search_category_enabled: form.client_search_category_enabled ? 1 : 0,
    client_search_category_ids: JSON.stringify(form.client_search_category_ids),
    client_search_history_enabled: form.client_search_history_enabled ? 1 : 0,
    client_search_hot_enabled: form.client_search_hot_enabled ? 1 : 0,
    client_search_quick_filter_enabled: form.client_search_quick_filter_enabled
      ? 1
      : 0,
    client_search_quick_filters: JSON.stringify(
      form.client_search_quick_filters,
    ),
    client_share_cover: uploadValueToString(imageValues.client_share_cover),
    client_share_desc: form.client_share_desc,
    client_share_title: form.client_share_title,
    client_site_name: form.client_site_name,
    client_splash_duration: form.client_splash_duration,
    client_splash_enabled: form.client_splash_enabled ? 1 : 0,
    customer_service_allowed_ips: form.customer_service_allowed_ips,
    customer_service_api_base: form.customer_service_api_base,
    customer_service_connector_enabled: form.customer_service_connector_enabled
      ? 1
      : 0,
    customer_service_connector_secret: form.customer_service_connector_secret,
    customer_service_context_key_id: form.customer_service_context_key_id,
    customer_service_context_secret: form.customer_service_context_secret,
    customer_service_context_ttl: form.customer_service_context_ttl,
    customer_service_operator_admin_id: form.customer_service_operator_admin_id,
    customer_service_platform_code: form.customer_service_platform_code,
    customer_service_socket_base: form.customer_service_socket_base,
    customer_service_timestamp_window: form.customer_service_timestamp_window,
  };

  if (!codes) return payload;
  return Object.fromEntries(codes.map((code) => [code, payload[code]]));
}

async function saveConfig(codes?: string[]) {
  saving.value = true;
  try {
    await saveSettingConfigApi('ClientConfig', buildPayload(codes));
    message.success('保存成功');
    await loadConfig();
    return true;
  } catch (error) {
    console.error('保存客户端配置失败:', error);
    return false;
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadConfig();
  loadCategories();
});

watch(contentDrawerOpen, async (open) => {
  if (contentEditorFrame !== undefined) {
    cancelAnimationFrame(contentEditorFrame);
    contentEditorFrame = undefined;
  }

  contentEditorReady.value = false;
  if (!open) return;

  await nextTick();
  contentEditorFrame = requestAnimationFrame(() => {
    contentEditorReady.value = true;
    contentEditorFrame = undefined;
  });
});

onBeforeUnmount(() => {
  if (contentEditorFrame !== undefined) {
    cancelAnimationFrame(contentEditorFrame);
  }
});
</script>

<template>
  <div class="client-config-page">
    <div class="client-config-header">
      <div>
        <h2 class="client-config-title">客户端配置</h2>
        <p class="client-config-desc">
          管理客户端基础信息、商品展示、搜索页和内容页面。
        </p>
      </div>
      <div class="client-config-actions">
        <a-button @click="loadConfig">刷新</a-button>
        <a-button type="primary" :loading="saving" @click="saveConfig()">
          保存配置
        </a-button>
      </div>
    </div>

    <a-spin :spinning="loading">
      <div class="client-config-panel">
        <a-tabs v-model:active-key="activeTab">
          <a-tab-pane key="basic" tab="基础配置">
            <a-form layout="vertical" class="config-form-grid">
              <a-form-item label="客户端站点名称">
                <a-input
                  v-model:value="form.client_site_name"
                  placeholder="请输入客户端站点名称"
                />
              </a-form-item>
              <a-form-item label="启用启动页">
                <a-switch v-model:checked="form.client_splash_enabled" />
              </a-form-item>
              <a-form-item
                v-if="form.client_splash_enabled"
                label="启动页时长(ms)"
              >
                <a-input-number
                  v-model:value="form.client_splash_duration"
                  :min="0"
                  :max="30_000"
                  class="w-full"
                />
              </a-form-item>
              <a-form-item label="客户端图标">
                <Upload
                  v-model:value="imageValues.client_logo"
                  type="image"
                  module="dynamic_form"
                  mode="both"
                />
              </a-form-item>
              <a-form-item label="启动屏图">
                <Upload
                  v-model:value="imageValues.client_launch_image"
                  type="image"
                  module="dynamic_form"
                  mode="both"
                />
              </a-form-item>
              <a-form-item label="分享默认标题">
                <a-input
                  v-model:value="form.client_share_title"
                  placeholder="留空时使用站点名称"
                />
              </a-form-item>
              <a-form-item label="分享默认简介">
                <a-textarea
                  v-model:value="form.client_share_desc"
                  :rows="3"
                  placeholder="请输入分享默认简介"
                />
              </a-form-item>
              <a-form-item label="分享默认封面">
                <Upload
                  v-model:value="imageValues.client_share_cover"
                  type="image"
                  module="dynamic_form"
                  mode="both"
                />
              </a-form-item>
            </a-form>
          </a-tab-pane>

          <a-tab-pane key="goods" tab="商品配置">
            <div class="setting-block">
              <div class="setting-block__head">
                <h3>商品卡片展示</h3>
                <p>控制首页商品组和商品列表页的商品卡片展示项。</p>
              </div>
              <div class="switch-grid">
                <div class="switch-item">
                  <span>显示快捷加购按钮</span>
                  <a-switch
                    v-model:checked="form.client_goods_card_show_cart_button"
                  />
                </div>
                <div class="switch-item">
                  <span>显示销量</span>
                  <a-switch
                    v-model:checked="form.client_goods_card_show_sales"
                  />
                </div>
                <div class="switch-item">
                  <span>显示市场价</span>
                  <a-switch
                    v-model:checked="form.client_goods_card_show_market_price"
                  />
                </div>
                <div class="switch-item">
                  <span>显示商品副标题</span>
                  <a-switch
                    v-model:checked="form.client_goods_card_show_subtitle"
                  />
                </div>
                <div class="switch-item">
                  <span>显示推荐/新品/热卖角标</span>
                  <a-switch
                    v-model:checked="form.client_goods_card_show_badge"
                  />
                </div>
              </div>
            </div>

            <div v-if="form.client_goods_card_show_badge" class="setting-block">
              <div class="setting-block__head">
                <h3>角标样式配置</h3>
                <p>控制新品、热卖、推荐三个商品角标的文案和展示样式。</p>
              </div>
              <div class="badge-config-layout">
                <a-form layout="vertical" class="badge-config-form">
                  <div class="badge-config-grid">
                    <a-form-item label="新品文案">
                      <a-input
                        v-model:value="form.client_goods_badge_config.new.text"
                        maxlength="6"
                      />
                    </a-form-item>
                    <a-form-item label="热卖文案">
                      <a-input
                        v-model:value="form.client_goods_badge_config.hot.text"
                        maxlength="6"
                      />
                    </a-form-item>
                    <a-form-item label="推荐文案">
                      <a-input
                        v-model:value="
                          form.client_goods_badge_config.recommend.text
                        "
                        maxlength="6"
                      />
                    </a-form-item>
                    <a-form-item label="背景颜色">
                      <div class="color-field">
                        <input
                          aria-label="选择角标背景颜色"
                          class="color-field__picker"
                          type="color"
                          :value="
                            colorPickerValue(
                              form.client_goods_badge_config.style
                                .backgroundColor,
                              'backgroundColor',
                            )
                          "
                          @input="
                            (event) =>
                              updateBadgeColor('backgroundColor', event)
                          "
                        />
                        <a-input
                          v-model:value="
                            form.client_goods_badge_config.style.backgroundColor
                          "
                          allow-clear
                          placeholder="留空则跟随主题色"
                        />
                        <a-button
                          class="color-field__action"
                          size="small"
                          @click="clearBadgeColor('backgroundColor')"
                        >
                          跟随主题
                        </a-button>
                      </div>
                      <div class="field-hint">
                        留空时使用客户端当前主题主色。
                      </div>
                    </a-form-item>
                    <a-form-item label="文字颜色">
                      <div class="color-field">
                        <input
                          aria-label="选择角标文字颜色"
                          class="color-field__picker"
                          type="color"
                          :value="
                            colorPickerValue(
                              form.client_goods_badge_config.style.textColor,
                              'textColor',
                            )
                          "
                          @input="
                            (event) => updateBadgeColor('textColor', event)
                          "
                        />
                        <a-input
                          v-model:value="
                            form.client_goods_badge_config.style.textColor
                          "
                          allow-clear
                          placeholder="留空则跟随主题文字色"
                        />
                        <a-button
                          class="color-field__action"
                          size="small"
                          @click="clearBadgeColor('textColor')"
                        >
                          跟随主题
                        </a-button>
                      </div>
                      <div class="field-hint">
                        留空时使用客户端主题的前景色。
                      </div>
                    </a-form-item>
                    <a-form-item label="字号">
                      <a-input-number
                        v-model:value="
                          form.client_goods_badge_config.style.fontSize
                        "
                        :min="16"
                        :max="36"
                        addon-after="rpx"
                        class="w-full"
                      />
                    </a-form-item>
                    <a-form-item label="背景高度">
                      <a-input-number
                        v-model:value="
                          form.client_goods_badge_config.style.height
                        "
                        :min="24"
                        :max="60"
                        addon-after="rpx"
                        class="w-full"
                      />
                    </a-form-item>
                    <a-form-item label="左右内边距">
                      <a-input-number
                        v-model:value="
                          form.client_goods_badge_config.style.paddingX
                        "
                        :min="6"
                        :max="40"
                        addon-after="rpx"
                        class="w-full"
                      />
                    </a-form-item>
                    <a-form-item label="圆角">
                      <a-input-number
                        v-model:value="
                          form.client_goods_badge_config.style.borderRadius
                        "
                        :min="0"
                        :max="999"
                        addon-after="rpx"
                        class="w-full"
                      />
                    </a-form-item>
                  </div>
                </a-form>
                <div class="badge-preview-panel">
                  <div class="badge-preview-panel__title">实时预览</div>
                  <div class="badge-preview-list">
                    <span class="badge-preview" :style="goodsBadgePreviewStyle">
                      {{ form.client_goods_badge_config.new.text || '新品' }}
                    </span>
                    <span class="badge-preview" :style="goodsBadgePreviewStyle">
                      {{ form.client_goods_badge_config.hot.text || '热卖' }}
                    </span>
                    <span class="badge-preview" :style="goodsBadgePreviewStyle">
                      {{
                        form.client_goods_badge_config.recommend.text || '推荐'
                      }}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <div class="setting-block">
              <div class="setting-block__head">
                <h3>商品保障</h3>
                <p>客户端详情页当前展示保障标题，说明文案随接口保留。</p>
              </div>
              <div class="guarantee-list">
                <div
                  v-for="(item, index) in form.client_goods_guarantees"
                  :key="index"
                  class="guarantee-row"
                >
                  <a-input v-model:value="item.title" placeholder="保障标题" />
                  <a-input v-model:value="item.desc" placeholder="保障说明" />
                  <a-button danger @click="removeGuarantee(index)">
                    删除
                  </a-button>
                </div>
                <a-button type="dashed" @click="addGuarantee">
                  添加保障
                </a-button>
              </div>
            </div>
          </a-tab-pane>

          <a-tab-pane key="search" tab="搜索页">
            <div class="setting-block">
              <div class="setting-block__head">
                <h3>区块开关</h3>
                <p>控制搜索页四个内容区块是否展示。</p>
              </div>
              <div class="switch-grid">
                <div class="switch-item">
                  <span>搜索历史</span>
                  <a-switch
                    v-model:checked="form.client_search_history_enabled"
                  />
                </div>
                <div class="switch-item">
                  <span>快捷筛选</span>
                  <a-switch
                    v-model:checked="form.client_search_quick_filter_enabled"
                  />
                </div>
                <div class="switch-item">
                  <span>热门搜索</span>
                  <a-switch v-model:checked="form.client_search_hot_enabled" />
                </div>
                <div class="switch-item">
                  <span>常用分类</span>
                  <a-switch
                    v-model:checked="form.client_search_category_enabled"
                  />
                </div>
              </div>
            </div>

            <div
              v-if="form.client_search_quick_filter_enabled"
              class="setting-block"
            >
              <div class="setting-block__head">
                <h3>快捷筛选入口</h3>
                <p>开启快捷筛选后，客户端只展示这里勾选的固定入口。</p>
              </div>
              <a-checkbox-group
                v-model:value="form.client_search_quick_filters"
                class="quick-filter-grid"
              >
                <a-checkbox
                  v-for="item in quickFilterOptions"
                  :key="item.value"
                  :value="item.value"
                >
                  <div class="quick-filter-option">
                    <strong>{{ item.label }}</strong>
                    <span>{{ item.desc }}</span>
                  </div>
                </a-checkbox>
              </a-checkbox-group>
            </div>

            <div
              v-if="form.client_search_category_enabled"
              class="setting-block"
            >
              <div class="setting-block__head">
                <h3>常用分类数据</h3>
                <p>开启常用分类后，客户端优先按选择顺序展示这些分类。</p>
              </div>
              <a-tree-select
                v-model:value="form.client_search_category_ids"
                :tree-data="categoryTreeData"
                :loading="categoryLoading"
                tree-checkable
                allow-clear
                show-search
                tree-node-filter-prop="title"
                placeholder="请选择常用分类"
                class="w-full"
              />
            </div>
          </a-tab-pane>

          <a-tab-pane key="customer-service" tab="客服配置">
            <div class="setting-block">
              <div class="setting-block__head">
                <h3>客服入口</h3>
                <p>控制客户端“联系客服”入口使用手机号或在线客服系统。</p>
              </div>
              <a-form layout="vertical" class="config-form-grid">
                <a-form-item label="客服方式">
                  <a-radio-group
                    v-model:value="form.client_customer_service_mode"
                    button-style="solid"
                  >
                    <a-radio-button value="phone">手机号客服</a-radio-button>
                    <a-radio-button value="system">
                      在线客服系统
                    </a-radio-button>
                  </a-radio-group>
                </a-form-item>
                <a-form-item
                  v-if="form.client_customer_service_mode === 'phone'"
                  label="客服手机号"
                >
                  <a-input
                    v-model:value="form.client_customer_service_phone"
                    placeholder="请输入客服手机号"
                  />
                </a-form-item>
              </a-form>
            </div>

            <div
              v-if="form.client_customer_service_mode === 'system'"
              class="setting-block"
            >
              <div class="setting-block__head">
                <h3>在线客服接入</h3>
                <p>
                  配置 MallBase 原生客服页连接 Customer Service 所需的
                  API、实时通信地址和上下文凭证。
                </p>
              </div>
              <a-form layout="vertical" class="config-form-grid">
                <a-form-item label="平台标识">
                  <a-input
                    v-model:value="form.customer_service_platform_code"
                    placeholder="mallbase"
                  />
                </a-form-item>
                <a-form-item label="上下文 Key ID">
                  <a-input
                    v-model:value="form.customer_service_context_key_id"
                    placeholder="ctx_..."
                  />
                  <div class="field-hint">
                    在 Customer Service 的“对接平台 →
                    上下文凭证”创建后，一次性复制 Key ID
                    和密钥；两项必须成对更新。
                  </div>
                </a-form-item>
                <a-form-item label="客服 API 基础地址">
                  <a-input
                    v-model:value="form.customer_service_api_base"
                    placeholder="https://customer.example.com/api"
                  />
                  <div class="field-hint">
                    小程序需将该 HTTPS 域名配置为 request 和 uploadFile
                    合法域名。
                  </div>
                </a-form-item>
                <a-form-item label="Socket.IO 服务地址">
                  <a-input
                    v-model:value="form.customer_service_socket_base"
                    placeholder="https://customer.example.com"
                  />
                  <div class="field-hint">
                    填写服务 Origin，不要带 /api 或 /socket.io；小程序
                    还需配置对应的 socket 合法域名。
                  </div>
                </a-form-item>
                <a-form-item label="Token 有效期(秒)">
                  <a-input-number
                    v-model:value="form.customer_service_context_ttl"
                    :min="60"
                    :max="300"
                    class="w-full"
                  />
                </a-form-item>
                <a-form-item>
                  <template #label>
                    上下文签名密钥
                    <a-tag
                      :color="
                        settingHasValue('customer_service_context_secret')
                          ? 'green'
                          : 'orange'
                      "
                    >
                      {{
                        settingHasValue('customer_service_context_secret')
                          ? '已配置'
                          : '未配置'
                      }}
                    </a-tag>
                  </template>
                  <a-input-password
                    v-model:value="form.customer_service_context_secret"
                    autocomplete="new-password"
                    :placeholder="
                      settingHasValue('customer_service_context_secret')
                        ? '留空保持不变'
                        : '粘贴 Customer Service 一次性显示的密钥'
                    "
                  />
                </a-form-item>
              </a-form>
            </div>

            <div
              v-if="form.client_customer_service_mode === 'system'"
              class="setting-block"
            >
              <div class="setting-block__head">
                <h3>连接器接口</h3>
                <p>配置客服系统调用 MallBase 后端接口时的服务端签名校验。</p>
              </div>
              <a-form layout="vertical" class="config-form-grid">
                <a-form-item label="启用连接器">
                  <a-switch
                    v-model:checked="form.customer_service_connector_enabled"
                  />
                </a-form-item>
                <a-form-item label="签名时间窗口(秒)">
                  <a-input-number
                    v-model:value="form.customer_service_timestamp_window"
                    :min="60"
                    :max="3600"
                    class="w-full"
                  />
                </a-form-item>
                <a-form-item label="允许 IP">
                  <a-input
                    v-model:value="form.customer_service_allowed_ips"
                    placeholder="留空表示不限制，多个 IP 用英文逗号分隔"
                  />
                </a-form-item>
                <a-form-item label="操作管理员 ID">
                  <a-input-number
                    v-model:value="form.customer_service_operator_admin_id"
                    :min="1"
                    class="w-full"
                  />
                </a-form-item>
                <a-form-item>
                  <template #label>
                    连接器签名密钥
                    <a-tag
                      :color="
                        settingHasValue('customer_service_connector_secret')
                          ? 'green'
                          : 'orange'
                      "
                    >
                      {{
                        settingHasValue('customer_service_connector_secret')
                          ? '已配置'
                          : '未配置'
                      }}
                    </a-tag>
                  </template>
                  <div class="secret-input-row">
                    <a-input-password
                      v-model:value="form.customer_service_connector_secret"
                      autocomplete="new-password"
                      :placeholder="
                        settingHasValue('customer_service_connector_secret')
                          ? '留空保持不变'
                          : '请输入不少于 32 位的随机密钥'
                      "
                    />
                    <a-button
                      class="secret-input-row__action"
                      @click="
                        fillRandomSecret('customer_service_connector_secret')
                      "
                    >
                      生成
                    </a-button>
                  </div>
                </a-form-item>
              </a-form>
            </div>
          </a-tab-pane>

          <a-tab-pane key="content" tab="内容页面">
            <div class="content-grid">
              <div
                v-for="item in contentItems"
                :key="item.code"
                class="content-card"
                @click="openContentEditor(item)"
              >
                <div class="content-card__main">
                  <div>
                    <h3>{{ item.title }}</h3>
                    <p>{{ item.desc }}</p>
                  </div>
                  <a-tag
                    :color="contentConfigured(item.code) ? 'green' : 'orange'"
                  >
                    {{ contentConfigured(item.code) ? '已配置' : '未配置' }}
                  </a-tag>
                </div>
                <div class="content-card__meta">
                  <span>更新时间：{{ contentUpdatedAt(item.code) }}</span>
                </div>
                <div class="content-card__actions" @click.stop>
                  <a-button size="small" @click="openPreview(item)">
                    预览
                  </a-button>
                  <a-button
                    size="small"
                    type="primary"
                    @click="openContentEditor(item)"
                  >
                    编辑
                  </a-button>
                </div>
              </div>
            </div>
          </a-tab-pane>
        </a-tabs>
      </div>
    </a-spin>

    <a-drawer
      v-model:open="contentDrawerOpen"
      :title="editingContent ? `编辑${editingContent.title}` : '编辑内容'"
      width="100%"
      :body-style="{ overflow: 'hidden', padding: 0 }"
      destroy-on-close
    >
      <div class="content-editor-drawer">
        <RichTextEditor
          v-if="contentEditorReady"
          v-model="contentDraft"
          :height="360"
          class="content-editor-drawer__editor"
          module="client_content"
          :placeholder="
            editingContent ? `请输入${editingContent.title}` : '请输入内容'
          "
        />
      </div>
      <template #footer>
        <div class="drawer-footer">
          <a-button @click="contentDrawerOpen = false">取消</a-button>
          <a-button type="primary" :loading="saving" @click="saveContentDraft">
            保存
          </a-button>
        </div>
      </template>
    </a-drawer>

    <a-modal
      v-model:open="previewOpen"
      :title="previewContent ? `${previewContent.title}预览` : '预览'"
      :footer="null"
      width="860px"
    >
      <iframe
        v-if="previewContent"
        class="content-preview-frame"
        sandbox=""
        :srcdoc="previewHtml(previewContent.code)"
      ></iframe>
    </a-modal>
  </div>
</template>

<style scoped>
.client-config-page {
  padding: 16px;
}

.client-config-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}

.client-config-title {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
}

.client-config-desc {
  margin: 6px 0 0;
  color: hsl(var(--muted-foreground));
}

.client-config-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}

.client-config-panel,
.setting-block {
  background: hsl(var(--card));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.client-config-panel {
  padding: 16px;
}

.config-form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 20px;
}

.setting-block {
  padding: 18px;
}

.setting-block + .setting-block {
  margin-top: 16px;
}

.setting-block__head {
  margin-bottom: 16px;
}

.setting-block__head h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
}

.setting-block__head p {
  margin: 6px 0 0;
  color: hsl(var(--muted-foreground));
}

.switch-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.switch-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 48px;
  padding: 0 14px;
  background: hsl(var(--muted) / 40%);
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.badge-config-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 280px;
  gap: 18px;
  align-items: stretch;
}

.badge-config-form {
  min-width: 0;
}

.badge-config-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px 16px;
}

.color-field {
  display: flex;
  align-items: center;
  gap: 8px;
}

.color-field__picker {
  width: 40px;
  height: 32px;
  padding: 0;
  background: transparent;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
  cursor: pointer;
  flex-shrink: 0;
}

.color-field__action {
  flex-shrink: 0;
}

.field-hint {
  margin-top: 6px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
  line-height: 1.5;
}

.secret-input-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.secret-input-row :deep(.ant-input-password) {
  flex: 1;
  min-width: 0;
}

.secret-input-row__action {
  flex-shrink: 0;
}

.badge-preview-panel {
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-height: 184px;
  padding: 16px;
  background: hsl(var(--muted) / 35%);
  border: 1px dashed hsl(var(--border));
  border-radius: 8px;
}

.badge-preview-panel__title {
  margin-bottom: 14px;
  color: hsl(var(--muted-foreground));
  font-size: 13px;
}

.badge-preview-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.badge-preview {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  max-width: 100%;
  font-weight: 600;
  line-height: 1;
}

.guarantee-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.guarantee-row {
  display: grid;
  grid-template-columns: minmax(120px, 180px) minmax(180px, 1fr) 72px;
  gap: 10px;
}

.quick-filter-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.quick-filter-option {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 2px 0;
}

.quick-filter-option span {
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.content-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.content-card {
  min-height: 164px;
  padding: 16px;
  background: hsl(var(--card));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  cursor: pointer;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease;
}

.content-card:hover {
  border-color: hsl(var(--primary) / 60%);
  box-shadow: 0 10px 24px hsl(var(--foreground) / 8%);
}

.content-card__main {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.content-card h3 {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
}

.content-card p {
  margin: 8px 0 0;
  color: hsl(var(--muted-foreground));
  line-height: 1.6;
}

.content-card__meta {
  margin-top: 18px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.content-card__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 14px;
}

.content-editor-drawer {
  box-sizing: border-box;
  height: 100%;
  min-height: 0;
  padding: 16px;
}

.content-editor-drawer__editor {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.content-editor-drawer__editor :deep(.rich-text-editor__body) {
  flex: 1;
  min-height: 0;
  height: auto !important;
}

.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.content-preview-frame {
  width: 100%;
  height: 640px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

@media (max-width: 1100px) {
  .content-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .client-config-header,
  .switch-grid,
  .config-form-grid,
  .badge-config-layout,
  .badge-config-grid,
  .quick-filter-grid,
  .content-grid {
    grid-template-columns: 1fr;
  }

  .client-config-header {
    display: grid;
  }

  .guarantee-row {
    grid-template-columns: 1fr;
  }
}
</style>
