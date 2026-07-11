<script lang="ts" setup>
import type { SettingApi } from '#/api/setting';

import { h, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';
import { IconifyIcon } from '@vben/icons';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  changeSettingGroupStatusApi,
  deleteSettingGroupApi,
  getSettingGroupTreeApi,
} from '#/api/setting';

import GroupModal from './group-modal.vue';

defineOptions({ name: 'SettingGroup' });

const { hasAccessByCodes } = useAccess();

/* ---------------- 数据加载 ---------------- */

const tableData = ref<SettingApi.SettingGroup[]>([]);
const loading = ref(false);

const loadData = async () => {
  loading.value = true;
  try {
    tableData.value = await getSettingGroupTreeApi(searchParams.value);
  } catch (error) {
    console.error('加载分组数据失败:', error);
    message.error('加载分组数据失败');
  } finally {
    loading.value = false;
  }
};

/* ---------------- 搜索参数 ---------------- */

const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
});

const resetSearch = () => {
  searchParams.value = { keyword: '', status: undefined };
  loadData();
};

/* ---------------- 弹窗 ---------------- */

const groupModalVisible = ref(false);
const editingGroup = ref<null | SettingApi.SettingGroup>(null);
const groupModalMode = ref<'create' | 'detail' | 'edit'>('create');

const handleCreate = () => {
  editingGroup.value = null;
  groupModalMode.value = 'create';
  groupModalVisible.value = true;
};

const handleDetail = (record: SettingApi.SettingGroup) => {
  editingGroup.value = record;
  groupModalMode.value = 'detail';
  groupModalVisible.value = true;
};

const handleEdit = (record: SettingApi.SettingGroup) => {
  if (record.is_system === 1) return;
  editingGroup.value = record;
  groupModalMode.value = 'edit';
  groupModalVisible.value = true;
};

const onModalSuccess = () => {
  loadData();
};

/* ---------------- 删除 ---------------- */

const handleDelete = (record: SettingApi.SettingGroup) => {
  const childCount = record.children?.length || 0;
  const extra =
    childCount > 0 ? `（包含 ${childCount} 个子分组，将一并删除）` : '';
  Modal.confirm({
    content: `确定要删除分组「${record.name}」吗？${extra}`,
    okType: 'danger',
    title: '删除分组',
    async onOk() {
      await deleteSettingGroupApi(record.id);
      message.success('删除成功');
      loadData();
    },
  });
};

/* ---------------- 表格列 ---------------- */

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '分组名称', dataIndex: 'name', width: 200 },
  { title: '分组编码', dataIndex: 'code', width: 180 },
  { title: '图标', dataIndex: 'icon', width: 60 },
  {
    title: '展示方式',
    dataIndex: 'display_type',
    width: 100,
  },
  { title: '来源', dataIndex: 'is_system', width: 100 },
  { title: '排序', dataIndex: 'sort', width: 80 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: any) => {
      if (!hasAccessByCodes(['SettingGroupChangeStatus'])) {
        return record.status === 1 ? '启用' : '禁用';
      }

      return h(Switch, {
        checked: record.status === 1,
        disabled: record.is_system === 1,
        'checked-children': '启用',
        'un-checked-children': '禁用',
        onChange: async (checked: any) => {
          await changeSettingGroupStatusApi(record.id, checked ? 1 : 0);
          message.success('更新成功');
          loadData();
        },
      });
    },
  },
  { title: '创建时间', dataIndex: 'create_time', width: 180 },
  { title: '操作', key: 'action', width: 220, fixed: 'right' },
];

/* ---------------- 初始化 ---------------- */

onMounted(() => {
  loadData();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">设置分组</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SettingGroupCreate'"
        >
          新增分组
        </a-button>
        <a-button @click="loadData" v-access:code="'SettingGroupTree'">
          刷新
        </a-button>
      </div>
    </div>

    <!-- 搜索表单 -->
    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
      >
        <a-form-item label="关键词" class="mb-0">
          <a-input
            v-model:value="searchParams.keyword"
            placeholder="分组名称/编码"
            allow-clear
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select
            v-model:value="searchParams.status"
            placeholder="请选择"
            allow-clear
            class="w-full"
          >
            <a-select-option :value="1"> 启用 </a-select-option>
            <a-select-option :value="0"> 禁用 </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
          <div class="flex justify-end gap-2">
            <a-button type="primary" @click="loadData"> 搜索 </a-button>
            <a-button @click="resetSearch"> 重置 </a-button>
          </div>
        </a-form-item>
      </a-form>
    </div>

    <!-- 树形表格 -->
    <div class="overflow-hidden rounded-lg border bg-[hsl(var(--card))]">
      <a-table
        :columns="columns"
        :data-source="tableData"
        :loading="loading"
        :pagination="false"
        :default-expand-all-rows="true"
        row-key="id"
        :scroll="{ x: 1100 }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.dataIndex === 'name'">
            <div class="flex items-center gap-1">
              <IconifyIcon
                v-if="record.icon"
                :icon="record.icon"
                class="text-base"
              />
              <span>{{ record.name }}</span>
            </div>
          </template>

          <template v-if="column.dataIndex === 'display_type'">
            <a-tag
              :color="
                record.display_type === 'category'
                  ? 'orange'
                  : record.display_type === 'tab'
                    ? 'blue'
                    : 'green'
              "
            >
              {{
                record.display_type === 'category'
                  ? '目录'
                  : record.display_type === 'tab'
                    ? '选项卡'
                    : '页面'
              }}
            </a-tag>
          </template>

          <template v-if="column.dataIndex === 'is_system'">
            <a-tag :color="record.is_system === 1 ? 'blue' : 'default'">
              {{ record.is_system === 1 ? '系统内置' : '用户添加' }}
            </a-tag>
          </template>

          <template v-if="column.dataIndex === 'icon'">
            <IconifyIcon
              v-if="record.icon"
              :icon="record.icon"
              class="text-lg"
            />
            <span v-else class="text-gray-300">-</span>
          </template>

          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                type="link"
                size="small"
                @click="handleDetail(record)"
                v-access:code="'SettingGroupInfo'"
              >
                详情
              </a-button>
              <a-button
                type="link"
                size="small"
                :disabled="record.is_system === 1"
                @click="handleEdit(record)"
                v-access:code="'SettingGroupUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                :disabled="record.is_system === 1"
                @click="handleDelete(record)"
                v-access:code="'SettingGroupDelete'"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <!-- 分组弹窗 -->
    <GroupModal
      v-model:visible="groupModalVisible"
      :edit-data="editingGroup"
      :mode="groupModalMode"
      @success="onModalSuccess"
    />
  </div>
</template>
