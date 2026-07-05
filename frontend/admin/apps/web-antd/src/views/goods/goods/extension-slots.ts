import type { GoodsApi } from '#/api/goods';

export type GoodsEditFeatureCode = 'distribution' | 'member' | 'points';
export type GoodsEditFieldType = 'number' | 'select';

export interface GoodsEditFeatureState {
  distribution?: boolean;
  member: boolean;
  points: boolean;
}

export interface GoodsEditSlotContext {
  features?: GoodsEditFeatureState;
  formData: Record<string, any>;
  specType?: 'multi' | 'single';
}

export interface GoodsEditSlotOption {
  label: string;
  value: number | string;
}

export interface GoodsEditSlotField {
  controls?: boolean;
  key: string;
  min?: number;
  model: string;
  options?: GoodsEditSlotOption[];
  placeholder?: string;
  precision?: number;
  prefix?: string;
  suffix?: string;
  type: GoodsEditFieldType;
  visible?: (context: GoodsEditSlotContext) => boolean;
  width?: string;
}

export interface GoodsEditMarketingSlot {
  code: GoodsEditFeatureCode;
  disabledDescription: string;
  feature: GoodsEditFeatureCode;
  fields: GoodsEditSlotField[];
  label: string;
  title: string;
}

export interface GoodsEditSingleSkuSlot {
  code: string;
  fields: GoodsEditSlotField[];
  label: string;
  visible: (context: GoodsEditSlotContext) => boolean;
}

export interface GoodsEditSkuField {
  batchKey: string;
  defaultValue: unknown;
  hydrate: (row: Record<string, any>, sku: Record<string, any>) => void;
  key: string;
  min?: number;
  options?: GoodsEditSlotOption[];
  placeholder?: string;
  precision?: number;
  prefix?: string;
  submit: (
    payload: Record<string, any>,
    source: Record<string, any>,
    context: GoodsEditSlotContext,
  ) => void;
  title: string;
  type: GoodsEditFieldType;
  visible: (context: GoodsEditSlotContext) => boolean;
  width: number;
}

export interface GoodsEditExtensionSlot {
  code: GoodsEditFeatureCode;
  hydrateResponse?: (
    formData: Record<string, any>,
    detail: GoodsApi.GoodsItem,
  ) => void;
  marketing?: GoodsEditMarketingSlot;
  singleSku?: GoodsEditSingleSkuSlot;
  skuFields?: GoodsEditSkuField[];
  transformSubmit?: (
    submitData: Record<string, any>,
    context: GoodsEditSlotContext,
  ) => void;
}

const pointsRewardModeOptions: GoodsEditSlotOption[] = [
  { label: '关闭', value: 'disabled' },
  { label: '默认全局配置', value: 'global' },
  { label: '按金额比例', value: 'ratio' },
  { label: '固定积分', value: 'fixed' },
  { label: '规格单独配置', value: 'sku' },
];

const skuPointsRewardModeOptions: GoodsEditSlotOption[] = [
  { label: '使用全局规则', value: 'inherit' },
  { label: '不赠送积分', value: 'disabled' },
  { label: '按金额比例', value: 'ratio' },
  { label: '固定积分', value: 'fixed' },
];

const memberBenefitModeOptions: GoodsEditSlotOption[] = [
  { label: '默认全局配置', value: 'global' },
  { label: '关闭', value: 'disabled' },
  { label: '参与等级折扣', value: 'level_discount' },
  { label: '规格会员价', value: 'sku_price' },
];

const isFeatureEnabled = (
  context: GoodsEditSlotContext,
  feature: GoodsEditFeatureCode,
) => context.features?.[feature] !== false;

const isPointsSkuMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'points') &&
  context.formData.points_reward_mode === 'sku';

const isMemberSkuPriceMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'member') &&
  context.formData.member_benefit_mode === 'sku_price';

