import type { GoodsApi } from '#/api/goods';

export type GoodsEditFeatureCode = 'distribution' | 'member' | 'points';
export type GoodsEditFieldType = 'number' | 'select';

export interface GoodsEditFeatureState {
  distribution?: boolean;
  distributionSecondLevel?: boolean;
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
  label?: string;
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
  description?: (context: GoodsEditSlotContext) => string;
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
  suffix?: string;
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
  { label: '规格单独按比例', value: 'sku_ratio' },
  { label: '规格单独固定积分', value: 'sku_fixed' },
];

const memberBenefitModeOptions: GoodsEditSlotOption[] = [
  { label: '默认全局配置', value: 'global' },
  { label: '关闭', value: 'disabled' },
  { label: '参与等级折扣', value: 'level_discount' },
  { label: '规格会员价', value: 'sku_price' },
];

const distributionCommissionModeOptions: GoodsEditSlotOption[] = [
  { label: '使用默认规则', value: 'global' },
  { label: '关闭分佣', value: 'disabled' },
  { label: '按比例', value: 'rate' },
  { label: '固定金额', value: 'fixed' },
  { label: '规格单独比例', value: 'sku_rate' },
  { label: '规格单独固定金额', value: 'sku_fixed' },
];

const isFeatureEnabled = (
  context: GoodsEditSlotContext,
  feature: GoodsEditFeatureCode,
) => context.features?.[feature] !== false;

const isDistributionSecondLevelEnabled = (context: GoodsEditSlotContext) =>
  context.features?.distributionSecondLevel === true;

const modeDescription = (descriptions: Record<string, string>, mode: unknown) =>
  descriptions[String(mode || '')] || '';

const pointsRewardModeDescriptions: Record<string, string> = {
  disabled: '该商品不赠送积分。',
  fixed: '整件商品使用固定赠送积分，按购买数量累计发放。',
  global: '按系统积分规则继续计算。',
  ratio: '整件商品按订单项实付金额换算积分。',
  sku_fixed: '到规格库存中为每个 SKU 填写固定积分，按购买数量累计发放。',
  sku_ratio: '到规格库存中为每个 SKU 填写积分比例。',
};

const distributionCommissionModeDescriptions: Record<string, string> = {
  disabled: '该商品不生成分销佣金。',
  fixed: '整件商品按购买数量发放固定佣金，可能高于订单项实付金额。',
  global: '按全局、分类、等级等默认规则继续计算。',
  rate: '整件商品按订单项实付金额计算佣金比例。',
  sku_fixed: '到规格库存中为每个 SKU 填写固定佣金，按购买数量发放。',
  sku_rate: '到规格库存中为每个 SKU 填写佣金比例。',
};

const isPointsSkuMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'points') &&
  ['sku_fixed', 'sku_ratio'].includes(context.formData.points_reward_mode);

const isPointsSkuRatioMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'points') &&
  context.formData.points_reward_mode === 'sku_ratio';

const isPointsSkuFixedMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'points') &&
  context.formData.points_reward_mode === 'sku_fixed';

const isMemberSkuPriceMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'member') &&
  context.formData.member_benefit_mode === 'sku_price';

const isDistributionSkuMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'distribution') &&
  ['sku_fixed', 'sku_rate'].includes(
    context.formData.distribution_commission_mode,
  );

const isDistributionSkuRateMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'distribution') &&
  context.formData.distribution_commission_mode === 'sku_rate';

const isDistributionSkuFixedMode = (context: GoodsEditSlotContext) =>
  isFeatureEnabled(context, 'distribution') &&
  context.formData.distribution_commission_mode === 'sku_fixed';

const goodsPointsRewardModeForForm = (detail: GoodsApi.GoodsItem) => {
  const mode = detail.points_reward_mode;
  if (mode === 'sku') {
    const skuMode = (detail.skus || []).find((sku) =>
      ['fixed', 'ratio'].includes(String(sku.points_reward_mode || '')),
    )?.points_reward_mode;
    return skuMode === 'fixed' ? 'sku_fixed' : 'sku_ratio';
  }
  return mode && mode !== 'inherit' ? mode : 'global';
};

