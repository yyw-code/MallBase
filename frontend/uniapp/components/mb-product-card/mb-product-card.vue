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
      />
    </view>
    <view class="mb-card__info">
      <text class="mb-card__name">{{ goods.name }}</text>
      <text v-if="goods.subtitle" class="mb-card__sub">{{ goods.subtitle }}</text>
      <view class="mb-card__bottom">
        <mb-price :value="goods.price" :size="mode === 'grid' ? 'md' : 'md'" color="var(--color-text-title, #131b2e)" />
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
  if (Array.isArray(props.goods.images) && props.goods.images.length > 0) return props.goods.images[0]
  return ''
})
</script>

<style scoped>
.mb-card {
  background: #ffffff;
  border-radius: 24rpx;
  overflow: hidden;
}

.mb-card--grid {
  display: flex;
  flex-direction: column;
}

.mb-card--list {
  display: flex;
  flex-direction: row;
  padding: 20rpx;
  gap: 20rpx;
}

.mb-card__img-wrap--grid {
  width: 100%;
  aspect-ratio: 1;
  overflow: hidden;
  background: #eef0f3;
}

.mb-card__img-wrap--list {
  width: 200rpx;
  height: 200rpx;
  border-radius: 16rpx;
  overflow: hidden;
  background: #eef0f3;
  flex-shrink: 0;
}

.mb-card__img {
  width: 100%;
  height: 100%;
}

.mb-card__info {
  padding: 20rpx 24rpx 24rpx;
  display: flex;
  flex-direction: column;
  flex: 1;
}

.mb-card--list .mb-card__info {
  padding: 0;
  justify-content: space-between;
}

.mb-card__name {
  font-size: 26rpx;
  font-weight: 500;
  color: var(--color-text, #1b1b1b);
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.45;
}

.mb-card__sub {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #848484);
  margin-top: 4rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mb-card__bottom {
  display: flex;
  align-items: baseline;
  gap: 12rpx;
  margin-top: 12rpx;
}

.mb-card__original {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #848484);
  text-decoration: line-through;
}
</style>
