<script lang="ts" setup>
import type { ClientDecorateApi } from '#/api/client';
import type { GoodsApi, GoodsCategoryApi } from '#/api/goods';

import { computed, ref } from 'vue';

import { IconPicker } from '@vben/common-ui';
import { IconifyIcon } from '@vben/icons';

import RichTextEditor from '#/components/rich-text-editor/index.vue';
import Upload from '#/components/upload/index.vue';

import ClientPhonePreview from '../../components/ClientPhonePreview.vue';
import PageLinkPicker from './PageLinkPicker.vue';
import ProductSourcePicker from './ProductSourcePicker.vue';
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

defineEmits<{
  addNavItem: [module: ModuleItem];
  addProfileItem: [module: ModuleItem];
  moduleDelete: [index: number];
  moduleMouseDown: [index: number, event: MouseEvent];
  moduleMove: [index: number, direction: 'down' | 'up'];
  paletteClick: [type: string];
  paletteMouseDown: [item: PaletteItem, event: MouseEvent];
  removeConfigItem: [items: any[], index: number | string];
  resetModuleConfig: [module: ModuleItem];
  resetPageStyle: [];
  selectModule: [module: ModuleItem];
  updatePageStyle: [field: 'paddingX' | 'paddingY', value: unknown];
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

const bannerDragIndex = ref<null | number>(null);
const bannerDropIndex = ref<null | number>(null);
const navDragIndex = ref<null | number>(null);
const navDropIndex = ref<null | number>(null);
const entryCardIconPrefix = ref(props.iconPrefix || 'ant-design');

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
    .slice(0, 4)
    .map((item: any, index: number) => normalizeCubeItem(item, index));
  syncCubeItems(module, items);
  return items;
};

const selectedCubeItems = computed<any[]>(() =>
  editableModule.value?.type === 'imageCube' && editableModule.value.config
    ? getCubeItems(editableModule.value)
    : [],
);

const createCubeItem = (index: number) => ({
  id: createLocalId('cube_item'),
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
});

const addSelectedCubeItem = () => {
  if (!editableModule.value) return;
  const items = getCubeItems(editableModule.value);
  if (items.length >= 4) return;
  items.push(createCubeItem(items.length));
  syncCubeItems(editableModule.value, items);
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

const updateSelectedBackgroundColor = (event: Event) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  if (value && editableModule.value?.config) {
    editableModule.value.config.background = value;
  }
};

const updateSelectedEntryIconColor = (event: Event) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  if (value && editableModule.value?.config) {
    editableModule.value.config.icon_color = value;
  }
};

const updateSelectedEntryIconBackground = (event: Event) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  if (value && editableModule.value?.config) {
    editableModule.value.config.icon_background = value;
  }
};

const updateSelectedTitleColor = (event: Event) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  if (value && editableModule.value?.config) {
    editableModule.value.config.title_color = value;
  }
};

const updateSelectedSubTitleColor = (event: Event) => {
  const value = (event.target as HTMLInputElement | null)?.value;
  if (value && editableModule.value?.config) {
    editableModule.value.config.sub_color = value;
  }
};
</script>

