<script lang="ts" setup>
import type { ClientDecorateApi } from '#/api/client';

import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { getClientDecorateProductSourcesApi } from '#/api/client';

defineOptions({ name: 'ClientDecorateProductSourcePicker' });

const props = withDefaults(
  defineProps<{
    brandId?: null | number;
    categoryId?: null | number;
    disabled?: boolean;
    ids?: number[] | string;
    previewGoods?: ClientDecorateApi.ProductPickerGoodsItem[];
    source?: string;
    sourceOptions?: SourceOption[];
    tagIds?: number[] | string;
  }>(),
  {
    brandId: null,
    categoryId: null,
    disabled: false,
    ids: '',
    previewGoods: () => [],
    source: 'recommend',
    sourceOptions: () => [],
    tagIds: '',
  },
);

const emit = defineEmits<{
  'update:brandId': [value: null | number];
  'update:categoryId': [value: null | number];
  'update:ids': [value: string];
  'update:previewGoods': [value: ClientDecorateApi.ProductPickerGoodsItem[]];
  'update:source': [value: string];
  'update:tagIds': [value: string];
}>();

type SourceOption = {
  label: string;
  value: string;
};

const fallbackSourceOptions: SourceOption[] = [
  { label: '手动商品', value: 'manual' },
  { label: '指定分类', value: 'category' },
  { label: '指定品牌', value: 'brand' },
  { label: '指定标签', value: 'tag' },
  { label: '推荐商品', value: 'recommend' },
  { label: '新品商品', value: 'new' },
  { label: '热销商品', value: 'hot' },
  { label: '综合筛选', value: 'filter' },
];

type GroupKey = 'brand' | 'category' | 'manual' | 'system' | 'tag';

const modalOpen = ref(false);
const loading = ref(false);
const keyword = ref('');
const activeGroupKey = ref<GroupKey>('manual');
const goods = ref<ClientDecorateApi.ProductPickerGoodsItem[]>([]);
const categories = ref<ClientDecorateApi.ProductPickerCategoryItem[]>([]);
const brands = ref<ClientDecorateApi.ProductPickerBrandItem[]>([]);
const tags = ref<ClientDecorateApi.ProductPickerTagItem[]>([]);
let searchTimer: null | ReturnType<typeof setTimeout> = null;

const parseIds = (value: number[] | string | undefined): number[] => {
  if (Array.isArray(value)) {
    return value
      .map(Number)
      .filter((item) => Number.isInteger(item) && item > 0);
  }

  if (typeof value !== 'string' || value.trim() === '') {
    return [];
  }

  return value
    .split(',')
    .map((item) => Number(item.trim()))
    .filter((item) => Number.isInteger(item) && item > 0);
};

const stringifyIds = (ids: number[]) =>
  [...new Set(ids.filter((item) => item > 0))].join(',');

const formatNamedSelection = (
  ids: number[],
  getName: (id: number) => string | undefined,
  emptyText: string,
) => {
  if (ids.length === 0) return emptyText;
  return ids
    .map((id) => getName(id) || `ID ${id}`)
    .slice(0, 4)
    .join('、');
};

const groupKeyBySource = (source: string | undefined): GroupKey => {
  if (source === 'category' || source === 'brand' || source === 'tag') {
    return source;
  }

  if (source === 'manual') {
    return 'manual';
  }

  return 'system';
};

const normalizedSourceOptions = computed(() =>
  props.sourceOptions.length > 0 ? props.sourceOptions : fallbackSourceOptions,
);

const systemSourceOptions = computed(() =>
  normalizedSourceOptions.value.filter((item) =>
    ['filter', 'hot', 'new', 'recommend'].includes(item.value),
  ),
);

const sourceLabel = computed(
  () =>
    normalizedSourceOptions.value.find((item) => item.value === props.source)
      ?.label || '请选择来源',
);

const goodsMap = computed(
  () => new Map(goods.value.map((item) => [Number(item.id), item])),
);

const categoryMap = computed(
  () => new Map(categories.value.map((item) => [Number(item.id), item])),
);

const brandMap = computed(
  () => new Map(brands.value.map((item) => [Number(item.id), item])),
);

const tagMap = computed(
  () => new Map(tags.value.map((item) => [Number(item.id), item])),
);

const categoryParentMap = computed(
  () =>
    new Map(
      categories.value.map((item) => [Number(item.id), Number(item.pid || 0)]),
    ),
);

const selectedGoodsIds = computed(() => parseIds(props.ids));
const selectedTagIds = computed(() => parseIds(props.tagIds));

