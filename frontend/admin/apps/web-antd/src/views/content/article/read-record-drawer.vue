<script lang="ts" setup>
import type { ArticleApi } from '#/api/content';

import { reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getArticleReadRecordsApi } from '#/api/content';

const props = withDefaults(
  defineProps<{
    articleId?: number;
    articleTitle?: string;
    open: boolean;
  }>(),
  {
    articleId: 0,
    articleTitle: '',
    open: false,
  },
);

const emit = defineEmits<{
  'update:open': [value: boolean];
}>();

const loading = ref(false);
const tableData = ref<ArticleApi.ReadRecordItem[]>([]);
const searchParams = reactive({
  keyword: '',
  read_range: [] as string[],
});
const pagination = reactive({
  current: 1,
  pageSize: 10,
  total: 0,
  showSizeChanger: true,
  showTotal: (total: number) => `共 ${total} 条`,
});

const columns = [
  { title: '用户', key: 'user', width: 230 },
  { title: '阅读次数', dataIndex: 'read_count', width: 100 },
  { title: '首次阅读', dataIndex: 'first_read_time', width: 170 },
  { title: '最近阅读', dataIndex: 'last_read_time', width: 170 },
];

const buildParams = (): ArticleApi.ReadRecordParams => ({
  article_id: props.articleId,
  end_time: searchParams.read_range?.[1],
  keyword: searchParams.keyword || undefined,
  limit: pagination.pageSize,
  page: pagination.current,
  start_time: searchParams.read_range?.[0],
});

const loadData = async () => {
  if (!props.articleId) return;
  loading.value = true;
  try {
    const result = await getArticleReadRecordsApi(buildParams());
    tableData.value = result.list;
    pagination.total = result.total;
  } catch (error: any) {
    message.error(error?.message || '加载阅读记录失败');
  } finally {
    loading.value = false;
  }
};

const submitSearch = () => {
  pagination.current = 1;
  loadData();
};

const resetSearch = () => {
  searchParams.keyword = '';
  searchParams.read_range = [];
  pagination.current = 1;
  loadData();
};

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current ?? pagination.current;
  pagination.pageSize = newPagination.pageSize ?? pagination.pageSize;
  loadData();
};

watch(
  () => props.open,
  (open) => {
    if (!open) return;
    searchParams.keyword = '';
    searchParams.read_range = [];
    pagination.current = 1;
    loadData();
  },
);

const getUserSubText = (record: ArticleApi.ReadRecordItem) => {
  if (record.user_id === 0) {
    return '未登录访问';
  }

  return record.user_mobile || record.user_email || `ID ${record.user_id}`;
};
</script>

<template>
  <a-drawer
    :open="open"
    placement="right"
    :title="`阅读记录${articleTitle ? `：${articleTitle}` : ''}`"
    width="720"
    @close="emit('update:open', false)"
  >
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-2">
        <a-form-item class="mb-0" label="用户">
          <a-input
            v-model:value="searchParams.keyword"
            allow-clear
            placeholder="昵称 / 手机 / 邮箱"
            @press-enter="submitSearch"
          />
        </a-form-item>
        <a-form-item class="mb-0" label="阅读时间">
          <a-range-picker
            v-model:value="searchParams.read_range"
            class="w-full"
            show-time
            value-format="YYYY-MM-DD HH:mm:ss"
          />
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-2">
          <div class="flex justify-end gap-2">
            <a-button type="primary" @click="submitSearch">搜索</a-button>
            <a-button @click="resetSearch">重置</a-button>
          </div>
        </a-form-item>
      </a-form>
    </div>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      row-key="id"
      :scroll="{ x: 660 }"
      @change="handleTableChange"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'user'">
          <div class="flex items-center gap-2">
            <a-avatar :src="record.user_avatar_full_url" :size="32">
              {{ (record.user_nickname || '用户').slice(0, 1) }}
            </a-avatar>
            <div class="min-w-0">
              <div class="truncate">
                {{ record.user_nickname || '已注销用户' }}
              </div>
              <div class="truncate text-xs text-gray-500">
                {{ getUserSubText(record) }}
              </div>
            </div>
          </div>
        </template>
      </template>
    </a-table>
  </a-drawer>
</template>
