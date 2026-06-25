<script lang="ts" setup>
import type { ClientDecorateApi } from '#/api/client';
import type { GoodsApi, GoodsCategoryApi } from '#/api/goods';

import { computed, ref } from 'vue';

import { IconifyIcon } from '@vben/icons';

import RichTextEditor from '#/components/rich-text-editor/index.vue';
import Upload from '#/components/upload/index.vue';

import ClientPhonePreview from '../../components/ClientPhonePreview.vue';
import { paddingSideFields } from '../utils/useModuleSpacing';
import ModuleStylePanel from './ModuleStylePanel.vue';
import PageLinkPicker from './PageLinkPicker.vue';
import ProductSourcePicker from './ProductSourcePicker.vue';
import SpacingControl from './SpacingControl.vue';
import TargetPicker from './TargetPicker.vue';

type ModuleItem = Record<string, any>;

type PaletteItem = {
  desc: string;
  icon: string;
  label: string;
  type: string;
};

type PaletteGroup = {
  items: PaletteItem[];
  title: string;
};

const props = defineProps<{
  activeType: ClientDecorateApi.SchemeType;
  activeTypeLabel: string;
  currentThemeTokens: Record<string, string>;
  dragActive: boolean;
  dragDropIndex: null | number;
  iconPrefix: string;
  isReadonlyScheme: boolean;
  normalizeProfileModuleType: (type: string) => string;
  paletteGroups: PaletteGroup[];
  previewCategoryTree: GoodsCategoryApi.CategoryItem[];
  previewGoods: GoodsApi.GoodsItem | null;
  previewGoodsList: GoodsApi.GoodsItem[];
  productLayoutOptions: Array<{ label: string; value: string }>;
  productSortOptions: Array<{ label: string; value: string }>;
  productSourceOptions: Array<{ label: string; value: string }>;
  schemeForm: {
    description: string;
    name: string;
    pageStyle: Record<string, any>;
    schema: ModuleItem[];
    sort: number;
    status: number;
    tabbar_mode: ClientDecorateApi.TabbarMode;
  };
  selectedModule: ModuleItem | null;
  selectedModuleId: null | string;
  tabbarPreviewItems: ModuleItem[];
}>();

const emit = defineEmits<{
  addNavItem: [module: ModuleItem];
  addProfileItem: [module: ModuleItem];
  moduleDelete: [index: number];
  moduleMouseDown: [index: number, event: MouseEvent];
  moduleMove: [index: number, direction: 'down' | 'up'];
  paletteClick: [type: string];
  paletteMouseDown: [item: PaletteItem, event: MouseEvent];
  removeConfigItem: [items: any[], index: number | string];
  resetModuleConfig: [module: ModuleItem];
  resetModuleContent: [module: ModuleItem];
  resetModuleStyle: [module: ModuleItem];
  resetPageStyle: [];
  selectModule: [module: ModuleItem];
  updatePageStyle: [field: string, value: unknown];
}>();

const previewCurrentPath = computed(() =>
  props.activeType === 'profile'
    ? '/pages/profile/index'
    : '/pages/index/index',
);

const previewKind = computed(() =>
  props.activeType === 'tabbar' ? 'tabbar' : props.activeType,
);

const editableModule = computed(() => props.selectedModule);
const editableProfileType = computed(() =>
  editableModule.value
    ? props.normalizeProfileModuleType(String(editableModule.value.type || ''))
    : '',
);
const isProfileEntryModule = computed(() =>
  ['customMenu', 'orderEntry', 'serviceMenu'].includes(
    editableProfileType.value,
  ),
);

const bannerDragIndex = ref<null | number>(null);
const bannerDropIndex = ref<null | number>(null);
const navDragIndex = ref<null | number>(null);
const navDropIndex = ref<null | number>(null);
const profileEntryDragIndex = ref<null | number>(null);
const profileEntryDropIndex = ref<null | number>(null);
type ProfileTextStyleField =
  | 'backgroundColorEnd'
  | 'backgroundColorStart'
  | 'backgroundGradientDirection'
  | 'backgroundHeight'
  | 'backgroundImage'
  | 'backgroundMode'
  | 'backgroundPosition'
  | 'backgroundRadius'
  | 'backgroundWidth'
  | 'color'
  | 'fontSize'
  | 'fontStyle'
  | 'fontWeight'
  | 'textAlign';
type ProfileTextStyleRole =
  | 'action'
  | 'amount'
  | 'iconText'
  | 'itemLabel'
  | 'meta'
  | 'more'
  | 'placeholder'
  | 'primaryAction'
  | 'subtitle'
  | 'title';

const profileStyleColorDefaults: Record<string, string> = {
  backgroundColorEnd: '#ffffff',
  backgroundColorStart: '#ffffff',
  borderColor: '#e5e5e5',
  shadowColor: '#0f172a',
};

const profilePageStyleColorDefaults: Record<string, string> = {
  backgroundColorEnd: '#ffffff',
  backgroundColorStart: '#ffffff',
};

const profilePagePaddingDefaults: Record<string, number> = {
  paddingBottom: 24,
  paddingLeft: 28,
  paddingRight: 28,
  paddingTop: 10,
};

const homePagePaddingDefaults: Record<string, number> = {
  paddingBottom: 0,
  paddingLeft: 28,
  paddingRight: 28,
  paddingTop: 0,
};

const backgroundModeOptions = [
  { label: '颜色', value: 'color' },
  { label: '图片', value: 'image' },
];

const gradientDirectionOptions = [
  { label: '横向', value: 'horizontal' },
  { label: '纵向', value: 'vertical' },
  { label: '左斜', value: 'diagonalLeft' },
  { label: '右斜', value: 'diagonalRight' },
];

const titleBackgroundPositionOptions = [
  { label: '左上', value: 'topLeft' },
  { label: '上', value: 'top' },
  { label: '右上', value: 'topRight' },
  { label: '左', value: 'centerLeft' },
  { label: '居中', value: 'center' },
  { label: '右', value: 'centerRight' },
  { label: '左下', value: 'bottomLeft' },
  { label: '下', value: 'bottom' },
  { label: '右下', value: 'bottomRight' },
];

const cubeLayoutDisplayLimitMap: Record<string, number> = {
  four: 4,
  one: 1,
  two: 2,
};

const maxCubeEditableItems = 12;

const visibilityOptions = [
  { label: '隐藏', value: false },
  { label: '显示', value: true },
];

const borderStyleOptions = [
  { label: '实线', value: 'solid' },
  { label: '虚线', value: 'dashed' },
  { label: '点状', value: 'dotted' },
];

const profileTextWeightOptions = [
  { label: '常规', value: '400' },
  { label: '中等', value: '500' },
  { label: '半粗', value: '600' },
  { label: '加粗', value: '700' },
  { label: '重粗', value: '800' },
  { label: '特粗', value: '900' },
];

const profileTextAlignOptions = [
  { label: '左对齐', value: 'left' },
  { label: '居中', value: 'center' },
  { label: '右对齐', value: 'right' },
];

const profileTextStyleDefaults: Record<
  string,
  Partial<
    Record<ProfileTextStyleRole, Partial<Record<ProfileTextStyleField, any>>>
  >
> = {
  customMenu: {
    itemLabel: {
      color: '#191b23',
      fontSize: 28,
      fontWeight: '500',
      textAlign: 'left',
    },
    title: {
      color: '#191b23',
      fontSize: 30,
      fontWeight: '700',
      textAlign: 'left',
    },
  },
  orderEntry: {
    itemLabel: {
      color: '#434654',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'center',
    },
    more: {
      color: '#737686',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'right',
    },
    title: {
      color: '#191b23',
      fontSize: 30,
      fontWeight: '700',
      textAlign: 'left',
    },
  },
  serviceMenu: {
    itemLabel: {
      color: '#191b23',
      fontSize: 28,
      fontWeight: '500',
      textAlign: 'left',
    },
    title: {
      color: '#191b23',
      fontSize: 30,
      fontWeight: '700',
      textAlign: 'left',
    },
  },
  userInfo: {
    action: {
      color: '#0d50d5',
      fontSize: 22,
      fontWeight: '600',
      textAlign: 'left',
    },
    meta: {
      color: '#434654',
      fontSize: 22,
      fontWeight: '400',
      textAlign: 'left',
    },
    subtitle: {
      color: '#737686',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'left',
    },
    title: {
      color: '#191b23',
      fontSize: 30,
      fontWeight: '700',
      textAlign: 'left',
    },
  },
  walletEntry: {
    action: {
      color: '#434654',
      fontSize: 24,
      fontWeight: '600',
      textAlign: 'center',
    },
    amount: {
      color: '#191b23',
      fontSize: 52,
      fontWeight: '800',
      textAlign: 'left',
    },
    meta: {
      color: '#737686',
      fontSize: 22,
      fontWeight: '400',
      textAlign: 'left',
    },
    primaryAction: {
      color: '#ffffff',
      fontSize: 24,
      fontWeight: '600',
      textAlign: 'center',
    },
    title: {
      color: '#434654',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'left',
    },
  },
  entryCard: {
    subtitle: {
      color: '#737686',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'left',
    },
    title: {
      color: '#191b23',
      fontSize: 30,
      fontWeight: '700',
      textAlign: 'left',
    },
  },
  imageCube: {
    itemLabel: {
      backgroundColorEnd: '#ffffff',
      backgroundColorStart: '#ffffff',
      backgroundGradientDirection: 'horizontal',
      backgroundHeight: 26,
      backgroundMode: 'color',
      backgroundPosition: 'bottom',
      backgroundRadius: 12,
      backgroundWidth: 100,
      color: '#191b23',
      fontSize: 28,
      fontWeight: '700',
      textAlign: 'center',
    },
  },
  navGrid: {
    itemLabel: {
      color: '#434654',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'center',
    },
  },
  productGroup: {
    more: {
      color: '#737686',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'right',
    },
    subtitle: {
      color: '#737686',
      fontSize: 22,
      fontWeight: '400',
      textAlign: 'left',
    },
    title: {
      color: '#191b23',
      fontSize: 30,
      fontWeight: '700',
      textAlign: 'left',
    },
  },
  search: {
    placeholder: {
      color: '#737686',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'left',
    },
  },
  title: {
    more: {
      color: '#737686',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'right',
    },
    subtitle: {
      color: '#737686',
      fontSize: 24,
      fontWeight: '400',
      textAlign: 'left',
    },
    title: {
      color: '#191b23',
      fontSize: 32,
      fontWeight: '800',
      textAlign: 'left',
    },
  },
};

const profileTextStyleFieldsByType: Record<
  string,
  Array<{ label: string; role: ProfileTextStyleRole }>
> = {
  customMenu: [
    { label: '组件标题', role: 'title' },
    { label: '入口文字', role: 'itemLabel' },
  ],
  orderEntry: [
    { label: '组件标题', role: 'title' },
    { label: '查看全部', role: 'more' },
    { label: '入口文字', role: 'itemLabel' },
  ],
  serviceMenu: [
    { label: '组件标题', role: 'title' },
    { label: '入口文字', role: 'itemLabel' },
  ],
  userInfo: [
    { label: '昵称/登录', role: 'title' },
    { label: '说明文字', role: 'subtitle' },
    { label: '手机号/签名', role: 'meta' },
  ],
  walletEntry: [
    { label: '卡片标题', role: 'title' },
    { label: '金额文字', role: 'amount' },
    { label: '辅助说明', role: 'meta' },
    { label: '普通按钮', role: 'action' },
    { label: '主按钮', role: 'primaryAction' },
  ],
};

const homeTextStyleFieldsByType: Record<
  string,
  Array<{ label: string; role: ProfileTextStyleRole }>
> = {
  entryCard: [
    { label: '卡片标题', role: 'title' },
    { label: '卡片副标题', role: 'subtitle' },
  ],
  imageCube: [{ label: '图片标题', role: 'itemLabel' }],
  navGrid: [{ label: '入口文字', role: 'itemLabel' }],
  productGroup: [
    { label: '组件标题', role: 'title' },
    { label: '组件副标题', role: 'subtitle' },
    { label: '更多文字', role: 'more' },
  ],
  search: [{ label: '占位文字', role: 'placeholder' }],
  title: [
    { label: '组件标题', role: 'title' },
    { label: '组件副标题', role: 'subtitle' },
    { label: '更多文字', role: 'more' },
  ],
};

const editableTextStyleType = computed(() => {
  if (!editableModule.value) return '';
  const type = String(editableModule.value.type || '');
  return props.activeType === 'profile'
    ? props.normalizeProfileModuleType(type)
    : type;
});

const moduleTextStyleFields = computed(() =>
  props.activeType === 'profile'
    ? profileTextStyleFieldsByType[editableProfileType.value] || []
    : homeTextStyleFieldsByType[editableTextStyleType.value] || [],
);

const normalizeProfileTextAlign = (value: unknown) => {
  const align = String(value || '');
  return profileTextAlignOptions.some((item) => item.value === align)
    ? align
    : '';
};

