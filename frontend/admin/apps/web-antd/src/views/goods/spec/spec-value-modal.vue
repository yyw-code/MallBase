<script lang="ts" setup>
import type { GoodsSpecApi } from '#/api/goods';

import { computed, ref, watch } from 'vue';

import { message, Tag } from 'ant-design-vue';

import {
  batchCreateSpecValuesApi,
  createSpecValueApi,
  deleteSpecValueApi,
  getGoodsSpecInfoApi,
} from '#/api/goods';

interface Props {
  visible: boolean;
  specData?: GoodsSpecApi.SpecItem | null;
}

interface Emits {
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}

const props = withDefaults(defineProps<Props>(), {
  visible: false,
  specData: null,
});

const emit = defineEmits<Emits>();

const loading = ref(false);
const specValues = ref<GoodsSpecApi.SpecValueItem[]>([]);
const newValue = ref('');
const batchValues = ref('');

const specName = computed(() => props.specData?.name || '');

/* ---------------- 加载规格值 ---------------- */
const loadSpecValues = async () => {
  if (!props.specData) return;
  try {
    const detail = await getGoodsSpecInfoApi(props.specData.id);
    specValues.value = detail.spec_values || [];
  } catch (error) {
    console.error('加载规格值失败:', error);
    message.error('加载规格值失败');
  }
};

/* ---------------- 监听 visible 变化 ---------------- */
watch(
  () => props.visible,
  (val) => {
    if (val) {
      newValue.value = '';
      batchValues.value = '';
      loadSpecValues();
    }
  },
);

/* ---------------- 添加单个规格值 ---------------- */
const handleAddValue = async () => {
  if (!props.specData) return;
  const value = newValue.value.trim();
  if (!value) {
    message.warning('请输入规格值');
    return;
  }

  try {
    loading.value = true;
    await createSpecValueApi(props.specData.id, value);
    message.success('添加成功');
    newValue.value = '';
    await loadSpecValues();
    emit('success');
  } catch (error: any) {
    console.error('添加规格值失败:', error);
    message.error(error.message || '添加失败');
  } finally {
    loading.value = false;
  }
};

/* ---------------- 批量添加规格值 ---------------- */
const handleBatchAdd = async () => {
  if (!props.specData) return;
  const text = batchValues.value.trim();
  if (!text) {
    message.warning('请输入规格值');
    return;
  }

  const values = text
    .split(',')
    .map((v) => v.trim())
    .filter((v) => v.length > 0);

  if (values.length === 0) {
    message.warning('请输入有效的规格值');
    return;
  }

  try {
    loading.value = true;
    await batchCreateSpecValuesApi(props.specData.id, values);
    message.success(`成功添加 ${values.length} 个规格值`);
    batchValues.value = '';
    await loadSpecValues();
    emit('success');
  } catch (error: any) {
    console.error('批量添加规格值失败:', error);
    message.error(error.message || '批量添加失败');
  } finally {
    loading.value = false;
  }
};

/* ---------------- 删除规格值 ---------------- */
const handleDeleteValue = async (item: GoodsSpecApi.SpecValueItem) => {
  try {
    await deleteSpecValueApi(item.id);
    message.success('删除成功');
    await loadSpecValues();
    emit('success');
  } catch (error: any) {
    console.error('删除规格值失败:', error);
    message.error(error.message || '删除失败');
  }
};

/* ---------------- 取消 ---------------- */
const handleCancel = () => {
  emit('update:visible', false);
};
</script>

<template>
  <a-modal
    :title="`管理规格值 - ${specName}`"
    :open="visible"
    :footer="null"
    :width="600"
    @cancel="handleCancel"
  >
    <div class="mt-4">
      <!-- 已有规格值 -->
      <div class="mb-4">
        <div class="mb-2 font-medium">已有规格值</div>
        <div v-if="specValues.length === 0" class="text-gray-400">
          暂无规格值
        </div>
        <div v-else style="display: flex; flex-wrap: wrap; gap: 8px">
          <a-tag
            v-for="item in specValues"
            :key="item.id"
            color="blue"
            closable
            @close="handleDeleteValue(item)"
          >
            {{ item.value }}
          </a-tag>
        </div>
      </div>

      <a-divider />

      <!-- 添加单个规格值 -->
      <div class="mb-4">
        <div class="mb-2 font-medium">添加规格值</div>
        <div style="display: flex; gap: 8px">
          <a-input
            v-model:value="newValue"
            placeholder="请输入规格值"
            allow-clear
            @press-enter="handleAddValue"
          />
          <a-button
            type="primary"
            :loading="loading"
            @click="handleAddValue"
          >
            添加
          </a-button>
        </div>
      </div>

      <a-divider />

      <!-- 批量添加 -->
      <div>
        <div class="mb-2 font-medium">批量添加</div>
        <a-textarea
          v-model:value="batchValues"
          placeholder="多个规格值用逗号分隔，如：红色,蓝色,绿色"
          :rows="3"
          allow-clear
        />
        <a-button
          type="primary"
          class="mt-2"
          :loading="loading"
          @click="handleBatchAdd"
        >
          批量添加
        </a-button>
      </div>
    </div>
  </a-modal>
</template>
