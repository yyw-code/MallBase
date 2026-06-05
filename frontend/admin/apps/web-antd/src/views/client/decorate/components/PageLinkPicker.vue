<script lang="ts" setup>
import type { ClientPageApi } from '#/api/client';

import { computed, onBeforeUnmount, ref, watch } from 'vue';

import { getClientPagePickerApi } from '#/api/client';

defineOptions({ name: 'ClientDecoratePageLinkPicker' });

const props = withDefaults(
  defineProps<{
    disabled?: boolean;
    placeholder?: string;
    value?: string;
  }>(),
  {
    disabled: false,
    placeholder: '输入链接或选择页面',
    value: '',
  },
);

const emit = defineEmits<{
  'update:value': [value: string];
}>();

type PickerItem = ClientPageApi.PagePickerItem & {
  groupLabel?: string;
};

const modalOpen = ref(false);
const loading = ref(false);
const keyword = ref('');
const activeGroupKey = ref('all');
const groups = ref<ClientPageApi.PagePickerGroup[]>([]);
const inputValue = ref(props.value || '');
let searchTimer: null | ReturnType<typeof setTimeout> = null;

const allItems = computed<PickerItem[]>(() =>
  groups.value.flatMap((group) =>
    group.items.map((item) => ({
      ...item,
      groupLabel: group.label,
    })),
  ),
);

const groupTabs = computed(() => [
  {
    count: allItems.value.length,
    key: 'all',
    label: '全部页面',
  },
  ...groups.value.map((group) => ({
    count: group.count,
    key: group.key,
    label: group.label,
  })),
]);

const activeItems = computed<PickerItem[]>(() => {
  if (activeGroupKey.value === 'all') return allItems.value;
  const group = groups.value.find((item) => item.key === activeGroupKey.value);
  return (
    group?.items.map((item) => ({
      ...item,
      groupLabel: group.label,
    })) || []
  );
});

watch(
  () => props.value,
  (value) => {
    inputValue.value = value || '';
  },
);

watch(inputValue, (value) => {
  emit('update:value', value || '');
});

watch(keyword, () => {
  if (!modalOpen.value) return;
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    loadPickerPages();
  }, 260);
});

const loadPickerPages = async () => {
  loading.value = true;
  try {
    const result = await getClientPagePickerApi({
      keyword: keyword.value.trim() || undefined,
    });
    groups.value = result.groups || [];
    if (
      activeGroupKey.value !== 'all' &&
      !groups.value.some((group) => group.key === activeGroupKey.value)
    ) {
      activeGroupKey.value = 'all';
    }
  } catch (error) {
    console.error('加载页面链接失败:', error);
    groups.value = [];
  } finally {
    loading.value = false;
  }
};

const openPicker = async () => {
  if (props.disabled) return;
  modalOpen.value = true;
  activeGroupKey.value = 'all';
  keyword.value = '';
  await loadPickerPages();
};

const selectPage = (item: PickerItem) => {
  inputValue.value = item.path;
  modalOpen.value = false;
};

const clearLink = () => {
  inputValue.value = '';
};

onBeforeUnmount(() => {
  if (searchTimer) clearTimeout(searchTimer);
});
</script>

