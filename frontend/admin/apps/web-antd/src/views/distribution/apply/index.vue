<script lang="ts" setup>
import type { DistributionApi } from '#/api/distribution';

import { h, onMounted, reactive, ref } from 'vue';

import { useAccess } from '@vben/access';

import { Image, message, Tag } from 'ant-design-vue';

import {
  approveDistributionApplyApi,
  getDistributionApplyListApi,
  getDistributionLevelListApi,
  rejectDistributionApplyApi,
} from '#/api/distribution';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'DistributionApply' });

const { hasAccessByCodes } = useAccess();
const { tableData, loading, pagination, loadData } = useTableCrud<
  DistributionApi.ApplyItem,
  DistributionApi.ListParams
>(
  { list: getDistributionApplyListApi },
  { immediateLoad: false },
);

const searchParams = ref({
  keyword: '',
  status: undefined as number | undefined,
});
const levels = ref<DistributionApi.LevelItem[]>([]);
const reviewVisible = ref(false);
const reviewType = ref<'approve' | 'reject'>('approve');
const currentRecord = ref<DistributionApi.ApplyItem | null>(null);
const reviewForm = reactive({
  level_id: 1,
  review_remark: '',
});

const columns = [
  { title: 'ID', dataIndex: 'id', width: 80 },
  { title: '用户ID', dataIndex: 'user_id', width: 90 },
  {
    title: '用户',
    dataIndex: 'user',
    width: 180,
    customRender: ({ record }: { record: DistributionApi.ApplyItem }) =>
      record.user?.nickname || record.user?.mobile || '-',
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 110,
    customRender: ({ record }: { record: DistributionApi.ApplyItem }) => {
      const color =
        record.status === 10
          ? 'green'
          : record.status === 20
            ? 'red'
            : record.status === 30
              ? 'default'
              : 'blue';
      return h(Tag, { color }, () => record.status_text || '-');
    },
  },
  { title: '姓名', dataIndex: 'real_name', width: 120 },
  { title: '联系电话', dataIndex: 'mobile', width: 140 },
  { title: '申请说明', dataIndex: 'reason', ellipsis: true },
  {
    title: '申请凭证',
    dataIndex: 'proof_image_full_url',
    width: 110,
    customRender: ({ record }: { record: DistributionApi.ApplyItem }) =>
      record.proof_image_full_url
        ? h(Image, {
            height: 48,
            preview: { src: record.proof_image_full_url },
            src: record.proof_image_full_url,
            style: { borderRadius: '6px', objectFit: 'cover' },
            width: 48,
          })
        : '-',
  },
  { title: '申请时间', dataIndex: 'create_time', width: 170 },
  { title: '操作', key: 'action', width: 160, fixed: 'right' },
];

function resetSearch() {
  searchParams.value = { keyword: '', status: undefined };
  pagination.current = 1;
  loadData(searchParams.value);
}

function openReview(record: DistributionApi.ApplyItem, type: 'approve' | 'reject') {
  currentRecord.value = record;
  reviewType.value = type;
  reviewForm.review_remark = '';
  if (levels.value[0]) reviewForm.level_id = levels.value[0].id;
  reviewVisible.value = true;
}

async function submitReview() {
  const record = currentRecord.value;
  if (!record) return;

  if (reviewType.value === 'approve') {
    await approveDistributionApplyApi(record.id, {
      level_id: reviewForm.level_id,
      review_remark: reviewForm.review_remark,
    });
    message.success('审核通过');
  } else {
    if (!reviewForm.review_remark.trim()) {
      message.warning('请填写驳回原因');
      return;
    }
    await rejectDistributionApplyApi(record.id, reviewForm.review_remark);
    message.success('审核驳回');
  }

  reviewVisible.value = false;
  await loadData(searchParams.value);
}

onMounted(async () => {
  const data = await getDistributionLevelListApi({ limit: 100, page: 1, status: 1 });
  levels.value = data.list;
  if (levels.value[0]) reviewForm.level_id = levels.value[0].id;
  await loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">分销申请</h2>
      <a-button @click="() => loadData(searchParams)">刷新</a-button>
    </div>

    <div class="mb-3 rounded-lg border bg-[hsl(var(--card))] p-4">
      <a-form class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-3 xl:grid-cols-6">
        <a-form-item class="mb-0" label="关键词">
          <a-input v-model:value="searchParams.keyword" allow-clear placeholder="昵称/手机/姓名/用户ID" />
        </a-form-item>
        <a-form-item class="mb-0" label="状态">
          <a-select v-model:value="searchParams.status" allow-clear placeholder="请选择">
            <a-select-option :value="0">待审核</a-select-option>
            <a-select-option :value="10">已通过</a-select-option>
            <a-select-option :value="20">已驳回</a-select-option>
            <a-select-option :value="30">已撤回</a-select-option>
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
        :scroll="{ x: 1280 }"
        row-key="id"
        @change="(p: any) => { pagination.current = p.current; pagination.pageSize = p.pageSize; loadData(searchParams); }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'action'">
            <a-space v-if="record.status === 0">
              <a-button
                v-if="hasAccessByCodes(['SystemDistributionApplyApprove'])"
                size="small"
                type="link"
                @click="openReview(record, 'approve')"
              >
                通过
              </a-button>
              <a-button
                v-if="hasAccessByCodes(['SystemDistributionApplyReject'])"
                danger
                size="small"
                type="link"
                @click="openReview(record, 'reject')"
              >
                驳回
              </a-button>
            </a-space>
            <span v-else>-</span>
          </template>
        </template>
      </a-table>
    </div>

    <a-modal
      v-model:open="reviewVisible"
      :title="reviewType === 'approve' ? '通过分销申请' : '驳回分销申请'"
      @ok="submitReview"
    >
      <a-form class="pt-4" :label-col="{ style: { width: '100px' } }">
        <a-form-item v-if="reviewType === 'approve'" label="分销等级">
          <a-select v-model:value="reviewForm.level_id">
            <a-select-option v-for="level in levels" :key="level.id" :value="level.id">
              {{ level.name }}
            </a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item :label="reviewType === 'approve' ? '审核备注' : '驳回原因'">
          <a-textarea v-model:value="reviewForm.review_remark" :rows="3" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>
