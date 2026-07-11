<script lang="ts" setup>
import type { MemberLevelApi } from '#/api/member';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  deleteMemberLevelApi,
  getMemberLevelInfoApi,
  getMemberLevelListApi,
  updateMemberLevelStatusApi,
} from '#/api/member';
import { useTableCrud } from '#/composables/useTableCrud';

import LevelModal from './level-modal.vue';

defineOptions({ name: 'MemberLevelManagement' });

const { hasAccessByCodes } = useAccess();

const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  MemberLevelApi.LevelItem,
  MemberLevelApi.ListParams
>(
  {
    delete: deleteMemberLevelApi,
    list: getMemberLevelListApi,
  },
  { immediateLoad: false },
);

const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
});

const modalVisible = ref(false);
const editingItem = ref<null | MemberLevelApi.LevelItem>(null);

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

const handleEdit = async (record: MemberLevelApi.LevelItem) => {
  try {
    editingItem.value = await getMemberLevelInfoApi(record.id);
    modalVisible.value = true;
  } catch (error) {
    console.error('获取会员等级详情失败:', error);
    message.error('获取会员等级详情失败');
  }
};

const onModalSuccess = () => {
  loadData(searchParams.value);
};

const handleStatusChange = async (
  record: MemberLevelApi.LevelItem,
  checked: boolean | number | string,
) => {
  try {
    await updateMemberLevelStatusApi(record.id, checked === true ? 1 : 0);
    message.success('状态更新成功');
    await loadData(searchParams.value);
  } catch {
    await loadData(searchParams.value);
  }
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '等级名称', dataIndex: 'name', width: 160, ellipsis: true },
  { title: '成长值门槛', dataIndex: 'growth_min', width: 130 },
  {
    title: '等级折扣',
    dataIndex: 'discount_percent',
    width: 120,
    customRender: ({ record }: { record: MemberLevelApi.LevelItem }) =>
      `${record.discount_percent}%`,
  },
  { title: '排序', dataIndex: 'sort', width: 90 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: { record: MemberLevelApi.LevelItem }) => {
      if (!hasAccessByCodes(['SystemMemberLevelUpdateStatus'])) {
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
  { title: '操作', key: 'action', width: 150, fixed: 'right' },
];

onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">会员等级</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          v-access:code="'SystemMemberLevelCreate'"
          type="primary"
          @click="handleCreate"
        >
          新增等级
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
            placeholder="等级名称"
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
        :scroll="{ x: 1100 }"
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
                v-access:code="'SystemMemberLevelUpdate'"
                size="small"
                type="link"
                @click="handleEdit(record)"
              >
                编辑
              </a-button>
              <a-button
                v-access:code="'SystemMemberLevelDelete'"
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

    <LevelModal
      v-model:visible="modalVisible"
      :edit-data="editingItem"
      @success="onModalSuccess"
    />
  </div>
</template>
