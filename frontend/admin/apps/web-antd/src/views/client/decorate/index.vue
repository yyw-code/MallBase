<script lang="ts" setup>
import type { ClientDecorateApi, ClientThemeApi } from '#/api/client';
import type { GoodsApi, GoodsCategoryApi } from '#/api/goods';

import {
  computed,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  toRaw,
  watch,
} from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { message, Modal } from 'ant-design-vue';

import {
  activateClientDecorateSchemeApi,
  copyClientDecorateSchemeApi,
  createClientDecorateSchemeApi,
  deleteClientDecorateSchemeApi,
  getClientDecorateSchemeInfoApi,
  getClientDecorateSchemeListApi,
  getClientThemeListApi,
  getClientThemePolicyApi,
  updateClientDecorateSchemeApi,
} from '#/api/client';
import { getGoodsCategoryTreeApi, getGoodsListApi } from '#/api/goods';

import DecorateEditor from './components/DecorateEditor.vue';
import DecorateSchemeList from './components/DecorateSchemeList.vue';

defineOptions({ name: 'ClientDecorateManagement' });

type ModuleItem = Record<string, any>;
type UploadFileInfo = {
  name?: string;
  url?: number | string;
};

const DEFAULT_THEME_TOKENS = {
  colorBg: '#ffffff',
  colorBgSecondary: '#faf8ff',
  colorBgSurface: '#f3f3fe',
  colorBorder: '#e0e4e8',
  colorDivider: '#f0f2f5',
  colorPrice: '#ff5a1f',
  colorPrimary: '#0d50d5',
  colorPrimaryLight: '#386bef',
  colorText: '#191b23',
  colorTextSecondary: '#434654',
  colorTextTertiary: '#737686',
  colorTextTitle: '#191b23',
};

const DEFAULT_THEME_POLICY: ClientThemeApi.ThemePolicy = {
  allow_user_select: 1,
  default_mode: 'system',
  default_theme_id: null,
};

const DEFAULT_HOME_PAGE_STYLE = {
  paddingX: 28,
  paddingY: 0,
};

const SCHEME_TABS: Array<{
  help: string;
  label: string;
  value: ClientDecorateApi.SchemeType;
}> = [
  { help: '配置首页组件、排序和内容', label: '首页装修', value: 'home' },
  { help: '配置底部导航项、图标和模式', label: '底部导航', value: 'tabbar' },
  { help: '配置个人中心模块和入口', label: '个人中心', value: 'profile' },
];

const HOME_MODULES: Array<{
  desc: string;
  icon: string;
  label: string;
  type: ClientDecorateApi.ComponentType;
}> = [
  {
    desc: '搜索入口和占位文案',
    icon: 'lucide:search',
    label: '搜索框',
    type: 'search',
  },
  {
    desc: '多图轮播广告位',
    icon: 'lucide:images',
    label: '轮播图',
    type: 'banner',
  },
  {
    desc: '常用入口宫格',
    icon: 'lucide:grid-3x3',
    label: '导航宫格',
    type: 'navGrid',
  },
  {
    desc: '单图、双图、四宫格广告',
    icon: 'lucide:layout-grid',
    label: '图片魔方',
    type: 'imageCube',
  },
  {
    desc: '手动、分类、品牌、标签商品',
    icon: 'lucide:shopping-bag',
    label: '商品分组',
    type: 'productGroup',
  },
  {
    desc: '独立图文跳转卡片',
    icon: 'lucide:mouse-pointer-click',
    label: '入口卡片',
    type: 'entryCard',
  },
  {
    desc: '内容区块标题和更多链接',
    icon: 'lucide:heading',
    label: '标题栏',
    type: 'title',
  },
  {
    desc: '简单富文本内容',
    icon: 'lucide:file-text',
    label: '富文本',
    type: 'richText',
  },
  {
    desc: '上下留白',
    icon: 'lucide:panel-top-open',
    label: '空白间距',
    type: 'spacing',
  },
  {
    desc: '内容分隔线',
    icon: 'lucide:minus',
    label: '分割线',
    type: 'divider',
  },
];

const PROFILE_MODULES = [
  {
    desc: '头像、昵称、手机号',
    icon: 'lucide:user-round',
    label: '用户信息',
    type: 'userInfo',
  },
  {
    desc: '待付款、待发货等订单入口',
    icon: 'lucide:package-check',
    label: '订单入口',
    type: 'orderEntry',
  },
  {
    desc: '余额和积分卡片',
    icon: 'lucide:wallet',
    label: '钱包卡片',
    type: 'walletEntry',
  },
  {
    desc: '常用服务入口',
    icon: 'lucide:layout-list',
    label: '服务菜单',
    type: 'serviceMenu',
  },
  {
    desc: '管理员自定义入口',
    icon: 'lucide:menu-square',
    label: '自定义菜单',
    type: 'customMenu',
  },
];

const PROFILE_TYPE_ALIAS: Record<string, string> = {
  orderShortcut: 'orderEntry',
  profileHeader: 'userInfo',
  walletCard: 'walletEntry',
};

const HOME_TYPE_ALIAS: Record<string, string> = {
  categoryEntry: 'entryCard',
};

const DEMO_ASSET_BASE_URL = `${
  new URL(import.meta.env.VITE_GLOB_API_URL || '/', window.location.origin)
    .origin
}/static/demo/`;

const createDemoAssetFile = (url: string, name: string) => ({
  full_url: `${DEMO_ASSET_BASE_URL}${name}`,
  name,
  url,
});

const DEFAULT_BANNER_IMAGE_BY_INDEX = [
  createDemoAssetFile('48', 'decorate-banner-market.png'),
  createDemoAssetFile('49', 'decorate-banner-member.png'),
  createDemoAssetFile('50', 'decorate-banner-home.png'),
];

const LEGACY_DEFAULT_BANNER_IDS = new Set(['6', '7', '8', '41']);
const LEGACY_DEFAULT_NAV_IDS = new Set([
  '15',
  '16',
  '20',
  '23',
  '40',
  '46',
  '47',
]);

const DEFAULT_BANNER_ITEMS = [
  {
    image: DEFAULT_BANNER_IMAGE_BY_INDEX[0],
    path: '/pages-sub/goods/list?is_recommend=1',
    title: '夏日好物限时满减',
  },
  {
    image: DEFAULT_BANNER_IMAGE_BY_INDEX[1],
    path: '/pages-sub/goods/list?sort=sales',
    title: '会员精选 每日上新',
  },
];

const DEFAULT_NAV_IMAGE_BY_KEY: Record<
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

const DEFAULT_CUBE_ITEMS = [
  {
    image: createDemoAssetFile('57', 'decorate-cube-new.png'),
    path: '/pages-sub/goods/list?sort=newest',
    title: '新品上架',
  },
  {
    image: createDemoAssetFile('58', 'decorate-cube-picks.png'),
    path: '/pages-sub/goods/list?is_recommend=1',
    title: '精选榜单',
  },
  {
    image: createDemoAssetFile('59', 'decorate-cube-member.png'),
    path: '/pages-sub/goods/list?sort=sales',
    title: '会员专享',
  },
  {
    image: createDemoAssetFile('60', 'decorate-cube-sale.png'),
    path: '/pages-sub/goods/list?is_hot=1',
    title: '限时满减',
  },
];

const getDefaultNavImage = (item: any, fallback = '') => {
  const key = String(item?.icon || item?.key || '').replace(/^lucide:/, '');
  const title = String(item?.title || item?.label || item?.text || '');
  if (key.includes('sparkles') || key.includes('beauty') || title === '美妆') {
    return DEFAULT_NAV_IMAGE_BY_KEY.beauty;
  }
  if (
    key.includes('shirt') ||
    key.includes('clothes') ||
    key.includes('menswear') ||
    title === '服饰'
  ) {
    return DEFAULT_NAV_IMAGE_BY_KEY.shirt;
  }
  if (
    key.includes('sofa') ||
    key.includes('home') ||
    key.includes('furniture') ||
    title === '家居'
  ) {
    return DEFAULT_NAV_IMAGE_BY_KEY.home;
  }
  if (key.includes('utensils') || key.includes('food') || title === '美食') {
    return DEFAULT_NAV_IMAGE_BY_KEY.food;
  }
  if (key.includes('dumbbell') || key.includes('sport') || title === '运动') {
    return DEFAULT_NAV_IMAGE_BY_KEY.sport;
  }
  if (key.includes('smartphone') || key.includes('phone') || title === '数码') {
    return DEFAULT_NAV_IMAGE_BY_KEY.phone;
  }
  return fallback;
};

