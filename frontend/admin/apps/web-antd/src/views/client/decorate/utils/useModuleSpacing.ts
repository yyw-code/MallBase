export type ModuleItem = Record<string, any>;

export const paddingSideFields = [
  { field: 'paddingTop', label: '上' },
  { field: 'paddingRight', label: '右' },
  { field: 'paddingBottom', label: '下' },
  { field: 'paddingLeft', label: '左' },
] as const;

export type PaddingSideField = (typeof paddingSideFields)[number]['field'];

export const marginSideFields = [
  { field: 'marginTop', label: '上' },
  { field: 'marginRight', label: '右' },
  { field: 'marginBottom', label: '下' },
  { field: 'marginLeft', label: '左' },
] as const;

export type MarginSideField = (typeof marginSideFields)[number]['field'];

type SideAliasMap<T extends string> = Record<T, readonly string[]>;

const paddingAliasMap: SideAliasMap<PaddingSideField> = {
  paddingBottom: ['paddingBottom', 'padding_bottom', 'paddingY', 'padding_y'],
  paddingLeft: ['paddingLeft', 'padding_left', 'paddingX', 'padding_x'],
  paddingRight: ['paddingRight', 'padding_right', 'paddingX', 'padding_x'],
  paddingTop: ['paddingTop', 'padding_top', 'paddingY', 'padding_y'],
};

const paddingSnakeAlias: Record<PaddingSideField, string> = {
  paddingBottom: 'padding_bottom',
  paddingLeft: 'padding_left',
  paddingRight: 'padding_right',
  paddingTop: 'padding_top',
};

const marginAliasMap: SideAliasMap<MarginSideField> = {
  marginBottom: ['marginBottom', 'margin_bottom'],
  marginLeft: ['marginLeft', 'margin_left'],
  marginRight: ['marginRight', 'margin_right'],
  marginTop: ['marginTop', 'margin_top'],
};

const marginSnakeAlias: Record<MarginSideField, string> = {
  marginBottom: 'margin_bottom',
  marginLeft: 'margin_left',
  marginRight: 'margin_right',
  marginTop: 'margin_top',
};

export const clampRpxValue = (value: unknown, max = 160) => {
  const numberValue = Number(value ?? 0);
  if (!Number.isFinite(numberValue)) return 0;
  return Math.max(0, Math.min(Math.round(numberValue), max));
};

const readConfigNumber = (
  config: Record<string, any>,
  keys: readonly string[],
  fallback = 0,
) => {
  for (const key of keys) {
    const value = config[key];
    if (value !== undefined && value !== null && value !== '') {
      return clampRpxValue(value);
    }
  }
  return clampRpxValue(fallback);
};

export const getModulePaddingSide = (
  module: ModuleItem | null,
  field: PaddingSideField,
) => {
  const config = module?.config || {};
  const padding = readConfigNumber(config, ['padding']);
  return readConfigNumber(config, paddingAliasMap[field], padding);
};

export const getModulePaddingOverall = (module: ModuleItem | null) => {
  const sides = paddingSideFields.map((item) =>
    getModulePaddingSide(module, item.field),
  );
  if (sides.every((value) => value === sides[0])) return sides[0] || 0;
  return Math.round(sides.reduce((total, value) => total + value, 0) / 4);
};

export const syncModulePaddingCompat = (config: Record<string, any>) => {
  const padding = readConfigNumber(config, ['padding']);
  const top = readConfigNumber(config, paddingAliasMap.paddingTop, padding);
  const right = readConfigNumber(config, paddingAliasMap.paddingRight, padding);
  const bottom = readConfigNumber(
    config,
    paddingAliasMap.paddingBottom,
    padding,
  );
  const left = readConfigNumber(config, paddingAliasMap.paddingLeft, padding);

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

export const updateModulePaddingAll = (
  module: ModuleItem | null,
  value: unknown,
) => {
  if (!module) return;
  const config = (module.config ||= {});
  const nextValue = clampRpxValue(value);
  config.padding = nextValue;
  config.paddingY = nextValue;
  config.padding_y = nextValue;
  config.paddingX = nextValue;
  config.padding_x = nextValue;
  config.paddingTop = nextValue;
  config.padding_top = nextValue;
  config.paddingRight = nextValue;
  config.padding_right = nextValue;
  config.paddingBottom = nextValue;
  config.padding_bottom = nextValue;
  config.paddingLeft = nextValue;
  config.padding_left = nextValue;
};

export const updateModulePaddingSide = (
  module: ModuleItem | null,
  field: PaddingSideField,
  value: unknown,
) => {
  if (!module) return;
  const config = (module.config ||= {});
  const nextValue = clampRpxValue(value);
  config[field] = nextValue;
  config[paddingSnakeAlias[field]] = nextValue;
  syncModulePaddingCompat(config);
};

export const getModuleMarginSide = (
  module: ModuleItem | null,
  field: MarginSideField,
) => {
  const config = module?.config || {};
  return readConfigNumber(config, marginAliasMap[field]);
};

export const getModuleMarginOverall = (module: ModuleItem | null) => {
  const sides = marginSideFields.map((item) =>
    getModuleMarginSide(module, item.field),
  );
  if (sides.every((value) => value === sides[0])) return sides[0] || 0;
  return Math.round(sides.reduce((total, value) => total + value, 0) / 4);
};

export const updateModuleMarginAll = (
  module: ModuleItem | null,
  value: unknown,
) => {
  if (!module) return;
  const config = (module.config ||= {});
  const nextValue = clampRpxValue(value);
  config.marginTop = nextValue;
  config.margin_top = nextValue;
  config.marginRight = nextValue;
  config.margin_right = nextValue;
  config.marginBottom = nextValue;
  config.margin_bottom = nextValue;
  config.marginLeft = nextValue;
  config.margin_left = nextValue;
};

export const updateModuleMarginSide = (
  module: ModuleItem | null,
  field: MarginSideField,
  value: unknown,
) => {
  if (!module) return;
  const config = (module.config ||= {});
  const nextValue = clampRpxValue(value);
  config[field] = nextValue;
  config[marginSnakeAlias[field]] = nextValue;
};
