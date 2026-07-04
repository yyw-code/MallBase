<script lang="ts" setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue';

import { IconifyIcon } from '@vben/icons';

type ModuleItem = Record<string, any>;
type PreviewRecord = Record<string, any>;

type PreviewKind =
  | 'category'
  | 'floating'
  | 'goodsDetail'
  | 'home'
  | 'profile'
  | 'tabbar';

const props = withDefaults(
  defineProps<{
    categoryTree?: PreviewRecord[];
    currentPath?: string;
    dragging?: boolean;
    dropIndex?: null | number;
    floatingConfig?: Record<string, any>;
    goods?: null | PreviewRecord;
    goodsList?: PreviewRecord[];
    interactive?: boolean;
    kind?: PreviewKind;
    modules?: ModuleItem[];
    pageStyle?: Record<string, any>;
    selectedModuleId?: null | string;
    size?: 'compact' | 'normal';
    tabbarItems?: ModuleItem[];
    themeTokens?: Record<string, string>;
    title?: string;
  }>(),
  {
    categoryTree: () => [],
    currentPath: '/pages/index/index',
    dragging: false,
    dropIndex: null,
    floatingConfig: () => ({}),
    goods: null,
    goodsList: () => [],
    interactive: false,
    kind: 'home',
    modules: () => [],
    pageStyle: () => ({}),
    selectedModuleId: null,
    size: 'normal',
    tabbarItems: () => [],
    themeTokens: () => ({}),
    title: '',
  },
);

const emit = defineEmits<{
  moduleDelete: [index: number];
  moduleMouseDown: [index: number, event: MouseEvent];
  moduleMove: [index: number, direction: 'down' | 'up'];
  selectModule: [module: ModuleItem];
}>();

const bannerPreviewTick = ref(0);
const previewRootRef = ref<HTMLElement | null>(null);
const selectedModuleControlReady = ref(false);
const selectedModuleControlStyle = ref<Record<string, string>>({});
const selectedModuleFrameReady = ref(false);
const selectedModuleFrameStyle = ref<Record<string, string>>({});
let bannerPreviewTimer: ReturnType<typeof setInterval> | undefined;

const THEME_ACTION_PATH = 'mb-action://theme';
const THEME_PAGE_PATH = '/pages-sub/user/theme';

onMounted(() => {
  bannerPreviewTimer = setInterval(() => {
    bannerPreviewTick.value += 1;
  }, 1000);
  window.addEventListener('resize', updateSelectedModuleControlPosition);
  updateSelectedModuleControlPosition();
});

onBeforeUnmount(() => {
  if (bannerPreviewTimer) clearInterval(bannerPreviewTimer);
  window.removeEventListener('resize', updateSelectedModuleControlPosition);
});

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

const PREVIEW_EXTERNAL_CONTROL_VERTICAL_INSET = 56;

const DEFAULT_HOME_MODULES: ModuleItem[] = [
  {
    id: 'preview-search',
    props: { placeholder: '搜索你心仪的商品...' },
    type: 'search',
  },
  {
    id: 'preview-banner',
    props: {
      height: 314,
      list: [
        {
          image: 'static/demo/decorate-banner-market.png',
          title: '夏日好物限时满减',
        },
        {
          image: 'static/demo/decorate-banner-member.png',
          title: '会员精选 每日上新',
        },
      ],
    },
    type: 'banner',
  },
  {
    id: 'preview-nav',
    props: {
      columns: 6,
      items: [
        { image: 'static/demo/decorate-nav-digital.png', label: '数码' },
        { image: 'static/demo/decorate-nav-beauty.png', label: '美妆' },
        { image: 'static/demo/decorate-nav-fashion.png', label: '服饰' },
        { image: 'static/demo/decorate-nav-home.png', label: '家居' },
        { image: 'static/demo/decorate-nav-food.png', label: '美食' },
        { image: 'static/demo/decorate-nav-sport.png', label: '运动' },
      ],
    },
    type: 'navGrid',
  },
  {
    id: 'preview-product-scroll',
    props: { layout: 'scroll', moreText: '查看全部', title: '今日必买' },
    type: 'productGroup',
  },
  {
    id: 'preview-product-grid',
    props: { layout: 'grid', title: '猜你喜欢' },
    type: 'productGroup',
  },
];

const DEFAULT_PROFILE_ORDER_ITEMS = [
  {
    image: 'static/demo/profile-order-pay.svg',
    label: '待付款',
    path: '/pages-sub/order/list?status=10',
  },
  {
    image: 'static/demo/profile-order-ship.svg',
    label: '待发货',
    path: '/pages-sub/order/list?status=20',
  },
  {
    image: 'static/demo/profile-order-receive.svg',
    label: '待收货',
    path: '/pages-sub/order/list?status=30',
  },
  {
    image: 'static/demo/profile-order-refund.svg',
    label: '退款售后',
    path: '/pages-sub/refund/list',
  },
];

const DEFAULT_PROFILE_SERVICE_ITEMS = [
  {
    image: 'static/demo/profile-service-address.svg',
    label: '地址管理',
    path: '/pages-sub/address/list',
  },
  {
    image: 'static/demo/profile-service-settings.svg',
    label: '系统设置',
    path: '/pages-sub/user/settings',
  },
  {
    image: 'static/demo/profile-service-support.svg',
    label: '联系客服',
    path: '',
  },
];

const DEFAULT_PROFILE_ORDER_IMAGES = DEFAULT_PROFILE_ORDER_ITEMS.map(
  (item) => item.image,
);
const DEFAULT_PROFILE_SERVICE_IMAGES = DEFAULT_PROFILE_SERVICE_ITEMS.map(
  (item) => item.image,
);

const DEFAULT_PROFILE_STYLE = {
  background: '',
  backgroundColorEnd: '',
  backgroundColorStart: '',
  backgroundGradientDirection: 'horizontal',
  backgroundMode: 'color',
  background_image: '',
  borderColor: '',
  borderEnabled: true,
  borderStyle: 'solid',
  borderWidth: 1,
  marginBottom: 0,
  marginLeft: 0,
  marginRight: 0,
  marginTop: 0,
  padding: 0,
  radius: 20,
  shadowEnabled: false,
  widthPercent: 100,
};

const DEFAULT_PROFILE_USER_STYLE = {
  ...DEFAULT_PROFILE_STYLE,
  paddingX: 28,
  paddingY: 28,
  radius: 0,
};

const DEFAULT_PROFILE_CARD_STYLE = {
  ...DEFAULT_PROFILE_STYLE,
  paddingX: 28,
  paddingY: 28,
};

const DEFAULT_PROFILE_MENU_STYLE = {
  ...DEFAULT_PROFILE_STYLE,
  paddingX: 10,
  paddingY: 0,
};

const PROFILE_ICON_PRESETS = [
  {
    keywords: [
      'pending_pay',
      'pay',
      'wallet',
      '待付款',
      '钱包',
      'ant-design:wallet-outlined',
    ],
    text: '¥',
    type: 'pay',
  },
  {
    keywords: [
      'pending_ship',
      'ship',
      'car',
      '待发货',
      'ant-design:car-outlined',
    ],
    text: '发',
    type: 'ship',
  },
  {
    keywords: [
      'pending_receive',
      'receive',
      'inbox',
      '待收货',
      'ant-design:inbox-outlined',
    ],
    text: '收',
    type: 'receive',
  },
  {
    keywords: [
      'refund',
      'reload',
      '退款',
      '售后',
      'ant-design:reload-outlined',
    ],
    text: '退',
    type: 'refund',
  },
  {
    keywords: [
      'address',
      'environment',
      '地址',
      'ant-design:environment-outlined',
    ],
    text: '址',
    type: 'address',
  },
  {
    keywords: ['favorite', 'heart', '收藏', 'ant-design:heart-outlined'],
    text: '藏',
    type: 'favorite',
  },
  {
    keywords: ['theme', 'skin', '主题', 'ant-design:skin-outlined'],
    text: '题',
    type: 'theme',
  },
  {
    keywords: [
      'service',
      'customer',
      '客服',
      'ant-design:customer-service-outlined',
    ],
    text: '客',
    type: 'service',
  },
];

const DEFAULT_PROFILE_MODULES: ModuleItem[] = [
  {
    id: 'preview-user',
    props: {
      ...DEFAULT_PROFILE_USER_STYLE,
      show_mobile: true,
    },
    type: 'userCard',
  },
  {
    id: 'preview-orders',
    props: {
      ...DEFAULT_PROFILE_CARD_STYLE,
      display: 'grid',
      items: DEFAULT_PROFILE_ORDER_ITEMS,
      title: '我的订单',
    },
    type: 'orderShortcut',
  },
  {
    id: 'preview-wallet',
    props: {
      ...DEFAULT_PROFILE_CARD_STYLE,
      show_balance: true,
      show_records: true,
      show_view_button: true,
      title: '我的余额',
    },
    type: 'wallet',
  },
  {
    id: 'preview-points',
    props: {
      ...DEFAULT_PROFILE_CARD_STYLE,
      show_records: true,
      show_view_button: true,
      title: '我的积分',
    },
    type: 'pointsEntry',
  },
  {
    id: 'preview-service',
    props: {
      ...DEFAULT_PROFILE_MENU_STYLE,
      columns: 4,
      display: 'list',
      items: DEFAULT_PROFILE_SERVICE_ITEMS,
      title: '我的服务',
    },
    type: 'serviceMenu',
  },
];

const DEFAULT_TABBAR_ITEMS: ModuleItem[] = [
  { id: 'preview-tab-home', path: '/pages/index/index', text: '首页' },
  { id: 'preview-tab-category', path: '/pages/category/index', text: '分类' },
  { id: 'preview-tab-cart', path: '/pages/cart/index', text: '购物车' },
  { id: 'preview-tab-order', path: '/pages/order/index', text: '订单' },
  { id: 'preview-tab-profile', path: '/pages/profile/index', text: '我的' },
];

const CATEGORY_ITEMS = [
  '生活家居',
  '运动专区',
  '电子产品',
  '家用电器',
  '家具软装',
  '美妆个护',
];

const CATEGORY_GROUPS = [
  {
    items: ['全部商品', '生活家电', '小家电'],
    title: '家用电器',
  },
  {
    items: ['厨房大电', '卫浴电器', '卫浴清洁'],
    title: '厨卫清洁',
  },
  {
    items: ['全部商品', '居家家具', '家饰软装'],
    title: '家具软装',
  },
];

const PRODUCT_PREVIEW_ITEMS = [
  { color: '#fee2e2', name: '轻盈跑鞋', price: '129.00' },
  { color: '#e0f2fe', name: '便携咖啡杯', price: '39.90' },
  { color: '#ede9fe', name: '智能小夜灯', price: '59.00' },
  { color: '#dcfce7', name: '柔软浴巾', price: '26.80' },
  { color: '#ffedd5', name: '居家水壶', price: '199.00' },
  { color: '#fce7f3', name: '香薰礼盒', price: '69.00' },
  { color: '#fef3c7', name: '柔雾口红', price: '89.00' },
  { color: '#dbeafe', name: '轻便背包', price: '159.00' },
];

const MODULE_LABELS: Record<string, string> = {
  banner: '轮播图',
  categoryEntry: '入口卡片',
  divider: '分割线',
  entryCard: '入口卡片',
  imageCube: '图片魔方',
  navGrid: '导航宫格',
  orderShortcut: '订单入口',
  productGroup: '商品分组',
  richText: '富文本',
  search: '搜索框',
  serviceMenu: '服务菜单',
  spacing: '空白间距',
  title: '标题栏',
  userCard: '用户信息',
  wallet: '钱包卡片',
  points: '积分卡片',
  pointsEntry: '积分卡片',
};

const GOODS_FALLBACK = {
  category_name: '',
  market_price: 299,
  name: '美的电热水壶家用烧水壶 0涂层食品级不锈钢',
  price: 199,
  stock: 1000,
  subtitle: '双层防烫 · 全钢无缝 · 轻音烧水',
};

const tokens = computed(() => ({
  ...DEFAULT_THEME_TOKENS,
  ...props.themeTokens,
}));

const previewStyle = computed(() => ({
  '--mb-preview-bg': tokens.value.colorBg,
  '--mb-preview-bg-secondary': tokens.value.colorBgSecondary,
  '--mb-preview-bg-surface': tokens.value.colorBgSurface,
  '--mb-preview-border': tokens.value.colorBorder,
  '--mb-preview-divider': tokens.value.colorDivider,
  '--mb-preview-price': tokens.value.colorPrice,
  '--mb-preview-primary': tokens.value.colorPrimary,
  '--mb-preview-primary-light': tokens.value.colorPrimaryLight,
  '--mb-preview-text': tokens.value.colorText,
  '--mb-preview-text-secondary': tokens.value.colorTextSecondary,
  '--mb-preview-text-tertiary': tokens.value.colorTextTertiary,
  '--mb-preview-text-title':
    tokens.value.colorTextTitle || tokens.value.colorText,
}));

const pageTitle = computed(() => {
  if (props.kind === 'profile') return 'MallBase';
  if (props.title) return props.title;
  const map: Record<PreviewKind, string> = {
    category: '分类',
    floating: '悬浮按钮',
    goodsDetail: '商品详情',
    home: '首页',
    profile: 'MallBase',
    tabbar: '底部导航',
  };
  return map[props.kind];
});

const normalizedModules = computed(() => {
  const fallback =
    props.kind === 'floating'
      ? []
      : props.kind === 'profile'
        ? DEFAULT_PROFILE_MODULES
        : DEFAULT_HOME_MODULES;
  const source = (
    props.modules.length > 0 ? props.modules : fallback
  ) as ModuleItem[];
  return source
    .map(
      (module, index): ModuleItem => ({
        ...module,
        __previewIndex: index,
        id: module.id || module.key || `preview-module-${index}`,
        props: normalizeModuleProps(module),
        type: normalizeProfileType(
          String(module.type || module.component || ''),
        ),
      }),
    )
    .filter(
      (module) =>
        props.interactive ||
        (module.enabled !== false && module.visible !== false),
    );
});

const selectedModuleForControls = computed(() => {
  if (
    !props.interactive ||
    !props.selectedModuleId ||
    props.kind === 'tabbar'
  ) {
    return null;
  }

  return (
    normalizedModules.value.find(
      (module) => module.id === props.selectedModuleId,
    ) || null
  );
});

const selectedModuleControlIndex = computed(() =>
  selectedModuleForControls.value
    ? Number(selectedModuleForControls.value.__previewIndex)
    : -1,
);

const selectedModuleControlTitle = computed(() =>
  selectedModuleForControls.value
    ? getModuleTitle(selectedModuleForControls.value)
    : '',
);

const normalizedTabbarItems = computed(() => {
  let items = DEFAULT_TABBAR_ITEMS;
  if (props.kind === 'tabbar' || props.tabbarItems.length > 0) {
    items = props.tabbarItems;
  }

  return items.slice(0, 5).map((item, index) => ({
    ...item,
    __previewIndex: index,
    id: item.id || item.key || `preview-tabbar-${index}`,
    path: item.path || item.pagePath || item.url || '',
    text: item.text || item.label || item.title || '导航',
  }));
});

