<script lang="ts" setup>
import type { SmsProviderApi } from '#/api/sms/provider';

import { computed, ref } from 'vue';

import { useAccess } from '@vben/access';

import { message } from 'ant-design-vue';

import { SMS_DRIVER } from '#/api/sms/constants';
import {
  createSmsProviderApi,
  deleteSmsProviderApi,
  getSmsProviderInfoApi,
  getSmsProviderListApi,
  testSmsProviderApi,
  updateSmsProviderApi,
} from '#/api/sms/provider';
import { useFormModal, useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'SmsProvider' });

const { hasAccessByCodes } = useAccess();

const driverOptions = [{ label: '阿里云短信', value: SMS_DRIVER.ALIYUN }];

const searchParams = ref<SmsProviderApi.ListParams>({
  keyword: '',
  driver: undefined,
  status: undefined,
});

const { tableData, loading, pagination, loadData, refresh, handleDelete } =
  useTableCrud<SmsProviderApi.ProviderItem, SmsProviderApi.ListParams>(
    {
      delete: deleteSmsProviderApi,
      getInfo: getSmsProviderInfoApi,
      list: getSmsProviderListApi,
    },
    { immediateLoad: false },
  );

const {
  modalVisible,
  modalTitle,
  formData,
  formRef,
  openCreateModal,
  openEditModal,
  handleSubmit,
} = useFormModal<SmsProviderApi.ProviderItem>();

const isEdit = computed(() => modalTitle.value.includes('编辑'));

const handleCreate = () => {
  openCreateModal({
    name: '',
    driver: 'aliyun',
    access_key_id: '',
    access_key_secret: '',
    region: 'cn-hangzhou',
    is_default: 0,
    status: 1,
    remark: '',
    sort: 0,
  });
};

const handleEdit = async (row: SmsProviderApi.ProviderItem) => {
  await openEditModal(row, getSmsProviderInfoApi);
  // 编辑时不展示明文 secret,清空让用户按需重填
  formData.value.access_key_secret = '';
};

const handleFormSubmit = async () => {
  await handleSubmit(
    {
      create: createSmsProviderApi,
      update: (id, data) => updateSmsProviderApi(id, data),
    },
    () => loadData(searchParams.value),
  );
};

const handleTest = async (row: SmsProviderApi.ProviderItem) => {
  const result = await testSmsProviderApi(row.id);
  if (result.ok) {
    message.success(result.message || '凭证可用');
  } else {
    message.error(result.message || '凭证不可用');
  }
};

const resetSearch = () => {
  searchParams.value = { keyword: '', driver: undefined, status: undefined };
  pagination.current = 1;
  loadData(searchParams.value);
};

const driverLabel = (driver: string) =>
  driverOptions.find((o) => o.value === driver)?.label || driver;

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '名称', dataIndex: 'name', width: 160 },
  { title: '驱动', dataIndex: 'driver', width: 120 },
  {
    title: 'AccessKeyId',
    dataIndex: 'access_key_id',
    width: 220,
    ellipsis: true,
  },
  { title: '区域', dataIndex: 'region', width: 120 },
  { title: '默认', dataIndex: 'is_default', width: 80 },
  { title: '状态', dataIndex: 'status', width: 80 },
  { title: '排序', dataIndex: 'sort', width: 80 },
  { title: '更新时间', dataIndex: 'update_time', width: 180 },
  { title: '操作', key: 'action', width: 240 },
];

