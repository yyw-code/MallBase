<script lang="ts" setup>
type SpacingSideField = {
  field: string;
  label: string;
};

defineProps<{
  disabled?: boolean;
  getSideValue: (field: string) => number;
  max?: number;
  min?: number;
  overallValue: number;
  sideFields: readonly SpacingSideField[];
  unit?: string;
}>();

const emit = defineEmits<{
  'update:all': [value: unknown];
  'update:side': [field: string, value: unknown];
}>();

const emitAll = (value: unknown) => {
  emit('update:all', value);
};

const emitSide = (field: string, value: unknown) => {
  emit('update:side', field, value);
};
</script>

<template>
  <div class="spacing-control">
    <div class="spacing-control__main">
      <span class="spacing-control__main-label">全部</span>
      <a-slider
        :disabled="disabled"
        :max="max ?? 160"
        :min="min ?? 0"
        :value="overallValue"
        class="spacing-control__slider"
        @change="emitAll"
        @update:value="emitAll"
      />
      <a-input-number
        :addon-after="unit ?? 'rpx'"
        :disabled="disabled"
        :max="max ?? 160"
        :min="min ?? 0"
        :value="overallValue"
        class="spacing-control__number"
        @change="emitAll"
        @update:value="emitAll"
      />
    </div>

    <div class="spacing-side-grid">
      <label
        v-for="side in sideFields"
        :key="side.field"
        class="spacing-side-field"
      >
        <span class="spacing-side-field__label">{{ side.label }}</span>
        <a-input-number
          :addon-after="unit ?? 'rpx'"
          :disabled="disabled"
          :max="max ?? 160"
          :min="min ?? 0"
          :value="getSideValue(side.field)"
          class="spacing-side-field__number"
          @change="(value: unknown) => emitSide(side.field, value)"
          @update:value="(value: unknown) => emitSide(side.field, value)"
        />
      </label>
    </div>
  </div>
</template>