watch(
  [selectedModuleForControls, normalizedModules],
  () => updateSelectedModuleControlPosition(),
  { flush: 'post' },
);

function sameStyleValue(
  current: Record<string, string>,
  next: Record<string, string>,
) {
  const currentKeys = Object.keys(current);
  const nextKeys = Object.keys(next);
  return (
    currentKeys.length === nextKeys.length &&
    nextKeys.every((key) => current[key] === next[key])
  );
}

function setStyleIfChanged(
  target: typeof selectedModuleFrameStyle,
  next: Record<string, string>,
) {
  if (!sameStyleValue(target.value, next)) {
    target.value = next;
  }
}

function setReadyIfChanged(
  target: typeof selectedModuleFrameReady,
  next: boolean,
) {
  if (target.value !== next) {
    target.value = next;
  }
}

const categorySidebarItems = computed(() => {
  if (props.categoryTree.length > 0) return props.categoryTree;
  return CATEGORY_ITEMS.map((name, index) => ({
    children: [],
    id: `preview-category-${index}`,
    name,
  }));
});

const activeCategoryIndex = computed(() =>
  Math.min(3, Math.max(0, categorySidebarItems.value.length - 1)),
);

const activeCategory = computed(
  () => categorySidebarItems.value[activeCategoryIndex.value] || null,
);

const categoryPreviewGroups = computed(() => {
  const children = activeCategory.value?.children;
  if (!Array.isArray(children) || children.length === 0) {
    return CATEGORY_GROUPS.map((group) => ({
      ...group,
      items: group.items.map((name) => ({ name })),
    }));
  }
  const hotCount = Math.min(6, children.length);
  const groups = [{ items: children.slice(0, hotCount), title: '热门分类' }];
  if (children.length > hotCount) {
    groups.push({
      items: children.slice(hotCount),
      title: `${activeCategory.value?.name || '更多'}配件`,
    });
  }
  return groups;
});

const goodsPreview = computed(() => ({
  ...GOODS_FALLBACK,
  ...props.goods,
}));

function normalizeModuleProps(module: ModuleItem) {
  const rawData = module.data || {};
  const rawProps = module.props || {};
  const rawConfig = module.config || {};
  const props = { ...rawData, ...rawProps, ...rawConfig };
  if (rawConfig.list !== undefined) {
    props.list = rawConfig.list;
  }
  if (rawConfig.images !== undefined) {
    props.images = rawConfig.images;
    props.list = rawConfig.images;
  }
  if (rawConfig.items !== undefined) {
    props.items = rawConfig.items;
    props.list = rawConfig.items;
  }
  props.widthPercent = Number(props.widthPercent ?? props.width_percent ?? 100);
  props.marginTop = Number(props.marginTop ?? props.margin_top ?? 0);
  props.marginBottom = Number(props.marginBottom ?? props.margin_bottom ?? 0);
  const padding = props.padding ?? 0;
  props.paddingY = Number(props.paddingY ?? props.padding_y ?? padding);
  props.paddingX = Number(props.paddingX ?? props.padding_x ?? padding);
  props.paddingTop = Number(
    props.paddingTop ?? props.padding_top ?? props.paddingY,
  );
  props.paddingRight = Number(
    props.paddingRight ?? props.padding_right ?? props.paddingX,
  );
  props.paddingBottom = Number(
    props.paddingBottom ?? props.padding_bottom ?? props.paddingY,
  );
  props.paddingLeft = Number(
    props.paddingLeft ?? props.padding_left ?? props.paddingX,
  );
  if (!props.list && (props.items || props.images)) {
    props.list = props.items || props.images;
  }
  if (!props.items && props.list) {
    props.items = props.list;
  }
  if (!props.title && module.title) {
    props.title = module.title;
  }
  if (!props.placeholder && props.placeholder_text) {
    props.placeholder = props.placeholder_text;
  }
  if (!props.moreText && (props.more_text || props.more_title)) {
    props.moreText = props.more_text || props.more_title;
  }
  if (!props.moreUrl && (props.more_path || props.moreUrl)) {
    props.moreUrl = props.more_path || props.moreUrl;
  }
  if (!props.subtitle && props.sub_title) {
    props.subtitle = props.sub_title;
  }
  return props;
}

function normalizeProfileType(type: string) {
  const alias: Record<string, string> = {
    categoryEntry: 'entryCard',
    customMenu: 'serviceMenu',
    orderEntry: 'orderShortcut',
    points: 'pointsEntry',
    pointsCard: 'pointsEntry',
    profileHeader: 'userCard',
    userInfo: 'userCard',
    walletEntry: 'wallet',
  };
  return alias[type] || type;
}

function defaultProfileEntryImage(moduleType: string, index: number) {
  if (moduleType === 'orderShortcut') {
    return DEFAULT_PROFILE_ORDER_IMAGES[
      index % DEFAULT_PROFILE_ORDER_IMAGES.length
    ];
  }
  if (moduleType === 'serviceMenu') {
    return DEFAULT_PROFILE_SERVICE_IMAGES[
      index % DEFAULT_PROFILE_SERVICE_IMAGES.length
    ];
  }
  return '';
}

function isProfileEntryImageRemoved(item: any) {
  return item?.imageRemoved === true || item?.image_removed === true;
}

function rawModuleList(module: ModuleItem) {
  const list = [
    module.props?.list,
    module.props?.items,
    module.props?.images,
  ].find((value) => Array.isArray(value) && value.length > 0);
  return Array.isArray(list) ? list : [];
}

function hasConfiguredModuleItems(module: ModuleItem) {
  return rawModuleList(module).length > 0;
}

function moduleList(module: ModuleItem) {
  const list = rawModuleList(module);
  let source: any[] = [];
  if (list.length > 0) {
    source = list;
  } else if (module.type === 'orderShortcut') {
    source = DEFAULT_PROFILE_ORDER_ITEMS;
  } else if (module.type === 'serviceMenu') {
    source = DEFAULT_PROFILE_SERVICE_ITEMS;
  }
  return source
    .filter(
      (item: any) =>
        item?.enabled !== false &&
        item?.visible !== false &&
        !isThemeSelectorTarget(item),
    )
    .map((item: any, index: number) => {
      if (isProfileEntryImageRemoved(item)) return item;
      if (getImage(item)) return item;
      const image = defaultProfileEntryImage(module.type, index);
      return image ? { ...item, image } : item;
    });
}

function normalizeTargetPath(value: unknown) {
  const path = String(value || '').split('?')[0];
  if (!path) return '';
  if (path.includes('://')) return path;
  return `/${path.replace(/^\/+/, '')}`;
}

function isThemeSelectorTarget(item: any) {
  const action = String(item?.action || item?.key || '').toLowerCase();
  if (action === 'theme') return true;
  const path = normalizeTargetPath(
    item?.path ||
      item?.url ||
      item?.link ||
      item?.link_url ||
      item?.linkUrl ||
      item?.target_path ||
      item?.targetPath,
  );
  return path === THEME_ACTION_PATH || path === THEME_PAGE_PATH;
}

function getImage(item: any): string {
  if (typeof item === 'string') return normalizePreviewImageUrl(item);
  if (isProfileEntryImageRemoved(item)) return '';
  const candidates = [
    item?.full_url ||
      item?.fullUrl ||
      item?.thumbUrl ||
      item?.thumb_url ||
      item?.response?.full_url ||
      item?.response?.fullUrl ||
      item?.response?.url ||
      item?.preview_url ||
      item?.previewUrl ||
      item?.image_full_url ||
      item?.imageFullUrl,
    item?.image,
    item?.image_url,
    item?.imageUrl,
    item?.pic,
    item?.src,
    item?.cover,
    item?.url,
  ];
  for (const value of candidates) {
    if (value && typeof value === 'object') {
      const nestedValue: string = getImage(value);
      if (nestedValue) return nestedValue;
      continue;
    }
    const normalizedValue = normalizePreviewImageUrl(value);
    if (normalizedValue) return normalizedValue;
  }
  return '';
}

function normalizePreviewImageUrl(value: unknown) {
  if (typeof value !== 'string' || value.length === 0) return '';
  if (/^(?:https?:|data:image|blob:)/.test(value)) {
    return value;
  }
  if (!looksLikeImagePath(value)) return '';
  const apiBase = import.meta.env.VITE_GLOB_API_URL || '';
  if (!value.startsWith('/')) {
    if (value.startsWith('uploads/') || value.startsWith('static/')) {
      try {
        return `${new URL(apiBase, window.location.origin).origin}/${value}`;
      } catch {
        return `/${value}`;
      }
    }
    return value;
  }
  try {
    return `${new URL(apiBase, window.location.origin).origin}${value}`;
  } catch {
    return value;
  }
}

function looksLikeImagePath(value: string) {
  return (
    value.startsWith('/uploads/') ||
    value.startsWith('uploads/') ||
    value.startsWith('/static/') ||
    value.startsWith('static/') ||
    /\.(?:png|jpe?g|gif|webp|svg)(?:\?.*)?$/i.test(value)
  );
}

function getCategoryImage(item: PreviewRecord) {
  return item.image_full_url || item.image || '';
}

function getGoodsImage(item: PreviewRecord) {
  return (
    item.main_image_full_url ||
    item.main_image ||
    item.images?.[0]?.full_url ||
    item.images?.[0]?.url ||
    ''
  );
}

function formatAmount(value: unknown) {
  const numberValue = Number(value || 0);
  return Number.isFinite(numberValue) ? numberValue.toFixed(2) : '0.00';
}

function getLabel(item: any) {
  return item?.label || item?.title || item?.text || item?.name || '入口';
}

function getRecordKey(item: any, index: number) {
  return item?.id || item?.key || item?.name || index;
}

function getFallbackIcon(item: any) {
  const key = item?.icon || item?.key || '';
  const map: Record<string, string> = {
    beauty: '美',
    food: '食',
    home: '家',
    phone: '数',
    shirt: '衣',
  };
  return map[key] || getLabel(item).slice(0, 1);
}

function getModuleTitle(module: ModuleItem) {
  return (
    module.title ||
    module.text ||
    module.props?.title ||
    MODULE_LABELS[module.type] ||
    '模块'
  );
}

function isIconifyName(value: unknown) {
  return typeof value === 'string' && /^[\w-]+:[\w-]+/.test(value);
}

function isImageLike(value: unknown) {
  return (
    typeof value === 'string' &&
    (/^(?:https?:|data:image|blob:)/.test(value) || value.startsWith('/'))
  );
}

function resolvePreviewImageValue(value: unknown) {
  if (!value || typeof value !== 'object') return value;
  const source = value as Record<string, any>;
  return (
    source.full_url ||
    source.fullUrl ||
    source.preview_url ||
    source.previewUrl ||
    source.url ||
    ''
  );
}

function getEntryIcon(item: any) {
  return item?.icon || item?.selected_icon || '';
}

