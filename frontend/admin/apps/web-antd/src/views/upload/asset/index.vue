<script lang="ts" setup>
import type { UploadAssetApi } from '#/api/upload/asset';

import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import { Image, message, Modal, Tag } from 'ant-design-vue';

import { getUploadOptionsCached } from '#/api/core/upload-config-cache';
import {
  deleteUploadAssetApi,
  getUploadAssetCategoryTreeApi,
  getUploadAssetListApi,
  purgeUploadAssetApi,
  restoreUploadAssetApi,
} from '#/api/upload/asset';

defineOptions({ name: 'UploadAssetManagement' });

const loading = ref(false);
const route = useRoute();
const tableData = ref<UploadAssetApi.AssetItem[]>([]);
const categories = ref<UploadAssetApi.CategoryItem[]>([]);
const assetTypeOptions = ref<{ label: string; value: string }[]>([]);
const driverOptions = ref<{ label: string; value: string }[]>([]);
const currentDriverLabel = ref('');
const pagination = reactive({ current: 1, pageSize: 20, total: 0 });
const searchParams = reactive({
  keyword: '',
  category_id: undefined as number | undefined,
  driver: undefined as string | undefined,
  type: undefined as string | undefined,
  status: 1,
});

const isRecyclePage = computed(() =>
  route.path.includes('/upload/asset/recycle'),
);
const routeStatus = computed(() => (isRecyclePage.value ? 0 : 1));

const categoryOptions = () =>
  categories.value.map((item) => ({
    label: item.name,
    value: item.id,
    children: item.children?.map((child) => ({
      label: child.name,
      value: child.id,
    })),
  }));

const loadCategories = async () => {
  categories.value = await getUploadAssetCategoryTreeApi({ status: 1 });
};

const loadUploadOptions = async () => {
  try {
    const options = await getUploadOptionsCached();
    assetTypeOptions.value = (options.asset_types || []).map((item) => ({
      label: item.label,
      value: item.value,
    }));
    driverOptions.value = (options.upload_drivers || []).map((item) => ({
      label: item.label,
      value: item.value,
    }));
    currentDriverLabel.value =
      options.upload_drivers?.find((item) => item.enabled)?.label || '';
  } catch (error) {
    console.warn('加载上传选项失败:', error);
    assetTypeOptions.value = [];
    driverOptions.value = [];
    currentDriverLabel.value = '';
    message.error('上传配置加载失败');
  }
};

const loadData = async () => {
  searchParams.status = routeStatus.value;
  loading.value = true;
  try {
    const res = await getUploadAssetListApi({
      ...searchParams,
      page: pagination.current,
      limit: pagination.pageSize,
    });
    tableData.value = res.list || [];
    pagination.total = res.total || 0;
  } finally {
    loading.value = false;
  }
};

const resetSearch = () => {
  Object.assign(searchParams, {
    keyword: '',
    category_id: undefined,
    driver: undefined,
    type: undefined,
    status: routeStatus.value,
  });
  pagination.current = 1;
  loadData();
};

const handleTableChange = (pager: { current?: number; pageSize?: number }) => {
  pagination.current = pager.current || pagination.current;
  pagination.pageSize = pager.pageSize || pagination.pageSize;
  loadData();
};

const confirmAction = (
  title: string,
  content: string,
  action: () => Promise<any>,
) => {
  Modal.confirm({
    title,
    content,
    async onOk() {
      await action();
      message.success('操作成功');
      await loadData();
    },
  });
};

const handleDelete = (record: UploadAssetApi.AssetItem) =>
  confirmAction('删除素材', '无引用素材会进入回收站，确认删除吗？', () =>
    deleteUploadAssetApi(record.id),
  );

const handleRestore = (record: UploadAssetApi.AssetItem) =>
  confirmAction('恢复素材', '确认从回收站恢复该素材吗？', () =>
    restoreUploadAssetApi(record.id),
  );

const handlePurge = (record: UploadAssetApi.AssetItem) =>
  confirmAction('永久删除', '永久删除会清理存储对象，确认继续吗？', () =>
    purgeUploadAssetApi(record.id),
  );

