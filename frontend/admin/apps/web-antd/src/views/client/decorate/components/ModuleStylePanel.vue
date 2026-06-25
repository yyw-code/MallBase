<script lang="ts" setup>
import type {
  MarginSideField,
  ModuleItem,
  PaddingSideField,
} from '../utils/useModuleSpacing';

import { computed } from 'vue';

import Upload from '#/components/upload/index.vue';

import {
  getModuleMarginOverall,
  getModuleMarginSide,
  getModulePaddingOverall,
  getModulePaddingSide,
  marginSideFields,
  paddingSideFields,
  updateModuleMarginAll,
  updateModuleMarginSide,
  updateModulePaddingAll,
  updateModulePaddingSide,
} from '../utils/useModuleSpacing';
import SpacingControl from './SpacingControl.vue';

type RadioOption = {
  label: string;
  value: any;
};

const props = defineProps<{
  backgroundModeOptions: RadioOption[];
  borderStyleOptions: RadioOption[];
  disabled: boolean;
  getProfileStyleColorInputValue: (
    config: Record<string, any>,
    field: string,
  ) => string;
  gradientDirectionOptions: RadioOption[];
  module: ModuleItem;
  syncModuleBackgroundShortcutByModule: (module: ModuleItem | null) => void;
  updateModuleStyleColorByField: (
    module: ModuleItem | null,
    field: string,
  ) => void;
  updateModuleStyleColorFromEvent: (
    module: ModuleItem | null,
    field: string,
    event: Event,
  ) => void;
  visibilityOptions: RadioOption[];
}>();

const emit = defineEmits<{
  reset: [module: ModuleItem];
}>();

const editableModule = computed(() => props.module);

const moduleColorFields = ['backgroundColorStart', 'backgroundColorEnd'];

const getPaddingSideValue = (field: string) =>
  getModulePaddingSide(editableModule.value, field as PaddingSideField);

const getMarginSideValue = (field: string) =>
  getModuleMarginSide(editableModule.value, field as MarginSideField);

const updatePaddingSide = (field: string, value: unknown) => {
  updateModulePaddingSide(
    editableModule.value,
    field as PaddingSideField,
    value,
  );
};

const updateMarginSide = (field: string, value: unknown) => {
  updateModuleMarginSide(editableModule.value, field as MarginSideField, value);
};
</script>