const currentSummary = computed(() => {
  if (props.source === 'manual') {
    return formatNamedSelection(
      selectedGoodsIds.value,
      (id) => goodsMap.value.get(id)?.name,
      '未选择商品',
    );
  }

  if (props.source === 'category') {
    if (!props.categoryId) return '未选择分类';
    return (
      categoryMap.value.get(Number(props.categoryId))?.name ||
      `分类 ${props.categoryId}`
    );
  }

  if (props.source === 'brand') {
    if (!props.brandId) return '未选择品牌';
    return (
      brandMap.value.get(Number(props.brandId))?.name || `品牌 ${props.brandId}`
    );
  }

  if (props.source === 'tag') {
    return formatNamedSelection(
      selectedTagIds.value,
      (id) => tagMap.value.get(id)?.name,
      '未选择标签',
    );
  }

  return sourceLabel.value;
});

const groupTabs = computed(() => [
  {
    count: goods.value.length,
    key: 'manual' as const,
    label: '指定商品',
  },
  {
    count: categories.value.length,
    key: 'category' as const,
    label: '商品分类',
  },
  {
    count: brands.value.length,
    key: 'brand' as const,
    label: '商品品牌',
  },
  {
    count: tags.value.length,
    key: 'tag' as const,
    label: '商品标签',
  },
  {
    count: systemSourceOptions.value.length,
    key: 'system' as const,
    label: '系统条件',
  },
]);

watch(keyword, () => {
  if (!modalOpen.value) return;
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    loadPickerData();
  }, 260);
});

const loadPickerData = async () => {
  loading.value = true;
  try {
    const result = await getClientDecorateProductSourcesApi({
      keyword: keyword.value.trim() || undefined,
    });
    goods.value = result.goods || [];
    categories.value = result.categories || [];
    brands.value = result.brands || [];
    tags.value = result.tags || [];
    if (props.source === 'manual') {
      emitPreviewGoods(selectedGoodsIds.value);
    }
  } catch (error) {
    console.error('加载商品来源失败:', error);
    goods.value = [];
    categories.value = [];
    brands.value = [];
    tags.value = [];
  } finally {
    loading.value = false;
  }
};

const openPicker = async () => {
  if (props.disabled) return;
  modalOpen.value = true;
  keyword.value = '';
  activeGroupKey.value = groupKeyBySource(props.source);
  await loadPickerData();
};

const setSource = (source: string) => {
  emit('update:source', source);
  if (source === 'manual') {
    emitPreviewGoods(selectedGoodsIds.value);
  } else {
    emit('update:previewGoods', []);
  }
};

const handleSourceChange = (value: unknown) => {
  setSource(String(value || 'recommend'));
};

const selectGroup = (key: GroupKey) => {
  activeGroupKey.value = key;
  if (key !== 'system') {
    setSource(key);
  }
};

const toggleGoods = (item: ClientDecorateApi.ProductPickerGoodsItem) => {
  setSource('manual');
  const id = Number(item.id);
  const ids = selectedGoodsIds.value.includes(id)
    ? selectedGoodsIds.value.filter((itemId) => itemId !== id)
    : [...selectedGoodsIds.value, id];
  emit('update:ids', stringifyIds(ids));
  emitPreviewGoods(ids);
};

const selectCategory = (item: ClientDecorateApi.ProductPickerCategoryItem) => {
  setSource('category');
  emit('update:categoryId', Number(item.id));
  modalOpen.value = false;
};

const selectBrand = (item: ClientDecorateApi.ProductPickerBrandItem) => {
  setSource('brand');
  emit('update:brandId', Number(item.id));
  modalOpen.value = false;
};

const toggleTag = (item: ClientDecorateApi.ProductPickerTagItem) => {
  setSource('tag');
  const id = Number(item.id);
  const ids = selectedTagIds.value.includes(id)
    ? selectedTagIds.value.filter((itemId) => itemId !== id)
    : [...selectedTagIds.value, id];
  emit('update:tagIds', stringifyIds(ids));
};

const selectSystemSource = (source: string) => {
  setSource(source);
  modalOpen.value = false;
};

const clearCurrentSelection = () => {
  if (props.source === 'manual') {
    emit('update:ids', '');
    emit('update:previewGoods', []);
  }
  if (props.source === 'category') emit('update:categoryId', null);
  if (props.source === 'brand') emit('update:brandId', null);
  if (props.source === 'tag') emit('update:tagIds', '');
};

const productImageUrl = (item: ClientDecorateApi.ProductPickerGoodsItem) =>
  item.main_image_full_url || '';

const toPreviewGoodsItem = (
  item: ClientDecorateApi.ProductPickerGoodsItem,
) => ({
  brand_id: item.brand_id,
  brand_name: item.brand_name,
  category_id: item.category_id,
  category_name: item.category_name,
  id: item.id,
  is_hot: item.is_hot,
  is_new: item.is_new,
  is_recommend: item.is_recommend,
  main_image: item.main_image,
  main_image_full_url: item.main_image_full_url,
  market_price: item.market_price,
  name: item.name,
  price: item.price,
  sales: item.sales,
  subtitle: item.subtitle,
});

