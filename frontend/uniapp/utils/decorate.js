import {
  DEFAULT_DECORATE_CONFIG,
  DEFAULT_TABBAR_ITEMS,
} from '@/config/decorate';

const TABBAR_PAGES = DEFAULT_TABBAR_ITEMS.map((item) =>
  normalizePath(item.pagePath),
);

const TABBAR_DEFAULT_KEYWORDS = [
  {
    key: "home",
    match: (item) =>
      item.pagePath.includes("/pages/index/") || item.text.includes("首页"),
  },
  {
    key: "category",
    match: (item) =>
      item.pagePath.includes("/pages/category/") || item.text.includes("分类"),
  },
  {
    key: "cart",
    match: (item) =>
      item.pagePath.includes("/pages/cart/") || item.text.includes("购物车"),
  },
  {
    key: "order",
    match: (item) =>
      item.pagePath.includes("/pages/order/") || item.text.includes("订单"),
  },
  {
    key: "profile",
    match: (item) =>
      item.pagePath.includes("/pages/profile/") || item.text.includes("我的"),
  },
];

function getDefaultTabbarKey(item, index) {
  const matched = TABBAR_DEFAULT_KEYWORDS.find((option) => option.match(item));
  if (matched) return matched.key;
  const fallback = DEFAULT_TABBAR_ITEMS[index % DEFAULT_TABBAR_ITEMS.length];
  return fallback?.key || "home";
}

function getDefaultTabbarAsset(item, index, active = false) {
  const key = getDefaultTabbarKey(item, index);
  return `/static/images/tabbar/${key}${active ? "-active" : ""}.png`;
}

export function mergeDecorateConfig(config) {
  const source = isPlainObject(config) ? config : {};
  const sourceTheme = isPlainObject(source.theme) ? source.theme : {};
  const sourceThemes =
    Array.isArray(sourceTheme.themes) || isPlainObject(sourceTheme.themes)
      ? sourceTheme.themes
      : DEFAULT_DECORATE_CONFIG.theme.themes;
  return {
    home: normalizeSchema(source.home, DEFAULT_DECORATE_CONFIG.home),
    profile: normalizeSchema(source.profile, DEFAULT_DECORATE_CONFIG.profile),
    tabbar: normalizeTabbar(source.tabbar),
    theme: {
      ...DEFAULT_DECORATE_CONFIG.theme,
      ...sourceTheme,
      policy: {
        ...DEFAULT_DECORATE_CONFIG.theme.policy,
        ...(isPlainObject(sourceTheme.policy) ? sourceTheme.policy : {}),
      },
      themes: sourceThemes,
    },
  };
}

export function normalizeSchema(schema, fallback) {
  if (Array.isArray(schema)) {
    return {
      ...fallback,
      modules: normalizeModules(schema),
    };
  }
  if (!isPlainObject(schema)) return fallback;
  return {
    ...fallback,
    ...schema,
    pageStyle: normalizePageStyle(
      schema.pageStyle || fallback.pageStyle,
      fallback.pageStyle,
    ),
    modules: normalizeModules(
      schema.modules ||
        schema.components ||
        schema.schema?.modules ||
        schema.schema?.components ||
        schema.items ||
        fallback.modules,
    ),
  };
}

