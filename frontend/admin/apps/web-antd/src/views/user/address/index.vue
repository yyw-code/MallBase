<script lang="ts" setup>
import type { UserAddressApi } from '#/api/user';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag, Tooltip } from 'ant-design-vue';

import {
  deleteUserAddressApi,
  getUserAddressInfoApi,
  getUserAddressListApi,
  refreshUserAddressInvalidApi,
  setUserAddressDefaultApi,
} from '#/api/user';
import { useTableCrud } from '#/composables/useTableCrud';

import AddressModal from './address-modal.vue';

defineOptions({ name: 'UserAddressManagement' });

const { hasAccessByCodes } = useAccess();

const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  UserAddressApi.AddressItem,
  UserAddressApi.ListParams
>(
  { delete: deleteUserAddressApi, list: getUserAddressListApi },
  { immediateLoad: false },
);

const searchParams = ref({
  keyword: '',
  user_id: undefined as number | undefined,
  region_status: undefined as number | undefined,
  is_default: undefined as number | undefined,
});

const getDefaultSearchParams = () => ({
  keyword: '',
  user_id: undefined as number | undefined,
  region_status: undefined as number | undefined,
  is_default: undefined as number | undefined,
});

const modalVisible = ref(false);
const editingItem = ref<null | UserAddressApi.AddressItem>(null);

const handleCreate = () => {
  editingItem.value = null;
  modalVisible.value = true;
};

const handleEdit = async (record: UserAddressApi.AddressItem) => {
  editingItem.value = await getUserAddressInfoApi(record.id);
  modalVisible.value = true;
};

const handleSetDefault = async (record: UserAddressApi.AddressItem) => {
  await setUserAddressDefaultApi(record.id);
  message.success('设置成功');
  await loadData(searchParams.value);
};

const handleRefreshInvalid = async () => {
  const result = await refreshUserAddressInvalidApi();
  message.success(
    `已扫描 ${result.total} 条，恢复 ${result.recovered} 条，仍失效 ${result.invalid} 条`,
  );
  await loadData(searchParams.value);
};

onMounted(async () => {
  await loadData(searchParams.value);
});

async function handleSearch() {
  pagination.current = 1;
  await loadData(searchParams.value);
}

async function handleReset() {
  searchParams.value = getDefaultSearchParams();
  pagination.current = 1;
  await loadData(searchParams.value);
}

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  {
    title: '用户',
    key: 'user',
    width: 180,
    customRender: ({ record }: { record: UserAddressApi.AddressItem }) =>
      `${record.user_nickname || '-'} / ${record.user_mobile || '-'}`,
  },
  { title: '收货人', dataIndex: 'receiver_name', width: 100 },
  { title: '联系电话', dataIndex: 'receiver_mobile', width: 130 },
  { title: '地区', dataIndex: 'region_path_text', width: 260, ellipsis: true },
  { title: '详细地址', dataIndex: 'address_detail', ellipsis: true },
  {
    title: '区域状态',
    dataIndex: 'region_status',
    width: 100,
    customRender: ({ record }: { record: UserAddressApi.AddressItem }) =>
      h(
        Tooltip,
        {
          title:
            record.region_status === 1
              ? undefined
              : record.region_invalid_reason ||
                '关联地区已失效，请重新编辑地址',
        },
        () =>
          h(
            Tag,
            { color: record.region_status === 1 ? 'success' : 'error' },
            () => (record.region_status === 1 ? '有效' : '失效'),
          ),
      ),
  },
  {
    title: '默认',
    dataIndex: 'is_default',
    width: 90,
    customRender: ({ record }: { record: UserAddressApi.AddressItem }) =>
      h(Switch, {
        checked: record.is_default === 1,
        checkedChildren: '是',
        unCheckedChildren: '否',
        disabled:
          record.is_default === 1 ||
          !hasAccessByCodes(['SystemUserAddressSetDefault']),
        onChange: () => handleSetDefault(record),
      }),
  },
  { title: '创建时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', key: 'action', width: 160, fixed: 'right' },
];
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button
        type="primary"
        @click="handleCreate"
        v-access:code="'SystemUserAddressCreate'"
      >
        新增地址
      </a-button>
      <a-button
        class="ml-2"
        @click="handleRefreshInvalid"
        v-access:code="'SystemUserAddressRefreshInvalid'"
      >
        更新失效数据
      </a-button>
      <a-button class="ml-2" @click="() => loadData(searchParams)">
        刷新
      </a-button>
    </div>
    <a-form layout="inline" class="mb-4">
      <a-form-item label="关键词">
        <a-input
          v-model:value="searchParams.keyword"
          placeholder="收货人/手机号/地区"
          allow-clear
          style="width: 220px"
        />
      </a-form-item>
      <a-form-item label="区域状态">
        <a-select
          v-model:value="searchParams.region_status"
          allow-clear
          style="width: 120px"
        >
          <a-select-option :value="1">有效</a-select-option>
          <a-select-option :value="0">失效</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item label="默认地址">
        <a-select
          v-model:value="searchParams.is_default"
          allow-clear
          style="width: 120px"
        >
          <a-select-option :value="1">默认</a-select-option>
          <a-select-option :value="0">非默认</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item>
        <a-space>
          <a-button type="primary" @click="handleSearch">搜索</a-button>
          <a-button @click="handleReset">重置</a-button>
        </a-space>
      </a-form-item>
    </a-form>
    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1300 }"
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
        <template v-if="column.key === 'action'">
          <a-space>
            <a-button
              type="link"
              size="small"
              @click="handleEdit(record)"
              v-access:code="'SystemUserAddressUpdate'"
            >
              编辑
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record, 'receiver_name')"
              v-access:code="'SystemUserAddressDelete'"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>
    <AddressModal
      v-model:visible="modalVisible"
      :edit-data="editingItem"
      @success="() => loadData(searchParams)"
    />
  </div>
</template>
