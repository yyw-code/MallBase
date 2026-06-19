<script lang="ts" setup>
import type { RechargePackageApi } from '#/api/marketing';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  deleteRechargePackageApi,
  getRechargePackageInfoApi,
  getRechargePackageListApi,
  updateRechargePackageStatusApi,
} from '#/api/marketing';
import { useTableCrud } from '#/composables/useTableCrud';

import PackageModal from './package-modal.vue';

defineOptions({ name: 'RechargePackageManagement' });

const { hasAccessByCodes } = useAccess();

const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  RechargePackageApi.PackageItem,
  RechargePackageApi.ListParams
>(
  {
    delete: deleteRechargePackageApi,
    list: getRechargePackageListApi,
  },
  { immediateLoad: false },
);

const searchParams = ref({
  name: '',
  status: undefined as number | undefined,
});

const modalVisible = ref(false);
const editingItem = ref<null | RechargePackageApi.PackageItem>(null);

const resetSearch = () => {
  searchParams.value = {
    name: '',
    status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

const handleCreate = () => {
  editingItem.value = null;
  modalVisible.value = true;
};

const handleEdit = async (record: RechargePackageApi.PackageItem) => {
  try {
    editingItem.value = await getRechargePackageInfoApi(record.id);
    modalVisible.value = true;
  } catch (error) {
    console.error('获取充值套餐详情失败:', error);
    message.error('获取充值套餐详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

const handleStatusChange = async (
  record: RechargePackageApi.PackageItem,
  checked: boolean | number | string,
) => {
  try {
    await updateRechargePackageStatusApi(record.id, checked === true ? 1 : 0);
    message.success('状态更新成功');
    await loadData(searchParams.value);
  } catch {
    await loadData(searchParams.value);
  }
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  {
    title: '背景',
    dataIndex: 'background_image',
    width: 120,
    customRender: ({ record }: { record: RechargePackageApi.PackageItem }) => {
      const src = record.background_image_full_url || record.background_image;
      if (!src) return '-';
      return h('img', {
        src,
        class: 'h-10 w-20 rounded object-cover',
        alt: 'background',
      });
    },
  },
  { title: '套餐名称', dataIndex: 'name', width: 180, ellipsis: true },
  {
    title: '支付金额',
    dataIndex: 'pay_amount',
    width: 120,
    customRender: ({ record }: { record: RechargePackageApi.PackageItem }) =>
      `¥${record.pay_amount}`,
  },
  {
    title: '赠送金额',
    dataIndex: 'gift_amount',
    width: 120,
    customRender: ({ record }: { record: RechargePackageApi.PackageItem }) =>
      `¥${record.gift_amount}`,
  },
  {
    title: '到账余额',
    dataIndex: 'balance_amount',
    width: 120,
    customRender: ({ record }: { record: RechargePackageApi.PackageItem }) =>
      h(
        'span',
        { class: 'font-medium text-green-600' },
        `¥${record.balance_amount}`,
      ),
  },
  { title: '排序', dataIndex: 'sort', width: 90 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: { record: RechargePackageApi.PackageItem }) => {
      if (!hasAccessByCodes(['SystemRechargePackageUpdateStatus'])) {
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
  { title: '备注', dataIndex: 'remark', width: 220, ellipsis: true },
  { title: '创建时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', width: 160, fixed: 'right' },
];

onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">充值套餐</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          v-access:code="'SystemRechargePackageCreate'"
          type="primary"
          @click="handleCreate"
        >
          新增套餐
        </a-button>
        <a-button @click="() => loadData(searchParams)"> 刷新 </a-button>
      </div>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="套餐名称" class="mb-0">
          <a-input
            v-model:value="searchParams.name"
            allow-clear
            placeholder="请输入套餐名称"
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select
            v-model:value="searchParams.status"
            allow-clear
            placeholder="请选择"
            class="w-full"
          >
            <a-select-option :value="1"> 启用 </a-select-option>
            <a-select-option :value="0"> 禁用 </a-select-option>
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
        row-key="id"
        :scroll="{ x: 1280 }"
        @change="
          (newPagination: any) => {
            pagination.current = newPagination.current;
            pagination.pageSize = newPagination.pageSize;
            loadData(searchParams);
          }
        "
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                v-access:code="'SystemRechargePackageUpdate'"
                size="small"
                type="link"
                @click="handleEdit(record)"
              >
                编辑
              </a-button>
              <a-button
                v-access:code="'SystemRechargePackageDelete'"
                danger
                size="small"
                type="link"
                @click="handleDelete(record, 'name')"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <PackageModal
      v-model:visible="modalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
