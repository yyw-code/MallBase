<script lang="ts" setup>
import type { PointsGoodsApi } from '#/api/points';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  deletePointsGoodsApi,
  getPointsGoodsInfoApi,
  getPointsGoodsListApi,
  updatePointsGoodsStatusApi,
} from '#/api/points';
import { useTableCrud } from '#/composables/useTableCrud';

import GoodsModal from './goods-modal.vue';

defineOptions({ name: 'PointsGoodsManagement' });

const { hasAccessByCodes } = useAccess();

const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  PointsGoodsApi.GoodsItem,
  PointsGoodsApi.ListParams
>(
  {
    delete: deletePointsGoodsApi,
    list: getPointsGoodsListApi,
  },
  { immediateLoad: false },
);

const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
});

const modalVisible = ref(false);
const editingItem = ref<null | PointsGoodsApi.GoodsItem>(null);
const imageErrorKeys = ref<Record<string, true>>({});

const normalizeImageUrl = (raw?: string) => {
  const value = String(raw || '');
  return value && !/^\d+$/.test(value) ? value : '';
};

const goodsImageUrl = (record: PointsGoodsApi.GoodsItem) =>
  record.goods_image_full_url || normalizeImageUrl(record.goods_image);

const imageKey = (record: PointsGoodsApi.GoodsItem) =>
  `${record.id}:${goodsImageUrl(record)}`;

const hasGoodsImage = (record: PointsGoodsApi.GoodsItem) =>
  !!goodsImageUrl(record) && !imageErrorKeys.value[imageKey(record)];

const markImageError = (record: PointsGoodsApi.GoodsItem) => {
  imageErrorKeys.value = {
    ...imageErrorKeys.value,
    [imageKey(record)]: true,
  };
};

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const handleCreate = () => {
  editingItem.value = null;
  modalVisible.value = true;
};

const handleEdit = async (record: PointsGoodsApi.GoodsItem) => {
  try {
    editingItem.value = await getPointsGoodsInfoApi(record.id);
    modalVisible.value = true;
  } catch (error) {
    console.error('获取积分商品详情失败:', error);
    message.error('获取积分商品详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

const handleStatusChange = async (
  record: PointsGoodsApi.GoodsItem,
  checked: boolean | number | string,
) => {
  try {
    await updatePointsGoodsStatusApi(record.id, checked === true ? 1 : 0);
    message.success('状态更新成功');
    await loadData(searchParams.value);
  } catch {
    await loadData(searchParams.value);
  }
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '商品', key: 'goods', width: 320 },
  {
    title: '兑换积分',
    dataIndex: 'points_price',
    width: 120,
    customRender: ({ record }: { record: PointsGoodsApi.GoodsItem }) =>
      `${record.points_price} 积分`,
  },
  {
    title: '兑换库存',
    dataIndex: 'exchange_stock',
    width: 120,
    customRender: ({ record }: { record: PointsGoodsApi.GoodsItem }) =>
      `${record.available_stock ?? record.exchange_stock}/${record.exchange_stock}`,
  },
  { title: '已兑换', dataIndex: 'exchanged_count', width: 100 },
  {
    title: '每人限兑',
    dataIndex: 'limit_per_user',
    width: 110,
    customRender: ({ record }: { record: PointsGoodsApi.GoodsItem }) =>
      record.limit_per_user > 0 ? record.limit_per_user : '不限制',
  },
  { title: '排序', dataIndex: 'sort', width: 90 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: { record: PointsGoodsApi.GoodsItem }) => {
      if (!hasAccessByCodes(['SystemPointsGoodsUpdateStatus'])) {
        return h(
          Tag,
          { color: record.status === 1 ? 'green' : 'default' },
          () => (record.status === 1 ? '启用' : '禁用'),
        );
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
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', width: 150, fixed: 'right' },
];

onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">积分商品</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          v-access:code="'SystemPointsGoodsCreate'"
          type="primary"
          @click="handleCreate"
        >
          新增商品
        </a-button>
        <a-button @click="() => loadData(searchParams)">刷新</a-button>
      </div>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="关键词" class="mb-0">
          <a-input
            v-model:value="searchParams.keyword"
            allow-clear
            class="w-full"
            placeholder="商品名称或积分商品ID"
          />
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
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
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
          <div class="flex justify-end gap-2">
            <a-button
              type="primary"
              @click="
                () => {
                  pagination.current = 1;
                  loadData(searchParams);
                }
              "
            >
              搜索
            </a-button>
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
        :scroll="{ x: 1320 }"
        row-key="id"
        @change="
          (newPagination: any) => {
            pagination.current = newPagination.current;
            pagination.pageSize = newPagination.pageSize;
            loadData(searchParams);
          }
        "
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'goods'">
            <div class="flex items-center gap-3">
              <img
                v-if="hasGoodsImage(record)"
                :src="goodsImageUrl(record)"
                class="h-12 w-12 shrink-0 rounded object-cover"
                @error="markImageError(record)"
              />
              <div
                v-else
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded bg-muted text-[10px] text-muted-foreground"
              >
                暂无图
              </div>
              <div class="min-w-0">
                <div class="truncate font-medium">
                  {{ record.goods_name || '-' }}
                </div>
                <div class="mt-1 text-xs text-muted-foreground">
                  SKU：{{ record.sku_spec || '默认规格' }} / 售价
                  {{ record.sku_price || record.goods_price || '-' }}
                </div>
                <div class="mt-1 flex flex-wrap gap-1">
                  <a-tag
                    :color="record.goods_is_on_sale === 1 ? 'green' : 'default'"
                  >
                    {{ record.goods_is_on_sale === 1 ? '已上架' : '未上架' }}
                  </a-tag>
                  <a-tag :color="record.sku_status === 1 ? 'blue' : 'default'">
                    SKU库存 {{ record.sku_stock ?? 0 }}
                  </a-tag>
                </div>
              </div>
            </div>
          </template>

          <template v-else-if="column.key === 'action'">
            <a-space>
              <a-button
                v-access:code="'SystemPointsGoodsUpdate'"
                size="small"
                type="link"
                @click="handleEdit(record)"
              >
                编辑
              </a-button>
              <a-button
                v-access:code="'SystemPointsGoodsDelete'"
                danger
                size="small"
                type="link"
                @click="handleDelete(record, 'goods_name')"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <GoodsModal
      v-model:visible="modalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