function getProfileIconPreset(item: any) {
  const source = [
    item?.key,
    item?.icon,
    item?.action,
    item?.label,
    item?.title,
    item?.text,
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();
  return PROFILE_ICON_PRESETS.find((preset) =>
    preset.keywords.some((keyword) => source.includes(keyword.toLowerCase())),
  );
}

function profileIconType(item: any) {
  return getProfileIconPreset(item)?.type || 'default';
}

function profileIconText(item: any) {
  return getProfileIconPreset(item)?.text || getLabel(item).slice(0, 1) || '•';
}

function profileIconClass(item: any) {
  return `profile-entry-icon--${profileIconType(item)}`;
}

function entryCardIconImage(module: ModuleItem) {
  return getImage(module.props?.icon_image || module.props?.iconImage || '');
}

function entryCardBackgroundImage(module: ModuleItem) {
  return getImage(
    module.props?.background_image || module.props?.backgroundImage || '',
  );
}

function entryCardBoxStyle(module: ModuleItem) {
  const style = moduleBoxStyle(module);
  const image = entryCardBackgroundImage(module);
  if (image) {
    style.backgroundImage = `url("${image}")`;
    style.backgroundPosition = 'center';
    style.backgroundSize = 'cover';
  }
  return style;
}

function titleSubtitle(module: ModuleItem) {
  return module.props?.subtitle || module.props?.sub_title || '';
}

function titleMoreText(module: ModuleItem) {
  if (
    !(
      module.props?.more_path ||
      module.props?.moreUrl ||
      module.props?.more_url
    )
  ) {
    return '';
  }
  return module.props?.more_text || module.props?.moreText || '查看全部';
}

function titleAlign(module: ModuleItem) {
  const align = module.props?.title_align || module.props?.titleAlign || 'left';
  return ['center', 'right'].includes(align) ? align : 'left';
}

function clampNumber(
  value: unknown,
  fallback: number,
  min: number,
  max: number,
) {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return fallback;
  return Math.max(min, Math.min(numberValue, max));
}

function titleBoxStyle(module: ModuleItem) {
  return moduleBoxStyle(module);
}

function titleMainStyle(module: ModuleItem) {
  const textAlign =
    homeTextStyle(module, 'title').textAlign || titleAlign(module);
  return {
    textAlign,
  };
}

function titleTextStyle(module: ModuleItem) {
  const props = module.props || {};
  const fallbackStyle = {
    color: props.title_color || props.titleColor || undefined,
    fontSize: `${toPreviewPx(
      clampNumber(props.title_font_size ?? props.titleFontSize, 32, 18, 72),
    )}px`,
    fontStyle: props.title_italic || props.titleItalic ? 'italic' : undefined,
    fontWeight:
      props.title_bold === false || props.titleBold === false ? '500' : '800',
  };
  return {
    ...fallbackStyle,
    ...homeTextStyle(module, 'title'),
  };
}

function titleSubStyle(module: ModuleItem) {
  const props = module.props || {};
  const fallbackStyle = {
    color: props.sub_color || props.subColor || undefined,
    fontSize: `${toPreviewPx(
      clampNumber(props.sub_font_size ?? props.subFontSize, 24, 16, 56),
    )}px`,
    fontStyle: props.sub_italic || props.subItalic ? 'italic' : undefined,
    fontWeight: props.sub_bold || props.subBold ? '700' : undefined,
  };
  return {
    ...fallbackStyle,
    ...homeTextStyle(module, 'subtitle'),
  };
}

function titleMoreStyle(module: ModuleItem) {
  return homeTextStyle(module, 'more');
}

function navColumns(module: ModuleItem) {
  const columns = Number(module.props?.columns || 6);
  return Math.max(3, Math.min(columns, 6));
}

function toPreviewPx(value: unknown, fallback = 0) {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return fallback / 2;
  return Math.max(0, numberValue / 2);
}

function styleColor(value: unknown) {
  return typeof value === 'string' && value.trim() ? value.trim() : '';
}

function styleBoolean(value: unknown, fallback = false) {
  if (value === undefined || value === null || value === '') return fallback;
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value === 1;
  if (typeof value === 'string') {
    return ['1', 'true'].includes(value.toLowerCase());
  }
  return Boolean(value);
}

function normalizeHexColor(value: unknown) {
  const color = styleColor(value).toLowerCase();
  const shortHex = color.match(/^#([\da-f])([\da-f])([\da-f])$/i);
  if (shortHex) {
    return `#${shortHex[1]}${shortHex[1]}${shortHex[2]}${shortHex[2]}${shortHex[3]}${shortHex[3]}`;
  }
  return color;
}

function isDefaultProfileSurfaceColor(value: unknown) {
  return ['#f3f3fe', '#faf8ff', '#ffffff'].includes(normalizeHexColor(value));
}

function isDefaultProfileBorderColor(value: unknown) {
  return ['#e0e4e8', '#e5e5e5', '#f0f2f5'].includes(normalizeHexColor(value));
}

function shouldUseThemeModuleSurface(
  props: Record<string, any>,
  mode: string,
  backgroundImage: string,
) {
  if (mode !== 'color' || backgroundImage) return false;
  const colors = [
    props.background,
    props.backgroundColorStart || props.background_color_start,
    props.backgroundColorEnd || props.background_color_end,
    props.bottomBackground || props.bottom_background,
  ]
    .map((item) => styleColor(item))
    .filter(Boolean);
  return (
    colors.length > 0 &&
    colors.every((item) => isDefaultProfileSurfaceColor(item))
  );
}

function shouldUseThemeModuleBorder(props: Record<string, any>) {
  const borderEnabled = props.borderEnabled ?? props.border_enabled;
  if (!styleBoolean(borderEnabled, true)) return false;
  const borderWidth = Number(props.borderWidth ?? props.border_width ?? 1);
  const borderStyle = String(
    props.borderStyle || props.border_style || 'solid',
  );
  const borderColor = props.borderColor || props.border_color || '';
  return (
    borderWidth === 1 &&
    borderStyle === 'dashed' &&
    isDefaultProfileBorderColor(borderColor)
  );
}

function normalizePreviewFontWeight(value: unknown) {
  const weight = String(value || '');
  return ['400', '500', '600', '700', '800', '900'].includes(weight)
    ? weight
    : '';
}

function normalizePreviewFontStyle(value: unknown, italicValue?: unknown) {
  if (value === 'italic' || styleBoolean(italicValue)) return 'italic';
  return '';
}

function normalizePreviewTextAlign(value: unknown) {
  const align = String(value || '');
  return ['center', 'left', 'right'].includes(align) ? align : '';
}

function profileTextVisible(_module: ModuleItem, _role: string) {
  return true;
}

function profileTextStyleConfig(module: ModuleItem, role: string) {
  const props = module.props || {};
  const textStyles = props.textStyles || props.text_styles || {};
  const styleConfig = textStyles?.[role];
  return styleConfig && typeof styleConfig === 'object' ? styleConfig : {};
}

function profileTextStyle(module: ModuleItem, role: string) {
  const styleConfig = profileTextStyleConfig(module, role);
  if (Object.keys(styleConfig).length === 0) return {};
  const style: Record<string, string> = {};
  const color = styleColor(styleConfig.color);
  if (color) style.color = color;
  const fontSize = Number(styleConfig.fontSize ?? styleConfig.font_size);
  if (Number.isFinite(fontSize) && fontSize > 0) {
    style.fontSize = `${toPreviewPx(clampNumber(fontSize, 24, 16, 80))}px`;
  }
  const fontWeight = normalizePreviewFontWeight(
    styleConfig.fontWeight ?? styleConfig.font_weight,
  );
  if (fontWeight) style.fontWeight = fontWeight;
  const fontStyle = normalizePreviewFontStyle(
    styleConfig.fontStyle ?? styleConfig.font_style,
    styleConfig.italic,
  );
  if (fontStyle) style.fontStyle = fontStyle;
  const textAlign = normalizePreviewTextAlign(
    styleConfig.textAlign ?? styleConfig.text_align,
  );
  if (textAlign) style.textAlign = textAlign;
  return style;
}

const homeTextStyle = profileTextStyle;

function textAlignJustifyContent(value: unknown) {
  const align = normalizePreviewTextAlign(value);
  if (align === 'center') return 'center';
  if (align === 'right') return 'flex-end';
  return 'flex-start';
}

function gradientDirection(value: unknown) {
  const map: Record<string, string> = {
    diagonalLeft: '135deg',
    diagonalRight: '45deg',
    horizontal: '90deg',
    vertical: '180deg',
  };
  return map[String(value || 'horizontal')] || map.horizontal;
}

function gradientBackground(
  startValue: unknown,
  endValue: unknown,
  directionValue: unknown,
  bottomValue?: unknown,
) {
  const start = styleColor(startValue);
  const end = styleColor(endValue) || start;
  const bottom = styleColor(bottomValue);
  if (!start && !bottom) return '';
  if (bottom && start) {
    return `linear-gradient(180deg, ${start} 0%, ${end} 68%, ${bottom} 100%)`;
  }
  if (!start) return bottom;
  if (!end || start.toLowerCase() === end.toLowerCase()) return start;
  return `linear-gradient(${gradientDirection(directionValue)}, ${start}, ${end})`;
}

function clampStyleNumber(
  value: unknown,
  fallback: number,
  min: number,
  max: number,
) {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return fallback;
  return Math.max(min, Math.min(numberValue, max));
}

function hexToRgba(value: unknown, opacity: unknown, fallback = '#0f172a') {
  const color = styleColor(value) || fallback;
  const alpha = clampStyleNumber(opacity, 14, 0, 100) / 100;
  const shortHex = color.match(/^#([\da-f])([\da-f])([\da-f])$/i);
  const fullHex = color.match(/^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i);
  const match = fullHex || shortHex;
  if (!match) return color;
  const red = Number.parseInt(
    fullHex ? match[1]! : `${match[1]}${match[1]}`,
    16,
  );
  const green = Number.parseInt(
    fullHex ? match[2]! : `${match[2]}${match[2]}`,
    16,
  );
  const blue = Number.parseInt(
    fullHex ? match[3]! : `${match[3]}${match[3]}`,
    16,
  );
  return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
}

function moduleShadowStyle(props: Record<string, any>) {
  const shadowEnabled = props.shadowEnabled ?? props.shadow_enabled;
  const enabled = styleBoolean(shadowEnabled);
  if (shadowEnabled !== undefined && !enabled) return 'none';
  if (!enabled) return '';
  const offsetX = toPreviewPx(
    clampStyleNumber(props.shadowOffsetX ?? props.shadow_offset_x, 0, -80, 80),
  );
  const offsetY = toPreviewPx(
    clampStyleNumber(props.shadowOffsetY ?? props.shadow_offset_y, 12, -80, 80),
  );
  const blur = toPreviewPx(
    clampStyleNumber(props.shadowBlur ?? props.shadow_blur, 30, 0, 160),
  );
  const spread = toPreviewPx(
    clampStyleNumber(props.shadowSpread ?? props.shadow_spread, 0, -80, 80),
  );
  const color = hexToRgba(
    props.shadowColor ?? props.shadow_color,
    props.shadowOpacity ?? props.shadow_opacity,
  );
  return `${offsetX}px ${offsetY}px ${blur}px ${spread}px ${color}`;
}

function moduleBackgroundStyle(module: ModuleItem) {
  const props = module.props || {};
  const style: Record<string, string> = {};
  const mode = props.backgroundMode || props.background_mode || 'color';
  const backgroundImage = getImage(
    props.background_image || props.backgroundImage || '',
  );
  const useThemeSurface = shouldUseThemeModuleSurface(
    props,
    mode,
    backgroundImage,
  );

  if (mode === 'image' && backgroundImage) {
    style.backgroundImage = `url("${backgroundImage}")`;
    style.backgroundPosition = 'center';
    style.backgroundSize = 'cover';
    const fallback =
      styleColor(props.background) ||
      styleColor(props.bottomBackground || props.bottom_background);
    if (fallback) style.backgroundColor = fallback;
    return style;
  }

  if (!useThemeSurface) {
    const background = gradientBackground(
      props.backgroundColorStart ||
        props.background_color_start ||
        props.background,
      props.backgroundColorEnd || props.background_color_end,
      props.backgroundGradientDirection || props.background_gradient_direction,
      props.bottomBackground || props.bottom_background,
    );
    if (background) style.background = background;
  }
  return style;
}

function pageBackgroundStyle(pageStyle: Record<string, any>) {
  const style: Record<string, string> = {};
  const mode = pageStyle.backgroundMode || pageStyle.background_mode || 'color';
  const backgroundImage = getImage(
    pageStyle.background_image || pageStyle.backgroundImage || '',
  );

  if (mode === 'image' && backgroundImage) {
    style.backgroundImage = `url("${backgroundImage}")`;
    style.backgroundPosition = 'center';
    style.backgroundSize = 'cover';
    return style;
  }

  const background = gradientBackground(
    pageStyle.backgroundColorStart || pageStyle.background_color_start,
    pageStyle.backgroundColorEnd || pageStyle.background_color_end,
    pageStyle.backgroundGradientDirection ||
      pageStyle.background_gradient_direction,
  );
  if (background) style.background = background;
  return style;
}

const homePageStyle = computed(() => {
  const pageStyle = props.pageStyle || {};
  const paddingY = pageStyle.paddingY ?? pageStyle.padding_y;
  const paddingX = pageStyle.paddingX ?? pageStyle.padding_x ?? 28;
  const paddingTop =
    pageStyle.paddingTop ?? pageStyle.padding_top ?? paddingY ?? 0;
  const paddingRight =
    pageStyle.paddingRight ?? pageStyle.padding_right ?? paddingX;
  const paddingBottom =
    pageStyle.paddingBottom ?? pageStyle.padding_bottom ?? paddingY ?? 0;
  const paddingLeft =
    pageStyle.paddingLeft ?? pageStyle.padding_left ?? paddingX;
  return {
    ...pageBackgroundStyle(pageStyle),
    paddingBottom: `${toPreviewPx(paddingBottom)}px`,
    paddingLeft: `${toPreviewPx(paddingLeft)}px`,
    paddingRight: `${toPreviewPx(paddingRight)}px`,
    paddingTop: `${toPreviewPx(paddingTop)}px`,
  };
});

const profilePageStyle = computed(() => {
  const pageStyle = props.pageStyle || {};
  const paddingY = pageStyle.paddingY ?? pageStyle.padding_y;
  const paddingX = pageStyle.paddingX ?? pageStyle.padding_x ?? 28;
  const paddingTop =
    pageStyle.paddingTop ?? pageStyle.padding_top ?? paddingY ?? 10;
  const paddingRight =
    pageStyle.paddingRight ?? pageStyle.padding_right ?? paddingX;
  const paddingBottom =
    pageStyle.paddingBottom ?? pageStyle.padding_bottom ?? paddingY ?? 24;
  const paddingLeft =
    pageStyle.paddingLeft ?? pageStyle.padding_left ?? paddingX;
  return {
    ...pageBackgroundStyle(pageStyle),
    paddingBottom: `${toPreviewPx(paddingBottom)}px`,
    paddingLeft: `${toPreviewPx(paddingLeft)}px`,
    paddingRight: `${toPreviewPx(paddingRight)}px`,
    paddingTop: `${toPreviewPx(paddingTop)}px`,
  };
});

function moduleOuterStyle(module: ModuleItem) {
  const props = module.props || {};
  const width = Math.max(50, Math.min(Number(props.widthPercent || 100), 100));
  const marginLeft = props.marginLeft ?? props.margin_left;
  const marginRight = props.marginRight ?? props.margin_right;
  const marginLeftPx = marginLeft === undefined ? 0 : toPreviewPx(marginLeft);
  const marginRightPx =
    marginRight === undefined ? 0 : toPreviewPx(marginRight);
  const horizontalMargin = marginLeftPx + marginRightPx;
  const style: Record<string, string> = {
    marginBottom: `${toPreviewPx(props.marginBottom)}px`,
    marginTop: `${toPreviewPx(props.marginTop)}px`,
    width:
      horizontalMargin > 0
        ? `calc(${width}% - ${horizontalMargin}px)`
        : `${width}%`,
  };
  if (marginLeft !== undefined) {
    style.marginLeft = `${marginLeftPx}px`;
  } else if (width < 100) {
    style.marginLeft = 'auto';
  }
  if (marginRight !== undefined) {
    style.marginRight = `${marginRightPx}px`;
  } else if (width < 100) {
    style.marginRight = 'auto';
  }
  const componentBackground = gradientBackground(
    props.componentBackgroundStart || props.component_background_start,
    props.componentBackgroundEnd || props.component_background_end,
    props.backgroundGradientDirection || props.background_gradient_direction,
  );
  if (componentBackground) style.background = componentBackground;
  return style;
}

function moduleBoxStyle(
  module: ModuleItem,
  extra: Record<string, string> = {},
) {
  const props = module.props || {};
  const style: Record<string, string> = {
    boxSizing: 'border-box',
    ...extra,
    ...moduleBackgroundStyle(module),
  };
  if (props.radius !== undefined) {
    style.borderRadius = `${toPreviewPx(props.radius)}px`;
  }
  const textColor = styleColor(props.textColor || props.text_color);
  if (textColor) {
    style.color = textColor;
    style['--mb-preview-text'] = textColor;
    style['--mb-preview-text-secondary'] = textColor;
    style['--mb-preview-text-tertiary'] = textColor;
    style['--mb-preview-text-title'] = textColor;
  }
  const borderEnabled = props.borderEnabled ?? props.border_enabled;
  if (borderEnabled === false) {
    style.border = '0';
  } else if (
    borderEnabled !== undefined &&
    !shouldUseThemeModuleBorder(props)
  ) {
    const borderWidth = toPreviewPx(props.borderWidth ?? props.border_width, 1);
    const borderStyle = props.borderStyle || props.border_style || 'solid';
    const borderColor =
      styleColor(props.borderColor || props.border_color) ||
      'var(--mb-preview-divider)';
    style.border = `${borderWidth}px ${borderStyle} ${borderColor}`;
  }
  const shadowEnabled = props.shadowEnabled ?? props.shadow_enabled;
  if (shadowEnabled !== undefined) {
    const boxShadow = moduleShadowStyle(props);
    if (boxShadow) style.boxShadow = boxShadow;
  }
  const hasSidePadding =
    props.paddingTop !== undefined ||
    props.padding_top !== undefined ||
    props.paddingRight !== undefined ||
    props.padding_right !== undefined ||
    props.paddingBottom !== undefined ||
    props.padding_bottom !== undefined ||
    props.paddingLeft !== undefined ||
    props.padding_left !== undefined;
  if (hasSidePadding) {
    const padding = props.padding ?? 0;
    const paddingY = props.paddingY ?? props.padding_y ?? padding;
    const paddingX = props.paddingX ?? props.padding_x ?? padding;
    const paddingTop = props.paddingTop ?? props.padding_top ?? paddingY;
    const paddingRight = props.paddingRight ?? props.padding_right ?? paddingX;
    const paddingBottom =
      props.paddingBottom ?? props.padding_bottom ?? paddingY;
    const paddingLeft = props.paddingLeft ?? props.padding_left ?? paddingX;
    style.padding = `${toPreviewPx(paddingTop)}px ${toPreviewPx(
      paddingRight,
    )}px ${toPreviewPx(paddingBottom)}px ${toPreviewPx(paddingLeft)}px`;
  } else if (props.paddingY !== undefined || props.paddingX !== undefined) {
    const padding = props.padding ?? 0;
    const paddingY = props.paddingY ?? props.padding_y ?? padding;
    const paddingX = props.paddingX ?? props.padding_x ?? padding;
    style.padding = `${toPreviewPx(paddingY)}px ${toPreviewPx(paddingX)}px`;
  } else if (props.padding !== undefined) {
    style.padding = `${toPreviewPx(props.padding)}px`;
  }
  return style;
}

function serviceMenuBoxStyle(module: ModuleItem) {
  return {
    ...moduleBoxStyle(module),
    '--mb-profile-service-columns': String(
      Math.max(3, Math.min(Number(module.props.columns || 4), 5)),
    ),
  };
}

function bannerHeight(module: ModuleItem) {
  const height = Number(module.props?.height || 314);
  return `${Math.max(120, Math.min(Math.round(height / 2), 190))}px`;
}

function bannerImage(module: ModuleItem) {
  const images = imageList(module);
  if (images.length <= 1) return images[0] || '';
  const intervalSeconds = Math.max(
    1,
    Math.round(Number(module.props?.interval || 3000) / 1000),
  );
  const index =
    Math.floor(bannerPreviewTick.value / intervalSeconds) % images.length;
  return images[index] || images[0] || '';
}

function imageList(module: ModuleItem) {
  return moduleList(module)
    .map((item: any) => getImage(item))
    .filter(Boolean);
}

function cubeLayout(module: ModuleItem) {
  const layout = String(module.props?.layout || 'four');
  return ['four', 'one', 'two'].includes(layout) ? layout : 'four';
}

function cubeDisplayLimit(module: ModuleItem) {
  const map: Record<string, number> = {
    four: 4,
    one: 1,
    two: 2,
  };
  return map[cubeLayout(module)] || map.four;
}

function imageCubePreviewItems(module: ModuleItem) {
  const items = moduleList(module);
  if (items.length > 0 || hasConfiguredModuleItems(module)) {
    return items
      .map((item: any, index: number) => ({
        image: getImage(item),
        title:
          (typeof item === 'string' && !getImage(item) ? item : '') ||
          item?.title ||
          item?.label ||
          item?.text ||
          item?.name ||
          module.props?.titles?.[index] ||
          '',
      }))
      .filter((item) => item.image || item.title);
  }
  return (
    module.props.titles || ['精选榜单', '本周值得买', '会员专享', '新品榜']
  ).map((title: string) => ({ image: '', title }));
}

function cubeTitlePositionStyle(value: unknown) {
  const position = String(value || 'bottom');
  const style: Record<string, string> = {
    bottom: 'auto',
    left: 'auto',
    right: 'auto',
    top: 'auto',
    transform: 'none',
  };
  const offset = '8px';
  if (position.includes('top')) {
    style.top = offset;
  } else if (position.includes('center')) {
    style.top = '50%';
    style.transform = 'translateY(-50%)';
  } else {
    style.bottom = offset;
  }
  if (position.endsWith('Left')) {
    style.left = offset;
  } else if (position.endsWith('Right')) {
    style.right = offset;
  } else {
    style.left = '50%';
    style.transform =
      style.transform === 'translateY(-50%)'
        ? 'translate(-50%, -50%)'
        : 'translateX(-50%)';
  }
  return style;
}

function cubeTitleStyle(module: ModuleItem) {
  const styleConfig = profileTextStyleConfig(module, 'itemLabel');
  const style: Record<string, string> = {
    ...homeTextStyle(module, 'itemLabel'),
  };
  const backgroundMode = String(
    styleConfig.backgroundMode ?? styleConfig.background_mode ?? 'color',
  );
  const backgroundImage = getImage(
    styleConfig.backgroundImage ?? styleConfig.background_image ?? '',
  );
  if (backgroundMode === 'image' && backgroundImage) {
    style.backgroundImage = `url("${backgroundImage}")`;
    style.backgroundPosition = 'center';
    style.backgroundSize = 'cover';
  } else {
    const background = gradientBackground(
      styleConfig.backgroundColorStart ?? styleConfig.background_color_start,
      styleConfig.backgroundColorEnd ?? styleConfig.background_color_end,
      styleConfig.backgroundGradientDirection ??
        styleConfig.background_gradient_direction,
    );
    if (background) style.background = background;
  }
  const height = Number(
    styleConfig.backgroundHeight ?? styleConfig.background_height ?? 26,
  );
  style.height = `${clampNumber(height, 26, 10, 100)}%`;
  const width = Number(
    styleConfig.backgroundWidth ?? styleConfig.background_width ?? 100,
  );
  style.width = `${clampNumber(width, 100, 20, 100)}%`;
  style.maxHeight = 'calc(100% - 16px)';
  style.maxWidth = 'calc(100% - 16px)';
  const radius = Number(
    styleConfig.backgroundRadius ?? styleConfig.background_radius ?? 12,
  );
  style.borderRadius = `${toPreviewPx(clampNumber(radius, 12, 0, 80))}px`;
  Object.assign(
    style,
    cubeTitlePositionStyle(
      styleConfig.backgroundPosition ?? styleConfig.background_position,
    ),
  );
  const align =
    style.textAlign ||
    normalizePreviewTextAlign(
      styleConfig.textAlign ?? styleConfig.text_align,
    ) ||
    'center';
  style.textAlign = align;
  style.justifyContent = textAlignJustifyContent(align);
  return style;
}

function productLayout(module: ModuleItem) {
  const layout = module.props?.layout || 'grid';
  return ['grid', 'large', 'list', 'scroll'].includes(layout) ? layout : 'grid';
}

function productPreviewItems(module: ModuleItem) {
  const limit = Math.max(1, Math.min(Number(module.props?.limit || 4), 8));
  const previewList = [
    module.props?.preview_goods,
    module.props?.previewGoods,
    module.props?.goods,
    module.props?.list,
    module.props?.items,
  ].find((item) => Array.isArray(item) && item.length > 0);

  if (Array.isArray(previewList) && previewList.length > 0) {
    return previewList.slice(0, limit);
  }

  if (props.goodsList.length > 0) {
    return props.goodsList.slice(0, limit);
  }

  if (props.goods) {
    return [props.goods];
  }

  return Array.from({ length: limit }, (_, index) => {
    const item = PRODUCT_PREVIEW_ITEMS[index % PRODUCT_PREVIEW_ITEMS.length];
    return item || PRODUCT_PREVIEW_ITEMS[0]!;
  });
}

function productPreviewImage(item: any) {
  return getGoodsImage(item) || getImage(item);
}

function productPreviewName(item: any) {
  return item?.name || item?.title || '商品';
}

function productPreviewPrice(item: any) {
  return formatAmount(item?.price ?? item?.min_price ?? 0);
}

function productImageStyle(item: any, index: number) {
  return {
    '--mb-product-demo-color':
      item.color || PRODUCT_PREVIEW_ITEMS[index]?.color,
  };
}

function walletShowBalance(module: ModuleItem) {
  return module.props.show_balance !== false;
}

type WalletPreviewAction = { key: string; label: string; primary: boolean };

function walletActions(module: ModuleItem): WalletPreviewAction[] {
  const actions: WalletPreviewAction[] = [];
  if (walletShowBalance(module) && module.props.show_records !== false) {
    actions.push({ key: 'records', label: '余额明细', primary: false });
  }
  if (module.props.show_view_button !== false) {
    actions.push({ key: 'view', label: '去查看', primary: true });
  }
  return actions;
}

function pointsActions(module: ModuleItem): WalletPreviewAction[] {
  const actions: WalletPreviewAction[] = [];
  if (module.props.show_records !== false) {
    actions.push({ key: 'records', label: '积分明细', primary: false });
  }
  if (module.props.show_view_button !== false) {
    actions.push({ key: 'view', label: '去查看', primary: true });
  }
  return actions;
}

function richTextHtml(module: ModuleItem) {
  return (
    module.props?.content ||
    module.props?.html ||
    '<p><strong>图文内容</strong></p><p>这里展示活动说明、门店公告或售后政策。</p>'
  );
}

function spacingStyle(module: ModuleItem) {
  const height = clampNumber(module.props?.height, 32, 0, 300);
  return {
    height: `${toPreviewPx(height)}px`,
  };
}

function dividerStyle(module: ModuleItem) {
  const lineHeight = toPreviewPx(
    clampNumber(
      module.props?.height ??
        module.props?.lineHeight ??
        module.props?.line_height,
      1,
      1,
      20,
    ),
  );
  const color = styleColor(module.props?.color) || 'var(--mb-preview-divider)';
  const lineStyle = module.props?.style === 'dashed' ? 'dashed' : 'solid';
  return {
    borderTop: `${lineHeight}px ${lineStyle} ${color}`,
  };
}

function moduleIsHidden(module: ModuleItem) {
  return module.enabled === false || module.visible === false;
}

function getTabbarIcon(item: ModuleItem) {
  const active = item.path === props.currentPath;
  const icon = active
    ? item.selected_icon || item.icon || ''
    : item.icon || item.selected_icon || '';
  return resolvePreviewImageValue(icon);
}

function getTabbarIconType(item: ModuleItem) {
  const source = `${item.path || ''} ${item.key || ''} ${item.text || ''}`;
  if (source.includes('/pages/category') || source.includes('分类')) {
    return 'category';
  }
  if (source.includes('/pages/cart') || source.includes('购物车')) {
    return 'cart';
  }
  if (source.includes('/pages/order') || source.includes('订单')) {
    return 'order';
  }
  if (source.includes('/pages/profile') || source.includes('我的')) {
    return 'profile';
  }
  return 'home';
}

function tabbarIconClass(item: ModuleItem) {
  return [
    `tabbar-icon--${getTabbarIconType(item)}`,
    { 'tabbar-icon--custom': Boolean(getTabbarIcon(item)) },
  ];
}

const floatingPreviewConfig = computed(() => {
  const source = props.floatingConfig || {};
  const style =
    source.style && typeof source.style === 'object' ? source.style : {};
  return {
    enabled: source.enabled !== false,
    mode: ['expand', 'single', 'vertical'].includes(source.mode)
      ? source.mode
      : 'expand',
    offsetBottom: clampNumber(source.offsetBottom, 160, 0, 360),
    offsetX: clampNumber(source.offsetX, 24, 0, 160),
    position:
      source.position === 'left-bottom' ? 'left-bottom' : 'right-bottom',
    style: {
      backgroundColor:
        styleColor(style.backgroundColor) || 'var(--mb-preview-primary)',
      color: styleColor(style.color) || '#ffffff',
      radius: clampNumber(style.radius, 44, 0, 120),
      shadowBlur: clampNumber(style.shadowBlur ?? style.shadow_blur, 30, 0, 160),
      shadowColor: styleColor(style.shadowColor ?? style.shadow_color) || '#0f172a',
      shadowEnabled: style.shadowEnabled !== false,
      shadowOffsetX: clampNumber(
        style.shadowOffsetX ?? style.shadow_offset_x,
        0,
        -80,
        80,
      ),
      shadowOffsetY: clampNumber(
        style.shadowOffsetY ?? style.shadow_offset_y,
        12,
        -80,
        80,
      ),
      shadowOpacity: clampNumber(
        style.shadowOpacity ?? style.shadow_opacity,
        14,
        0,
        100,
      ),
      shadowSpread: clampNumber(
        style.shadowSpread ?? style.shadow_spread,
        0,
        -80,
        80,
      ),
      size: clampNumber(style.size, 88, 56, 128),
    },
  };
});

const floatingPreviewItems = computed(() =>
  normalizedModules.value
    .filter((item) => item.enabled !== false && item.visible !== false)
    .slice(0, 6),
);

const floatingRootStyle = computed(() => {
  const config = floatingPreviewConfig.value;
  return {
    bottom: `${toPreviewPx(config.offsetBottom)}px`,
    left:
      config.position === 'left-bottom'
        ? `${toPreviewPx(config.offsetX)}px`
        : 'auto',
    right:
      config.position === 'right-bottom'
        ? `${toPreviewPx(config.offsetX)}px`
        : 'auto',
  };
});

function floatingButtonStyle(item?: ModuleItem) {
  const config = floatingPreviewConfig.value;
  return {
    background: config.style.backgroundColor,
    borderRadius: `${toPreviewPx(config.style.radius)}px`,
    boxShadow: moduleShadowStyle(config.style),
    color: config.style.color,
    height: `${toPreviewPx(config.style.size)}px`,
    opacity: item?.enabled === false ? 0.45 : 1,
    width: `${toPreviewPx(config.style.size)}px`,
  };
}

function floatingIcon(item: ModuleItem) {
  return resolvePreviewImageValue(item.icon);
}

function isDropBefore(index: unknown) {
  return (
    props.interactive &&
    props.dragging &&
    props.dropIndex !== null &&
    Number(index) === props.dropIndex
  );
}

function isDropAppend() {
  if (!props.interactive || !props.dragging || props.dropIndex === null) {
    return false;
  }
  const visibleIndexes = normalizedModules.value.map((module) =>
    Number(module.__previewIndex),
  );
  return !visibleIndexes.includes(props.dropIndex);
}

function isTabbarDropAppend() {
  if (!props.interactive || !props.dragging || props.dropIndex === null) {
    return false;
  }
  const visibleIndexes = normalizedTabbarItems.value.map((item) =>
    Number(item.__previewIndex),
  );
  return !visibleIndexes.includes(props.dropIndex);
}

function updateSelectedModuleControlPosition() {
  if (!selectedModuleForControls.value) {
    setReadyIfChanged(selectedModuleControlReady, false);
    setReadyIfChanged(selectedModuleFrameReady, false);
    return;
  }

  void nextTick(() => {
    const root = previewRootRef.value;
    const target = root?.querySelector<HTMLElement>(
      '[data-module-selected="true"]',
    );
    const device = root?.querySelector<HTMLElement>(
      '.client-phone-preview__device',
    );
    if (!root || !target || !device) {
      setReadyIfChanged(selectedModuleControlReady, false);
      setReadyIfChanged(selectedModuleFrameReady, false);
      return;
    }

    const rootRect = root.getBoundingClientRect();
    const deviceRect = device.getBoundingClientRect();
    const targetRect = target.getBoundingClientRect();
    setStyleIfChanged(selectedModuleFrameStyle, {
      height: `${targetRect.height}px`,
      left: `${targetRect.left - deviceRect.left - device.clientLeft}px`,
      top: `${targetRect.top - deviceRect.top - device.clientTop}px`,
      width: `${targetRect.width}px`,
    });
    const rawControlTop = targetRect.top - rootRect.top + targetRect.height / 2;
    const controlTopMin =
      deviceRect.top -
      rootRect.top +
      device.clientTop +
      PREVIEW_EXTERNAL_CONTROL_VERTICAL_INSET;
    const controlTopMax =
      deviceRect.top -
      rootRect.top +
      device.clientTop +
      device.clientHeight -
      PREVIEW_EXTERNAL_CONTROL_VERTICAL_INSET;
    const controlTop =
      controlTopMin <= controlTopMax
        ? clampNumber(
            rawControlTop,
            rawControlTop,
            controlTopMin,
            controlTopMax,
          )
        : rawControlTop;
    setStyleIfChanged(selectedModuleControlStyle, {
      top: `${controlTop}px`,
    });
    setReadyIfChanged(selectedModuleFrameReady, true);
    setReadyIfChanged(selectedModuleControlReady, true);
  });
}

function handlePreviewBodyScroll() {
  updateSelectedModuleControlPosition();
}

function handlePreviewDelete(index: number, event: MouseEvent) {
  event.stopPropagation();
  emit('moduleDelete', index);
}

function handlePreviewMove(
  index: number,
  direction: 'down' | 'up',
  event: MouseEvent,
) {
  event.stopPropagation();
  emit('moduleMove', index, direction);
}

function handleSelect(module: ModuleItem) {
  if (!props.interactive) return;
  emit('selectModule', module);
}

function handlePreviewMouseDown(index: number, event: MouseEvent) {
  if (!props.interactive) return;
  event.preventDefault();
  emit('moduleMouseDown', index, event);
}
</script>

<template>
  <div
    ref="previewRootRef"
    class="client-phone-preview"
    :class="[
      `client-phone-preview--${size}`,
      `client-phone-preview--${kind}`,
      { 'client-phone-preview--interactive': interactive },
    ]"
    :style="previewStyle"
  >
    <div class="client-phone-preview__device">
      <div class="client-phone-preview__status">
        <span>9:41</span>
        <span>100%</span>
      </div>
      <div class="client-phone-preview__navbar">
        <span class="client-phone-preview__back">{{
          kind === 'home' || kind === 'profile' ? '' : '‹'
        }}</span>
        <strong>{{ pageTitle }}</strong>
        <span class="client-phone-preview__more">{{
          kind === 'profile' ? '' : '•••'
        }}</span>
      </div>

      <div class="client-phone-preview__body" @scroll="handlePreviewBodyScroll">
        <template v-if="kind === 'home'">
          <div class="home-page" :style="homePageStyle">
            <div class="home-brand">
              <span class="home-brand__mark"></span>
              <strong>MallBase</strong>
            </div>

            <template v-for="module in normalizedModules" :key="module.id">
              <div
                v-if="isDropBefore(module.__previewIndex)"
                class="preview-drop-placeholder"
              >
                <span>释放到这里</span>
              </div>
              <div
                class="preview-module"
                :class="[
                  {
                    'preview-module--hidden': moduleIsHidden(module),
                    'preview-module--interactive': interactive,
                    'preview-module--selected': selectedModuleId === module.id,
                  },
                ]"
                :data-module-index="interactive ? module.__previewIndex : null"
                :data-module-selected="
                  selectedModuleId === module.id ? 'true' : null
                "
                role="button"
                :style="moduleOuterStyle(module)"
                tabindex="0"
                @click="handleSelect(module)"
                @mousedown="
                  handlePreviewMouseDown(Number(module.__previewIndex), $event)
                "
              >
                <div
                  v-if="module.type === 'search'"
                  class="home-search"
                  :style="moduleBoxStyle(module)"
                >
                  <span class="home-search__icon"></span>
                  <span :style="homeTextStyle(module, 'placeholder')">
                    {{ module.props.placeholder || '搜索商品' }}
                  </span>
                </div>

                <div
                  v-else-if="module.type === 'banner'"
                  class="home-banner"
                  :style="
                    moduleBoxStyle(module, { height: bannerHeight(module) })
                  "
                >
                  <img v-if="bannerImage(module)" :src="bannerImage(module)" />
                  <div
                    v-else-if="!hasConfiguredModuleItems(module)"
                    class="home-banner__fallback"
                  >
                    <small>{{ module.props.subtitle || 'NEW ARRIVAL' }}</small>
                    <strong>{{
                      module.props.title || '夏日好物限时满减'
                    }}</strong>
                    <span>{{ module.props.buttonText || '立即领取' }}</span>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'navGrid'"
                  class="home-nav"
                  :style="{
                    ...moduleBoxStyle(module),
                    gridTemplateColumns: `repeat(${navColumns(module)}, 1fr)`,
                  }"
                >
                  <div
                    v-for="(item, itemIndex) in moduleList(module).slice(0, 10)"
                    :key="itemIndex"
                    class="home-nav__item"
                  >
                    <span class="home-nav__icon">
                      <img v-if="getImage(item)" :src="getImage(item)" />
                      <IconifyIcon
                        v-else-if="isIconifyName(getEntryIcon(item))"
                        :icon="getEntryIcon(item)"
                      />
                      <template v-else>{{ getFallbackIcon(item) }}</template>
                    </span>
                    <span :style="homeTextStyle(module, 'itemLabel')">
                      {{ getLabel(item) }}
                    </span>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'entryCard'"
                  class="home-entry-card"
                  :style="entryCardBoxStyle(module)"
                >
                  <span
                    v-if="entryCardIconImage(module)"
                    class="home-entry-card__icon"
                  >
                    <img :src="entryCardIconImage(module)" />
                  </span>
                  <div>
                    <strong :style="homeTextStyle(module, 'title')">
                      {{ module.props.title || '入口卡片' }}
                    </strong>
                    <small :style="homeTextStyle(module, 'subtitle')">
                      {{
                        module.props.subtitle || module.props.path || '点击查看'
                      }}
                    </small>
                  </div>
                  <em v-if="module.props.show_arrow !== false">›</em>
                </div>

                <div
                  v-else-if="module.type === 'imageCube'"
                  class="home-cube"
                  :class="`home-cube--${cubeDisplayLimit(module)}`"
                  :style="moduleBoxStyle(module)"
                >
                  <div
                    v-for="(item, itemIndex) in imageCubePreviewItems(
                      module,
                    ).slice(0, cubeDisplayLimit(module))"
                    :key="itemIndex"
                    class="home-cube__item"
                  >
                    <img v-if="item.image" :src="item.image" />
                    <strong
                      v-if="item.title"
                      class="home-cube__title"
                      :style="cubeTitleStyle(module)"
                    >
                      {{ item.title }}
                    </strong>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'productGroup'"
                  class="home-products"
                  :style="moduleBoxStyle(module)"
                >
                  <div class="home-section-head">
                    <div class="home-section-head__main">
                      <strong :style="homeTextStyle(module, 'title')">
                        {{ module.props.title || '猜你喜欢' }}
                      </strong>
                      <small
                        v-if="module.props.subtitle"
                        :style="homeTextStyle(module, 'subtitle')"
                      >
                        {{ module.props.subtitle }}
                      </small>
                    </div>
                    <span :style="homeTextStyle(module, 'more')">
                      {{ module.props.moreText || '查看全部' }}
                    </span>
                  </div>
                  <div
                    class="home-product-list"
                    :class="[`home-product-list--${productLayout(module)}`]"
                  >
                    <div
                      v-for="(item, itemIndex) in productPreviewItems(module)"
                      :key="getRecordKey(item, itemIndex)"
                      class="home-product"
                    >
                      <span
                        class="home-product__image"
                        :style="productImageStyle(item, itemIndex)"
                      >
                        <img
                          v-if="productPreviewImage(item)"
                          :src="productPreviewImage(item)"
                          alt=""
                        />
                      </span>
                      <span class="home-product__body">
                        <span class="home-product__name">
                          {{ productPreviewName(item) }}
                        </span>
                        <strong>¥{{ productPreviewPrice(item) }}</strong>
                      </span>
                    </div>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'title'"
                  class="home-section-head"
                  :style="titleBoxStyle(module)"
                >
                  <div
                    class="home-section-head__main"
                    :style="titleMainStyle(module)"
                  >
                    <strong :style="titleTextStyle(module)">
                      {{ module.props.title || module.props.text || '标题' }}
                    </strong>
                    <small
                      v-if="titleSubtitle(module)"
                      :style="titleSubStyle(module)"
                    >
                      {{ titleSubtitle(module) }}
                    </small>
                  </div>
                  <span
                    v-if="titleMoreText(module)"
                    class="home-section-head__more"
                    :style="titleMoreStyle(module)"
                  >
                    {{ titleMoreText(module) }}
                    <em>›</em>
                  </span>
                </div>

                <div
                  v-else-if="module.type === 'richText'"
                  class="home-rich"
                  :style="moduleBoxStyle(module)"
                >
                  <div
                    class="home-rich__content"
                    v-html="richTextHtml(module)"
                  ></div>
                </div>

                <div
                  v-else-if="module.type === 'spacing'"
                  class="home-spacing"
                  :style="moduleBoxStyle(module)"
                >
                  <div
                    class="home-spacing__inner"
                    :style="spacingStyle(module)"
                  ></div>
                </div>

                <div
                  v-else-if="module.type === 'divider'"
                  class="home-divider-wrap"
                  :style="moduleBoxStyle(module)"
                >
                  <div class="home-divider" :style="dividerStyle(module)"></div>
                </div>
              </div>
            </template>
            <div v-if="isDropAppend()" class="preview-drop-placeholder">
              <span>释放到这里</span>
            </div>
          </div>
        </template>

        <template v-else-if="kind === 'category'">
          <div class="category-page">
            <aside class="category-sidebar">
              <div
                v-for="(item, index) in categorySidebarItems"
                :key="getRecordKey(item, index)"
                class="category-sidebar__item"
                :class="[{ active: index === activeCategoryIndex }]"
              >
                {{ item.name }}
              </div>
            </aside>
            <main class="category-content">
              <div class="category-banner">
                <small>NEW ARRIVAL</small>
                <strong>{{ activeCategory?.name || '品质生活甄选' }}</strong>
              </div>
              <section
                v-for="group in categoryPreviewGroups"
                :key="group.title"
                class="category-group"
              >
                <div class="category-group__title">
                  <strong>{{ group.title }}</strong>
                  <span></span>
                </div>
                <div class="category-grid">
                  <div
                    v-for="(item, itemIndex) in group.items"
                    :key="getRecordKey(item, itemIndex)"
                  >
                    <span>
                      <img
                        v-if="getCategoryImage(item)"
                        :src="getCategoryImage(item)"
                      />
                      <template v-else>{{
                        getLabel(item).slice(0, 1)
                      }}</template>
                    </span>
                    <small>{{ getLabel(item) }}</small>
                  </div>
                </div>
              </section>
            </main>
          </div>
        </template>

        <template v-else-if="kind === 'goodsDetail' || kind === 'floating'">
          <div class="goods-detail-page">
            <div class="goods-hero">
              <img
                v-if="getGoodsImage(goodsPreview)"
                :src="getGoodsImage(goodsPreview)"
              />
              <span v-else></span>
            </div>
            <section class="goods-card goods-price-card">
              <div>
                <small>到手价</small>
                <strong>¥{{ formatAmount(goodsPreview.price) }}</strong>
                <em>¥{{ formatAmount(goodsPreview.market_price) }}</em>
              </div>
              <span>库存 {{ goodsPreview.stock || 0 }}</span>
            </section>
            <section class="goods-card">
              <h4>{{ goodsPreview.name }}</h4>
              <p>{{ goodsPreview.subtitle || goodsPreview.category_name }}</p>
              <div class="goods-tags">
                <span>活动标签</span>
                <span>商品保障</span>
              </div>
            </section>
            <section class="goods-cell">
              <span>活动</span>
              <strong>多人拼团、限时秒杀</strong>
              <em>›</em>
            </section>
            <section class="goods-cell">
              <span>规格</span>
              <strong>黑色, 80ml</strong>
              <em>›</em>
            </section>
            <section class="goods-card goods-review">
              <strong>评价</strong>
              <p>暂无评论</p>
            </section>
          </div>
        </template>

        <template v-else-if="kind === 'profile'">
          <div class="profile-page" :style="profilePageStyle">
            <template v-for="module in normalizedModules" :key="module.id">
              <div
                v-if="isDropBefore(module.__previewIndex)"
                class="preview-drop-placeholder"
              >
                <span>释放到这里</span>
              </div>
              <div
                class="preview-module"
                :class="[
                  {
                    'preview-module--hidden': moduleIsHidden(module),
                    'preview-module--interactive': interactive,
                    'preview-module--selected': selectedModuleId === module.id,
                  },
                ]"
                :data-module-index="interactive ? module.__previewIndex : null"
                :data-module-selected="
                  selectedModuleId === module.id ? 'true' : null
                "
                role="button"
                :style="moduleOuterStyle(module)"
                tabindex="0"
                @click="handleSelect(module)"
                @mousedown="
                  handlePreviewMouseDown(Number(module.__previewIndex), $event)
                "
              >
                <div
                  v-if="module.type === 'userCard'"
                  class="profile-header-card"
                  :style="moduleBoxStyle(module)"
                >
                  <span class="profile-avatar">M</span>
                  <div class="profile-header-card__main">
                    <strong
                      v-if="profileTextVisible(module, 'title')"
                      :style="profileTextStyle(module, 'title')"
                    >
                      点击登录
                    </strong>
                    <small
                      v-if="profileTextVisible(module, 'subtitle')"
                      :style="profileTextStyle(module, 'subtitle')"
                    >
                      登录后享受更多服务
                    </small>
                    <p
                      v-if="profileTextVisible(module, 'meta')"
                      :style="profileTextStyle(module, 'meta')"
                    >
                      {{
                        module.props.show_mobile !== false
                          ? '完善资料后可展示手机号和签名'
                          : '完善资料后可展示个性签名'
                      }}
                    </p>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'wallet'"
                  class="profile-wallet-card"
                  :style="moduleBoxStyle(module)"
                >
                  <small
                    v-if="profileTextVisible(module, 'title')"
                    :style="profileTextStyle(module, 'title')"
                  >
                    {{ module.props.title || '我的余额' }}
                  </small>
                  <strong
                    v-if="
                      walletShowBalance(module) &&
                      profileTextVisible(module, 'amount')
                    "
                    :style="profileTextStyle(module, 'amount')"
                  >
                    ¥0.00
                  </strong>
                  <p
                    v-if="
                      walletShowBalance(module) &&
                      profileTextVisible(module, 'meta')
                    "
                    class="profile-wallet-card__meta"
                    :style="profileTextStyle(module, 'meta')"
                  >
                    <span>累计充值 ¥0.00</span>
                    <i>•</i>
                    <span>累计消费 ¥0.00</span>
                  </p>
                  <div
                    v-if="walletActions(module).length > 0"
                    class="profile-wallet-card__actions"
                  >
                    <span
                      v-for="action in walletActions(module)"
                      :key="action.key"
                      :style="
                        profileTextStyle(
                          module,
                          action.primary ? 'primaryAction' : 'action',
                        )
                      "
                    >
                      {{
                        profileTextVisible(
                          module,
                          action.primary ? 'primaryAction' : 'action',
                        )
                          ? action.label
                          : ''
                      }}
                    </span>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'pointsEntry'"
                  class="profile-points-card"
                  :style="moduleBoxStyle(module)"
                >
                  <small
                    v-if="profileTextVisible(module, 'title')"
                    :style="profileTextStyle(module, 'title')"
                  >
                    {{ module.props.title || '我的积分' }}
                  </small>
                  <strong
                    v-if="profileTextVisible(module, 'amount')"
                    :style="profileTextStyle(module, 'amount')"
                  >
                    0
                  </strong>
                  <p
                    v-if="profileTextVisible(module, 'meta')"
                    class="profile-points-card__meta"
                    :style="profileTextStyle(module, 'meta')"
                  >
                    <span>累计获得 0</span>
                    <i>•</i>
                    <span>累计扣减 0</span>
                  </p>
                  <div
                    v-if="pointsActions(module).length > 0"
                    class="profile-points-card__actions"
                  >
                    <span
                      v-for="action in pointsActions(module)"
                      :key="action.key"
                      :style="
                        profileTextStyle(
                          module,
                          action.primary ? 'primaryAction' : 'action',
                        )
                      "
                    >
                      {{
                        profileTextVisible(
                          module,
                          action.primary ? 'primaryAction' : 'action',
                        )
                          ? action.label
                          : ''
                      }}
                    </span>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'orderShortcut'"
                  class="profile-order-card"
                  :style="moduleBoxStyle(module)"
                >
                  <div class="profile-section-head">
                    <strong
                      v-if="profileTextVisible(module, 'title')"
                      :style="profileTextStyle(module, 'title')"
                    >
                      {{ module.props.title || '我的订单' }}
                    </strong>
                    <span
                      v-if="profileTextVisible(module, 'more')"
                      class="profile-section-more"
                      :style="profileTextStyle(module, 'more')"
                    >
                      查看全部<i></i>
                    </span>
                  </div>
                  <div class="profile-grid">
                    <div
                      v-for="(item, index) in moduleList(module)"
                      :key="index"
                    >
                      <span
                        class="profile-entry-icon profile-entry-icon--order"
                        :class="profileIconClass(item)"
                      >
                        <img
                          v-if="getImage(item)"
                          :src="getImage(item)"
                          alt=""
                        />
                        <template v-else>
                          <span :style="profileTextStyle(module, 'iconText')">
                            {{ profileIconText(item) }}
                          </span>
                        </template>
                      </span>
                      <small
                        v-if="profileTextVisible(module, 'itemLabel')"
                        :style="profileTextStyle(module, 'itemLabel')"
                      >
                        {{ getLabel(item) }}
                      </small>
                    </div>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'serviceMenu'"
                  class="profile-service-card"
                  :style="serviceMenuBoxStyle(module)"
                >
                  <div class="profile-section-head">
                    <strong
                      v-if="profileTextVisible(module, 'title')"
                      :style="profileTextStyle(module, 'title')"
                    >
                      {{ module.props.title || '我的服务' }}
                    </strong>
                  </div>
                  <div
                    v-if="module.props.display === 'grid'"
                    class="profile-grid profile-grid--service"
                  >
                    <div
                      v-for="(item, index) in moduleList(module)"
                      :key="index"
                    >
                      <span
                        class="profile-entry-icon profile-entry-icon--grid"
                        :class="profileIconClass(item)"
                      >
                        <img
                          v-if="getImage(item)"
                          :src="getImage(item)"
                          alt=""
                        />
                        <template v-else>
                          <span :style="profileTextStyle(module, 'iconText')">
                            {{ profileIconText(item) }}
                          </span>
                        </template>
                      </span>
                      <small
                        v-if="profileTextVisible(module, 'itemLabel')"
                        :style="profileTextStyle(module, 'itemLabel')"
                      >
                        {{ getLabel(item) }}
                      </small>
                    </div>
                  </div>
                  <template v-else>
                    <div
                      v-for="(item, index) in moduleList(module)"
                      :key="index"
                      class="profile-cell"
                    >
                      <span
                        class="profile-entry-icon profile-entry-icon--cell"
                        :class="profileIconClass(item)"
                      >
                        <img
                          v-if="getImage(item)"
                          :src="getImage(item)"
                          alt=""
                        />
                        <template v-else>
                          <span :style="profileTextStyle(module, 'iconText')">
                            {{ profileIconText(item) }}
                          </span>
                        </template>
                      </span>
                      <strong
                        v-if="profileTextVisible(module, 'itemLabel')"
                        :style="profileTextStyle(module, 'itemLabel')"
                      >
                        {{ getLabel(item) }}
                      </strong>
                      <em>›</em>
                    </div>
                  </template>
                </div>
              </div>
            </template>
            <div v-if="isDropAppend()" class="preview-drop-placeholder">
              <span>释放到这里</span>
            </div>
          </div>
        </template>

        <template v-else>
          <div class="tabbar-page"></div>
        </template>
      </div>

      <div
        v-if="kind === 'floating' && floatingPreviewConfig.enabled"
        class="client-phone-preview__floating"
        :class="[
          `client-phone-preview__floating--${floatingPreviewConfig.mode}`,
          `client-phone-preview__floating--${floatingPreviewConfig.position}`,
        ]"
        :style="floatingRootStyle"
      >
        <template
          v-for="(item, index) in floatingPreviewConfig.mode === 'single'
            ? floatingPreviewItems.slice(0, 1)
            : floatingPreviewItems"
          :key="item.id"
        >
          <div
            v-if="isDropBefore(item.__previewIndex)"
            class="preview-drop-placeholder preview-drop-placeholder--floating"
          ></div>
          <button
            class="floating-preview-button"
            :class="{
              'floating-preview-button--selected': selectedModuleId === item.id,
              'floating-preview-button--text':
                floatingPreviewConfig.mode === 'vertical',
            }"
            :data-module-index="interactive ? item.__previewIndex : null"
            :data-module-selected="selectedModuleId === item.id ? 'true' : null"
            type="button"
            :style="floatingButtonStyle(item)"
            @click="handleSelect(item)"
            @mousedown="
              handlePreviewMouseDown(Number(item.__previewIndex), $event)
            "
          >
            <img v-if="floatingIcon(item)" :src="floatingIcon(item)" alt="" />
            <span v-else>{{ (item.text || '入').slice(0, 1) }}</span>
            <small v-if="floatingPreviewConfig.mode === 'vertical'">
              {{ item.text || `入口${index + 1}` }}
            </small>
          </button>
        </template>
        <div
          v-if="isDropAppend()"
          class="preview-drop-placeholder preview-drop-placeholder--floating"
        ></div>
      </div>

      <div
        v-if="kind !== 'goodsDetail' && kind !== 'floating'"
        class="client-phone-preview__tabbar"
      >
        <template v-for="item in normalizedTabbarItems" :key="item.id">
          <div
            v-if="kind === 'tabbar' && isDropBefore(item.__previewIndex)"
            class="preview-drop-placeholder preview-drop-placeholder--tabbar"
          ></div>
          <div
            class="client-phone-preview__tabbar-item"
            :class="{
              active: item.path === currentPath,
              selected: selectedModuleId === item.id,
            }"
            :data-module-index="
              kind === 'tabbar' && interactive ? item.__previewIndex : null
            "
            role="button"
            tabindex="0"
            @click="kind === 'tabbar' && handleSelect(item)"
            @mousedown="
              kind === 'tabbar' &&
              handlePreviewMouseDown(Number(item.__previewIndex), $event)
            "
          >
            <span
              v-if="
                kind === 'tabbar' && interactive && selectedModuleId === item.id
              "
              class="preview-selected-badge preview-selected-badge--tabbar"
              :class="[
                {
                  'preview-selected-badge--edge-start':
                    Number(item.__previewIndex) === 0,
                  'preview-selected-badge--edge-end':
                    Number(item.__previewIndex) ===
                    normalizedTabbarItems.length - 1,
                },
              ]"
            >
              <span class="preview-selected-label">{{ item.text }}</span>
              <span class="preview-selected-actions">
                <button
                  type="button"
                  title="上移"
                  @click.stop="
                    handlePreviewMove(Number(item.__previewIndex), 'up', $event)
                  "
                  @mousedown.stop.prevent
                >
                  ←
                </button>
                <button
                  type="button"
                  title="下移"
                  @click.stop="
                    handlePreviewMove(
                      Number(item.__previewIndex),
                      'down',
                      $event,
                    )
                  "
                  @mousedown.stop.prevent
                >
                  →
                </button>
                <button
                  class="danger"
                  type="button"
                  title="删除"
                  @click.stop="
                    handlePreviewDelete(Number(item.__previewIndex), $event)
                  "
                  @mousedown.stop.prevent
                >
                  ×
                </button>
              </span>
            </span>
            <span class="tabbar-icon" :class="tabbarIconClass(item)">
              <img
                v-if="isImageLike(getTabbarIcon(item))"
                :src="getTabbarIcon(item)"
              />
              <IconifyIcon
                v-else-if="isIconifyName(getTabbarIcon(item))"
                :icon="getTabbarIcon(item)"
              />
            </span>
            <small>{{ item.text }}</small>
          </div>
        </template>
        <div
          v-if="kind === 'tabbar' && isTabbarDropAppend()"
          class="preview-drop-placeholder preview-drop-placeholder--tabbar"
        ></div>
      </div>

      <div v-else class="goods-action-bar">
        <div>
          <span></span>
          <small>客服</small>
        </div>
        <div>
          <span></span>
          <small>购物车</small>
        </div>
        <button>加入购物车</button>
        <button>立即购买</button>
      </div>

      <div
        v-if="selectedModuleForControls && selectedModuleFrameReady"
        class="preview-selection-frame"
        :style="selectedModuleFrameStyle"
        aria-hidden="true"
      ></div>
    </div>

    <div
      v-if="selectedModuleForControls && selectedModuleControlReady"
      class="preview-external-controls"
      :style="selectedModuleControlStyle"
      @click.stop
      @mousedown.stop
    >
      <span class="preview-external-title">
        {{ selectedModuleControlTitle }}
      </span>
      <span class="preview-external-actions">
        <button
          type="button"
          title="上移"
          @click.stop="
            handlePreviewMove(selectedModuleControlIndex, 'up', $event)
          "
          @mousedown.stop.prevent
        >
          ↑
        </button>
        <button
          type="button"
          title="下移"
          @click.stop="
            handlePreviewMove(selectedModuleControlIndex, 'down', $event)
          "
          @mousedown.stop.prevent
        >
          ↓
        </button>
        <button
          class="danger"
          type="button"
          title="删除"
          @click.stop="handlePreviewDelete(selectedModuleControlIndex, $event)"
          @mousedown.stop.prevent
        >
          ×
        </button>
        <button
          class="drag"
          type="button"
          title="拖拽排序"
          @mousedown.stop="
            handlePreviewMouseDown(selectedModuleControlIndex, $event)
          "
        >
          ⋮⋮
        </button>
      </span>
    </div>
  </div>
