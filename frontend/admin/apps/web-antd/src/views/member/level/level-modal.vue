<script lang="ts" setup>
import type { MemberLevelApi } from '#/api/member';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { createMemberLevelApi, updateMemberLevelApi } from '#/api/member';

interface Props {
  editData?: null | MemberLevelApi.LevelItem;
  visible: boolean;
}

interface Emits {
  (e: 'success'): void;
  (e: 'update:visible', value: boolean): void;
}

const props = withDefaults(defineProps<Props>(), {
  editData: null,
  visible: false,
});

const emit = defineEmits<Emits>();

const formRef = ref();
const loading = ref(false);
const isEdit = computed(() => !!props.editData);

const formData = reactive<MemberLevelApi.SaveParams>({
  name: '',
  growth_min: 0,
  discount_percent: 100,
  sort: 0,
  status: 1,
  remark: '',
});

const rules = {
  name: [{ required: true, message: '请输入等级名称', trigger: 'blur' }],
  growth_min: [{ required: true, message: '请输入成长值门槛', trigger: 'blur' }],
  discount_percent: [
    { required: true, message: '请输入等级折扣', trigger: 'blur' },
  ],
};

watch(
  () => props.visible,
  (visible) => {
    if (!visible) return;
    resetForm();
    if (props.editData) {
      Object.assign(formData, {
        name: props.editData.name,
        growth_min: props.editData.growth_min,
        discount_percent: props.editData.discount_percent,
        sort: props.editData.sort,
        status: props.editData.status,
        remark: props.editData.remark || '',
      });
    }
  },
);

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '',
    growth_min: 0,
    discount_percent: 100,
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
      await updateMemberLevelApi(props.editData!.id, payload);
      message.success('更新成功');
    } else {
      await createMemberLevelApi(payload);
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
    :title="isEdit ? '编辑会员等级' : '新增会员等级'"
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
      <a-form-item label="等级名称" name="name">
        <a-input
          v-model:value="formData.name"
          allow-clear
          placeholder="请输入等级名称"
        />
      </a-form-item>

      <a-form-item label="成长值门槛" name="growth_min">
        <a-input-number
          v-model:value="formData.growth_min"
          class="w-full"
          :min="0"
          :precision="0"
        />
      </a-form-item>

      <a-form-item label="等级折扣" name="discount_percent">
        <a-input-number
          v-model:value="formData.discount_percent"
          class="w-full"
          :max="100"
          :min="0"
          :precision="2"
        >
          <template #suffix>%</template>
        </a-input-number>
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