if (hasAccessByCodes(['SmsProviderList'])) {
  loadData(searchParams.value);
}
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">短信服务商</h2>
      <div class="flex flex-wrap justify-end gap-2">
        <a-button
          type="primary"
          @click="handleCreate"
          v-access:code="'SmsProviderCreate'"
        >
          新增服务商
        </a-button>
        <a-button @click="refresh" v-access:code="'SmsProviderList'">
          刷新
        </a-button>
      </div>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form
        class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6"
        v-access:code="'SmsProviderList'"
      >
        <a-form-item label="关键词" class="mb-0">
          <a-input
            v-model:value="searchParams.keyword"
            placeholder="服务商名称"
            allow-clear
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="驱动" class="mb-0">
          <a-select
            v-model:value="searchParams.driver"
            placeholder="全部"
            allow-clear
            :options="driverOptions"
            class="w-full"
          />
        </a-form-item>
        <a-form-item label="状态" class="mb-0">
          <a-select
            v-model:value="searchParams.status"
            placeholder="全部"
            allow-clear
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
        :scroll="{ x: 1300 }"
        row-key="id"
        @change="
          (newPagination) => {
            pagination.current = newPagination.current;
            pagination.pageSize = newPagination.pageSize;
            loadData(searchParams);
          }
        "
        v-access:code="'SmsProviderList'"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.dataIndex === 'driver'">
            <a-tag color="blue">{{ driverLabel(record.driver) }}</a-tag>
          </template>

          <template v-if="column.dataIndex === 'is_default'">
            <a-tag v-if="record.is_default === 1" color="green">默认</a-tag>
            <span v-else>-</span>
          </template>

          <template v-if="column.dataIndex === 'status'">
            <a-tag :color="record.status === 1 ? 'green' : 'default'">
              {{ record.status === 1 ? '启用' : '禁用' }}
            </a-tag>
          </template>

          <template v-if="column.key === 'action'">
            <a-space>
              <a-button
                type="link"
                size="small"
                @click="handleTest(record)"
                v-access:code="'SmsProviderTest'"
              >
                测试
              </a-button>
              <a-button
                type="link"
                size="small"
                @click="handleEdit(record)"
                v-access:code="'SmsProviderUpdate'"
              >
                编辑
              </a-button>
              <a-button
                type="link"
                danger
                size="small"
                @click="handleDelete(record, 'name')"
                v-access:code="'SmsProviderDelete'"
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
      :title="modalTitle"
      width="600px"
      @ok="handleFormSubmit"
    >
      <a-form
        ref="formRef"
        :model="formData"
        :label-col="{ span: 6 }"
        :wrapper-col="{ span: 16 }"
      >
        <a-form-item
          label="名称"
          name="name"
          :rules="[{ required: true, message: '请输入名称' }]"
        >
          <a-input
            v-model:value="formData.name"
            placeholder="如：阿里云短信-生产"
          />
        </a-form-item>
        <a-form-item
          label="驱动"
          name="driver"
          :rules="[{ required: true, message: '请选择驱动' }]"
        >
          <a-select
            v-model:value="formData.driver"
            :options="driverOptions"
            :disabled="isEdit"
          />
        </a-form-item>
        <a-form-item label="AccessKeyId" name="access_key_id">
          <a-input
            v-model:value="formData.access_key_id"
            placeholder="LTAI..."
          />
        </a-form-item>
        <a-form-item label="AccessKeySecret">
          <a-input-password
            v-model:value="formData.access_key_secret"
            :placeholder="
              isEdit && formData.access_key_secret_set
                ? '留空则保留原值'
                : '请输入 AccessKeySecret'
            "
            autocomplete="new-password"
          />
        </a-form-item>
        <a-form-item label="区域" name="region">
          <a-input v-model:value="formData.region" placeholder="cn-hangzhou" />
        </a-form-item>
        <a-form-item label="设为默认">
          <a-radio-group v-model:value="formData.is_default">
            <a-radio :value="1">是</a-radio>
            <a-radio :value="0">否</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="状态">
          <a-radio-group v-model:value="formData.status">
            <a-radio :value="1">启用</a-radio>
            <a-radio :value="0">禁用</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="排序">
          <a-input-number v-model:value="formData.sort" :min="0" />
        </a-form-item>
        <a-form-item label="备注">
          <a-textarea v-model:value="formData.remark" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
