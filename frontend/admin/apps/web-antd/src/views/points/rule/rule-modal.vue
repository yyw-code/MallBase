<script lang="ts" setup>
import type { PointsRuleApi } from '#/api/points';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { createPointsRuleApi, updatePointsRuleApi } from '#/api/points';

interface Props {
  editData?: null | PointsRuleApi.RuleItem;
  sceneOptions: PointsRuleApi.SceneOption[];
  visible: boolean;
}

interface Emits {
  (e: 'success'): void;
  (e: 'update:visible', value: boolean): void;
}

const props = withDefaults(defineProps<Props>(), {
  editData: null,
  sceneOptions: () => [],
  visible: false,
});

const emit = defineEmits<Emits>();

const formRef = ref();
const loading = ref(false);
const isEdit = computed(() => !!props.editData);

const formData = reactive<PointsRuleApi.SaveParams>({
  scene: 'order_complete',
  name: '',
  description: '',
  points_per_yuan: 1,
  fixed_points: 0,
  max_points: 0,
  sort: 0,
  status: 1,
  remark: '',
});

const isOrderRule = computed(() => formData.scene === 'order_complete');

const rules = {
  name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
  scene: [{ required: true, message: '请选择规则场景', trigger: 'change' }],
};

watch(
  () => props.visible,
  (visible) => {
    if (!visible) return;
    resetForm();
    if (props.editData) {
      Object.assign(formData, {
        scene: props.editData.scene,
        name: props.editData.name,
        description: props.editData.description || '',
        points_per_yuan: props.editData.points_per_yuan,
        fixed_points: props.editData.fixed_points,
        max_points: props.editData.max_points,
        sort: props.editData.sort,
        status: props.editData.status,
        remark: props.editData.remark || '',
      });
    }
  },
);

watch(
  () => formData.scene,
  (scene) => {
    if (scene === 'order_complete') {
      formData.fixed_points = 0;
      if (!formData.points_per_yuan) formData.points_per_yuan = 1;
      return;
    }
    formData.points_per_yuan = 0;
    if (!formData.fixed_points) formData.fixed_points = 1;
  },
);

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    scene: 'order_complete',
    name: '',
    description: '',
    points_per_yuan: 1,
    fixed_points: 0,
    max_points: 0,
    sort: 0,
    status: 1,
    remark: '',
  });
};

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;
    const payload = { ...formData };

    if (isEdit.value) {
      await updatePointsRuleApi(props.editData!.id, payload);
      message.success('更新成功');
    } else {
      await createPointsRuleApi(payload);
      message.success('创建成功');
    }

    emit('success');
    emit('update:visible', false);
  } catch (error: any) {
    if (!error?.errorFields) {
      message.error(error?.message || '操作失败');
    }
  } finally {
    loading.value = false;
  }
};

const handleCancel = () => {
  emit('update:visible', false);
};
</script>

<template>
  <a-modal
    :confirm-loading="loading"
    :open="visible"
    :title="isEdit ? '编辑积分规则' : '新增积分规则'"
    @cancel="handleCancel"
    @ok="handleSubmit"
  >
    <a-form
      ref="formRef"
      class="pt-4"
      :label-col="{ style: { width: '110px' } }"
      :model="formData"
      :rules="rules"
    >
      <a-form-item label="规则场景" name="scene">
        <a-select
          v-model:value="formData.scene"
          :disabled="isEdit"
          placeholder="请选择规则场景"
        >
          <a-select-option
            v-for="option in sceneOptions"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </a-select-option>
        </a-select>
      </a-form-item>

      <a-form-item label="规则名称" name="name">
        <a-input
          v-model:value="formData.name"
          allow-clear
          placeholder="请输入规则名称"
        />
      </a-form-item>

      <a-form-item label="规则说明" name="description">
        <a-textarea
          v-model:value="formData.description"
          :maxlength="255"
          :rows="2"
          allow-clear
          show-count
        />
      </a-form-item>

      <a-form-item v-if="isOrderRule" label="每元奖励" name="points_per_yuan">
        <a-input-number
          v-model:value="formData.points_per_yuan"
          class="w-full"
          :min="1"
          :precision="0"
          placeholder="每消费 1 元奖励积分"
        />
      </a-form-item>

      <a-form-item v-else label="固定奖励" name="fixed_points">
        <a-input-number
          v-model:value="formData.fixed_points"
          class="w-full"
          :min="1"
          :precision="0"
          placeholder="单次固定奖励积分"
        />
      </a-form-item>

      <a-form-item label="单次上限" name="max_points">
        <a-input-number
          v-model:value="formData.max_points"
          class="w-full"
          :min="0"
          :precision="0"
          placeholder="0 表示不限制"
        />
      </a-form-item>

      <a-form-item label="排序" name="sort">
        <a-input-number
          v-model:value="formData.sort"
          class="w-full"
          :min="0"
          :precision="0"
        />
      </a-form-item>

      <a-form-item label="状态" name="status">
        <a-radio-group v-model:value="formData.status">
          <a-radio :value="1">启用</a-radio>
          <a-radio :value="0">禁用</a-radio>
        </a-radio-group>
      </a-form-item>

      <a-form-item label="备注" name="remark">
        <a-textarea
          v-model:value="formData.remark"
          :maxlength="255"
          :rows="3"
          allow-clear
          show-count
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
