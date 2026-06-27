<script lang="ts" setup>
import type { ArticleApi, ArticleCategoryApi } from '#/api/content';

import { h, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import { useAccess } from '@vben/access';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  deleteArticleApi,
  getAllArticleCategoriesApi,
  getArticleListApi,
  updateArticleStatusApi,
} from '#/api/content';
import { useTableCrud } from '#/composables/useTableCrud';

import ReadRecordDrawer from './read-record-drawer.vue';

defineOptions({ name: 'ArticleManagement' });

const router = useRouter();
const { hasAccessByCodes } = useAccess();

const { tableData, loading, pagination, loadData } = useTableCrud<
  ArticleApi.ArticleItem,
  ArticleApi.ListParams
>(
  {
    list: getArticleListApi,
  },
  { immediateLoad: false },
);

const categoryOptions = ref<ArticleCategoryApi.CategoryItem[]>([]);
const searchParams = ref({
  category_id: undefined as number | undefined,
  keyword: '',
  status: undefined as number | undefined,
});

const readDrawerOpen = ref(false);
const readingArticle = ref<ArticleApi.ArticleItem | null>(null);

const loadCategories = async () => {
  try {
    categoryOptions.value = await getAllArticleCategoriesApi();
  } catch (error) {
    console.error('加载文章分类失败:', error);
  }
};

const refreshData = () => loadData(searchParams.value);

const submitSearch = () => {
  pagination.current = 1;
  refreshData();
};

const resetSearch = () => {
  searchParams.value = {
    category_id: undefined,
    keyword: '',
    status: undefined,
  };
  pagination.current = 1;
  refreshData();
};

const handleCreate = () => {
  router.push('/content/article/edit');
};

const handleEdit = (record: ArticleApi.ArticleItem) => {
  router.push(`/content/article/edit?id=${record.id}`);
};

const handleDelete = (record: ArticleApi.ArticleItem) => {
  Modal.confirm({
    cancelText: '取消',
    content: `确认删除文章「${record.title}」？`,
    okButtonProps: { danger: true },
    okText: '删除',
    title: '删除文章',
    onOk: async () => {
      await deleteArticleApi(record.id);
      message.success('删除成功');
      await refreshData();
    },
  });
};

const handleStatusChange = async (
  record: ArticleApi.ArticleItem,
  checked: boolean | number | string,
) => {
  try {
    await updateArticleStatusApi(record.id, checked === true ? 1 : 0);
    message.success('状态更新成功');
    await refreshData();
  } catch (error: any) {
    message.error(error?.message || '状态更新失败');
    await refreshData();
  }
};

const showReadRecords = (record: ArticleApi.ArticleItem) => {
  readingArticle.value = record;
  readDrawerOpen.value = true;
};

const coverUrl = (record: ArticleApi.ArticleItem) =>
  record.cover_full_url ||
  (typeof record.cover === 'string' ? record.cover : '');

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  {
    title: '封面',
    dataIndex: 'cover',
    width: 86,
    customRender: ({ record }: { record: ArticleApi.ArticleItem }) => {
      const url = coverUrl(record);
      if (!url) return '-';
      return h('img', {
        alt: record.title,
        class: 'article-cover',
        src: url,
      });
    },
  },
  { title: '标题', dataIndex: 'title', ellipsis: true, width: 260 },
  { title: '分类', dataIndex: 'category_name', width: 130 },
  { title: '阅读量', dataIndex: 'read_count', width: 100 },
  { title: '排序', dataIndex: 'sort', width: 90 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: { record: ArticleApi.ArticleItem }) => {
      if (!hasAccessByCodes(['SystemArticleUpdateStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: boolean | number | string) =>
          handleStatusChange(record, checked),
      });
    },
  },
  { title: '更新时间', dataIndex: 'update_time', width: 170 },
  { title: '操作', key: 'action', fixed: 'right', width: 240 },
];

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current ?? pagination.current;
  pagination.pageSize = newPagination.pageSize ?? pagination.pageSize;
  refreshData();
};

onMounted(async () => {
  await loadCategories();
  refreshData();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">文章管理</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemArticleCreate'"
        >
          新增文章
        </a-button>
        <a-button @click="refreshData">刷新</a-button>
      </div>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item class="mb-0" label="关键词">
          <a-input
            v-model:value="searchParams.keyword"
            allow-clear
            class="w-full"
            placeholder="标题 / 描述"
            @press-enter="submitSearch"
          />
        </a-form-item>
        <a-form-item class="mb-0" label="分类">
          <a-select
            v-model:value="searchParams.category_id"
            allow-clear
            class="w-full"
            placeholder="全部分类"
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
        <a-form-item class="mb-0" label="状态">
          <a-select
            v-model:value="searchParams.status"
            allow-clear
            class="w-full"
            placeholder="请选择"
          >
            <a-select-option :value="1">启用</a-select-option>
            <a-select-option :value="0">禁用</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-3">
          <div class="flex justify-end gap-2">
            <a-button type="primary" @click="submitSearch">搜索</a-button>
            <a-button @click="resetSearch">重置</a-button>
          </div>
        </a-form-item>
      </a-form>
    </div>

    <div class="overflow-hidden rounded-lg border bg-[hsl(var(--card))]">
      <a-table
        :columns="columns"
        :data-source="tableData"
        :loading="loading"
        :pagination="pagination"
        row-key="id"
        :scroll="{ x: 1180 }"
        @change="handleTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                size="small"
                type="link"
                @click="handleEdit(record)"
                v-access:code="'SystemArticleUpdate'"
              >
                编辑
              </a-button>
              <a-button
                size="small"
                type="link"
                @click="showReadRecords(record)"
                v-access:code="'SystemArticleReadRecords'"
              >
                阅读记录
              </a-button>
              <a-button
                danger
                size="small"
                type="link"
                @click="handleDelete(record)"
                v-access:code="'SystemArticleDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <ReadRecordDrawer
      v-model:open="readDrawerOpen"
      :article-id="readingArticle?.id"
      :article-title="readingArticle?.title"
    />
  </div>
</template>

<style scoped>
.article-cover {
  width: 56px;
  height: 40px;
  object-fit: cover;
  border-radius: 4px;
}
</style>
