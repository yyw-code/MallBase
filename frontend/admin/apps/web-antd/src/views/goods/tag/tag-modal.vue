<script lang="ts" setup>
import type { GoodsTagApi } from '#/api/goods';

import { computed, onMounted, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  createGoodsTagApi,
  updateGoodsTagApi,
} from '#/api/goods';
import { useColorOptions } from '#/composables/useColorOptions';

interface Props {
  visible: boolean;
  editData?: GoodsTagApi.TagItem | null;
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
  color: 'blue',
  sort: 0,
  status: 1,
});

const rules = {
  name: [{ required: true, message: '请输入标签名称', trigger: 'blur' }],
  color: [{ required: true, message: '请选择显示颜色', trigger: 'change' }],
};

const colorOptions = ref<Array<{ value: string; label: string; color: string }>>([]);

const formRef = ref();

const loading = ref(false);

/* ---------------- 加载颜色选项 ---------------- */
onMounted(async () => {
  colorOptions.value = await useColorOptions();
});

/* ---------------- 监听 visible 变化 ---------------- */
watch(
  () => props.visible,
  (val) => {
    if (val) {
      resetForm();
      if (props.editData) {
        Object.assign(formData, {
          name: props.editData.name || '',
          color: props.editData.color || 'blue',
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
    color: 'blue',
    sort: 0,
    status: 1,
  });
};

/* ---------------- 提交表单 ---------------- */
const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;

    if (isEdit.value) {
      await updateGoodsTagApi(props.editData!.id, formData);
      message.success('更新成功');
    } else {
      await createGoodsTagApi(formData);
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
    :title="isEdit ? '编辑标签' : '新增标签'"
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
      <a-form-item label="标签名称" name="name">
        <a-input
          v-model:value="formData.name"
          placeholder="请输入标签名称"
          allow-clear
        />
      </a-form-item>

      <a-form-item label="显示颜色" name="color">
        <a-select v-model:value="formData.color" placeholder="请选择显示颜色">
          <a-select-option
            v-for="option in colorOptions"
            :key="option.value"
            :value="option.value"
          >
            <a-tag :color="option.color">{{ option.label }}</a-tag>
          </a-select-option>
        </a-select>
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
