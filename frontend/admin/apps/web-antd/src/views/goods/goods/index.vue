<script lang="ts" setup>
import type { GoodsApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { useAccess } from '@vben/access';

import { Avatar, message, Modal, Switch } from 'ant-design-vue';

import {
  deleteGoodsApi,
  exportGoodsCsvApi,
  getAllGoodsBrandsApi,
  getAllGoodsCategoriesApi,
  getGoodsListApi,
  getGoodsStatsApi,
  purgeGoodsApi,
  restoreGoodsApi,
  updateGoodsOnSaleApi,
  updateGoodsStatusApi,
} from '#/api/goods';
import { useTableCrud } from '#/composables/useTableCrud';
import { downloadBlob } from '#/utils/download';

defineOptions({ name: 'GoodsManagement' });

const route = useRoute();
const router = useRouter();
const { hasAccessByCodes } = useAccess();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData } = useTableCrud<
  GoodsApi.GoodsItem,
  GoodsApi.ListParams
>(
  {
    delete: deleteGoodsApi,
    list: getGoodsListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
const searchParams = ref({
  keyword: '',
  category_id: undefined as number | undefined,
  brand_id: undefined as number | undefined,
  is_on_sale: undefined as number | undefined,
  status: undefined as number | undefined,
  stock_warning: undefined as 0 | 1 | undefined,
  view: 'all' as GoodsApi.ListView,
});
const activeViewTab = ref<GoodsApi.ListView>('all');
const statsTabs = ref<GoodsApi.StatsTab[]>([]);

const buildQuery = (): GoodsApi.ListParams => ({ ...searchParams.value });

/* ---------------- 分类树数据 ---------------- */
const categoryTreeData = ref<any[]>([]);

const buildTree = (list: any[], pid: number = 0): any[] => {
  return list
    .filter((item) => item.pid === pid)
    .map((item) => ({
      title: item.name,
      value: item.id,
      key: item.id,
      children: buildTree(list, item.id),
    }));
};

const loadCategories = async () => {
  try {
    const list = await getAllGoodsCategoriesApi();
    categoryTreeData.value = buildTree(list);
  } catch (error) {
    console.error('加载分类失败:', error);
  }
};

/* ---------------- 品牌数据 ---------------- */
const brandOptions = ref<{ label: string; value: number }[]>([]);

const loadBrands = async () => {
  try {
    const list = await getAllGoodsBrandsApi();
    brandOptions.value = list.map((item) => ({
      label: item.name,
      value: item.id,
    }));
  } catch (error) {
    console.error('加载品牌失败:', error);
  }
};

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    category_id: undefined,
    brand_id: undefined,
    is_on_sale: undefined,
    status: undefined,
    stock_warning: undefined,
    view: 'all',
  };
  activeViewTab.value = 'all';
  pagination.current = 1;
  refreshData();
};

const submitSearch = () => {
  pagination.current = 1;
  refreshData();
};

const loadStats = async () => {
  const res = await getGoodsStatsApi(buildQuery());
  statsTabs.value = res?.tabs ?? [];
};

const refreshData = async () => {
  await Promise.all([loadData(buildQuery()), loadStats()]);
};

const routeStringQuery = (value: unknown): string | undefined => {
  const raw = Array.isArray(value) ? value[0] : value;
  return typeof raw === 'string' && raw !== '' ? raw : undefined;
};

const routeNumberQuery = (value: unknown): number | undefined => {
  const raw = routeStringQuery(value);
  if (raw === undefined) {
    return undefined;
  }
  const numeric = Number(raw);
  return Number.isNaN(numeric) ? undefined : numeric;
};

const applyRouteQuery = () => {
  const view = routeStringQuery(route.query.view) as GoodsApi.ListView;
  if (['all', 'disabled', 'off_sale', 'on_sale', 'recycle'].includes(view)) {
    activeViewTab.value = view;
    searchParams.value.view = view;
  }

  const stockWarning = routeNumberQuery(route.query.stock_warning);
  if (stockWarning === 1) {
    searchParams.value.stock_warning = 1;
  }
};

const handleViewTabChange = (key: string) => {
  const view = key as GoodsApi.ListView;
  activeViewTab.value = view;
  searchParams.value.view = view;
  pagination.current = 1;
  refreshData();
};

