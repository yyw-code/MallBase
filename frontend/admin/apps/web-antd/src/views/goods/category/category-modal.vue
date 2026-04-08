<script lang="ts" setup>
import type { GoodsCategoryApi } from '#/api/goods';

import type { FileInfo } from '#/components/upload';

import { computed, onMounted, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { IconPicker } from '@vben/common-ui';

import Upload from '#/components/upload/index.vue';

import {
  getAllGoodsCategoriesApi,
  createGoodsCategoryApi,
  updateGoodsCategoryApi,
} from '#/api/goods';

interface Props {
  visible: boolean;
  editData?: GoodsCategoryApi.CategoryItem | null;
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
  pid: 0,
  name: '',
  icon: '',
  image: undefined as FileInfo | string | undefined,
  description: '',
  sort: 0,
  status: 1,
});

const rules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
};

const formRef = ref();

const loading = ref(false);
const iconPrefix = ref('ant-design');

/* ---------------- 树形分类数据 ---------------- */
const categoryTreeData = ref<{ title: string; value: number; key: number; children?: any[] }[]>([]);

const buildTree = (list: GoodsCategoryApi.CategoryItem[], pid: number = 0): any[] => {
  return list
    .filter((item) => item.pid === pid)
    .map((item) => ({
      title: item.name,
      value: item.id,
      key: item.id,
      children: buildTree(list, item.id),
    }));
};

const loadCategoryTree = async () => {
  try {
    const list = await getAllGoodsCategoriesApi();
    categoryTreeData.value = [
      { title: '顶级分类', value: 0, key: 0, children: buildTree(list) },
    ];
  } catch (error) {
    console.error('加载分类树失败:', error);
  }
};

/* ---------------- 监听 visible 变化 ---------------- */
watch(
  () => props.visible,
  (val) => {
    if (val) {
      resetForm();
      loadCategoryTree();
      if (props.editData) {
        Object.assign(formData, {
          pid: props.editData.pid || 0,
          name: props.editData.name || '',
          icon: props.editData.icon || '',
          image: props.editData.image || undefined,
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
    pid: 0,
    name: '',
    icon: '',
    image: undefined,
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
      image: typeof formData.image === 'object' ? formData.image?.url || '' : formData.image || '',
    };

    if (isEdit.value) {
      await updateGoodsCategoryApi(props.editData!.id, submitData);
      message.success('更新成功');
    } else {
      await createGoodsCategoryApi(submitData);
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

onMounted(() => {
  loadCategoryTree();
});
</script>

<template>
  <a-modal
    :title="isEdit ? '编辑分类' : '新增分类'"
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
      <a-form-item label="父级分类" name="pid">
        <a-tree-select
          v-model:value="formData.pid"
          :tree-data="categoryTreeData"
          placeholder="请选择父级分类"
          tree-default-expand-all
          allow-clear
        />
      </a-form-item>

      <a-form-item label="分类名称" name="name">
        <a-input
          v-model:value="formData.name"
          placeholder="请输入分类名称"
          allow-clear
        />
      </a-form-item>

      <a-form-item label="图标" name="icon">
        <div class="flex flex-col" style="width: 100%">
          <div class="mb-2">
            <a-select
              v-model:value="iconPrefix"
              style="width: 200px"
              placeholder="选择图标集"
            >
              <a-select-option value="ant-design">
                Ant Design
              </a-select-option>
              <a-select-option value="lucide">Lucide</a-select-option>
              <a-select-option value="mdi">Material Design</a-select-option>
              <a-select-option value="carbon">Carbon</a-select-option>
              <a-select-option value="mdi-light">MDI Light</a-select-option>
            </a-select>
            <span class="sm ml-2 text-gray-400">
              也可直接输入，如：lucide:shield
            </span>
          </div>
          <IconPicker
            v-model="formData.icon"
            :prefix="iconPrefix"
            placeholder="请选择图标"
            style="width: 100%"
          />
        </div>
      </a-form-item>

      <a-form-item label="分类图片" name="image">
        <Upload
          v-model:value="formData.image"
          type="image"
          module="goods"
        />
      </a-form-item>

      <a-form-item label="描述" name="description">
        <a-textarea
          v-model:value="formData.description"
          placeholder="请输入分类描述"
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