const emitPreviewGoods = (ids: number[]) => {
  const selected = ids
    .map((id) => goodsMap.value.get(id))
    .filter(
      (item): item is ClientDecorateApi.ProductPickerGoodsItem =>
        item !== undefined,
    )
    .map((item) => toPreviewGoodsItem(item));

  emit('update:previewGoods', selected);
};

const categoryDepth = (item: ClientDecorateApi.ProductPickerCategoryItem) => {
  let depth = 0;
  let pid = Number(item.pid || 0);

  while (pid > 0 && depth < 4) {
    depth += 1;
    pid = categoryParentMap.value.get(pid) || 0;
  }

  return depth;
};

onBeforeUnmount(() => {
  if (searchTimer) clearTimeout(searchTimer);
});

onMounted(() => {
  void loadPickerData();
});
</script>

<template>
  <div class="product-source-picker">
    <div class="product-source-picker__control">
      <a-select
        :value="source"
        :disabled="disabled"
        :options="normalizedSourceOptions"
        @change="handleSourceChange"
      />
      <a-button :disabled="disabled" @click="openPicker">选择数据</a-button>
    </div>
    <div class="product-source-picker__summary">
      <span>{{ sourceLabel }}</span>
      <strong>{{ currentSummary }}</strong>
    </div>

    <a-modal
      v-model:open="modalOpen"
      :footer="null"
      title="选择商品来源"
      width="880px"
    >
      <div class="product-source-modal">
        <aside class="product-source-modal__side">
          <button
            v-for="group in groupTabs"
            :key="group.key"
            class="product-source-group"
            :class="{ active: activeGroupKey === group.key }"
            type="button"
            @click="selectGroup(group.key)"
          >
            <span>{{ group.label }}</span>
            <em>{{ group.count }}</em>
          </button>
        </aside>

        <section class="product-source-modal__main">
          <div class="product-source-modal__search">
            <a-input
              v-model:value="keyword"
              allow-clear
              placeholder="搜索商品、分类、品牌或标签"
            />
            <a-button @click="loadPickerData">搜索</a-button>
          </div>

          <div class="product-source-current">
            <span>当前来源</span>
            <strong>{{ sourceLabel }}：{{ currentSummary }}</strong>
            <a-button
              v-if="
                ['brand', 'category', 'manual', 'tag'].includes(source || '')
              "
              size="small"
              type="link"
              @click="clearCurrentSelection"
            >
              清空
            </a-button>
            <a-button size="small" @click="modalOpen = false">完成</a-button>
          </div>

          <a-spin :spinning="loading">
            <div v-if="activeGroupKey === 'manual'" class="product-source-list">
              <button
                v-for="item in goods"
                :key="item.id"
                class="product-source-item product-source-item--goods"
                :class="{ active: selectedGoodsIds.includes(Number(item.id)) }"
                type="button"
                @click="toggleGoods(item)"
              >
                <span class="product-source-item__image">
                  <img
                    v-if="productImageUrl(item)"
                    :src="productImageUrl(item)"
                    alt=""
                  />
                </span>
                <span class="product-source-item__content">
                  <strong>{{ item.name }}</strong>
                  <span>
                    ¥{{ item.price }}
                    <template v-if="item.category_name">
                      · {{ item.category_name }}
                    </template>
                    <template v-if="item.brand_name">
                      · {{ item.brand_name }}
                    </template>
                  </span>
                </span>
              </button>
              <a-empty v-if="goods.length === 0" description="没有可选择商品" />
            </div>

            <div
              v-else-if="activeGroupKey === 'category'"
              class="product-source-list"
            >
              <button
                v-for="item in categories"
                :key="item.id"
                class="product-source-item"
                :class="{ active: Number(categoryId) === Number(item.id) }"
                :style="{ paddingLeft: `${categoryDepth(item) * 14 + 12}px` }"
                type="button"
                @click="selectCategory(item)"
              >
                <span class="product-source-item__content">
                  <strong>{{ item.name }}</strong>
                  <span>ID {{ item.id }}</span>
                </span>
              </button>
              <a-empty
                v-if="categories.length === 0"
                description="没有可选择分类"
              />
            </div>

            <div
              v-else-if="activeGroupKey === 'brand'"
              class="product-source-list"
            >
              <button
                v-for="item in brands"
                :key="item.id"
                class="product-source-item"
                :class="{ active: Number(brandId) === Number(item.id) }"
                type="button"
                @click="selectBrand(item)"
              >
                <span class="product-source-item__content">
                  <strong>{{ item.name }}</strong>
                  <span>ID {{ item.id }}</span>
                </span>
              </button>
              <a-empty
                v-if="brands.length === 0"
                description="没有可选择品牌"
              />
            </div>

            <div
              v-else-if="activeGroupKey === 'tag'"
              class="product-source-list"
            >
              <button
                v-for="item in tags"
                :key="item.id"
                class="product-source-item"
                :class="{ active: selectedTagIds.includes(Number(item.id)) }"
                type="button"
                @click="toggleTag(item)"
              >
                <span
                  class="product-source-item__dot"
                  :style="{ backgroundColor: item.color || undefined }"
                ></span>
                <span class="product-source-item__content">
                  <strong>{{ item.name }}</strong>
                  <span>ID {{ item.id }}</span>
                </span>
              </button>
              <a-empty v-if="tags.length === 0" description="没有可选择标签" />
            </div>

            <div v-else class="product-source-system">
              <button
                v-for="item in systemSourceOptions"
                :key="item.value"
                class="product-source-system__item"
                :class="{ active: source === item.value }"
                type="button"
                @click="selectSystemSource(item.value)"
              >
                <strong>{{ item.label }}</strong>
                <span>
                  {{
                    item.value === 'filter'
                      ? '按排序和数量综合取商品'
                      : '使用商品标记自动取数'
                  }}
                </span>
              </button>
            </div>
          </a-spin>
        </section>
      </div>
    </a-modal>
  </div>
