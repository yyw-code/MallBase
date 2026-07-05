<script lang="ts" setup>
import type { DistributionApi } from '#/api/distribution';

import { h, onMounted, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message, Switch, Tag } from 'ant-design-vue';

import {
  createDistributionRuleApi,
  deleteDistributionRuleApi,
  getDistributionRuleInfoApi,
  getDistributionRuleListApi,
  updateDistributionRuleApi,
  updateDistributionRuleStatusApi,
} from '#/api/distribution';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'DistributionRule' });

const { hasAccessByCodes } = useAccess();
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  DistributionApi.RuleItem,
  DistributionApi.ListParams
>(
  { delete: deleteDistributionRuleApi, list: getDistributionRuleListApi },
  { immediateLoad: false },
);

const targetOptions = [
  { label: '分类', value: 'category' },
  { label: '商品', value: 'goods' },
  { label: 'SKU', value: 'sku' },
];
const searchParams = ref({ status: undefined as number | undefined, target_type: undefined as string | undefined });
const modalVisible = ref(false);
const editingId = ref(0);
const form = reactive({
  first_rate: '5.00',
  name: '',
  remark: '',
  second_rate: '2.00',
  status: 1,
  target_id: undefined as number | undefined,
  target_type: 'goods',
});

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '对象类型', dataIndex: 'target_type_text', width: 110 },
  { title: '对象ID', dataIndex: 'target_id', width: 100 },
  { title: '规则名称', dataIndex: 'name', width: 180, ellipsis: true },
  { title: '一级比例', dataIndex: 'first_rate', width: 120 },
  { title: '二级比例', dataIndex: 'second_rate', width: 120 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 110,
    customRender: ({ record }: { record: DistributionApi.RuleItem }) => {
      if (!hasAccessByCodes(['SystemDistributionRuleUpdateStatus'])) {
        return h(Tag, { color: record.status === 1 ? 'green' : 'default' }, () =>
          record.status === 1 ? '启用' : '禁用',
        );
      }
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '启用',
        unCheckedChildren: '禁用',
        onChange: async (checked: boolean | number | string) => {
          await updateDistributionRuleStatusApi(record.id, checked === true ? 1 : 0);
          await loadData(searchParams.value);
        },
      });
    },
  },
  { title: '操作', key: 'action', width: 150, fixed: 'right' },
];

function resetForm() {
  Object.assign(form, {
    first_rate: '5.00',
    name: '',
    remark: '',
    second_rate: '2.00',
    status: 1,
    target_id: undefined,
    target_type: 'goods',
  });
}

function resetSearch() {
  searchParams.value = { status: undefined, target_type: undefined };
  pagination.current = 1;
  loadData(searchParams.value);
}

function handleCreate() {
  editingId.value = 0;
  resetForm();
  modalVisible.value = true;
}

async function handleEdit(record: DistributionApi.RuleItem) {
  const detail = await getDistributionRuleInfoApi(record.id);
  editingId.value = detail.id;
  Object.assign(form, detail);
  modalVisible.value = true;
}

async function submitForm() {
  if (!form.target_id) {
    message.warning('请输入对象ID');
    return;
  }
  if (editingId.value > 0) {
    await updateDistributionRuleApi(editingId.value, form);
    message.success('更新成功');
  } else {
    await createDistributionRuleApi(form);
    message.success('创建成功');
  }
  modalVisible.value = false;
  await loadData(searchParams.value);
}

onMounted(() => loadData(searchParams.value));
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">佣金规则</h2>
      <div class="flex gap-2">
        <a-button @click="() => loadData(searchParams)">刷新</a-button>
        <a-button v-access:code="'SystemDistributionRuleCreate'" type="primary" @click="handleCreate">新增规则</a-button>
      </div>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6">
        <a-form-item label="对象类型" class="mb-0">
          <a-select v-model:value="searchParams.target_type" allow-clear placeholder="请选择">
            <a-select-option v-for="item in targetOptions" :key="item.value" :value="item.value">{{ item.label }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select v-model:value="searchParams.status" allow-clear placeholder="请选择">
            <a-select-option :value="1">启用</a-select-option>
            <a-select-option :value="0">禁用</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item class="mb-0 md:col-span-3 xl:col-span-6">
          <div class="flex justify-end gap-2">
            <a-button type="primary" @click="() => { pagination.current = 1; loadData(searchParams); }">搜索</a-button>
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
        :scroll="{ x: 980 }"
        row-key="id"
        @change="(p: any) => { pagination.current = p.current; pagination.pageSize = p.pageSize; loadData(searchParams); }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space>
              <a-button v-access:code="'SystemDistributionRuleUpdate'" size="small" type="link" @click="handleEdit(record)">编辑</a-button>
              <a-button v-access:code="'SystemDistributionRuleDelete'" danger size="small" type="link" @click="handleDelete(record, 'name')">删除</a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <a-modal v-model:open="modalVisible" :title="editingId ? '编辑佣金规则' : '新增佣金规则'" @ok="submitForm">
      <a-form class="pt-4" :label-col="{ style: { width: '110px' } }">
        <a-form-item label="对象类型">
          <a-select v-model:value="form.target_type">
            <a-select-option v-for="item in targetOptions" :key="item.value" :value="item.value">{{ item.label }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="对象ID">
          <a-input-number v-model:value="form.target_id" class="w-full" :min="1" :precision="0" />
        </a-form-item>
        <a-form-item label="规则名称">
          <a-input v-model:value="form.name" allow-clear />
        </a-form-item>
        <a-form-item label="一级比例">
          <a-input-number v-model:value="form.first_rate" class="w-full" :max="100" :min="0" :precision="2" />
        </a-form-item>
        <a-form-item label="二级比例">
          <a-input-number v-model:value="form.second_rate" class="w-full" :max="100" :min="0" :precision="2" />
        </a-form-item>
        <a-form-item label="状态">
          <a-radio-group v-model:value="form.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="备注">
          <a-textarea v-model:value="form.remark" :rows="3" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