const handleExport = async () => {
  try {
    const blob = await exportGoodsCsvApi(buildQuery());
    downloadBlob(blob, 'goods.csv');
  } catch (error: any) {
    message.error(error?.message || '导出失败');
  }
};

const handleTableChange = (newPagination: {
  current?: number;
  pageSize?: number;
}) => {
  pagination.current = newPagination.current ?? pagination.current;
  pagination.pageSize = newPagination.pageSize ?? pagination.pageSize;
  loadData(buildQuery());
};

/* ---------------- 路由跳转 ---------------- */
const handleCreate = () => {
  router.push('/goods/edit');
};
const handleEdit = (record: GoodsApi.GoodsItem) => {
  router.push(`/goods/edit?id=${record.id}`);
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: GoodsApi.GoodsItem,
  checked: boolean | number | string,
) => {
  try {
    await updateGoodsStatusApi(record.id, checked === true ? 1 : 0);
    message.success('状态更新成功');
    await refreshData();
  } catch (error: any) {
    message.error(error?.message || '状态更新失败');
    await refreshData();
  }
};

/* ---------------- 上架状态切换 ---------------- */
const handleOnSaleChange = async (
  record: GoodsApi.GoodsItem,
  checked: boolean | number | string,
) => {
  try {
    await updateGoodsOnSaleApi(record.id, checked === true ? 1 : 0);
    message.success(checked === true ? '上架成功' : '下架成功');
    await refreshData();
  } catch (error: any) {
    message.error(
      error?.message || (checked === true ? '上架失败' : '下架失败'),
    );
    await refreshData();
  }
};

const handleMoveToRecycle = (record: GoodsApi.GoodsItem) => {
  Modal.confirm({
    title: '删除商品',
    content: `确认将商品「${record.name}」移入回收站？`,
    okText: '确认删除',
    okButtonProps: { danger: true },
    cancelText: '取消',
    onOk: async () => {
      await deleteGoodsApi(record.id);
      message.success('商品已移入回收站');
      await refreshData();
    },
  });
};

const handleRestore = async (record: GoodsApi.GoodsItem) => {
  await restoreGoodsApi(record.id);
  message.success('商品已恢复');
  await refreshData();
};

const handlePurge = (record: GoodsApi.GoodsItem) => {
  Modal.confirm({
    title: '永久删除商品',
    content: `确认永久删除商品「${record.name}」？该操作不可恢复。`,
    okText: '永久删除',
    okButtonProps: { danger: true },
    cancelText: '取消',
    onOk: async () => {
      await purgeGoodsApi(record.id);
      message.success('商品已永久删除');
      await refreshData();
    },
  });
};