<template>
  <div class="property-section">
    <div class="property-section__head">
      <div class="property-section__title">基础样式</div>
      <a-button
        :disabled="disabled"
        size="small"
        type="link"
        @click="emit('reset', editableModule)"
      >
        重置
      </a-button>
    </div>

    <div class="profile-style-settings">
      <div class="style-control-row">
        <div class="style-control-row__label">组件宽度</div>
        <div class="style-control-row__body">
          <div class="style-range-control">
            <a-slider
              v-model:value="editableModule.config.widthPercent"
              :max="100"
              :min="50"
              class="style-range-control__slider"
            />
            <a-input-number
              v-model:value="editableModule.config.widthPercent"
              :max="100"
              :min="50"
              addon-after="%"
              class="style-range-control__number"
            />
          </div>
        </div>
      </div>

      <div class="style-control-row">
        <div class="style-control-row__label">背景设置</div>
        <div class="style-control-row__body">
          <a-radio-group
            v-model:value="editableModule.config.backgroundMode"
            :options="backgroundModeOptions"
          />
        </div>
      </div>

      <template v-if="editableModule.config.backgroundMode !== 'image'">
        <div class="style-control-row">
          <div class="style-control-row__label">背景颜色</div>
          <div class="style-control-row__body">
            <div class="style-color-stack">
              <div
                v-for="field in moduleColorFields"
                :key="field"
                class="style-color-field style-color-field--no-action"
              >
                <input
                  :aria-label="`选择${field}颜色`"
                  class="style-color-field__picker"
                  type="color"
                  :value="
                    getProfileStyleColorInputValue(editableModule.config, field)
                  "
                  @input="
                    (event: Event) =>
                      updateModuleStyleColorFromEvent(
                        editableModule,
                        field,
                        event,
                      )
                  "
                />
                <a-input
                  v-model:value="editableModule.config[field]"
                  class="style-color-field__input"
                  @change="
                    () => updateModuleStyleColorByField(editableModule, field)
                  "
                />
              </div>
            </div>
          </div>
        </div>

        <div class="style-control-row">
          <div class="style-control-row__label">渐变方向</div>
          <div class="style-control-row__body">
            <a-radio-group
              v-model:value="editableModule.config.backgroundGradientDirection"
              :options="gradientDirectionOptions"
              @change="
                () => syncModuleBackgroundShortcutByModule(editableModule)
              "
            />
          </div>
        </div>
      </template>

      <div v-else class="style-control-row">
        <div class="style-control-row__label">背景图片</div>
        <div class="style-control-row__body">
          <Upload
            v-model:value="editableModule.config.background_image"
            :disabled="disabled"
            module="client"
            type="image"
          />
        </div>
      </div>

      <div class="style-control-row">
        <div class="style-control-row__label">背景圆角</div>
        <div class="style-control-row__body">
          <div class="style-range-control">
            <a-slider
              v-model:value="editableModule.config.radius"
              :max="160"
              :min="0"
              class="style-range-control__slider"
            />
            <a-input-number
              v-model:value="editableModule.config.radius"
              :max="160"
              :min="0"
              addon-after="rpx"
              class="style-range-control__number"
            />
          </div>
        </div>
      </div>

      <div class="style-control-row style-control-row--spacing">
        <div class="style-control-row__label">外边距</div>
        <div class="style-control-row__body">
          <SpacingControl
            :disabled="disabled"
            :get-side-value="getMarginSideValue"
            :overall-value="getModuleMarginOverall(editableModule)"
            :side-fields="marginSideFields"
            @update:all="
              (value: unknown) => updateModuleMarginAll(editableModule, value)
            "
            @update:side="updateMarginSide"
          />
        </div>
      </div>

      <div class="style-control-row style-control-row--spacing">
        <div class="style-control-row__label">内边距</div>
        <div class="style-control-row__body">
          <SpacingControl
            :disabled="disabled"
            :get-side-value="getPaddingSideValue"
            :overall-value="getModulePaddingOverall(editableModule)"
            :side-fields="paddingSideFields"
            @update:all="
              (value: unknown) => updateModulePaddingAll(editableModule, value)
            "
            @update:side="updatePaddingSide"
          />
        </div>
      </div>

      <div class="style-control-row">
        <div class="style-control-row__label">边框设置</div>
        <div class="style-control-row__body">
          <a-radio-group
            v-model:value="editableModule.config.borderEnabled"
            :options="visibilityOptions"
          />
        </div>
      </div>

      <template v-if="editableModule.config.borderEnabled">
        <div class="style-control-row">
          <div class="style-control-row__label">边框样式</div>
          <div class="style-control-row__body">
            <a-radio-group
              v-model:value="editableModule.config.borderStyle"
              :options="borderStyleOptions"
            />
          </div>
        </div>

        <div class="style-control-row">
          <div class="style-control-row__label">边框粗细</div>
          <div class="style-control-row__body">
            <div class="style-range-control">
              <a-slider
                v-model:value="editableModule.config.borderWidth"
                :max="12"
                :min="0"
                class="style-range-control__slider"
              />
              <a-input-number
                v-model:value="editableModule.config.borderWidth"
                :max="12"
                :min="0"
                addon-after="rpx"
                class="style-range-control__number"
              />
            </div>
          </div>
        </div>

        <div class="style-control-row">
          <div class="style-control-row__label">边框颜色</div>
          <div class="style-control-row__body">
            <div class="style-color-field style-color-field--no-action">
              <input
                aria-label="选择边框颜色"
                class="style-color-field__picker"
                type="color"
                :value="
                  getProfileStyleColorInputValue(
                    editableModule.config,
                    'borderColor',
                  )
                "
                @input="
                  (event: Event) =>
                    updateModuleStyleColorFromEvent(
                      editableModule,
                      'borderColor',
                      event,
                    )
                "
              />
              <a-input
                v-model:value="editableModule.config.borderColor"
                class="style-color-field__input"
              />
            </div>
          </div>
        </div>
      </template>

      <div class="style-control-row">
        <div class="style-control-row__label">阴影设置</div>
        <div class="style-control-row__body">
          <a-radio-group
            v-model:value="editableModule.config.shadowEnabled"
            :options="visibilityOptions"
          />
        </div>
      </div>

      <template v-if="editableModule.config.shadowEnabled">
        <div class="style-control-row style-control-row--shadow">
          <div class="style-control-row__label">阴影参数</div>
          <div class="style-control-row__body">
            <div class="shadow-control-grid">
              <a-form-item label="X 偏移">
                <a-input-number
                  v-model:value="editableModule.config.shadowOffsetX"
                  :max="80"
                  :min="-80"
                  addon-after="rpx"
                  class="w-full"
                />
              </a-form-item>
              <a-form-item label="Y 偏移">
                <a-input-number
                  v-model:value="editableModule.config.shadowOffsetY"
                  :max="80"
                  :min="-80"
                  addon-after="rpx"
                  class="w-full"
                />
              </a-form-item>
              <a-form-item label="模糊">
                <a-input-number
                  v-model:value="editableModule.config.shadowBlur"
                  :max="160"
                  :min="0"
                  addon-after="rpx"
                  class="w-full"
                />
              </a-form-item>
              <a-form-item label="扩散">
                <a-input-number
                  v-model:value="editableModule.config.shadowSpread"
                  :max="80"
                  :min="-80"
                  addon-after="rpx"
                  class="w-full"
                />
              </a-form-item>
              <a-form-item label="颜色">
                <div class="style-color-field style-color-field--no-action">
                  <input
                    aria-label="选择阴影颜色"
                    class="style-color-field__picker"
                    type="color"
                    :value="
                      getProfileStyleColorInputValue(
                        editableModule.config,
                        'shadowColor',
                      )
                    "
                    @input="
                      (event: Event) =>
                        updateModuleStyleColorFromEvent(
                          editableModule,
                          'shadowColor',
                          event,
                        )
                    "
                  />
                  <a-input
                    v-model:value="editableModule.config.shadowColor"
                    class="style-color-field__input"
                  />
                </div>
              </a-form-item>
              <a-form-item label="透明度">
                <a-input-number
                  v-model:value="editableModule.config.shadowOpacity"
                  :max="100"
                  :min="0"
                  addon-after="%"
                  class="w-full"
                />
              </a-form-item>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
