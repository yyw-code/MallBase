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
  getClientThemeSettingApi,
  updateClientDecorateSchemeApi,
} from '#/api/client';
import { getGoodsCategoryTreeApi, getGoodsListApi } from '#/api/goods';

import DecorateEditor from './components/DecorateEditor.vue';
import DecorateSchemeList from './components/DecorateSchemeList.vue';
import { resolvePreviewImageUrl } from './utils/previewImage';

defineOptions({ name: 'ClientDecorateManagement' });

type ModuleItem = Record<string, any>;
type PageStyle = {
  background_image?: unknown;
  backgroundColorEnd?: string;
  backgroundColorStart?: string;
  backgroundGradientDirection?: string;
  backgroundMode?: string;
  padding?: number;
  paddingBottom?: number;
  paddingLeft?: number;
  paddingRight?: number;
  paddingTop?: number;
  paddingX: number;
  paddingY?: number;
};
type FloatingConfig = {
  enabled: boolean;
  hiddenPages: string[];
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
type UploadFileInfo = {
  full_url?: string;
  fullUrl?: string;
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

const DEFAULT_THEME_SETTING: ClientThemeApi.ThemeSetting = {
  admin_theme_id: null,
  admin_theme_mode: 'system',
  user_select_enabled: 1,
};

const DEFAULT_HOME_PAGE_STYLE: PageStyle = {
  backgroundColorEnd: '',
  backgroundColorStart: '',
  backgroundGradientDirection: 'horizontal',
  backgroundMode: 'color',
  background_image: '',
  padding: 14,
  paddingBottom: 0,
  paddingLeft: 28,
  paddingRight: 28,
  paddingTop: 0,
  paddingX: 28,
  paddingY: 0,
};

const DEFAULT_FLOATING_CONFIG: FloatingConfig = {
  enabled: true,
  hiddenPages: ['/pages-sub/user/login', '/pages-sub/user/agreement'],
  mode: 'expand',
  offsetBottom: 160,
  offsetX: 24,
  position: 'right-bottom',
  singleItemId: '',
  style: {
    backgroundColor: '',
    color: '',
    radius: 44,
    shadowBlur: 30,
    shadowColor: '#0f172a',
    shadowEnabled: true,
    shadowOffsetX: 0,
    shadowOffsetY: 12,
    shadowOpacity: 14,
    shadowSpread: 0,
    size: 88,
  },
};

const DEFAULT_PROFILE_PAGE_STYLE: PageStyle = {
  backgroundColorEnd: '',
  backgroundColorStart: '',
  backgroundGradientDirection: 'horizontal',
  backgroundMode: 'color',
  background_image: '',
  padding: 23,
  paddingBottom: 24,
  paddingLeft: 28,
  paddingRight: 28,
  paddingTop: 10,
  paddingX: 28,
  paddingY: 17,
};

const PROFILE_STYLE_DEFAULTS = {
  backgroundColorEnd: '#ffffff',
  backgroundColorStart: '#ffffff',
  backgroundGradientDirection: 'horizontal',
  backgroundMode: 'color',
  background_image: '',
  borderColor: '#e5e5e5',
  borderEnabled: true,
  borderStyle: 'dashed',
  borderWidth: 1,
  marginLeft: 0,
  marginRight: 0,
  shadowEnabled: false,
  shadowBlur: 30,
  shadowColor: '#0f172a',
  shadowOffsetX: 0,
  shadowOffsetY: 12,
  shadowOpacity: 14,
  shadowSpread: 0,
};

const HOME_STYLE_DEFAULTS = {
  ...PROFILE_STYLE_DEFAULTS,
  backgroundColorEnd: '',
  backgroundColorStart: '',
  borderEnabled: false,
  borderStyle: 'solid',
};

const MODULE_STYLE_FIELDS = [
  'background',
  'backgroundColorEnd',
  'backgroundColorStart',
  'backgroundGradientDirection',
  'backgroundMode',
  'background_image',
  'borderColor',
  'borderEnabled',
  'borderStyle',
  'borderWidth',
  'marginBottom',
  'marginLeft',
  'marginRight',
  'marginTop',
  'padding',
  'paddingBottom',
  'paddingLeft',
  'paddingRight',
  'paddingTop',
  'paddingX',
  'paddingY',
  'radius',
  'shadowBlur',
  'shadowColor',
  'shadowEnabled',
  'shadowOffsetX',
  'shadowOffsetY',
  'shadowOpacity',
  'shadowSpread',
  'widthPercent',
] as const;

const SCHEME_TABS: Array<{
  help: string;
  label: string;
  value: ClientDecorateApi.SchemeType;
}> = [
  { help: '配置首页组件、排序和内容', label: '首页装修', value: 'home' },
  { help: '配置底部导航项、图标和模式', label: '底部导航', value: 'tabbar' },
  { help: '配置个人中心模块和入口', label: '个人中心', value: 'profile' },
  {
    help: '配置全局悬浮入口、位置和样式',
    label: '悬浮按钮',
    value: 'floating',
  },
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
    desc: '头像、昵称、手机号和签名',
    icon: 'lucide:user-round',
    label: '用户信息',
    type: 'userInfo',
  },
  {
    desc: '默认四个订单状态入口',
    icon: 'lucide:package-check',
    label: '订单入口',
    type: 'orderEntry',
  },
  {
    desc: '余额、明细和查看入口',
    icon: 'lucide:wallet',
    label: '钱包卡片',
    type: 'walletEntry',
  },
  {
    desc: '积分余额、明细和查看入口',
    icon: 'lucide:badge-cent',
    label: '积分卡片',
    type: 'pointsEntry',
  },
  {
    desc: '默认四个常用服务入口',
    icon: 'lucide:layout-list',
    label: '服务菜单',
    type: 'serviceMenu',
  },
];

const PROFILE_TYPE_ALIAS: Record<string, string> = {
  customMenu: 'serviceMenu',
  orderShortcut: 'orderEntry',
  points: 'pointsEntry',
  pointsCard: 'pointsEntry',
  profileHeader: 'userInfo',
  walletCard: 'walletEntry',
};

const HOME_TYPE_ALIAS: Record<string, string> = {
  categoryEntry: 'entryCard',
};

const DECORATE_ASSET_BASE_URL = `${
  new URL(import.meta.env.VITE_GLOB_API_URL || '/', window.location.origin)
    .origin
}/static/decorate/`;

const createDecorateAssetFile = (url: string, name: string) => ({
  full_url: `${DECORATE_ASSET_BASE_URL}${name}`,
  name,
  url,
});

const extractUploadName = (value: string) => {
  const cleanValue = value.split('?')[0] || value;
  const name = decodeURIComponent(cleanValue.split('/').pop() || '');
  return name || '图片';
};

const normalizeEditorUploadImage = (value: any) => {
  if (!value) return '';
  if (typeof value === 'number') {
    return normalizeEditorUploadImage(String(value));
  }
  if (typeof value === 'string') {
    const fullUrl = resolvePreviewImageUrl(value);
    return {
      full_url: fullUrl,
      name: extractUploadName(fullUrl || value),
      url: value,
    };
  }
  if (typeof value === 'object') {
    const url =
      value.url ||
      value.path ||
      value.image ||
      value.src ||
      value.response?.url ||
      value.asset_id ||
      '';
    if (!url) return '';
    const fullUrl =
      value.full_url ||
      value.fullUrl ||
      value.response?.full_url ||
      value.response?.fullUrl ||
      value.preview_url ||
      value.previewUrl ||
      resolvePreviewImageUrl(url) ||
      '';
    return {
      ...value,
      full_url: fullUrl,
      name:
        value.name ||
        value.original_name ||
        extractUploadName(String(fullUrl || url)),
      url: String(url),
    };
  }
  return '';
};

const DEFAULT_BANNER_IMAGE_BY_INDEX = [
  createDecorateAssetFile('1001', 'decorate-banner-market.png'),
  createDecorateAssetFile('1002', 'decorate-banner-member.png'),
  createDecorateAssetFile('1003', 'decorate-banner-home.png'),
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
  beauty: createDecorateAssetFile('1005', 'decorate-nav-beauty.png'),
  food: createDecorateAssetFile('1008', 'decorate-nav-food.png'),
  home: createDecorateAssetFile('1007', 'decorate-nav-home.png'),
  phone: createDecorateAssetFile('1004', 'decorate-nav-digital.png'),
  shirt: createDecorateAssetFile('1006', 'decorate-nav-fashion.png'),
  sport: createDecorateAssetFile('1009', 'decorate-nav-sport.png'),
};

const DEFAULT_CUBE_ITEMS = [
  {
    image: createDecorateAssetFile('1010', 'decorate-cube-new.png'),
    path: '/pages-sub/goods/list?sort=newest',
    title: '新品上架',
  },
  {
    image: createDecorateAssetFile('1011', 'decorate-cube-picks.png'),
    path: '/pages-sub/goods/list?is_recommend=1',
    title: '精选榜单',
  },
  {
    image: createDecorateAssetFile('1012', 'decorate-cube-member.png'),
    path: '/pages-sub/goods/list?sort=sales',
    title: '会员专享',
  },
  {
    image: createDecorateAssetFile('1013', 'decorate-cube-sale.png'),
    path: '/pages-sub/goods/list?is_hot=1',
    title: '限时满减',
  },
];

const DEFAULT_PROFILE_ORDER_IMAGE_BY_INDEX = [
  createDecorateAssetFile(
    'static/decorate/profile-order-pay.svg',
    'profile-order-pay.svg',
  ),
  createDecorateAssetFile(
    'static/decorate/profile-order-ship.svg',
    'profile-order-ship.svg',
  ),
  createDecorateAssetFile(
    'static/decorate/profile-order-receive.svg',
    'profile-order-receive.svg',
  ),
  createDecorateAssetFile(
    'static/decorate/profile-order-refund.svg',
    'profile-order-refund.svg',
  ),
];

const DEFAULT_PROFILE_SERVICE_IMAGE_BY_INDEX = [
  createDecorateAssetFile(
    'static/decorate/profile-service-address.svg',
    'profile-service-address.svg',
  ),
  createDecorateAssetFile(
    'static/decorate/profile-service-settings.svg',
    'profile-service-settings.svg',
  ),
  createDecorateAssetFile(
    'static/decorate/profile-service-support.svg',
    'profile-service-support.svg',
  ),
];

const getDefaultProfileEntryImage = (type: string, index: number) =>
  type === 'orderEntry'
    ? DEFAULT_PROFILE_ORDER_IMAGE_BY_INDEX[
        index % DEFAULT_PROFILE_ORDER_IMAGE_BY_INDEX.length
      ]
    : DEFAULT_PROFILE_SERVICE_IMAGE_BY_INDEX[
        index % DEFAULT_PROFILE_SERVICE_IMAGE_BY_INDEX.length
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
  { id: 'preview-order', path: '/pages/order/index', text: '订单' },
  { id: 'preview-profile', path: '/pages/profile/index', text: '我的' },
];

const resolveRouteSchemeType = (path: string): ClientDecorateApi.SchemeType => {
  if (path.includes('/client/decorate/floating')) return 'floating';
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
    previewKind: 'floating' | 'home' | 'profile' | 'tabbar';
  }
> = {
  floating: {
    cardDesc: '全局悬浮入口、客服和快捷跳转',
    currentPath: '/pages-sub/goods/detail',
    path: '客户端全局悬浮入口',
    previewKind: 'floating',
  },
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
const themeSetting = ref<ClientThemeApi.ThemeSetting>({
  ...DEFAULT_THEME_SETTING,
});
const overviewSchemes = reactive<
  Record<ClientDecorateApi.SchemeType, ClientDecorateApi.SchemeItem[]>
>({
  floating: [],
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

const schemeForm = reactive<{
  description: string;
  floatingConfig: FloatingConfig;
  name: string;
  pageStyle: PageStyle;
  schema: ModuleItem[];
  sort: number;
  status: number;
  tabbar_mode: ClientDecorateApi.TabbarMode;
}>({
  description: '',
  floatingConfig: {
    ...DEFAULT_FLOATING_CONFIG,
    hiddenPages: [...DEFAULT_FLOATING_CONFIG.hiddenPages],
    style: { ...DEFAULT_FLOATING_CONFIG.style },
  },
  name: '',
  pageStyle: { ...DEFAULT_HOME_PAGE_STYLE },
  schema: [],
  sort: 0,
  status: 1,
  tabbar_mode: 'custom',
});

const getSchemeSchemaList = (
  scheme: ClientDecorateApi.SchemeItem | null | undefined,
  type: ClientDecorateApi.SchemeType,
) => {
  const schema = scheme?.schema as any;
  if (Array.isArray(schema)) return schema;
  if (type === 'tabbar') return schema?.items || [];
  if (type === 'floating') return schema?.items || [];
  if (type === 'profile') return schema?.modules || schema?.components || [];
  return schema?.components || schema?.modules || [];
};

const getDefaultPageStyle = (type: ClientDecorateApi.SchemeType) =>
  type === 'profile' ? DEFAULT_PROFILE_PAGE_STYLE : DEFAULT_HOME_PAGE_STYLE;

const normalizePageSpacingNumber = (value: unknown, fallback = 0) => {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return Math.max(0, fallback);
  return Math.max(0, Math.round(numberValue));
};

const normalizePageStyle = (value: any, type: ClientDecorateApi.SchemeType) => {
  const defaults = getDefaultPageStyle(type);
  const paddingX = normalizePageSpacingNumber(
    value?.paddingX ?? value?.padding_x,
    defaults.paddingX,
  );
  if (type === 'home' || type === 'profile') {
    const paddingY = normalizePageSpacingNumber(
      value?.paddingY ?? value?.padding_y,
      defaults.paddingY,
    );
    const paddingTop = normalizePageSpacingNumber(
      value?.paddingTop ?? value?.padding_top ?? value?.paddingY,
      defaults.paddingTop ?? paddingY,
    );
    const paddingRight = normalizePageSpacingNumber(
      value?.paddingRight ?? value?.padding_right ?? value?.paddingX,
      defaults.paddingRight ?? paddingX,
    );
    const paddingBottom = normalizePageSpacingNumber(
      value?.paddingBottom ?? value?.padding_bottom ?? value?.paddingY,
      defaults.paddingBottom ?? paddingY,
    );
    const paddingLeft = normalizePageSpacingNumber(
      value?.paddingLeft ?? value?.padding_left ?? value?.paddingX,
      defaults.paddingLeft ?? paddingX,
    );
    return {
      backgroundColorEnd:
        value?.backgroundColorEnd ??
        value?.background_color_end ??
        defaults.backgroundColorEnd,
      backgroundColorStart:
        value?.backgroundColorStart ??
        value?.background_color_start ??
        defaults.backgroundColorStart,
      backgroundGradientDirection:
        value?.backgroundGradientDirection ??
        value?.background_gradient_direction ??
        defaults.backgroundGradientDirection,
      backgroundMode:
        value?.backgroundMode ??
        value?.background_mode ??
        defaults.backgroundMode,
      background_image: normalizeEditorUploadImage(
        value?.background_image && typeof value.background_image !== 'object'
          ? {
              full_url:
                value?.background_image_full_url ||
                value?.backgroundImageFullUrl ||
                '',
              url: value.background_image,
            }
          : (value?.background_image ?? value?.backgroundImage ?? ''),
      ),
      padding:
        paddingTop === paddingRight &&
        paddingRight === paddingBottom &&
        paddingBottom === paddingLeft
          ? paddingTop
          : Math.round(
              (paddingTop + paddingRight + paddingBottom + paddingLeft) / 4,
            ),
      paddingBottom,
      paddingLeft,
      paddingRight,
      paddingTop,
      paddingX:
        paddingLeft === paddingRight
          ? paddingLeft
          : Math.round((paddingLeft + paddingRight) / 2),
      paddingY:
        paddingTop === paddingBottom
          ? paddingTop
          : Math.round((paddingTop + paddingBottom) / 2),
    };
  }
  return { ...DEFAULT_HOME_PAGE_STYLE };
};

const getSchemePageStyle = (
  scheme: ClientDecorateApi.SchemeItem | null | undefined,
  type: ClientDecorateApi.SchemeType,
) => normalizePageStyle((scheme?.schema as any)?.pageStyle, type);

const getActiveSchemeByType = (type: ClientDecorateApi.SchemeType) =>
  overviewSchemes[type].find((item) => item.is_active === 1) ||
  overviewSchemes[type][0] ||
  null;

const getOverviewModules = (type: ClientDecorateApi.SchemeType) =>
  getSchemeSchemaList(getActiveSchemeByType(type), type).map(
    (item: ModuleItem, index: number) =>
      type === 'floating'
        ? normalizeFloatingEditorItem(item, index)
        : type === 'tabbar'
          ? normalizeTabbarEditorItem(item, index)
          : normalizeEditorModule(item, index, type),
  );

const getThemeByType = (type: ClientThemeApi.ThemeType) =>
  themeList.value.find((item) => item.type === type && item.status === 1);

const getDefaultCustomTheme = () =>
  themeList.value.find(
    (item) =>
      item.id === themeSetting.value.admin_theme_id &&
      item.status === 1 &&
      item.type === 'custom',
  );

const resolveCurrentTheme = () => {
  if (themeSetting.value.admin_theme_mode === 'custom') {
    return getDefaultCustomTheme() || getThemeByType('custom');
  }
  if (themeSetting.value.admin_theme_mode === 'dark') {
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
  if (activeType.value === 'floating') {
    return [
      {
        desc: '全局悬浮快捷入口',
        icon: 'lucide:message-circle',
        label: '悬浮入口',
        type: 'floatingItem',
      },
    ];
  }
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
  if (activeType.value === 'floating') {
    return [{ items: componentPalette.value, title: '悬浮入口' }];
  }
  if (activeType.value === 'tabbar') {
    return [{ items: componentPalette.value, title: '底部导航' }];
  }
  if (activeType.value === 'profile') {
    return [
      {
        items: pick(['userInfo', 'walletEntry', 'pointsEntry']),
        title: '基础组件',
      },
      {
        items: pick(['orderEntry', 'serviceMenu']),
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
  return modeName[themeSetting.value.admin_theme_mode] || '默认主题';
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
  if (scheme.type === 'floating') {
    return getSchemeSchemaList(scheme, scheme.type).map(
      (item: ModuleItem, index: number) =>
        normalizeFloatingEditorItem(item, index),
    );
  }
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
    scheme.type === 'floating'
      ? item.text || item.title || '悬浮入口'
      : scheme.type === 'tabbar'
        ? item.text || item.label || item.title || '导航项'
        : item.title ||
          item.label ||
          getModuleLabel(
            String(item.type || item.component || ''),
            scheme.type,
          ),
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

const TABBAR_STATIC_IMAGE_URLS = import.meta.glob(
  '../../../../../../../uniapp/static/images/tabbar/*.png',
  {
    eager: true,
    import: 'default',
    query: '?url',
  },
) as Record<string, string>;

const TABBAR_DEFAULT_IMAGE_KEYS = [
  'home',
  'category',
  'cart',
  'order',
  'profile',
];

const getTabbarStaticImagePath = (key: string, active = false) =>
  `static/images/tabbar/${key}${active ? '-active' : ''}.png`;

const getFloatingStaticIconPath = (key: string) =>
  `static/decorate/floating/${key}.png`;

const SYSTEM_FLOATING_ITEM_IDS = new Set([
  'floating-cart',
  'floating-home',
  'floating-service',
]);

const getDefaultTabbarImageKey = (item: ModuleItem = {}, index = 0) => {
  const text = String(item.text || item.label || item.title || '');
  const path = String(item.path || item.pagePath || item.url || '');
  if (path.includes('/pages/index/') || text.includes('首页')) return 'home';
  if (path.includes('/pages/category/') || text.includes('分类'))
    return 'category';
  if (path.includes('/pages/cart/') || text.includes('购物车')) return 'cart';
  if (path.includes('/pages/order/') || text.includes('订单')) return 'order';
  if (path.includes('/pages/profile/') || text.includes('我的'))
    return 'profile';
  return TABBAR_DEFAULT_IMAGE_KEYS[index % TABBAR_DEFAULT_IMAGE_KEYS.length]!;
};

const getDefaultTabbarImagePath = (
  item: ModuleItem = {},
  index = 0,
  active = false,
) => getTabbarStaticImagePath(getDefaultTabbarImageKey(item, index), active);

const resolveTabbarStaticImageFullUrl = (value: any) => {
  if (!value || typeof value !== 'string') return '';
  const normalized = value.replace(/^\/+/, '');
  if (!normalized.startsWith('static/images/tabbar/')) return '';
  const filename = normalized.split('/').pop();
  if (!filename) return '';
  const matched = Object.entries(TABBAR_STATIC_IMAGE_URLS).find(([path]) =>
    path.endsWith(`/${filename}`),
  );
  return matched?.[1] || '';
};

const resolveTabbarIconValue = (item: ModuleItem, active = false) =>
  active
    ? (item.selected_icon ??
      item.selectedIconPath ??
      item.activeIcon ??
      item.active_icon ??
      item.icon ??
      item.iconPath ??
      '')
    : (item.icon ?? item.iconPath ?? item.icon_path ?? '');

const isTabbarImageIcon = (value: any) => {
  const raw =
    value && typeof value === 'object'
      ? value.full_url ||
        value.fullUrl ||
        value.url ||
        value.response?.url ||
        ''
      : value;
  if (!raw || typeof raw !== 'string') return false;
  return (
    /^(?:https?:)?\/\//.test(raw) ||
    raw.startsWith('/') ||
    raw.startsWith('static/') ||
    raw.startsWith('upload/') ||
    /\.(?:avif|gif|jpe?g|png|svg|webp)(?:[?#].*)?$/i.test(raw)
  );
};

const normalizeTabbarIconValueForEditor = (
  value: any,
  item: ModuleItem = {},
  index = 0,
  active = false,
) => {
  if (!value || !isTabbarImageIcon(value)) {
    value = getDefaultTabbarImagePath(item, index, active);
  }

  if (typeof value === 'object') {
    const url =
      value.url ||
      value.path ||
      value.src ||
      value.asset_id ||
      value.response?.url ||
      '';
    if (!url) return '';
    const fullUrl =
      value.full_url ||
      value.fullUrl ||
      value.response?.full_url ||
      value.response?.fullUrl ||
      resolveTabbarStaticImageFullUrl(String(url));

    return {
      ...value,
      full_url: fullUrl,
      name: value.name || value.original_name || extractUploadName(String(url)),
      url: String(url),
    };
  }

  const url = String(value);
  return {
    full_url: resolveTabbarStaticImageFullUrl(url),
    name: extractUploadName(url),
    url,
  };
};

const normalizeTabbarEditorItem = (
  item: ModuleItem,
  index: number,
): ModuleItem => {
  const rawIcon = resolveTabbarIconValue(item);
  const rawSelectedIcon = resolveTabbarIconValue(item, true) || rawIcon;
  const icon = normalizeTabbarIconValueForEditor(rawIcon, item, index);
  const selectedIcon = normalizeTabbarIconValueForEditor(
    rawSelectedIcon,
    item,
    index,
    true,
  );

  return {
    ...item,
    activeIcon: selectedIcon,
    icon,
    iconPath: icon,
    icon_mode: 'upload',
    id: item.id || item.key || `tabbar_${index}`,
    path: item.path || item.pagePath || item.url || '',
    selected_icon: selectedIcon,
    selected_icon_mode: 'upload',
    selectedIconPath: selectedIcon,
    text: item.text || item.label || item.title || '导航',
    type: item.type || 'tabbarItem',
  };
};

const normalizeTabbarEditorItems = (items: ModuleItem[]) =>
  (Array.isArray(items) ? items : [])
    .slice(0, 5)
    .map((item, index) => normalizeTabbarEditorItem(item, index));

const normalizeTabbarSaveItem = (item: ModuleItem, index: number) => {
  const normalized = normalizeTabbarEditorItem(item, index);
  const icon = normalizeUploadValue(normalized.icon);
  const selectedIcon =
    normalizeUploadValue(normalized.selected_icon) ||
    normalizeUploadValue(icon);

  return {
    activeIcon: selectedIcon,
    enabled: normalized.enabled !== false,
    icon,
    iconPath: icon,
    icon_mode: normalized.icon_mode,
    id: normalized.id,
    path: normalized.path,
    selected_icon: selectedIcon,
    selected_icon_mode: normalized.selected_icon_mode,
    selectedIconPath: selectedIcon,
    text: normalized.text,
  };
};

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
  schemeType === 'floating'
    ? type
    : schemeType === 'profile'
      ? normalizeProfileModuleType(type)
      : normalizeHomeModuleType(type);

const getModuleLabel = (type: string, schemeType = activeType.value) =>
  schemeType === 'floating'
    ? '悬浮入口'
    : [...HOME_MODULES, ...PROFILE_MODULES].find(
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

const normalizeShadowStyle = (config: Record<string, any>) => {
  config.shadowEnabled = normalizeBooleanValue(
    config.shadowEnabled ?? config.shadow_enabled,
    PROFILE_STYLE_DEFAULTS.shadowEnabled,
  );
  config.shadowOffsetX = clampNumber(
    config.shadowOffsetX ?? config.shadow_offset_x,
    PROFILE_STYLE_DEFAULTS.shadowOffsetX,
    -80,
    80,
  );
  config.shadowOffsetY = clampNumber(
    config.shadowOffsetY ?? config.shadow_offset_y,
    PROFILE_STYLE_DEFAULTS.shadowOffsetY,
    -80,
    80,
  );
  config.shadowBlur = clampNumber(
    config.shadowBlur ?? config.shadow_blur,
    PROFILE_STYLE_DEFAULTS.shadowBlur,
    0,
    160,
  );
  config.shadowSpread = clampNumber(
    config.shadowSpread ?? config.shadow_spread,
    PROFILE_STYLE_DEFAULTS.shadowSpread,
    -80,
    80,
  );
  config.shadowColor =
    config.shadowColor ??
    config.shadow_color ??
    PROFILE_STYLE_DEFAULTS.shadowColor;
  config.shadowOpacity = clampNumber(
    config.shadowOpacity ?? config.shadow_opacity,
    PROFILE_STYLE_DEFAULTS.shadowOpacity,
    0,
    100,
  );
  delete config.shadow_enabled;
  delete config.shadow_offset_x;
  delete config.shadow_offset_y;
  delete config.shadow_blur;
  delete config.shadow_spread;
  delete config.shadow_color;
  delete config.shadow_opacity;
};

const normalizeTitleAlign = (value: unknown) =>
  ['center', 'left', 'right'].includes(String(value)) ? String(value) : 'left';

const normalizeProfileDisplay = (value: unknown) =>
  ['grid', 'list'].includes(String(value)) ? String(value) : 'list';

const PROFILE_TEXT_STYLE_ROLES = [
  'action',
  'amount',
  'iconText',
  'itemLabel',
  'meta',
  'more',
  'placeholder',
  'primaryAction',
  'subtitle',
  'title',
];

const PROFILE_TEXT_STYLE_ROLES_BY_TYPE: Record<string, string[]> = {
  orderEntry: ['itemLabel', 'more', 'title'],
  serviceMenu: ['itemLabel', 'title'],
  userInfo: ['meta', 'subtitle', 'title'],
  walletEntry: ['action', 'amount', 'meta', 'primaryAction', 'title'],
  pointsEntry: ['action', 'amount', 'meta', 'primaryAction', 'title'],
  entryCard: ['subtitle', 'title'],
  imageCube: ['itemLabel'],
  navGrid: ['itemLabel'],
  productGroup: ['more', 'subtitle', 'title'],
  search: ['placeholder'],
  title: ['more', 'subtitle', 'title'],
};

const getProfileTextStyleRoles = (type?: string) =>
  PROFILE_TEXT_STYLE_ROLES_BY_TYPE[type || ''] || PROFILE_TEXT_STYLE_ROLES;

const normalizeProfileTextAlign = (value: unknown) =>
  ['center', 'left', 'right'].includes(String(value)) ? String(value) : '';

const normalizeProfileTextBackgroundMode = (value: unknown) =>
  ['color', 'image'].includes(String(value)) ? String(value) : '';

const normalizeProfileTextGradientDirection = (value: unknown) =>
  ['diagonalLeft', 'diagonalRight', 'horizontal', 'vertical'].includes(
    String(value),
  )
    ? String(value)
    : '';

const normalizeProfileTextBackgroundPosition = (value: unknown) =>
  [
    'bottom',
    'bottomLeft',
    'bottomRight',
    'center',
    'centerLeft',
    'centerRight',
    'top',
    'topLeft',
    'topRight',
  ].includes(String(value))
    ? String(value)
    : '';

const normalizeCubeLayout = (value: unknown) =>
  ['four', 'one', 'two'].includes(String(value)) ? String(value) : 'four';

const normalizeProfileTextStyles = (
  value: unknown,
  roles = PROFILE_TEXT_STYLE_ROLES,
) => {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
  const source = value as Record<string, any>;
  return Object.fromEntries(
    roles
      .map((role) => {
        const rawStyle = source[role];
        if (
          !rawStyle ||
          typeof rawStyle !== 'object' ||
          Array.isArray(rawStyle)
        ) {
          return [role, undefined];
        }
        const style: Record<string, any> = {};
        if (typeof rawStyle.color === 'string' && rawStyle.color.trim()) {
          style.color = rawStyle.color.trim();
        }
        const fontSize = Number(rawStyle.fontSize ?? rawStyle.font_size);
        if (Number.isFinite(fontSize) && fontSize > 0) {
          style.fontSize = clampNumber(fontSize, 24, 16, 80);
        }
        const fontWeight = String(
          rawStyle.fontWeight ?? rawStyle.font_weight ?? '',
        );
        if (['400', '500', '600', '700', '800', '900'].includes(fontWeight)) {
          style.fontWeight = fontWeight;
        }
        const fontStyle = String(
          rawStyle.fontStyle ?? rawStyle.font_style ?? '',
        );
        if (fontStyle === 'italic' || normalizeBooleanValue(rawStyle.italic)) {
          style.fontStyle = 'italic';
        }
        const textAlign = normalizeProfileTextAlign(
          rawStyle.textAlign ?? rawStyle.text_align,
        );
        if (textAlign) {
          style.textAlign = textAlign;
        }
        const backgroundMode = normalizeProfileTextBackgroundMode(
          rawStyle.backgroundMode ?? rawStyle.background_mode,
        );
        if (backgroundMode) {
          style.backgroundMode = backgroundMode;
        }
        const backgroundColorStart = String(
          rawStyle.backgroundColorStart ??
            rawStyle.background_color_start ??
            '',
        ).trim();
        if (backgroundColorStart) {
          style.backgroundColorStart = backgroundColorStart;
        }
        const backgroundColorEnd = String(
          rawStyle.backgroundColorEnd ?? rawStyle.background_color_end ?? '',
        ).trim();
        if (backgroundColorEnd) {
          style.backgroundColorEnd = backgroundColorEnd;
        }
        const backgroundGradientDirection =
          normalizeProfileTextGradientDirection(
            rawStyle.backgroundGradientDirection ??
              rawStyle.background_gradient_direction,
          );
        if (backgroundGradientDirection) {
          style.backgroundGradientDirection = backgroundGradientDirection;
        }
        const backgroundImage = normalizeEditorUploadImage(
          rawStyle.backgroundImage ?? rawStyle.background_image ?? '',
        );
        if (backgroundImage) {
          style.backgroundImage = backgroundImage;
        }
        const backgroundHeight = Number(
          rawStyle.backgroundHeight ?? rawStyle.background_height,
        );
        if (Number.isFinite(backgroundHeight) && backgroundHeight > 0) {
          style.backgroundHeight = clampNumber(backgroundHeight, 26, 10, 100);
        }
        const backgroundWidth = Number(
          rawStyle.backgroundWidth ?? rawStyle.background_width,
        );
        if (Number.isFinite(backgroundWidth) && backgroundWidth > 0) {
          style.backgroundWidth = clampNumber(backgroundWidth, 100, 20, 100);
        }
        const backgroundRadius = Number(
          rawStyle.backgroundRadius ?? rawStyle.background_radius,
        );
        if (Number.isFinite(backgroundRadius) && backgroundRadius >= 0) {
          style.backgroundRadius = clampNumber(backgroundRadius, 12, 0, 80);
        }
        const backgroundPosition = normalizeProfileTextBackgroundPosition(
          rawStyle.backgroundPosition ?? rawStyle.background_position,
        );
        if (backgroundPosition) {
          style.backgroundPosition = backgroundPosition;
        }
        return [role, Object.keys(style).length > 0 ? style : undefined];
      })
      .filter(([, style]) => style !== undefined),
  );
};

const buildTitleLegacyTextStyles = (config: Record<string, any>) => {
  const titleAlign = normalizeProfileTextAlign(
    config.title_align || config.titleAlign || config.text_align,
  );
  const legacyStyles: Record<string, Record<string, any>> = {};
  const titleStyle: Record<string, any> = {};
  if (typeof config.title_color === 'string' && config.title_color.trim()) {
    titleStyle.color = config.title_color.trim();
  } else if (
    typeof config.titleColor === 'string' &&
    config.titleColor.trim()
  ) {
    titleStyle.color = config.titleColor.trim();
  }
  titleStyle.fontSize = clampNumber(
    config.title_font_size || config.titleFontSize || config.font_size,
    32,
    18,
    72,
  );
  titleStyle.fontWeight =
    config.title_bold === false || config.titleBold === false ? '500' : '800';
  if (config.title_italic || config.titleItalic) {
    titleStyle.fontStyle = 'italic';
  }
  if (titleAlign) {
    titleStyle.textAlign = titleAlign;
  }
  legacyStyles.title = titleStyle;

  const subtitleStyle: Record<string, any> = {};
  if (typeof config.sub_color === 'string' && config.sub_color.trim()) {
    subtitleStyle.color = config.sub_color.trim();
  } else if (typeof config.subColor === 'string' && config.subColor.trim()) {
    subtitleStyle.color = config.subColor.trim();
  }
  subtitleStyle.fontSize = clampNumber(
    config.sub_font_size || config.subFontSize,
    24,
    16,
    56,
  );
  if (config.sub_bold || config.subBold) {
    subtitleStyle.fontWeight = '700';
  }
  if (config.sub_italic || config.subItalic) {
    subtitleStyle.fontStyle = 'italic';
  }
  if (titleAlign) {
    subtitleStyle.textAlign = titleAlign;
  }
  legacyStyles.subtitle = subtitleStyle;

  return normalizeProfileTextStyles(legacyStyles, [
    'more',
    'subtitle',
    'title',
  ]);
};

const mergeMissingTextStyles = (
  primary: Record<string, any>,
  fallback: Record<string, any>,
) => {
  const roles = new Set([...Object.keys(fallback), ...Object.keys(primary)]);
  return Object.fromEntries(
    [...roles].map((role) => [
      role,
      {
        ...fallback[role],
        ...primary[role],
      },
    ]),
  );
};

const normalizeSpacingNumber = (value: unknown) => {
  const numberValue = Number(value ?? 0);
  if (!Number.isFinite(numberValue)) return 0;
  return Math.max(0, Math.round(numberValue));
};

const syncProfilePaddingCompat = (config: Record<string, any>) => {
  const top = normalizeSpacingNumber(
    config.paddingTop ??
      config.padding_top ??
      config.paddingY ??
      config.padding,
  );
  const right = normalizeSpacingNumber(
    config.paddingRight ??
      config.padding_right ??
      config.paddingX ??
      config.padding,
  );
  const bottom = normalizeSpacingNumber(
    config.paddingBottom ??
      config.padding_bottom ??
      config.paddingY ??
      config.padding,
  );
  const left = normalizeSpacingNumber(
    config.paddingLeft ??
      config.padding_left ??
      config.paddingX ??
      config.padding,
  );
  config.paddingTop = top;
  config.padding_top = top;
  config.paddingRight = right;
  config.padding_right = right;
  config.paddingBottom = bottom;
  config.padding_bottom = bottom;
  config.paddingLeft = left;
  config.padding_left = left;
  config.paddingY = top === bottom ? top : Math.round((top + bottom) / 2);
  config.padding_y = config.paddingY;
  config.paddingX = left === right ? left : Math.round((left + right) / 2);
  config.padding_x = config.paddingX;
  config.padding =
    top === right && right === bottom && bottom === left
      ? top
      : Math.round((top + right + bottom + left) / 4);
};

const syncProfileMarginCompat = (config: Record<string, any>) => {
  const top = normalizeSpacingNumber(config.marginTop ?? config.margin_top);
  const right = normalizeSpacingNumber(
    config.marginRight ?? config.margin_right,
  );
  const bottom = normalizeSpacingNumber(
    config.marginBottom ?? config.margin_bottom,
  );
  const left = normalizeSpacingNumber(config.marginLeft ?? config.margin_left);

  config.marginTop = top;
  config.margin_top = top;
  config.marginRight = right;
  config.margin_right = right;
  config.marginBottom = bottom;
  config.margin_bottom = bottom;
  config.marginLeft = left;
  config.margin_left = left;
};

const normalizeProfileItems = (
  items: unknown,
  type: string,
): Array<Record<string, any>> => {
  if (!Array.isArray(items)) return [];
  return items
    .filter((item) => item && typeof item === 'object')
    .map((item: any, index) => {
      const label =
        item.label ||
        item.title ||
        item.text ||
        (type === 'orderEntry'
          ? `订单入口${index + 1}`
          : `服务入口${index + 1}`);
      const imageRemoved =
        item.imageRemoved === true || item.image_removed === true;
      const image = imageRemoved
        ? ''
        : (item.image ??
          item.image_url ??
          item.imageUrl ??
          item.icon_image ??
          item.iconImage ??
          getDefaultProfileEntryImage(type, index));
      const rest = { ...item };
      if (rest.action === 'theme' && !rest.key) {
        rest.key = 'theme';
      }
      delete rest.action;
      delete rest.icon;
      if (imageRemoved) {
        delete rest.image_url;
        delete rest.imageUrl;
        delete rest.icon_image;
        delete rest.iconImage;
        delete rest.full_url;
        delete rest.fullUrl;
        delete rest.preview_url;
        delete rest.previewUrl;
        rest.imageRemoved = true;
        rest.image_removed = true;
      } else {
        delete rest.imageRemoved;
        delete rest.image_removed;
      }
      return {
        ...rest,
        enabled: item.enabled !== false && item.visible !== false,
        id: item.id || item.key || createId('profile_item'),
        image,
        label,
        path: item.path || item.url || item.link || item.target_path || '',
        title: label,
      };
    });
};

const getProfileDefaultTitle = (type: string) => {
  if (type === 'orderEntry') return '我的订单';
  if (type === 'pointsEntry') return '我的积分';
  return '我的服务';
};

const getProfileStyleDefaults = (type: string): Record<string, any> => {
  const base = {
    ...PROFILE_STYLE_DEFAULTS,
    background: '',
    marginBottom: 0,
    marginTop: 0,
    padding: 0,
    paddingX: 10,
    paddingY: 0,
    radius: 20,
    widthPercent: 100,
  };
  const defaults: Record<string, Record<string, any>> = {
    orderEntry: {
      ...base,
      paddingX: 28,
      paddingY: 28,
    },
    serviceMenu: base,
    userInfo: {
      ...base,
      paddingX: 28,
      paddingY: 28,
      radius: 0,
    },
    walletEntry: {
      ...base,
      paddingX: 28,
      paddingY: 28,
    },
    pointsEntry: {
      ...base,
      paddingX: 28,
      paddingY: 28,
    },
  };
  return clone(
    defaults[normalizeProfileModuleType(type)] || defaults.serviceMenu || base,
  );
};

const getHomeStyleDefaults = (): Record<string, any> =>
  clone({
    ...HOME_STYLE_DEFAULTS,
    background: '',
    marginBottom: 0,
    marginLeft: 0,
    marginRight: 0,
    marginTop: 0,
    padding: 0,
    paddingX: 0,
    paddingY: 0,
    radius: 0,
    widthPercent: 100,
  });

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
    ? normalizeTabbarEditorItems(schemeForm.schema)
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
  schemeType: ClientDecorateApi.SchemeType = activeType.value,
) => {
  const profileTypes = new Set([
    'orderEntry',
    'serviceMenu',
    'userInfo',
    'walletEntry',
    'pointsEntry',
  ]);
  const profileType = normalizeProfileModuleType(type);
  const isProfileModule =
    schemeType === 'profile' && profileTypes.has(profileType);
  const isHomeModule = schemeType === 'home';
  let config = clone(rawConfig || {});
  if (isProfileModule) {
    config = {
      ...getProfileStyleDefaults(profileType),
      ...config,
    };
  } else if (isHomeModule) {
    config = {
      ...getHomeStyleDefaults(),
      ...config,
    };
  }
  config.widthPercent = Number(
    rawConfig?.widthPercent ??
      rawConfig?.width_percent ??
      config.widthPercent ??
      100,
  );
  config.marginTop = Number(
    rawConfig?.marginTop ?? rawConfig?.margin_top ?? config.marginTop ?? 0,
  );
  config.marginBottom = Number(
    rawConfig?.marginBottom ??
      rawConfig?.margin_bottom ??
      config.marginBottom ??
      0,
  );
  config.radius =
    rawConfig?.radius === undefined && rawConfig?.border_radius === undefined
      ? config.radius
      : (rawConfig?.radius ?? rawConfig?.border_radius);
  config.radius =
    config.radius === undefined || config.radius === null
      ? 0
      : Number(config.radius);
  config.padding =
    rawConfig?.padding === undefined && rawConfig?.padding_y === undefined
      ? config.padding
      : (rawConfig?.padding ?? rawConfig?.padding_y);
  config.padding =
    config.padding === undefined || config.padding === null
      ? 0
      : Number(config.padding);
  config.paddingY = Number(
    rawConfig?.paddingY ??
      rawConfig?.padding_y ??
      config.paddingY ??
      config.padding,
  );
  config.paddingX = Number(
    rawConfig?.paddingX ??
      rawConfig?.padding_x ??
      config.paddingX ??
      config.padding,
  );
  config.paddingTop = Number(
    rawConfig?.paddingTop ??
      rawConfig?.padding_top ??
      config.paddingTop ??
      config.paddingY ??
      config.padding,
  );
  config.paddingRight = Number(
    rawConfig?.paddingRight ??
      rawConfig?.padding_right ??
      config.paddingRight ??
      config.paddingX ??
      config.padding,
  );
  config.paddingBottom = Number(
    rawConfig?.paddingBottom ??
      rawConfig?.padding_bottom ??
      config.paddingBottom ??
      config.paddingY ??
      config.padding,
  );
  config.paddingLeft = Number(
    rawConfig?.paddingLeft ??
      rawConfig?.padding_left ??
      config.paddingLeft ??
      config.paddingX ??
      config.padding,
  );
  config.background = rawConfig?.background ?? config.background ?? '';
  const rawBackground =
    typeof rawConfig?.background === 'string' && rawConfig.background.trim()
      ? rawConfig.background
      : undefined;
  if (isHomeModule || isProfileModule) {
    const styleDefaults = isProfileModule
      ? PROFILE_STYLE_DEFAULTS
      : HOME_STYLE_DEFAULTS;
    config.backgroundMode =
      rawConfig?.backgroundMode ??
      rawConfig?.background_mode ??
      config.backgroundMode ??
      'color';
    config.backgroundColorStart =
      rawConfig?.backgroundColorStart ??
      rawConfig?.background_color_start ??
      rawBackground ??
      config.backgroundColorStart ??
      styleDefaults.backgroundColorStart;
    config.backgroundColorEnd =
      rawConfig?.backgroundColorEnd ??
      rawConfig?.background_color_end ??
      rawBackground ??
      config.backgroundColorEnd ??
      config.backgroundColorStart ??
      styleDefaults.backgroundColorEnd;
    config.backgroundGradientDirection =
      rawConfig?.backgroundGradientDirection ??
      rawConfig?.background_gradient_direction ??
      config.backgroundGradientDirection ??
      styleDefaults.backgroundGradientDirection;
    config.background_image = normalizeEditorUploadImage(
      rawConfig?.background_image &&
        typeof rawConfig.background_image !== 'object'
        ? {
            full_url:
              rawConfig?.background_image_full_url ||
              rawConfig?.backgroundImageFullUrl ||
              '',
            url: rawConfig.background_image,
          }
        : (rawConfig?.background_image ??
            rawConfig?.backgroundImage ??
            config.background_image),
    );
    delete config.bottomBackground;
    delete config.bottom_background;
    delete config.componentBackgroundEnd;
    delete config.component_background_end;
    delete config.componentBackgroundStart;
    delete config.component_background_start;
    delete config.textColor;
    delete config.text_color;
    config.marginLeft = Number(
      rawConfig?.marginLeft ??
        rawConfig?.margin_left ??
        config.marginLeft ??
        styleDefaults.marginLeft,
    );
    config.marginRight = Number(
      rawConfig?.marginRight ??
        rawConfig?.margin_right ??
        config.marginRight ??
        styleDefaults.marginRight,
    );
    config.borderEnabled = normalizeBooleanValue(
      rawConfig?.borderEnabled ??
        rawConfig?.border_enabled ??
        config.borderEnabled,
      styleDefaults.borderEnabled,
    );
    config.borderStyle =
      rawConfig?.borderStyle ??
      rawConfig?.border_style ??
      config.borderStyle ??
      styleDefaults.borderStyle;
    config.borderWidth = Number(
      rawConfig?.borderWidth ??
        rawConfig?.border_width ??
        config.borderWidth ??
        styleDefaults.borderWidth,
    );
    config.borderColor =
      rawConfig?.borderColor ??
      rawConfig?.border_color ??
      config.borderColor ??
      styleDefaults.borderColor;
    normalizeShadowStyle(config);
  }
  if (isHomeModule || isProfileModule) {
    const textStyleType = isProfileModule ? profileType : type;
    const profileTextStyleRoles = getProfileTextStyleRoles(textStyleType);
    const legacyTextStyles =
      isHomeModule && type === 'title'
        ? buildTitleLegacyTextStyles(rawConfig || config)
        : {};
    const textStyles = normalizeProfileTextStyles(
      mergeMissingTextStyles(
        normalizeProfileTextStyles(
          rawConfig?.textStyles ?? rawConfig?.text_styles ?? config.textStyles,
          profileTextStyleRoles,
        ),
        legacyTextStyles,
      ),
      profileTextStyleRoles,
    );
    if (Object.keys(textStyles).length > 0) {
      config.textStyles = textStyles;
    } else {
      delete config.textStyles;
    }
    delete config.text_styles;
    delete config.textVisibility;
    delete config.text_visibility;
  }
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
    config.paddingTop = Number(
      rawConfig.paddingTop ??
        rawConfig.padding_top ??
        config.paddingTop ??
        config.paddingY,
    );
    config.paddingRight = Number(
      rawConfig.paddingRight ??
        rawConfig.padding_right ??
        config.paddingRight ??
        config.paddingX,
    );
    config.paddingBottom = Number(
      rawConfig.paddingBottom ??
        rawConfig.padding_bottom ??
        config.paddingBottom ??
        config.paddingY,
    );
    config.paddingLeft = Number(
      rawConfig.paddingLeft ??
        rawConfig.padding_left ??
        config.paddingLeft ??
        config.paddingX,
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
    config.icon_image = normalizeEditorUploadImage(
      config.icon_image || config.iconImage || '',
    );
    delete config.icon;
    delete config.iconMode;
    delete config.icon_mode;
    delete config.iconColor;
    delete config.icon_color;
    delete config.iconBackground;
    delete config.icon_background;
    config.background_image =
      config.background_image || config.backgroundImage || '';
    config.show_arrow =
      config.show_arrow === undefined ? true : Boolean(config.show_arrow);
  }
  if (type === 'imageCube') {
    config.layout = normalizeCubeLayout(config.layout);
  }
  if (type === 'richText') {
    config.content = config.content || config.html || '';
    config.radius = Number(config.radius ?? 24);
    config.padding = Number(config.padding ?? 24);
    config.paddingY = Number(config.paddingY ?? config.padding);
    config.paddingX = Number(config.paddingX ?? config.padding);
    config.paddingTop = Number(config.paddingTop ?? config.paddingY);
    config.paddingRight = Number(config.paddingRight ?? config.paddingX);
    config.paddingBottom = Number(config.paddingBottom ?? config.paddingY);
    config.paddingLeft = Number(config.paddingLeft ?? config.paddingX);
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
  if (['orderEntry', 'serviceMenu'].includes(type)) {
    let sourceItems: any[] = [];
    if (Array.isArray(config.items) && config.items.length > 0) {
      sourceItems = config.items;
    } else if (Array.isArray(config.list) && config.list.length > 0) {
      sourceItems = config.list;
    } else {
      sourceItems =
        type === 'orderEntry'
          ? defaultProfileOrderItems()
          : defaultProfileServiceItems();
    }
    config.items = normalizeProfileItems(sourceItems, type);
    config.list = config.items;
    config.title = config.title || getProfileDefaultTitle(type);
    config.columns = clampNumber(config.columns, 4, 3, 5);
    config.display =
      type === 'orderEntry' ? 'grid' : normalizeProfileDisplay(config.display);
  }
  if (type === 'userInfo') {
    delete config.show_level;
    config.show_mobile = normalizeBooleanValue(config.show_mobile, true);
  }
  if (type === 'walletEntry') {
    config.title = config.title || '我的余额';
    config.show_balance = normalizeBooleanValue(config.show_balance, true);
    delete config.show_points;
    config.show_records = normalizeBooleanValue(config.show_records, true);
    config.show_view_button = normalizeBooleanValue(
      config.show_view_button,
      true,
    );
  }
  if (type === 'pointsEntry') {
    config.title = config.title || '我的积分';
    config.show_records = normalizeBooleanValue(config.show_records, true);
    config.show_view_button = normalizeBooleanValue(
      config.show_view_button,
      true,
    );
  }
  if (isHomeModule || isProfileModule) {
    syncProfilePaddingCompat(config);
    syncProfileMarginCompat(config);
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
    {
      ...(item.data && typeof item.data === 'object' ? item.data : {}),
      ...(item.props && typeof item.props === 'object' ? item.props : {}),
      ...(item.config && typeof item.config === 'object' ? item.config : {}),
    },
    schemeType,
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
    ...getHomeStyleDefaults(),
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
      background_image: createDecorateAssetFile(
        '1014',
        'decorate-entry-category.png',
      ),
      icon_image: createDecorateAssetFile('1014', 'decorate-entry-category.png'),
      padding: 24,
      path: '/pages/category/index',
      radius: 24,
      show_arrow: true,
      subtitle: '查看全部商品分类',
      title: '热门分类',
    }),
    divider: withStyle({ color: '', height: 1, style: 'solid' }),
    imageCube: withStyle({
      images: clone(DEFAULT_CUBE_ITEMS),
      items: clone(DEFAULT_CUBE_ITEMS),
      list: clone(DEFAULT_CUBE_ITEMS),
      layout: 'four',
      radius: 20,
      textStyles: {
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

const defaultProfileOrderItems = () => [
  {
    id: createId('profile_item'),
    image: DEFAULT_PROFILE_ORDER_IMAGE_BY_INDEX[0],
    path: '/pages-sub/order/list?status=10',
    title: '待付款',
  },
  {
    id: createId('profile_item'),
    image: DEFAULT_PROFILE_ORDER_IMAGE_BY_INDEX[1],
    path: '/pages-sub/order/list?status=20',
    title: '待发货',
  },
  {
    id: createId('profile_item'),
    image: DEFAULT_PROFILE_ORDER_IMAGE_BY_INDEX[2],
    path: '/pages-sub/order/list?status=30',
    title: '待收货',
  },
  {
    id: createId('profile_item'),
    image: DEFAULT_PROFILE_ORDER_IMAGE_BY_INDEX[3],
    path: '/pages-sub/refund/list',
    title: '退款售后',
  },
];

const defaultProfileServiceItems = () => [
  {
    id: createId('profile_item'),
    image: DEFAULT_PROFILE_SERVICE_IMAGE_BY_INDEX[0],
    path: '/pages-sub/address/list',
    title: '地址管理',
  },
  {
    id: createId('profile_item'),
    image: DEFAULT_PROFILE_SERVICE_IMAGE_BY_INDEX[1],
    path: '/pages-sub/user/settings',
    title: '系统设置',
  },
  {
    id: createId('profile_item'),
    image: DEFAULT_PROFILE_SERVICE_IMAGE_BY_INDEX[2],
    path: '',
    title: '联系客服',
  },
];

const defaultProfileConfig = (type: string): Record<string, any> => {
  const withStyle = (moduleType: string, config: Record<string, any>) => ({
    ...getProfileStyleDefaults(moduleType),
    ...config,
  });
  const defaults: Record<string, Record<string, any>> = {
    orderEntry: withStyle('orderEntry', {
      display: 'grid',
      items: defaultProfileOrderItems(),
      title: '我的订单',
    }),
    serviceMenu: withStyle('serviceMenu', {
      columns: 4,
      display: 'list',
      items: defaultProfileServiceItems(),
      title: '我的服务',
    }),
    userInfo: withStyle('userInfo', {
      show_mobile: true,
    }),
    walletEntry: withStyle('walletEntry', {
      show_balance: true,
      show_records: true,
      show_view_button: true,
      title: '我的余额',
    }),
    pointsEntry: withStyle('pointsEntry', {
      show_records: true,
      show_view_button: true,
      title: '我的积分',
    }),
  };
  const config = defaults[normalizeProfileModuleType(type)] ||
    defaults.serviceMenu || { columns: 4, items: [] };
  syncProfilePaddingCompat(config);
  return clone(config);
};

const defaultTabbarItem = (key = 'category'): ModuleItem => ({
  icon: getTabbarStaticImagePath(key),
  icon_mode: 'upload',
  id: createId('tabbar'),
  path: '',
  selected_icon: getTabbarStaticImagePath(key, true),
  selected_icon_mode: 'upload',
  text: '导航',
});

const defaultTabbarItems = (): ModuleItem[] => [
  {
    ...defaultTabbarItem('home'),
    path: '/pages/index/index',
    text: '首页',
  },
  {
    ...defaultTabbarItem('profile'),
    path: '/pages/profile/index',
    text: '我的',
  },
];

const defaultFloatingItems = (): ModuleItem[] => [
  {
    enabled: true,
    icon: normalizeEditorUploadImage(getFloatingStaticIconPath('service')),
    id: 'floating-service',
    text: '客服',
    type: 'customerService',
  },
  {
    enabled: true,
    icon: normalizeEditorUploadImage(getFloatingStaticIconPath('cart')),
    id: 'floating-cart',
    path: '/pages/cart/index',
    text: '购物车',
    type: 'page',
  },
  {
    enabled: true,
    icon: normalizeEditorUploadImage(getFloatingStaticIconPath('home')),
    id: 'floating-home',
    path: '/pages/index/index',
    text: '首页',
    type: 'page',
  },
];

const defaultFloatingItem = (): ModuleItem => ({
  enabled: true,
  icon: '',
  id: createId('floating'),
  path: '/pages/index/index',
  text: '入口',
  type: 'page',
});

const getSystemFloatingItemType = (item: ModuleItem) => {
  const id = String(item.id || item.key || '');
  if (SYSTEM_FLOATING_ITEM_IDS.has(id)) return id.replace('floating-', '');

  const text = String(item.text || '').trim();
  const path = String(item.path || '').split(/[?#]/)[0]?.replace(/\/+$/, '');
  if (item.type === 'customerService' && text === '客服') return 'service';
  if (item.type === 'page' && text === '购物车' && path === '/pages/cart/index')
    return 'cart';
  if (item.type === 'page' && text === '首页' && path === '/pages/index/index')
    return 'home';
  return '';
};

const isSystemFloatingItem = (item: ModuleItem) =>
  Boolean(getSystemFloatingItemType(item));

const hasFloatingIcon = (item: ModuleItem) =>
  Boolean(normalizeUploadValue(item.icon || ''));

const normalizeFloatingConfig = (schema: any): FloatingConfig => {
  const source = schema && typeof schema === 'object' ? schema : {};
  const style =
    source.style && typeof source.style === 'object' ? source.style : {};
  const mode = ['expand', 'single', 'vertical'].includes(source.mode)
    ? source.mode
    : DEFAULT_FLOATING_CONFIG.mode;
  const position = ['left-bottom', 'right-bottom'].includes(source.position)
    ? source.position
    : DEFAULT_FLOATING_CONFIG.position;
  const hiddenPages = Array.isArray(source.hiddenPages)
    ? source.hiddenPages
    : Array.isArray(source.hidden_pages)
      ? source.hidden_pages
      : DEFAULT_FLOATING_CONFIG.hiddenPages;
  return {
    enabled: source.enabled !== false,
    hiddenPages: hiddenPages
      .map((item: unknown) => String(item || '').trim())
      .filter(Boolean),
    mode,
    offsetBottom: clampNumber(
      source.offsetBottom ?? source.offset_bottom,
      DEFAULT_FLOATING_CONFIG.offsetBottom,
      0,
      360,
    ),
    offsetX: clampNumber(
      source.offsetX ?? source.offset_x,
      DEFAULT_FLOATING_CONFIG.offsetX,
      0,
      160,
    ),
    position,
    singleItemId: String(source.singleItemId ?? source.single_item_id ?? ''),
    style: {
      backgroundColor: String(
        style.backgroundColor ??
          style.background_color ??
          DEFAULT_FLOATING_CONFIG.style.backgroundColor,
      ),
      color: String(style.color ?? DEFAULT_FLOATING_CONFIG.style.color),
      radius: clampNumber(
        style.radius,
        DEFAULT_FLOATING_CONFIG.style.radius,
        0,
        120,
      ),
      shadowBlur: clampNumber(
        style.shadowBlur ?? style.shadow_blur,
        DEFAULT_FLOATING_CONFIG.style.shadowBlur,
        0,
        160,
      ),
      shadowColor: String(
        style.shadowColor ??
          style.shadow_color ??
          DEFAULT_FLOATING_CONFIG.style.shadowColor,
      ),
      shadowEnabled:
        (style.shadowEnabled ?? style.shadow_enabled ?? true) ? true : false,
      shadowOffsetX: clampNumber(
        style.shadowOffsetX ?? style.shadow_offset_x,
        DEFAULT_FLOATING_CONFIG.style.shadowOffsetX,
        -80,
        80,
      ),
      shadowOffsetY: clampNumber(
        style.shadowOffsetY ?? style.shadow_offset_y,
        DEFAULT_FLOATING_CONFIG.style.shadowOffsetY,
        -80,
        80,
      ),
      shadowOpacity: clampNumber(
        style.shadowOpacity ?? style.shadow_opacity,
        DEFAULT_FLOATING_CONFIG.style.shadowOpacity,
        0,
        100,
      ),
      shadowSpread: clampNumber(
        style.shadowSpread ?? style.shadow_spread,
        DEFAULT_FLOATING_CONFIG.style.shadowSpread,
        -80,
        80,
      ),
      size: clampNumber(
        style.size,
        DEFAULT_FLOATING_CONFIG.style.size,
        56,
        128,
      ),
    },
  };
};

const normalizeFloatingEditorItem = (
  item: ModuleItem = {},
  index = 0,
): ModuleItem => {
  const type = item.type === 'customerService' ? 'customerService' : 'page';
  return {
    enabled: item.enabled !== false && item.visible !== false,
    icon: normalizeEditorUploadImage(item.icon || ''),
    id: item.id || item.key || createId('floating'),
    path: type === 'page' ? item.path || '/pages/index/index' : '',
    sort: item.sort ?? index,
    text:
      item.text || item.title || (type === 'customerService' ? '客服' : '入口'),
    type,
  };
};

const normalizeFloatingSaveItem = (item: ModuleItem, index: number) => {
  const type = item.type === 'customerService' ? 'customerService' : 'page';
  return {
    enabled: item.enabled !== false,
    icon: normalizeUploadValue(item.icon || ''),
    id: item.id || createId('floating'),
    path: type === 'page' ? item.path || '/pages/index/index' : '',
    sort: item.sort ?? index,
    text: item.text || '入口',
    type,
  };
};

const getEnabledFloatingItems = () =>
  schemeForm.schema.filter((item) => item && item.enabled !== false);

const normalizeFloatingSingleItemId = (value?: string) => {
  const enabledItems = getEnabledFloatingItems();
  if (enabledItems.length === 0) return '';
  const id = String(value || '').trim();
  if (id && enabledItems.some((item) => item.id === id)) return id;
  return String(enabledItems[0]?.id || '');
};

const ensureFloatingSingleItemId = () => {
  if (activeType.value !== 'floating') return;
  schemeForm.floatingConfig.singleItemId = normalizeFloatingSingleItemId(
    schemeForm.floatingConfig.singleItemId,
  );
};

const resetSchemeForm = (type = activeType.value) => {
  selectedSchemeId.value = null;
  selectedIndex.value = 0;
  Object.assign(schemeForm, {
    description: '',
    floatingConfig: {
      ...DEFAULT_FLOATING_CONFIG,
      hiddenPages: [...DEFAULT_FLOATING_CONFIG.hiddenPages],
      style: { ...DEFAULT_FLOATING_CONFIG.style },
    },
    name: `${activeTypeLabel.value}方案`,
    pageStyle: { ...getDefaultPageStyle(type) },
    schema:
      type === 'tabbar'
        ? defaultTabbarItems()
        : type === 'floating'
          ? defaultFloatingItems()
          : [],
    sort: 0,
    status: 1,
    tabbar_mode: type === 'tabbar' ? 'custom' : 'native',
  });
  ensureFloatingSingleItemId();
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
    const [themes, setting] = await Promise.all([
      getClientThemeListApi({ limit: 100, page: 1, status: 1 }),
      getClientThemeSettingApi(),
    ]);
    themeList.value = themes.list || [];
    themeSetting.value = {
      ...DEFAULT_THEME_SETTING,
      ...setting,
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
    floatingConfig:
      activeType.value === 'floating'
        ? normalizeFloatingConfig(detail.schema)
        : {
            ...DEFAULT_FLOATING_CONFIG,
            hiddenPages: [...DEFAULT_FLOATING_CONFIG.hiddenPages],
            style: { ...DEFAULT_FLOATING_CONFIG.style },
          },
    name: detail.name,
    pageStyle:
      activeType.value === 'tabbar' || activeType.value === 'floating'
        ? clone(DEFAULT_HOME_PAGE_STYLE)
        : getSchemePageStyle(detail, activeType.value),
    schema:
      activeType.value === 'floating'
        ? clone(schema).map((item: ModuleItem, index: number) =>
            normalizeFloatingEditorItem(item, index),
          )
        : activeType.value === 'tabbar'
          ? normalizeTabbarEditorItems(clone(schema))
          : clone(schema).map((item: ModuleItem, index: number) =>
              normalizeEditorModule(item, index, activeType.value),
            ),
    sort: detail.sort || 0,
    status: detail.status ?? 1,
    tabbar_mode:
      activeType.value === 'tabbar' ? 'custom' : detail.tabbar_mode || 'native',
  });
  ensureFloatingSingleItemId();
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
  message.success('复制成功，可在列表中编辑副本');
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
  if (activeType.value === 'floating') {
    return stripRuntimePreviewFields(
      normalizeUploadValue({
        ...schemeForm.floatingConfig,
        hiddenPages: schemeForm.floatingConfig.hiddenPages
          .map((item) => String(item || '').trim())
          .filter(Boolean),
        singleItemId: normalizeFloatingSingleItemId(
          schemeForm.floatingConfig.singleItemId,
        ),
        items: clone(schema).map((item, index) =>
          normalizeFloatingSaveItem(item, index),
        ),
        style: {
          ...schemeForm.floatingConfig.style,
        },
      }),
    ) as ClientDecorateApi.FloatingSchemeSchema;
  }

  if (activeType.value === 'tabbar') {
    const items = normalizeTabbarEditorItems(clone(schema)).map((item, index) =>
      normalizeTabbarSaveItem(item, index),
    );
    return {
      items: stripRuntimePreviewFields(normalizeUploadValue(items)),
    };
  }

  const modules = stripRuntimePreviewFields(
    normalizeUploadValue(
      clone(schema).map((item: ModuleItem, index: number) => {
        const props = {
          ...(item.data && typeof item.data === 'object' ? item.data : {}),
          ...(item.props && typeof item.props === 'object' ? item.props : {}),
          ...(item.config && typeof item.config === 'object'
            ? item.config
            : {}),
        };
        const moduleType = normalizeModuleTypeByScheme(
          String(item.type || ''),
          activeType.value,
        );
        if (
          activeType.value === 'profile' &&
          ['orderEntry', 'serviceMenu'].includes(moduleType)
        ) {
          let sourceItems: any[] = [];
          if (Array.isArray(props.items) && props.items.length > 0) {
            sourceItems = props.items;
          } else if (Array.isArray(props.list) && props.list.length > 0) {
            sourceItems = props.list;
          } else {
            sourceItems =
              moduleType === 'orderEntry'
                ? defaultProfileOrderItems()
                : defaultProfileServiceItems();
          }
          props.items = normalizeProfileItems(sourceItems, moduleType);
          props.list = props.items;
          props.columns = clampNumber(props.columns, 4, 3, 5);
          props.display =
            moduleType === 'orderEntry'
              ? 'grid'
              : normalizeProfileDisplay(props.display);
          props.title = props.title || getProfileDefaultTitle(moduleType);
        }
        if (activeType.value === 'profile' && moduleType === 'userInfo') {
          delete props.show_level;
          props.show_mobile = normalizeBooleanValue(props.show_mobile, true);
        }
        if (activeType.value === 'profile' && moduleType === 'walletEntry') {
          props.title = props.title || '我的余额';
          props.show_balance = normalizeBooleanValue(props.show_balance, true);
          delete props.show_points;
          props.show_records = normalizeBooleanValue(props.show_records, true);
          props.show_view_button = normalizeBooleanValue(
            props.show_view_button,
            true,
          );
        }
        if (activeType.value === 'profile' && moduleType === 'pointsEntry') {
          props.title = props.title || '我的积分';
          props.show_records = normalizeBooleanValue(props.show_records, true);
          props.show_view_button = normalizeBooleanValue(
            props.show_view_button,
            true,
          );
        }
        if (activeType.value === 'home' && moduleType === 'entryCard') {
          props.icon_image = normalizeEditorUploadImage(
            props.icon_image || props.iconImage || '',
          );
          delete props.icon;
          delete props.iconMode;
          delete props.icon_mode;
          delete props.iconColor;
          delete props.icon_color;
          delete props.iconBackground;
          delete props.icon_background;
        }
        if (activeType.value === 'home' && moduleType === 'imageCube') {
          props.layout = normalizeCubeLayout(props.layout);
        }
        if (activeType.value === 'home' || activeType.value === 'profile') {
          syncProfilePaddingCompat(props);
          syncProfileMarginCompat(props);
          normalizeShadowStyle(props);
        }
        if (activeType.value === 'home' || activeType.value === 'profile') {
          const profileTextStyleRoles = getProfileTextStyleRoles(moduleType);
          const legacyTextStyles =
            activeType.value === 'home' && moduleType === 'title'
              ? buildTitleLegacyTextStyles(props)
              : {};
          const textStyles = normalizeProfileTextStyles(
            mergeMissingTextStyles(
              normalizeProfileTextStyles(
                props.textStyles ?? props.text_styles,
                profileTextStyleRoles,
              ),
              legacyTextStyles,
            ),
            profileTextStyleRoles,
          );
          if (Object.keys(textStyles).length > 0) {
            props.textStyles = textStyles;
          } else {
            delete props.textStyles;
          }
          delete props.text_styles;
        }
        delete props.textVisibility;
        delete props.text_visibility;

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
      pageStyle: normalizePageStyle(schemeForm.pageStyle, activeType.value),
    };
  }

  if (activeType.value === 'profile') {
    return {
      modules: modules as ClientDecorateApi.ProfileModule[],
      pageStyle: normalizePageStyle(schemeForm.pageStyle, activeType.value),
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
  if (activeType.value === 'floating') {
    if (schemeForm.schema.length === 0) {
      message.warning('请至少配置 1 个悬浮入口');
      return false;
    }
    const invalid = schemeForm.schema.some((item) => {
      if (!item.text) return true;
      return item.type === 'page' && !item.path;
    });
    if (invalid) {
      message.warning('请完善悬浮入口名称和页面路径');
      return false;
    }
    const missingCustomIcon = schemeForm.schema.some(
      (item) => !isSystemFloatingItem(item) && !hasFloatingIcon(item),
    );
    if (missingCustomIcon) {
      message.warning('自定义悬浮入口请上传入口图标');
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
  const result = await copyClientDecorateSchemeApi(selectedSchemeId.value);
  selectedSchemeId.value = result.id;
  selectedIndex.value = 0;
  message.success('已复制，正在编辑副本');
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
  if (activeType.value === 'floating') {
    if (schemeForm.schema.length >= 6) {
      message.warning('悬浮入口最多 6 项');
      return;
    }
    item = defaultFloatingItem();
  } else if (activeType.value === 'tabbar') {
    if (schemeForm.schema.length >= 5) {
      message.warning('底部导航最多 5 项');
      return;
    }
    item = defaultTabbarItem(
      TABBAR_DEFAULT_IMAGE_KEYS[schemeForm.schema.length] || 'category',
    );
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
  if (activeType.value === 'floating') {
    ensureFloatingSingleItemId();
  }
};

const removeModule = (index: number) => {
  if (warnReadonlyScheme()) return;
  if (activeType.value === 'floating' && schemeForm.schema.length <= 1) {
    message.warning('悬浮按钮至少 1 项');
    return;
  }
  if (activeType.value === 'tabbar' && schemeForm.schema.length <= 2) {
    message.warning('底部导航至少 2 项');
    return;
  }
  schemeForm.schema.splice(index, 1);
  ensureSelectedIndex();
  if (activeType.value === 'floating') {
    ensureFloatingSingleItemId();
  }
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

const getPaletteClickInsertIndex = () => {
  if (!['home', 'profile'].includes(activeType.value)) {
    return schemeForm.schema.length;
  }
  if (!selectedModule.value) return schemeForm.schema.length;

  const currentIndex = schemeForm.schema.findIndex(
    (item) => item.id === selectedModule.value?.id,
  );
  if (currentIndex === -1) return schemeForm.schema.length;
  return currentIndex + 1;
};

const handlePaletteClick = (type: string) => {
  if (suppressPaletteClick.value) {
    suppressPaletteClick.value = false;
    return;
  }
  addModuleByType(type, getPaletteClickInsertIndex());
};

const addNavItem = (module: ModuleItem) => {
  if (warnReadonlyScheme()) return;
  const items = (module.config.items ||= []);
  items.push(clone(DEFAULT_NAV_ITEMS[items.length % DEFAULT_NAV_ITEMS.length]));
};

const addProfileItem = (module: ModuleItem) => {
  if (warnReadonlyScheme()) return;
  const type = normalizeProfileModuleType(String(module.type || ''));
  const items = (module.config.items ||= []);
  const defaults =
    type === 'orderEntry'
      ? defaultProfileOrderItems()
      : defaultProfileServiceItems();
  const defaultItem: Record<string, any> = defaults[
    items.length % defaults.length
  ] || {
    image: getDefaultProfileEntryImage(type, items.length),
    path: '',
    title: '菜单项',
  };
  const label = defaultItem.label || defaultItem.title || '菜单项';
  const nextItem = clone(defaultItem);
  delete nextItem.action;
  delete nextItem.icon;
  items.push({
    ...nextItem,
    enabled: true,
    id: createId('profile_item'),
    label,
    title: label,
    visible: true,
  });
  module.config.list = items;
};

const removeConfigItem = (items: any[], index: number | string) => {
  if (warnReadonlyScheme()) return;
  items.splice(Number(index), 1);
};

const updateHomePageStyle = (field: string, value: unknown) => {
  if (warnReadonlyScheme()) return;
  const nextValue = field.startsWith('padding')
    ? normalizePageSpacingNumber(value)
    : value;
  (schemeForm.pageStyle as Record<string, unknown>)[field] = nextValue;
  if (activeType.value === 'home' || activeType.value === 'profile') {
    Object.assign(
      schemeForm.pageStyle,
      normalizePageStyle(schemeForm.pageStyle, activeType.value),
    );
  }
};

const resetHomePageStyle = () => {
  if (warnReadonlyScheme()) return;
  schemeForm.pageStyle = { ...getDefaultPageStyle(activeType.value) };
  message.success('已重置页面样式');
};

const resetFloatingStyle = () => {
  if (warnReadonlyScheme()) return;
  schemeForm.floatingConfig.position = DEFAULT_FLOATING_CONFIG.position;
  schemeForm.floatingConfig.offsetX = DEFAULT_FLOATING_CONFIG.offsetX;
  schemeForm.floatingConfig.offsetBottom = DEFAULT_FLOATING_CONFIG.offsetBottom;
  schemeForm.floatingConfig.style = { ...DEFAULT_FLOATING_CONFIG.style };
  message.success('已重置基础样式');
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

const resetModuleStyle = (module: ModuleItem) => {
  if (warnReadonlyScheme()) return;
  if (!module || activeType.value === 'tabbar') return;
  const type = String(module.type || '');
  const defaults =
    activeType.value === 'profile'
      ? defaultProfileConfig(normalizeProfileModuleType(type))
      : defaultHomeConfig(type as ClientDecorateApi.ComponentType);
  const nextConfig = { ...module.config };
  MODULE_STYLE_FIELDS.forEach((field) => {
    if (field in defaults) {
      nextConfig[field] = clone(defaults[field]);
    } else {
      delete nextConfig[field];
    }
  });
  syncProfilePaddingCompat(nextConfig);
  syncProfileMarginCompat(nextConfig);
  normalizeShadowStyle(nextConfig);
  module.config = nextConfig;
  message.success('已重置基础样式');
};

const resetModuleContent = (module: ModuleItem) => {
  if (warnReadonlyScheme()) return;
  if (!module || activeType.value === 'tabbar') return;
  if (activeType.value === 'floating') {
    const id = module.id || createId('floating');
    const sort = module.sort;
    const defaults =
      module.type === 'customerService'
        ? {
            enabled: true,
            icon: '',
            text: '客服',
            type: 'customerService',
          }
        : defaultFloatingItem();
    Object.keys(module).forEach((key) => delete module[key]);
    Object.assign(module, defaults, { id });
    if (sort !== undefined) {
      module.sort = sort;
    }
    ensureFloatingSingleItemId();
    message.success('已重置入口内容');
    return;
  }
  const rawType = String(module.type || '');
  const type =
    activeType.value === 'profile'
      ? normalizeProfileModuleType(rawType)
      : rawType;
  const currentConfig = { ...module.config };
  syncProfilePaddingCompat(currentConfig);
  syncProfileMarginCompat(currentConfig);
  normalizeShadowStyle(currentConfig);
  const styleSnapshot: Record<string, any> = {};
  for (const field of MODULE_STYLE_FIELDS) {
    if (field in currentConfig) {
      styleSnapshot[field] = clone(currentConfig[field]);
    }
  }
  if (
    currentConfig.textStyles &&
    typeof currentConfig.textStyles === 'object'
  ) {
    styleSnapshot.textStyles = clone(currentConfig.textStyles);
  }
  module.config = {
    ...(activeType.value === 'profile'
      ? defaultProfileConfig(type)
      : defaultHomeConfig(type as ClientDecorateApi.ComponentType)),
    ...styleSnapshot,
  };
  syncProfilePaddingCompat(module.config);
  syncProfileMarginCompat(module.config);
  normalizeShadowStyle(module.config);
  message.success('已重置组件内容');
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
    :class="{
      'decorate-page--editor': viewMode !== 'overview',
      'decorate-page--profile': activeType === 'profile',
    }"
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
      :floating-config="schemeForm.floatingConfig"
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
      @reset-floating-style="resetFloatingStyle"
      @reset-module-content="resetModuleContent"
      @reset-module-config="resetModuleConfig"
      @reset-module-style="resetModuleStyle"
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
