<script lang="ts" setup>
import type { GoodsApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';

import { useRouter } from 'vue-router';

import { Avatar, message, Switch } from 'ant-design-vue';

import {
  deleteGoodsApi,
  getAllGoodsBrandsApi,
  getAllGoodsCategoriesApi,
  getGoodsListApi,
  updateGoodsOnSaleApi,
  updateGoodsStatusApi,
} from '#/api/goods';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'GoodsManagement' });

const router = useRouter();

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
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
});

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
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

/* ---------------- 路由跳转 ---------------- */
const handleCreate = () => { router.push('/goods/edit'); };
const handleEdit = (record: GoodsApi.GoodsItem) => { router.push(`/goods/edit?id=${record.id}`); };

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: GoodsApi.GoodsItem,
  checked: boolean,
) => {
  try {
    await updateGoodsStatusApi(record.id, checked ? 1 : 0);
    message.success('状态更新成功');
    await loadData(searchParams.value);
  } catch (error: any) {
    message.error(error?.message || '状态更新失败');
    await loadData(searchParams.value);
  }
};

/* ---------------- 上架状态切换 ---------------- */
const handleOnSaleChange = async (
  record: GoodsApi.GoodsItem,
  checked: boolean,
) => {
  try {
    await updateGoodsOnSaleApi(record.id, checked ? 1 : 0);
    message.success(checked ? '上架成功' : '下架成功');
    await loadData(searchParams.value);
  } catch (error: any) {
    message.error(error?.message || (checked ? '上架失败' : '下架失败'));
    await loadData(searchParams.value);
  }
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
        src: record.main_image_full_url || record.main_image,
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
      return h(Switch, {
        checked: record.is_on_sale === 1,
        checkedChildren: '上架',
        unCheckedChildren: '下架',
        onChange: (checked: boolean) => handleOnSaleChange(record, checked),
      });
    },
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: GoodsApi.GoodsItem }) => {
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: (checked: boolean) => handleStatusChange(record, checked),
      });
    },
  },
  { title: '创建时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', key: 'action', fixed: 'right', width: 200 },
];

/* ---------------- 初始化 ---------------- */
onMounted(() => {
  loadData(searchParams.value);
  loadCategories();
  loadBrands();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button type="primary" @click="handleCreate"> 新增商品 </a-button>
      <a-button class="ml-2" @click="() => loadData(searchParams.value)">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="商品名称/关键词"
          allow-clear
          style="width: 180px"
        />
      </a-form-item>
      <a-form-item label="分类">
        <a-tree-select
          v-model:value="searchParams.category_id"
          :tree-data="categoryTreeData"
          placeholder="请选择分类"
          allow-clear
          style="width: 180px"
        />
      </a-form-item>
      <a-form-item label="品牌">
        <a-select
          v-model:value="searchParams.brand_id"
          placeholder="请选择品牌"
          allow-clear
          style="width: 150px"
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
      <a-form-item label="上架状态">
        <a-select
          v-model:value="searchParams.is_on_sale"
          placeholder="请选择"
          allow-clear
          style="width: 120px"
        >
          <a-select-option :value="1">已上架</a-select-option>
          <a-select-option :value="0">已下架</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          placeholder="请选择"
          allow-clear
          style="width: 120px"
        >
          <a-select-option :value="1">启用</a-select-option>
          <a-select-option :value="0">禁用</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item>
        <a-button
          type="primary"
          @click="
            () => {
              pagination.current = 1;
              loadData(searchParams.value);
            }
          "
        >
          搜索
        </a-button>
        <a-button class="ml-2" @click="resetSearch"> 重置 </a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1500 }"
      row-key="id"
      @change="
        (newPagination) => {
          pagination.current = newPagination.current;
          pagination.pageSize = newPagination.pageSize;
          loadData(searchParams.value);
        }
      "
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button type="link" size="small" @click="handleEdit(record)">
              编辑
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'name')"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>

  </div>
</template>