const normalizeTextBackgroundMode = (value: unknown) =>
  backgroundModeOptions.some((item) => item.value === value)
    ? String(value)
    : 'color';

const normalizeTextGradientDirection = (value: unknown) =>
  gradientDirectionOptions.some((item) => item.value === value)
    ? String(value)
    : 'horizontal';

const normalizeTextBackgroundPosition = (value: unknown) =>
  titleBackgroundPositionOptions.some((item) => item.value === value)
    ? String(value)
    : 'bottom';

const isImageCubeTitleTextStyle = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
) =>
  props.activeType === 'home' &&
  module?.type === 'imageCube' &&
  role === 'itemLabel';

const normalizeStyleColorValue = (value: unknown, fallback = '#ffffff') => {
  const color = typeof value === 'string' ? value.trim() : '';
  if (/^#[\da-f]{6}$/i.test(color)) return color;
  const shortColor = color.match(/^#([\da-f])([\da-f])([\da-f])$/i);
  if (shortColor) {
    return `#${shortColor[1]}${shortColor[1]}${shortColor[2]}${shortColor[2]}${shortColor[3]}${shortColor[3]}`;
  }
  return fallback;
};

const getProfileStyleColorInputValue = (
  config: Record<string, any>,
  field: string,
) =>
  normalizeStyleColorValue(
    config[field],
    profileStyleColorDefaults[field] || '#ffffff',
  );

const getPageStyleColorInputValue = (field: string) =>
  normalizeStyleColorValue(
    props.schemeForm.pageStyle[field],
    profilePageStyleColorDefaults[field] || '#ffffff',
  );

const syncModuleBackgroundShortcut = (config: Record<string, any>) => {
  const start = String(config.backgroundColorStart || '').trim();
  const end = String(config.backgroundColorEnd || '').trim();
  if (!start) {
    config.background = '';
    return;
  }
  if (!end || start.toLowerCase() === end.toLowerCase()) {
    config.background = start;
    return;
  }
  const directionMap: Record<string, string> = {
    diagonalLeft: '135deg',
    diagonalRight: '45deg',
    horizontal: '90deg',
    vertical: '180deg',
  };
  const direction =
    directionMap[String(config.backgroundGradientDirection || 'horizontal')] ||
    directionMap.horizontal;
  config.background = `linear-gradient(${direction}, ${start}, ${end})`;
};

const updatePageStyleColor = (field: string, value: unknown) => {
  emit('updatePageStyle', field, value);
  const pageStyle = props.schemeForm.pageStyle;
  if (field === 'backgroundColorStart' || field === 'backgroundColorEnd') {
    syncModuleBackgroundShortcut(pageStyle);
  }
};

const updatePageStyleColorFromEvent = (field: string, event: Event) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  if (value) updatePageStyleColor(field, value);
};

const updatePageStyleColorByField = (field: string) => {
  updatePageStyleColor(field, props.schemeForm.pageStyle[field]);
};

const updatePageStyleField = (field: string, value: unknown) => {
  emit('updatePageStyle', field, value);
};

const syncPageBackgroundShortcut = () => {
  syncModuleBackgroundShortcut(props.schemeForm.pageStyle);
};

const normalizeSpacingControlNumber = (value: unknown, fallback = 0) => {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return Math.max(0, fallback);
  return Math.max(0, Math.min(Math.round(numberValue), 160));
};

const getPagePaddingDefault = (field: string) =>
  props.activeType === 'profile'
    ? profilePagePaddingDefaults[field]
    : homePagePaddingDefaults[field];

const getPagePaddingSide = (field: string) => {
  const pageStyle = props.schemeForm.pageStyle;
  if (field === 'paddingTop') {
    return normalizeSpacingControlNumber(
      pageStyle.paddingTop ?? pageStyle.padding_top ?? pageStyle.paddingY,
      getPagePaddingDefault(field),
    );
  }
  if (field === 'paddingRight') {
    return normalizeSpacingControlNumber(
      pageStyle.paddingRight ?? pageStyle.padding_right ?? pageStyle.paddingX,
      getPagePaddingDefault(field),
    );
  }
  if (field === 'paddingBottom') {
    return normalizeSpacingControlNumber(
      pageStyle.paddingBottom ?? pageStyle.padding_bottom ?? pageStyle.paddingY,
      getPagePaddingDefault(field),
    );
  }
  return normalizeSpacingControlNumber(
    pageStyle.paddingLeft ?? pageStyle.padding_left ?? pageStyle.paddingX,
    getPagePaddingDefault(field),
  );
};

const getPagePaddingOverall = () => {
  const sides = paddingSideFields.map((item) => getPagePaddingSide(item.field));
  if (sides.every((value) => value === sides[0])) return sides[0] || 0;
  return Math.round(sides.reduce((total, value) => total + value, 0) / 4);
};

const updatePagePaddingAll = (value: unknown) => {
  const nextValue = normalizeSpacingControlNumber(value);
  paddingSideFields.forEach((item) => {
    emit('updatePageStyle', item.field, nextValue);
  });
};

const updatePagePaddingSide = (field: string, value: unknown) => {
  emit('updatePageStyle', field, normalizeSpacingControlNumber(value));
};

const updateModuleStyleColor = (
  module: ModuleItem | null,
  field: string,
  value: unknown,
) => {
  if (!module) return;
  const config = (module.config ||= {});
  config[field] = value;
  if (field === 'backgroundColorStart' || field === 'backgroundColorEnd') {
    syncModuleBackgroundShortcut(config);
  }
};

const updateModuleStyleColorFromEvent = (
  module: ModuleItem | null,
  field: string,
  event: Event,
) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  if (value) updateModuleStyleColor(module, field, value);
};

const updateModuleStyleColorByField = (
  module: ModuleItem | null,
  field: string,
) => {
  if (!module?.config) return;
  updateModuleStyleColor(module, field, module.config[field]);
};

const syncModuleBackgroundShortcutByModule = (module: ModuleItem | null) => {
  if (!module?.config) return;
  syncModuleBackgroundShortcut(module.config);
};

const updateSelectedRichTextContent = (value: string) => {
  if (editableModule.value?.config) {
    editableModule.value.config.content = value;
  }
};

const getIntervalSeconds = (module: ModuleItem) => {
  const milliseconds = Number(module.config?.interval || 3000);
  return Math.max(1, Math.round(milliseconds / 1000));
};

const updateIntervalSeconds = (module: ModuleItem, value: unknown) => {
  module.config.interval = Math.max(1, Number(value || 3)) * 1000;
};

const updateSelectedIntervalSeconds = (value: unknown) => {
  if (editableModule.value?.config) {
    updateIntervalSeconds(editableModule.value, value);
  }
};

