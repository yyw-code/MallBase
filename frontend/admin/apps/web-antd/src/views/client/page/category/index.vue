<script lang="ts" setup>
import type { FormInstance, Rule } from 'ant-design-vue/es/form';

import type { ClientPageApi } from '#/api/client';

import { computed, onMounted, reactive, ref } from 'vue';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  createClientPageCategoryApi,
  deleteClientPageCategoryApi,
  getClientPageCategoryListApi,
  updateClientPageCategoryApi,
  updateClientPageCategoryStatusApi,
} from '#/api/client';

defineOptions({ name: 'ClientPageCategoryManagement' });

const loading = ref(false);
const tableData = ref<ClientPageApi.PageCategoryItem[]>([]);
const pagination = reactive({ current: 1, pageSize: 15, total: 0 });
const searchParams = ref<ClientPageApi.CategoryListParams>({
  keyword: '',
  status: undefined,
});
const modalVisible = ref(false);
const modalLoading = ref(false);
const formRef = ref<FormInstance>();
const editingId = ref<null | number>(null);
const formData = reactive<ClientPageApi.CategorySaveParams>({
  description: null,
  name: '',
  sort: 0,
  status: 1,
});

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '分类名称', dataIndex: 'name', width: 180 },
  { title: '描述', dataIndex: 'description', ellipsis: true },
  { title: '系统', dataIndex: 'is_system', width: 90 },
  { title: '排序', dataIndex: 'sort', width: 90 },
  { title: '状态', dataIndex: 'status', width: 110 },
  { title: '更新时间', dataIndex: 'update_time', width: 170 },
  { title: '操作', key: 'action', fixed: 'right', width: 160 },
];

const formRules: Record<string, Rule[]> = {
  name: [{ required: true, message: '请输入分类名称', whitespace: true }],
};

const modalTitle = computed(() =>
  editingId.value ? '编辑页面分类' : '新增页面分类',
);

const loadData = async () => {
  loading.value = true;
  try {
    const result = await getClientPageCategoryListApi({
      ...searchParams.value,
      limit: pagination.pageSize,
      page: pagination.current,
    });
    tableData.value = result.list || [];
    pagination.total = result.total || 0;
  } finally {
    loading.value = false;
  }
};

const resetSearch = () => {
  searchParams.value = {
    keyword: '',
    status: undefined,
  };
  pagination.current = 1;
  loadData();
};

const submitSearch = () => {
  pagination.current = 1;
  loadData();
};

const handleTableChange = (pager: { current?: number; pageSize?: number }) => {
  pagination.current = pager.current ?? pagination.current;
  pagination.pageSize = pager.pageSize ?? pagination.pageSize;
  loadData();
};

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    description: null,
    name: '',
    sort: 0,
    status: 1,
  });
};

const handleCreate = () => {
  editingId.value = null;
  resetForm();
  modalVisible.value = true;
};

const handleEdit = (record: ClientPageApi.PageCategoryItem) => {
  editingId.value = record.id;
  resetForm();
  Object.assign(formData, {
    description: record.description ?? null,
    name: record.name,
    sort: record.sort ?? 0,
    status: record.status ?? 1,
  });
  modalVisible.value = true;
};

const buildSubmitData = (): ClientPageApi.CategorySaveParams => ({
  ...formData,
  description: formData.description || null,
});

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    modalLoading.value = true;
    const data = buildSubmitData();
    if (editingId.value) {
      await updateClientPageCategoryApi(editingId.value, data);
      message.success('更新成功');
    } else {
      await createClientPageCategoryApi(data);
      message.success('创建成功');
    }
    modalVisible.value = false;
    await loadData();
  } catch (error: any) {
    if (!error?.errorFields) {
      console.error('保存页面分类失败:', error);
    }
  } finally {
    modalLoading.value = false;
  }
};

const handleDelete = (record: ClientPageApi.PageCategoryItem) => {
  Modal.confirm({
    content: `确定要删除页面分类"${record.name}"吗？`,
    title: '删除页面分类',
    onOk: async () => {
      await deleteClientPageCategoryApi(record.id);
      message.success('删除成功');
      await loadData();
    },
  });
};

const handleStatusChange = async (
  record: ClientPageApi.PageCategoryItem,
  checked: boolean | number | string,
) => {
  const nextStatus = checked === true ? 1 : 0;
  try {
    await updateClientPageCategoryStatusApi(record.id, nextStatus);
    message.success('状态更新成功');
    await loadData();
  } catch (error) {
    console.error('更新页面分类状态失败:', error);
    await loadData();
  }
};

onMounted(() => {
  loadData();
});
</script>