</template>

<style scoped>
.client-phone-preview {
  position: relative;
  width: max-content;
  margin: 0 auto;
  color: var(--mb-preview-text);
  user-select: none;
  -webkit-user-select: none;
}

.client-phone-preview__device {
  position: relative;
  display: flex;
  flex-direction: column;
  width: 375px;
  height: 720px;
  margin: 0 auto;
  overflow: hidden;
  border: 1px solid hsl(var(--border));
  border-radius: 28px;
  background: var(--mb-preview-bg-secondary);
  box-shadow: 0 16px 42px rgb(15 23 42 / 12%);
}

.client-phone-preview--compact .client-phone-preview__device {
  width: 280px;
  height: 536px;
  border-radius: 18px;
  box-shadow: 0 10px 28px rgb(15 23 42 / 10%);
}

.client-phone-preview--tabbar.client-phone-preview--compact
  .client-phone-preview__device {
  height: 210px;
}

.client-phone-preview--tabbar:not(.client-phone-preview--compact)
  .client-phone-preview__device {
  height: 320px;
}

.client-phone-preview__status {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 24px;
  padding: 0 22px;
  font-size: 12px;
  color: var(--mb-preview-text);
  background: var(--mb-preview-bg);
}

.client-phone-preview--profile .client-phone-preview__status {
  display: none;
}

