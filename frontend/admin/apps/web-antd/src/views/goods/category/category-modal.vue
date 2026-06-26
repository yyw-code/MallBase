<script lang="ts" setup>
import type { GoodsCategoryApi } from '#/api/goods';
import type { FileInfo } from '#/components/upload';

import { computed, onMounted, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  createGoodsCategoryApi,
  getAllGoodsCategoriesApi,
  updateGoodsCategoryApi,
} from '#/api/goods';
import Upload from '#/components/upload/index.vue';

interface Props {
  visible?: boolean;
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
  image: undefined as FileInfo | string | undefined,
  description: '',
  sort: 0,
  status: 1,
});

const isRootCategory = computed(() => Number(formData.pid || 0) === 0);

const imageSizeTip = computed(() =>
  isRootCategory.value
    ? '一级分类建议 1905×825 横幅图，约 2.3:1，用于移动端顶部分类 banner'
    : '二级分类建议 1254×1254 方图，1:1，用于移动端分类宫格入口',
);

const rules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
};

const formRef = ref();

const loading = ref(false);
const fileNameFromValue = (value: unknown) =>
  String(value || '')
    .split('/')
    .pop() || '';

/* ---------------- 树形分类数据 ---------------- */
const categoryTreeData = ref<
  { children?: any[]; key: number; title: string; value: number }[]
>([]);

const buildTree = (
  list: GoodsCategoryApi.CategoryItem[],
  pid: number = 0,
): any[] => {
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
        const imageUrl = props.editData.image || '';
        const imageFullUrl = props.editData.image_full_url || imageUrl;
        Object.assign(formData, {
          pid: props.editData.pid || 0,
          name: props.editData.name || '',
          image: imageUrl
            ? {
                url: String(imageUrl),
                full_url: imageFullUrl || String(imageUrl),
                name: fileNameFromValue(imageUrl),
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
    pid: 0,
    name: '',
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
      icon: '',
      description: isRootCategory.value ? formData.description : '',
      image:
        typeof formData.image === 'object'
          ? formData.image?.url || ''
          : formData.image || '',
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
      console.warn('表单验证失败:', error);
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

      <a-form-item label="分类图片" name="image">
        <div class="form-tip">
          {{ imageSizeTip }}
        </div>
        <Upload v-model:value="formData.image" type="image" module="goods" />
      </a-form-item>

      <a-form-item v-if="isRootCategory" label="描述" name="description">
        <a-textarea
          v-model:value="formData.description"
          placeholder="请输入一级分类描述"
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

<style scoped>
.form-tip {
  color: hsl(var(--muted-foreground));
  font-size: 12px;
  line-height: 1.4;
}
</style>