const renderPreview = (record: UploadAssetApi.AssetItem) => {
  if (record.type === 'image' && record.full_url) {
    return h(Image, {
      src: record.full_url,
      width: 48,
      height: 48,
      style: {
        borderRadius: '6px',
        objectFit: 'cover',
      },
    });
  }
  return formatAssetType(record.type);
};

const formatAssetType = (type: string) =>
  assetTypeOptions.value.find((item) => item.value === type)?.label || type;

const formatDriver = (driver?: string) =>
  driverOptions.value.find((item) => item.value === driver)?.label ||
  driver ||
  '-';

const columns = [
  {
    title: '预览',
    key: 'preview',
    width: 80,
    customRender: ({ record }: any) => renderPreview(record),
  },
  {
    title: '名称',
    dataIndex: 'original_name',
    width: 220,
    ellipsis: true,
    customRender: ({ record }: any) => record.original_name || record.name,
  },
  { title: '分类', dataIndex: 'category_name', width: 120 },
  {
    title: '类型',
    dataIndex: 'type',
    width: 90,
    customRender: ({ text }: any) => formatAssetType(text),
  },
  {
    title: '驱动',
    dataIndex: 'driver',
    width: 90,
    customRender: ({ text }: any) => formatDriver(text),
  },
  {
    title: '大小',
    dataIndex: 'size',
    width: 110,
    customRender: ({ text }: any) =>
      `${Math.round(Number(text || 0) / 1024)} KB`,
  },
  { title: '引用', dataIndex: 'usage_count', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: any) =>
      h(Tag, { color: record.status === 1 ? 'green' : 'orange' }, () =>
        record.status === 1 ? '正常' : '回收站',
      ),
  },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', width: 210 },
];
const tableScroll = { x: 1280 };

onMounted(async () => {
  searchParams.status = routeStatus.value;
  await Promise.all([loadCategories(), loadUploadOptions()]);
  await loadData();
});

watch(
  () => route.path,
  () => {
    searchParams.status = routeStatus.value;
    pagination.current = 1;
    loadData();
  },
);
</script>

<template>
  <div class="asset-page">
    <div class="asset-toolbar">
      <a-input
        v-model:value="searchParams.keyword"
        placeholder="关键词"
        allow-clear
        class="asset-toolbar__item"
      />
      <a-tree-select
        v-model:value="searchParams.category_id"
        :tree-data="categoryOptions()"
        placeholder="分类"
        allow-clear
        tree-default-expand-all
        class="asset-toolbar__item"
      />
      <a-select
        v-model:value="searchParams.type"
        placeholder="类型"
        allow-clear
        class="asset-toolbar__item"
        :options="assetTypeOptions"
      />
      <a-select
        v-model:value="searchParams.driver"
        placeholder="上传驱动"
        allow-clear
        class="asset-toolbar__item"
        :options="driverOptions"
      />
      <span v-if="currentDriverLabel" class="asset-toolbar__hint">
        当前驱动：{{ currentDriverLabel }}
      </span>
      <a-button
        type="primary"
        @click="loadData"
        v-access:code="'SystemUploadAssetList'"
        >查询</a-button
      >
      <a-button @click="resetSearch">重置</a-button>
    </div>

    <a-table
      row-key="id"
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="tableScroll"
      @change="handleTableChange"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'action'">
          <a-button
            v-if="record.status === 1"
            type="link"
            size="small"
            @click="handleDelete(record)"
            v-access:code="'SystemUploadAssetDelete'"
          >
            删除
          </a-button>
          <a-button
            v-if="record.status === 0"
            type="link"
            size="small"
            @click="handleRestore(record)"
            v-access:code="'SystemUploadAssetRestore'"
          >
            恢复
          </a-button>
          <a-button
            v-if="record.status === 0"
            type="link"
            danger
            size="small"
            @click="handlePurge(record)"
            v-access:code="'SystemUploadAssetPurge'"
          >
            永久删除
          </a-button>
        </template>
      </template>
    </a-table>
  </div>
</template>

<style scoped>
.asset-page {
  padding: 16px;
}

.asset-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
}

.asset-toolbar__item {
  width: 180px;
}

.asset-toolbar__hint {
  display: inline-flex;
  align-items: center;
  height: 32px;
  font-size: 13px;
  color: hsl(var(--muted-foreground));
}
</style>