.client-phone-preview--compact .client-phone-preview__status {
  height: 20px;
  padding: 0 17px;
  font-size: 10px;
}

.client-phone-preview__navbar {
  display: grid;
  grid-template-columns: 42px 1fr 42px;
  align-items: center;
  min-height: 44px;
  padding: 0 10px;
  border-bottom: 1px solid var(--mb-preview-divider);
  background: var(--mb-preview-bg);
}

.client-phone-preview--compact .client-phone-preview__navbar {
  grid-template-columns: 32px 1fr 32px;
  min-height: 34px;
  font-size: 12px;
}

.client-phone-preview__navbar strong {
  overflow: hidden;
  font-size: 15px;
  text-align: center;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.client-phone-preview--compact .client-phone-preview__navbar strong {
  font-size: 12px;
}

.client-phone-preview__back {
  font-size: 26px;
  line-height: 1;
  color: var(--mb-preview-text-tertiary);
}

.client-phone-preview__more {
  text-align: right;
  color: var(--mb-preview-text-tertiary);
}

.client-phone-preview__body {
  flex: 1;
  min-height: 0;
  overflow: auto;
  background: var(--mb-preview-bg-secondary);
  scrollbar-width: none;
}

.client-phone-preview__body::-webkit-scrollbar {
  display: none;
}

.home-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 14px;
}

