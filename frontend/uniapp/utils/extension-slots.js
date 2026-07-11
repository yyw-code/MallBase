import { isPositivePrice, normalizePrice } from './price'

const goodsDetailBenefitSlots = []
const orderConfirmItemBenefitSlots = []

export function registerGoodsDetailBenefitSlot(slot) {
  if (!slot?.key || typeof slot.build !== 'function') return
  goodsDetailBenefitSlots.push(slot)
  goodsDetailBenefitSlots.sort((a, b) => (a.order || 100) - (b.order || 100))
}

export function registerOrderConfirmItemBenefitSlot(slot) {
  if (!slot?.key || typeof slot.build !== 'function') return
  orderConfirmItemBenefitSlots.push(slot)
  orderConfirmItemBenefitSlots.sort((a, b) => (a.order || 100) - (b.order || 100))
}

export function isExtensionFeatureEnabled(value, fallback = true) {
  if (value === undefined || value === null || value === '') return fallback
  return ['1', 'true', 'on'].includes(String(value).toLowerCase())
}

export function formatExtensionAmount(value) {
  const num = Number(value)
  if (Number.isNaN(num)) return '0'
  const int = Math.floor(num).toLocaleString('zh-CN')
  const dec = num.toFixed(2).split('.')[1]
  return dec === '00' ? int : `${int}.${dec}`
}

function resolveFeatureState({ features, siteConfig } = {}) {
  return {
    member:
      features?.member ??
      isExtensionFeatureEnabled(siteConfig?.member_enabled, false),
    points:
      features?.points ??
      isExtensionFeatureEnabled(siteConfig?.points_enabled, true),
  }
}

function rewardRatioText(value) {
  const ratio = Number(value || 0)
  return ratio > 0 ? `每消费 1 元赠 ${ratio} 积分` : ''
}

function rewardFixedText(value) {
  const fixed = Number(value || 0)
  return fixed > 0 ? `每件赠 ${fixed} 积分` : ''
}

function selectedMemberPrice(goods, sku, features) {
  if (!features.member || goods?.member_benefit_mode !== 'sku_price') return ''
  const price = Number(sku?.price ?? goods?.price ?? 0)
  const memberPrice = Number(sku?.member_price ?? 0)
  if (!memberPrice || memberPrice >= price) return ''
  return sku?.member_price
}

function memberDiscountText(goods, sku, features) {
  if (!features.member || selectedMemberPrice(goods, sku, features) !== '') return ''
  const mode = goods?.member_benefit_mode || 'global'
  if (!['global', 'level_discount'].includes(mode)) return ''
  return '下单按会员等级优惠'
}

function pointsRewardText(goods, sku, features) {
  const previewText = sku?.points_reward_preview_text || goods?.points_reward_preview_text
  if (previewText) return previewText
  if (!features.points || !goods) return ''

  const mode = goods.points_reward_mode || 'global'
  if (mode === 'disabled') return ''
  if (mode === 'ratio') return rewardRatioText(goods.points_reward_ratio)
  if (mode === 'fixed') return rewardFixedText(goods.points_reward_fixed)

  if (mode === 'sku') {
    const skuMode = sku?.points_reward_mode || 'inherit'
    if (skuMode === 'disabled') return ''
    if (skuMode === 'ratio') return rewardRatioText(sku?.points_reward_ratio)
    if (skuMode === 'fixed') return rewardFixedText(sku?.points_reward_fixed)
  }

  return '按全局规则赠送'
}

registerGoodsDetailBenefitSlot({
  key: 'member_price',
  order: 100,
  build({ goods, sku, features }) {
    const price = selectedMemberPrice(goods, sku, features)
    if (price === '') return null
    return {
      key: 'member_price',
      label: '会员价',
      value: `¥${formatExtensionAmount(price)}`,
      tone: 'member',
    }
  },
})

registerGoodsDetailBenefitSlot({
  key: 'member_discount',
  order: 110,
  build({ goods, sku, features }) {
    const text = memberDiscountText(goods, sku, features)
    if (!text) return null
    return {
      key: 'member_discount',
      label: '会员优惠',
      value: text,
      tone: 'member',
    }
  },
})

registerGoodsDetailBenefitSlot({
  key: 'points_reward',
  order: 200,
  build({ goods, sku, features }) {
    const text = pointsRewardText(goods, sku, features)
    if (!text) return null
    return {
      key: 'points_reward',
      label: '积分',
      value: text,
      tone: 'points',
    }
  },
})

