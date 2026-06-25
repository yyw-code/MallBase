<script lang="ts" setup>
import type { ClientDecorateApi, ClientPageApi } from '#/api/client';

import { computed, onBeforeUnmount, ref, watch } from 'vue';

import { getClientDecorateTargetPickerApi } from '#/api/client';

defineOptions({ name: 'ClientDecorateTargetPicker' });

const props = withDefaults(
  defineProps<{
    disabled?: boolean;
    placeholder?: string;
    value?: string;
  }>(),
  {
    disabled: false,
    placeholder: '输入链接或选择跳转目标',
    value: '',
  },
);

const emit = defineEmits<{
  'update:value': [value: string];
}>();

type PickerSectionKey = 'custom' | 'goods' | 'page' | string;

type PickerTargetItem = {
  color?: string;
  depth?: number;
  desc?: string;
  image?: number | string;
  key: number | string;
  path: string;
  tags?: string[];
  title: string;
};

type PickerTargetGroup = {
  count: number;
  items: PickerTargetItem[];
  key: string;
  label: string;
};

type PickerTargetSection = {
  count: number;
  groups: PickerTargetGroup[];
  key: PickerSectionKey;
  label: string;
};

const GOODS_DETAIL_PATH = '/pages-sub/goods/detail';
const GOODS_LIST_PATH = '/pages-sub/goods/list';

const modalOpen = ref(false);
const loading = ref(false);
const keyword = ref('');
const activeSectionKey = ref<PickerSectionKey>('page');
const activeGroupKey = ref('');
const pageGroups = ref<ClientPageApi.PagePickerGroup[]>([]);
const goods = ref<ClientDecorateApi.ProductPickerGoodsItem[]>([]);
const categories = ref<ClientDecorateApi.ProductPickerCategoryItem[]>([]);
const brands = ref<ClientDecorateApi.ProductPickerBrandItem[]>([]);
const tags = ref<ClientDecorateApi.ProductPickerTagItem[]>([]);
const extensionSections = ref<ClientDecorateApi.TargetPickerSection[]>([]);
const inputValue = ref(props.value || '');
let searchTimer: null | ReturnType<typeof setTimeout> = null;

const systemListTargets: PickerTargetItem[] = [
  {
    desc: '默认排序',
    key: 'goods-system-all',
    path: GOODS_LIST_PATH,
    title: '全部商品',
  },
  {
    desc: '推荐标记',
    key: 'goods-system-recommend',
    path: `${GOODS_LIST_PATH}?is_recommend=1`,
    title: '推荐商品',
  },
  {
    desc: '新品标记',
    key: 'goods-system-new',
    path: `${GOODS_LIST_PATH}?is_new=1`,
    title: '新品商品',
  },
  {
    desc: '热销标记',
    key: 'goods-system-hot',
    path: `${GOODS_LIST_PATH}?is_hot=1`,
    title: '热销商品',
  },
];

const allPageItems = computed(() =>
  pageGroups.value.flatMap((group) => group.items),
);

const categoryParentMap = computed(
  () =>
    new Map(
      categories.value.map((item) => [Number(item.id), Number(item.pid || 0)]),
    ),
);

const categoryDepth = (item: ClientDecorateApi.ProductPickerCategoryItem) => {
  let depth = 0;
  let pid = Number(item.pid || 0);

  while (pid > 0 && depth < 4) {
    depth += 1;
    pid = categoryParentMap.value.get(pid) || 0;
  }

  return depth;
};

const normalizeTargetImageUrl = (value: unknown) => {
  if (typeof value !== 'string' || value.length === 0) return '';
  if (/^\d+$/.test(value.trim())) return '';
  if (/^(?:https?:|data:image|blob:)/.test(value)) return value;
  const apiBase = import.meta.env.VITE_GLOB_API_URL || '';
  if (!value.startsWith('/')) {
    if (value.startsWith('uploads/') || value.startsWith('static/')) {
      try {
        return `${new URL(apiBase, window.location.origin).origin}/${value}`;
      } catch {
        return `/${value}`;
      }
    }
    return value;
  }
  try {
    return `${new URL(apiBase, window.location.origin).origin}${value}`;
  } catch {
    return value;
  }
};