.profile-page {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 100%;
  padding: 0 14px 16px;
  background: var(--mb-preview-bg-secondary);
}

.client-phone-preview--interactive .home-page {
  padding-top: 46px;
}

.client-phone-preview--interactive .profile-page {
  padding-top: 0;
}

.client-phone-preview--compact .home-page {
  gap: 10px;
  padding: 10px;
}

.client-phone-preview--compact .profile-page {
  gap: 10px;
  padding: 0 10px 12px;
}

.client-phone-preview--compact.client-phone-preview--interactive .home-page {
  padding-top: 40px;
}

.client-phone-preview--compact.client-phone-preview--interactive .profile-page {
  padding-top: 0;
}

.home-brand {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 36px;
  color: var(--mb-preview-primary);
}

.client-phone-preview--compact .home-brand {
  height: 28px;
  font-size: 13px;
}

.home-brand__mark {
  position: relative;
  width: 17px;
  height: 15px;
  border: 2px solid currentColor;
  border-radius: 4px;
}

.home-brand__mark::before {
  position: absolute;
  top: 3px;
  right: 2px;
  left: 2px;
  height: 2px;
  content: '';
  background: currentColor;
  border-radius: 2px;
}

.preview-module {
  position: relative;
  display: block;
  width: 100%;
  padding: 0;
  color: inherit;
  text-align: left;
  cursor: default;
  border: 0;
  background: transparent;
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;
}

.preview-module--interactive {
  cursor: pointer;
}

.preview-module--hidden {
  opacity: 0.46;
}

.preview-module--hidden::after {
  position: absolute;
  z-index: 4;
  inset: 0;
  display: grid;
  pointer-events: none;
  color: var(--mb-preview-text-secondary);
  content: '已隐藏，客户端不展示';
  border: 1px dashed var(--mb-preview-border);
  border-radius: 12px;
  background: color-mix(in srgb, var(--mb-preview-bg) 72%, transparent);
  place-items: center;
  font-size: 12px;
  font-weight: 700;
}

.preview-selected-badge {
  position: absolute;
  z-index: 30;
  top: -34px;
  left: -6px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  max-width: calc(100% + 24px);
  padding: 4px 6px 4px 9px;
  color: white;
  border-radius: 999px;
  background: var(--mb-preview-primary);
  box-shadow: 0 8px 18px rgb(15 23 42 / 16%);
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
}

