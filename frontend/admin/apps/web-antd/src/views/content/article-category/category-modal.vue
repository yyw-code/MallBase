<script lang="ts" setup>
import type { ArticleCategoryApi } from '#/api/content';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  createArticleCategoryApi,
  updateArticleCategoryApi,
} from '#/api/content';

interface Props {
  editData?: ArticleCategoryApi.CategoryItem | null;
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

const isEdit = computed(() => !!props.editData);
const formRef = ref();
const loading = ref(false);

const formData = reactive({
  description: '',
  name: '',
  sort: 0,
  status: 1,
});

const rules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
};

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    description: '',
    name: '',
    sort: 0,
    status: 1,
  });
};

watch(
  () => props.visible,
  (visible) => {
    if (!visible) return;
    resetForm();
    if (!props.editData) return;
    Object.assign(formData, {
      description: props.editData.description || '',
      name: props.editData.name || '',
      sort: props.editData.sort || 0,
      status: props.editData.status ?? 1,
    });
  },
);

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;
    const submitData = {
      description: formData.description || null,
      name: formData.name,
      sort: formData.sort,
      status: formData.status,
    };

    if (isEdit.value) {
      await updateArticleCategoryApi(props.editData!.id, submitData);
      message.success('更新成功');
    } else {
      await createArticleCategoryApi(submitData);
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
</script>

<template>
  <a-modal
    :confirm-loading="loading"
    :open="visible"
    :title="isEdit ? '编辑文章分类' : '新增文章分类'"
    @cancel="emit('update:visible', false)"
    @ok="handleSubmit"
  >
    <a-form
      ref="formRef"
      class="pt-4"
      :label-col="{ style: { width: '100px' } }"
      :model="formData"
      :rules="rules"
    >
      <a-form-item label="分类名称" name="name">
        <a-input
          v-model:value="formData.name"
          allow-clear
          :maxlength="80"
          placeholder="请输入分类名称"
          show-count
        />
      </a-form-item>

      <a-form-item label="描述" name="description">
        <a-textarea
          v-model:value="formData.description"
          allow-clear
          :maxlength="255"
          placeholder="请输入分类描述"
          :rows="3"
          show-count
        />
      </a-form-item>

      <a-form-item label="排序" name="sort">
        <a-input-number
          v-model:value="formData.sort"
          class="w-full"
          :max="9999"
          :min="0"
          placeholder="数字越小越靠前"
        />
      </a-form-item>

      <a-form-item label="状态" name="status">
        <a-radio-group v-model:value="formData.status">
          <a-radio :value="1">启用</a-radio>
          <a-radio :value="0">禁用</a-radio>
        </a-radio-group>
      </a-form-item>
    </a-form>
  </a-modal>
</template>
