<script lang="ts" setup>
import type { GoodsSpecTemplateApi } from '#/api/goods';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import {
  createGoodsSpecTemplateApi,
  updateGoodsSpecTemplateApi,
} from '#/api/goods';

interface Props {
  visible: boolean;
  editData?: GoodsSpecTemplateApi.TemplateItem | null;
}

interface Emits {
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}

const props = withDefaults(defineProps<Props>(), {
  visible: false,
  editData: null,
});

const emit = defineEmits<Emits>();

const isEdit = computed(() => !!props.editData);

const formData = reactive({
  name: '',
  detail: [] as GoodsSpecTemplateApi.DetailItem[],
  sort: 0,
  status: 1,
});

const rules = {
  name: [{ required: true, message: '请输入模板名称', trigger: 'blur' }],
};

const formRef = ref();
const loading = ref(false);

/* ---------------- 规格行操作 ---------------- */

const addSpecRow = () => {
  formData.detail.push({ spec_name: '', values: [] });
};

const removeSpecRow = (index: number) => {
  formData.detail.splice(index, 1);
};

/**
 * 将逗号分隔字符串同步回 values 数组
 */
const syncValuesFromText = (index: number, text: string) => {
  formData.detail[index]!.values = text
    .split(',')
    .map((v) => v.trim())
    .filter((v) => v.length > 0);
};

/**
 * 将 values 数组转为逗号分隔展示字符串
 */
const valuesToText = (values: string[]): string => {
  return values.join(', ');
};

/* ---------------- 监听 visible 变化 ---------------- */

watch(
  () => props.visible,
  (val) => {
    if (val) {
      resetForm();
      if (props.editData) {
        Object.assign(formData, {
          name: props.editData.name || '',
          detail: (props.editData.detail || []).map((item) => ({
            spec_name: item.spec_name,
            values: [...item.values],
          })),
          sort: props.editData.sort || 0,
          status: props.editData.status ?? 1,
        });
      }
    }
  },
);

/* ---------------- 重置表单 ---------------- */

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '',
    detail: [],
    sort: 0,
    status: 1,
  });
};

/* ---------------- 提交表单 ---------------- */

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();

    // 过滤掉名称为空的规格行
    const validDetail = formData.detail.filter(
      (item) => item.spec_name.trim().length > 0,
    );

    if (validDetail.length === 0) {
      message.warning('请至少添加一条规格项');
      return;
    }

    loading.value = true;

    const submitData = {
      name: formData.name,
      detail: validDetail,
      sort: formData.sort,
      status: formData.status,
    };

    if (isEdit.value) {
      await updateGoodsSpecTemplateApi(props.editData!.id, submitData);
      message.success('更新成功');
    } else {
      await createGoodsSpecTemplateApi(submitData);
      message.success('创建成功');
    }

    emit('success');
    emit('update:visible', false);
  } catch (error: any) {
    if (error.errorFields) {
      console.log('表单验证失败:', error);
    } else {
      console.error('提交失败:', error);
      message.error(error.message || '操作失败');
    }
  } finally {
    loading.value = false;
  }
};

/* ---------------- 取消 ---------------- */

const handleCancel = () => {
  emit('update:visible', false);
};
</script>

<template>
  <a-modal
    :title="isEdit ? '编辑规格模板' : '新增规格模板'"
    :open="visible"
    :confirm-loading="loading"
    :width="640"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <a-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      :label-col="{ style: { width: '90px' } }"
      class="pt-4"
    >
      <a-form-item label="模板名称" name="name">
        <a-input
          v-model:value="formData.name"
          placeholder="请输入模板名称"
          :maxlength="60"
          show-count
          allow-clear
        />
      </a-form-item>

      <a-form-item label="规格项" :wrapper-col="{ span: 18 }">
        <div class="spec-editor">
          <!-- 表头 -->
          <div v-if="formData.detail.length > 0" class="spec-editor-header">
            <span class="col-name">规格名称</span>
            <span class="col-values">规格值（逗号分隔）</span>
            <span class="col-action" />
          </div>

          <!-- 规格行列表 -->
          <div
            v-for="(item, index) in formData.detail"
            :key="index"
            class="spec-row"
          >
            <a-input
              v-model:value="item.spec_name"
              placeholder="如：颜色"
              :maxlength="30"
              class="col-name"
            />
            <a-input
              :value="valuesToText(item.values)"
              placeholder="如：红色, 蓝色, 绿色"
              class="col-values"
              allow-clear
              @change="(e: any) => syncValuesFromText(index, e.target.value)"
            />
            <a-button
              type="text"
              danger
              size="small"
              class="col-action"
              @click="removeSpecRow(index)"
            >
              <template #icon>
                <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><path d="M6 4.586L9.293 1.293a1 1 0 011.414 1.414L7.414 6l3.293 3.293a1 1 0 01-1.414 1.414L6 7.414l-3.293 3.293a1 1 0 01-1.414-1.414L4.586 6 1.293 2.707A1 1 0 012.707 1.293L6 4.586z"/></svg>
              </template>
            </a-button>
          </div>

          <!-- 空状态提示 -->
          <div v-if="formData.detail.length === 0" class="spec-empty-tip">
            暂无规格项，请点击下方按钮添加
          </div>

          <!-- 添加行按钮 -->
          <a-button
            type="dashed"
            block
            class="mt-2"
            @click="addSpecRow"
          >
            <template #icon>
              <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><path d="M6 1a1 1 0 011 1v3h3a1 1 0 010 2H7v3a1 1 0 01-2 0V7H2a1 1 0 010-2h3V2a1 1 0 011-1z"/></svg>
            </template>
            添加规格项
          </a-button>
        </div>
      </a-form-item>

      <a-form-item label="排序" name="sort">
        <a-input-number
          v-model:value="formData.sort"
          :min="0"
          :max="9999"
          placeholder="数字越小越靠前"
          class="w-full"
          style="width: 160px"
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
</template>

<style scoped>
.spec-editor {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.spec-editor-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: #8c8c8c;
  padding: 0 2px 4px;
  border-bottom: 1px solid #f0f0f0;
}

.spec-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.col-name {
  width: 140px;
  flex-shrink: 0;
}

.col-values {
  flex: 1;
  min-width: 0;
}

.col-action {
  flex-shrink: 0;
  width: 32px;
}

.spec-empty-tip {
  text-align: center;
  color: #bfbfbf;
  font-size: 13px;
  padding: 12px 0;
  border: 1px dashed #e8e8e8;
  border-radius: 4px;
  background: #fafafa;
}
</style>