const createLocalId = (prefix: string) =>
  `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

const extractUploadName = (value: string) => {
  const cleanValue = value.split('?')[0] || value;
  const name = decodeURIComponent(cleanValue.split('/').pop() || '');
  return name || '图片';
};

const buildUploadFullUrl = (value: unknown) => {
  if (typeof value !== 'string' || value.length === 0) return '';
  if (/^\d+$/.test(value.trim())) return '';
  if (/^(?:https?:|data:image|blob:)/.test(value)) return value;
  const apiBase = import.meta.env.VITE_GLOB_API_URL || '';
  const normalizedPath = value.startsWith('/') ? value : `/${value}`;
  try {
    return `${new URL(apiBase, window.location.origin).origin}${normalizedPath}`;
  } catch {
    return normalizedPath;
  }
};

const normalizeUploadImageValue = (value: any, previewUrl?: unknown) => {
  if (!value) return undefined;
  if (typeof value === 'number') {
    return normalizeUploadImageValue(String(value), previewUrl);
  }
  if (typeof value === 'string') {
    const fullUrl =
      typeof previewUrl === 'string' && previewUrl
        ? previewUrl
        : buildUploadFullUrl(value);
    return {
      full_url: fullUrl,
      name: extractUploadName(fullUrl || value),
      url: value,
    };
  }
  if (typeof value === 'object') {
    const fullUrl =
      value.full_url ||
      value.fullUrl ||
      value.response?.full_url ||
      value.response?.fullUrl ||
      value.preview_url ||
      value.previewUrl ||
      value.image_full_url ||
      value.imageFullUrl ||
      previewUrl ||
      '';
    const url =
      value.url ||
      value.path ||
      value.image ||
      value.src ||
      value.response?.url ||
      value.asset_id ||
      '';
    if (!url) return undefined;
    return {
      ...value,
      full_url: fullUrl || buildUploadFullUrl(String(url)),
      name:
        value.name ||
        value.original_name ||
        extractUploadName(String(fullUrl || url)),
      url: String(url),
    };
  }
  return undefined;
};

const demoAssetBaseUrl = `${
  new URL(import.meta.env.VITE_GLOB_API_URL || '/', window.location.origin)
    .origin
}/static/demo/`;

const createDemoAssetFile = (url: string, name: string) => ({
  full_url: `${demoAssetBaseUrl}${name}`,
  name,
  url,
});

const defaultBannerImageByIndex = [
  createDemoAssetFile('48', 'decorate-banner-market.png'),
  createDemoAssetFile('49', 'decorate-banner-member.png'),
  createDemoAssetFile('50', 'decorate-banner-home.png'),
];

const legacyDefaultBannerIds = new Set(['6', '7', '8', '41']);
const legacyDefaultNavIds = new Set(['15', '16', '20', '23', '40', '46', '47']);

const defaultNavImageByKey: Record<
  string,
  { full_url: string; name: string; url: string }
> = {
  beauty: createDemoAssetFile('52', 'decorate-nav-beauty.png'),
  food: createDemoAssetFile('55', 'decorate-nav-food.png'),
  home: createDemoAssetFile('54', 'decorate-nav-home.png'),
  phone: createDemoAssetFile('51', 'decorate-nav-digital.png'),
  shirt: createDemoAssetFile('53', 'decorate-nav-fashion.png'),
  sport: createDemoAssetFile('56', 'decorate-nav-sport.png'),
};

const getDefaultNavImageValue = (item: any) => {
  const key = String(item?.icon || item?.key || '').replace(/^lucide:/, '');
  const title = String(item?.title || item?.label || item?.text || '');
  if (key.includes('sparkles') || key.includes('beauty') || title === '美妆') {
    return defaultNavImageByKey.beauty;
  }
  if (
    key.includes('shirt') ||
    key.includes('clothes') ||
    key.includes('menswear') ||
    title === '服饰'
  ) {
    return defaultNavImageByKey.shirt;
  }
  if (
    key.includes('sofa') ||
    key.includes('home') ||
    key.includes('furniture') ||
    title === '家居'
  ) {
    return defaultNavImageByKey.home;
  }
  if (key.includes('utensils') || key.includes('food') || title === '美食') {
    return defaultNavImageByKey.food;
  }
  if (key.includes('dumbbell') || key.includes('sport') || title === '运动') {
    return defaultNavImageByKey.sport;
  }
  if (key.includes('smartphone') || key.includes('phone') || title === '数码') {
    return defaultNavImageByKey.phone;
  }
  return undefined;
};

const getDefaultBannerImageValue = (index: number) =>
  defaultBannerImageByIndex[index % defaultBannerImageByIndex.length];

const defaultProfileOrderImageByIndex = [
  createDemoAssetFile(
    'static/demo/profile-order-pay.svg',
    'profile-order-pay.svg',
  ),
  createDemoAssetFile(
    'static/demo/profile-order-ship.svg',
    'profile-order-ship.svg',
  ),
  createDemoAssetFile(
    'static/demo/profile-order-receive.svg',
    'profile-order-receive.svg',
  ),
  createDemoAssetFile(
    'static/demo/profile-order-refund.svg',
    'profile-order-refund.svg',
  ),
];

const defaultProfileServiceImageByIndex = [
  createDemoAssetFile(
    'static/demo/profile-service-address.svg',
    'profile-service-address.svg',
  ),
  createDemoAssetFile(
    'static/demo/profile-service-favorite.svg',
    'profile-service-favorite.svg',
  ),
  createDemoAssetFile(
    'static/demo/profile-service-settings.svg',
    'profile-service-settings.svg',
  ),
  createDemoAssetFile(
    'static/demo/profile-service-support.svg',
    'profile-service-support.svg',
  ),
];

const getDefaultProfileEntryImageValue = (moduleType: string, index: number) =>
  moduleType === 'orderEntry'
    ? defaultProfileOrderImageByIndex[
        index % defaultProfileOrderImageByIndex.length
      ]
    : defaultProfileServiceImageByIndex[
        index % defaultProfileServiceImageByIndex.length
      ];

const isProfileItemImageRemoved = (item: any) =>
  item?.imageRemoved === true || item?.image_removed === true;

const clearProfileItemImageFields = (item: any) => {
  delete item.image;
  delete item.image_url;
  delete item.imageUrl;
  delete item.icon_image;
  delete item.iconImage;
  delete item.full_url;
  delete item.fullUrl;
  delete item.preview_url;
  delete item.previewUrl;
};

const setProfileItemImageRemoved = (item: any, removed: boolean) => {
  if (removed) {
    item.imageRemoved = true;
    item.image_removed = true;
    clearProfileItemImageFields(item);
    return;
  }
  delete item.imageRemoved;
  delete item.image_removed;
};

const getDefaultBannerItem = (index: number) => ({
  image: getDefaultBannerImageValue(index),
  path:
    index % 2 === 0
      ? '/pages-sub/goods/list?is_recommend=1'
      : '/pages-sub/goods/list?sort=sales',
  title: index % 2 === 0 ? '夏日好物限时满减' : '会员精选 每日上新',
});

const getUploadValueId = (value: any) =>
  value && typeof value === 'object'
    ? String(value.url || value.asset_id || '')
    : String(value || '');

const normalizeBannerItem = (item: any, index: number) => {
  if (typeof item === 'string') {
    const image =
      item.startsWith('data:image/svg') || legacyDefaultBannerIds.has(item)
        ? getDefaultBannerImageValue(index)
        : item;
    return {
      id: createLocalId('banner_item'),
      image: normalizeUploadImageValue(image),
      path: '',
      title: `轮播图${index + 1}`,
    };
  }
  const target = item && typeof item === 'object' ? item : {};
  if (!target.id) target.id = target.key || createLocalId('banner_item');
  const imagePreviewUrl =
    (target.image && typeof target.image === 'object'
      ? target.image.full_url ||
        target.image.fullUrl ||
        target.image.preview_url ||
        target.image.previewUrl ||
        target.image.response?.full_url ||
        target.image.response?.fullUrl
      : '') ||
    target.image_full_url ||
    target.imageFullUrl ||
    target.full_url ||
    target.fullUrl ||
    target.preview_url ||
    target.previewUrl ||
    '';
  if (!target.image) {
    target.image =
      target.image_id ||
      target.imageId ||
      target.image_url ||
      target.imageUrl ||
      imagePreviewUrl ||
      target.src ||
      target.cover ||
      target.url ||
      '';
  }
  if (
    (typeof target.image === 'string' &&
      target.image.startsWith('data:image/svg')) ||
    legacyDefaultBannerIds.has(getUploadValueId(target.image))
  ) {
    target.image = getDefaultBannerImageValue(index);
  }
  target.image = normalizeUploadImageValue(target.image, imagePreviewUrl);
  if (!target.path) {
    target.path =
      target.target_path ||
      target.link ||
      target.href ||
      target.jump_url ||
      target.jumpUrl ||
      '';
  }
  if (!target.title) target.title = target.label || `轮播图${index + 1}`;
  return target;
};

const syncBannerItems = (module: ModuleItem, items: any[]) => {
  const config = (module.config ||= {});
  if (config.items !== items) config.items = items;
  if (config.images !== items) config.images = items;
  if (config.list !== items) config.list = items;
};

const isNormalizedBannerItem = (item: any) =>
  item &&
  typeof item === 'object' &&
  !Array.isArray(item) &&
  item.id &&
  'image' in item &&
  (!item.image || typeof item.image === 'object') &&
  'path' in item &&
  'title' in item;

const isNormalizedBannerItems = (items: any[]) =>
  items.every((item) => isNormalizedBannerItem(item));

const getBannerSource = (config: Record<string, any>) => {
  if (Array.isArray(config.items) && config.items.length > 0) {
    return config.items;
  }
  if (Array.isArray(config.images) && config.images.length > 0) {
    return config.images;
  }
  if (Array.isArray(config.list)) {
    return config.list;
  }
  return Array.isArray(config.items) ? config.items : [];
};

const getBannerItems = (module: ModuleItem) => {
  const config = (module.config ||= {});
  if (Array.isArray(config.items) && isNormalizedBannerItems(config.items)) {
    syncBannerItems(module, config.items);
    return config.items;
  }
  const source = getBannerSource(config);
  const items = source.map((item: any, index: number) =>
    normalizeBannerItem(item, index),
  );
  syncBannerItems(module, items);
  return items;
};

const selectedBannerItems = computed<any[]>(() =>
  editableModule.value?.type === 'banner' && editableModule.value.config
    ? getBannerItems(editableModule.value)
    : [],
);

const normalizeNavGridItem = (item: any, index: number) => {
  const target = item && typeof item === 'object' ? item : {};
  if (!target.id) target.id = target.key || createLocalId('nav_item');
  const defaultImage = getDefaultNavImageValue(target);
  const imagePreviewUrl =
    (target.image && typeof target.image === 'object'
      ? target.image.full_url ||
        target.image.fullUrl ||
        target.image.preview_url ||
        target.image.previewUrl ||
        target.image.response?.full_url ||
        target.image.response?.fullUrl
      : '') ||
    target.image_full_url ||
    target.imageFullUrl ||
    target.full_url ||
    target.fullUrl ||
    target.preview_url ||
    target.previewUrl ||
    '';
  if (
    (typeof target.image === 'string' &&
      target.image.startsWith('data:image/svg')) ||
    legacyDefaultNavIds.has(getUploadValueId(target.image))
  ) {
    target.image = defaultImage || target.image;
  }
  if (!target.image) {
    target.image =
      target.image_id ||
      target.imageId ||
      target.image_url ||
      target.imageUrl ||
      imagePreviewUrl ||
      defaultImage ||
      target.icon ||
      '';
  }
  target.image = normalizeUploadImageValue(target.image, imagePreviewUrl);
  if (!target.title) target.title = target.label || `导航${index + 1}`;
  if (!target.path) {
    target.path =
      target.target_path ||
      target.link ||
      target.href ||
      target.jump_url ||
      target.jumpUrl ||
      '';
  }
  return target;
};

const isNormalizedNavGridItem = (item: any) =>
  item &&
  typeof item === 'object' &&
  !Array.isArray(item) &&
  (!item.image || typeof item.image === 'object') &&
  'path' in item &&
  'title' in item;

const getNavGridItems = (module: ModuleItem) => {
  const config = (module.config ||= {});
  const source = Array.isArray(config.items) ? config.items : [];
  if (source.every((item: any) => isNormalizedNavGridItem(item))) {
    config.items = source;
    return source;
  }
  const items = source.map((item: any, index: number) =>
    normalizeNavGridItem(item, index),
  );
  config.items = items;
  return items;
};

const selectedNavGridItems = computed<any[]>(() =>
  editableModule.value?.type === 'navGrid' && editableModule.value.config
    ? getNavGridItems(editableModule.value)
    : [],
);

const bannerIndex = Number;
const navIndex = Number;

const createBannerItem = (index: number) => ({
  id: createLocalId('banner_item'),
  ...getDefaultBannerItem(index),
});

const addBannerItem = (module: ModuleItem) => {
  const items = getBannerItems(module);
  items.push(createBannerItem(items.length));
  syncBannerItems(module, items);
};

const addSelectedBannerItem = () => {
  if (editableModule.value) addBannerItem(editableModule.value);
};

const removeBannerItem = (module: ModuleItem, index: number) => {
  const items = getBannerItems(module);
  items.splice(index, 1);
  syncBannerItems(module, items);
};

const removeSelectedBannerItem = (index: number) => {
  if (editableModule.value) removeBannerItem(editableModule.value, index);
};

const moveBannerItem = (
  module: ModuleItem,
  index: number,
  direction: 'down' | 'up',
) => {
  const items = getBannerItems(module);
  const nextIndex = direction === 'up' ? index - 1 : index + 1;
  if (nextIndex < 0 || nextIndex >= items.length) return;
  const [item] = items.splice(index, 1);
  if (item) items.splice(nextIndex, 0, item);
  syncBannerItems(module, items);
};

const moveSelectedBannerItem = (index: number, direction: 'down' | 'up') => {
  if (editableModule.value) {
    moveBannerItem(editableModule.value, index, direction);
  }
};

const handleBannerItemDragStart = (index: number, event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  bannerDragIndex.value = index;
  event.dataTransfer?.setData('text/plain', String(index));
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
  }
};

const handleBannerItemDragOver = (event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move';
  }
};

const handleBannerItemDragEnter = (index: number, event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  event.preventDefault();
  bannerDropIndex.value = index;
};

const handleBannerItemDrop = (
  module: ModuleItem,
  targetIndex: number,
  event: DragEvent,
) => {
  if (props.isReadonlyScheme) return;
  event.preventDefault();
  const sourceIndex =
    bannerDragIndex.value ??
    Number(event.dataTransfer?.getData('text/plain') ?? -1);
  if (!Number.isInteger(sourceIndex) || sourceIndex === targetIndex) {
    bannerDragIndex.value = null;
    bannerDropIndex.value = null;
    return;
  }
  const items = getBannerItems(module);
  if (sourceIndex < 0 || sourceIndex >= items.length) {
    bannerDragIndex.value = null;
    bannerDropIndex.value = null;
    return;
  }
  const [item] = items.splice(sourceIndex, 1);
  if (item) {
    const insertIndex =
      sourceIndex < targetIndex ? Math.max(0, targetIndex - 1) : targetIndex;
    items.splice(insertIndex, 0, item);
  }
  syncBannerItems(module, items);
  bannerDragIndex.value = null;
  bannerDropIndex.value = null;
};

const handleSelectedBannerItemDrop = (
  targetIndex: number,
  event: DragEvent,
) => {
  if (editableModule.value) {
    handleBannerItemDrop(editableModule.value, targetIndex, event);
  }
};

const handleBannerItemDragEnd = () => {
  bannerDragIndex.value = null;
  bannerDropIndex.value = null;
};

const syncNavGridItems = (module: ModuleItem, items: any[]) => {
  const config = (module.config ||= {});
  if (config.items !== items) config.items = items;
};

const moveNavGridItem = (
  module: ModuleItem,
  sourceIndex: number,
  targetIndex: number,
) => {
  if (sourceIndex === targetIndex) return;
  const items = getNavGridItems(module);
  if (
    sourceIndex < 0 ||
    sourceIndex >= items.length ||
    targetIndex < 0 ||
    targetIndex >= items.length
  ) {
    return;
  }
  const [item] = items.splice(sourceIndex, 1);
  if (item) {
    items.splice(targetIndex, 0, item);
  }
  syncNavGridItems(module, items);
};

const handleNavItemDragStart = (index: number, event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  navDragIndex.value = index;
  event.dataTransfer?.setData('text/plain', String(index));
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
  }
};

const handleNavItemDragOver = (event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move';
  }
};

const handleNavItemDragEnter = (index: number, event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  event.preventDefault();
  navDropIndex.value = index;
};

const handleSelectedNavItemDrop = (targetIndex: number, event: DragEvent) => {
  if (!editableModule.value || props.isReadonlyScheme) return;
  event.preventDefault();
  const sourceIndex =
    navDragIndex.value ??
    Number(event.dataTransfer?.getData('text/plain') ?? -1);
  moveNavGridItem(editableModule.value, sourceIndex, targetIndex);
  navDragIndex.value = null;
  navDropIndex.value = null;
};

const handleNavItemDragEnd = () => {
  navDragIndex.value = null;
  navDropIndex.value = null;
};

const normalizeCubeItem = (item: any, index: number) => {
  const normalized = normalizeBannerItem(item, index);
  normalized.id =
    normalized.id?.replace?.(/^banner_item/, 'cube_item') ||
    createLocalId('cube_item');
  normalized.title = normalized.title || `图片${index + 1}`;
  if (/^轮播图\d+$/.test(normalized.title)) {
    normalized.title = `图片${index + 1}`;
  }
  return normalized;
};

const syncCubeItems = (module: ModuleItem, items: any[]) => {
  const config = (module.config ||= {});
  if (config.images !== items) config.images = items;
  if (config.items !== items) config.items = items;
  if (config.list !== items) config.list = items;
};

const isNormalizedCubeItem = (item: any) =>
  item &&
  typeof item === 'object' &&
  !Array.isArray(item) &&
  item.id &&
  'image' in item &&
  (!item.image || typeof item.image === 'object') &&
  'path' in item &&
  'title' in item;

const isNormalizedCubeItems = (items: any[]) =>
  items.every((item) => isNormalizedCubeItem(item));

const getCubeSource = (config: Record<string, any>) => {
  if (Array.isArray(config.images) && config.images.length > 0) {
    return config.images;
  }
  if (Array.isArray(config.items) && config.items.length > 0) {
    return config.items;
  }
  if (Array.isArray(config.list)) {
    return config.list;
  }
  return Array.isArray(config.images) ? config.images : [];
};

const getCubeItems = (module: ModuleItem) => {
  const config = (module.config ||= {});
  if (Array.isArray(config.images) && isNormalizedCubeItems(config.images)) {
    syncCubeItems(module, config.images);
    return config.images;
  }
  const source = getCubeSource(config);
  const items = source
    .slice(0, maxCubeEditableItems)
    .map((item: any, index: number) => normalizeCubeItem(item, index));
  syncCubeItems(module, items);
  return items;
};

const selectedCubeItems = computed<any[]>(() =>
  editableModule.value?.type === 'imageCube' && editableModule.value.config
    ? getCubeItems(editableModule.value)
    : [],
);

const normalizeCubeLayout = (value: unknown) =>
  Object.prototype.hasOwnProperty.call(
    cubeLayoutDisplayLimitMap,
    String(value || ''),
  )
    ? String(value)
    : 'four';

const getCubeDisplayLimit = (module: ModuleItem | null) =>
  cubeLayoutDisplayLimitMap[
    normalizeCubeLayout(module?.config?.layout || module?.props?.layout)
  ] ?? 4;

const selectedCubeVisibleCount = computed(
  () =>
    selectedCubeItems.value.filter((item) => getConfigItemVisible(item)).length,
);

const normalizeProfileEntryItem = (
  item: any,
  index: number,
  moduleType: string,
) => {
  const target = item && typeof item === 'object' ? item : {};
  const label =
    target.label ||
    target.title ||
    target.text ||
    (moduleType === 'orderEntry'
      ? `订单入口${index + 1}`
      : `服务入口${index + 1}`);
  if (!target.id) target.id = target.key || createLocalId('profile_item');
  if (target.label !== label) target.label = label;
  if (target.title !== label) target.title = label;
  if (target.action === 'theme' && !target.key) target.key = 'theme';

  const imageRemoved = isProfileItemImageRemoved(target);
  const imageSource = imageRemoved
    ? undefined
    : target.image ||
      target.image_url ||
      target.imageUrl ||
      target.icon_image ||
      target.iconImage ||
      getDefaultProfileEntryImageValue(moduleType, index);
  const shouldNormalizeImage =
    !imageRemoved &&
    (!target.image ||
      typeof target.image === 'string' ||
      typeof target.image === 'number');
  if (shouldNormalizeImage) {
    const normalizedImage =
      normalizeUploadImageValue(imageSource) ||
      getDefaultProfileEntryImageValue(moduleType, index);
    if (target.image !== normalizedImage) target.image = normalizedImage;
  } else if (imageRemoved) {
    clearProfileItemImageFields(target);
    target.imageRemoved = true;
    target.image_removed = true;
  }

  const path =
    target.path || target.url || target.link || target.target_path || '';
  if (target.path !== path) target.path = path;
  if ('icon' in target) delete target.icon;
  if ('action' in target) delete target.action;
  return target;
};

const getProfileEntryItems = (module: ModuleItem) => {
  const config = (module.config ||= {});
  const moduleType = props.normalizeProfileModuleType(
    String(module.type || ''),
  );
  let source: any[] = [];
  if (Array.isArray(config.items)) {
    source = config.items;
  } else if (Array.isArray(config.list)) {
    source = config.list;
  }
  const items = source;
  items.forEach((item: any, index: number) => {
    const normalizedItem = normalizeProfileEntryItem(item, index, moduleType);
    if (items[index] !== normalizedItem) items[index] = normalizedItem;
  });
  if (config.items !== items) config.items = items;
  if (config.list !== items) config.list = items;
  return items;
};

const selectedProfileEntryItems = computed<any[]>(() =>
  editableModule.value?.config && isProfileEntryModule.value
    ? getProfileEntryItems(editableModule.value)
    : [],
);

const moveProfileEntryItem = (
  module: ModuleItem,
  sourceIndex: number,
  targetIndex: number,
) => {
  if (sourceIndex === targetIndex) return;
  const items = getProfileEntryItems(module);
  if (
    sourceIndex < 0 ||
    sourceIndex >= items.length ||
    targetIndex < 0 ||
    targetIndex >= items.length
  ) {
    return;
  }
  const [item] = items.splice(sourceIndex, 1);
  if (item) {
    items.splice(targetIndex, 0, item);
  }
  module.config.items = items;
  module.config.list = items;
};

const handleProfileEntryDragStart = (index: number, event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  profileEntryDragIndex.value = index;
  event.dataTransfer?.setData('text/plain', String(index));
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
  }
};

const handleProfileEntryDragOver = (event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move';
  }
};

const handleProfileEntryDragEnter = (index: number, event: DragEvent) => {
  if (props.isReadonlyScheme) return;
  event.preventDefault();
  profileEntryDropIndex.value = index;
};

const handleProfileEntryDrop = (targetIndex: number, event: DragEvent) => {
  if (!editableModule.value || props.isReadonlyScheme) return;
  event.preventDefault();
  const sourceIndex =
    profileEntryDragIndex.value ??
    Number(event.dataTransfer?.getData('text/plain') ?? -1);
  moveProfileEntryItem(editableModule.value, sourceIndex, targetIndex);
  profileEntryDragIndex.value = null;
  profileEntryDropIndex.value = null;
};

const handleProfileEntryDragEnd = () => {
  profileEntryDragIndex.value = null;
  profileEntryDropIndex.value = null;
};

const updateProfileItemLabel = (item: any, value: string) => {
  item.label = value;
  item.title = value;
};

const updateProfileItemPath = (item: any, value: string) => {
  item.path = value;
};

const updateProfileItemImage = (item: any, value: any) => {
  if (value) {
    item.image = value;
    setProfileItemImageRemoved(item, false);
    return;
  }
  setProfileItemImageRemoved(item, true);
};

const createCubeItem = (index: number, visible = true) => ({
  id: createLocalId('cube_item'),
  enabled: visible,
  image: createDemoAssetFile(
    String(57 + (index % 4)),
    [
      'decorate-cube-new.png',
      'decorate-cube-picks.png',
      'decorate-cube-member.png',
      'decorate-cube-sale.png',
    ][index % 4] || 'decorate-cube-new.png',
  ),
  path:
    [
      '/pages-sub/goods/list?sort=newest',
      '/pages-sub/goods/list?is_recommend=1',
      '/pages-sub/goods/list?sort=sales',
      '/pages-sub/goods/list?is_hot=1',
    ][index % 4] || '/pages-sub/goods/list',
  title:
    ['新品上架', '精选榜单', '会员专享', '限时满减'][index % 4] ||
    `图片${index + 1}`,
  visible,
});

const addSelectedCubeItem = () => {
  const module = editableModule.value;
  if (!module) return;
  const items = getCubeItems(module);
  if (items.length >= maxCubeEditableItems) return;
  const visible = selectedCubeVisibleCount.value < getCubeDisplayLimit(module);
  items.push(createCubeItem(items.length, visible));
  syncCubeItems(module, items);
};

const removeSelectedCubeItem = (index: number) => {
  if (!editableModule.value) return;
  const items = getCubeItems(editableModule.value);
  items.splice(index, 1);
  syncCubeItems(editableModule.value, items);
};

const getColorInputValue = (value: unknown) => {
  const color = typeof value === 'string' ? value.trim() : '';
  if (/^#[\da-f]{6}$/i.test(color)) return color;
  const shortColor = color.match(/^#([\da-f])([\da-f])([\da-f])$/i);
  if (shortColor) {
    return `#${shortColor[1]}${shortColor[1]}${shortColor[2]}${shortColor[2]}${shortColor[3]}${shortColor[3]}`;
  }
  return '#ffffff';
};