const productImageUrl = (item: ClientDecorateApi.ProductPickerGoodsItem) =>
  normalizeTargetImageUrl(item.main_image_full_url || item.main_image || '');

const buildPath = (
  path: string,
  params: Record<string, number | string | undefined>,
) => {
  const query = Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== '')
    .map(
      ([key, value]) =>
        `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`,
    )
    .join('&');

  return query ? `${path}?${query}` : path;
};

const pageSection = computed<PickerTargetSection>(() => ({
  count: allPageItems.value.length,
  groups: pageGroups.value.map((group) => ({
    count: group.count,
    items: group.items.map((item) => ({
      desc: item.path,
      key: `page-${item.id}`,
      path: item.path,
      tags: [
        item.page_type_label,
        ...(item.need_login === 1 ? ['需登录'] : []),
      ],
      title: item.name,
    })),
    key: `page:${group.key}`,
    label: group.label,
  })),
  key: 'page',
  label: '页面',
}));

const goodsSection = computed<PickerTargetSection>(() => {
  const groups: PickerTargetGroup[] = [
    {
      count: goods.value.length,
      items: goods.value.map((item) => ({
        desc: [`¥${item.price}`, item.category_name, item.brand_name]
          .filter(Boolean)
          .join(' · '),
        image: productImageUrl(item),
        key: `goods-${item.id}`,
        path: buildPath(GOODS_DETAIL_PATH, { id: item.id }),
        title: item.name,
      })),
      key: 'goods:detail',
      label: '商品详情',
    },
    {
      count: categories.value.length,
      items: categories.value.map((item) => ({
        depth: categoryDepth(item),
        desc: `ID ${item.id}`,
        key: `goods-category-${item.id}`,
        path: buildPath(GOODS_LIST_PATH, { category_id: item.id }),
        title: item.name,
      })),
      key: 'goods:category',
      label: '分类列表',
    },
    {
      count: brands.value.length,
      items: brands.value.map((item) => ({
        desc: `ID ${item.id}`,
        key: `goods-brand-${item.id}`,
        path: buildPath(GOODS_LIST_PATH, { brand_id: item.id }),
        title: item.name,
      })),
      key: 'goods:brand',
      label: '品牌列表',
    },
    {
      count: tags.value.length,
      items: tags.value.map((item) => ({
        color: item.color,
        desc: `ID ${item.id}`,
        key: `goods-tag-${item.id}`,
        path: buildPath(GOODS_LIST_PATH, { tag_ids: item.id }),
        title: item.name,
      })),
      key: 'goods:tag',
      label: '标签列表',
    },
    {
      count: systemListTargets.length,
      items: systemListTargets,
      key: 'goods:system',
      label: '系统列表',
    },
  ];

  return {
    count: groups.reduce((total, group) => total + group.count, 0),
    groups,
    key: 'goods',
    label: '商品',
  };
});

const normalizedExtensionSections = computed<PickerTargetSection[]>(() =>
  extensionSections.value.map((section) => {
    const groups = section.groups.map((group) => ({
      count: group.count ?? group.items.length,
      items: group.items.map((item) => ({
        desc: item.desc,
        key: item.key || item.path,
        path: item.path,
        tags: item.tags,
        title: item.title || item.label || item.path,
      })),
      key: `ext:${section.key}:${group.key}`,
      label: group.label,
    }));

    return {
      count:
        section.count ??
        groups.reduce((total, group) => total + group.count, 0),
      groups,
      key: `ext:${section.key}`,
      label: section.label,
    };
  }),
);