const goodsDistributionCommissionModeForForm = (detail: GoodsApi.GoodsItem) => {
  const mode = detail.distribution_commission_mode;
  if (mode === 'sku') {
    const skuMode = (detail.skus || []).find((sku) =>
      ['fixed', 'rate'].includes(
        String(sku.distribution_commission_mode || ''),
      ),
    )?.distribution_commission_mode;
    return skuMode === 'fixed' ? 'sku_fixed' : 'sku_rate';
  }
  return mode || 'global';
};

const pointsSlot: GoodsEditExtensionSlot = {
  code: 'points',
  hydrateResponse(formData, detail) {
    formData.points_reward_mode = goodsPointsRewardModeForForm(detail);
    formData.points_reward_ratio = detail.points_reward_ratio ?? 0;
    formData.points_reward_fixed = detail.points_reward_fixed ?? 0;
  },
  marketing: {
    code: 'points',
    description: (context) =>
      modeDescription(
        pointsRewardModeDescriptions,
        context.formData.points_reward_mode,
      ),
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
        controls: false,
        key: 'sku_points_reward_ratio',
        label: '每元积分',
        min: 0,
        model: 'sku_points_reward_ratio',
        precision: 0,
        suffix: '积分/元',
        type: 'number',
        visible: isPointsSkuRatioMode,
        width: '150px',
      },
      {
        controls: false,
        key: 'sku_points_reward_fixed',
        label: '每件积分',
        min: 0,
        model: 'sku_points_reward_fixed',
        precision: 0,
        suffix: '积分/件',
        type: 'number',
        visible: isPointsSkuFixedMode,
        width: '150px',
      },
    ],
    label: '规格积分',
    visible: isPointsSkuMode,
  },
  skuFields: [
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
      suffix: '积分/元',
      submit(payload, source, context) {
        if (!isPointsSkuRatioMode(context)) return;
        payload.points_reward_mode = 'ratio';
        payload.points_reward_ratio = source.points_reward_ratio || 0;
        payload.points_reward_fixed = 0;
      },
      title: '每元积分',
      type: 'number',
      visible: isPointsSkuRatioMode,
      width: 130,
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
      suffix: '积分/件',
      submit(payload, source, context) {
        if (!isPointsSkuFixedMode(context)) return;
        payload.points_reward_mode = 'fixed';
        payload.points_reward_ratio = 0;
        payload.points_reward_fixed = source.points_reward_fixed || 0;
      },
      title: '每件积分',
      type: 'number',
      visible: isPointsSkuFixedMode,
      width: 130,
    },
  ],
  transformSubmit(submitData) {
    if (['sku_fixed', 'sku_ratio'].includes(submitData.points_reward_mode)) {
      submitData.points_reward_mode = 'sku';
    }
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
        suffix: '元',
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
      suffix: '元',
      submit(payload, source) {
        payload.member_price = source.member_price ?? null;
      },
      title: '会员价(元)',
      type: 'number',
      visible: isMemberSkuPriceMode,
      width: 120,
    },
  ],
  transformSubmit(submitData) {
    delete submitData.member_price;
  },
};

