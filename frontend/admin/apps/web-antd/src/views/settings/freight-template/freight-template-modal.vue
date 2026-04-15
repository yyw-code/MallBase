<script lang="ts" setup>
import type { FreightTemplateApi } from '#/api/setting/freight-template';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  createFreightTemplateApi,
  updateFreightTemplateApi,
} from '#/api/setting/freight-template';
import RegionPicker from '#/components/region-picker/index.vue';

const props = withDefaults(
  defineProps<{
    editData?: FreightTemplateApi.TemplateItem | null;
    visible?: boolean;
  }>(),
  {
    visible: false,
    editData: null,
  },
);

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}>();
const formRef = ref();
const loading = ref(false);
const isEdit = computed(() => !!props.editData?.id);
const title = computed(() => (isEdit.value ? '编辑运费模板' : '新增运费模板'));

const createRule = (): FreightTemplateApi.FreightRuleItem => ({
  region_ids: [],
  first_amount: 1,
  first_fee: 0,
  continue_amount: 1,
  continue_fee: 0,
  region_status: 1,
  region_invalid_reason: undefined,
  sort: 0,
});

const formData = ref<FreightTemplateApi.SaveParams>({
  name: '',
  charge_type: 'piece',
  default_first_amount: 1,
  default_first_fee: 0,
  default_continue_amount: 1,
  default_continue_fee: 0,
  status: 1,
  remark: '',
  rules: [createRule()],
});

watch(
  () => props.editData,
  (data) => {
    formData.value = data
      ? {
          name: data.name,
          charge_type: data.charge_type,
          default_first_amount: data.default_first_amount,
          default_first_fee: data.default_first_fee,
          default_continue_amount: data.default_continue_amount,
          default_continue_fee: data.default_continue_fee,
          status: data.status,
          remark: data.remark || '',
          rules: (data.rules || []).map((item) => ({
            region_ids: item.region_ids || [],
            first_amount: item.first_amount,
            first_fee: item.first_fee,
            continue_amount: item.continue_amount,
            continue_fee: item.continue_fee,
            region_status: item.region_status,
            region_invalid_reason: item.region_invalid_reason,
            sort: item.sort || 0,
          })),
        }
      : {
          name: '',
          charge_type: 'piece',
          default_first_amount: 1,
          default_first_fee: 0,
          default_continue_amount: 1,
          default_continue_fee: 0,
          status: 1,
          remark: '',
          rules: [createRule()],
        };
  },
  { immediate: true },
);

const rules = {
  name: [{ required: true, message: '请输入模板名称' }],
};

function addRule() {
  formData.value.rules.push(createRule());
}

function removeRule(index: number) {
  formData.value.rules.splice(index, 1);
}

function normalizeRuleRegionIds(
  regionIds: FreightTemplateApi.RegionSelectionValue[],
) {
  return regionIds
    .map((item) => (Array.isArray(item) ? item[item.length - 1] : item))
    .filter((item): item is number => typeof item === 'number' && item > 0);
}