<template>
  <div class="page-link-picker">
    <a-input
      v-model:value="inputValue"
      allow-clear
      :disabled="disabled"
      :placeholder="placeholder"
    >
      <template #addonAfter>
        <a-button :disabled="disabled" type="link" @click="openPicker">
          选择
        </a-button>
      </template>
    </a-input>

    <a-modal
      v-model:open="modalOpen"
      :footer="null"
      title="选择页面链接"
      width="820px"
    >
      <div class="page-link-modal">
        <aside class="page-link-modal__side">
          <button
            v-for="group in groupTabs"
            :key="group.key"
            class="page-link-group"
            :class="{ active: activeGroupKey === group.key }"
            type="button"
            @click="activeGroupKey = group.key"
          >
            <span>{{ group.label }}</span>
            <em>{{ group.count }}</em>
          </button>
        </aside>

        <section class="page-link-modal__main">
          <div class="page-link-modal__search">
            <a-input
              v-model:value="keyword"
              allow-clear
              placeholder="搜索页面名称、路径或备注"
            />
            <a-button @click="loadPickerPages">搜索</a-button>
          </div>

          <div class="page-link-current">
            <span>当前链接</span>
            <strong>{{ inputValue || '未设置' }}</strong>
            <a-button
              v-if="inputValue"
              size="small"
              type="link"
              @click="clearLink"
            >
              清空
            </a-button>
          </div>

          <a-spin :spinning="loading">
            <div v-if="activeItems.length > 0" class="page-link-list">
              <button
                v-for="item in activeItems"
                :key="item.id"
                class="page-link-item"
                :class="{ active: inputValue === item.path }"
                type="button"
                @click="selectPage(item)"
              >
                <div>
                  <strong>{{ item.name }}</strong>
                  <span>{{ item.path }}</span>
                </div>
                <div class="page-link-item__tags">
                  <a-tag>{{ item.groupLabel || item.category_label }}</a-tag>
                  <a-tag>{{ item.page_type_label }}</a-tag>
                  <a-tag v-if="item.need_login === 1" color="orange">
                    需登录
                  </a-tag>
                </div>
              </button>
            </div>
            <a-empty v-else description="没有找到可选择的页面" />
          </a-spin>
        </section>
      </div>
    </a-modal>
  </div>
</template>

<style scoped>
.page-link-picker {
  width: 100%;
}

.page-link-picker :deep(.ant-input-group-addon) {
  padding: 0;
}

.page-link-picker :deep(.ant-input-group-addon .ant-btn) {
  height: 30px;
}

.page-link-modal {
  display: grid;
  grid-template-columns: 168px minmax(0, 1fr);
  gap: 14px;
  min-height: 430px;
}

.page-link-modal__side {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-right: 12px;
  border-right: 1px solid hsl(var(--border));
}

.page-link-group {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  padding: 9px 10px;
  text-align: left;
  cursor: pointer;
  border: 1px solid transparent;
  border-radius: 6px;
  background: transparent;
}

.page-link-group.active,
.page-link-group:hover {
  color: hsl(var(--primary));
  border-color: hsl(var(--primary) / 20%);
  background: hsl(var(--primary) / 8%);
}

.page-link-group em {
  font-style: normal;
  color: hsl(var(--muted-foreground));
}

.page-link-modal__main {
  min-width: 0;
}

.page-link-modal__search {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
  margin-bottom: 10px;
}

.page-link-current {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
  padding: 8px 10px;
  margin-bottom: 10px;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
  background: hsl(var(--muted) / 24%);
}

.page-link-current span {
  color: hsl(var(--muted-foreground));
}

.page-link-current strong {
  overflow: hidden;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.page-link-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 340px;
  overflow: auto;
  padding-right: 4px;
}

.page-link-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  padding: 10px 12px;
  text-align: left;
  cursor: pointer;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
  background: hsl(var(--background));
}

.page-link-item.active,
.page-link-item:hover {
  border-color: hsl(var(--primary));
  box-shadow: 0 0 0 2px hsl(var(--primary) / 10%);
}

.page-link-item strong,
.page-link-item span {
  display: block;
}

.page-link-item span {
  margin-top: 4px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.page-link-item__tags {
  display: flex;
  flex-shrink: 0;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 4px;
  max-width: 160px;
}

@media (max-width: 760px) {
  .page-link-modal {
    grid-template-columns: 1fr;
  }

  .page-link-modal__side {
    flex-direction: row;
    overflow: auto;
    padding-right: 0;
    padding-bottom: 8px;
    border-right: 0;
    border-bottom: 1px solid hsl(var(--border));
  }

  .page-link-group {
    flex: 0 0 auto;
    width: auto;
  }
}
</style>