<template>
  <div
    class="decorate-editor"
    :class="{ 'decorate-editor--tabbar': activeType === 'tabbar' }"
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
          <span>手机预览就是编辑画布，组件可直接拖入和选中配置。</span>
        </div>
        <a-space>
          <a-button
            v-if="activeType === 'tabbar'"
            :disabled="isReadonlyScheme || schemeForm.schema.length >= 5"
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
          v-if="activeType === 'home'"
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
            <div class="style-grid">
              <a-form-item label="上下内边距">
                <a-input-number
                  :value="schemeForm.pageStyle.paddingY"
                  :min="0"
                  :max="120"
                  addon-after="rpx"
                  class="w-full"
                  @change="
                    (value) => $emit('updatePageStyle', 'paddingY', value)
                  "
                />
              </a-form-item>
              <a-form-item label="左右内边距">
                <a-input-number
                  :value="schemeForm.pageStyle.paddingX"
                  :min="0"
                  :max="120"
                  addon-after="rpx"
                  class="w-full"
                  @change="
                    (value) => $emit('updatePageStyle', 'paddingX', value)
                  "
                />
              </a-form-item>
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
              <div class="property-section">
                <div class="property-section__head">
                  <div class="property-section__title">基础样式</div>
                  <a-button
                    :disabled="isReadonlyScheme"
                    size="small"
                    type="link"
                    @click="$emit('resetModuleConfig', editableModule)"
                  >
                    重置
                  </a-button>
                </div>
                <div class="style-grid">
                  <a-form-item label="宽度">
                    <a-input-number
                      v-model:value="editableModule.config.widthPercent"
                      :min="50"
                      :max="100"
                      addon-after="%"
                      class="w-full"
                    />
                  </a-form-item>
                  <a-form-item label="上边距">
                    <a-input-number
                      v-model:value="editableModule.config.marginTop"
                      :min="0"
                      :max="120"
                      addon-after="rpx"
                      class="w-full"
                    />
                  </a-form-item>
                  <a-form-item label="下边距">
                    <a-input-number
                      v-model:value="editableModule.config.marginBottom"
                      :min="0"
                      :max="120"
                      addon-after="rpx"
                      class="w-full"
                    />
                  </a-form-item>
                  <a-form-item label="圆角">
                    <a-input-number
                      v-model:value="editableModule.config.radius"
                      :min="0"
                      :max="80"
                      addon-after="rpx"
                      class="w-full"
                    />
                  </a-form-item>
                  <a-form-item label="上下内边距">
                    <a-input-number
                      v-model:value="editableModule.config.paddingY"
                      :min="0"
                      :max="80"
                      addon-after="rpx"
                      class="w-full"
                    />
                  </a-form-item>
                  <a-form-item label="左右内边距">
                    <a-input-number
                      v-model:value="editableModule.config.paddingX"
                      :min="0"
                      :max="80"
                      addon-after="rpx"
                      class="w-full"
                    />
                  </a-form-item>
                  <a-form-item label="背景色">
                    <a-input
                      v-model:value="editableModule.config.background"
                      allow-clear
                      placeholder="跟随主题背景"
                    >
                      <template #addonAfter>
                        <input
                          :value="
                            getColorInputValue(editableModule.config.background)
                          "
                          aria-label="选择背景色"
                          class="color-input"
                          type="color"
                          @input="updateSelectedBackgroundColor"
                        />
                      </template>
                    </a-input>
                  </a-form-item>
                </div>
              </div>
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
                <a-segmented
                  v-model:value="editableModule.icon_mode"
                  :options="[
                    { label: '图标', value: 'icon' },
                    { label: '图片', value: 'upload' },
                  ]"
                />
                <div class="mt-3">
                  <Upload
                    v-if="editableModule.icon_mode === 'upload'"
                    v-model:value="editableModule.icon"
                    :disabled="isReadonlyScheme"
                    module="client"
                    type="image"
                  />
                  <IconPicker
                    v-else
                    v-model="editableModule.icon"
                    :prefix="iconPrefix"
                    placeholder="选择默认图标"
                    style="width: 100%"
                  />
                </div>
              </a-form-item>
              <a-form-item label="选中图标">
                <a-segmented
                  v-model:value="editableModule.selected_icon_mode"
                  :options="[
                    { label: '图标', value: 'icon' },
                    { label: '图片', value: 'upload' },
                  ]"
                />
                <div class="mt-3">
                  <Upload
                    v-if="editableModule.selected_icon_mode === 'upload'"
                    v-model:value="editableModule.selected_icon"
                    :disabled="isReadonlyScheme"
                    module="client"
                    type="image"
                  />
                  <IconPicker
                    v-else
                    v-model="editableModule.selected_icon"
                    :prefix="iconPrefix"
                    placeholder="选择选中图标"
                    style="width: 100%"
                  />
                </div>
              </a-form-item>
            </div>
          </template>

          <div
            v-if="activeType === 'home' && editableModule.config"
            class="property-section"
          >
            <div class="property-section__title">组件内容</div>
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
                    <a-input v-model:value="item.title" placeholder="标题" />
                    <TargetPicker
                      v-model:value="item.path"
                      :disabled="isReadonlyScheme"
                      placeholder="跳转目标"
                    />
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
                      <a-button
                        danger
                        size="small"
                        @click="removeSelectedCubeItem(itemIndex)"
                      >
                        删除
                      </a-button>
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
                      isReadonlyScheme || selectedCubeItems.length >= 4
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
              <a-form-item label="图标类型">
                <a-segmented
                  v-model:value="editableModule.config.icon_mode"
                  :options="[
                    { label: '图标', value: 'icon' },
                    { label: '图片', value: 'image' },
                  ]"
                />
              </a-form-item>
              <a-form-item
                v-if="editableModule.config.icon_mode !== 'image'"
                label="图标"
              >
                <div class="flex flex-col" style="width: 100%">
                  <div class="mb-2">
                    <a-select
                      v-model:value="entryCardIconPrefix"
                      placeholder="选择图标集"
                      style="width: 200px"
                    >
                      <a-select-option value="ant-design">
                        Ant Design
                      </a-select-option>
                      <a-select-option value="lucide">Lucide</a-select-option>
                      <a-select-option value="mdi">
                        Material Design
                      </a-select-option>
                      <a-select-option value="carbon">Carbon</a-select-option>
                      <a-select-option value="mdi-light">
                        MDI Light
                      </a-select-option>
                    </a-select>
                    <span class="sm ml-2 text-gray-400">
                      也可直接输入，如：lucide:shield
                    </span>
                  </div>
                  <IconPicker
                    v-model="editableModule.config.icon"
                    :prefix="entryCardIconPrefix"
                    placeholder="请选择图标"
                    style="width: 100%"
                  />
                </div>
              </a-form-item>
              <a-form-item v-else label="图标图片">
                <Upload
                  v-model:value="editableModule.config.icon_image"
                  :disabled="isReadonlyScheme"
                  module="client"
                  type="image"
                />
              </a-form-item>
              <a-form-item label="图标颜色">
                <a-input
                  v-model:value="editableModule.config.icon_color"
                  allow-clear
                  placeholder="跟随主题色"
                >
                  <template #addonAfter>
                    <input
                      :value="
                        getColorInputValue(editableModule.config.icon_color)
                      "
                      aria-label="选择图标颜色"
                      class="color-input"
                      type="color"
                      @input="updateSelectedEntryIconColor"
                    />
                  </template>
                </a-input>
              </a-form-item>
              <a-form-item label="图标背景">
                <a-input
                  v-model:value="editableModule.config.icon_background"
                  allow-clear
                  placeholder="跟随主题浅色"
                >
                  <template #addonAfter>
                    <input
                      :value="
                        getColorInputValue(
                          editableModule.config.icon_background,
                        )
                      "
                      aria-label="选择图标背景色"
                      class="color-input"
                      type="color"
                      @input="updateSelectedEntryIconBackground"
                    />
                  </template>
                </a-input>
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
              <div class="property-subsection__title">文字样式</div>
              <a-form-item label="对齐方式">
                <a-segmented
                  v-model:value="editableModule.config.title_align"
                  :options="[
                    { label: '左', value: 'left' },
                    { label: '中', value: 'center' },
                    { label: '右', value: 'right' },
                  ]"
                />
              </a-form-item>
              <div class="style-grid">
                <a-form-item label="标题字号">
                  <a-input-number
                    v-model:value="editableModule.config.title_font_size"
                    :min="18"
                    :max="72"
                    addon-after="rpx"
                    class="w-full"
                  />
                </a-form-item>
                <a-form-item label="副标字号">
                  <a-input-number
                    v-model:value="editableModule.config.sub_font_size"
                    :min="16"
                    :max="56"
                    addon-after="rpx"
                    class="w-full"
                  />
                </a-form-item>
              </div>
              <a-form-item label="主标题">
                <a-space>
                  <a-checkbox
                    v-model:checked="editableModule.config.title_bold"
                  >
                    加粗
                  </a-checkbox>
                  <a-checkbox
                    v-model:checked="editableModule.config.title_italic"
                  >
                    斜体
                  </a-checkbox>
                </a-space>
              </a-form-item>
              <a-form-item label="标题颜色">
                <a-input
                  v-model:value="editableModule.config.title_color"
                  allow-clear
                  placeholder="跟随主题标题色"
                >
                  <template #addonAfter>
                    <input
                      :value="
                        getColorInputValue(editableModule.config.title_color)
                      "
                      aria-label="选择标题颜色"
                      class="color-input"
                      type="color"
                      @input="updateSelectedTitleColor"
                    />
                  </template>
                </a-input>
              </a-form-item>
              <a-form-item label="副标题">
                <a-space>
                  <a-checkbox v-model:checked="editableModule.config.sub_bold">
                    加粗
                  </a-checkbox>
                  <a-checkbox
                    v-model:checked="editableModule.config.sub_italic"
                  >
                    斜体
                  </a-checkbox>
                </a-space>
              </a-form-item>
              <a-form-item label="副标颜色">
                <a-input
                  v-model:value="editableModule.config.sub_color"
                  allow-clear
                  placeholder="跟随主题辅助色"
                >
                  <template #addonAfter>
                    <input
                      :value="
                        getColorInputValue(editableModule.config.sub_color)
                      "
                      aria-label="选择副标题颜色"
                      class="color-input"
                      type="color"
                      @input="updateSelectedSubTitleColor"
                    />
                  </template>
                </a-input>
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
            v-if="activeType === 'profile' && editableModule.config"
            class="property-section"
          >
            <div class="property-section__title">组件内容</div>
            <template
              v-if="
                normalizeProfileModuleType(editableModule.type) === 'userInfo'
              "
            >
              <a-form-item label="显示等级">
                <a-switch v-model:checked="editableModule.config.show_level" />
              </a-form-item>
              <a-form-item label="显示手机号">
                <a-switch v-model:checked="editableModule.config.show_mobile" />
              </a-form-item>
            </template>

            <template
              v-if="
                normalizeProfileModuleType(editableModule.type) ===
                'walletEntry'
              "
            >
              <a-form-item label="显示余额">
                <a-switch
                  v-model:checked="editableModule.config.show_balance"
                />
              </a-form-item>
              <a-form-item label="显示积分">
                <a-switch v-model:checked="editableModule.config.show_points" />
              </a-form-item>
            </template>

            <template v-if="editableModule.config.items">
              <a-form-item
                v-if="
                  normalizeProfileModuleType(editableModule.type) ===
                    'serviceMenu' ||
                  normalizeProfileModuleType(editableModule.type) ===
                    'customMenu'
                "
                label="菜单标题"
              >
                <a-input v-model:value="editableModule.config.title" />
              </a-form-item>
              <a-form-item label="入口">
                <div class="entry-list">
                  <div
                    v-for="(item, itemIndex) in editableModule.config.items"
                    :key="item.id || itemIndex"
                    class="entry-row"
                  >
                    <a-input v-model:value="item.title" placeholder="标题" />
                    <TargetPicker
                      v-model:value="item.path"
                      :disabled="isReadonlyScheme"
                      placeholder="跳转目标"
                    />
                    <IconPicker
                      v-model="item.icon"
                      :prefix="iconPrefix"
                      placeholder="图标"
                    />
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
          </div>
        </a-form>
      </a-card>
    </aside>
  </div>
</template>
