<script lang="ts" setup>
import type { GoodsCommentApi } from '#/api/goods';

import { h, onMounted, ref } from 'vue';

import { message, Modal, Rate, Switch } from 'ant-design-vue';

import {
  deleteGoodsCommentApi,
  getGoodsCommentListApi,
  replyGoodsCommentApi,
  updateGoodsCommentStatusApi,
} from '#/api/goods';
import { useTableCrud } from '#/composables/useTableCrud';

defineOptions({ name: 'GoodsCommentManagement' });

/* ---------------- 表格 CRUD ---------------- */
const { tableData, loading, pagination, loadData, handleDelete } = useTableCrud<
  GoodsCommentApi.CommentItem,
  GoodsCommentApi.ListParams
>(
  {
    delete: deleteGoodsCommentApi,
    list: getGoodsCommentListApi,
  },
  { immediateLoad: false },
);

/* ---------------- 搜索参数 ---------------- */
const searchParams = ref({
  goods_id: undefined as number | undefined,
  rating: undefined as number | undefined,
  status: undefined as number | undefined,
});

const resetSearch = () => {
  searchParams.value = {
    goods_id: undefined,
    rating: undefined,
    status: undefined,
  };
  pagination.current = 1;
  loadData(searchParams.value);
};

/* ---------------- 状态切换 ---------------- */
const handleStatusChange = async (
  record: GoodsCommentApi.CommentItem,
  checked: boolean,
) => {
  try {
    await updateGoodsCommentStatusApi(record.id, checked ? 1 : 0);
    message.success('状态更新成功');
    await loadData(searchParams.value);
  } catch {
    // 失败后刷新列表恢复状态
    await loadData(searchParams.value);
  }
};

/* ---------------- 回复评论 ---------------- */
const handleReply = (record: GoodsCommentApi.CommentItem) => {
  let replyContent = '';

  Modal.confirm({
    title: `回复评论 - ${record.user_nickname || '匿名用户'}`,
    content: () =>
      h('div', [
        h('p', { style: 'margin-bottom: 8px; color: #666;' }, record.content),
        h('textarea', {
          style:
            'width: 100%; min-height: 80px; padding: 8px; border: 1px solid #d9d9d9; border-radius: 6px; resize: vertical;',
          placeholder: '请输入回复内容',
          onInput: (e: Event) => {
            replyContent = (e.target as HTMLTextAreaElement).value;
          },
        }),
      ]),
    async onOk() {
      if (!replyContent.trim()) {
        message.warning('请输入回复内容');
        return Promise.reject();
      }
      try {
        await replyGoodsCommentApi(record.id, replyContent.trim());
        message.success('回复成功');
        await loadData(searchParams.value);
      } catch (error: any) {
        console.error('回复失败:', error);
        message.error(error.message || '回复失败');
        return Promise.reject();
      }
    },
  });
};

/* ---------------- 表格列 ---------------- */
const columns = [
  { title: 'ID', dataIndex: 'id', width: 70 },
  { title: '商品名称', dataIndex: 'goods_name', width: 150, ellipsis: true },
  { title: '用户昵称', dataIndex: 'user_nickname', width: 120 },
  {
    title: '评分',
    dataIndex: 'rating',
    width: 140,
    customRender: ({ record }: { record: GoodsCommentApi.CommentItem }) => {
      return h(Rate, {
        value: record.rating,
        disabled: true,
      });
    },
  },
  {
    title: '评论内容',
    dataIndex: 'content',
    ellipsis: true,
  },
  {
    title: '回复状态',
    dataIndex: 'reply_content',
    width: 100,
    customRender: ({ record }: { record: GoodsCommentApi.CommentItem }) => {
      return record.reply_content ? '已回复' : '未回复';
    },
  },
  {
    title: '状态',
    dataIndex: 'status',
    width: 90,
    customRender: ({ record }: { record: GoodsCommentApi.CommentItem }) => {
      return h(Switch, {
        checked: record.status === 1,
        checkedChildren: '显示',
        unCheckedChildren: '隐藏',
        onChange: (checked: boolean) => handleStatusChange(record, checked),
      });
    },
  },
  { title: '创建时间', dataIndex: 'create_time', width: 160 },
  { title: '操作', key: 'action', width: 200 },
];

/* ---------------- 初始化 ---------------- */
onMounted(() => {
  loadData(searchParams.value);
});
</script>

<template>
  <div class="p-4">
    <div class="mb-4">
      <a-button class="ml-2" @click="() => loadData(searchParams)">
        刷新
      </a-button>
    </div>

    <!-- 搜索表单 -->
    <a-form layout="inline" class="mb-4">
      <a-form-item label="商品ID">
        <a-input-number
          v-model:value="searchParams.goods_id"
          placeholder="请输入商品ID"
          :min="1"
          allow-clear
          style="width: 150px"
        />
      </a-form-item>
      <a-form-item label="评分">
        <a-select
          v-model:value="searchParams.rating"
          placeholder="请选择"
          allow-clear
          style="width: 120px"
        >
          <a-select-option :value="5">5星</a-select-option>
          <a-select-option :value="4">4星</a-select-option>
          <a-select-option :value="3">3星</a-select-option>
          <a-select-option :value="2">2星</a-select-option>
          <a-select-option :value="1">1星</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item label="状态">
        <a-select
          v-model:value="searchParams.status"
          placeholder="请选择"
          allow-clear
          style="width: 120px"
        >
          <a-select-option :value="1">显示</a-select-option>
          <a-select-option :value="0">隐藏</a-select-option>
        </a-select>
      </a-form-item>
      <a-form-item>
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
        <a-button class="ml-2" @click="resetSearch"> 重置 </a-button>
      </a-form-item>
    </a-form>

    <a-table
      :columns="columns"
      :data-source="tableData"
      :loading="loading"
      :pagination="pagination"
      :scroll="{ x: 1200 }"
      row-key="id"
      @change="
        (newPagination) => {
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
              @click="handleReply(record)"
            >
              回复
            </a-button>
            <a-button
              type="link"
              danger
              size="small"
              @click="handleDelete(record)"
            >
              删除
            </a-button>
          </a-space>
        </template>
      </template>
    </a-table>
  </div>
</template>