const pointsSlot: GoodsEditExtensionSlot = {
  code: 'points',
  hydrateResponse(formData, detail) {
    formData.points_reward_mode =
      detail.points_reward_mode && detail.points_reward_mode !== 'inherit'
        ? detail.points_reward_mode
        : 'global';
    formData.points_reward_ratio = detail.points_reward_ratio ?? 0;
    formData.points_reward_fixed = detail.points_reward_fixed ?? 0;
  },
  marketing: {
    code: 'points',
    disabledDescription: '积分功能未开启',
    feature: 'points',
    fields: [
      {
        key: 'points_reward_mode',
        model: 'points_reward_mode',
        options: pointsRewardModeOptions,
        type: 'select',
        width: '180px',
      },
      {
        controls: false,
        key: 'points_reward_ratio',
        min: 0,
        model: 'points_reward_ratio',
        precision: 0,
        suffix: '积分/元',
        type: 'number',
        visible: (context) => context.formData.points_reward_mode === 'ratio',
        width: '150px',
      },
      {
        controls: false,
        key: 'points_reward_fixed',
        min: 0,
        model: 'points_reward_fixed',
        precision: 0,
        suffix: '积分/件',
        type: 'number',
        visible: (context) => context.formData.points_reward_mode === 'fixed',
        width: '150px',
      },
    ],
    label: '赠送积分',
    title: '积分',
  },
  singleSku: {
    code: 'points.single_sku_reward',
    fields: [
      {
        key: 'sku_points_reward_mode',
        model: 'sku_points_reward_mode',
        options: skuPointsRewardModeOptions,
        type: 'select',
        width: '180px',
      },
      {
        controls: false,
        key: 'sku_points_reward_ratio',
        min: 0,
        model: 'sku_points_reward_ratio',
        precision: 0,
        suffix: '积分/元',
        type: 'number',
        visible: (context) =>
          context.formData.sku_points_reward_mode === 'ratio',
        width: '150px',
      },
      {
        controls: false,
        key: 'sku_points_reward_fixed',
        min: 0,
        model: 'sku_points_reward_fixed',
        precision: 0,
        suffix: '积分/件',
        type: 'number',
        visible: (context) =>
          context.formData.sku_points_reward_mode === 'fixed',
        width: '150px',
      },
    ],
    label: '规格积分',
    visible: isPointsSkuMode,
  },
  skuFields: [
    {
      batchKey: '__points_reward_mode__',
      defaultValue: 'inherit',
      hydrate(row, sku) {
        row.points_reward_mode = sku.points_reward_mode || 'inherit';
      },
      key: 'points_reward_mode',
      options: skuPointsRewardModeOptions,
      placeholder: '积分模式',
      submit(payload, source) {
        payload.points_reward_mode = source.points_reward_mode || 'inherit';
      },
      title: '积分模式',
      type: 'select',
      visible: isPointsSkuMode,
      width: 120,
    },
    {
      batchKey: '__points_reward_ratio__',
      defaultValue: 0,
      hydrate(row, sku) {
        row.points_reward_ratio = sku.points_reward_ratio ?? 0;
      },
      key: 'points_reward_ratio',
      min: 0,
      placeholder: '每元积分',
      precision: 0,
      submit(payload, source) {
        payload.points_reward_ratio = source.points_reward_ratio || 0;
      },
      title: '每元积分',
      type: 'number',
      visible: isPointsSkuMode,
      width: 100,
    },
    {
      batchKey: '__points_reward_fixed__',
      defaultValue: 0,
      hydrate(row, sku) {
        row.points_reward_fixed = sku.points_reward_fixed ?? 0;
      },
      key: 'points_reward_fixed',
      min: 0,
      placeholder: '每件积分',
      precision: 0,
      submit(payload, source) {
        payload.points_reward_fixed = source.points_reward_fixed || 0;
      },
      title: '每件积分',
      type: 'number',
      visible: isPointsSkuMode,
      width: 100,
    },
  ],
  transformSubmit(submitData) {
    delete submitData.sku_points_reward_mode;
    delete submitData.sku_points_reward_ratio;
    delete submitData.sku_points_reward_fixed;
  },
};

const memberSlot: GoodsEditExtensionSlot = {
  code: 'member',
  hydrateResponse(formData, detail) {
    formData.member_benefit_mode = detail.member_benefit_mode || 'global';
  },
  marketing: {
    code: 'member',
    disabledDescription: '会员功能未开启',
    feature: 'member',
    fields: [
      {
        key: 'member_benefit_mode',
        model: 'member_benefit_mode',
        options: memberBenefitModeOptions,
        type: 'select',
        width: '180px',
      },
    ],
    label: '会员权益',
    title: '会员',
  },
  singleSku: {
    code: 'member.single_sku_price',
    fields: [
      {
        controls: false,
        key: 'member_price',
        min: 0,
        model: 'member_price',
        precision: 2,
        prefix: '¥',
        type: 'number',
        width: '160px',
      },
    ],
    label: '规格会员价',
    visible: isMemberSkuPriceMode,
  },
  skuFields: [
    {
      batchKey: '__member_price__',
      defaultValue: undefined,
      hydrate(row, sku) {
        row.member_price =
          sku.member_price === null || sku.member_price === undefined
            ? undefined
            : Number(sku.member_price);
      },
      key: 'member_price',
      min: 0,
      placeholder: '会员价',
      precision: 2,
      prefix: '¥',
      submit(payload, source) {
        payload.member_price = source.member_price ?? null;
      },
      title: '会员价',
      type: 'number',
      visible: isMemberSkuPriceMode,
      width: 110,
    },
  ],
  transformSubmit(submitData) {
    delete submitData.member_price;
  },
};

