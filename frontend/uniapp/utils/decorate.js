import {
  DEFAULT_DECORATE_CONFIG,
  DEFAULT_TABBAR_ITEMS,
} from '@/config/decorate';

const TABBAR_PAGES = DEFAULT_TABBAR_ITEMS.map((item) =>
  normalizePath(item.pagePath),
);

export function mergeDecorateConfig(config) {
  const source = isPlainObject(config) ? config : {};
  return {
    home: normalizeSchema(source.home, DEFAULT_DECORATE_CONFIG.home),
    profile: normalizeSchema(source.profile, DEFAULT_DECORATE_CONFIG.profile),
    tabbar: normalizeTabbar(source.tabbar),
    theme: {
      ...DEFAULT_DECORATE_CONFIG.theme,
      ...(isPlainObject(source.theme) ? source.theme : {}),
      policy: {
        ...DEFAULT_DECORATE_CONFIG.theme.policy,
        ...(isPlainObject(source.theme?.policy) ? source.theme.policy : {}),
      },
      themes: {
        ...DEFAULT_DECORATE_CONFIG.theme.themes,
        ...(isPlainObject(source.theme?.themes) ? source.theme.themes : {}),
      },
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
    pageStyle: normalizePageStyle(schema.pageStyle || fallback.pageStyle),
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

export function normalizePageStyle(style) {
  const source = isPlainObject(style) ? style : {};
  return {
    paddingX: Number(source.paddingX ?? source.padding_x ?? 28),
    paddingY: Number(source.paddingY ?? source.padding_y ?? 0),
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
    mode: source.mode === 'custom' ? 'custom' : 'native',
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
    .map((item, index) => ({
      key: item.key || item.name || `tab-${index}`,
      text: item.text || item.label || item.title || '',
      pagePath: normalizePath(item.pagePath || item.path || item.url || ''),
      iconPath: normalizeAssetPath(item.iconPath || item.icon || ''),
      selectedIconPath: normalizeAssetPath(
        item.selectedIconPath ||
          item.activeIcon ||
          item.active_icon ||
          item.iconPath ||
          '',
      ),
    }))
    .filter((item) => item.text && item.pagePath);

  return normalized.length >= 2 ? normalized : DEFAULT_TABBAR_ITEMS;
}

export function normalizePath(path) {
  if (!path) return '';
  return path.startsWith('/') ? path : `/${path}`;
}

export function normalizeAssetPath(path) {
  if (path && typeof path === 'object') {
    path = path.full_url || path.fullUrl || path.url || '';
  }
  if (!path) return '';
  path = String(path);
  if (/^https?:\/\//.test(path)) return path;
  if (path.includes(':')) return '';
  return path.startsWith('/') ? path : `/${path}`;
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