.preview-selected-badge--tabbar {
  top: -32px;
  left: 50%;
  gap: 4px;
  width: max-content;
  max-width: none;
  min-height: 26px;
  padding: 2px 3px 2px 8px;
  color: var(--mb-preview-primary);
  white-space: nowrap;
  border: 1px solid
    color-mix(in srgb, var(--mb-preview-primary) 28%, transparent);
  background: color-mix(
    in srgb,
    var(--mb-preview-bg) 92%,
    var(--mb-preview-primary) 8%
  );
  box-shadow: 0 8px 18px rgb(15 23 42 / 14%);
  transform: translateX(-50%);
}

.preview-selected-badge--tabbar.preview-selected-badge--edge-start {
  left: 0;
  transform: translateX(0);
}

.preview-selected-badge--tabbar.preview-selected-badge--edge-end {
  right: 0;
  left: auto;
  transform: translateX(0);
}

.preview-selected-label {
  display: inline-block;
  white-space: nowrap;
}

.preview-selected-badge--tabbar .preview-selected-label {
  line-height: 20px;
}

.preview-selected-title {
  white-space: nowrap;
}

.preview-selection-frame {
  position: absolute;
  z-index: 50;
  box-sizing: border-box;
  pointer-events: none;
  border-radius: 12px;
  outline: 2px solid var(--mb-preview-primary);
  outline-offset: -2px;
  box-shadow: inset 0 0 0 5px
    color-mix(in srgb, var(--mb-preview-primary) 12%, transparent);
}

.preview-external-controls {
  position: absolute;
  z-index: 60;
  right: 0;
  left: 0;
  pointer-events: none;
  transform: translateY(-50%);
}

.preview-external-title {
  position: absolute;
  top: 50%;
  right: calc(100% + 10px);
  display: inline-flex;
  align-items: center;
  max-width: 96px;
  padding: 7px 9px;
  overflow: hidden;
  color: #fff;
  pointer-events: none;
  text-overflow: ellipsis;
  white-space: nowrap;
  border-radius: 999px;
  background: var(--mb-preview-primary);
  box-shadow: 0 8px 18px rgb(15 23 42 / 16%);
  transform: translateY(-50%);
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
}

.preview-selected-actions {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.preview-selected-badge--tabbar .preview-selected-actions {
  gap: 4px;
}

.preview-selected-actions button,
.preview-external-actions button {
  display: grid;
  width: 24px;
  height: 24px;
  padding: 0;
  color: white;
  cursor: pointer;
  border: 0;
  border-radius: 999px;
  background: var(--mb-preview-primary);
  box-shadow: 0 5px 14px rgb(13 80 213 / 22%);
  place-items: center;
  font-size: 12px;
  line-height: 1;
}

.preview-selected-actions button:hover,
.preview-external-actions button:hover {
  background: var(--mb-preview-primary-light);
}

.preview-selected-actions button.danger,
.preview-external-actions button.danger {
  color: #fff;
  background: #ff4d4f;
}

.preview-selected-actions button.drag,
.preview-external-actions button.drag {
  letter-spacing: -2px;
}

.preview-external-actions {
  position: absolute;
  top: 50%;
  left: calc(100% + 10px);
  display: inline-flex;
  flex-direction: column;
  gap: 4px;
  pointer-events: auto;
  transform: translateY(-50%);
}

.preview-drop-placeholder {
  display: grid;
  position: relative;
  min-height: 14px;
  margin: -5px 0;
  pointer-events: none;
  color: var(--mb-preview-primary);
  border-radius: 999px;
  background: transparent;
  place-items: center;
  animation: preview-drop-pulse 0.9s ease-in-out infinite alternate;
}

.preview-drop-placeholder::before {
  position: absolute;
  top: 50%;
  right: 10px;
  left: 10px;
  height: 3px;
  content: '';
  background: var(--mb-preview-primary);
  border-radius: 999px;
  transform: translateY(-50%);
}

.preview-drop-placeholder span {
  position: relative;
  z-index: 1;
  padding: 4px 10px;
  color: var(--mb-preview-primary);
  border-radius: 999px;
  background: var(--mb-preview-bg);
  font-size: 12px;
  font-weight: 700;
}

.preview-drop-placeholder--tabbar {
  align-self: stretch;
  width: 8px;
  min-height: 44px;
  margin: 0 2px;
  pointer-events: none;
  border-width: 0 0 0 3px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--mb-preview-primary) 18%, transparent);
}

.preview-drop-placeholder--tabbar::before {
  top: 8px;
  bottom: 8px;
  left: 50%;
  width: 3px;
  height: auto;
  transform: translateX(-50%);
}

@keyframes preview-drop-pulse {
  from {
    box-shadow: 0 0 0 0
      color-mix(in srgb, var(--mb-preview-primary) 10%, transparent);
  }

  to {
    box-shadow: 0 0 0 5px
      color-mix(in srgb, var(--mb-preview-primary) 8%, transparent);
  }
}

.home-search {
  display: flex;
  align-items: center;
  height: 36px;
  padding: 0 14px;
  color: var(--mb-preview-text-tertiary);
  border-radius: 10px;
  background: var(--mb-preview-bg-surface);
}

.client-phone-preview--compact .home-search {
  height: 30px;
  padding: 0 10px;
  font-size: 11px;
}

.home-search__icon {
  position: relative;
  width: 12px;
  height: 12px;
  margin-right: 10px;
  border: 2px solid currentColor;
  border-radius: 50%;
}

.home-search__icon::after {
  position: absolute;
  right: -6px;
  bottom: -4px;
  width: 7px;
  height: 2px;
  content: '';
  background: currentColor;
  border-radius: 2px;
  transform: rotate(45deg);
}

.home-banner {
  overflow: hidden;
  border-radius: 12px;
  background: var(--mb-preview-bg-surface);
}

.home-banner img {
  width: 100%;
  height: 100%;
  object-fit: fill;
}

.home-cube img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.home-banner__fallback {
  display: grid;
  align-content: center;
  height: 100%;
  padding: 22px;
  color: white;
  background:
    radial-gradient(circle at 80% 22%, rgb(255 255 255 / 26%), transparent 24%),
    linear-gradient(
      135deg,
      var(--mb-preview-primary) 0%,
      var(--mb-preview-primary-light) 44%,
      var(--mb-preview-price) 100%
    );
}

.client-phone-preview--compact .home-banner__fallback {
  padding: 16px;
}

.home-banner__fallback small,
.home-banner__fallback strong,
.home-banner__fallback span {
  display: block;
}

.home-banner__fallback strong {
  margin: 6px 0 12px;
  font-size: 20px;
}

.client-phone-preview--compact .home-banner__fallback strong {
  font-size: 15px;
}

.home-banner__fallback span {
  width: max-content;
  padding: 5px 12px;
  border-radius: 999px;
  background: rgb(255 255 255 / 22%);
}

.home-nav {
  display: grid;
  row-gap: 14px;
  padding: 6px 0;
}

.home-nav__item,
.profile-grid > div {
  min-width: 0;
  text-align: center;
}

.home-nav__item > span:last-child,
.profile-grid small,
.client-phone-preview__tabbar small {
  display: block;
  overflow: hidden;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.client-phone-preview--compact .home-nav__item > span:last-child,
.client-phone-preview--compact .profile-grid small,
.client-phone-preview--compact .client-phone-preview__tabbar small {
  font-size: 10px;
}

.home-nav__icon,
.profile-entry-icon {
  display: grid;
  width: 38px;
  height: 38px;
  margin: 0 auto 8px;
  place-items: center;
  color: var(--profile-icon-color, var(--mb-preview-primary));
  border-radius: 14px;
  background: var(
    --profile-icon-bg,
    color-mix(in srgb, var(--mb-preview-primary) 10%, transparent)
  );
  font-size: 15px;
  font-weight: 700;
  line-height: 1;
}

.home-nav__icon svg,
.profile-entry-icon svg {
  width: 18px;
  height: 18px;
}

.home-nav__icon img,
.profile-entry-icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: inherit;
}

.client-phone-preview--compact .home-nav__icon,
.client-phone-preview--compact .profile-entry-icon {
  width: 30px;
  height: 30px;
  margin-bottom: 5px;
  border-radius: 10px;
  font-size: 11px;
}

.home-cube {
  display: grid;
  gap: 8px;
}

.home-cube--1 {
  grid-template-columns: 1fr;
}

.home-cube--2,
.home-cube--4 {
  grid-template-columns: repeat(2, 1fr);
}

.home-entry-card {
  display: grid;
  grid-template-columns: 42px minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  padding: 13px 14px;
  border: 1px solid var(--mb-preview-divider);
  border-radius: 12px;
  background: var(--mb-preview-bg);
}

.home-entry-card__icon {
  display: grid;
  width: 38px;
  height: 38px;
  color: var(--mb-preview-primary);
  border-radius: 14px;
  background: color-mix(in srgb, var(--mb-preview-primary) 10%, transparent);
  place-items: center;
}

.home-entry-card__icon img,
.home-entry-card__icon svg {
  width: 18px;
  height: 18px;
}

.home-entry-card__icon img {
  object-fit: cover;
}

.home-entry-card strong,
.home-entry-card small {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.home-entry-card small {
  margin-top: 3px;
  color: var(--mb-preview-text-tertiary);
  font-size: 11px;
}

.home-entry-card em {
  color: var(--mb-preview-text-tertiary);
  font-style: normal;
}

.home-cube__item {
  position: relative;
  display: grid;
  min-height: 92px;
  overflow: hidden;
  place-items: end start;
  border-radius: 10px;
  background:
    radial-gradient(
      circle at 75% 30%,
      color-mix(in srgb, var(--mb-preview-primary) 22%, transparent),
      transparent 36%
    ),
    var(--mb-preview-bg-surface);
}

.home-cube__title {
  position: absolute;
  bottom: 8px;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  width: calc(100% - 16px);
  height: 26%;
  max-width: calc(100% - 16px);
  max-height: calc(100% - 16px);
  padding: 4px 6px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  left: 50%;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.78);
  transform: translateX(-50%);
}

.client-phone-preview--compact .home-cube__item {
  min-height: 64px;
  font-size: 11px;
}

.home-products,
.home-rich,
.profile-order-card,
.profile-points-card,
.profile-service-card,
.profile-wallet-card {
  padding: 14px;
  border: 1px solid var(--mb-preview-divider);
  border-radius: 10px;
  background: var(--mb-preview-bg);
}

.profile-order-card,
.profile-points-card,
.profile-service-card,
.profile-wallet-card {
  margin: 0;
}

.client-phone-preview--compact .home-products,
.client-phone-preview--compact .home-rich,
.client-phone-preview--compact .profile-order-card,
.client-phone-preview--compact .profile-points-card,
.client-phone-preview--compact .profile-service-card,
.client-phone-preview--compact .profile-wallet-card {
  padding: 10px;
  border-radius: 10px;
}

.home-section-head,
.profile-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
}

.home-section-head__main {
  flex: 1;
  min-width: 0;
}

.home-section-head strong,
.profile-section-head strong {
  display: block;
  color: var(--mb-preview-text-title);
}

.profile-section-head strong {
  flex: 1;
  min-width: 0;
}

.home-section-head span,
.profile-section-head span {
  font-size: 12px;
  color: var(--mb-preview-text-tertiary);
}

.profile-section-more {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 4px;
}

.profile-section-more i {
  width: 6px;
  height: 6px;
  border-right: 1px solid currentColor;
  border-bottom: 1px solid currentColor;
  transform: rotate(-45deg);
}