<template>
  <div class="client-page-category p-4">
    <div class="client-page-category__header">
      <div>
        <h2 class="client-page-category__title">页面分类</h2>
      </div>
      <div class="client-page-category__actions">
        <a-button
          v-access:code="'SystemClientPageCategoryCreate'"
          type="primary"
          @click="handleCreate"
        >
          新增分类
        </a-button>
        <a-button @click="loadData">刷新</a-button>
      </div>
    </div>

    <div class="client-page-category__filter">
      <a-form class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3">
        <a-form-item class="mb-0" label="关键词">
          <a-input
            v-model:value="searchParams.keyword"
            allow-clear
            class="w-full"
            placeholder="名称/描述"
            @press-enter="submitSearch"
          />
        </a-form-item>
        <a-form-item class="mb-0" label="状态">
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
        <a-form-item class="mb-0">
          <div class="flex justify-end gap-2">
            <a-button type="primary" @click="submitSearch">搜索</a-button>
            <a-button @click="resetSearch">重置</a-button>
          </div>
        </a-form-item>
      </a-form>
    </div>

    <div class="client-page-category__table">
      <div class="client-page-category__table-header">
        <div>
          <h3 class="client-page-category__table-title">页面分类</h3>
        </div>
      </div>

      <a-table
        :columns="columns"
        :data-source="tableData"
        :loading="loading"
        :pagination="pagination"
        :scroll="{ x: 900 }"
        row-key="id"
        @change="handleTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.dataIndex === 'description'">
            {{ record.description || '-' }}
          </template>

          <template v-if="column.dataIndex === 'is_system'">
            <a-tag :color="record.is_system === 1 ? 'gold' : 'default'">
              {{ record.is_system === 1 ? '系统' : '自定义' }}
            </a-tag>
          </template>

          <template v-if="column.dataIndex === 'status'">
            <Switch
              v-access:code="'SystemClientPageCategoryUpdateStatus'"
              :checked="record.status === 1"
              checked-children="启用"
              un-checked-children="禁用"
              @change="(checked) => handleStatusChange(record, checked)"
            />
          </template>

          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                v-access:code="'SystemClientPageCategoryUpdate'"
                type="link"
                size="small"
                @click="handleEdit(record)"
              >
                编辑
              </a-button>
              <a-button
                v-access:code="'SystemClientPageCategoryDelete'"
                type="link"
                danger
                size="small"
                :disabled="record.is_system === 1"
                @click="handleDelete(record)"
              >
                删除
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </div>

    <a-modal
      v-model:open="modalVisible"
      :confirm-loading="modalLoading"
      :title="modalTitle"
      width="620px"
      @ok="handleSubmit"
    >
      <a-form
        ref="formRef"
        :label-col="{ style: { width: '100px' } }"
        :model="formData"
        :rules="formRules"
        class="pt-4"
      >
        <a-form-item label="分类名称" name="name">
          <a-input
            v-model:value="formData.name"
            allow-clear
            :maxlength="80"
            placeholder="请输入分类名称"
            show-count
          />
        </a-form-item>
        <a-form-item label="描述" name="description">
          <a-textarea
            v-model:value="formData.description"
            :maxlength="255"
            :rows="3"
            allow-clear
            placeholder="可选"
            show-count
          />
        </a-form-item>
        <a-form-item label="排序" name="sort">
          <a-input-number
            v-model:value="formData.sort"
            :min="0"
            :max="9999"
            class="w-full"
            placeholder="数字越小越靠前"
          />
        </a-form-item>
        <a-form-item label="状态" name="status">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<style scoped>
.client-page-category {
  min-height: 100%;
}

.client-page-category__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 12px;
}

.client-page-category__title {
  margin: 0;
  color: hsl(var(--foreground));
  font-size: 18px;
  font-weight: 600;
  line-height: 32px;
}

.client-page-category__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}

.client-page-category__filter,
.client-page-category__table {
  background: hsl(var(--card));
  border: 1px solid hsl(var(--border));
  border-radius: 8px;
}

.client-page-category__filter {
  padding: 16px;
  margin-bottom: 12px;
}

.client-page-category__table {
  overflow: hidden;
}

.client-page-category__table-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid hsl(var(--border));
}

.client-page-category__table-title {
  margin: 0;
  color: hsl(var(--foreground));
  font-size: 15px;
  font-weight: 600;
  line-height: 24px;
}

.client-page-category__table :deep(.ant-table) {
  background: hsl(var(--card));
}

@media (max-width: 768px) {
  .client-page-category__header,
  .client-page-category__table-header {
    align-items: stretch;
    flex-direction: column;
  }

  .client-page-category__actions {
    justify-content: flex-start;
  }
}
</style>
