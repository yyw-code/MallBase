<script setup lang="ts">
import type { RegionApi } from '#/api/region';

import { computed, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getRegionChildrenApi, getRegionPathApi } from '#/api/region';

interface CascaderOption {
  value: number;
  label: string;
  level: number;
  isLeaf?: boolean;
  children?: CascaderOption[];
}

type RegionPickerValue = Array<number | number[]> | number[];

const props = withDefaults(
  defineProps<{
    allowSelectAll?: boolean;
    labels?: string[];
    leafOnly?: boolean;
    multiple?: boolean;
    placeholder?: string;
    value?: Array<number | number[]> | number[];
  }>(),
  {
    value: () => [],
    labels: () => [],
    multiple: false,
    placeholder: '请选择地区',
    leafOnly: true,
    allowSelectAll: false,
  },
);

const emit = defineEmits<{
  (e: 'update:value', value: RegionPickerValue): void;
}>();

const options = ref<CascaderOption[]>([]);
const innerValue = ref<RegionPickerValue>(props.multiple ? [] : []);
const resolvedLeafIds = ref(new Set<number>());
const resolvedPathLabels = ref(new Map<number, string[]>());
const selectingAll = ref(false);

const showSelectAllButton = computed(
  () => props.allowSelectAll && props.multiple,
);

watch(
  [() => props.value, () => props.labels],
  async ([value, labels]) => {
    innerValue.value = normalizeIncomingValue(
      value ?? (props.multiple ? [] : []),
    );
    preseedFromLabels(innerValue.value, labels ?? []);
    await ensureValueOptions();
  },
  { immediate: true, deep: true },
);

const cascaderValue = computed({
  get: () => innerValue.value,
  set: (value: RegionPickerValue) => {
    const normalized = normalizeIncomingValue(value);
    innerValue.value = normalized;
    emit('update:value', normalized);
  },
});

function normalizeIncomingValue(value: RegionPickerValue): RegionPickerValue {
  if (!Array.isArray(value)) {
    return props.multiple ? [] : [];
  }

  // 多选模式下 a-cascader 的 value 必须是 Array<number[]>（每项是一条路径）。
  // 后端及父组件通常只下发一维 number[]（叶子 ID 列表），
  // 这里把每个标量 ID 包装成单元素路径 [id]，cascader 会渲染成一个独立的 tag；
  // 否则 cascader 会把一维数组视作 “一个长路径”，渲染出一个把所有 ID 拼在一起的 tag。
  if (props.multiple) {
    return value.map((item) => {
      if (Array.isArray(item)) {
        return item.map(Number).filter((id) => Number.isInteger(id) && id > 0);
      }
      const normalized = Number(item);
      if (Number.isInteger(normalized) && normalized > 0) {
        return [normalized];
      }
      return [];
    }) as RegionPickerValue;
  }

  return value.map((item) => {
    if (Array.isArray(item)) {
      return item.map(Number).filter((id) => Number.isInteger(id) && id > 0);
    }

    const normalized = Number(item);
    return Number.isInteger(normalized) && normalized > 0 ? normalized : item;
  }) as RegionPickerValue;
}

function preseedFromLabels(value: RegionPickerValue, labels: string[]) {
  if (!props.multiple || !Array.isArray(value) || labels.length === 0) return;
  value.forEach((item, idx) => {
    const leafId = Array.isArray(item) ? item[item.length - 1] : item;
    const normalizedLeafId = Number(leafId);
    if (!Number.isInteger(normalizedLeafId) || normalizedLeafId <= 0) return;
    if (resolvedPathLabels.value.has(normalizedLeafId)) return;
    const rawLabel = labels[idx];
    if (typeof rawLabel !== 'string' || rawLabel.trim() === '') return;
    const parts = rawLabel
      .split(' / ')
      .map((part) => part.trim())
      .filter(Boolean);
    if (parts.length === 0) return;
    resolvedPathLabels.value.set(normalizedLeafId, parts);
    // 预填命中即视为已解析，首屏不再触发额外接口
    resolvedLeafIds.value.add(normalizedLeafId);
  });
}

