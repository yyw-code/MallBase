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
    </view>
    <view class="mb-card__info">
      <text class="mb-card__name">{{ goods.name }}</text>
      <text v-if="goods.subtitle" class="mb-card__sub">{{ goods.subtitle }}</text>
      <view class="mb-card__bottom">
        <mb-price
          :value="goods.price"
          :size="mode === 'grid' ? 'md' : 'md'"
          color="var(--color-price, #ff5a1f)"
        />
        <text v-if="goods.original_price && Number(goods.original_price) > Number(goods.price)" class="mb-card__original">
          ¥{{ Number(goods.original_price).toFixed(0) }}
        </text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  goods: { type: Object, required: true },
  mode: { type: String, default: 'grid' },
})

defineEmits(['tap'])

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

.mb-card__img-wrap--grid {
  position: relative;
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
  align-items: baseline;
  flex-wrap: wrap;
  gap: 12rpx;
  min-width: 0;
  margin-top: 12rpx;
}

.mb-card__original {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
  text-decoration: line-through;
}
</style>