const getProfileEntryLabel = (item: any) => {
  const label = item?.label || item?.title || item?.text || item?.name || '';
  return String(label || '').trim() || '入口';
};

const getProfileEntryItemsForText = (module: ModuleItem | null) => {
  const config = module?.config || {};
  if (Array.isArray(config.items)) return config.items;
  if (Array.isArray(config.list)) return config.list;
  return [];
};

const getHomeEntryItemsForText = (module: ModuleItem | null) => {
  const config = module?.config || {};
  if (Array.isArray(config.items)) return config.items;
  if (Array.isArray(config.list)) return config.list;
  if (Array.isArray(config.images)) return config.images;
  return [];
};

const getHomeEntryLabel = (item: any) => {
  if (typeof item === 'string') return item;
  const label = item?.label || item?.title || item?.text || item?.name || '';
  return String(label || '').trim() || '内容项';
};

const joinPreviewTexts = (items: string[], fallback = '当前未显示') => {
  const texts = items.map((item) => item.trim()).filter(Boolean);
  if (texts.length === 0) return fallback;
  return texts.slice(0, 3).join(' / ');
};

const profileTextStyleTargetText = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
) => {
  const config = module?.config || {};
  const type = module ? editableTextStyleType.value : '';
  if (props.activeType === 'home') {
    const items = getHomeEntryItemsForText(module);
    if (type === 'search') {
      return role === 'placeholder'
        ? String(config.placeholder || '搜索商品、分类或品牌')
        : '当前未显示';
    }
    if (type === 'navGrid') {
      const targets: Partial<Record<ProfileTextStyleRole, string>> = {
        itemLabel: joinPreviewTexts(
          items.map((item: any) => getHomeEntryLabel(item)),
        ),
      };
      return targets[role] || '当前未显示';
    }
    if (type === 'entryCard') {
      const targets: Partial<Record<ProfileTextStyleRole, string>> = {
        subtitle: String(config.subtitle || config.path || '点击查看'),
        title: String(config.title || '入口卡片'),
      };
      return targets[role] || '当前未显示';
    }
    if (type === 'imageCube') {
      return role === 'itemLabel'
        ? joinPreviewTexts(items.map((item: any) => getHomeEntryLabel(item)))
        : '当前未显示';
    }
    if (type === 'productGroup') {
      const targets: Partial<Record<ProfileTextStyleRole, string>> = {
        more: String(config.moreText || config.more_text || '查看全部'),
        subtitle: String(config.subtitle || '精选好物实时更新'),
        title: String(config.title || '精选好物'),
      };
      return targets[role] || '当前未显示';
    }
    if (type === 'title') {
      const targets: Partial<Record<ProfileTextStyleRole, string>> = {
        more: String(config.more_text || config.moreText || '查看全部'),
        subtitle: String(config.sub_title || config.subtitle || '当前未显示'),
        title: String(config.title || config.text || '标题'),
      };
      return targets[role] || '当前未显示';
    }
  }
  const items = getProfileEntryItemsForText(module);
  if (type === 'userInfo') {
    const targets: Partial<Record<ProfileTextStyleRole, string>> = {
      action: '资料编辑',
      amount: '当前未显示',
      iconText: '当前未显示',
      itemLabel: '当前未显示',
      meta: '手机号 / 个性签名',
      more: '当前未显示',
      primaryAction: '当前未显示',
      subtitle: '登录后享受更多服务',
      title: '点击登录 / MallBase 用户',
    };
    return targets[role] || '当前未显示';
  }
  if (type === 'walletEntry') {
    const targets: Partial<Record<ProfileTextStyleRole, string>> = {
      action:
        config.show_balance !== false && config.show_records !== false
          ? '余额明细'
          : '当前未显示',
      amount: config.show_balance === false ? '当前未显示' : '¥0.00',
      iconText: '当前未显示',
      itemLabel: '当前未显示',
      meta:
        config.show_balance === false ? '当前未显示' : '累计充值 / 累计消费',
      more: '当前未显示',
      primaryAction:
        config.show_view_button === false ? '当前未显示' : '去查看',
      subtitle: '当前未显示',
      title: String(config.title || '我的余额'),
    };
    return targets[role] || '当前未显示';
  }
  if (type === 'orderEntry') {
    const targets: Partial<Record<ProfileTextStyleRole, string>> = {
      action: '当前未显示',
      amount: '当前未显示',
      iconText: '当前未显示',
      itemLabel: joinPreviewTexts(
        items.map((item: any) => getProfileEntryLabel(item)),
      ),
      meta: '当前未显示',
      more: '查看全部',
      primaryAction: '当前未显示',
      subtitle: '当前未显示',
      title: String(config.title || '我的订单'),
    };
    return targets[role] || '当前未显示';
  }
  if (['customMenu', 'serviceMenu'].includes(type)) {
    const targets: Partial<Record<ProfileTextStyleRole, string>> = {
      action: '当前未显示',
      amount: '当前未显示',
      iconText: '当前未显示',
      itemLabel: joinPreviewTexts(
        items.map((item: any) => getProfileEntryLabel(item)),
      ),
      meta: '当前未显示',
      more: '当前未显示',
      primaryAction: '当前未显示',
      subtitle: '当前未显示',
      title: String(
        config.title || (type === 'customMenu' ? '自定义菜单' : '我的服务'),
      ),
    };
    return targets[role] || '当前未显示';
  }
  return '';
};

const getProfileTextStyleDefault = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
) => {
  const type = module ? editableTextStyleType.value : '';
  const defaults = {
    ...profileTextStyleDefaults[type]?.[role],
  } as Partial<Record<ProfileTextStyleField, any>>;
  if (
    ['customMenu', 'serviceMenu'].includes(type) &&
    role === 'itemLabel' &&
    module?.config?.display === 'grid'
  ) {
    defaults.color = '#434654';
    defaults.fontSize = 24;
    defaults.fontWeight = '400';
    defaults.textAlign = 'center';
  }
  return defaults;
};