async function loadRootOptions() {
  if (options.value.length > 0) return;
  const list = await getRegionChildrenApi(0);
  options.value = list.map((item) => mapOption(item));
}

function mapOption(item: RegionApi.RegionItem): CascaderOption {
  return {
    value: Number(item.id),
    label: item.name,
    level: item.level,
    isLeaf: item.level >= 4,
  };
}

async function ensureValueOptions() {
  await loadRootOptions();

  const singlePath = (innerValue.value as number[]) || [];
  const values = props.multiple
    ? ((innerValue.value as Array<number | number[]>) || [])
        .map((item) => (Array.isArray(item) ? item[item.length - 1] : item))
        .filter((item): item is number => typeof item === 'number' && item > 0)
    : [singlePath[singlePath.length - 1]].filter(
        (item): item is number => typeof item === 'number' && item > 0,
      );

  const pending = values.filter((leafId) => !resolvedLeafIds.value.has(leafId));
  if (pending.length === 0) return;

  const results = await Promise.allSettled(
    pending.map((leafId) => getRegionPathApi(leafId)),
  );

  const resolved = new Map<number, RegionApi.RegionItem[]>();
  results.forEach((result, idx) => {
    if (result.status !== 'fulfilled') return;
    const path = result.value;
    if (!Array.isArray(path) || path.length === 0) return;
    const leafId = pending[idx];
    if (typeof leafId !== 'number') return;
    resolved.set(leafId, path);
  });

  if (resolved.size === 0) return;

  for (const [leafId, path] of resolved) {
    resolvedPathLabels.value.set(
      leafId,
      path.map((node) => node.name),
    );
    mergeIntoOptions(path);
    resolvedLeafIds.value.add(leafId);
  }

  if (props.multiple) {
    const current = (innerValue.value as Array<number | number[]>) || [];
    const next = current.map((item) => {
      if (Array.isArray(item)) return item;
      const path = resolved.get(item as number);
      if (path) return path.map((node) => node.id);
      return item;
    });
    innerValue.value = next;
    emit('update:value', next);
  }
}

function mergeIntoOptions(path: RegionApi.RegionItem[]) {
  let current = options.value;
  for (const node of path) {
    const nodeId = Number(node.id);
    let existing = current.find((item) => item.value === nodeId);
    if (!existing) {
      existing = mapOption(node);
      current.push(existing);
    }
    if (!existing.children && !existing.isLeaf) {
      existing.children = [];
    }
    current = existing.children || [];
  }
}

function displayRender({
  labels,
}: {
  labels: unknown[];
  selectedOptions?: CascaderOption[];
}) {
  let normalizedLabels: string[] = [];
  if (Array.isArray(labels)) {
    normalizedLabels = labels.map((label) =>
      typeof label === 'string' || typeof label === 'number'
        ? String(label)
        : String((label as { label?: string }).label ?? ''),
    );
  } else if (labels === null || labels === undefined) {
    normalizedLabels = [];
  } else {
    normalizedLabels = [String(labels)];
  }

  if (
    normalizedLabels.length > 0 &&
    normalizedLabels.every((label) => label !== '')
  ) {
    return normalizedLabels.join(' / ');
  }

  if (props.multiple) {
    return normalizedLabels.join(' / ');
  }

  const currentPath = Array.isArray(innerValue.value)
    ? (innerValue.value as number[])
    : [];
  const leafId = currentPath[currentPath.length - 1];
  const normalizedLeafId = Number(leafId);
  if (
    Number.isInteger(normalizedLeafId) &&
    normalizedLeafId > 0 &&
    resolvedPathLabels.value.has(normalizedLeafId)
  ) {
    return resolvedPathLabels.value.get(normalizedLeafId)!.join(' / ');
  }

  return normalizedLabels.join(' / ');
}