const normalizeNavImageValue = (item: any, defaultItem: any) => {
  const image =
    item?.image ||
    item?.image_url ||
    item?.imageUrl ||
    item?.full_url ||
    item?.fullUrl ||
    '';
  const imageUrl =
    typeof image === 'object' ? String(image.url || image.asset_id || '') : '';
  if (
    (typeof image === 'string' && image.startsWith('data:image/svg')) ||
    LEGACY_DEFAULT_NAV_IDS.has(String(image || imageUrl))
  ) {
    return getDefaultNavImage(item, defaultItem.image);
  }
  return image || getDefaultNavImage(item, defaultItem.image);
};

const DEFAULT_NAV_ITEMS = [
  {
    icon: 'lucide:smartphone',
    image: DEFAULT_NAV_IMAGE_BY_KEY.phone,
    path: '/pages/category/index',
    title: '数码',
  },
  {
    icon: 'lucide:sparkles',
    image: DEFAULT_NAV_IMAGE_BY_KEY.beauty,
    path: '/pages/category/index',
    title: '美妆',
  },
  {
    icon: 'lucide:shirt',
    image: DEFAULT_NAV_IMAGE_BY_KEY.shirt,
    path: '/pages/category/index',
    title: '服饰',
  },
  {
    icon: 'lucide:sofa',
    image: DEFAULT_NAV_IMAGE_BY_KEY.home,
    path: '/pages/category/index',
    title: '家居',
  },
  {
    icon: 'lucide:utensils',
    image: DEFAULT_NAV_IMAGE_BY_KEY.food,
    path: '/pages/category/index',
    title: '美食',
  },
  {
    icon: 'lucide:dumbbell',
    image: DEFAULT_NAV_IMAGE_BY_KEY.sport,
    path: '/pages/category/index',
    title: '运动',
  },
];

const PRODUCT_SOURCE_OPTIONS = [
  { label: '手动商品', value: 'manual' },
  { label: '指定分类', value: 'category' },
  { label: '指定品牌', value: 'brand' },
  { label: '指定标签', value: 'tag' },
  { label: '推荐商品', value: 'recommend' },
  { label: '新品商品', value: 'new' },
  { label: '热销商品', value: 'hot' },
  { label: '综合筛选', value: 'filter' },
];

const PRODUCT_LAYOUT_OPTIONS = [
  { label: '双列卡片', value: 'grid' },
  { label: '横向滑动', value: 'scroll' },
  { label: '大图卡片', value: 'large' },
  { label: '紧凑列表', value: 'list' },
];

const PRODUCT_SORT_OPTIONS = [
  { label: '默认排序', value: 'default' },
  { label: '销量优先', value: 'sales_desc' },
  { label: '价格从低到高', value: 'price_asc' },
  { label: '价格从高到低', value: 'price_desc' },
  { label: '最新上架', value: 'newest' },
];

const DEFAULT_TABBAR_PREVIEW_ITEMS = [
  { id: 'preview-home', path: '/pages/index/index', text: '首页' },
  { id: 'preview-category', path: '/pages/category/index', text: '分类' },
  { id: 'preview-cart', path: '/pages/cart/index', text: '购物车' },
  { id: 'preview-profile', path: '/pages/profile/index', text: '我的' },
];

const resolveRouteSchemeType = (path: string): ClientDecorateApi.SchemeType => {
  if (path.includes('/client/decorate/tabbar')) return 'tabbar';
  if (path.includes('/client/decorate/profile')) return 'profile';
  return 'home';
};

const SCHEME_TYPE_META: Record<
  ClientDecorateApi.SchemeType,
  {
    cardDesc: string;
    currentPath: string;
    path: string;
    previewKind: 'home' | 'profile' | 'tabbar';
  }
> = {
  home: {
    cardDesc: '首页模块、轮播、导航和商品分组',
    currentPath: '/pages/index/index',
    path: '/pages/index/index',
    previewKind: 'home',
  },
  profile: {
    cardDesc: '个人信息、钱包、订单和服务入口',
    currentPath: '/pages/profile/index',
    path: '/pages/profile/index',
    previewKind: 'profile',
  },
  tabbar: {
    cardDesc: '底部导航项、图标、页面路径和选中态',
    currentPath: '/pages/index/index',
    path: '客户端底部导航',
    previewKind: 'tabbar',
  },
};

const route = useRoute();
const router = useRouter();
const viewMode = ref<'editor' | 'overview'>('overview');
const activeType = ref<ClientDecorateApi.SchemeType>(
  resolveRouteSchemeType(route.path),
);
const loading = ref(false);
const overviewLoading = ref(false);
const saving = ref(false);
const selectedIndex = ref(0);
const selectedSchemeId = ref<null | number>(null);
const schemeSettingsOpen = ref(false);
const schemeList = ref<ClientDecorateApi.SchemeItem[]>([]);
const themeList = ref<ClientThemeApi.ThemeItem[]>([]);
const previewCategoryTree = ref<GoodsCategoryApi.CategoryItem[]>([]);
const previewGoods = ref<GoodsApi.GoodsItem | null>(null);
const previewGoodsList = ref<GoodsApi.GoodsItem[]>([]);
const themePolicy = ref<ClientThemeApi.ThemePolicy>({
  ...DEFAULT_THEME_POLICY,
});
const overviewSchemes = reactive<
  Record<ClientDecorateApi.SchemeType, ClientDecorateApi.SchemeItem[]>
>({
  home: [],
  profile: [],
  tabbar: [],
});
const iconPrefix = ref('ant-design');
const mouseDrag = ref<null | {
  active: boolean;
  index?: number;
  kind: 'module' | 'palette';
  label: string;
  startX: number;
  startY: number;
  type?: string;
}>(null);
const dragDropIndex = ref<null | number>(null);
const dragPreview = reactive({
  label: '',
  visible: false,
  x: 0,
  y: 0,
});
const suppressPaletteClick = ref(false);
const DRAG_THRESHOLD = 8;
let dragMoveFrame: null | number = null;
let pendingDragEvent: MouseEvent | null = null;

const schemeForm = reactive({
  description: '',
  name: '',
  pageStyle: { ...DEFAULT_HOME_PAGE_STYLE },
  schema: [] as ModuleItem[],
  sort: 0,
  status: 1,
  tabbar_mode: 'native' as ClientDecorateApi.TabbarMode,
});

const getSchemeSchemaList = (
  scheme: ClientDecorateApi.SchemeItem | null | undefined,
  type: ClientDecorateApi.SchemeType,
) => {
  const schema = scheme?.schema as any;
  if (Array.isArray(schema)) return schema;
  if (type === 'tabbar') return schema?.items || [];
  if (type === 'profile') return schema?.modules || schema?.components || [];
  return schema?.components || schema?.modules || [];
};

const normalizeHomePageStyle = (value: any) => ({
  paddingX: Number(
    value?.paddingX ?? value?.padding_x ?? DEFAULT_HOME_PAGE_STYLE.paddingX,
  ),
  paddingY: Number(
    value?.paddingY ?? value?.padding_y ?? DEFAULT_HOME_PAGE_STYLE.paddingY,
  ),
});

const getSchemePageStyle = (
  scheme: ClientDecorateApi.SchemeItem | null | undefined,
) => normalizeHomePageStyle((scheme?.schema as any)?.pageStyle);

const getActiveSchemeByType = (type: ClientDecorateApi.SchemeType) =>
  overviewSchemes[type].find((item) => item.is_active === 1) ||
  overviewSchemes[type][0] ||
  null;

const getOverviewModules = (type: ClientDecorateApi.SchemeType) =>
  getSchemeSchemaList(getActiveSchemeByType(type), type).map(
    (item: ModuleItem, index: number) =>
      type === 'tabbar' ? item : normalizeEditorModule(item, index, type),
  );

const getThemeByType = (type: ClientThemeApi.ThemeType) =>
  themeList.value.find((item) => item.type === type && item.status === 1);

const getDefaultCustomTheme = () =>
  themeList.value.find(
    (item) =>
      item.id === themePolicy.value.default_theme_id &&
      item.status === 1 &&
      item.type === 'custom',
  );

const resolveCurrentTheme = () => {
  if (themePolicy.value.default_mode === 'custom') {
    return getDefaultCustomTheme() || getThemeByType('custom');
  }
  if (themePolicy.value.default_mode === 'dark') {
    return getThemeByType('dark');
  }
  return getThemeByType('light');
};

const activeTabMeta = computed(
  () =>
    SCHEME_TABS.find((item) => item.value === activeType.value) ||
    ({
      help: '配置首页组件、排序和内容',
      label: '首页装修',
      value: 'home',
    } as const),
);

const activeTypeLabel = computed(() => activeTabMeta.value.label);