const customSection = computed<PickerTargetSection>(() => ({
  count: inputValue.value ? 1 : 0,
  groups: [
    {
      count: inputValue.value ? 1 : 0,
      items: [],
      key: 'custom:input',
      label: '自定义链接',
    },
  ],
  key: 'custom',
  label: '自定义',
}));

const targetSections = computed<PickerTargetSection[]>(() => [
  pageSection.value,
  goodsSection.value,
  ...normalizedExtensionSections.value,
  customSection.value,
]);

const activeSection = computed(
  () =>
    targetSections.value.find(
      (section) => section.key === activeSectionKey.value,
    ) || targetSections.value[0],
);

const activeGroups = computed(() => activeSection.value?.groups || []);

const activeGroup = computed(
  () =>
    activeGroups.value.find((group) => group.key === activeGroupKey.value) ||
    activeGroups.value[0],
);

const selectedTarget = computed(() => {
  if (!inputValue.value) return null;

  for (const section of targetSections.value) {
    for (const group of section.groups) {
      const item = group.items.find(
        (target) => target.path === inputValue.value,
      );
      if (item) {
        return {
          group,
          item,
          section,
        };
      }
    }
  }

  return null;
});

const currentTargetLabel = computed(() => {
  if (selectedTarget.value) {
    return `${selectedTarget.value.section.label} / ${selectedTarget.value.group.label}`;
  }

  const value = inputValue.value || '';
  if (value.startsWith(GOODS_DETAIL_PATH)) return '商品 / 商品详情';
  if (value.startsWith(GOODS_LIST_PATH)) return '商品 / 商品列表';
  if (value.startsWith('/pages')) return '页面';
  return value ? '自定义链接' : '未设置';
});

const searchPlaceholder = computed(() => {
  if (activeSectionKey.value === 'page') return '搜索页面名称、路径或备注';
  if (activeSectionKey.value === 'goods') {
    return '搜索商品、分类、品牌或标签';
  }
  return `搜索${activeSection.value?.label || '目标'}`;
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

watch(activeSectionKey, () => {
  activeGroupKey.value = activeGroups.value[0]?.key || '';
});

watch(keyword, () => {
  if (!modalOpen.value) return;
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    void loadPickerData();
  }, 260);
});

const ensureActiveGroup = () => {
  if (
    activeGroupKey.value &&
    activeGroups.value.some((group) => group.key === activeGroupKey.value)
  ) {
    return;
  }
  activeGroupKey.value = activeGroups.value[0]?.key || '';
};

const loadPickerData = async () => {
  loading.value = true;
  try {
    const result = await getClientDecorateTargetPickerApi({
      keyword: keyword.value.trim() || undefined,
    });
    pageGroups.value = result.pages?.groups || [];
    goods.value = result.goods || [];
    categories.value = result.categories || [];
    brands.value = result.brands || [];
    tags.value = result.tags || [];
    extensionSections.value = result.sections || [];
    ensureActiveGroup();
  } catch (error) {
    console.error('加载跳转目标失败:', error);
    pageGroups.value = [];
    goods.value = [];
    categories.value = [];
    brands.value = [];
    tags.value = [];
    extensionSections.value = [];
  } finally {
    loading.value = false;
  }
};

const queryParams = (path: string) => {
  const query = path.split('?')[1] || '';
  return new URLSearchParams(query);
};