function resolveTagText(value: unknown, label: unknown): string {
  const leafId = Array.isArray(value) ? value[value.length - 1] : value;
  const normalizedLeafId = Number(leafId);
  if (
    Number.isInteger(normalizedLeafId) &&
    normalizedLeafId > 0 &&
    resolvedPathLabels.value.has(normalizedLeafId)
  ) {
    return resolvedPathLabels.value.get(normalizedLeafId)!.join(' / ');
  }
  if (typeof label === 'string' && label.trim() !== '') {
    return label;
  }
  if (Array.isArray(label)) {
    const parts = label
      .map((part) =>
        typeof part === 'string' || typeof part === 'number'
          ? String(part)
          : String((part as { label?: string })?.label ?? ''),
      )
      .filter((part) => part !== '');
    if (parts.length > 0) {
      return parts.join(' / ');
    }
  }
  return String(value ?? '');
}

async function handleLoadData(selectedOptions: CascaderOption[]) {
  const targetOption = selectedOptions[selectedOptions.length - 1];
  if (!targetOption || targetOption.isLeaf) {
    return;
  }

  const children = await getRegionChildrenApi(targetOption.value);
  targetOption.children = children.map((item) => mapOption(item));
}

async function handleSelectAllProvinces() {
  if (!showSelectAllButton.value || selectingAll.value) return;
  selectingAll.value = true;
  try {
    const list = await getRegionChildrenApi(0);
    if (!Array.isArray(list) || list.length === 0) {
      message.warning('省份列表为空');
      return;
    }

    // 合并到 options：已存在的省份保留其 children 子树，避免把用户之前展开过的层级打掉
    for (const item of list) {
      const exists = options.value.some((opt) => opt.value === item.id);
      if (!exists) {
        options.value.push(mapOption(item));
      }
    }

    // 预写 tag 文案 + 标记已解析，tagRender 可直接命中，不再触发 region/path 查询
    for (const item of list) {
      const regionId = Number(item.id);
      if (!Number.isInteger(regionId) || regionId <= 0) continue;
      resolvedPathLabels.value.set(regionId, [item.name]);
      resolvedLeafIds.value.add(regionId);
    }

    // 多选模式下 cascader 需要 Array<number[]>，每条路径单独一个 tag
    const next = list
      .map((item) => Number(item.id))
      .filter((id) => Number.isInteger(id) && id > 0)
      .map((id) => [id]) as RegionPickerValue;
    innerValue.value = next;
    emit('update:value', next);
  } catch {
    message.error('获取省份列表失败');
  } finally {
    selectingAll.value = false;
  }
}
</script>

<template>
  <div :class="{ 'region-picker-inline': showSelectAllButton }">
    <a-cascader
      v-model:value="cascaderValue"
      :options="options"
      :load-data="handleLoadData"
      :multiple="multiple"
      :placeholder="placeholder"
      :field-names="{ label: 'label', value: 'value', children: 'children' }"
      :display-render="displayRender"
      :change-on-select="!leafOnly"
      style="width: 100%"
    >
      <template
        v-if="multiple"
        #tagRender="{ value: tagValue, label, closable, onClose }"
      >
        <a-tag
          :closable="closable"
          class="region-picker-tag"
          data-testid="region-picker-tag"
          @close="onClose"
          @mousedown.prevent
        >
          {{ resolveTagText(tagValue, label) }}
        </a-tag>
      </template>
    </a-cascader>
    <a-button
      v-if="showSelectAllButton"
      class="region-picker-select-all"
      data-testid="region-picker-select-all"
      :loading="selectingAll"
      @click="handleSelectAllProvinces"
    >
      全选省份
    </a-button>
  </div>
</template>

<style scoped>
.region-picker-tag {
  margin-inline-end: 4px;
}

.region-picker-inline {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  width: 100%;
}

.region-picker-inline > :first-child {
  flex: 1;
  min-width: 0;
}

.region-picker-select-all {
  flex: 0 0 auto;
}
</style>