const getProfileTextStyle = (
  config: Record<string, any>,
  role: ProfileTextStyleRole,
) => {
  const styles = config.textStyles || config.text_styles;
  const style = styles?.[role];
  return style && typeof style === 'object' ? style : {};
};

const getProfileTextStyleValue = (
  config: Record<string, any>,
  role: ProfileTextStyleRole,
  field: ProfileTextStyleField,
) => {
  const style = getProfileTextStyle(config, role);
  const aliasMap: Record<ProfileTextStyleField, string> = {
    backgroundColorEnd: 'background_color_end',
    backgroundColorStart: 'background_color_start',
    backgroundGradientDirection: 'background_gradient_direction',
    backgroundHeight: 'background_height',
    backgroundImage: 'background_image',
    backgroundMode: 'background_mode',
    backgroundPosition: 'background_position',
    backgroundRadius: 'background_radius',
    backgroundWidth: 'background_width',
    color: 'color',
    fontSize: 'font_size',
    fontStyle: 'font_style',
    fontWeight: 'font_weight',
    textAlign: 'text_align',
  };
  const value = style[field] ?? style[aliasMap[field]];
  return value === '' || value === null ? undefined : value;
};

const getProfileTextStyleDisplayValue = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
  field: ProfileTextStyleField,
) =>
  getProfileTextStyleValue(module?.config || {}, role, field) ??
  getProfileTextStyleDefault(module, role)[field];

const getProfileTextStyleColorInputValue = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
) => getColorInputValue(getProfileTextStyleDisplayValue(module, role, 'color'));

const getProfileTextStyleFieldColorInputValue = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
  field: ProfileTextStyleField,
) => getColorInputValue(getProfileTextStyleDisplayValue(module, role, field));

const ensureProfileTextStyle = (
  config: Record<string, any>,
  role: ProfileTextStyleRole,
) => {
  if (!config.textStyles || typeof config.textStyles !== 'object') {
    config.textStyles =
      config.text_styles && typeof config.text_styles === 'object'
        ? { ...config.text_styles }
        : {};
  }
  if (!config.textStyles[role] || typeof config.textStyles[role] !== 'object') {
    config.textStyles[role] = {};
  }
  return config.textStyles[role];
};

const normalizeProfileTextWeight = (value: unknown) => {
  const weight = String(value || '');
  return profileTextWeightOptions.some((item) => item.value === weight)
    ? weight
    : '';
};

const isProfileTextDefaultValue = (
  module: ModuleItem,
  role: ProfileTextStyleRole,
  field: ProfileTextStyleField,
  value: unknown,
) => {
  const defaultValue = getProfileTextStyleDefault(module, role)[field];
  if (defaultValue === undefined || defaultValue === null) return false;
  if (
    field === 'color' ||
    field === 'backgroundColorStart' ||
    field === 'backgroundColorEnd'
  ) {
    return (
      String(value || '').toLowerCase() ===
      String(defaultValue || '').toLowerCase()
    );
  }
  return String(value || '') === String(defaultValue || '');
};

const updateProfileTextStyleField = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
  field: ProfileTextStyleField,
  value: unknown,
) => {
  if (!module?.config) return;
  const style = ensureProfileTextStyle(module.config, role);
  if (
    field === 'fontSize' ||
    field === 'backgroundHeight' ||
    field === 'backgroundRadius' ||
    field === 'backgroundWidth'
  ) {
    const hasNumberValue =
      value !== '' && value !== null && value !== undefined;
    const numberValue = Number(value);
    if (
      hasNumberValue &&
      Number.isFinite(numberValue) &&
      (field === 'backgroundRadius' ? numberValue >= 0 : numberValue > 0)
    ) {
      let limits = { max: 80, min: 16 };
      switch (field) {
        case 'backgroundHeight': {
          limits = { max: 100, min: 10 };

          break;
        }
        case 'backgroundRadius': {
          limits = { max: 80, min: 0 };

          break;
        }
        case 'backgroundWidth': {
          limits = { max: 100, min: 20 };

          break;
        }
        // No default
      }
      const numberStyleValue = Math.max(
        limits.min,
        Math.min(Math.round(numberValue), limits.max),
      );
      if (isProfileTextDefaultValue(module, role, field, numberStyleValue)) {
        delete style[field];
      } else {
        style[field] = numberStyleValue;
      }
    } else {
      delete style[field];
    }
    return;
  }
  if (field === 'backgroundPosition') {
    const position = normalizeTextBackgroundPosition(value);
    if (position && !isProfileTextDefaultValue(module, role, field, position)) {
      style.backgroundPosition = position;
    } else {
      delete style.backgroundPosition;
    }
    return;
  }
  if (field === 'backgroundMode') {
    const mode = normalizeTextBackgroundMode(value);
    if (mode && !isProfileTextDefaultValue(module, role, field, mode)) {
      style.backgroundMode = mode;
    } else {
      delete style.backgroundMode;
    }
    return;
  }
  if (field === 'backgroundGradientDirection') {
    const direction = normalizeTextGradientDirection(value);
    if (
      direction &&
      !isProfileTextDefaultValue(module, role, field, direction)
    ) {
      style.backgroundGradientDirection = direction;
    } else {
      delete style.backgroundGradientDirection;
    }
    return;
  }
  if (field === 'backgroundImage') {
    if (value) {
      style.backgroundImage = value;
    } else {
      delete style.backgroundImage;
    }
    return;
  }
  if (field === 'fontWeight') {
    const weight = normalizeProfileTextWeight(value);
    if (weight && !isProfileTextDefaultValue(module, role, field, weight)) {
      style.fontWeight = weight;
    } else {
      delete style.fontWeight;
    }
    return;
  }
  if (field === 'fontStyle') {
    const fontStyle = value === 'italic' ? 'italic' : '';
    if (
      fontStyle &&
      !isProfileTextDefaultValue(module, role, field, fontStyle)
    ) {
      style.fontStyle = fontStyle;
    } else {
      delete style.fontStyle;
    }
    return;
  }
  if (field === 'textAlign') {
    const align = normalizeProfileTextAlign(value);
    if (align && !isProfileTextDefaultValue(module, role, field, align)) {
      style.textAlign = align;
    } else {
      delete style.textAlign;
    }
    return;
  }
  const color = typeof value === 'string' ? value.trim() : '';
  if (color && !isProfileTextDefaultValue(module, role, field, color)) {
    style[field] = color;
  } else {
    delete style[field];
  }
};

const updateProfileTextStyleColorFromEvent = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
  event: Event,
) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  updateProfileTextStyleField(module, role, 'color', value);
};

const updateProfileTextStyleFieldColorFromEvent = (
  module: ModuleItem | null,
  role: ProfileTextStyleRole,
  field: ProfileTextStyleField,
  event: Event,
) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  updateProfileTextStyleField(module, role, field, value);
};

const resetProfileTextStyles = (module: ModuleItem | null) => {
  if (!module?.config) return;
  delete module.config.textStyles;
  delete module.config.text_styles;
  delete module.config.textVisibility;
  delete module.config.text_visibility;
};

const getConfigItemVisible = (item: any) =>
  item?.enabled !== false && item?.visible !== false;

const updateConfigItemVisible = (item: any, checked: boolean) => {
  if (!item || typeof item !== 'object') return;
  item.enabled = checked;
  item.visible = checked;
};

const getProfileItemVisible = getConfigItemVisible;
const updateProfileItemVisible = updateConfigItemVisible;
</script>

