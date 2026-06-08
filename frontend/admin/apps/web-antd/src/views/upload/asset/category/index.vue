<script lang="ts" setup>
import type { UploadAssetApi } from '#/api/upload/asset';

import { h, onMounted, reactive, ref } from 'vue';

import { message, Modal, Switch } from 'ant-design-vue';

import {
  createUploadAssetCategoryApi,
  deleteUploadAssetCategoryApi,
  getUploadAssetCategoryListApi,
  updateUploadAssetCategoryApi,
} from '#/api/upload/asset';

defineOptions({ name: 'UploadAssetCategoryManagement' });

const loading = ref(false);
const modalOpen = ref(false);
const editingId = ref<number>();
const tableData = ref<UploadAssetApi.CategoryItem[]>([]);
const pagination = reactive({ current: 1, pageSize: 50, total: 0 });
const formRef = ref();
const formData = reactive({
  pid: 0,
  name: '',
  code: '',
  sort: 0,
  status: 1,
});

const loadData = async () => {
  loading.value = true;
  try {
    const res = await getUploadAssetCategoryListApi({
      page: pagination.current,
      limit: pagination.pageSize,
    });
    tableData.value = res.list || [];
    pagination.total = res.total || 0;
  } finally {
    loading.value = false;
  }
};

const handleTableChange = (pager: { current?: number; pageSize?: number }) => {
  pagination.current = pager.current || pagination.current;
  pagination.pageSize = pager.pageSize || pagination.pageSize;
  loadData();
};

const resetForm = () => {
  editingId.value = undefined;
  Object.assign(formData, { pid: 0, name: '', code: '', sort: 0, status: 1 });
};

const openCreate = () => {
  resetForm();
  modalOpen.value = true;
};

const openEdit = (record: UploadAssetApi.CategoryItem) => {
  editingId.value = record.id;
  Object.assign(formData, {
    pid: record.pid || 0,
    name: record.name || '',
    code: record.code || '',
    sort: record.sort || 0,
    status: record.status ?? 1,
  });
  modalOpen.value = true;
};

const submit = async () => {
  await formRef.value?.validate();
  if (editingId.value) {
    await updateUploadAssetCategoryApi(editingId.value, formData);
    message.success('更新成功');
  } else {
    await createUploadAssetCategoryApi(formData);
    message.success('创建成功');
  }
  modalOpen.value = false;
  await loadData();
};

const handleDelete = (record: UploadAssetApi.CategoryItem) => {
  Modal.confirm({
    title: '删除分类',
    content: '确认删除该素材分类吗？',
    async onOk() {
      await deleteUploadAssetCategoryApi(record.id);
      message.success('删除成功');
      await loadData();
    },
  });
};

const handleStatusChange = (checked: boolean | number | string) => {
  formData.status = checked ? 1 : 0;
};

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '名称', dataIndex: 'name', width: 180 },
  { title: '编码', dataIndex: 'code', width: 180 },
  { title: '排序', dataIndex: 'sort', width: 100 },
  {
    title: '状态',
    dataIndex: 'status',
    width: 100,
    customRender: ({ record }: any) =>
      h(Switch, { checked: record.status === 1, disabled: true }),
  },
  { title: '操作', key: 'action', width: 180 },
];
const tableScroll = { x: 860 };

onMounted(loadData);
</script>

<template>
  <div class="category-page">
    <div class="category-toolbar">
      <a-button type="primary" @click="openCreate" v-access:code="'SystemUploadAssetCategoryCreate'">
        新增分类
      </a-button>
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
            type="link"
            size="small"
            @click="openEdit(record)"
            v-access:code="'SystemUploadAssetCategoryUpdate'"
          >
            编辑
          </a-button>
          <a-button
            type="link"
            danger
            size="small"
            @click="handleDelete(record)"
            v-access:code="'SystemUploadAssetCategoryDelete'"
          >
            删除
          </a-button>
        </template>
      </template>
    </a-table>

    <a-modal
      v-model:open="modalOpen"
      :title="editingId ? '编辑分类' : '新增分类'"
      @ok="submit"
    >
      <a-form ref="formRef" :model="formData" :label-col="{ style: { width: '100px' } }" class="pt-4">
        <a-form-item label="名称" name="name" :rules="[{ required: true, message: '请输入名称' }]">
          <a-input v-model:value="formData.name" />
        </a-form-item>
        <a-form-item label="编码" name="code" :rules="[{ required: true, message: '请输入编码' }]">
          <a-input v-model:value="formData.code" :disabled="!!editingId" />
        </a-form-item>
        <a-form-item label="排序" name="sort">
          <a-input-number v-model:value="formData.sort" :min="0" class="category-number" />
        </a-form-item>
        <a-form-item label="状态" name="status">
          <a-switch
            :checked="formData.status === 1"
            @change="handleStatusChange"
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<style scoped>
.category-page {
  padding: 16px;
}

.category-toolbar {
  margin-bottom: 16px;
}

.category-number {
  width: 160px;
}
</style>