</template>

<style scoped>
.product-source-picker {
  width: 100%;
}

.product-source-picker__control {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
}

.product-source-picker__summary {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 8px;
  align-items: center;
  margin-top: 8px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.product-source-picker__summary strong {
  overflow: hidden;
  color: hsl(var(--foreground));
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-source-modal {
  display: grid;
  grid-template-columns: 168px minmax(0, 1fr);
  gap: 14px;
  min-height: 460px;
}

.product-source-modal__side {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding-right: 12px;
  border-right: 1px solid hsl(var(--border));
}

.product-source-group {
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

.product-source-group.active,
.product-source-group:hover {
  color: hsl(var(--primary));
  border-color: hsl(var(--primary) / 20%);
  background: hsl(var(--primary) / 8%);
}

.product-source-group em {
  font-style: normal;
  color: hsl(var(--muted-foreground));
}

.product-source-modal__main {
  min-width: 0;
}

.product-source-modal__search {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
  margin-bottom: 10px;
}

.product-source-current {
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

.product-source-current span {
  color: hsl(var(--muted-foreground));
}

.product-source-current strong {
  overflow: hidden;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-source-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 360px;
  overflow: auto;
  padding-right: 4px;
}

.product-source-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 10px 12px;
  text-align: left;
  cursor: pointer;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
  background: hsl(var(--background));
}

.product-source-item.active,
.product-source-item:hover,
.product-source-system__item.active,
.product-source-system__item:hover {
  border-color: hsl(var(--primary));
  box-shadow: 0 0 0 2px hsl(var(--primary) / 10%);
}

.product-source-item__image {
  display: grid;
  flex: 0 0 48px;
  width: 48px;
  height: 48px;
  place-items: center;
  overflow: hidden;
  border-radius: 6px;
  background: hsl(var(--muted) / 42%);
}

.product-source-item__image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.product-source-item__content {
  min-width: 0;
}

.product-source-item__content strong,
.product-source-item__content span {
  display: block;
}

.product-source-item__content strong {
  overflow: hidden;
  color: hsl(var(--foreground));
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.product-source-item__content span {
  margin-top: 4px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

.product-source-item__dot {
  flex: 0 0 12px;
  width: 12px;
  height: 12px;
  border: 1px solid hsl(var(--border));
  border-radius: 50%;
  background: hsl(var(--primary));
}

.product-source-system {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.product-source-system__item {
  padding: 12px;
  text-align: left;
  cursor: pointer;
  border: 1px solid hsl(var(--border));
  border-radius: 6px;
  background: hsl(var(--background));
}

.product-source-system__item strong,
.product-source-system__item span {
  display: block;
}

.product-source-system__item span {
  margin-top: 6px;
  color: hsl(var(--muted-foreground));
  font-size: 12px;
}

@media (max-width: 760px) {
  .product-source-modal {
    grid-template-columns: 1fr;
  }

  .product-source-modal__side {
    flex-direction: row;
    overflow: auto;
    padding-right: 0;
    padding-bottom: 8px;
    border-right: 0;
    border-bottom: 1px solid hsl(var(--border));
  }

  .product-source-group {
    flex: 0 0 auto;
    width: auto;
  }

  .product-source-system {
    grid-template-columns: 1fr;
  }
}
</style>
