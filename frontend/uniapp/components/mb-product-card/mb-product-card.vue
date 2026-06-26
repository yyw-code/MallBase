<template>
  <view
    class="mb-card"
    :class="[`mb-card--${mode}`]"
    @tap="$emit('tap', goods)"
  >
    <view class="mb-card__img-wrap" :class="[`mb-card__img-wrap--${mode}`]">
      <image
        class="mb-card__img"
        :src="cover"
        :mode="mode === 'grid' ? 'aspectFill' : 'aspectFill'"
        lazy-load
        @error="onImageError"
      />
      <view
        v-if="showBadge && badgeText"
        class="mb-card__badge"
        :style="badgeStyle"
      >
        <text class="mb-card__badge-text" :style="badgeTextStyle">
          {{ badgeText }}
        </text>
      </view>
    </view>
    <view class="mb-card__info">
      <text class="mb-card__name">{{ goods.name }}</text>
      <text v-if="showSubtitle && subtitleText" class="mb-card__sub">
        {{ subtitleText }}
      </text>
      <view class="mb-card__bottom">
        <view class="mb-card__price-main">
          <mb-price
            :value="goods.price"
            :size="mode === 'grid' ? 'md' : 'md'"
            color="var(--color-price, #ff5a1f)"
          />
          <text
            v-if="showMarketPrice && marketPrice > Number(goods.price)"
            class="mb-card__original"
          >
            ¥{{ marketPrice.toFixed(0) }}
          </text>
        </view>
        <view
          v-if="showCartButton"
          class="mb-card__add"
          :class="{ 'mb-card__add--loading': adding }"
          @tap.stop="quickAddCart"
        >
          <text class="mb-card__add-symbol">+</text>
        </view>
      </view>
      <text v-if="showSales" class="mb-card__sales">{{ salesText }}</text>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { getGoodsDetail } from '@/api/goods/goods'
import { useAppStore } from '@/store/app'
import { useCartStore } from '@/store/cart'
import { requireLogin } from '@/utils/auth'
import {
  getGoodsBadgeBoxStyle,
  getGoodsBadgeText,
  getGoodsBadgeTextStyle,
  normalizeGoodsBadgeConfig,
} from '@/utils/goods-badge'

const props = defineProps({
  goods: { type: Object, required: true },
  loginRedirect: { type: String, default: '/pages/index/index' },
  mode: { type: String, default: 'grid' },
  showBadge: { type: [Boolean, Number, String], default: undefined },
  showCartButton: { type: [Boolean, Number, String], default: undefined },
  showMarketPrice: { type: [Boolean, Number, String], default: undefined },
  showSales: { type: [Boolean, Number, String], default: undefined },
  showSubtitle: { type: [Boolean, Number, String], default: undefined },
})

defineEmits(['tap'])

const appStore = useAppStore()
const cartStore = useCartStore()
const adding = ref(false)

function isTruthyFlag(value) {
  return value === true || value === 1 || value === '1' || value === 'true'
}

function configFlag(propValue, code, fallback = true) {
  if (propValue !== undefined && propValue !== null && propValue !== '') {
    return isTruthyFlag(propValue)
  }
  const value = appStore.siteConfig?.[code]
  if (value === undefined || value === null || value === '') return fallback
  return isTruthyFlag(value)
}

const cover = computed(() => {
  if (props.goods.cover) return props.goods.cover
  if (props.goods.main_image_full_url) return props.goods.main_image_full_url
  if (props.goods.main_image) return props.goods.main_image
  if (Array.isArray(props.goods.images) && props.goods.images.length > 0) {
    const first = props.goods.images[0]
    if (typeof first === 'string') return first
    return first.full_url || first.url || ''
  }
  return ''
})

const showSubtitle = computed(() =>
  configFlag(props.showSubtitle, 'client_goods_card_show_subtitle'),
)
const showMarketPrice = computed(() =>
  configFlag(props.showMarketPrice, 'client_goods_card_show_market_price'),
)
const showSales = computed(() =>
  configFlag(props.showSales, 'client_goods_card_show_sales'),
)
const showBadge = computed(() =>
  configFlag(props.showBadge, 'client_goods_card_show_badge'),
)
const showCartButton = computed(() =>
  configFlag(props.showCartButton, 'client_goods_card_show_cart_button'),
)

const goodsBadgeConfig = computed(() =>
  normalizeGoodsBadgeConfig(appStore.siteConfig?.client_goods_badge_config),
)

const badgeStyle = computed(() => {
  return getGoodsBadgeBoxStyle(goodsBadgeConfig.value)
})

const badgeTextStyle = computed(() => {
  return getGoodsBadgeTextStyle(goodsBadgeConfig.value)
})

const marketPrice = computed(() =>
  Number(props.goods.market_price || props.goods.original_price || 0),
)

const subtitleText = computed(() =>
  props.goods.subtitle ||
  props.goods.description ||
  props.goods.category_name ||
  '',
)