.home-section-head small {
  display: block;
  margin-top: 3px;
  overflow: hidden;
  color: var(--mb-preview-text-tertiary);
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.home-section-head__more {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: 2px;
}

.home-section-head__more em {
  font-style: normal;
}

.home-product-list {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
}

.home-product-list--scroll {
  display: flex;
  overflow: hidden;
}

.home-product-list--large {
  grid-template-columns: 1fr;
}

.home-product-list--list {
  grid-template-columns: 1fr;
  gap: 8px;
}

.home-product {
  min-width: 96px;
  padding: 8px;
  border-radius: 8px;
  background: var(--mb-preview-bg-secondary);
}

.home-product-list--list .home-product {
  display: grid;
  grid-template-columns: 64px minmax(0, 1fr);
  gap: 10px;
  align-items: center;
}

.home-product-list--large .home-product {
  padding: 10px;
}

.client-phone-preview--compact .home-product {
  min-width: 74px;
  padding: 6px;
}

.home-product__image {
  display: block;
  height: 78px;
  margin-bottom: 7px;
  overflow: hidden;
  border-radius: 7px;
  background:
    radial-gradient(
      circle at 72% 28%,
      color-mix(in srgb, var(--mb-preview-primary) 18%, transparent),
      transparent 32%
    ),
    var(--mb-product-demo-color, var(--mb-preview-bg-surface));
}

.home-product__image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.client-phone-preview--compact .home-product__image {
  height: 54px;
}

.home-product-list--large .home-product__image {
  height: 132px;
  margin-bottom: 9px;
}

.home-product-list--list .home-product__image {
  height: 64px;
  margin-bottom: 0;
}

.client-phone-preview--compact .home-product-list--large .home-product__image {
  height: 94px;
}

.client-phone-preview--compact .home-product-list--list .home-product__image {
  height: 52px;
}

.home-product__body {
  display: block;
  min-width: 0;
}

.home-product__name {
  display: block;
  overflow: hidden;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.home-product-list--large .home-product__name {
  font-size: 13px;
  font-weight: 600;
}

.home-product-list--list .home-product__name {
  margin-bottom: 5px;
  white-space: normal;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.home-product strong,
.goods-price-card strong {
  color: var(--mb-preview-price);
}

.home-product strong {
  display: block;
  margin-top: 4px;
  font-size: 13px;
}

.home-product-list--large .home-product strong {
  font-size: 15px;
}

.home-product-list--list .home-product strong {
  margin-top: 0;
}

.home-spacing,
.home-divider-wrap {
  box-sizing: border-box;
}

.home-spacing__inner {
  width: 100%;
}

.home-divider {
  width: 100%;
  height: 0;
  border-top: 1px solid var(--mb-preview-divider);
  background: transparent;
}

.home-rich__content {
  user-select: none;
}

.home-rich__content :deep(p) {
  margin: 0 0 8px;
}

.home-rich__content :deep(p:last-child) {
  margin-bottom: 0;
}

.home-rich__content :deep(img) {
  max-width: 100%;
  border-radius: 8px;
}

.category-page {
  display: grid;
  grid-template-columns: 86px 1fr;
  min-height: 100%;
  background: var(--mb-preview-bg);
}

.client-phone-preview--compact .category-page {
  grid-template-columns: 66px 1fr;
}

.category-sidebar {
  background: var(--mb-preview-bg-surface);
}

.category-sidebar__item {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 54px;
  padding: 0 8px;
  overflow: hidden;
  font-size: 12px;
  color: var(--mb-preview-text-secondary);
  text-align: center;
  text-overflow: ellipsis;
}

.client-phone-preview--compact .category-sidebar__item {
  min-height: 42px;
  font-size: 10px;
}

.category-sidebar__item.active {
  color: var(--mb-preview-text-title);
  background: var(--mb-preview-bg);
  font-weight: 700;
}

.category-sidebar__item.active::before {
  position: absolute;
  top: 17px;
  bottom: 17px;
  left: 0;
  width: 3px;
  content: '';
  background: var(--mb-preview-primary);
  border-radius: 0 999px 999px 0;
}

.category-content {
  min-width: 0;
  padding: 14px;
}

.client-phone-preview--compact .category-content {
  padding: 10px;
}

.category-banner {
  display: grid;
  align-content: center;
  min-height: 100px;
  padding: 16px;
  color: white;
  border-radius: 12px;
  background:
    radial-gradient(
      circle at 78% 48%,
      rgb(255 255 255 / 16%) 0 16%,
      transparent 17%
    ),
    linear-gradient(
      135deg,
      color-mix(in srgb, var(--mb-preview-primary) 86%, #111827) 0%,
      #111827 100%
    );
}

.client-phone-preview--compact .category-banner {
  min-height: 76px;
  padding: 12px;
}

.category-banner small {
  opacity: 0.74;
}

.category-banner strong {
  margin-top: 4px;
}

.category-group {
  margin-top: 18px;
}

.category-group__title {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--mb-preview-text-secondary);
  font-size: 12px;
}

.category-group__title span {
  flex: 1;
  height: 1px;
  background: var(--mb-preview-divider);
}

.category-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px 8px;
  margin-top: 10px;
}

.category-grid div {
  min-width: 0;
  text-align: center;
}

.category-grid span {
  display: grid;
  width: 52px;
  height: 52px;
  margin: 0 auto 6px;
  place-items: center;
  color: var(--mb-preview-primary);
  border: 1px solid var(--mb-preview-divider);
  border-radius: 14px;
  background: var(--mb-preview-bg-secondary);
  font-weight: 700;
}

.category-grid img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.client-phone-preview--compact .category-grid span {
  width: 38px;
  height: 38px;
  border-radius: 10px;
}

.category-grid small {
  display: block;
  overflow: hidden;
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.goods-detail-page {
  min-height: 100%;
  padding-bottom: 10px;
  background: var(--mb-preview-bg-secondary);
}

.goods-hero {
  display: grid;
  height: 300px;
  place-items: center;
  background: var(--mb-preview-bg);
}

.client-phone-preview--compact .goods-hero {
  height: 204px;
}

.goods-hero span {
  width: 150px;
  height: 190px;
  border-radius: 18px;
  background:
    radial-gradient(circle at 54% 18%, white 0 14%, transparent 15%),
    linear-gradient(
      160deg,
      var(--mb-preview-bg-surface),
      color-mix(in srgb, var(--mb-preview-primary) 16%, var(--mb-preview-bg))
    );
  box-shadow: 0 12px 30px rgb(15 23 42 / 12%);
}

.goods-hero img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.client-phone-preview--compact .goods-hero span {
  width: 96px;
  height: 124px;
}

.goods-card,
.goods-cell {
  margin: 10px;
  padding: 14px;
  border: 1px solid var(--mb-preview-divider);
  border-radius: 12px;
  background: var(--mb-preview-bg);
}

.client-phone-preview--compact .goods-card,
.client-phone-preview--compact .goods-cell {
  margin: 8px;
  padding: 10px;
}

.goods-price-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.goods-price-card small,
.goods-price-card em,
.goods-card p,
.goods-cell span,
.goods-review p {
  color: var(--mb-preview-text-tertiary);
}

.goods-price-card strong {
  margin: 0 6px;
  font-size: 24px;
}

.client-phone-preview--compact .goods-price-card strong {
  font-size: 18px;
}

.goods-price-card em {
  font-style: normal;
  text-decoration: line-through;
}

.goods-card h4 {
  margin: 0;
  font-size: 16px;
  line-height: 1.45;
}

.client-phone-preview--compact .goods-card h4 {
  font-size: 13px;
}

.goods-card p {
  margin: 6px 0 0;
}

.goods-tags {
  display: flex;
  gap: 6px;
  margin-top: 10px;
}

.goods-tags span {
  padding: 2px 6px;
  color: var(--mb-preview-price);
  border: 1px solid color-mix(in srgb, var(--mb-preview-price) 55%, transparent);
  border-radius: 4px;
  font-size: 11px;
}

.goods-cell {
  display: grid;
  grid-template-columns: 42px 1fr auto;
  gap: 8px;
  align-items: center;
  margin-top: 0;
}

.goods-cell strong {
  overflow: hidden;
  color: var(--mb-preview-text-secondary);
  font-size: 13px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.goods-cell em {
  color: var(--mb-preview-text-tertiary);
  font-style: normal;
}

.profile-header-card {
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 14px 14px 18px;
  border: 0;
  border-radius: 0;
  background: linear-gradient(
    180deg,
    color-mix(in srgb, var(--mb-preview-primary) 10%, transparent) 0%,
    var(--mb-preview-bg-secondary) 100%
  );
}

.client-phone-preview--compact .profile-header-card {
  padding: 12px 10px 14px;
}

.profile-avatar {
  display: grid;
  flex: 0 0 auto;
  width: 46px;
  height: 46px;
  place-items: center;
  color: var(--mb-preview-primary);
  border-radius: 50%;
  background: var(--mb-preview-bg-surface);
  font-weight: 800;
}

.profile-header-card__main {
  min-width: 0;
  flex: 1;
}

.profile-header-card strong {
  display: block;
  color: var(--mb-preview-text-title);
  font-size: 15px;
  line-height: 1.3;
}

.profile-header-card small,
.profile-header-card p {
  display: block;
  margin: 4px 0 0;
  color: var(--mb-preview-text-tertiary);
  font-size: 12px;
}

.profile-header-card p {
  color: var(--mb-preview-text-secondary);
}

.profile-points-card strong,
.profile-wallet-card strong {
  display: block;
  margin-top: 5px;
  color: var(--mb-preview-text-title);
  font-size: 26px;
  line-height: 1;
}

.profile-points-card small,
.profile-wallet-card small {
  display: block;
  color: var(--mb-preview-text-secondary);
}

.profile-points-card__meta,
.profile-wallet-card__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  margin: 8px 0 0;
  color: var(--mb-preview-text-tertiary);
  font-size: 11px;
  line-height: 1.4;
}

.profile-points-card__meta i,
.profile-wallet-card__meta i {
  font-style: normal;
}

.profile-points-card__actions,
.profile-wallet-card__actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(72px, 1fr));
  gap: 8px;
  margin-top: 12px;
}

.profile-points-card__actions span,
.profile-wallet-card__actions span {
  padding: 7px;
  overflow: hidden;
  text-align: center;
  border-radius: 999px;
  background: var(--mb-preview-bg-surface);
  font-size: 12px;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-points-card__actions span:last-child,
.profile-wallet-card__actions span:last-child {
  color: white;
  background: var(--mb-preview-primary);
}

.profile-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px 4px;
}

.profile-grid--service {
  grid-template-columns: repeat(
    var(--mb-profile-service-columns, 4),
    minmax(0, 1fr)
  );
  padding-top: 2px;
}

.profile-service-card {
  padding: 14px;
  overflow: hidden;
}

.profile-cell {
  display: grid;
  grid-template-columns: 26px minmax(0, 1fr) auto auto;
  gap: 10px;
  align-items: center;
  padding: 14px 0;
  border-bottom: 1px solid var(--mb-preview-divider);
}

.profile-cell:last-child {
  border-bottom: 0;
}

.profile-entry-icon--cell {
  width: 26px;
  height: 26px;
  margin: 0;
  border-radius: 8px;
  font-size: 11px;
}

.profile-entry-icon--grid {
  width: 32px;
  height: 32px;
  margin-bottom: 6px;
  border-radius: 10px;
  font-size: 13px;
}

.profile-entry-icon--pay {
  --profile-icon-bg: rgb(13 80 213 / 10%);
  --profile-icon-color: #0d50d5;
}

.profile-entry-icon--ship {
  --profile-icon-bg: rgb(0 128 96 / 10%);
  --profile-icon-color: #007a5a;
}

.profile-entry-icon--receive {
  --profile-icon-bg: rgb(86 77 196 / 10%);
  --profile-icon-color: #564dc4;
}

.profile-entry-icon--refund {
  --profile-icon-bg: rgb(204 94 36 / 12%);
  --profile-icon-color: #b8501d;
}

.profile-entry-icon--address {
  --profile-icon-bg: rgb(0 118 196 / 10%);
  --profile-icon-color: #006fb8;
}

.profile-entry-icon--favorite {
  --profile-icon-bg: rgb(213 62 107 / 10%);
  --profile-icon-color: #c42c62;
}

.profile-entry-icon--theme {
  --profile-icon-bg: rgb(122 89 0 / 12%);
  --profile-icon-color: #805d00;
}

.profile-entry-icon--service {
  --profile-icon-bg: rgb(0 132 135 / 10%);
  --profile-icon-color: #007f82;
}

.profile-cell strong {
  min-width: 0;
  overflow: hidden;
  font-size: 13px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-cell__value {
  color: var(--mb-preview-text-tertiary);
  font-size: 12px;
  white-space: nowrap;
}

.profile-cell em {
  color: var(--mb-preview-text-tertiary);
  font-style: normal;
}

.tabbar-page {
  height: 100%;
  background:
    linear-gradient(var(--mb-preview-divider), var(--mb-preview-divider)) 24px
      26px / 42% 6px no-repeat,
    linear-gradient(var(--mb-preview-divider), var(--mb-preview-divider)) 24px
      46px / 66% 6px no-repeat,
    var(--mb-preview-bg-secondary);
  opacity: 0.42;
}

.client-phone-preview__tabbar,
.goods-action-bar {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(0, 1fr);
  gap: 0;
  padding: 8px 10px 7px;
  border-top: 1px solid var(--mb-preview-divider);
  background: var(--mb-preview-bg);
}

.client-phone-preview--compact .client-phone-preview__tabbar {
  padding: 6px 7px 6px;
}

.client-phone-preview__tabbar-item {
  position: relative;
  min-width: 0;
  padding: 0;
  color: var(--mb-preview-text-tertiary);
  cursor: default;
  text-align: center;
  border: 0;
  background: transparent;
  transition:
    color 0.16s ease,
    outline-color 0.16s ease,
    transform 0.16s ease;
}

.client-phone-preview__tabbar-item.active {
  color: var(--mb-preview-primary);
}

.client-phone-preview__tabbar-item.selected {
  color: var(--mb-preview-primary);
  border-radius: 12px;
  background: color-mix(in srgb, var(--mb-preview-primary) 8%, transparent);
  box-shadow:
    inset 0 0 0 2px var(--mb-preview-primary),
    0 4px 10px color-mix(in srgb, var(--mb-preview-primary) 16%, transparent);
}

.tabbar-icon {
  position: relative;
  display: block;
  width: 22px;
  height: 22px;
  margin: 0 auto 3px;
  color: currentColor;
  border: 0;
  border-radius: 0;
}

.tabbar-icon img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.tabbar-icon svg {
  width: 17px;
  height: 17px;
}

.client-phone-preview--compact .tabbar-icon {
  width: 18px;
  height: 18px;
}

.tabbar-icon:not(.tabbar-icon--custom)::before,
.tabbar-icon:not(.tabbar-icon--custom)::after {
  position: absolute;
  display: block;
  content: '';
  box-sizing: border-box;
}

.tabbar-icon--home::before {
  top: 8px;
  left: 4px;
  width: 14px;
  height: 11px;
  border: 1.8px solid currentColor;
  border-top: 0;
  border-radius: 2px 2px 3px 3px;
}

.tabbar-icon--home::after {
  top: 3px;
  left: 4px;
  width: 14px;
  height: 14px;
  border-top: 1.8px solid currentColor;
  border-left: 1.8px solid currentColor;
  transform: rotate(45deg);
  transform-origin: center;
}

.tabbar-icon--category::before {
  inset: 3px;
  background: radial-gradient(circle, currentColor 2px, transparent 2.7px) 0 0 /
    8px 8px;
}

.tabbar-icon--cart::before {
  top: 7px;
  left: 4px;
  width: 14px;
  height: 12px;
  border: 1.8px solid currentColor;
  border-radius: 4px 4px 5px 5px;
}

.tabbar-icon--cart::after {
  top: 3px;
  left: 8px;
  width: 6px;
  height: 7px;
  border: 1.6px solid currentColor;
  border-bottom: 0;
  border-radius: 8px 8px 0 0;
}

.tabbar-icon--order::before {
  top: 3px;
  left: 6px;
  width: 11px;
  height: 16px;
  border: 1.7px solid currentColor;
  border-radius: 2px;
}

.tabbar-icon--order::after {
  top: 8px;
  left: 9px;
  width: 5px;
  height: 7px;
  border-top: 1.5px solid currentColor;
  border-bottom: 1.5px solid currentColor;
}

.tabbar-icon--profile::before {
  top: 3px;
  left: 8px;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
}

.tabbar-icon--profile::after {
  top: 11px;
  left: 5px;
  width: 13px;
  height: 8px;
  border-radius: 8px 8px 4px 4px;
  background: currentColor;
}

.goods-action-bar {
  grid-template-columns: 46px 46px 1fr 1fr;
  grid-auto-flow: initial;
  align-items: center;
}

.goods-action-bar div {
  text-align: center;
}

.goods-action-bar span {
  display: block;
  width: 22px;
  height: 22px;
  margin: 0 auto 2px;
  border: 2px solid var(--mb-preview-text-tertiary);
  border-radius: 7px;
}

.goods-action-bar small {
  color: var(--mb-preview-text-tertiary);
  font-size: 10px;
}

.goods-action-bar button {
  height: 34px;
  color: white;
  border: 0;
  border-radius: 999px;
  background: var(--mb-preview-primary);
  font-size: 12px;
}

.goods-action-bar button:last-child {
  background: var(--mb-preview-price);
}

.client-phone-preview__floating {
  position: absolute;
  z-index: 18;
  display: flex;
  flex-direction: column-reverse;
  gap: 8px;
  align-items: center;
  pointer-events: none;
}

.client-phone-preview__floating--vertical {
  flex-direction: column;
  align-items: stretch;
}

.client-phone-preview__floating--left-bottom {
  align-items: flex-start;
}

.client-phone-preview__floating--right-bottom {
  align-items: flex-end;
}

.floating-preview-button {
  position: relative;
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  padding: 0;
  overflow: hidden;
  border: 0;
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
  line-height: 1;
  pointer-events: auto;
}

.floating-preview-button img {
  width: 58%;
  height: 58%;
  object-fit: contain;
}

.floating-preview-button--text {
  justify-content: flex-start;
  gap: 6px;
  width: auto !important;
  min-width: 92px;
  padding: 0 12px;
  border-radius: 999px !important;
}

.floating-preview-button--text img,
.floating-preview-button--text > span {
  width: 20px;
  height: 20px;
  display: grid;
  flex: 0 0 auto;
  place-items: center;
}

.floating-preview-button small {
  max-width: 56px;
  overflow: hidden;
  font-size: 12px;
  font-weight: 700;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.floating-preview-button--selected {
  outline: 2px solid rgb(13 80 213 / 50%);
  outline-offset: 2px;
}

.preview-drop-placeholder--floating {
  width: 42px;
  height: 8px;
  min-height: 8px;
  margin: 0;
  border-radius: 999px;
}
</style>