const distributionSlot: GoodsEditExtensionSlot = {
  code: 'distribution',
  hydrateResponse(formData, detail) {
    formData.distribution_commission_mode =
      goodsDistributionCommissionModeForForm(detail);
    formData.distribution_first_rate = Number(
      detail.distribution_first_rate ?? 0,
    );
    formData.distribution_second_rate = Number(
      detail.distribution_second_rate ?? 0,
    );
    formData.distribution_first_fixed_amount = Number(
      detail.distribution_first_fixed_amount ?? 0,
    );
    formData.distribution_second_fixed_amount = Number(
      detail.distribution_second_fixed_amount ?? 0,
    );
  },
  marketing: {
    code: 'distribution',
    description: (context) =>
      modeDescription(
        distributionCommissionModeDescriptions,
        context.formData.distribution_commission_mode,
      ),
    disabledDescription: '分销功能未开启',
    feature: 'distribution',
    fields: [
      {
        key: 'distribution_commission_mode',
        model: 'distribution_commission_mode',
        options: distributionCommissionModeOptions,
        type: 'select',
        width: '180px',
      },
      {
        controls: false,
        key: 'distribution_first_rate',
        label: '一级佣金',
        min: 0,
        model: 'distribution_first_rate',
        precision: 2,
        suffix: '%',
        type: 'number',
        visible: (context) =>
          context.formData.distribution_commission_mode === 'rate',
        width: '120px',
      },
      {
        controls: false,
        key: 'distribution_second_rate',
        label: '二级佣金',
        min: 0,
        model: 'distribution_second_rate',
        precision: 2,
        suffix: '%',
        type: 'number',
        visible: (context) =>
          context.formData.distribution_commission_mode === 'rate' &&
          isDistributionSecondLevelEnabled(context),
        width: '120px',
      },
      {
        controls: false,
        key: 'distribution_first_fixed_amount',
        label: '一级佣金',
        min: 0,
        model: 'distribution_first_fixed_amount',
        precision: 2,
        suffix: '元/件',
        type: 'number',
        visible: (context) =>
          context.formData.distribution_commission_mode === 'fixed',
        width: '150px',
      },
      {
        controls: false,
        key: 'distribution_second_fixed_amount',
        label: '二级佣金',
        min: 0,
        model: 'distribution_second_fixed_amount',
        precision: 2,
        suffix: '元/件',
        type: 'number',
        visible: (context) =>
          context.formData.distribution_commission_mode === 'fixed' &&
          isDistributionSecondLevelEnabled(context),
        width: '150px',
      },
    ],
    label: '佣金规则',
    title: '分销',
  },
  singleSku: {
    code: 'distribution.single_sku_commission',
    fields: [
      {
        controls: false,
        key: 'sku_distribution_first_rate',
        label: '一级佣金',
        min: 0,
        model: 'sku_distribution_first_rate',
        precision: 2,
        suffix: '%',
        type: 'number',
        visible: isDistributionSkuRateMode,
        width: '120px',
      },
      {
        controls: false,
        key: 'sku_distribution_second_rate',
        label: '二级佣金',
        min: 0,
        model: 'sku_distribution_second_rate',
        precision: 2,
        suffix: '%',
        type: 'number',
        visible: (context) =>
          isDistributionSkuRateMode(context) &&
          isDistributionSecondLevelEnabled(context),
        width: '120px',
      },
      {
        controls: false,
        key: 'sku_distribution_first_fixed_amount',
        label: '一级佣金',
        min: 0,
        model: 'sku_distribution_first_fixed_amount',
        precision: 2,
        suffix: '元/件',
        type: 'number',
        visible: isDistributionSkuFixedMode,
        width: '150px',
      },
      {
        controls: false,
        key: 'sku_distribution_second_fixed_amount',
        label: '二级佣金',
        min: 0,
        model: 'sku_distribution_second_fixed_amount',
        precision: 2,
        suffix: '元/件',
        type: 'number',
        visible: (context) =>
          isDistributionSkuFixedMode(context) &&
          isDistributionSecondLevelEnabled(context),
        width: '150px',
      },
    ],
    label: '规格佣金',
    visible: isDistributionSkuMode,
  },
  skuFields: [
    {
      batchKey: '__distribution_first_rate__',
      defaultValue: 0,
      hydrate(row, sku) {
        row.distribution_first_rate = Number(sku.distribution_first_rate ?? 0);
      },
      key: 'distribution_first_rate',
      min: 0,
      placeholder: '一级比例',
      precision: 2,
      suffix: '%',
      submit(payload, source, context) {
        if (!isDistributionSkuRateMode(context)) return;
        payload.distribution_commission_mode = 'rate';
        payload.distribution_first_rate = source.distribution_first_rate || 0;
        payload.distribution_second_rate = isDistributionSecondLevelEnabled(
          context,
        )
          ? source.distribution_second_rate || 0
          : 0;
        payload.distribution_first_fixed_amount = 0;
        payload.distribution_second_fixed_amount = 0;
      },
      title: '一级佣金(%)',
      type: 'number',
      visible: isDistributionSkuRateMode,
      width: 108,
    },
    {
      batchKey: '__distribution_second_rate__',
      defaultValue: 0,
      hydrate(row, sku) {
        row.distribution_second_rate = Number(
          sku.distribution_second_rate ?? 0,
        );
      },
      key: 'distribution_second_rate',
      min: 0,
      placeholder: '二级比例',
      precision: 2,
      suffix: '%',
      submit(payload, source, context) {
        if (!isDistributionSkuRateMode(context)) return;
        payload.distribution_second_rate = source.distribution_second_rate || 0;
      },
      title: '二级佣金(%)',
      type: 'number',
      visible: (context) =>
        isDistributionSkuRateMode(context) &&
        isDistributionSecondLevelEnabled(context),
      width: 108,
    },
    {
      batchKey: '__distribution_first_fixed_amount__',
      defaultValue: 0,
      hydrate(row, sku) {
        row.distribution_first_fixed_amount = Number(
          sku.distribution_first_fixed_amount ?? 0,
        );
      },
      key: 'distribution_first_fixed_amount',
      min: 0,
      placeholder: '一级佣金',
      precision: 2,
      suffix: '元/件',
      submit(payload, source, context) {
        if (!isDistributionSkuFixedMode(context)) return;
        payload.distribution_commission_mode = 'fixed';
        payload.distribution_first_rate = 0;
        payload.distribution_second_rate = 0;
        payload.distribution_first_fixed_amount =
          source.distribution_first_fixed_amount || 0;
        payload.distribution_second_fixed_amount =
          isDistributionSecondLevelEnabled(context)
            ? source.distribution_second_fixed_amount || 0
            : 0;
      },
      title: '一级佣金(元/件)',
      type: 'number',
      visible: isDistributionSkuFixedMode,
      width: 132,
    },
    {
      batchKey: '__distribution_second_fixed_amount__',
      defaultValue: 0,
      hydrate(row, sku) {
        row.distribution_second_fixed_amount = Number(
          sku.distribution_second_fixed_amount ?? 0,
        );
      },
      key: 'distribution_second_fixed_amount',
      min: 0,
      placeholder: '二级佣金',
      precision: 2,
      suffix: '元/件',
      submit(payload, source, context) {
        if (!isDistributionSkuFixedMode(context)) return;
        payload.distribution_second_fixed_amount =
          source.distribution_second_fixed_amount || 0;
      },
      title: '二级佣金(元/件)',
      type: 'number',
      visible: (context) =>
        isDistributionSkuFixedMode(context) &&
        isDistributionSecondLevelEnabled(context),
      width: 132,
    },
  ],
  transformSubmit(submitData, context) {
    if (!isFeatureEnabled(context, 'distribution')) {
      delete submitData.distribution_commission_mode;
      delete submitData.distribution_first_rate;
      delete submitData.distribution_second_rate;
      delete submitData.distribution_first_fixed_amount;
      delete submitData.distribution_second_fixed_amount;
      delete submitData.sku_distribution_commission_mode;
      delete submitData.sku_distribution_first_rate;
      delete submitData.sku_distribution_second_rate;
      delete submitData.sku_distribution_first_fixed_amount;
      delete submitData.sku_distribution_second_fixed_amount;
      return;
    }
    if (!isDistributionSecondLevelEnabled(context)) {
      submitData.distribution_second_rate = 0;
      submitData.distribution_second_fixed_amount = 0;
    }
    delete submitData.sku_distribution_commission_mode;
    delete submitData.sku_distribution_first_rate;
    delete submitData.sku_distribution_second_rate;
    delete submitData.sku_distribution_first_fixed_amount;
    delete submitData.sku_distribution_second_fixed_amount;
  },
};

export const goodsEditExtensionSlots: GoodsEditExtensionSlot[] = [
  distributionSlot,
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
      (slot): slot is GoodsEditSingleSkuSlot => !!slot && slot.visible(context),
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

export function cloneGoodsEditSkuRowExtensionFields(
  source: Record<string, any>,
) {
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
  formData.sku_distribution_commission_mode =
    sku.distribution_commission_mode || 'inherit';
  formData.sku_distribution_first_rate = Number(
    sku.distribution_first_rate ?? 0,
  );
  formData.sku_distribution_second_rate = Number(
    sku.distribution_second_rate ?? 0,
  );
  formData.sku_distribution_first_fixed_amount = Number(
    sku.distribution_first_fixed_amount ?? 0,
  );
  formData.sku_distribution_second_fixed_amount = Number(
    sku.distribution_second_fixed_amount ?? 0,
  );
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
  context: GoodsEditSlotContext,
) {
  getGoodsEditSkuFields().forEach((field) => {
    if (!field.visible(context)) return;
    const value = batchData[field.batchKey];
    if (value === undefined || value === null || value === '') return;
    row[field.key] = field.type === 'number' ? Number(value) : value;
  });
}
