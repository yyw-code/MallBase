<script setup lang="ts">
import type { RegionApi } from '#/api/region';

import { computed, ref, watch } from 'vue';

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
    leafOnly?: boolean;
    multiple?: boolean;
    placeholder?: string;
    value?: Array<number | number[]> | number[];
  }>(),
  {
    value: () => [],
    multiple: false,
    placeholder: '请选择地区',
    leafOnly: true,
  },
);

const emit = defineEmits<{
  (e: 'update:value', value: RegionPickerValue): void;
}>();

const options = ref<CascaderOption[]>([]);
const innerValue = ref<RegionPickerValue>(props.multiple ? [] : []);
const resolvedLeafIds = ref(new Set<number>());
const resolvedPathLabels = ref(new Map<number, string[]>());

watch(
  () => props.value,
  async (value) => {
    innerValue.value = normalizeIncomingValue(
      value ?? (props.multiple ? [] : []),
    );
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

  return value.map((item) => {
    if (Array.isArray(item)) {
      return item.map(Number).filter((id) => Number.isInteger(id) && id > 0);
    }

    const normalized = Number(item);
    return Number.isInteger(normalized) && normalized > 0 ? normalized : item;
  }) as RegionPickerValue;
}

async function loadRootOptions() {
  if (options.value.length > 0) return;
  const list = await getRegionChildrenApi(0);
  options.value = list.map((item) => mapOption(item));
}

function mapOption(item: RegionApi.RegionItem): CascaderOption {
  return {
    value: item.id,
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

  for (const leafId of values) {
    if (resolvedLeafIds.value.has(leafId)) {
      continue;
    }
    await mergePath(leafId);
  }
}

async function mergePath(leafId: number) {
  if (!leafId) return;
  const path = await getRegionPathApi(leafId);
  if (!Array.isArray(path) || path.length === 0) return;
  resolvedPathLabels.value.set(
    leafId,
    path.map((node) => node.name),
  );

  if (props.multiple) {
    const current = (innerValue.value as Array<number | number[]>) || [];
    innerValue.value = current.map((item) => {
      if (Array.isArray(item)) {
        return item;
      }
      return item === leafId ? path.map((node) => node.id) : item;
    });
  }

  let current = options.value;
  for (const node of path) {
    let existing = current.find((item) => item.value === node.id);
    if (!existing) {
      existing = mapOption(node);
      current.push(existing);
    }
    if (!existing.children && !existing.isLeaf) {
      existing.children = [];
    }
    current = existing.children || [];
  }

  resolvedLeafIds.value.add(leafId);
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

async function handleLoadData(selectedOptions: CascaderOption[]) {
  const targetOption = selectedOptions[selectedOptions.length - 1];
  if (!targetOption || targetOption.isLeaf) {
    return;
  }

  const children = await getRegionChildrenApi(targetOption.value);
  targetOption.children = children.map((item) => mapOption(item));
}
</script>

<template>
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
  />
</template>
