<script lang="ts" setup>
import type { ArticleApi, ArticleCategoryApi } from '#/api/content';

import type { FileInfo } from '#/components/upload';

import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { message } from 'ant-design-vue';

import RichTextEditor from '#/components/rich-text-editor/index.vue';
import Upload from '#/components/upload/index.vue';

import {
  createArticleApi,
  getAllArticleCategoriesApi,
  getArticleInfoApi,
  updateArticleApi,
} from '#/api/content';

defineOptions({ name: 'ArticleEdit' });

const route = useRoute();
const router = useRouter();

const editId = computed(() => {
  const raw = Array.isArray(route.query.id)
    ? route.query.id[0]
    : route.query.id;
  const id = raw ? Number(raw) : 0;
  return Number.isNaN(id) ? 0 : id;
});
const isEdit = computed(() => editId.value > 0);

const formRef = ref();
const loading = ref(false);
const categoryOptions = ref<ArticleCategoryApi.CategoryItem[]>([]);

const formData = reactive({
  category_id: undefined as number | undefined,
  content: '',
  cover: undefined as FileInfo | string | undefined,
  description: '',
  sort: 0,
  status: 1,
  title: '',
});

const rules = {
  category_id: [
    { required: true, message: '请选择文章分类', trigger: 'change' },
  ],
  title: [{ required: true, message: '请输入文章标题', trigger: 'blur' }],
};

const fileNameFromValue = (value: unknown) =>
  String(value || '')
    .split('/')
    .pop() || '';

const uploadValueToScalar = (value: FileInfo | string | undefined) => {
  if (!value) return '';
  if (typeof value === 'string') return value;
  return value.url || value.asset_id || '';
};

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    category_id: undefined,
    content: '',
    cover: undefined,
    description: '',
    sort: 0,
    status: 1,
    title: '',
  });
};

const normalizeCoverForForm = (detail: ArticleApi.ArticleItem) => {
  if (!detail.cover) return undefined;
  const cover = String(detail.cover);
  return {
    asset_id: /^\d+$/.test(cover) ? Number(cover) : undefined,
    full_url: detail.cover_full_url || cover,
    name: fileNameFromValue(cover),
    url: cover,
  };
};

const loadCategories = async () => {
  categoryOptions.value = await getAllArticleCategoriesApi();
};

const loadEditData = async (id: number) => {
  const detail = await getArticleInfoApi(id);
  Object.assign(formData, {
    category_id: detail.category_id,
    content: detail.content || '',
    cover: normalizeCoverForForm(detail),
    description: detail.description || '',
    sort: detail.sort || 0,
    status: detail.status ?? 1,
    title: detail.title || '',
  });
};

const loadPageData = async () => {
  loading.value = true;
  try {
    resetForm();
    await loadCategories();
    if (editId.value > 0) {
      await loadEditData(editId.value);
    }
  } catch (error: any) {
    message.error(error?.message || '加载文章数据失败');
  } finally {
    loading.value = false;
  }
};

const buildSubmitData = (): ArticleApi.SaveParams => ({
  category_id: Number(formData.category_id || 0),
  content: formData.content,
  cover: uploadValueToScalar(formData.cover),
  description: formData.description || null,
  sort: formData.sort,
  status: formData.status,
  title: formData.title,
});

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;
    const submitData = buildSubmitData();
    if (isEdit.value) {
      await updateArticleApi(editId.value, submitData);
      message.success('保存成功');
    } else {
      await createArticleApi(submitData);
      message.success('创建成功');
    }
    router.back();
  } catch (error: any) {
    if (!error?.errorFields) {
      message.error(error?.message || '保存失败');
    }
  } finally {
    loading.value = false;
  }
};

watch(
  () => editId.value,
  () => {
    void loadPageData();
  },
  { immediate: true },
);
</script>

<template>
  <div class="article-edit-page">
    <div class="article-edit-header">
      <div class="flex items-center gap-3">
        <a-button type="text" @click="router.back()">← 返回</a-button>
        <span class="text-lg font-semibold">
          {{ isEdit ? '编辑文章' : '新增文章' }}
        </span>
      </div>
      <div class="flex items-center gap-2">
        <a-button @click="router.back()">取消</a-button>
        <a-button type="primary" :loading="loading" @click="handleSubmit">
          保存
        </a-button>
      </div>
    </div>

    <div class="article-edit-body">
      <a-spin :spinning="loading">
        <a-form
          ref="formRef"
          :label-col="{ style: { width: '92px' } }"
          :model="formData"
          :rules="rules"
        >
          <div class="article-edit-panel">
            <div class="article-edit-panel__title">基础信息</div>
            <a-form-item label="文章标题" name="title">
              <a-input
                v-model:value="formData.title"
                allow-clear
                :maxlength="160"
                placeholder="请输入文章标题"
                show-count
              />
            </a-form-item>

            <a-form-item label="文章分类" name="category_id">
              <a-select
                v-model:value="formData.category_id"
                allow-clear
                placeholder="请选择文章分类"
                style="width: 260px"
              >
                <a-select-option
                  v-for="item in categoryOptions"
                  :key="item.id"
                  :value="item.id"
                >
                  {{ item.name }}
                </a-select-option>
              </a-select>
            </a-form-item>

            <a-form-item label="封面" name="cover">
              <Upload
                v-model:value="formData.cover"
                module="article"
                type="image"
              />
            </a-form-item>

            <a-form-item label="描述" name="description">
              <a-textarea
                v-model:value="formData.description"
                allow-clear
                :maxlength="500"
                placeholder="用于文章列表摘要展示"
                :rows="3"
                show-count
              />
            </a-form-item>

            <div class="grid grid-cols-1 gap-x-4 md:grid-cols-2">
              <a-form-item label="排序" name="sort">
                <a-input-number
                  v-model:value="formData.sort"
                  :max="9999"
                  :min="0"
                  placeholder="数字越小越靠前"
                  style="width: 160px"
                />
              </a-form-item>

              <a-form-item label="状态" name="status">
                <a-radio-group v-model:value="formData.status">
                  <a-radio :value="1">启用</a-radio>
                  <a-radio :value="0">禁用</a-radio>
                </a-radio-group>
              </a-form-item>
            </div>
          </div>

          <div class="article-edit-panel">
            <div class="article-edit-panel__title">文章内容</div>
            <a-form-item
              label="内容"
              name="content"
              :wrapper-col="{ span: 22 }"
            >
              <RichTextEditor
                :height="520"
                module="article"
                :model-value="formData.content"
                placeholder="请输入文章内容"
                @update:model-value="
                  (value: string) => (formData.content = value)
                "
              />
            </a-form-item>
          </div>
        </a-form>
      </a-spin>
    </div>
  </div>
</template>

<style scoped>
.article-edit-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: hsl(var(--background-deep));
}

.article-edit-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 18px;
  border-bottom: 1px solid hsl(var(--border));
  background: hsl(var(--card));
}

.article-edit-body {
  flex: 1;
  min-height: 0;
  padding: 16px;
  overflow: auto;
}

.article-edit-panel {
  padding: 18px;
  margin-bottom: 16px;
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
  background: hsl(var(--card));
}

.article-edit-panel__title {
  margin-bottom: 16px;
  font-size: 15px;
  font-weight: 600;
}
</style>