async function handleSubmit() {
  await formRef.value?.validate();
  loading.value = true;
  try {
    if (
      formData.value.rules.some(
        (item) => !item.region_ids || item.region_ids.length === 0,
      )
    ) {
      message.error('已添加的区域规则必须选择地区');
      return;
    }

    const payload = {
      ...formData.value,
      rules: formData.value.rules.map((item, index) => ({
        ...item,
        region_ids: normalizeRuleRegionIds(item.region_ids),
        sort: index,
      })),
    };

    if (isEdit.value) {
      await updateFreightTemplateApi(props.editData!.id, payload);
      message.success('更新成功');
    } else {
      await createFreightTemplateApi(payload);
      message.success('创建成功');
    }
    emit('success');
    emit('update:visible', false);
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <a-modal
    :open="visible"
    :title="title"
    width="960px"
    :confirm-loading="loading"
    @ok="handleSubmit"
    @cancel="() => emit('update:visible', false)"
  >
    <a-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      :label-col="{ style: { width: '110px' } }"
      class="pt-4"
    >
      <a-form-item label="模板名称" name="name">
        <a-input v-model:value="formData.name" />
      </a-form-item>
      <a-form-item label="计费方式">
        <a-radio-group v-model:value="formData.charge_type">
          <a-radio-button value="piece">按件</a-radio-button>
          <a-radio-button value="weight">按重</a-radio-button>
        </a-radio-group>
      </a-form-item>
      <a-alert
        class="mb-4"
        message="全国默认运费（未匹配任何区域规则时使用）"
        show-icon
        type="info"
      />
      <a-row :gutter="16">
        <a-col :span="12">
          <a-form-item label="默认首件/重">
            <a-input-number
              v-model:value="formData.default_first_amount"
              :min="0.01"
              :precision="2"
              style="width: 100%"
            />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item label="默认首费">
            <a-input-number
              v-model:value="formData.default_first_fee"
              :min="0"
              :precision="2"
              style="width: 100%"
            />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item label="默认续件/重">
            <a-input-number
              v-model:value="formData.default_continue_amount"
              :min="0.01"
              :precision="2"
              style="width: 100%"
            />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item label="默认续费">
            <a-input-number
              v-model:value="formData.default_continue_fee"
              :min="0"
              :precision="2"
              style="width: 100%"
            />
          </a-form-item>
        </a-col>
      </a-row>
      <a-form-item label="备注">
        <a-input v-model:value="formData.remark" allow-clear />
      </a-form-item>
      <a-form-item label="状态">
        <a-switch
          v-model:checked="formData.status"
          :checked-value="1"
          :un-checked-value="0"
        />
      </a-form-item>

      <div class="mb-3 flex items-center justify-between">
        <h4 class="mb-0">区域规则</h4>
        <a-button type="dashed" @click="addRule">新增规则</a-button>
      </div>
      <a-alert
        class="mb-3"
        message="匹配优先级：街道 > 区县 > 市 > 省 > 全国默认。同一层级的地区不能跨规则重复配置。未添加任何规则时将使用全国默认运费。"
        show-icon
        type="info"
      />

      <div
        v-for="(rule, index) in formData.rules"
        :key="index"
        class="mb-3 rounded-lg border p-4"
      >
        <a-row :gutter="16">
          <a-col :span="24">
            <a-form-item label="适用地区">
              <RegionPicker
                v-model:value="rule.region_ids"
                multiple
                :leaf-only="false"
                placeholder="请选择地区（支持省 / 市 / 区县 / 街道，可多选）"
              />
            </a-form-item>
            <a-alert
              v-if="rule.region_status === 0 && rule.region_invalid_reason"
              :message="rule.region_invalid_reason"
              class="mb-4"
              show-icon
              type="warning"
            />
          </a-col>
          <a-col :span="6">
            <a-form-item label="首件/重">
              <a-input-number
                v-model:value="rule.first_amount"
                :min="0.01"
                :precision="2"
                style="width: 100%"
              />
            </a-form-item>
          </a-col>
          <a-col :span="6">
            <a-form-item label="首费">
              <a-input-number
                v-model:value="rule.first_fee"
                :min="0"
                :precision="2"
                style="width: 100%"
              />
            </a-form-item>
          </a-col>
          <a-col :span="6">
            <a-form-item label="续件/重">
              <a-input-number
                v-model:value="rule.continue_amount"
                :min="0.01"
                :precision="2"
                style="width: 100%"
              />
            </a-form-item>
          </a-col>
          <a-col :span="6">
            <a-form-item label="续费">
              <a-input-number
                v-model:value="rule.continue_fee"
                :min="0"
                :precision="2"
                style="width: 100%"
              />
            </a-form-item>
          </a-col>
        </a-row>
        <div class="flex justify-end">
          <a-button danger type="link" @click="removeRule(index)">
            删除规则
          </a-button>
        </div>
      </div>
    </a-form>
  </a-modal>
</template>