const selectedModule = computed<ModuleItem | null>(
  () => schemeForm.schema[selectedIndex.value] || null,
);

const componentPalette = computed(() => {
  if (activeType.value === 'profile') return PROFILE_MODULES;
  if (activeType.value === 'tabbar') {
    return [
      {
        desc: '底部导航按钮',
        icon: 'lucide:panel-bottom',
        label: '导航项',
        type: 'tabbarItem',
      },
    ];
  }
  return HOME_MODULES;
});

const paletteGroups = computed(() => {
  const pick = (types: string[]) =>
    componentPalette.value.filter((item) => types.includes(item.type));
  if (activeType.value === 'tabbar') {
    return [{ items: componentPalette.value, title: '底部导航' }];
  }
  if (activeType.value === 'profile') {
    return [
      { items: pick(['userInfo', 'walletEntry']), title: '基础组件' },
      {
        items: pick(['orderEntry', 'serviceMenu', 'customMenu']),
        title: '入口组件',
      },
    ].filter((group) => group.items.length > 0);
  }
  return [
    {
      items: pick(['search', 'banner', 'imageCube']),
      title: '基础组件',
    },
    { items: pick(['navGrid']), title: '图标分类' },
    {
      items: pick(['productGroup', 'entryCard', 'title', 'richText']),
      title: '内容组件',
    },
    { items: pick(['spacing', 'divider']), title: '工具组件' },
  ].filter((group) => group.items.length > 0);
});

const currentScheme = computed(
  () =>
    schemeList.value.find((item) => item.id === selectedSchemeId.value) || null,
);

const isSystemScheme = computed(() => currentScheme.value?.is_system === 1);
const isReadonlyScheme = computed(() => isSystemScheme.value);

const schemeSummary = computed(() => ({
  activeName:
    schemeList.value.find((item) => item.is_active === 1)?.name || '未设置',
  count: schemeList.value.length,
}));

const overviewActiveSchemes = computed(
  () => overviewSchemes[activeType.value] || [],
);

const overviewActiveName = computed(
  () =>
    overviewActiveSchemes.value.find((item) => item.is_active === 1)?.name ||
    '未设置',
);

const schemeSelectOptions = computed(() =>
  schemeList.value.map((item) => ({
    label: item.is_active === 1 ? `${item.name}（当前使用）` : item.name,
    value: item.id,
  })),
);

const currentTheme = computed(() => resolveCurrentTheme());

const currentThemeTokens = computed(() => ({
  ...DEFAULT_THEME_TOKENS,
  ...currentTheme.value?.tokens,
}));

const currentThemeName = computed(() => {
  if (currentTheme.value?.name) return currentTheme.value.name;
  const modeName: Record<ClientThemeApi.ThemeMode, string> = {
    custom: '自定义主题',
    dark: '深色主题',
    light: '浅色主题',
    system: '跟随系统',
  };
  return modeName[themePolicy.value.default_mode] || '默认主题';
});

const currentThemeSwatches = computed(() =>
  [
    { key: 'colorPrimary', label: '主色' },
    { key: 'colorPrimaryLight', label: '辅助色' },
    { key: 'colorPrice', label: '价格色' },
  ].map((item) => ({
    ...item,
    value:
      currentThemeTokens.value[item.key as keyof typeof DEFAULT_THEME_TOKENS] ||
      DEFAULT_THEME_TOKENS.colorPrimary,
  })),
);

const overviewTabbarItems = computed(() => getOverviewModules('tabbar'));

const getSchemeTypeLabel = (type: ClientDecorateApi.SchemeType) =>
  SCHEME_TABS.find((item) => item.value === type)?.label || type;

const getSchemePreviewMeta = (scheme: ClientDecorateApi.SchemeItem) =>
  SCHEME_TYPE_META[scheme.type] || SCHEME_TYPE_META.home;

const getOverviewSchemeModules = (scheme: ClientDecorateApi.SchemeItem) => {
  if (scheme.type === 'tabbar') return [];
  return getSchemeSchemaList(scheme, scheme.type).map(
    (item: ModuleItem, index: number) =>
      normalizeEditorModule(item, index, scheme.type),
  );
};

const getOverviewSchemeTabbarItems = (scheme: ClientDecorateApi.SchemeItem) =>
  scheme.type === 'tabbar'
    ? getSchemeSchemaList(scheme, 'tabbar')
    : overviewTabbarItems.value;

const getOverviewSchemeModuleNames = (scheme: ClientDecorateApi.SchemeItem) => {
  const modules = getSchemeSchemaList(scheme, scheme.type);
  return modules.map((item: ModuleItem) =>
    scheme.type === 'tabbar'
      ? item.text || item.label || item.title || '导航项'
      : item.title ||
        item.label ||
        getModuleLabel(String(item.type || item.component || ''), scheme.type),
  );
};

const getOverviewSchemeModuleSummary = (
  scheme: ClientDecorateApi.SchemeItem,
) => {
  const names = getOverviewSchemeModuleNames(scheme);
  if (names.length === 0) return '暂无模块';
  const visibleNames = names.slice(0, 3).join(' / ');
  return names.length > 3
    ? `${visibleNames} 等 ${names.length} 个`
    : visibleNames;
};

const getOverviewSchemeModuleTitle = (scheme: ClientDecorateApi.SchemeItem) => {
  const names = getOverviewSchemeModuleNames(scheme);
  return names.length === 0 ? '暂无模块' : names.join(' / ');
};

const getOverviewSchemeUpdateLabel = (scheme: ClientDecorateApi.SchemeItem) =>
  scheme.update_time ? `上次修改：${scheme.update_time}` : '暂无更新时间';

const isReadonlyOverviewScheme = (scheme: ClientDecorateApi.SchemeItem) =>
  scheme.is_system === 1;

const selectedModuleId = computed(() => selectedModule.value?.id || null);