const activateByPath = (path: string) => {
  for (const section of targetSections.value) {
    for (const group of section.groups) {
      if (group.items.some((item) => item.path === path)) {
        activeSectionKey.value = section.key;
        activeGroupKey.value = group.key;
        return;
      }
    }
  }

  const cleanPath = path.split('?')[0] || '';
  if (cleanPath === GOODS_DETAIL_PATH) {
    activeSectionKey.value = 'goods';
    activeGroupKey.value = 'goods:detail';
    return;
  }

  if (cleanPath === GOODS_LIST_PATH) {
    const params = queryParams(path);
    activeSectionKey.value = 'goods';
    if (params.has('category_id')) {
      activeGroupKey.value = 'goods:category';
    } else if (params.has('brand_id')) {
      activeGroupKey.value = 'goods:brand';
    } else if (params.has('tag_id') || params.has('tag_ids')) {
      activeGroupKey.value = 'goods:tag';
    } else {
      activeGroupKey.value = 'goods:system';
    }
    return;
  }

  if (path.startsWith('/pages')) {
    activeSectionKey.value = 'page';
    activeGroupKey.value = pageGroups.value[0]?.key
      ? `page:${pageGroups.value[0].key}`
      : '';
    return;
  }

  activeSectionKey.value = 'custom';
  activeGroupKey.value = 'custom:input';
};

const openPicker = async () => {
  if (props.disabled) return;
  modalOpen.value = true;
  activateByPath(inputValue.value);
  keyword.value = '';
  await loadPickerData();
  activateByPath(inputValue.value);
  ensureActiveGroup();
};

const resetSearch = async () => {
  keyword.value = '';
  await loadPickerData();
};

const selectPath = (path: string) => {
  inputValue.value = path;
  modalOpen.value = false;
};

const clearLink = () => {
  inputValue.value = '';
};

const confirmCustom = () => {
  if (!inputValue.value) return;
  modalOpen.value = false;
};

onBeforeUnmount(() => {
  if (searchTimer) clearTimeout(searchTimer);
});
</script>

<template>
  <div class="target-picker">
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
      title="选择跳转目标"
      width="960px"
    >
      <div class="target-modal">
        <aside class="target-modal__side">
          <button
            v-for="section in targetSections"
            :key="section.key"
            class="target-section"
            :class="{ active: activeSectionKey === section.key }"
            type="button"
            @click="activeSectionKey = section.key"
          >
            <span>{{ section.label }}</span>
            <em>{{ section.count }}</em>
          </button>
        </aside>

        <section class="target-modal__main">
          <div
            v-if="activeSectionKey !== 'custom'"
            class="target-modal__search"
          >
            <a-input
              v-model:value="keyword"
              allow-clear
              :placeholder="searchPlaceholder"
            />
            <a-button @click="loadPickerData">搜索</a-button>
            <a-button @click="resetSearch">重置</a-button>
          </div>

          <div class="target-current">
            <span>{{ currentTargetLabel }}</span>
            <strong>{{ inputValue || '未设置' }}</strong>
            <a-button
              v-if="inputValue"
              size="small"
              type="link"
              @click="clearLink"
            >
              清空
            </a-button>
            <a-button size="small" @click="modalOpen = false">完成</a-button>
          </div>

          <div
            v-if="activeSectionKey !== 'custom' && activeGroups.length > 1"
            class="target-subgroups"
          >
            <button
              v-for="group in activeGroups"
              :key="group.key"
              class="target-subgroup"
              :class="{ active: activeGroup?.key === group.key }"
              type="button"
              @click="activeGroupKey = group.key"
            >
              <span>{{ group.label }}</span>
              <em>{{ group.count }}</em>
            </button>
          </div>

          <a-spin :spinning="loading">
            <div v-if="activeSectionKey === 'custom'" class="target-custom">
              <a-input
                v-model:value="inputValue"
                allow-clear
                placeholder="/pages-sub/goods/list?keyword=夏季"
              />
              <a-button type="primary" @click="confirmCustom">应用</a-button>
            </div>

            <div v-else class="target-list">
              <button
                v-for="item in activeGroup?.items || []"
                :key="item.key"
                class="target-item"
                :class="{ active: inputValue === item.path }"
                :style="{
                  paddingLeft: item.depth
                    ? `${item.depth * 14 + 12}px`
                    : undefined,
                }"
                type="button"
                @click="selectPath(item.path)"
              >
                <span v-if="item.image" class="target-item__image">
                  <img :src="String(item.image)" alt="" />
                </span>
                <span
                  v-else-if="item.color"
                  class="target-item__dot"
                  :style="{ backgroundColor: item.color }"
                ></span>
                <span class="target-item__content">
                  <strong>{{ item.title }}</strong>
                  <span>{{ item.desc || item.path }}</span>
                </span>
                <span v-if="item.tags?.length" class="target-item__tags">
                  <a-tag v-for="tag in item.tags" :key="tag">{{ tag }}</a-tag>
                </span>
              </button>
              <a-empty
                v-if="!activeGroup || activeGroup.items.length === 0"
                :description="`没有可选择${activeGroup?.label || '目标'}`"
              />
            </div>
          </a-spin>
        </section>
      </div>
    </a-modal>
  </div>
