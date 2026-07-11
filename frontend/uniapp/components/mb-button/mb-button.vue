<template>
  <view
    class="mb-btn"
    :class="[
      `mb-btn--${type}`,
      `mb-btn--${size}`,
      block ? 'mb-btn--block' : '',
      disabled ? 'is-disabled' : '',
      loading ? 'is-loading' : '',
    ]"
    :hover-class="disabled || loading ? '' : 'mb-btn--active'"
    hover-stay-time="80"
    @tap="onTap"
  >
    <view v-if="loading" class="mb-btn__spinner" />
    <text v-if="icon && !loading" class="mb-btn__icon">{{ icon }}</text>
    <text class="mb-btn__label"><slot>{{ label }}</slot></text>
  </view>
</template>

<script setup>
const props = defineProps({
  type: { type: String, default: 'primary' }, // primary | secondary | ghost | danger
  size: { type: String, default: 'large' },   // large | medium | small
  block: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  icon: { type: String, default: '' },
  label: { type: String, default: '' },
})

const emit = defineEmits(['click'])

function onTap(e) {
  if (props.disabled || props.loading) return
  emit('click', e)
}
</script>

<style lang="scss" scoped>
@import '@/uni.scss';

.mb-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: $mb-radius-full;
  font-weight: 600;
  line-height: 1;
  transition: opacity 120ms ease, transform 120ms ease;
  box-sizing: border-box;
  white-space: nowrap;

  &--block {
    display: flex;
    width: 100%;
  }

  &--active {
    opacity: 0.9;
    transform: scale(0.98);
  }

  &.is-disabled {
    opacity: 0.45;
  }

  &.is-loading {
    opacity: 0.75;
  }

  /* Sizes */
  &--large {
    height: $mb-btn-height-lg;
    padding: 0 $mb-btn-padding-x-lg;
    font-size: $mb-btn-font-lg;
  }
  &--medium {
    height: $mb-btn-height-md;
    padding: 0 $mb-btn-padding-x-md;
    font-size: $mb-btn-font-md;
  }
  &--small {
    height: $mb-btn-height-sm;
    padding: 0 $mb-btn-padding-x-sm;
    font-size: $mb-btn-font-sm;
  }

  /* Types */
  &--primary {
    background: var(--color-primary, #0d50d5);
    color: var(--color-text-inverse, #ffffff);
  }
  &--secondary {
    background: var(--color-bg, #ffffff);
    color: var(--color-primary, #0d50d5);
    border: 1rpx solid var(--color-primary, #0d50d5);
  }
  &--ghost {
    background: transparent;
    color: var(--color-primary, #0d50d5);
  }
  &--danger {
    background: var(--color-error, #ba1a1a);
    color: var(--color-text-inverse, #ffffff);
  }
}

.mb-btn__icon {
  margin-right: 8rpx;
}

.mb-btn__spinner {
  width: 28rpx;
  height: 28rpx;
  border-radius: 50%;
  border: 3rpx solid rgba(255, 255, 255, 0.4);
  border-top-color: currentColor;
  margin-right: 12rpx;
  animation: mb-btn-spin 720ms linear infinite;
}

@keyframes mb-btn-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