const badgeText = computed(() => {
  return getGoodsBadgeText(props.goods, goodsBadgeConfig.value)
})

const salesText = computed(() => {
  const sales = Number(
    props.goods.sales || props.goods.sales_count || props.goods.virtual_sales || 0,
  )
  if (sales >= 10000) return `月销 ${(sales / 10000).toFixed(1)}万+`
  if (sales > 0) return `月销 ${sales}+`
  return '月销 200+'
})

function resolveGoodsId() {
  return props.goods.id || props.goods.goods_id || ''
}

function getDirectSkuId(goods) {
  if (goods.sku_id) return goods.sku_id
  if (goods.default_sku_id) return goods.default_sku_id
  if (Array.isArray(goods.skus) && goods.skus.length === 1) return goods.skus[0].id
  return ''
}

function goDetail() {
  const id = resolveGoodsId()
  if (!id) return
  uni.navigateTo({ url: `/pages-sub/goods/detail?id=${id}` })
}

async function quickAddCart() {
  if (!showCartButton.value || adding.value) return
  if (!requireLogin(props.loginRedirect)) return

  const goodsId = resolveGoodsId()
  adding.value = true
  try {
    let skuId = getDirectSkuId(props.goods)

    if (!skuId && goodsId) {
      const detail = await getGoodsDetail(goodsId)
      const goods = detail?.data ?? detail ?? {}
      const skus = Array.isArray(goods.skus) ? goods.skus : []
      if (skus.length === 1) {
        skuId = skus[0].id
      }
    }

    if (!skuId) {
      uni.showToast({ title: '请选择规格', icon: 'none' })
      setTimeout(goDetail, 500)
      return
    }

    await cartStore.add(skuId, 1)
    uni.showToast({ title: '已加入购物车', icon: 'success' })
  } catch {
    uni.showToast({ title: '加入失败，请重试', icon: 'none' })
  } finally {
    adding.value = false
  }
}

function onImageError(error) {
  if (import.meta.env.DEV) {
    console.warn('[mb-product-card:image-error]', {
      cover: cover.value,
      error,
    })
  }
}
</script>

<style scoped>
.mb-card {
  width: 100%;
  box-sizing: border-box;
  background: var(--color-bg, #ffffff);
  border-radius: var(--radius-lg, 20rpx);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  overflow: hidden;
}

.mb-card--grid {
  display: flex;
  flex-direction: column;
}

.mb-card--list {
  display: flex;
  flex-direction: row;
  align-items: stretch;
  padding: 20rpx;
  gap: 20rpx;
}

.mb-card__img-wrap {
  position: relative;
}

.mb-card__img-wrap--grid {
  width: 100%;
  height: 0;
  padding-bottom: 100%;
  overflow: hidden;
  background: var(--color-bg-secondary, #faf8ff);
}

.mb-card__img-wrap--list {
  width: 200rpx;
  height: 200rpx;
  border-radius: var(--radius-md, 12rpx);
  overflow: hidden;
  background: var(--color-bg-secondary, #faf8ff);
  flex-shrink: 0;
}

.mb-card__img {
  width: 100%;
  height: 100%;
}

.mb-card__img-wrap--grid .mb-card__img {
  position: absolute;
  top: 0;
  left: 0;
}

.mb-card__badge {
  position: absolute;
  top: 16rpx;
  left: 16rpx;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary, #0d50d5);
}

.mb-card__badge-text {
  font-size: 20rpx;
  line-height: 1;
  font-weight: 600;
  color: var(--color-text-on-primary, #ffffff);
}

.mb-card__info {
  padding: 20rpx 24rpx 24rpx;
  display: flex;
  flex-direction: column;
  flex: 1;
  min-width: 0;
  overflow: hidden;
}

.mb-card--list .mb-card__info {
  padding: 0;
  justify-content: space-between;
}

.mb-card__name {
  max-width: 100%;
  font-size: 26rpx;
  font-weight: 600;
  color: var(--color-text, #191b23);
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.45;
}

.mb-card__sub {
  max-width: 100%;
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
  margin-top: 4rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mb-card__bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12rpx;
  min-width: 0;
  margin-top: 12rpx;
}

.mb-card__price-main {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 12rpx;
  min-width: 0;
}

.mb-card__original {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
  text-decoration: line-through;
}

.mb-card__add {
  width: 32rpx;
  height: 32rpx;
  border-radius: 999rpx;
  background: var(--color-primary, #0d50d5);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.mb-card__add--loading {
  opacity: 0.45;
}

.mb-card__add-symbol {
  margin-top: -2rpx;
  font-size: 26rpx;
  font-weight: 600;
  line-height: 1;
  color: var(--color-text-inverse, #ffffff);
}

.mb-card__sales {
  margin-top: 10rpx;
  font-size: 22rpx;
  line-height: 1.3;
  color: var(--color-text-tertiary, #737686);
}
</style>