</template>

<style scoped>
.target-picker {
  width: 100%;
}

.target-picker :deep(.ant-input-group-addon) {
  padding: 0;
}

.target-picker :deep(.ant-input-group-addon .ant-btn) {
  height: 30px;
}

.target-modal {
  display: grid;
  grid-template-columns: 168px minmax(0, 1fr);
  gap: 14px;
  min-height: 500px;
}

.target-modal__side {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-right: 12px;
  border-right: 1px solid hsl(var(--border));
}

.target-section,
.target-subgroup {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  width: 100%;
  text-align: left;
  cursor: pointer;
  border: 1px solid transparent;
  border-radius: 6px;
  background: transparent;
}

.target-section {
  padding: 9px 10px;
}

.target-subgroup {
  padding: 7px 10px;
}

.target-section.active,
.target-section:hover,
.target-subgroup.active,
.target-subgroup:hover {
  color: hsl(var(--primary));
  border-color: hsl(var(--primary) / 20%);
  background: hsl(var(--primary) / 8%);
}

.target-section em,
.target-subgroup em {
  font-style: normal;
  color: hsl(var(--muted-foreground));
}

.target-modal__main {
  min-width: 0;
}

.target-modal__search {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  gap: 8px;
  margin-bottom: 10px;
}

.target-current {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  gap: 8px;
  align-items: center;
  padding: 8px 10px;
  margin-bottom: 10px;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
  background: hsl(var(--muted) / 24%);
}

.target-current span {
  color: hsl(var(--muted-foreground));
}

.target-current strong {
  overflow: hidden;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.target-subgroups {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.target-subgroup {
  width: auto;
}

.target-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 360px;
  overflow: auto;
  padding-right: 4px;
}

.target-item {
  display: flex;
  align-items: center;
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

.target-item.active,
.target-item:hover {
  border-color: hsl(var(--primary));
  box-shadow: 0 0 0 2px hsl(var(--primary) / 10%);
}

.target-item__content {
  min-width: 0;
}

.target-item__content strong,
.target-item__content span {
  display: block;
}

.target-item__content strong {
  overflow: hidden;
  color: hsl(var(--foreground));
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.target-item__content span {
  margin-top: 4px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.target-item__tags {
  display: flex;
  flex-shrink: 0;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 4px;
  max-width: 180px;
}

.target-item__image {
  display: grid;
  flex: 0 0 48px;
  width: 48px;
  height: 48px;
  place-items: center;
  overflow: hidden;
  border-radius: 6px;
  background: hsl(var(--muted) / 42%);
}

.target-item__image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.target-item__dot {
  flex: 0 0 12px;
  width: 12px;
  height: 12px;
  border: 1px solid hsl(var(--border));
  border-radius: 50%;
  background: hsl(var(--primary));
}

.target-custom {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
}

@media (max-width: 760px) {
  .target-modal {
    grid-template-columns: 1fr;
  }

  .target-modal__side {
    flex-direction: row;
    overflow: auto;
    padding-right: 0;
    padding-bottom: 8px;
    border-right: 0;
    border-bottom: 1px solid hsl(var(--border));
  }

  .target-section {
    flex: 0 0 auto;
    width: auto;
  }
}
</style>