const createId = (prefix: string) =>
  `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

const cloneSerializableValue = (
  value: any,
  seen = new WeakMap<object, any>(),
): any => {
  const raw = value && typeof value === 'object' ? toRaw(value) : value;
  if (raw === null || typeof raw === 'string') return raw;
  if (typeof raw === 'number' || typeof raw === 'boolean') return raw;
  if (typeof raw === 'bigint') return String(raw);
  if (typeof raw !== 'object') return undefined;
  if (typeof window !== 'undefined' && raw === window) return undefined;
  if (typeof Element !== 'undefined' && raw instanceof Element)
    return undefined;
  if (seen.has(raw)) return seen.get(raw);
  if (Array.isArray(raw)) {
    const result: any[] = [];
    seen.set(raw, result);
    raw.forEach((item) => {
      const cloned = cloneSerializableValue(item, seen);
      if (cloned !== undefined) result.push(cloned);
    });
    return result;
  }
  if (raw instanceof Date) return raw.toISOString();
  if (Object.prototype.toString.call(raw) !== '[object Object]') {
    return undefined;
  }
  const result: Record<string, any> = {};
  seen.set(raw, result);
  Object.entries(raw).forEach(([key, item]) => {
    const cloned = cloneSerializableValue(item, seen);
    if (cloned !== undefined) result[key] = cloned;
  });
  return result;
};

const clone = <T,>(value: T): T => cloneSerializableValue(value) as T;

const normalizeProfileModuleType = (type: string) =>
  PROFILE_TYPE_ALIAS[type] || type;

const normalizeHomeModuleType = (type: string) => HOME_TYPE_ALIAS[type] || type;

const normalizeModuleTypeByScheme = (
  type: string,
  schemeType = activeType.value,
) =>
  schemeType === 'profile'
    ? normalizeProfileModuleType(type)
    : normalizeHomeModuleType(type);

const getModuleLabel = (type: string, schemeType = activeType.value) =>
  [...HOME_MODULES, ...PROFILE_MODULES].find(
    (item) => item.type === normalizeModuleTypeByScheme(type, schemeType),
  )?.label || (type === 'tabbarItem' ? '导航项' : type);

const clampNumber = (
  value: unknown,
  fallback: number,
  min: number,
  max: number,
) => {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return fallback;
  return Math.max(min, Math.min(numberValue, max));
};

const normalizeBooleanValue = (value: unknown, fallback = false) => {
  if (value === undefined || value === null || value === '') return fallback;
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value === 1;
  if (typeof value === 'string') {
    if (['1', 'true'].includes(value.toLowerCase())) return true;
    if (['0', 'false'].includes(value.toLowerCase())) return false;
  }
  return Boolean(value);
};

const normalizeTitleAlign = (value: unknown) =>
  ['center', 'left', 'right'].includes(String(value)) ? String(value) : 'left';

const normalizeUploadValue = (value: any): any => {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeUploadValue(item));
  }
  if (value && typeof value === 'object') {
    if ('url' in value && 'name' in value) {
      return (value as UploadFileInfo).url || '';
    }
    return Object.fromEntries(
      Object.entries(value).map(([key, item]) => [
        key,
        normalizeUploadValue(item),
      ]),
    );
  }
  return value;
};

const getSchemeStatusLabel = (scheme: ClientDecorateApi.SchemeItem) => {
  if (scheme.is_active === 1) return '当前使用';
  return scheme.status === 1 ? '启用' : '禁用';
};

const getSchemeStatusColor = (scheme: ClientDecorateApi.SchemeItem) => {
  if (scheme.is_active === 1) return 'green';
  return scheme.status === 1 ? 'blue' : 'default';
};

const selectModuleFromPreview = (module: ModuleItem) => {
  const index = schemeForm.schema.findIndex((item) => item.id === module.id);
  if (index !== -1) selectedIndex.value = index;
};

const getTabbarPreviewItems = () =>
  activeType.value === 'tabbar' && schemeForm.schema.length > 0
    ? schemeForm.schema
    : DEFAULT_TABBAR_PREVIEW_ITEMS;

const normalizeBannerImageValue = (value: any, index: number) => {
  const url =
    value && typeof value === 'object'
      ? String(value.url || value.asset_id || '')
      : String(value || '');
  if (typeof value === 'string' && value.startsWith('data:image/svg')) {
    return DEFAULT_BANNER_IMAGE_BY_INDEX[
      index % DEFAULT_BANNER_IMAGE_BY_INDEX.length
    ];
  }
  if (LEGACY_DEFAULT_BANNER_IDS.has(url)) {
    return DEFAULT_BANNER_IMAGE_BY_INDEX[
      index % DEFAULT_BANNER_IMAGE_BY_INDEX.length
    ];
  }
  if (
    value?.image &&
    typeof value.image === 'string' &&
    value.image.startsWith('data:image/svg')
  ) {
    return DEFAULT_BANNER_IMAGE_BY_INDEX[
      index % DEFAULT_BANNER_IMAGE_BY_INDEX.length
    ];
  }
  return value;
};

const normalizeEditorConfig = (
  type: string,
  rawConfig: Record<string, any>,
) => {
  const config = clone(rawConfig || {});
  config.widthPercent = Number(
    config.widthPercent ?? config.width_percent ?? 100,
  );
  config.marginTop = Number(config.marginTop ?? config.margin_top ?? 0);
  config.marginBottom = Number(
    config.marginBottom ?? config.margin_bottom ?? 0,
  );
  config.radius =
    config.radius === undefined || config.radius === null
      ? 0
      : Number(config.radius);
  config.padding =
    config.padding === undefined || config.padding === null
      ? 0
      : Number(config.padding);
  config.paddingY = Number(
    config.paddingY ?? config.padding_y ?? config.padding,
  );
  config.paddingX = Number(
    config.paddingX ?? config.padding_x ?? config.padding,
  );
  config.background = config.background || '';
  if (type === 'banner') {
    let sourceItems = [];
    if (Array.isArray(config.items) && config.items.length > 0) {
      sourceItems = config.items;
    } else if (Array.isArray(config.images) && config.images.length > 0) {
      sourceItems = config.images;
    } else if (Array.isArray(config.list)) {
      sourceItems = config.list;
    }

    const bannerSourceItems =
      Array.isArray(sourceItems) && sourceItems.length > 0
        ? sourceItems
        : clone(DEFAULT_BANNER_ITEMS);
    const bannerItems = bannerSourceItems.map((item: any, index: number) => {
      if (typeof item === 'string') {
        return {
          id: createId('banner_item'),
          image: normalizeBannerImageValue(item, index),
          path: '',
          title: `轮播图${index + 1}`,
        };
      }
      const image = normalizeBannerImageValue(
        item.image ||
          item.full_url ||
          item.fullUrl ||
          item.image_url ||
          item.imageUrl ||
          item.src ||
          item.cover ||
          item.url ||
          '',
        index,
      );
      return {
        ...item,
        id: item.id || item.key || createId('banner_item'),
        image,
        path:
          item.path ||
          item.target_path ||
          item.link ||
          item.href ||
          item.jump_url ||
          item.jumpUrl ||
          '',
        title: item.title || item.label || `轮播图${index + 1}`,
      };
    });
    config.items = bannerItems;
    config.images = bannerItems;
    config.list = bannerItems;
    config.height = Number(config.height || 314);
    config.radius = Number(rawConfig.radius ?? rawConfig.border_radius ?? 24);
    config.padding = Number(rawConfig.padding ?? rawConfig.padding_y ?? 0);
    config.paddingY = Number(
      rawConfig.paddingY ?? rawConfig.padding_y ?? config.padding,
    );
    config.paddingX = Number(
      rawConfig.paddingX ?? rawConfig.padding_x ?? config.padding,
    );
    config.interval = Number(config.interval || 3000);
  }
  if (type === 'title') {
    config.title = config.title || config.text || '标题';
    config.sub_title = config.sub_title || config.subtitle || '';
    config.more_path = config.more_path || config.moreUrl || '';
    config.more_text = config.more_text || config.moreText || '查看全部';
    config.title_align = normalizeTitleAlign(
      config.title_align || config.titleAlign || config.text_align,
    );
    config.title_font_size = clampNumber(
      config.title_font_size || config.titleFontSize || config.font_size,
      32,
      18,
      72,
    );
    config.title_bold = normalizeBooleanValue(
      config.title_bold ?? config.titleBold,
      true,
    );
    config.title_italic = normalizeBooleanValue(
      config.title_italic ?? config.titleItalic,
    );
    config.title_color = config.title_color || config.titleColor || '';
    config.sub_font_size = clampNumber(
      config.sub_font_size || config.subFontSize,
      24,
      16,
      56,
    );
    config.sub_bold = normalizeBooleanValue(config.sub_bold ?? config.subBold);
    config.sub_italic = normalizeBooleanValue(
      config.sub_italic ?? config.subItalic,
    );
    config.sub_color = config.sub_color || config.subColor || '';
  }
  if (type === 'entryCard') {
    config.title = config.title || config.text || '入口标题';
    config.subtitle = config.subtitle || config.sub_title || '';
    config.path =
      config.path ||
      config.target_path ||
      config.link ||
      config.link_url ||
      config.url ||
      '';
    config.icon = config.icon || 'lucide:mouse-pointer-click';
    config.icon_mode = config.icon_mode || config.iconMode || 'icon';
    config.icon_color = config.icon_color || config.iconColor || '';
    config.icon_background =
      config.icon_background || config.iconBackground || '';
    config.icon_image = config.icon_image || config.iconImage || '';
    config.background_image =
      config.background_image || config.backgroundImage || '';
    config.show_arrow =
      config.show_arrow === undefined ? true : Boolean(config.show_arrow);
  }
  if (type === 'richText') {
    config.content = config.content || config.html || '';
    config.radius = Number(config.radius ?? 24);
    config.padding = Number(config.padding ?? 24);
    config.paddingY = Number(config.paddingY ?? config.padding);
    config.paddingX = Number(config.paddingX ?? config.padding);
  }
  if (type === 'productGroup') {
    if (config.source && typeof config.source === 'object') {
      config.filters = config.source.filters || config.filters || {};
      config.source = config.source.mode || 'filter';
    }
    config.source = config.source || 'filter';
    config.layout = config.layout || 'grid';
    config.limit = Number(config.limit || config.page_size || 8);
    config.preview_goods = Array.isArray(config.preview_goods)
      ? config.preview_goods
      : [];
    config.sort_by = config.sort_by || config.sortBy || 'default';
  }
  if (type === 'navGrid') {
    const sourceItems =
      Array.isArray(config.items) && config.items.length > 0
        ? config.items
        : clone(DEFAULT_NAV_ITEMS);
    config.columns = Number(config.columns || 6);
    config.items = sourceItems.map((item: any, index: number) => {
      const defaultItem = DEFAULT_NAV_ITEMS[index % DEFAULT_NAV_ITEMS.length]!;
      return {
        ...defaultItem,
        ...(item && typeof item === 'object' ? item : {}),
        image: normalizeNavImageValue(item, defaultItem),
        path: item?.path || item?.url || defaultItem.path,
        title: item?.title || item?.label || item?.text || defaultItem.title,
      };
    });
  }
  return config;
};

const normalizeEditorModule = (
  item: ModuleItem,
  index: number,
  schemeType = activeType.value,
): ModuleItem => {
  const rawType = String(item.type || item.component || '');
  const type = normalizeModuleTypeByScheme(rawType, schemeType);
  const rawTitle = item.title || item.label || '';
  const config = normalizeEditorConfig(
    type,
    item.config || item.props || item.data || {},
  );
  return {
    ...item,
    config,
    enabled: item.enabled !== false && item.visible !== false,
    id: item.id || item.key || createId(type || 'module'),
    title:
      rawTitle === '分类入口'
        ? getModuleLabel(type, schemeType)
        : rawTitle || getModuleLabel(type, schemeType),
    type,
    sort: item.sort ?? index,
  };
};

const defaultHomeConfig = (
  type: ClientDecorateApi.ComponentType,
): Record<string, any> => {
  const withStyle = (config: Record<string, any>) => ({
    background: '',
    marginBottom: 0,
    marginTop: 0,
    padding: 0,
    radius: 0,
    widthPercent: 100,
    ...config,
  });
  const defaults: Partial<
    Record<ClientDecorateApi.ComponentType, Record<string, any>>
  > = {
    banner: withStyle({
      height: 314,
      images: clone(DEFAULT_BANNER_ITEMS),
      items: clone(DEFAULT_BANNER_ITEMS),
      interval: 3000,
      list: clone(DEFAULT_BANNER_ITEMS),
      marginBottom: 16,
      marginTop: 12,
      radius: 24,
      subtitle: '新人首单立减，爆款商品限时优惠',
      title: '夏日好物限时满减',
    }),
    entryCard: withStyle({
      background_image: createDemoAssetFile(
        '61',
        'decorate-entry-category.png',
      ),
      icon: 'lucide:folder-tree',
      icon_background: '',
      icon_color: '',
      icon_image: createDemoAssetFile('61', 'decorate-entry-category.png'),
      icon_mode: 'image',
      padding: 24,
      path: '/pages/category/index',
      radius: 24,
      show_arrow: true,
      subtitle: '查看全部商品分类',
      title: '热门分类',
    }),
    divider: withStyle({ color: '', margin: 24, style: 'solid' }),
    imageCube: withStyle({
      images: clone(DEFAULT_CUBE_ITEMS),
      items: clone(DEFAULT_CUBE_ITEMS),
      list: clone(DEFAULT_CUBE_ITEMS),
      layout: 'four',
      radius: 20,
      titles: ['精选榜单', '本周值得买', '会员专享', '新品榜'],
    }),
    navGrid: withStyle({
      columns: 3,
      items: clone(DEFAULT_NAV_ITEMS),
      marginTop: 4,
      marginBottom: 18,
      paddingX: 20,
      paddingY: 20,
      radius: 24,
      widthPercent: 100,
    }),
    productGroup: withStyle({
      brand_id: null,
      category_id: null,
      ids: '',
      layout: 'grid',
      limit: 8,
      moreText: '查看全部',
      more_path: '/pages-sub/goods/list?is_recommend=1',
      marginTop: 4,
      paddingX: 20,
      paddingY: 20,
      radius: 24,
      sort_by: 'default',
      source: 'recommend',
      subtitle: '精选好物实时更新',
      tag_ids: '',
      title: '精选好物',
      widthPercent: 100,
    }),
    richText: withStyle({
      background: '',
      content:
        '<p><strong>新人专享福利</strong></p><p>下单即享满减优惠，支持图片、文字和活动说明。</p>',
      padding: 24,
      radius: 24,
    }),
    search: withStyle({
      marginBottom: 8,
      marginTop: 4,
      paddingX: 20,
      paddingY: 12,
      placeholder: '搜索商品、分类或品牌',
      radius: 36,
      target_path: '/pages-sub/goods/list',
      widthPercent: 100,
    }),
    spacing: withStyle({ height: 32 }),
    title: withStyle({
      marginBottom: 8,
      marginTop: 4,
      more_path: '/pages-sub/goods/list?is_recommend=1',
      more_text: '查看全部',
      paddingX: 30,
      paddingY: 4,
      sub_title: '严选好物正在热卖',
      sub_bold: false,
      sub_color: '',
      sub_font_size: 22,
      sub_italic: false,
      title: '人气推荐',
      title_align: 'left',
      title_bold: true,
      title_color: '',
      title_font_size: 34,
      title_italic: false,
      widthPercent: 100,
    }),
  };
  return clone(defaults[type] || {});
};

const defaultProfileItems = () => [
  {
    id: createId('profile_item'),
    icon: 'ant-design:customer-service-outlined',
    path: '',
    title: '联系客服',
  },
];

const defaultProfileConfig = (type: string): Record<string, any> => {
  const withStyle = (config: Record<string, any>) => ({
    background: '',
    marginBottom: 0,
    marginTop: 0,
    radius: 24,
    widthPercent: 100,
    ...config,
  });
  const defaults: Record<string, Record<string, any>> = {
    customMenu: withStyle({ columns: 4, items: defaultProfileItems() }),
    orderEntry: withStyle({
      items: [
        {
          id: createId('profile_item'),
          icon: 'ant-design:wallet-outlined',
          path: '/pages-sub/order/list?status=10',
          title: '待付款',
        },
        {
          id: createId('profile_item'),
          icon: 'ant-design:car-outlined',
          path: '/pages-sub/order/list?status=20',
          title: '待发货',
        },
      ],
      radius: 24,
    }),
    serviceMenu: withStyle({
      columns: 4,
      items: defaultProfileItems(),
      radius: 24,
      title: '我的服务',
    }),
    userInfo: withStyle({ radius: 0, show_level: true, show_mobile: true }),
    walletEntry: withStyle({ show_balance: true, show_points: true }),
  };
  const config = defaults[normalizeProfileModuleType(type)] ||
    defaults.serviceMenu || { columns: 4, items: [] };
  return clone(config);
};

const defaultTabbarItem = (): ModuleItem => ({
  icon: 'ant-design:appstore-outlined',
  icon_mode: 'icon',
  id: createId('tabbar'),
  path: '',
  selected_icon: 'ant-design:appstore-filled',
  selected_icon_mode: 'icon',
  text: '导航',
});

const defaultTabbarItems = (): ModuleItem[] => [
  {
    ...defaultTabbarItem(),
    icon: 'ant-design:home-outlined',
    path: '/pages/index/index',
    selected_icon: 'ant-design:home-filled',
    text: '首页',
  },
  {
    ...defaultTabbarItem(),
    icon: 'ant-design:user-outlined',
    path: '/pages/profile/index',
    selected_icon: 'ant-design:user-filled',
    text: '我的',
  },
];

const resetSchemeForm = (type = activeType.value) => {
  selectedSchemeId.value = null;
  selectedIndex.value = 0;
  Object.assign(schemeForm, {
    description: '',
    name: `${activeTypeLabel.value}方案`,
    pageStyle: { ...DEFAULT_HOME_PAGE_STYLE },
    schema: type === 'tabbar' ? defaultTabbarItems() : [],
    sort: 0,
    status: 1,
    tabbar_mode: 'native',
  });
};

const ensureSelectedIndex = () => {
  if (selectedIndex.value >= schemeForm.schema.length) {
    selectedIndex.value = Math.max(0, schemeForm.schema.length - 1);
  }
};

const warnReadonlyScheme = () => {
  if (!isReadonlyScheme.value) return false;
  message.info('系统默认方案不能直接修改，请先复制一份再编辑');
  return true;
};

const loadThemes = async () => {
  try {
    const [themes, policy] = await Promise.all([
      getClientThemeListApi({ limit: 100, page: 1, status: 1 }),
      getClientThemePolicyApi(),
    ]);
    themeList.value = themes.list || [];
    themePolicy.value = {
      ...DEFAULT_THEME_POLICY,
      ...policy,
    };
  } catch (error) {
    console.error('加载客户端主题失败:', error);
  }
};

const loadPreviewBusinessData = async () => {
  try {
    const [categories, goods] = await Promise.all([
      getGoodsCategoryTreeApi({ status: 1 }),
      getGoodsListApi({ is_on_sale: 1, limit: 8, page: 1, status: 1 }),
    ]);
    previewCategoryTree.value = Array.isArray(categories) ? categories : [];
    previewGoodsList.value = goods.list || [];
    previewGoods.value = previewGoodsList.value[0] || null;
  } catch (error) {
    console.error('加载装修业务预览数据失败:', error);
    previewCategoryTree.value = [];
    previewGoodsList.value = [];
    previewGoods.value = null;
  }
};

const loadOverviewSchemes = async () => {
  overviewLoading.value = true;
  try {
    const result = await getClientDecorateSchemeListApi({
      limit: 100,
      page: 1,
      type: activeType.value,
    });
    overviewSchemes[activeType.value] = result.list || [];
  } catch (error) {
    console.error('加载装修卡片失败:', error);
    message.error('加载装修卡片失败');
  } finally {
    overviewLoading.value = false;
  }
};

const loadSchemeDetail = async (id: number) => {
  const detail = await getClientDecorateSchemeInfoApi(id);
  selectedSchemeId.value = detail.id;
  const schema = getSchemeSchemaList(detail, activeType.value);

  Object.assign(schemeForm, {
    description: detail.description || '',
    name: detail.name,
    pageStyle:
      activeType.value === 'home'
        ? getSchemePageStyle(detail)
        : clone(DEFAULT_HOME_PAGE_STYLE),
    schema:
      activeType.value === 'tabbar'
        ? clone(schema)
        : clone(schema).map((item: ModuleItem, index: number) =>
            normalizeEditorModule(item, index, activeType.value),
          ),
    sort: detail.sort || 0,
    status: detail.status ?? 1,
    tabbar_mode: detail.tabbar_mode || 'native',
  });
  selectedIndex.value = 0;
};

const loadSchemes = async () => {
  loading.value = true;
  try {
    const result = await getClientDecorateSchemeListApi({
      limit: 100,
      page: 1,
      type: activeType.value,
    });
    schemeList.value = result.list || [];
    overviewSchemes[activeType.value] = schemeList.value;
    const next =
      schemeList.value.find((item) => item.id === selectedSchemeId.value) ||
      schemeList.value.find((item) => item.is_active === 1) ||
      schemeList.value[0];
    if (next) {
      await loadSchemeDetail(next.id);
    } else {
      resetSchemeForm();
    }
  } catch (error) {
    console.error('加载装修方案失败:', error);
    message.error('加载装修方案失败');
  } finally {
    loading.value = false;
  }
};

const refreshOverview = async () => {
  await Promise.all([
    loadThemes(),
    loadOverviewSchemes(),
    loadPreviewBusinessData(),
  ]);
};

const openEditor = async (
  type: ClientDecorateApi.SchemeType,
  schemeId?: number,
) => {
  activeType.value = type;
  viewMode.value = 'editor';
  selectedSchemeId.value = schemeId ?? null;
  selectedIndex.value = 0;
  await loadSchemes();
};

const handleOverviewCreate = async () => {
  viewMode.value = 'editor';
  selectedSchemeId.value = null;
  selectedIndex.value = 0;
  await loadSchemes();
  resetSchemeForm(activeType.value);
};

const handleOverviewEdit = async (scheme: ClientDecorateApi.SchemeItem) => {
  await openEditor(scheme.type, scheme.id);
};

const handleOverviewCopy = async (scheme: ClientDecorateApi.SchemeItem) => {
  await copyClientDecorateSchemeApi(scheme.id);
  message.success('复制成功');
  await loadOverviewSchemes();
};

const handleOverviewActivate = async (scheme: ClientDecorateApi.SchemeItem) => {
  if (scheme.is_active === 1) return;
  await activateClientDecorateSchemeApi(scheme.id);
  message.success('已设为当前');
  await loadOverviewSchemes();
};

const handleOverviewDelete = (scheme: ClientDecorateApi.SchemeItem) => {
  if (isReadonlyOverviewScheme(scheme)) {
    message.info('系统默认方案不能直接删除，请先复制一份再维护');
    return;
  }
  Modal.confirm({
    content: `确定要删除方案"${scheme.name}"吗？`,
    onOk: async () => {
      await deleteClientDecorateSchemeApi(scheme.id);
      message.success('删除成功');
      await loadOverviewSchemes();
    },
  });
};

const handleBackOverview = async () => {
  viewMode.value = 'overview';
  selectedSchemeId.value = null;
  selectedIndex.value = 0;
  await refreshOverview();
};

const handleSelectScheme = async (id: number) => {
  if (!id) return;
  selectedSchemeId.value = Number(id);
  await loadSchemeDetail(Number(id));
};

const handleNewScheme = () => {
  resetSchemeForm();
};

const openSchemeSettings = () => {
  schemeSettingsOpen.value = true;
};

const normalizeSchemaForClient = (
  schema: ModuleItem[],
): ClientDecorateApi.SchemeSchema => {
  if (activeType.value === 'tabbar') {
    return stripRuntimePreviewFields(normalizeUploadValue(clone(schema)));
  }

  const modules = stripRuntimePreviewFields(
    normalizeUploadValue(
      clone(schema).map((item: ModuleItem, index: number) => {
        const props = {
          ...(item.props && typeof item.props === 'object' ? item.props : {}),
          ...(item.config && typeof item.config === 'object'
            ? item.config
            : {}),
          ...(item.data && typeof item.data === 'object' ? item.data : {}),
        };

        return {
          enabled: item.enabled !== false,
          id: item.id || item.key || createId(item.type || 'module'),
          props,
          sort: item.sort ?? index,
          title: item.title || getModuleLabel(item.type, activeType.value),
          type: item.type,
        };
      }),
    ),
  ) as ClientDecorateApi.DecorationModule[] | ClientDecorateApi.ProfileModule[];

  if (activeType.value === 'home') {
    return {
      components: modules as ClientDecorateApi.DecorationModule[],
      modules: modules as ClientDecorateApi.DecorationModule[],
      pageStyle: normalizeHomePageStyle(schemeForm.pageStyle),
    };
  }

  return modules as ClientDecorateApi.SchemeSchema;
};

const buildSaveData = (): ClientDecorateApi.SaveParams => ({
  description: schemeForm.description || null,
  name: schemeForm.name,
  schema: normalizeSchemaForClient(schemeForm.schema),
  sort: schemeForm.sort,
  status: schemeForm.status,
  tabbar_mode: schemeForm.tabbar_mode,
  type: activeType.value,
});

const stripRuntimePreviewFields = (value: any): any => {
  if (Array.isArray(value)) {
    value.forEach((item) => stripRuntimePreviewFields(item));
    return value;
  }

  if (value && typeof value === 'object') {
    delete value.preview_goods;
    delete value.previewGoods;
    for (const item of Object.values(value)) {
      stripRuntimePreviewFields(item);
    }
  }

  return value;
};

const validateBeforeSave = () => {
  if (!schemeForm.name.trim()) {
    message.warning('请输入方案名称');
    return false;
  }
  if (activeType.value === 'tabbar') {
    if (schemeForm.schema.length < 2 || schemeForm.schema.length > 5) {
      message.warning('底部导航需要配置 2-5 项');
      return false;
    }
    const invalid = schemeForm.schema.some((item) => !item.text || !item.path);
    if (invalid) {
      message.warning('请完善底部导航名称和页面路径');
      return false;
    }
  }
  return true;
};

const handleSave = async () => {
  if (warnReadonlyScheme()) return;
  if (!validateBeforeSave()) return;
  saving.value = true;
  try {
    const data = buildSaveData();
    if (selectedSchemeId.value) {
      await updateClientDecorateSchemeApi(selectedSchemeId.value, data);
      message.success(
        currentScheme.value?.is_active === 1
          ? '保存成功，客户端已更新'
          : '保存成功，设为当前后客户端生效',
      );
    } else {
      const result = await createClientDecorateSchemeApi(data);
      selectedSchemeId.value = result.id;
      message.success('创建成功，设为当前后客户端生效');
    }
    await loadSchemes();
  } catch (error) {
    console.error('保存装修方案失败:', error);
  } finally {
    saving.value = false;
  }
};

const handleCopy = async () => {
  if (!selectedSchemeId.value) return;
  await copyClientDecorateSchemeApi(selectedSchemeId.value);
  message.success('复制成功');
  await loadSchemes();
};

const handleActivate = async () => {
  if (!selectedSchemeId.value) return;
  if (currentScheme.value?.is_active === 1) return;
  await activateClientDecorateSchemeApi(selectedSchemeId.value);
  message.success('已激活');
  await loadSchemes();
};

const handleDelete = () => {
  if (!selectedSchemeId.value) return;
  if (warnReadonlyScheme()) return;
  Modal.confirm({
    content: `确定要删除方案"${schemeForm.name}"吗？`,
    onOk: async () => {
      await deleteClientDecorateSchemeApi(selectedSchemeId.value!);
      message.success('删除成功');
      selectedSchemeId.value = null;
      await loadSchemes();
    },
  });
};

const addModuleByType = (type: string, insertIndex?: number) => {
  if (warnReadonlyScheme()) return;
  const moduleType = normalizeModuleTypeByScheme(type);
  let item: ModuleItem;
  if (activeType.value === 'tabbar') {
    if (schemeForm.schema.length >= 5) {
      message.warning('底部导航最多 5 项');
      return;
    }
    item = defaultTabbarItem();
  } else if (activeType.value === 'profile') {
    item = {
      config: defaultProfileConfig(moduleType),
      enabled: true,
      id: createId(moduleType),
      title: getModuleLabel(moduleType),
      type: moduleType,
    };
  } else {
    item = {
      config: defaultHomeConfig(moduleType as ClientDecorateApi.ComponentType),
      enabled: true,
      id: createId(moduleType),
      title: getModuleLabel(moduleType),
      type: moduleType,
    };
  }

  const targetIndex =
    insertIndex === undefined ? schemeForm.schema.length : insertIndex;
  schemeForm.schema.splice(targetIndex, 0, item);
  selectedIndex.value = targetIndex;
};

const removeModule = (index: number) => {
  if (warnReadonlyScheme()) return;
  if (activeType.value === 'tabbar' && schemeForm.schema.length <= 2) {
    message.warning('底部导航至少 2 项');
    return;
  }
  schemeForm.schema.splice(index, 1);
  ensureSelectedIndex();
};

const moveModule = (from: number, to: number) => {
  if (warnReadonlyScheme()) return;
  if (from === to || from < 0 || to < 0 || from >= schemeForm.schema.length)
    return;
  const target = Math.min(to, schemeForm.schema.length - 1);
  const [item] = schemeForm.schema.splice(from, 1);
  if (!item) return;
  schemeForm.schema.splice(target, 0, item);
  selectedIndex.value = target;
};

const insertModuleAt = (from: number, insertIndex: number) => {
  if (warnReadonlyScheme()) return;
  if (from < 0 || from >= schemeForm.schema.length) return;
  const clamped = Math.max(0, Math.min(insertIndex, schemeForm.schema.length));
  if (from === clamped || from + 1 === clamped) return;
  const [item] = schemeForm.schema.splice(from, 1);
  if (!item) return;
  const target = from < clamped ? clamped - 1 : clamped;
  schemeForm.schema.splice(target, 0, item);
  selectedIndex.value = target;
};

const handlePreviewModuleMove = (index: number, direction: 'down' | 'up') => {
  moveModule(index, direction === 'up' ? index - 1 : index + 1);
};

const handlePreviewModuleDelete = (index: number) => {
  removeModule(index);
};

const stopMouseDragListeners = () => {
  window.removeEventListener('mousemove', handleMouseDragMove);
  window.removeEventListener('mouseup', finishMouseDrag);
  if (dragMoveFrame !== null) {
    window.cancelAnimationFrame(dragMoveFrame);
    dragMoveFrame = null;
  }
  pendingDragEvent = null;
};

const resetMouseDrag = () => {
  stopMouseDragListeners();
  mouseDrag.value = null;
  dragDropIndex.value = null;
  dragPreview.visible = false;
};

const resolveDropIndex = (event: MouseEvent) => {
  const target = document.elementFromPoint(
    event.clientX,
    event.clientY,
  ) as HTMLElement | null;
  const moduleList = target?.closest(
    '[data-module-list]',
  ) as HTMLElement | null;
  if (!moduleList) return null;

  const moduleCards = [
    ...moduleList.querySelectorAll<HTMLElement>('[data-module-index]'),
  ].filter((item) => item.offsetParent !== null);
  if (moduleCards.length === 0) return schemeForm.schema.length;

  const isHorizontal = activeType.value === 'tabbar';
  const sortedCards = moduleCards.toSorted((a, b) => {
    const rectA = a.getBoundingClientRect();
    const rectB = b.getBoundingClientRect();
    return isHorizontal ? rectA.left - rectB.left : rectA.top - rectB.top;
  });
  const pointer = isHorizontal ? event.clientX : event.clientY;
  for (const card of sortedCards) {
    const rect = card.getBoundingClientRect();
    const midpoint = isHorizontal
      ? rect.left + rect.width / 2
      : rect.top + rect.height / 2;
    if (pointer < midpoint) {
      return Number(card.dataset.moduleIndex);
    }
  }
  return schemeForm.schema.length;
};

const updateDragPosition = (event: MouseEvent) => {
  dragPreview.x = event.clientX + 12;
  dragPreview.y = event.clientY + 12;
  const nextDropIndex = resolveDropIndex(event);
  if (dragDropIndex.value !== nextDropIndex) {
    dragDropIndex.value = nextDropIndex;
  }
};

function handleMouseDragMove(event: MouseEvent) {
  const payload = mouseDrag.value;
  if (!payload) return;

  const moved = Math.hypot(
    event.clientX - payload.startX,
    event.clientY - payload.startY,
  );
  if (!payload.active && moved >= DRAG_THRESHOLD) {
    payload.active = true;
    dragPreview.visible = true;
    dragPreview.label = payload.label;
  }
  if (!payload.active) return;

  event.preventDefault();
  pendingDragEvent = event;
  if (dragMoveFrame !== null) return;
  dragMoveFrame = window.requestAnimationFrame(() => {
    dragMoveFrame = null;
    if (pendingDragEvent) updateDragPosition(pendingDragEvent);
  });
}

function finishMouseDrag(event: MouseEvent) {
  const payload = mouseDrag.value;
  if (!payload) {
    resetMouseDrag();
    return;
  }

  stopMouseDragListeners();
  const shouldDrop = payload.active;
  const dropIndex = shouldDrop
    ? (dragDropIndex.value ?? resolveDropIndex(event))
    : null;
  if (shouldDrop && payload.kind === 'palette') {
    suppressPaletteClick.value = true;
    window.setTimeout(() => {
      suppressPaletteClick.value = false;
    }, 0);
  }

  mouseDrag.value = null;
  dragDropIndex.value = null;
  dragPreview.visible = false;
  if (dropIndex === null) return;

  if (payload.kind === 'palette' && payload.type) {
    addModuleByType(payload.type, dropIndex);
  }
  if (payload.kind === 'module' && payload.index !== undefined) {
    insertModuleAt(payload.index, dropIndex);
  }
}

const startMouseDrag = (
  payload: Omit<
    NonNullable<typeof mouseDrag.value>,
    'active' | 'startX' | 'startY'
  >,
  event: MouseEvent,
) => {
  if (warnReadonlyScheme()) {
    event.preventDefault();
    return;
  }
  if (event.button !== 0) return;
  resetMouseDrag();
  mouseDrag.value = {
    ...payload,
    active: false,
    startX: event.clientX,
    startY: event.clientY,
  };
  window.addEventListener('mousemove', handleMouseDragMove);
  window.addEventListener('mouseup', finishMouseDrag);
};

const handlePaletteMouseDown = (
  item: { label: string; type: string },
  event: MouseEvent,
) => {
  startMouseDrag(
    { kind: 'palette', label: item.label, type: item.type },
    event,
  );
};

const handleModuleMouseDown = (index: number, event: MouseEvent) => {
  event.stopPropagation();
  const module = schemeForm.schema[index];
  if (!module) return;
  startMouseDrag(
    {
      index,
      kind: 'module',
      label: module.title || module.text || getModuleLabel(module.type),
    },
    event,
  );
};

const handlePaletteClick = (type: string) => {
  if (suppressPaletteClick.value) {
    suppressPaletteClick.value = false;
    return;
  }
  addModuleByType(type);
};

const addNavItem = (module: ModuleItem) => {
  if (warnReadonlyScheme()) return;
  const items = (module.config.items ||= []);
  items.push(clone(DEFAULT_NAV_ITEMS[items.length % DEFAULT_NAV_ITEMS.length]));
};

const addProfileItem = (module: ModuleItem) => {
  if (warnReadonlyScheme()) return;
  const items = (module.config.items ||= []);
  items.push({
    id: createId('profile_item'),
    icon: 'ant-design:customer-service-outlined',
    path: '',
    title: '菜单项',
  });
};

const removeConfigItem = (items: any[], index: number | string) => {
  if (warnReadonlyScheme()) return;
  items.splice(Number(index), 1);
};

const updateHomePageStyle = (
  field: 'paddingX' | 'paddingY',
  value: unknown,
) => {
  if (warnReadonlyScheme()) return;
  schemeForm.pageStyle[field] = Math.max(0, Number(value || 0));
};

const resetHomePageStyle = () => {
  if (warnReadonlyScheme()) return;
  schemeForm.pageStyle = { ...DEFAULT_HOME_PAGE_STYLE };
  message.success('已重置页面样式');
};

const resetModuleConfig = (module: ModuleItem) => {
  if (warnReadonlyScheme()) return;
  if (!module) return;
  if (activeType.value === 'home') {
    module.config = defaultHomeConfig(
      module.type as ClientDecorateApi.ComponentType,
    );
  } else if (activeType.value === 'profile') {
    module.config = defaultProfileConfig(String(module.type || ''));
  }
  message.success('已重置组件属性');
};

watch(
  () => route.path,
  async (path) => {
    const nextType = resolveRouteSchemeType(path);
    if (nextType === activeType.value) return;
    activeType.value = nextType;
    viewMode.value = 'overview';
    selectedSchemeId.value = null;
    selectedIndex.value = 0;
    await Promise.all([loadSchemes(), refreshOverview()]);
  },
);

onMounted(async () => {
  await Promise.all([
    loadThemes(),
    loadOverviewSchemes(),
    loadPreviewBusinessData(),
  ]);
  await loadSchemes();
});

onBeforeUnmount(resetMouseDrag);
</script>

<template>
  <div
    class="decorate-page"
    :class="{ 'decorate-page--editor': viewMode !== 'overview' }"
  >
    <div
      v-if="dragPreview.visible"
      class="drag-ghost"
      :style="{
        transform: `translate3d(${dragPreview.x}px, ${dragPreview.y}px, 0)`,
      }"
    >
      {{ dragPreview.label }}
    </div>

    <div class="decorate-top">
      <div v-if="viewMode === 'overview'" class="decorate-theme-summary">
        <div class="decorate-theme-title">
          <a-tag color="blue">{{ activeTypeLabel }}</a-tag>
          <strong>当前主题：</strong>
          <span>{{ currentThemeName }}</span>
          <span
            v-for="item in currentThemeSwatches"
            :key="item.key"
            class="decorate-theme-swatch"
            :title="`${item.label}：${item.value}`"
          >
            <i :style="{ backgroundColor: item.value }"></i>
            <span>{{ item.label }}</span>
            <code>{{ item.value }}</code>
          </span>
        </div>
        <div class="decorate-help">
          {{ activeTabMeta.help }}，预览已接入主题色。
        </div>
      </div>

      <div v-else class="decorate-editor-summary">
        <div class="decorate-theme-title">
          <a-tag color="blue">{{ activeTypeLabel }}</a-tag>
          <strong>{{ schemeForm.name || `${activeTypeLabel}方案` }}</strong>
          <span>当前主题：{{ currentThemeName }}</span>
          <span
            v-for="item in currentThemeSwatches"
            :key="item.key"
            class="decorate-theme-swatch"
            :title="`${item.label}：${item.value}`"
          >
            <i :style="{ backgroundColor: item.value }"></i>
            <span>{{ item.label }}</span>
            <code>{{ item.value }}</code>
          </span>
        </div>
        <div class="decorate-help">
          {{ activeTabMeta.help }}，当前使用：{{ schemeSummary.activeName }}
        </div>
      </div>

      <a-space v-if="viewMode === 'overview'" wrap>
        <a-button :loading="overviewLoading" @click="refreshOverview">
          刷新预览
        </a-button>
        <a-button type="primary" @click="handleOverviewCreate">
          新建方案
        </a-button>
        <a-button @click="router.push('/client/theme')">主题设置</a-button>
      </a-space>

      <a-space v-else wrap>
        <a-button @click="handleBackOverview">返回卡片列表</a-button>
        <a-select
          :value="selectedSchemeId"
          :options="schemeSelectOptions"
          :loading="loading"
          placeholder="选择方案"
          show-search
          style="width: 240px"
          @change="handleSelectScheme"
        />
        <a-tag color="blue">{{ schemeSummary.count }} 个方案</a-tag>
        <a-button @click="openSchemeSettings">方案设置</a-button>
        <a-button @click="handleNewScheme">新建方案</a-button>
        <a-button :disabled="!selectedSchemeId" @click="handleCopy">
          复制
        </a-button>
        <a-button
          :disabled="!selectedSchemeId || currentScheme?.is_active === 1"
          @click="handleActivate"
        >
          设为当前
        </a-button>
        <a-button
          danger
          :disabled="!selectedSchemeId || isReadonlyScheme"
          @click="handleDelete"
        >
          删除
        </a-button>
        <a-button
          type="primary"
          :disabled="isReadonlyScheme"
          :loading="saving"
          @click="handleSave"
        >
          保存
        </a-button>
      </a-space>
    </div>

    <DecorateSchemeList
      v-if="viewMode === 'overview'"
      :active-help="activeTabMeta.help"
      :active-type-label="activeTypeLabel"
      :current-theme-tokens="currentThemeTokens"
      :get-overview-scheme-module-summary="getOverviewSchemeModuleSummary"
      :get-overview-scheme-module-title="getOverviewSchemeModuleTitle"
      :get-overview-scheme-modules="getOverviewSchemeModules"
      :get-overview-scheme-tabbar-items="getOverviewSchemeTabbarItems"
      :get-overview-scheme-update-label="getOverviewSchemeUpdateLabel"
      :get-scheme-preview-meta="getSchemePreviewMeta"
      :get-scheme-status-color="getSchemeStatusColor"
      :get-scheme-status-label="getSchemeStatusLabel"
      :get-scheme-type-label="getSchemeTypeLabel"
      :is-readonly-overview-scheme="isReadonlyOverviewScheme"
      :overview-active-name="overviewActiveName"
      :overview-active-schemes="overviewActiveSchemes"
      :overview-loading="overviewLoading"
      :preview-category-tree="previewCategoryTree"
      :preview-goods="previewGoods"
      :preview-goods-list="previewGoodsList"
      @activate="handleOverviewActivate"
      @copy="handleOverviewCopy"
      @create="handleOverviewCreate"
      @delete="handleOverviewDelete"
      @edit="handleOverviewEdit"
    />

    <DecorateEditor
      v-else
      :active-type="activeType"
      :active-type-label="activeTypeLabel"
      :current-theme-tokens="currentThemeTokens"
      :drag-active="Boolean(mouseDrag?.active)"
      :drag-drop-index="dragDropIndex"
      :icon-prefix="iconPrefix"
      :is-readonly-scheme="isReadonlyScheme"
      :normalize-profile-module-type="normalizeProfileModuleType"
      :palette-groups="paletteGroups"
      :preview-category-tree="previewCategoryTree"
      :preview-goods="previewGoods"
      :preview-goods-list="previewGoodsList"
      :product-layout-options="PRODUCT_LAYOUT_OPTIONS"
      :product-sort-options="PRODUCT_SORT_OPTIONS"
      :product-source-options="PRODUCT_SOURCE_OPTIONS"
      :scheme-form="schemeForm"
      :selected-module="selectedModule"
      :selected-module-id="selectedModuleId"
      :tabbar-preview-items="getTabbarPreviewItems()"
      @add-nav-item="addNavItem"
      @add-profile-item="addProfileItem"
      @module-delete="handlePreviewModuleDelete"
      @module-mouse-down="handleModuleMouseDown"
      @module-move="handlePreviewModuleMove"
      @palette-click="handlePaletteClick"
      @palette-mouse-down="handlePaletteMouseDown"
      @remove-config-item="removeConfigItem"
      @reset-module-config="resetModuleConfig"
      @reset-page-style="resetHomePageStyle"
      @select-module="selectModuleFromPreview"
      @update-page-style="updateHomePageStyle"
    />

    <a-modal
      v-model:open="schemeSettingsOpen"
      :footer="null"
      title="方案设置"
      width="520px"
    >
      <a-form
        :disabled="isReadonlyScheme"
        :label-col="{ style: { width: '76px' } }"
        :model="schemeForm"
        class="scheme-setting-form"
      >
        <a-form-item label="名称">
          <a-input
            v-model:value="schemeForm.name"
            allow-clear
            placeholder="请输入方案名称"
          />
        </a-form-item>
        <a-form-item label="说明">
          <a-textarea
            v-model:value="schemeForm.description"
            :rows="3"
            allow-clear
            placeholder="可选"
          />
        </a-form-item>
        <a-form-item label="排序">
          <a-input-number
            v-model:value="schemeForm.sort"
            :min="0"
            :max="9999"
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="状态">
          <a-radio-group v-model:value="schemeForm.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item v-if="activeType === 'tabbar'" label="模式">
          <a-segmented
            v-model:value="schemeForm.tabbar_mode"
            :options="[
              { label: '原生', value: 'native' },
              { label: '自绘', value: 'custom' },
            ]"
          />
        </a-form-item>
      </a-form>
      <a-alert
        v-if="currentScheme?.is_active === 1"
        message="当前启用方案"
        show-icon
        type="success"
      />
      <a-alert
        v-if="isSystemScheme"
        class="mt-3"
        message="系统默认方案不能直接修改，请先复制一份再编辑。"
        show-icon
        type="info"
      />
    </a-modal>
  </div>
</template>

<style src="./decorate.css"></style>
