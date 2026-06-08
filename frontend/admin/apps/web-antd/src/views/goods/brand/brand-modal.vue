<script lang="ts" setup>
import type { GoodsBrandApi } from '#/api/goods';

import type { FileInfo } from '#/components/upload';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import Upload from '#/components/upload/index.vue';

import {
  createGoodsBrandApi,
  updateGoodsBrandApi,
} from '#/api/goods';

interface Props {
  visible: boolean;
  editData?: GoodsBrandApi.BrandItem | null;
}

interface Emits {
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}

const props = withDefaults(defineProps<Props>(), {
  visible: false,
  editData: null,
});

const emit = defineEmits<Emits>();

const isEdit = computed(() => !!props.editData);

const formData = reactive({
  name: '',
  logo: undefined as FileInfo | string | undefined,
  description: '',
  sort: 0,
  status: 1,
});

const rules = {
  name: [{ required: true, message: '请输入品牌名称', trigger: 'blur' }],
};

const formRef = ref();

const loading = ref(false);
const fileNameFromValue = (value: unknown) => String(value || '').split('/').pop() || '';

/* ---------------- 监听 visible 变化 ---------------- */
watch(
  () => props.visible,
  (val) => {
    if (val) {
      resetForm();
      if (props.editData) {
        const logoUrl = props.editData.logo || '';
        const logoFullUrl = props.editData.logo_full_url || logoUrl;
        Object.assign(formData, {
          name: props.editData.name || '',
          logo: logoUrl
            ? {
                url: String(logoUrl),
                full_url: logoFullUrl || String(logoUrl),
                name: fileNameFromValue(logoUrl),
              }
            : undefined,
          description: props.editData.description || '',
          sort: props.editData.sort || 0,
          status: props.editData.status ?? 1,
        });
      }
    }
  },
);

/* ---------------- 重置表单 ---------------- */
const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '',
    logo: undefined,
    description: '',
    sort: 0,
    status: 1,
  });
};

/* ---------------- 提交表单 ---------------- */
const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;

    const submitData = {
      ...formData,
      logo: typeof formData.logo === 'object' ? formData.logo?.url || '' : formData.logo || '',
    };

    if (isEdit.value) {
      await updateGoodsBrandApi(props.editData!.id, submitData);
      message.success('更新成功');
    } else {
      await createGoodsBrandApi(submitData);
      message.success('创建成功');
    }

    emit('success');
    emit('update:visible', false);
  } catch (error: any) {
    if (error.errorFields) {
      console.log('表单验证失败:', error);
    } else {
      console.error('提交失败:', error);
      message.error(error.message || '操作失败');
    }
  } finally {
    loading.value = false;
  }
};

/* ---------------- 取消 ---------------- */
const handleCancel = () => {
  emit('update:visible', false);
};
</script>

<template>
  <a-modal
    :title="isEdit ? '编辑品牌' : '新增品牌'"
    :open="visible"
    :confirm-loading="loading"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <a-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      :label-col="{ style: { width: '100px' } }"
      class="pt-4"
    >
      <a-form-item label="品牌名称" name="name">
        <a-input
          v-model:value="formData.name"
          placeholder="请输入品牌名称"
          allow-clear
        />
      </a-form-item>

      <a-form-item label="LOGO" name="logo">
        <Upload
          v-model:value="formData.logo"
          type="image"
          module="goods"
        />
      </a-form-item>

      <a-form-item label="描述" name="description">
        <a-textarea
          v-model:value="formData.description"
          placeholder="请输入品牌描述"
          :rows="3"
          allow-clear
        />
      </a-form-item>

      <a-form-item label="排序" name="sort">
        <a-input-number
          v-model:value="formData.sort"
          :min="0"
          :max="9999"
          placeholder="数字越小越靠前"
          class="w-full"
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