/* ---------------- 表格列 ---------------- */
const columns = [
  { title: 'ID', dataIndex: 'id', width: 70 },
  {
    title: '主图',
    dataIndex: 'main_image',
    width: 80,
    customRender: ({ record }: { record: GoodsApi.GoodsItem }) => {
      if (!record.main_image) return '-';
      return h(Avatar, {
        src: record.main_image_full_url || String(record.main_image),
        size: 40,
        shape: 'square',
      });
    },
  },
  { title: '商品名称', dataIndex: 'name', width: 180, ellipsis: true },
  { title: '分类', dataIndex: 'category_name', width: 120 },
  { title: '品牌', dataIndex: 'brand_name', width: 100 },
  { title: '价格', dataIndex: 'price', width: 90 },
  { title: '库存', dataIndex: 'stock', width: 80 },
  {
    title: '上架',
    dataIndex: 'is_on_sale',
    width: 90,
    customRender: ({ record }: { record: GoodsApi.GoodsItem }) => {
      if (!hasAccessByCodes(['SystemGoodsUpdateOnSale'])) {
        return record.is_on_sale === 1 ? '上架' : '下架';
      }
      if (activeViewTab.value === 'recycle') {
        return record.is_on_sale === 1 ? '上架' : '下架';
      }
      return h(Switch, {
        checked: record.is_on_sale === 1,
        checkedChildren: '上架',
        unCheckedChildren: '下架',
        onChange: (checked: boolean | number | string) =>
          handleOnSaleChange(record, checked),
      });
    },
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: GoodsApi.GoodsItem }) => {
      if (!hasAccessByCodes(['SystemGoodsUpdateStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }
      if (activeViewTab.value === 'recycle') {
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
  { title: '创建时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', key: 'action', fixed: 'right', width: 200 },
];

/* ---------------- 初始化 ---------------- */
onMounted(() => {
  applyRouteQuery();
  refreshData();
  loadCategories();
  loadBrands();
});
</script>

<template>
  <div class="goods-page p-4">
    <div class="goods-header">
      <div>
        <h2 class="goods-title">商品列表</h2>
      </div>
      <div class="goods-header-actions">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SystemGoodsCreate'"
        >
          新增商品
        </a-button>
        <a-button @click="refreshData">刷新</a-button>
        <a-button v-access:code="'SystemGoodsExport'" @click="handleExport">
          导出
        </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="goods-filter-panel">
      <a-form>
        <div
          class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
        >
          <div>
            <a-form-item class="mb-0" label="关键词">
              <a-input
                v-model:value="searchParams.keyword"
                placeholder="商品名称/关键词"
                allow-clear
                class="w-full"
                @press-enter="submitSearch"
              />
            </a-form-item>
          </div>
          <div>
            <a-form-item class="mb-0" label="分类">
              <a-tree-select
                v-model:value="searchParams.category_id"
                :tree-data="categoryTreeData"
                placeholder="请选择分类"
                allow-clear
                class="w-full"
              />
            </a-form-item>
          </div>
          <div>
            <a-form-item class="mb-0" label="品牌">
              <a-select
                v-model:value="searchParams.brand_id"
                placeholder="请选择品牌"
                allow-clear
                class="w-full"
              >
                <a-select-option
                  v-for="brand in brandOptions"
                  :key="brand.value"
                  :value="brand.value"
                >
                  {{ brand.label }}
                </a-select-option>
              </a-select>
            </a-form-item>
          </div>
        </div>
        <div class="mt-3 flex justify-end gap-2">
          <a-button type="primary" @click="submitSearch">搜索</a-button>
          <a-button @click="resetSearch">重置</a-button>
        </div>
      </a-form>
    </div>

    <div class="goods-table-panel">
      <a-tabs
        :active-key="activeViewTab"
        class="goods-status-tabs"
        size="small"
        @change="handleViewTabChange"
      >
        <a-tab-pane v-for="tab in statsTabs" :key="tab.key">
          <template #tab>
            <span>{{ tab.label }} {{ tab.count }}</span>
          </template>
        </a-tab-pane>
      </a-tabs>

      <a-table
        :columns="columns"
        :data-source="tableData"
        :loading="loading"
        :pagination="pagination"
        :scroll="{ x: 1500 }"
        row-key="id"
        @change="handleTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space v-if="activeViewTab !== 'recycle'">
              <a-button
                type="link"
                size="small"
                @click="handleEdit(record)"
                v-access:code="'SystemGoodsUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleMoveToRecycle(record)"
                v-access:code="'SystemGoodsDelete'"
              >
                删除
              </a-button>
            </a-space>
            <a-space v-else>
              <a-button
                type="link"
                size="small"
                @click="handleRestore(record)"
                v-access:code="'SystemGoodsRestore'"
              >
                恢复
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handlePurge(record)"
                v-access:code="'SystemGoodsPurge'"
              >
                永久删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>
  </div>
</template>

<style scoped>
.goods-page {
  min-height: 100%;
}

.goods-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 12px;
}

.goods-title {
  margin: 0;
  color: hsl(var(--foreground));
  font-size: 18px;
  font-weight: 600;
  line-height: 32px;
}

.goods-header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.goods-filter-panel,
.goods-table-panel {
  background: hsl(var(--card));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.goods-filter-panel {
  padding: 16px;
  margin-bottom: 12px;
}

.goods-table-panel {
  overflow: hidden;
}

.goods-status-tabs {
  padding: 0 16px;
  margin-bottom: 0;
}

.goods-status-tabs :deep(.ant-tabs-nav) {
  margin-bottom: 0;
}

.goods-status-tabs :deep(.ant-tabs-tab) {
  padding: 14px 0 12px;
}

.goods-table-panel :deep(.ant-table-wrapper) {
  border-top: 1px solid hsl(var(--border));
}
</style>
