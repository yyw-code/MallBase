<template>
  <view class="mb-stepper">
    <view
      class="mb-stepper__btn"
      :class="{ 'mb-stepper__btn--disabled': modelValue <= min }"
      @tap="decrease"
    >
      <text class="mb-stepper__icon">−</text>
    </view>
    <input
      class="mb-stepper__input"
      type="number"
      :value="modelValue"
      @blur="onBlur"
    />
    <view
      class="mb-stepper__btn"
      :class="{ 'mb-stepper__btn--disabled': modelValue >= max }"
      @tap="increase"
    >
      <text class="mb-stepper__icon">+</text>
    </view>
  </view>
</template>

<script setup>
const props = defineProps({
  modelValue: { type: Number, default: 1 },
  min: { type: Number, default: 1 },
  max: { type: Number, default: 999 },
})

const emit = defineEmits(['update:modelValue', 'change'])

function clamp(val) {
  return Math.min(Math.max(Math.round(val), props.min), props.max)
}

function decrease() {
  if (props.modelValue <= props.min) return
  const next = clamp(props.modelValue - 1)
  emit('update:modelValue', next)
  emit('change', next)
}

function increase() {
  if (props.modelValue >= props.max) return
  const next = clamp(props.modelValue + 1)
  emit('update:modelValue', next)
  emit('change', next)
}

function onBlur(e) {
  const raw = parseInt(e.detail.value, 10)
  const next = clamp(Number.isNaN(raw) ? props.min : raw)
  emit('update:modelValue', next)
  emit('change', next)
}
</script>

<style scoped>
.mb-stepper {
  display: inline-flex;
  align-items: center;
  height: 56rpx;
  border-radius: 28rpx;
  background: var(--color-bg-secondary, #f7f9fb);
  overflow: hidden;
}

.mb-stepper__btn {
  width: 56rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

.mb-stepper__btn--disabled {
  opacity: 0.3;
  pointer-events: none;
}

.mb-stepper__icon {
  font-size: 28rpx;
  font-weight: 600;
  color: var(--color-text, #1b1b1b);
  line-height: 1;
}

.mb-stepper__input {
  width: 64rpx;
  height: 56rpx;
  text-align: center;
  font-size: 26rpx;
  font-weight: 600;
  color: var(--color-text, #1b1b1b);
  background: transparent;
}
</style>
