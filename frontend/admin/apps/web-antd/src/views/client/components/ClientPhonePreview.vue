<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { IconifyIcon } from '@vben/icons';

type ModuleItem = Record<string, any>;
type PreviewRecord = Record<string, any>;

type PreviewKind = 'category' | 'goodsDetail' | 'home' | 'profile' | 'tabbar';

const props = withDefaults(
  defineProps<{
    categoryTree?: PreviewRecord[];
    currentPath?: string;
    dragging?: boolean;
    dropIndex?: null | number;
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
let bannerPreviewTimer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
  bannerPreviewTimer = setInterval(() => {
    bannerPreviewTick.value += 1;
  }, 1000);
});

onBeforeUnmount(() => {
  if (bannerPreviewTimer) clearInterval(bannerPreviewTimer);
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

const DEFAULT_PROFILE_MODULES: ModuleItem[] = [
  { id: 'preview-user', props: {}, type: 'userCard' },
  { id: 'preview-wallet', props: {}, type: 'wallet' },
  {
    id: 'preview-orders',
    props: {
      items: [
        { icon: '¥', label: '待付款' },
        { icon: '车', label: '待发货' },
        { icon: '包', label: '待收货' },
        { icon: '↩', label: '售后' },
      ],
      title: '我的订单',
    },
    type: 'orderShortcut',
  },
  {
    id: 'preview-service',
    props: {
      items: [
        { icon: '地', label: '地址管理' },
        { icon: '藏', label: '我的收藏' },
        { icon: '券', label: '领券中心' },
        { icon: '题', label: '主题设置' },
      ],
    },
    type: 'serviceMenu',
  },
];

const DEFAULT_TABBAR_ITEMS: ModuleItem[] = [
  { id: 'preview-tab-home', path: '/pages/index/index', text: '首页' },
  { id: 'preview-tab-category', path: '/pages/category/index', text: '分类' },
  { id: 'preview-tab-cart', path: '/pages/cart/index', text: '购物车' },
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
  if (props.title) return props.title;
  const map: Record<PreviewKind, string> = {
    category: '分类',
    goodsDetail: '商品详情',
    home: '首页',
    profile: '我的',
    tabbar: '底部导航',
  };
  return map[props.kind];
});

const normalizedModules = computed(() => {
  const fallback =
    props.kind === 'profile' ? DEFAULT_PROFILE_MODULES : DEFAULT_HOME_MODULES;
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
  const props = { ...rawProps, ...rawConfig, ...rawData };
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
    profileHeader: 'userCard',
    userInfo: 'userCard',
    walletEntry: 'wallet',
  };
  return alias[type] || type;
}

function moduleList(module: ModuleItem) {
  const list = [
    module.props?.list,
    module.props?.items,
    module.props?.images,
  ].find((value) => Array.isArray(value) && value.length > 0);
  if (Array.isArray(list)) return list;
  return [];
}

function getImage(item: any): string {
  if (typeof item === 'string') return normalizePreviewImageUrl(item);
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

function getEntryIcon(item: any) {
  return item?.icon || item?.selected_icon || '';
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

function entryCardIconStyle(module: ModuleItem) {
  const props = module.props || {};
  return {
    background: props.icon_background || props.iconBackground || undefined,
    color: props.icon_color || props.iconColor || undefined,
  };
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
  return {
    textAlign: titleAlign(module),
  };
}

function titleTextStyle(module: ModuleItem) {
  const props = module.props || {};
  return {
    color: props.title_color || props.titleColor || undefined,
    fontSize: `${toPreviewPx(
      clampNumber(props.title_font_size ?? props.titleFontSize, 32, 18, 72),
    )}px`,
    fontStyle: props.title_italic || props.titleItalic ? 'italic' : undefined,
    fontWeight:
      props.title_bold === false || props.titleBold === false ? '500' : '800',
  };
}

function titleSubStyle(module: ModuleItem) {
  const props = module.props || {};
  return {
    color: props.sub_color || props.subColor || undefined,
    fontSize: `${toPreviewPx(
      clampNumber(props.sub_font_size ?? props.subFontSize, 24, 16, 56),
    )}px`,
    fontStyle: props.sub_italic || props.subItalic ? 'italic' : undefined,
    fontWeight: props.sub_bold || props.subBold ? '700' : undefined,
  };
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

const homePageStyle = computed(() => {
  const pageStyle = props.pageStyle || {};
  const paddingY = pageStyle.paddingY ?? pageStyle.padding_y ?? 0;
  const paddingX = pageStyle.paddingX ?? pageStyle.padding_x ?? 28;
  return {
    padding: `${toPreviewPx(paddingY)}px ${toPreviewPx(paddingX)}px`,
  };
});

function moduleOuterStyle(module: ModuleItem) {
  const props = module.props || {};
  const width = Math.max(50, Math.min(Number(props.widthPercent || 100), 100));
  const style: Record<string, string> = {
    marginBottom: `${toPreviewPx(props.marginBottom)}px`,
    marginTop: `${toPreviewPx(props.marginTop)}px`,
    width: `${width}%`,
  };
  if (width < 100) {
    style.marginLeft = 'auto';
    style.marginRight = 'auto';
  }
  return style;
}

function moduleBoxStyle(
  module: ModuleItem,
  extra: Record<string, string> = {},
) {
  const props = module.props || {};
  const style: Record<string, string> = { boxSizing: 'border-box', ...extra };
  if (props.background) style.background = props.background;
  if (props.radius !== undefined) {
    style.borderRadius = `${toPreviewPx(props.radius)}px`;
  }
  if (props.paddingY !== undefined || props.paddingX !== undefined) {
    const padding = props.padding ?? 0;
    const paddingY = props.paddingY ?? props.padding_y ?? padding;
    const paddingX = props.paddingX ?? props.padding_x ?? padding;
    style.padding = `${toPreviewPx(paddingY)}px ${toPreviewPx(paddingX)}px`;
  } else if (props.padding !== undefined) {
    style.padding = `${toPreviewPx(props.padding)}px`;
  }
  return style;
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

function richTextHtml(module: ModuleItem) {
  return (
    module.props?.content ||
    module.props?.html ||
    '<p><strong>图文内容</strong></p><p>这里展示活动说明、门店公告或售后政策。</p>'
  );
}

function spacingStyle(module: ModuleItem) {
  return {
    height: `${toPreviewPx(module.props?.height, 24)}px`,
  };
}

function dividerStyle(module: ModuleItem) {
  const margin = `${toPreviewPx(module.props?.margin, 12)}px`;
  return {
    ...moduleBoxStyle(module),
    background: module.props?.color || undefined,
    borderTopStyle: module.props?.style || 'solid',
    marginBottom: margin,
    marginTop: margin,
  };
}

function moduleIsHidden(module: ModuleItem) {
  return module.enabled === false || module.visible === false;
}

function getTabbarIcon(item: ModuleItem) {
  const active = item.path === props.currentPath;
  return active
    ? item.selected_icon || item.icon || ''
    : item.icon || item.selected_icon || '';
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
    class="client-phone-preview"
    :class="[`client-phone-preview--${size}`, `client-phone-preview--${kind}`]"
    :style="previewStyle"
  >
    <div class="client-phone-preview__device">
      <div class="client-phone-preview__status">
        <span>9:41</span>
        <span>100%</span>
      </div>
      <div class="client-phone-preview__navbar">
        <span class="client-phone-preview__back">{{
          kind === 'home' ? '' : '‹'
        }}</span>
        <strong>{{ pageTitle }}</strong>
        <span class="client-phone-preview__more">•••</span>
      </div>

      <div class="client-phone-preview__body">
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
                role="button"
                :style="moduleOuterStyle(module)"
                tabindex="0"
                @click="handleSelect(module)"
                @mousedown="
                  handlePreviewMouseDown(Number(module.__previewIndex), $event)
                "
              >
                <span
                  v-if="interactive && selectedModuleId === module.id"
                  class="preview-selected-badge"
                >
                  {{ getModuleTitle(module) }}
                  <span class="preview-selected-actions">
                    <button
                      type="button"
                      title="上移"
                      @click.stop="
                        handlePreviewMove(
                          Number(module.__previewIndex),
                          'up',
                          $event,
                        )
                      "
                      @mousedown.stop
                    >
                      ↑
                    </button>
                    <button
                      type="button"
                      title="下移"
                      @click.stop="
                        handlePreviewMove(
                          Number(module.__previewIndex),
                          'down',
                          $event,
                        )
                      "
                      @mousedown.stop
                    >
                      ↓
                    </button>
                    <button
                      class="danger"
                      type="button"
                      title="删除"
                      @click.stop="
                        handlePreviewDelete(
                          Number(module.__previewIndex),
                          $event,
                        )
                      "
                      @mousedown.stop
                    >
                      ×
                    </button>
                  </span>
                  <i>⋮⋮</i>
                </span>
                <div
                  v-if="module.type === 'search'"
                  class="home-search"
                  :style="moduleBoxStyle(module)"
                >
                  <span class="home-search__icon"></span>
                  <span>{{ module.props.placeholder || '搜索商品' }}</span>
                </div>

                <div
                  v-else-if="module.type === 'banner'"
                  class="home-banner"
                  :style="
                    moduleBoxStyle(module, { height: bannerHeight(module) })
                  "
                >
                  <img v-if="bannerImage(module)" :src="bannerImage(module)" />
                  <div v-else class="home-banner__fallback">
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
                    <span>{{ getLabel(item) }}</span>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'entryCard'"
                  class="home-entry-card"
                  :style="entryCardBoxStyle(module)"
                >
                  <span
                    class="home-entry-card__icon"
                    :style="entryCardIconStyle(module)"
                  >
                    <img
                      v-if="
                        module.props.icon_mode === 'image' &&
                        entryCardIconImage(module)
                      "
                      :src="entryCardIconImage(module)"
                    />
                    <IconifyIcon
                      v-else-if="isIconifyName(module.props.icon)"
                      :icon="module.props.icon"
                    />
                    <template v-else>
                      {{
                        getFallbackIcon({
                          icon: module.props.icon,
                          title: module.props.title,
                        })
                      }}
                    </template>
                  </span>
                  <div>
                    <strong>{{ module.props.title || '入口卡片' }}</strong>
                    <small>{{
                      module.props.subtitle || module.props.path || '点击查看'
                    }}</small>
                  </div>
                  <em v-if="module.props.show_arrow !== false">›</em>
                </div>

                <div
                  v-else-if="module.type === 'imageCube'"
                  class="home-cube"
                  :style="moduleBoxStyle(module)"
                >
                  <div
                    v-for="(item, itemIndex) in (imageList(module).length > 0
                      ? imageList(module)
                      : module.props.titles || [
                          '精选榜单',
                          '本周值得买',
                          '会员专享',
                          '新品榜',
                        ]
                    ).slice(0, 4)"
                    :key="itemIndex"
                    class="home-cube__item"
                  >
                    <img v-if="isImageLike(item)" :src="item" />
                    <strong v-else>{{ item }}</strong>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'productGroup'"
                  class="home-products"
                  :style="moduleBoxStyle(module)"
                >
                  <div class="home-section-head">
                    <strong>{{ module.props.title || '猜你喜欢' }}</strong>
                    <span>{{ module.props.moreText || '查看全部' }}</span>
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
                  :style="spacingStyle(module)"
                ></div>

                <div
                  v-else-if="module.type === 'divider'"
                  class="home-divider"
                  :style="dividerStyle(module)"
                ></div>
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

        <template v-else-if="kind === 'goodsDetail'">
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
          <div class="profile-page">
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
                role="button"
                :style="moduleOuterStyle(module)"
                tabindex="0"
                @click="handleSelect(module)"
                @mousedown="
                  handlePreviewMouseDown(Number(module.__previewIndex), $event)
                "
              >
                <span
                  v-if="interactive && selectedModuleId === module.id"
                  class="preview-selected-badge"
                >
                  {{ getModuleTitle(module) }}
                  <span class="preview-selected-actions">
                    <button
                      type="button"
                      title="上移"
                      @click.stop="
                        handlePreviewMove(
                          Number(module.__previewIndex),
                          'up',
                          $event,
                        )
                      "
                      @mousedown.stop
                    >
                      ↑
                    </button>
                    <button
                      type="button"
                      title="下移"
                      @click.stop="
                        handlePreviewMove(
                          Number(module.__previewIndex),
                          'down',
                          $event,
                        )
                      "
                      @mousedown.stop
                    >
                      ↓
                    </button>
                    <button
                      class="danger"
                      type="button"
                      title="删除"
                      @click.stop="
                        handlePreviewDelete(
                          Number(module.__previewIndex),
                          $event,
                        )
                      "
                      @mousedown.stop
                    >
                      ×
                    </button>
                  </span>
                  <i>⋮⋮</i>
                </span>
                <div
                  v-if="module.type === 'userCard'"
                  class="profile-header-card"
                  :style="moduleBoxStyle(module)"
                >
                  <span class="profile-avatar">M</span>
                  <div>
                    <strong>点击登录</strong>
                    <small>登录后享受更多服务</small>
                    <p v-if="module.props.show_mobile !== false">
                      完善资料后可展示手机号和签名
                    </p>
                    <p v-else>完善资料后可展示个性签名</p>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'wallet'"
                  class="profile-wallet-card"
                  :style="moduleBoxStyle(module)"
                >
                  <small>{{ module.props.title || '我的余额' }}</small>
                  <strong v-if="walletShowBalance(module)">¥0.00</strong>
                  <strong v-else>积分 0</strong>
                  <div>
                    <span v-if="walletShowBalance(module)">余额明细</span>
                    <span v-if="module.props.show_points !== false">
                      积分记录
                    </span>
                    <span>去查看</span>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'orderShortcut'"
                  class="profile-order-card"
                  :style="moduleBoxStyle(module)"
                >
                  <div class="profile-section-head">
                    <strong>{{ module.props.title || '我的订单' }}</strong>
                    <span>查看全部</span>
                  </div>
                  <div class="profile-grid">
                    <div
                      v-for="(item, index) in moduleList(module).slice(0, 4)"
                      :key="index"
                    >
                      <span>
                        <IconifyIcon
                          v-if="isIconifyName(getEntryIcon(item))"
                          :icon="getEntryIcon(item)"
                        />
                        <template v-else>
                          {{ item.icon || getLabel(item).slice(0, 1) }}
                        </template>
                      </span>
                      <small>{{ getLabel(item) }}</small>
                    </div>
                  </div>
                </div>

                <div
                  v-else-if="module.type === 'serviceMenu'"
                  class="profile-service-card"
                  :style="moduleBoxStyle(module)"
                >
                  <div v-if="module.props.title" class="profile-section-head">
                    <strong>{{ module.props.title }}</strong>
                    <span>全部</span>
                  </div>
                  <div
                    v-for="(item, index) in moduleList(module).slice(0, 5)"
                    :key="index"
                    class="profile-cell"
                  >
                    <span>
                      <IconifyIcon
                        v-if="isIconifyName(getEntryIcon(item))"
                        :icon="getEntryIcon(item)"
                      />
                      <template v-else>
                        {{ item.icon || getLabel(item).slice(0, 1) }}
                      </template>
                    </span>
                    <strong>{{ getLabel(item) }}</strong>
                    <em>›</em>
                  </div>
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

      <div v-if="kind !== 'goodsDetail'" class="client-phone-preview__tabbar">
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
            >
              {{ item.text }}
              <span class="preview-selected-actions">
                <button
                  type="button"
                  title="上移"
                  @click.stop="
                    handlePreviewMove(Number(item.__previewIndex), 'up', $event)
                  "
                  @mousedown.stop
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
                  @mousedown.stop
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
                  @mousedown.stop
                >
                  ×
                </button>
              </span>
            </span>
            <span class="tabbar-icon">
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
    </div>
  </div>
</template>

<style scoped>
.client-phone-preview {
  color: var(--mb-preview-text);
  user-select: none;
  -webkit-user-select: none;
}

.client-phone-preview__device {
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
}

.home-page,
.profile-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 14px;
}

.client-phone-preview--compact .home-page,
.client-phone-preview--compact .profile-page {
  gap: 10px;
  padding: 10px;
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
    box-shadow 0.16s ease,
    outline-color 0.16s ease;
}

.preview-module--interactive {
  cursor: pointer;
}

.preview-module--selected {
  outline: 2px solid var(--mb-preview-primary);
  outline-offset: 5px;
  border-radius: 12px;
  box-shadow: 0 0 0 6px
    color-mix(in srgb, var(--mb-preview-primary) 12%, transparent);
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
  z-index: 5;
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
  top: -40px;
  left: 50%;
  white-space: nowrap;
  transform: translateX(-50%);
}

.preview-selected-actions {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.preview-selected-actions button {
  display: grid;
  width: 20px;
  height: 20px;
  padding: 0;
  color: white;
  cursor: pointer;
  border: 0;
  border-radius: 999px;
  background: rgb(255 255 255 / 18%);
  place-items: center;
  font-size: 12px;
  line-height: 1;
}

.preview-selected-actions button:hover {
  background: rgb(255 255 255 / 28%);
}

.preview-selected-actions button.danger {
  color: #fff;
  background: #ff4d4f;
}

.preview-selected-badge i {
  color: rgb(255 255 255 / 78%);
  font-style: normal;
  letter-spacing: -2px;
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
.profile-grid span,
.profile-cell > span {
  display: grid;
  width: 38px;
  height: 38px;
  margin: 0 auto 8px;
  place-items: center;
  color: var(--mb-preview-primary);
  border-radius: 14px;
  background: color-mix(in srgb, var(--mb-preview-primary) 10%, transparent);
  font-weight: 700;
}

.home-nav__icon svg,
.profile-grid span svg,
.profile-cell > span svg {
  width: 18px;
  height: 18px;
}

.home-nav__icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: inherit;
}

.client-phone-preview--compact .home-nav__icon,
.client-phone-preview--compact .profile-grid span {
  width: 30px;
  height: 30px;
  margin-bottom: 5px;
  border-radius: 10px;
  font-size: 11px;
}

.home-cube {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
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
  display: grid;
  min-height: 92px;
  padding: 12px;
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

.client-phone-preview--compact .home-cube__item {
  min-height: 64px;
  padding: 8px;
  font-size: 11px;
}

.home-products,
.home-rich,
.profile-order-card,
.profile-service-card,
.profile-wallet-card {
  padding: 14px;
  border: 1px solid var(--mb-preview-divider);
  border-radius: 12px;
  background: var(--mb-preview-bg);
}

.client-phone-preview--compact .home-products,
.client-phone-preview--compact .home-rich,
.client-phone-preview--compact .profile-order-card,
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

.home-section-head span,
.profile-section-head span {
  font-size: 12px;
  color: var(--mb-preview-text-tertiary);
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

.home-divider {
  height: 1px;
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
  padding: 18px 14px;
  border-radius: 0 0 18px 18px;
  background: linear-gradient(
    180deg,
    color-mix(in srgb, var(--mb-preview-primary) 12%, transparent) 0%,
    var(--mb-preview-bg-secondary) 100%
  );
}

.client-phone-preview--compact .profile-header-card {
  padding: 14px 10px;
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

.profile-wallet-card strong {
  display: block;
  margin-top: 8px;
  color: var(--mb-preview-text-title);
  font-size: 26px;
}

.profile-wallet-card small {
  color: var(--mb-preview-text-secondary);
}

.profile-wallet-card > div {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-top: 14px;
}

.profile-wallet-card > div span {
  padding: 7px;
  text-align: center;
  border-radius: 8px;
  background: var(--mb-preview-bg-surface);
  font-size: 12px;
}

.profile-wallet-card > div span:last-child {
  color: white;
  background: var(--mb-preview-primary);
}

.profile-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}

.profile-service-card {
  padding: 0;
  overflow: hidden;
}

.profile-cell {
  display: grid;
  grid-template-columns: 32px 1fr auto;
  gap: 10px;
  align-items: center;
  padding: 12px 14px;
  border-bottom: 1px solid var(--mb-preview-divider);
}

.profile-cell:last-child {
  border-bottom: 0;
}

.profile-cell > span {
  width: 26px;
  height: 26px;
  margin: 0;
  border-radius: 8px;
  font-size: 11px;
}

.profile-cell strong {
  font-size: 13px;
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
  gap: 4px;
  padding: 8px 10px 10px;
  border-top: 1px solid var(--mb-preview-divider);
  background: var(--mb-preview-bg);
}

.client-phone-preview--compact .client-phone-preview__tabbar {
  padding: 6px 7px 8px;
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
  outline: 2px solid var(--mb-preview-primary);
  outline-offset: 2px;
  border-radius: 10px;
}

.tabbar-icon {
  display: grid;
  width: 21px;
  height: 21px;
  margin: 0 auto 3px;
  border: 2px solid currentColor;
  border-radius: 7px;
  place-items: center;
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
  width: 17px;
  height: 17px;
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
</style>