<template>
  <div
    class="decorate-editor"
    :class="{
      'decorate-editor--profile': activeType === 'profile',
      'decorate-editor--tabbar': activeType === 'tabbar',
    }"
  >
    <aside v-if="activeType !== 'tabbar'" class="component-library">
      <a-card size="small" title="组件库">
        <template #extra>
          <a-tag>
            {{
              paletteGroups.reduce(
                (total, group) => total + group.items.length,
                0,
              )
            }}
            个组件
          </a-tag>
        </template>
        <div class="palette-sections">
          <section v-for="group in paletteGroups" :key="group.title">
            <div class="palette-section-title">{{ group.title }}</div>
            <div class="palette-grid">
              <button
                v-for="item in group.items"
                :key="item.type"
                class="palette-item"
                :class="[{ locked: isReadonlyScheme }]"
                :disabled="isReadonlyScheme"
                :title="item.desc"
                type="button"
                @click="$emit('paletteClick', item.type)"
                @mousedown="$emit('paletteMouseDown', item, $event)"
              >
                <span class="palette-icon">
                  <IconifyIcon :icon="item.icon" />
                </span>
                <span class="palette-name">{{ item.label }}</span>
              </button>
            </div>
          </section>
        </div>
      </a-card>
    </aside>

    <main class="preview-canvas-panel">
      <div class="panel-title">
        <div>
          <strong>{{ activeTypeLabel }}画布</strong>
          <span class="panel-title__desc">
            手机预览就是编辑画布，组件可直接拖入和选中配置。
          </span>
        </div>
        <a-space class="panel-title__actions">
          <a-button
            v-if="activeType === 'tabbar'"
            :disabled="isReadonlyScheme || schemeForm.schema.length >= 5"
            class="panel-title__add"
            size="small"
            type="primary"
            @click="$emit('paletteClick', 'tabbarItem')"
          >
            添加导航项
          </a-button>
          <a-tag>{{ schemeForm.schema.length }} 个模块</a-tag>
          <a-tag v-if="isReadonlyScheme">只读</a-tag>
        </a-space>
      </div>

      <div
        class="phone-canvas"
        :class="{ 'phone-canvas--tabbar': activeType === 'tabbar' }"
        data-module-list="true"
      >
        <ClientPhonePreview
          :category-tree="previewCategoryTree"
          :current-path="previewCurrentPath"
          :dragging="dragActive"
          :drop-index="dragDropIndex"
          :goods="previewGoods"
          :goods-list="previewGoodsList"
          interactive
          :kind="previewKind"
          :modules="schemeForm.schema"
          :page-style="schemeForm.pageStyle"
          :selected-module-id="selectedModuleId"
          :tabbar-items="tabbarPreviewItems"
          :theme-tokens="currentThemeTokens"
          :title="activeTypeLabel"
          @module-delete="$emit('moduleDelete', $event)"
          @module-mouse-down="
            (index, event) => $emit('moduleMouseDown', index, event)
          "
          @module-move="
            (index, direction) => $emit('moduleMove', index, direction)
          "
          @select-module="$emit('selectModule', $event)"
        />
      </div>

      <a-empty
        v-if="schemeForm.schema.length === 0"
        class="canvas-empty"
        :description="
          activeType === 'tabbar'
            ? '点击添加导航项配置底部导航'
            : '从左侧组件库拖入第一个组件'
        "
      />
    </main>

    <aside class="property-panel">
      <a-card size="small" title="属性配置">
        <a-form
          v-if="activeType === 'home' || activeType === 'profile'"
          :disabled="isReadonlyScheme"
          :label-col="{ style: { width: '92px' } }"
          class="property-form"
        >
          <div class="property-section">
            <div class="property-section__head">
              <div class="property-section__title">页面样式</div>
              <a-button
                :disabled="isReadonlyScheme"
                size="small"
                type="link"
                @click="$emit('resetPageStyle')"
              >
                重置
              </a-button>
            </div>
            <div class="profile-style-settings">
              <div class="style-control-row">
                <div class="style-control-row__label">背景设置</div>
                <div class="style-control-row__body">
                  <a-radio-group
                    :value="schemeForm.pageStyle.backgroundMode"
                    :options="backgroundModeOptions"
                    @update:value="
                      (value: unknown) =>
                        updatePageStyleField('backgroundMode', value)
                    "
                  />
                </div>
              </div>

              <template v-if="schemeForm.pageStyle.backgroundMode !== 'image'">
                <div class="style-control-row">
                  <div class="style-control-row__label">背景颜色</div>
                  <div class="style-control-row__body">
                    <div class="style-color-stack">
                      <div
                        v-for="field in [
                          'backgroundColorStart',
                          'backgroundColorEnd',
                        ]"
                        :key="field"
                        class="style-color-field style-color-field--no-action"
                      >
                        <input
                          :aria-label="`选择页面${field}颜色`"
                          class="style-color-field__picker"
                          type="color"
                          :value="getPageStyleColorInputValue(field)"
                          @input="
                            (event: Event) =>
                              updatePageStyleColorFromEvent(field, event)
                          "
                        />
                        <a-input
                          :value="schemeForm.pageStyle[field]"
                          allow-clear
                          class="style-color-field__input"
                          placeholder="跟随主题背景"
                          @change="() => updatePageStyleColorByField(field)"
                          @update:value="
                            (value: string) =>
                              $emit('updatePageStyle', field, value)
                          "
                        />
                      </div>
                    </div>
                  </div>
                </div>

                <div class="style-control-row">
                  <div class="style-control-row__label">渐变方向</div>
                  <div class="style-control-row__body">
                    <a-radio-group
                      :value="schemeForm.pageStyle.backgroundGradientDirection"
                      :options="gradientDirectionOptions"
                      @change="syncPageBackgroundShortcut"
                      @update:value="
                        (value: unknown) =>
                          updatePageStyleField(
                            'backgroundGradientDirection',
                            value,
                          )
                      "
                    />
                  </div>
                </div>
              </template>

              <div v-else class="style-control-row">
                <div class="style-control-row__label">背景图片</div>
                <div class="style-control-row__body">
                  <Upload
                    :value="schemeForm.pageStyle.background_image"
                    :disabled="isReadonlyScheme"
                    module="client"
                    type="image"
                    @update:value="
                      (value: unknown) =>
                        updatePageStyleField('background_image', value)
                    "
                  />
                </div>
              </div>

              <div class="style-control-row style-control-row--spacing">
                <div class="style-control-row__label">页面内边距</div>
                <div class="style-control-row__body">
                  <SpacingControl
                    :disabled="isReadonlyScheme"
                    :get-side-value="getPagePaddingSide"
                    :overall-value="getPagePaddingOverall()"
                    :side-fields="paddingSideFields"
                    @update:all="updatePagePaddingAll"
                    @update:side="updatePagePaddingSide"
                  />
                </div>
              </div>
            </div>
          </div>
        </a-form>

        <a-empty
          v-if="!editableModule"
          description="选择画布中的模块后配置组件"
        />

        <a-form
          v-else
          :disabled="isReadonlyScheme"
          :label-col="{ style: { width: '92px' } }"
          class="property-form"
        >
          <template v-if="activeType !== 'tabbar'">
            <div class="property-section">
              <div class="property-section__title">模块设置</div>
              <a-form-item label="模块名称">
                <a-input v-model:value="editableModule.title" allow-clear />
              </a-form-item>
              <a-form-item label="显示状态">
                <a-switch v-model:checked="editableModule.enabled" />
              </a-form-item>
            </div>
            <template v-if="editableModule.config">
              <ModuleStylePanel
                v-if="activeType === 'home' || activeType === 'profile'"
                :background-mode-options="backgroundModeOptions"
                :border-style-options="borderStyleOptions"
                :disabled="isReadonlyScheme"
                :get-profile-style-color-input-value="
                  getProfileStyleColorInputValue
                "
                :gradient-direction-options="gradientDirectionOptions"
                :module="editableModule"
                :sync-module-background-shortcut-by-module="
                  syncModuleBackgroundShortcutByModule
                "
                :update-module-style-color-by-field="
                  updateModuleStyleColorByField
                "
                :update-module-style-color-from-event="
                  updateModuleStyleColorFromEvent
                "
                :visibility-options="visibilityOptions"
                @reset="$emit('resetModuleStyle', $event)"
              />
            </template>
          </template>

          <template v-if="activeType === 'tabbar'">
            <div class="property-section">
              <div class="property-section__title">导航设置</div>
              <a-form-item label="导航名称">
                <a-input
                  v-model:value="editableModule.text"
                  placeholder="如：首页"
                />
              </a-form-item>
              <a-form-item label="页面路径">
                <PageLinkPicker
                  v-model:value="editableModule.path"
                  :disabled="isReadonlyScheme"
                  placeholder="从页面库选择"
                />
              </a-form-item>
              <a-form-item label="默认图标">
                <Upload
                  v-model:value="editableModule.icon"
                  :disabled="isReadonlyScheme"
                  module="client"
                  type="image"
                />
              </a-form-item>
              <a-form-item label="选中图标">
                <Upload
                  v-model:value="editableModule.selected_icon"
                  :disabled="isReadonlyScheme"
                  module="client"
                  type="image"
                />
              </a-form-item>
            </div>
          </template>

          <div
            v-if="activeType === 'home' && editableModule.config"
            class="property-section"
          >
            <div class="property-section__head">
              <div class="property-section__title">组件内容</div>
              <a-button
                :disabled="isReadonlyScheme"
                size="small"
                type="link"
                @click="$emit('resetModuleContent', editableModule)"
              >
                重置
              </a-button>
            </div>
            <template v-if="editableModule.type === 'search'">
              <a-form-item label="占位文案">
                <a-input v-model:value="editableModule.config.placeholder" />
              </a-form-item>
              <a-form-item label="跳转页面">
                <TargetPicker
                  v-model:value="editableModule.config.target_path"
                  :disabled="isReadonlyScheme"
                  placeholder="输入链接或选择跳转目标"
                />
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'banner'">
              <a-form-item label="轮播项">
                <div class="banner-item-list">
                  <div
                    v-for="(item, itemIndex) in selectedBannerItems"
                    :key="item.id || itemIndex"
                    class="banner-item-row"
                    :class="{
                      'is-dragging': bannerDragIndex === bannerIndex(itemIndex),
                      'is-drop-target':
                        bannerDropIndex === bannerIndex(itemIndex) &&
                        bannerDragIndex !== bannerIndex(itemIndex),
                    }"
                    @dragenter="
                      handleBannerItemDragEnter(bannerIndex(itemIndex), $event)
                    "
                    @dragover="handleBannerItemDragOver"
                    @drop="
                      handleSelectedBannerItemDrop(
                        bannerIndex(itemIndex),
                        $event,
                      )
                    "
                  >
                    <div class="banner-item-row__head">
                      <div class="banner-item-title">
                        <button
                          class="banner-item-drag"
                          :disabled="isReadonlyScheme"
                          draggable="true"
                          title="拖动排序"
                          type="button"
                          @dragend="handleBannerItemDragEnd"
                          @dragstart="
                            handleBannerItemDragStart(
                              bannerIndex(itemIndex),
                              $event,
                            )
                          "
                        >
                          <IconifyIcon icon="lucide:grip-vertical" />
                        </button>
                        <strong>轮播图 {{ bannerIndex(itemIndex) + 1 }}</strong>
                      </div>
                      <a-space>
                        <span class="entry-row__visibility">
                          <span>显示</span>
                          <a-switch
                            :checked="getConfigItemVisible(item)"
                            :disabled="isReadonlyScheme"
                            size="small"
                            @change="
                              (checked: boolean) =>
                                updateConfigItemVisible(item, checked)
                            "
                          />
                        </span>
                        <a-button
                          :disabled="bannerIndex(itemIndex) === 0"
                          size="small"
                          @click="
                            moveSelectedBannerItem(bannerIndex(itemIndex), 'up')
                          "
                        >
                          上移
                        </a-button>
                        <a-button
                          :disabled="
                            bannerIndex(itemIndex) ===
                            selectedBannerItems.length - 1
                          "
                          size="small"
                          @click="
                            moveSelectedBannerItem(
                              bannerIndex(itemIndex),
                              'down',
                            )
                          "
                        >
                          下移
                        </a-button>
                        <a-button
                          danger
                          size="small"
                          @click="
                            removeSelectedBannerItem(bannerIndex(itemIndex))
                          "
                        >
                          删除
                        </a-button>
                      </a-space>
                    </div>
                    <div class="banner-item-row__body">
                      <div class="banner-item-row__image">
                        <div class="banner-item-label">图片</div>
                        <Upload
                          v-model:value="item.image"
                          :disabled="isReadonlyScheme"
                          module="client"
                          type="image"
                        />
                      </div>
                      <div class="banner-item-row__link">
                        <div class="banner-item-label">链接</div>
                        <TargetPicker
                          v-model:value="item.path"
                          :disabled="isReadonlyScheme"
                          placeholder="选择点击跳转目标"
                        />
                      </div>
                    </div>
                  </div>
                  <a-button
                    :disabled="isReadonlyScheme"
                    size="small"
                    type="dashed"
                    @click="addSelectedBannerItem"
                  >
                    添加轮播图
                  </a-button>
                </div>
              </a-form-item>
              <a-form-item label="高度">
                <a-input-number
                  v-model:value="editableModule.config.height"
                  :min="80"
                  :max="500"
                  addon-after="rpx"
                  class="w-full"
                />
              </a-form-item>
              <a-form-item label="轮播间隔">
                <a-input-number
                  :value="getIntervalSeconds(editableModule)"
                  :min="1"
                  :max="10"
                  addon-after="秒"
                  class="w-full"
                  @change="updateSelectedIntervalSeconds"
                />
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'navGrid'">
              <a-form-item label="每行数量">
                <a-input-number
                  v-model:value="editableModule.config.columns"
                  :min="3"
                  :max="6"
                  class="w-full"
                />
              </a-form-item>
              <a-form-item class="property-form-item--full" label="导航项">
                <div class="entry-list">
                  <div
                    v-for="(item, itemIndex) in selectedNavGridItems"
                    :key="item.id || itemIndex"
                    class="entry-row entry-row--nav"
                    :class="{
                      'is-dragging': navDragIndex === navIndex(itemIndex),
                      'is-drop-target':
                        navDropIndex === navIndex(itemIndex) &&
                        navDragIndex !== navIndex(itemIndex),
                    }"
                    @dragenter="
                      handleNavItemDragEnter(navIndex(itemIndex), $event)
                    "
                    @dragover="handleNavItemDragOver"
                    @drop="
                      handleSelectedNavItemDrop(navIndex(itemIndex), $event)
                    "
                  >
                    <button
                      class="banner-item-drag entry-row__drag"
                      :disabled="isReadonlyScheme"
                      draggable="true"
                      title="拖动排序"
                      type="button"
                      @dragend="handleNavItemDragEnd"
                      @dragstart="
                        handleNavItemDragStart(navIndex(itemIndex), $event)
                      "
                    >
                      <IconifyIcon icon="lucide:grip-vertical" />
                    </button>
                    <div class="entry-row__image">
                      <Upload
                        v-model:value="item.image"
                        :disabled="isReadonlyScheme"
                        :max-count="1"
                        mode="both"
                        module="client_decorate"
                        type="image"
                      />
                    </div>
                    <a-input
                      v-model:value="item.title"
                      class="entry-row__title"
                      placeholder="标题"
                    />
                    <TargetPicker
                      v-model:value="item.path"
                      class="entry-row__target"
                      :disabled="isReadonlyScheme"
                      placeholder="跳转目标"
                    />
                    <div class="entry-row__actions">
                      <div class="entry-row__visibility">
                        <span>显示</span>
                        <a-switch
                          :checked="getConfigItemVisible(item)"
                          :disabled="isReadonlyScheme"
                          size="small"
                          @change="
                            (checked: boolean) =>
                              updateConfigItemVisible(item, checked)
                          "
                        />
                      </div>
                      <a-button
                        danger
                        size="small"
                        @click="
                          $emit(
                            'removeConfigItem',
                            editableModule.config.items,
                            itemIndex,
                          )
                        "
                      >
                        删除
                      </a-button>
                    </div>
                  </div>
                  <a-button
                    size="small"
                    @click="$emit('addNavItem', editableModule)"
                  >
                    添加导航项
                  </a-button>
                </div>
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'imageCube'">
              <a-form-item label="布局">
                <a-select v-model:value="editableModule.config.layout">
                  <a-select-option value="one">单图</a-select-option>
                  <a-select-option value="two">双图</a-select-option>
                  <a-select-option value="four">四宫格</a-select-option>
                </a-select>
              </a-form-item>
              <a-form-item label="图片">
                <div class="banner-item-list">
                  <div
                    v-for="(item, itemIndex) in selectedCubeItems"
                    :key="item.id || itemIndex"
                    class="banner-item-row"
                  >
                    <div class="banner-item-row__head">
                      <div class="banner-item-title">
                        <strong>图片 {{ itemIndex + 1 }}</strong>
                      </div>
                      <a-space>
                        <span class="entry-row__visibility">
                          <span>显示</span>
                          <a-switch
                            :checked="getConfigItemVisible(item)"
                            :disabled="isReadonlyScheme"
                            size="small"
                            @change="
                              (checked: boolean) =>
                                updateConfigItemVisible(item, checked)
                            "
                          />
                        </span>
                        <a-button
                          danger
                          size="small"
                          @click="removeSelectedCubeItem(itemIndex)"
                        >
                          删除
                        </a-button>
                      </a-space>
                    </div>
                    <div class="banner-item-row__body">
                      <div class="banner-item-row__image">
                        <div class="banner-item-label">图片</div>
                        <Upload
                          v-model:value="item.image"
                          :disabled="isReadonlyScheme"
                          module="client"
                          type="image"
                        />
                      </div>
                      <div>
                        <div class="banner-item-label">标题</div>
                        <a-input
                          v-model:value="item.title"
                          allow-clear
                          placeholder="图片标题"
                        />
                        <div class="banner-item-label mt-3">链接</div>
                        <TargetPicker
                          v-model:value="item.path"
                          :disabled="isReadonlyScheme"
                          placeholder="选择点击跳转目标"
                        />
                      </div>
                    </div>
                  </div>
                  <a-button
                    :disabled="
                      isReadonlyScheme ||
                      selectedCubeItems.length >= maxCubeEditableItems
                    "
                    size="small"
                    type="dashed"
                    @click="addSelectedCubeItem"
                  >
                    添加图片
                  </a-button>
                </div>
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'productGroup'">
              <a-form-item label="标题">
                <a-input v-model:value="editableModule.config.title" />
              </a-form-item>
              <a-form-item label="商品来源">
                <ProductSourcePicker
                  v-model:brand-id="editableModule.config.brand_id"
                  v-model:category-id="editableModule.config.category_id"
                  v-model:ids="editableModule.config.ids"
                  v-model:preview-goods="editableModule.config.preview_goods"
                  v-model:source="editableModule.config.source"
                  v-model:tag-ids="editableModule.config.tag_ids"
                  :disabled="isReadonlyScheme"
                  :source-options="productSourceOptions"
                />
              </a-form-item>
              <a-form-item label="展示样式">
                <a-select
                  v-model:value="editableModule.config.layout"
                  :options="productLayoutOptions"
                />
              </a-form-item>
              <a-form-item label="排序">
                <a-select
                  v-model:value="editableModule.config.sort_by"
                  :options="productSortOptions"
                />
              </a-form-item>
              <a-form-item label="展示数量">
                <a-input-number
                  v-model:value="editableModule.config.limit"
                  :min="1"
                  :max="50"
                  class="w-full"
                />
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'entryCard'">
              <a-form-item label="标题">
                <a-input
                  v-model:value="editableModule.config.title"
                  placeholder="如：热门分类"
                />
              </a-form-item>
              <a-form-item label="副标题">
                <a-input
                  v-model:value="editableModule.config.subtitle"
                  allow-clear
                  placeholder="如：查看全部商品分类"
                />
              </a-form-item>
              <a-form-item label="跳转页面">
                <TargetPicker
                  v-model:value="editableModule.config.path"
                  :disabled="isReadonlyScheme"
                  placeholder="输入链接或选择跳转目标"
                />
              </a-form-item>
              <a-form-item label="图标图片">
                <Upload
                  v-model:value="editableModule.config.icon_image"
                  :disabled="isReadonlyScheme"
                  module="client"
                  type="image"
                />
              </a-form-item>
              <a-form-item label="背景图">
                <Upload
                  v-model:value="editableModule.config.background_image"
                  :disabled="isReadonlyScheme"
                  module="client"
                  type="image"
                />
              </a-form-item>
              <a-form-item label="显示箭头">
                <a-switch v-model:checked="editableModule.config.show_arrow" />
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'title'">
              <a-form-item label="主标题">
                <a-input v-model:value="editableModule.config.title" />
              </a-form-item>
              <a-form-item label="副标题">
                <a-input v-model:value="editableModule.config.sub_title" />
              </a-form-item>
              <div class="property-subsection__title">更多链接</div>
              <a-form-item label="更多文字">
                <a-input
                  v-model:value="editableModule.config.more_text"
                  allow-clear
                  placeholder="如：查看全部"
                />
              </a-form-item>
              <a-form-item label="更多页面">
                <TargetPicker
                  v-model:value="editableModule.config.more_path"
                  :disabled="isReadonlyScheme"
                  placeholder="输入链接或选择跳转目标"
                />
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'richText'">
              <a-form-item label="内容">
                <RichTextEditor
                  :disabled="isReadonlyScheme"
                  :height="220"
                  :model-value="editableModule.config.content"
                  module="client"
                  placeholder="请输入图文内容"
                  @update:model-value="updateSelectedRichTextContent"
                />
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'spacing'">
              <a-form-item label="高度">
                <a-input-number
                  v-model:value="editableModule.config.height"
                  :min="4"
                  :max="120"
                  class="w-full"
                />
              </a-form-item>
            </template>

            <template v-if="editableModule.type === 'divider'">
              <a-form-item label="线型">
                <a-select v-model:value="editableModule.config.style">
                  <a-select-option value="solid">实线</a-select-option>
                  <a-select-option value="dashed">虚线</a-select-option>
                </a-select>
              </a-form-item>
              <a-form-item label="边距">
                <a-input-number
                  v-model:value="editableModule.config.margin"
                  :min="0"
                  :max="60"
                  class="w-full"
                />
              </a-form-item>
            </template>
          </div>

          <div
            v-if="
              activeType === 'home' &&
              editableModule.config &&
              moduleTextStyleFields.length > 0
            "
            class="property-section"
          >
            <div class="property-subsection__head">
              <div class="property-section__title">文字样式</div>
              <a-button
                :disabled="isReadonlyScheme"
                size="small"
                type="link"
                @click="resetProfileTextStyles(editableModule)"
              >
                重置
              </a-button>
            </div>
            <div class="profile-text-style-list">
              <div
                v-for="item in moduleTextStyleFields"
                :key="item.role"
                class="profile-text-style-card"
              >
                <div class="profile-text-style-card__head">
                  <span class="profile-text-style-card__title">
                    <strong>{{ item.label }}</strong>
                    <small
                      :title="
                        profileTextStyleTargetText(editableModule, item.role)
                      "
                    >
                      {{
                        profileTextStyleTargetText(editableModule, item.role)
                      }}
                    </small>
                  </span>
                </div>
                <div class="profile-text-style-card__body">
                  <div
                    v-if="isImageCubeTitleTextStyle(editableModule, item.role)"
                    class="profile-text-style-card__background-title"
                  >
                    标题文字
                  </div>
                  <div class="profile-text-style-color">
                    <input
                      :value="
                        getProfileTextStyleColorInputValue(
                          editableModule,
                          item.role,
                        )
                      "
                      :aria-label="`选择${item.label}颜色`"
                      :disabled="isReadonlyScheme"
                      class="style-color-field__picker"
                      type="color"
                      @input="
                        (event: Event) =>
                          updateProfileTextStyleColorFromEvent(
                            editableModule,
                            item.role,
                            event,
                          )
                      "
                    />
                    <a-input
                      :value="
                        getProfileTextStyleDisplayValue(
                          editableModule,
                          item.role,
                          'color',
                        )
                      "
                      allow-clear
                      :disabled="isReadonlyScheme"
                      placeholder="跟随默认"
                      @update:value="
                        (value: string) =>
                          updateProfileTextStyleField(
                            editableModule,
                            item.role,
                            'color',
                            value,
                          )
                      "
                    />
                  </div>
                  <a-input-number
                    :value="
                      getProfileTextStyleDisplayValue(
                        editableModule,
                        item.role,
                        'fontSize',
                      )
                    "
                    :min="16"
                    :max="80"
                    addon-after="rpx"
                    :disabled="isReadonlyScheme"
                    placeholder="字号"
                    class="profile-text-style-card__number"
                    @change="
                      (value: unknown) =>
                        updateProfileTextStyleField(
                          editableModule,
                          item.role,
                          'fontSize',
                          value,
                        )
                    "
                  />
                  <a-select
                    :value="
                      getProfileTextStyleDisplayValue(
                        editableModule,
                        item.role,
                        'fontWeight',
                      )
                    "
                    :options="profileTextWeightOptions"
                    allow-clear
                    :disabled="isReadonlyScheme"
                    placeholder="粗细"
                    @change="
                      (value: unknown) =>
                        updateProfileTextStyleField(
                          editableModule,
                          item.role,
                          'fontWeight',
                          value,
                        )
                    "
                  />
                  <a-select
                    :value="
                      getProfileTextStyleDisplayValue(
                        editableModule,
                        item.role,
                        'textAlign',
                      )
                    "
                    :options="profileTextAlignOptions"
                    allow-clear
                    :disabled="isReadonlyScheme"
                    placeholder="对齐"
                    @change="
                      (value: unknown) =>
                        updateProfileTextStyleField(
                          editableModule,
                          item.role,
                          'textAlign',
                          value,
                        )
                    "
                  />
                  <a-checkbox
                    :checked="
                      getProfileTextStyleDisplayValue(
                        editableModule,
                        item.role,
                        'fontStyle',
                      ) === 'italic'
                    "
                    :disabled="isReadonlyScheme"
                    class="profile-text-style-card__toggle"
                    @change="
                      (event: any) =>
                        updateProfileTextStyleField(
                          editableModule,
                          item.role,
                          'fontStyle',
                          event.target.checked ? 'italic' : '',
                        )
                    "
                  >
                    斜体
                  </a-checkbox>
                </div>
                <div
                  v-if="isImageCubeTitleTextStyle(editableModule, item.role)"
                  class="profile-text-style-card__background"
                >
                  <div class="profile-text-style-card__background-title">
                    标题背景
                  </div>
                  <a-segmented
                    :value="
                      getProfileTextStyleDisplayValue(
                        editableModule,
                        item.role,
                        'backgroundMode',
                      )
                    "
                    :disabled="isReadonlyScheme"
                    :options="backgroundModeOptions"
                    @change="
                      (value: unknown) =>
                        updateProfileTextStyleField(
                          editableModule,
                          item.role,
                          'backgroundMode',
                          value,
                        )
                    "
                  />
                  <div
                    v-if="
                      getProfileTextStyleDisplayValue(
                        editableModule,
                        item.role,
                        'backgroundMode',
                      ) === 'image'
                    "
                    class="profile-text-style-card__upload"
                  >
                    <Upload
                      :value="
                        getProfileTextStyleDisplayValue(
                          editableModule,
                          item.role,
                          'backgroundImage',
                        )
                      "
                      :disabled="isReadonlyScheme"
                      module="client"
                      type="image"
                      @update:value="
                        (value: unknown) =>
                          updateProfileTextStyleField(
                            editableModule,
                            item.role,
                            'backgroundImage',
                            value,
                          )
                      "
                    />
                  </div>
                  <template v-else>
                    <div class="profile-text-style-background-grid">
                      <div class="profile-text-style-background-field">
                        <span class="profile-text-style-background-label">
                          起始颜色
                        </span>
                        <div class="profile-text-style-color">
                          <input
                            :value="
                              getProfileTextStyleFieldColorInputValue(
                                editableModule,
                                item.role,
                                'backgroundColorStart',
                              )
                            "
                            aria-label="选择标题背景起始颜色"
                            :disabled="isReadonlyScheme"
                            class="style-color-field__picker"
                            type="color"
                            @input="
                              (event: Event) =>
                                updateProfileTextStyleFieldColorFromEvent(
                                  editableModule,
                                  item.role,
                                  'backgroundColorStart',
                                  event,
                                )
                            "
                          />
                          <a-input
                            :value="
                              getProfileTextStyleDisplayValue(
                                editableModule,
                                item.role,
                                'backgroundColorStart',
                              )
                            "
                            allow-clear
                            :disabled="isReadonlyScheme"
                            placeholder="起始颜色"
                            @update:value="
                              (value: string) =>
                                updateProfileTextStyleField(
                                  editableModule,
                                  item.role,
                                  'backgroundColorStart',
                                  value,
                                )
                            "
                          />
                        </div>
                      </div>
                      <div class="profile-text-style-background-field">
                        <span class="profile-text-style-background-label">
                          结束颜色
                        </span>
                        <div class="profile-text-style-color">
                          <input
                            :value="
                              getProfileTextStyleFieldColorInputValue(
                                editableModule,
                                item.role,
                                'backgroundColorEnd',
                              )
                            "
                            aria-label="选择标题背景结束颜色"
                            :disabled="isReadonlyScheme"
                            class="style-color-field__picker"
                            type="color"
                            @input="
                              (event: Event) =>
                                updateProfileTextStyleFieldColorFromEvent(
                                  editableModule,
                                  item.role,
                                  'backgroundColorEnd',
                                  event,
                                )
                            "
                          />
                          <a-input
                            :value="
                              getProfileTextStyleDisplayValue(
                                editableModule,
                                item.role,
                                'backgroundColorEnd',
                              )
                            "
                            allow-clear
                            :disabled="isReadonlyScheme"
                            placeholder="结束颜色"
                            @update:value="
                              (value: string) =>
                                updateProfileTextStyleField(
                                  editableModule,
                                  item.role,
                                  'backgroundColorEnd',
                                  value,
                                )
                            "
                          />
                        </div>
                      </div>
                    </div>
                    <div class="profile-text-style-background-field">
                      <span class="profile-text-style-background-label">
                        渐变方向
                      </span>
                      <a-radio-group
                        :value="
                          getProfileTextStyleDisplayValue(
                            editableModule,
                            item.role,
                            'backgroundGradientDirection',
                          )
                        "
                        :options="gradientDirectionOptions"
                        :disabled="isReadonlyScheme"
                        @update:value="
                          (value: unknown) =>
                            updateProfileTextStyleField(
                              editableModule,
                              item.role,
                              'backgroundGradientDirection',
                              value,
                            )
                        "
                      />
                    </div>
                  </template>
                  <div class="profile-text-style-background-grid">
                    <div class="profile-text-style-background-field">
                      <span class="profile-text-style-background-label">
                        背景宽度
                      </span>
                      <a-input-number
                        :value="
                          getProfileTextStyleDisplayValue(
                            editableModule,
                            item.role,
                            'backgroundWidth',
                          )
                        "
                        :min="20"
                        :max="100"
                        addon-after="%"
                        :disabled="isReadonlyScheme"
                        placeholder="背景宽度"
                        @change="
                          (value: unknown) =>
                            updateProfileTextStyleField(
                              editableModule,
                              item.role,
                              'backgroundWidth',
                              value,
                            )
                        "
                      />
                    </div>
                    <div class="profile-text-style-background-field">
                      <span class="profile-text-style-background-label">
                        背景高度
                      </span>
                      <a-input-number
                        :value="
                          getProfileTextStyleDisplayValue(
                            editableModule,
                            item.role,
                            'backgroundHeight',
                          )
                        "
                        :min="10"
                        :max="100"
                        addon-after="%"
                        :disabled="isReadonlyScheme"
                        placeholder="背景高度"
                        @change="
                          (value: unknown) =>
                            updateProfileTextStyleField(
                              editableModule,
                              item.role,
                              'backgroundHeight',
                              value,
                            )
                        "
                      />
                    </div>
                  </div>
                  <div class="profile-text-style-background-field">
                    <span class="profile-text-style-background-label">
                      背景圆角
                    </span>
                    <div class="style-range-control">
                      <a-slider
                        :value="
                          getProfileTextStyleDisplayValue(
                            editableModule,
                            item.role,
                            'backgroundRadius',
                          )
                        "
                        :min="0"
                        :max="80"
                        :disabled="isReadonlyScheme"
                        class="style-range-control__slider"
                        @change="
                          (value: unknown) =>
                            updateProfileTextStyleField(
                              editableModule,
                              item.role,
                              'backgroundRadius',
                              value,
                            )
                        "
                      />
                      <a-input-number
                        :value="
                          getProfileTextStyleDisplayValue(
                            editableModule,
                            item.role,
                            'backgroundRadius',
                          )
                        "
                        :min="0"
                        :max="80"
                        addon-after="rpx"
                        :disabled="isReadonlyScheme"
                        class="style-range-control__number"
                        placeholder="背景圆角"
                        @change="
                          (value: unknown) =>
                            updateProfileTextStyleField(
                              editableModule,
                              item.role,
                              'backgroundRadius',
                              value,
                            )
                        "
                      />
                    </div>
                  </div>
                  <div class="profile-text-style-background-field">
                    <span class="profile-text-style-background-label">
                      定位位置
                    </span>
                    <a-radio-group
                      :value="
                        getProfileTextStyleDisplayValue(
                          editableModule,
                          item.role,
                          'backgroundPosition',
                        )
                      "
                      :disabled="isReadonlyScheme"
                      button-style="solid"
                      class="title-position-radio-grid"
                      @change="
                        (event: any) =>
                          updateProfileTextStyleField(
                            editableModule,
                            item.role,
                            'backgroundPosition',
                            event.target.value,
                          )
                      "
                    >
                      <a-radio-button
                        v-for="position in titleBackgroundPositionOptions"
                        :key="position.value"
                        :value="position.value"
                      >
                        {{ position.label }}
                      </a-radio-button>
                    </a-radio-group>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div
            v-if="activeType === 'profile' && editableModule.config"
            class="property-section"
          >
            <div class="property-section__head">
              <div>
                <div class="property-section__title">组件内容</div>
                <div class="property-section__desc">
                  {{
                    {
                      customMenu: '自定义服务入口，可配置图片、名称和跳转。',
                      orderEntry:
                        '默认展示四个订单状态入口，可配置图片、名称和跳转。',
                      serviceMenu:
                        '默认展示四个常用服务入口，可配置图片、名称和跳转。',
                      userInfo: '登录信息区域，控制手机号与签名展示。',
                      walletEntry: '钱包信息区域，控制余额、明细和查看入口。',
                    }[editableProfileType] || '配置当前个人中心组件内容。'
                  }}
                </div>
              </div>
              <a-button
                :disabled="isReadonlyScheme"
                size="small"
                type="link"
                @click="$emit('resetModuleContent', editableModule)"
              >
                重置
              </a-button>
            </div>

            <template v-if="editableProfileType === 'userInfo'">
              <div class="profile-config-grid">
                <a-form-item label="展示手机号">
                  <a-switch
                    v-model:checked="editableModule.config.show_mobile"
                  />
                </a-form-item>
              </div>
            </template>

            <template v-else-if="editableProfileType === 'walletEntry'">
              <a-form-item label="卡片标题">
                <a-input
                  v-model:value="editableModule.config.title"
                  placeholder="如：我的余额"
                />
              </a-form-item>
              <div class="profile-config-grid">
                <a-form-item label="展示余额">
                  <a-switch
                    v-model:checked="editableModule.config.show_balance"
                  />
                </a-form-item>
                <a-form-item label="余额明细">
                  <a-switch
                    v-model:checked="editableModule.config.show_records"
                  />
                </a-form-item>
                <a-form-item label="查看按钮">
                  <a-switch
                    v-model:checked="editableModule.config.show_view_button"
                  />
                </a-form-item>
              </div>
            </template>

            <template v-else-if="isProfileEntryModule">
              <a-form-item label="组件标题">
                <a-input
                  v-model:value="editableModule.config.title"
                  placeholder="如：我的订单、我的服务"
                />
              </a-form-item>
              <div
                v-if="editableProfileType !== 'orderEntry'"
                class="profile-config-grid"
              >
                <a-form-item label="展示方式">
                  <a-segmented
                    v-model:value="editableModule.config.display"
                    :options="[
                      { label: '列表', value: 'list' },
                      { label: '宫格', value: 'grid' },
                    ]"
                  />
                </a-form-item>
                <a-form-item label="每行数量">
                  <a-input-number
                    v-model:value="editableModule.config.columns"
                    :min="3"
                    :max="5"
                    class="w-full"
                  />
                </a-form-item>
              </div>
              <a-form-item class="property-form-item--full" label="入口项">
                <div class="entry-list profile-entry-list">
                  <div
                    v-for="(item, itemIndex) in selectedProfileEntryItems"
                    :key="item.id || itemIndex"
                    class="entry-row entry-row--profile"
                    :class="{
                      'is-dragging': profileEntryDragIndex === itemIndex,
                      'is-drop-target':
                        profileEntryDropIndex === itemIndex &&
                        profileEntryDragIndex !== itemIndex,
                      'entry-row--profile-service':
                        editableProfileType === 'serviceMenu',
                    }"
                    @dragenter="handleProfileEntryDragEnter(itemIndex, $event)"
                    @dragover="handleProfileEntryDragOver"
                    @drop="handleProfileEntryDrop(itemIndex, $event)"
                  >
                    <button
                      class="banner-item-drag entry-row__drag"
                      :disabled="isReadonlyScheme"
                      draggable="true"
                      title="拖动排序"
                      type="button"
                      @dragend="handleProfileEntryDragEnd"
                      @dragstart="
                        handleProfileEntryDragStart(itemIndex, $event)
                      "
                    >
                      <IconifyIcon icon="lucide:grip-vertical" />
                    </button>
                    <div class="entry-row__image">
                      <Upload
                        :value="item.image"
                        :disabled="isReadonlyScheme"
                        :max-count="1"
                        mode="both"
                        module="client_decorate"
                        type="image"
                        @update:value="updateProfileItemImage(item, $event)"
                      />
                    </div>
                    <a-input
                      :value="item.label || item.title"
                      placeholder="显示名称"
                      @update:value="updateProfileItemLabel(item, $event)"
                    />
                    <TargetPicker
                      :value="item.path"
                      :disabled="isReadonlyScheme"
                      placeholder="跳转目标"
                      @update:value="updateProfileItemPath(item, $event)"
                    />
                    <div
                      v-if="isProfileEntryModule"
                      class="entry-row__visibility"
                    >
                      <span>显示</span>
                      <a-switch
                        :checked="getProfileItemVisible(item)"
                        :disabled="isReadonlyScheme"
                        size="small"
                        @change="
                          (checked: boolean) =>
                            updateProfileItemVisible(item, checked)
                        "
                      />
                    </div>
                    <a-button
                      danger
                      size="small"
                      @click="
                        $emit(
                          'removeConfigItem',
                          editableModule.config.items,
                          itemIndex,
                        )
                      "
                    >
                      删除
                    </a-button>
                  </div>
                  <a-button
                    size="small"
                    @click="$emit('addProfileItem', editableModule)"
                  >
                    添加入口
                  </a-button>
                </div>
              </a-form-item>
            </template>

            <template v-if="moduleTextStyleFields.length > 0">
              <div class="property-subsection__head">
                <div class="property-subsection__title">文字样式</div>
                <a-button
                  :disabled="isReadonlyScheme"
                  size="small"
                  type="link"
                  @click="resetProfileTextStyles(editableModule)"
                >
                  重置
                </a-button>
              </div>
              <div class="profile-text-style-list">
                <div
                  v-for="item in moduleTextStyleFields"
                  :key="item.role"
                  class="profile-text-style-card"
                >
                  <div class="profile-text-style-card__head">
                    <span class="profile-text-style-card__title">
                      <strong>{{ item.label }}</strong>
                      <small
                        :title="
                          profileTextStyleTargetText(editableModule, item.role)
                        "
                      >
                        {{
                          profileTextStyleTargetText(editableModule, item.role)
                        }}
                      </small>
                    </span>
                  </div>
                  <div class="profile-text-style-card__body">
                    <div class="profile-text-style-color">
                      <input
                        :value="
                          getProfileTextStyleColorInputValue(
                            editableModule,
                            item.role,
                          )
                        "
                        :aria-label="`选择${item.label}颜色`"
                        :disabled="isReadonlyScheme"
                        class="style-color-field__picker"
                        type="color"
                        @input="
                          (event: Event) =>
                            updateProfileTextStyleColorFromEvent(
                              editableModule,
                              item.role,
                              event,
                            )
                        "
                      />
                      <a-input
                        :value="
                          getProfileTextStyleDisplayValue(
                            editableModule,
                            item.role,
                            'color',
                          )
                        "
                        allow-clear
                        :disabled="isReadonlyScheme"
                        placeholder="跟随默认"
                        @update:value="
                          (value: string) =>
                            updateProfileTextStyleField(
                              editableModule,
                              item.role,
                              'color',
                              value,
                            )
                        "
                      />
                    </div>
                    <a-input-number
                      :value="
                        getProfileTextStyleDisplayValue(
                          editableModule,
                          item.role,
                          'fontSize',
                        )
                      "
                      :min="16"
                      :max="80"
                      addon-after="rpx"
                      :disabled="isReadonlyScheme"
                      placeholder="字号"
                      class="profile-text-style-card__number"
                      @change="
                        (value: unknown) =>
                          updateProfileTextStyleField(
                            editableModule,
                            item.role,
                            'fontSize',
                            value,
                          )
                      "
                    />
                    <a-select
                      :value="
                        getProfileTextStyleDisplayValue(
                          editableModule,
                          item.role,
                          'fontWeight',
                        )
                      "
                      :options="profileTextWeightOptions"
                      allow-clear
                      :disabled="isReadonlyScheme"
                      placeholder="粗细"
                      @change="
                        (value: unknown) =>
                          updateProfileTextStyleField(
                            editableModule,
                            item.role,
                            'fontWeight',
                            value,
                          )
                      "
                    />
                    <a-select
                      :value="
                        getProfileTextStyleDisplayValue(
                          editableModule,
                          item.role,
                          'textAlign',
                        )
                      "
                      :options="profileTextAlignOptions"
                      allow-clear
                      :disabled="isReadonlyScheme"
                      placeholder="对齐"
                      @change="
                        (value: unknown) =>
                          updateProfileTextStyleField(
                            editableModule,
                            item.role,
                            'textAlign',
                            value,
                          )
                      "
                    />
                    <a-checkbox
                      :checked="
                        getProfileTextStyleDisplayValue(
                          editableModule,
                          item.role,
                          'fontStyle',
                        ) === 'italic'
                      "
                      :disabled="isReadonlyScheme"
                      class="profile-text-style-card__toggle"
                      @change="
                        (event: any) =>
                          updateProfileTextStyleField(
                            editableModule,
                            item.role,
                            'fontStyle',
                            event.target.checked ? 'italic' : '',
                          )
                      "
                    >
                      斜体
                    </a-checkbox>
                  </div>
                </div>
              </div>
            </template>
          </div>
        </a-form>
      </a-card>
    </aside>
  </div>
</template>
