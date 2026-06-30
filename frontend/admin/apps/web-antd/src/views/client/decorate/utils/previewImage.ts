type FloatingIconSide = 'left' | 'right';

type FloatingIconItem = {
  icon?: unknown;
  id?: string;
  key?: string;
  path?: string;
  text?: string;
  type?: string;
};

const FLOATING_STATIC_ICON_PATH = 'static/client/floating';
const LEGACY_FLOATING_STATIC_ICON_PATH = 'static/images/floating';

const getApiOrigin = () => {
  const apiBase = import.meta.env.VITE_GLOB_API_URL || '';
  if (typeof window === 'undefined') return '';

  try {
    return new URL(apiBase || '/', window.location.origin).origin;
  } catch {
    return window.location.origin;
  }
};

const normalizeFloatingIconFilename = (filename: string) =>
  filename.replace(/\.svg(?:[?#].*)?$/i, '.png');

const resolvePublicStaticUrl = (path: string) => {
  const normalized = path.replace(/^\/+/, '');
  const origin = getApiOrigin();
  return origin ? `${origin}/${normalized}` : `/${normalized}`;
};

const resolveFloatingStaticIcon = (filename: string) =>
  resolvePublicStaticUrl(
    `${FLOATING_STATIC_ICON_PATH}/${normalizeFloatingIconFilename(filename)}`,
  );

const isFloatingSystemIconPath = (value: unknown) => {
  if (typeof value !== 'string') return false;
  const normalized = value.replace(/^\/+/, '');
  return (
    normalized.startsWith(`${FLOATING_STATIC_ICON_PATH}/`) ||
    normalized.startsWith(`${LEGACY_FLOATING_STATIC_ICON_PATH}/`)
  );
};

const normalizePreviewImageUrl = (value: string) => {
  const path = value.trim();
  if (!path || /^\d+$/.test(path)) return '';
  if (/^(?:https?:|data:image|blob:)/.test(path)) return path;
  if (path.startsWith('//')) {
    const protocol =
      typeof window === 'undefined' ? 'https:' : window.location.protocol;
    return `${protocol}${path}`;
  }

  const normalizedStaticPath = path.replace(/^\/+/, '');
  if (
    normalizedStaticPath.startsWith(`${FLOATING_STATIC_ICON_PATH}/`) ||
    normalizedStaticPath.startsWith(`${LEGACY_FLOATING_STATIC_ICON_PATH}/`)
  ) {
    const filename = normalizedStaticPath.split('/').pop();
    return filename ? resolveFloatingStaticIcon(filename) : '';
  }

  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const origin = getApiOrigin();
  return origin ? `${origin}${normalizedPath}` : normalizedPath;
};

export const resolvePreviewImageUrl = (value: unknown): string => {
  if (typeof value === 'string') return normalizePreviewImageUrl(value);
  if (typeof value === 'number') return '';
  if (Array.isArray(value)) {
    for (const item of value) {
      const resolved = resolvePreviewImageUrl(item);
      if (resolved) return resolved;
    }
    return '';
  }
  if (!value || typeof value !== 'object') return '';

  const source = value as Record<string, unknown>;
  const candidates = [
    source.full_url,
    source.fullUrl,
    source.preview_url,
    source.previewUrl,
    source.thumb_url,
    source.thumbUrl,
    source.image_full_url,
    source.imageFullUrl,
    source.file_full_url,
    source.fileFullUrl,
    source.url,
    source.path,
    source.src,
    source.response,
    source.asset,
    source.file,
  ];

  for (const candidate of candidates) {
    const resolved = resolvePreviewImageUrl(candidate);
    if (resolved) return resolved;
  }

  return '';
};

export const getFloatingPresetIconType = (item: FloatingIconItem | null) => {
  if (!item) return '';
  const id = String((item as Record<string, unknown> | null)?.id || '');
  const map: Record<string, string> = {
    'floating-cart': 'cart',
    'floating-home': 'home',
    'floating-service': 'service',
  };
  if (map[id]) return map[id];

  const text = String(item.text || '').trim();
  const path = String(item.path || '')
    .split(/[?#]/)[0]
    ?.replace(/\/+$/, '');
  if (item.type === 'customerService' && text === '客服') return 'service';
  if (item.type === 'page' && text === '购物车' && path === '/pages/cart/index')
    return 'cart';
  if (item.type === 'page' && text === '首页' && path === '/pages/index/index')
    return 'home';
  return '';
};

export const isFloatingPresetIcon = (item: FloatingIconItem | null) => {
  if (!item) return false;
  if (isFloatingSystemIconPath(item.icon)) return true;
  if (resolvePreviewImageUrl(item.icon).includes('/static/client/floating/')) {
    return true;
  }
  return getFloatingPresetIconType(item) !== '';
};

export const resolveFloatingPresetIconUrl = (
  item: FloatingIconItem | null,
) => {
  const type = getFloatingPresetIconType(item);
  return type ? resolveFloatingStaticIcon(`${type}.png`) : '';
};

export const resolveFloatingMainIconUrl = (side: FloatingIconSide) =>
  resolveFloatingStaticIcon(
    side === 'right' ? 'collapse-left.png' : 'collapse-right.png',
  );
