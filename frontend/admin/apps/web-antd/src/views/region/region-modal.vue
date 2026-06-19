<script lang="ts" setup>
import type { RegionApi } from '#/api/region';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { createRegionApi, updateRegionApi } from '#/api/region';
import RegionPicker from '#/components/region-picker/index.vue';

const props = withDefaults(
  defineProps<{
    editData?:
      | null
      | (RegionApi.RegionItem & { path?: RegionApi.RegionItem[] });
    visible?: boolean;
  }>(),
  {
    editData: null,
    visible: false,
  },
);

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}>();
const isEdit = computed(() => !!props.editData?.id);
const title = computed(() => (isEdit.value ? '编辑地区' : '新增地区'));
const formRef = ref();
const loading = ref(false);

const formData = ref({
  parent_path: [] as number[],
  code: '',
  name: '',
  status: 1,
  sort: 0,
});

const rules = {
  code: [{ required: true, message: '请输入地区编码' }],
  name: [{ required: true, message: '请输入地区名称' }],
};

watch(
  () => props.editData,
  (data) => {
    formData.value = data
      ? {
          parent_path: (data.path || []).slice(0, -1).map((item) => item.id),
          code: data.code,
          name: data.name,
          status: data.status,
          sort: data.sort,
        }
      : {
          parent_path: [],
          code: '',
          name: '',
          status: 1,
          sort: 0,
        };
  },
  { immediate: true },
);

async function handleSubmit() {
  await formRef.value?.validate();
  loading.value = true;
  try {
    const parentPath = formData.value.parent_path || [];
    const parent_id = parentPath[parentPath.length - 1] || 0;
    const level = parentPath.length + 1;
    const payload = {
      parent_id,
      level,
      code: formData.value.code,
      name: formData.value.name,
      status: formData.value.status,
      sort: formData.value.sort,
    };
    if (isEdit.value) {
      await updateRegionApi(props.editData!.id, payload);
      message.success('更新成功');
    } else {
      await createRegionApi(payload);
      message.success('创建成功');
    }
    emit('success');
    emit('update:visible', false);
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <a-modal
    :open="visible"
    :title="title"
    :confirm-loading="loading"
    @ok="handleSubmit"
    @cancel="() => emit('update:visible', false)"
  >
    <a-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      :label-col="{ style: { width: '100px' } }"
      class="pt-4"
    >
      <a-form-item label="父级地区">
        <RegionPicker
          v-model:value="formData.parent_path"
          placeholder="为空表示省级地区"
        />
      </a-form-item>
      <a-form-item label="地区编码" name="code">
        <a-input v-model:value="formData.code" />
      </a-form-item>
      <a-form-item label="地区名称" name="name">
        <a-input v-model:value="formData.name" />
      </a-form-item>
      <a-form-item label="排序">
        <a-input-number
          v-model:value="formData.sort"
          :min="0"
          style="width: 100%"
        />
      </a-form-item>
      <a-form-item label="状态">
        <a-switch
          v-model:checked="formData.status"
          :checked-value="1"
          :un-checked-value="0"
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