registerGoodsDetailBenefitSlot({
  key: 'member_growth',
  order: 300,
  build({ goods, sku, features }) {
    if (!features.member) return null
    const text = sku?.member_growth_preview_text || goods?.member_growth_preview_text || ''
    if (!text) return null
    return {
      key: 'member_growth',
      label: '成长值',
      value: text,
      tone: 'growth',
    }
  },
})

export function buildGoodsDetailBenefitItems({ goods, sku, siteConfig, features } = {}) {
  const context = {
    features: resolveFeatureState({ features, siteConfig }),
    goods: goods || {},
    sku: sku || {},
  }
  return goodsDetailBenefitSlots
    .map((slot) => slot.build(context))
    .filter((item) => item && item.value !== '')
}

registerOrderConfirmItemBenefitSlot({
  key: 'member_discount',
  order: 100,
  build(item) {
    const amount = normalizePrice(item?.member_discount_amount || '0.00')
    if (!isPositivePrice(amount)) return null
    return {
      key: 'member_discount',
      text: `会员优惠 -¥${amount}`,
      tone: 'member',
    }
  },
})

registerOrderConfirmItemBenefitSlot({
  key: 'points_reward',
  order: 200,
  build(item) {
    const points = Number(item?.points_reward_points || 0)
    if (points <= 0) return null
    return {
      key: 'points_reward',
      text: `预计赠送 ${points} 积分`,
      tone: 'points',
    }
  },
})

export function buildOrderConfirmItemBenefitLines(item) {
  return orderConfirmItemBenefitSlots
    .map((slot) => slot.build(item))
    .filter((line) => line && line.text)
}

export function buildOrderConfirmExtensionState({
  pointsFeatureEnabled = false,
  previewResult = null,
  usePoints = false,
} = {}) {
  const pointsDeduction = pointsFeatureEnabled
    ? previewResult?.points_deduction || null
    : null
  const pointsReward = pointsFeatureEnabled
    ? previewResult?.points_reward || null
    : null
  const memberDiscount = previewResult?.member_discount || null

  const canUsePoints =
    !!pointsDeduction &&
    pointsDeduction.enabled !== false &&
    Number(pointsDeduction.usable_points || 0) > 0
  const shouldUsePoints = pointsFeatureEnabled && usePoints && canUsePoints
  const pointsDiscountAmount = normalizePrice(pointsDeduction?.discount_amount || '0.00')
  const hasPointsDiscount = shouldUsePoints && isPositivePrice(pointsDiscountAmount)
  const memberDiscountAmount = normalizePrice(memberDiscount?.discount_amount || '0.00')
  const hasMemberDiscount = isPositivePrice(memberDiscountAmount)
  const rewardPoints = Number(pointsReward?.reward_points || 0)
  const hasPointsReward =
    !!pointsReward && pointsReward.enabled !== false && rewardPoints > 0
  const freezeDays = Number(pointsReward?.freeze_days || 0)
  const rewardMetaText =
    freezeDays > 0
      ? `订单完成后冻结 ${freezeDays} 天，售后期结束后发放`
      : '订单完成后发放'

  const cards = []
  if (pointsDeduction) {
    cards.push({
      amountText: hasPointsDiscount ? `-¥${pointsDiscountAmount}` : '',
      checked: usePoints,
      disabled: !canUsePoints,
      key: 'points_deduction',
      metaText: `可用 ${pointsDeduction.available_points || 0}，本单最多可用 ${pointsDeduction.usable_points || 0}`,
      switchable: true,
      title: '积分抵扣',
    })
  }
  if (hasPointsReward) {
    cards.push({
      amountText: `+${rewardPoints} 积分`,
      className: 'reward-card',
      key: 'points_reward',
      metaText: rewardMetaText,
      switchable: false,
      title: '积分赠送',
    })
  }

  const summaryRows = []
  if (hasPointsDiscount) {
    summaryRows.push({
      amountText: `-¥${pointsDiscountAmount}`,
      key: 'points_discount',
      label: '积分抵扣',
    })
  }
  if (hasMemberDiscount) {
    summaryRows.push({
      amountText: `-¥${memberDiscountAmount}`,
      key: 'member_discount',
      label: '会员优惠',
    })
  }

  return {
    canUsePoints,
    cards,
    hasMemberDiscount,
    hasPointsDiscount,
    hasPointsReward,
    memberDiscount,
    memberDiscountAmount,
    pointsDeduction,
    pointsDiscountAmount,
    pointsReward,
    rewardMetaText,
    rewardPoints,
    shouldUsePoints,
    summaryRows,
  }
}