export const goodsEditExtensionSlots: GoodsEditExtensionSlot[] = [
  pointsSlot,
  memberSlot,
];

export function getGoodsEditMarketingSlots() {
  return goodsEditExtensionSlots
    .map((slot) => slot.marketing)
    .filter((slot): slot is GoodsEditMarketingSlot => !!slot);
}

export function getGoodsEditSingleSkuSlots(context: GoodsEditSlotContext) {
  return goodsEditExtensionSlots
    .map((slot) => slot.singleSku)
    .filter(
      (slot): slot is GoodsEditSingleSkuSlot =>
        !!slot && slot.visible(context),
    );
}

export function getGoodsEditSkuFields() {
  return goodsEditExtensionSlots.flatMap((slot) => slot.skuFields || []);
}

export function getGoodsEditSkuFieldMap() {
  return new Map(getGoodsEditSkuFields().map((field) => [field.key, field]));
}

export function getGoodsEditSkuColumns() {
  return getGoodsEditSkuFields().map((field) => ({
    dataIndex: field.key,
    title: field.title,
    width: field.width,
  }));
}

export function isGoodsEditSlotFeatureEnabled(
  slot: GoodsEditMarketingSlot,
  context: GoodsEditSlotContext,
) {
  return isFeatureEnabled(context, slot.feature);
}

export function isGoodsEditSlotFieldVisible(
  field: GoodsEditSlotField,
  context: GoodsEditSlotContext,
) {
  return field.visible ? field.visible(context) : true;
}

export function isGoodsEditSkuFieldVisible(
  field: GoodsEditSkuField,
  context: GoodsEditSlotContext,
) {
  return field.visible(context);
}

export function createGoodsEditSkuRowExtensionDefaults() {
  return Object.fromEntries(
    getGoodsEditSkuFields().map((field) => [field.key, field.defaultValue]),
  );
}

export function createGoodsEditBatchSkuRowExtensionDefaults() {
  return Object.fromEntries(
    getGoodsEditSkuFields().map((field) => [field.key, undefined]),
  );
}

export function cloneGoodsEditSkuRowExtensionFields(source: Record<string, any>) {
  return Object.fromEntries(
    getGoodsEditSkuFields().map((field) => [field.key, source[field.key]]),
  );
}

export function hydrateGoodsEditResponse(
  formData: Record<string, any>,
  detail: GoodsApi.GoodsItem,
) {
  goodsEditExtensionSlots.forEach((slot) => {
    slot.hydrateResponse?.(formData, detail);
  });
}

export function hydrateGoodsEditSkuRow(
  row: Record<string, any>,
  sku: Record<string, any>,
) {
  getGoodsEditSkuFields().forEach((field) => {
    field.hydrate(row, sku);
  });
}

export function hydrateGoodsEditSingleSkuForm(
  formData: Record<string, any>,
  sku?: Record<string, any>,
) {
  if (!sku) return;
  formData.member_price =
    sku.member_price === null || sku.member_price === undefined
      ? undefined
      : Number(sku.member_price);
  formData.sku_points_reward_mode = sku.points_reward_mode || 'inherit';
  formData.sku_points_reward_ratio = sku.points_reward_ratio ?? 0;
  formData.sku_points_reward_fixed = sku.points_reward_fixed ?? 0;
}

export function transformGoodsEditSubmitData(
  submitData: Record<string, any>,
  context: GoodsEditSlotContext,
) {
  goodsEditExtensionSlots.forEach((slot) => {
    slot.transformSubmit?.(submitData, context);
  });
  return submitData;
}

export function transformGoodsEditSkuPayload(
  payload: Record<string, any>,
  source: Record<string, any>,
  context: GoodsEditSlotContext,
) {
  getGoodsEditSkuFields().forEach((field) => {
    field.submit(payload, source, context);
  });
  return payload;
}

export function applyGoodsEditSkuBatch(
  row: Record<string, any>,
  batchData: Record<string, any>,
) {
  getGoodsEditSkuFields().forEach((field) => {
    const value = batchData[field.batchKey];
    if (value === undefined || value === null || value === '') return;
    row[field.key] = field.type === 'number' ? Number(value) : value;
  });
}