export function normalizePageStyle(style, fallback = {}) {
  const source = isPlainObject(style) ? style : {};
  const paddingX = Number(
    source.paddingX ??
      source.padding_x ??
      fallback.paddingX ??
      fallback.padding_x ??
      28,
  );
  const paddingY = Number(
    source.paddingY ??
      source.padding_y ??
      fallback.paddingY ??
      fallback.padding_y ??
      0,
  );
  const paddingTop = Number(
    source.paddingTop ??
      source.padding_top ??
      source.paddingY ??
      source.padding_y ??
      fallback.paddingTop ??
      fallback.padding_top ??
      fallback.paddingY ??
      fallback.padding_y ??
      0,
  );
  const paddingRight = Number(
    source.paddingRight ??
      source.padding_right ??
      source.paddingX ??
      source.padding_x ??
      fallback.paddingRight ??
      fallback.padding_right ??
      fallback.paddingX ??
      fallback.padding_x ??
      28,
  );
  const paddingBottom = Number(
    source.paddingBottom ??
      source.padding_bottom ??
      source.paddingY ??
      source.padding_y ??
      fallback.paddingBottom ??
      fallback.padding_bottom ??
      fallback.paddingY ??
      fallback.padding_y ??
      0,
  );
  const paddingLeft = Number(
    source.paddingLeft ??
      source.padding_left ??
      source.paddingX ??
      source.padding_x ??
      fallback.paddingLeft ??
      fallback.padding_left ??
      fallback.paddingX ??
      fallback.padding_x ??
      28,
  );
  return {
    backgroundColorEnd:
      source.backgroundColorEnd ??
      source.background_color_end ??
      fallback.backgroundColorEnd ??
      fallback.background_color_end ??
      "",
    backgroundColorStart:
      source.backgroundColorStart ??
      source.background_color_start ??
      fallback.backgroundColorStart ??
      fallback.background_color_start ??
      "",
    backgroundGradientDirection:
      source.backgroundGradientDirection ??
      source.background_gradient_direction ??
      fallback.backgroundGradientDirection ??
      fallback.background_gradient_direction ??
      "horizontal",
    backgroundMode:
      source.backgroundMode ??
      source.background_mode ??
      fallback.backgroundMode ??
      fallback.background_mode ??
      "color",
    background_image:
      source.background_image ??
      source.backgroundImage ??
      fallback.background_image ??
      fallback.backgroundImage ??
      "",
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

export function normalizeModules(modules) {
  if (!Array.isArray(modules)) return [];
  return modules
    .filter((item) => item && item.visible !== false && item.enabled !== false)
    .map((item, index) => ({
      ...item,
      id:
        item.id ||
        item.key ||
        `${item.type || item.component || 'module'}-${index}`,
      type: normalizeModuleType(item.type || item.component || item.name),
      sort: Number(item.sort ?? item.order ?? index),
      props: {
        ...(isPlainObject(item.props) ? item.props : {}),
        ...(isPlainObject(item.config) ? item.config : {}),
        ...(isPlainObject(item.data) ? item.data : {}),
      },
    }))
    .filter((item) => item.type);
}

export function normalizeTabbar(tabbar) {
  const source = isPlainObject(tabbar)
    ? tabbar
    : DEFAULT_DECORATE_CONFIG.tabbar;
  const schema = isPlainObject(source.schema) ? source.schema : {};
  const rawItems = Array.isArray(schema.items) ? schema.items : source.items;
  const items = normalizeTabbarItems(rawItems);
  return {
    mode: 'custom',
    schema: {
      ...schema,
      items,
    },
  };
}

export function normalizeTabbarItems(items) {
  const list =
    Array.isArray(items) && items.length >= 2 ? items : DEFAULT_TABBAR_ITEMS;
  const normalized = list
    .filter((item) => item && item.visible !== false && item.enabled !== false)
    .slice(0, 5)
    .map((item, index) => {
      const baseItem = {
        key: item.key || item.name || `tab-${index}`,
        text: item.text || item.label || item.title || "",
        pagePath: normalizePath(item.pagePath || item.path || item.url || ""),
      };
      const iconPath = normalizeTabbarAsset(
        [
          item.icon,
          item.icon_full_url,
          item.iconFullUrl,
          item.iconPath,
          item.icon_path,
        ],
        getDefaultTabbarAsset(baseItem, index),
      );
      return {
        ...baseItem,
        iconPath,
        selectedIconPath: normalizeTabbarAsset(
          [
            item.selected_icon,
            item.selectedIcon,
            item.selected_icon_full_url,
            item.selectedIconFullUrl,
            item.selectedIconPath,
            item.selected_icon_path,
            item.activeIcon,
            item.active_icon,
            item.icon,
            item.iconPath,
          ],
          getDefaultTabbarAsset(baseItem, index, true) || iconPath,
        ),
      };
    })
    .filter((item) => item.text && item.pagePath);

  return normalized.length >= 2 ? normalized : DEFAULT_TABBAR_ITEMS;
}

export function normalizePath(path) {
  if (!path) return '';
  return path.startsWith('/') ? path : `/${path}`;
}

function normalizeTabbarAsset(values, fallback = "") {
  for (const value of values) {
    const normalized = normalizeAssetPath(value);
    if (normalized) return normalized;
  }
  return normalizeAssetPath(fallback);
}

function getAssetRawValue(path) {
  if (path && typeof path === "object") {
    return (
      path.full_url ||
      path.fullUrl ||
      path.url ||
      path.path ||
      path.src ||
      path.response?.full_url ||
      path.response?.fullUrl ||
      path.response?.url ||
      ""
    );
  }
  return path;
}

export function normalizeAssetPath(path) {
  path = getAssetRawValue(path);
  if (!path) return "";
  path = String(path).trim();
  if (!path || /^\d+$/.test(path)) return "";
  if (/^(?:https?:)?\/\//.test(path) || path.startsWith("data:image/")) {
    return path;
  }
  if (path.includes(":")) return "";
  if (/^(?:\/)?(?:static|upload)\//.test(path)) {
    return path.startsWith("/") ? path : `/${path}`;
  }
  if (/^\/.+\.(?:avif|gif|jpe?g|png|svg|webp)(?:[?#].*)?$/i.test(path)) {
    return path;
  }
  if (/^.+\.(?:avif|gif|jpe?g|png|svg|webp)(?:[?#].*)?$/i.test(path)) {
    return `/${path.replace(/^\/+/, "")}`;
  }
  return "";
}

export function isTabbarPage(path) {
  return TABBAR_PAGES.includes(normalizePath(path));
}

export function openDecorateLink(target) {
  const url =
    typeof target === 'string'
      ? target
      : target?.link ||
        target?.link_url ||
        target?.linkUrl ||
        target?.url ||
        target?.path ||
        target?.pagePath ||
        '';
  const normalized = normalizePath(url);
  if (!normalized) return;
  const cleanPath = normalized.split('?')[0];
  if (isTabbarPage(cleanPath)) {
    uni.switchTab({ url: cleanPath });
    return;
  }
  uni.navigateTo({ url: normalized });
}

export function buildGoodsParams(props = {}, page = 1) {
  let source = props.source || 'filter';
  let sourceFilters = {};
  if (isPlainObject(source)) {
    sourceFilters = isPlainObject(source.filters) ? source.filters : {};
    source = source.mode || 'filter';
  }
  const params = {
    page,
    limit: Number(props.limit || props.page_size || 10),
  };

  if (source) params.source = source;
  if (props.sort_by || props.sortBy)
    params.sort_by = props.sort_by || props.sortBy;

  if (source === 'manual' && props.ids) {
    params.ids = Array.isArray(props.ids) ? props.ids.join(',') : props.ids;
  }
  if (source === 'category' && (props.category_id || props.categoryId)) {
    params.category_id = props.category_id || props.categoryId;
  }
  if (source === 'brand' && (props.brand_id || props.brandId)) {
    params.brand_id = props.brand_id || props.brandId;
  }
  if (source === 'tag') {
    if (props.tag_id || props.tagId)
      params.tag_id = props.tag_id || props.tagId;
    if (props.tag_ids || props.tagIds) {
      params.tag_ids = Array.isArray(props.tag_ids || props.tagIds)
        ? (props.tag_ids || props.tagIds).join(',')
        : props.tag_ids || props.tagIds;
    }
  }
  if (source === 'recommend') params.is_recommend = 1;
  if (source === 'new') params.is_new = 1;
  if (source === 'hot') params.is_hot = 1;

  const filters = {
    ...sourceFilters,
    ...(isPlainObject(props.filters) ? props.filters : {}),
    ...(isPlainObject(props.params) ? props.params : {}),
    ...(isPlainObject(props.query) ? props.query : {}),
  };
  if (isPlainObject(filters)) Object.assign(params, filters);

  return params;
}

function isPlainObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value);
}

function normalizeModuleType(type) {
  const aliases = {
    categoryEntry: 'entryCard',
    customMenu: 'serviceMenu',
    orderEntry: 'orderShortcut',
    profileHeader: 'userCard',
    userInfo: 'userCard',
    walletCard: 'wallet',
    walletEntry: 'wallet',
  };
  return aliases[type] || type;
}
