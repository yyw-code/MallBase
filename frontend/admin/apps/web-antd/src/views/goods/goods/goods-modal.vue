<script lang="ts" setup>
import type { GoodsApi } from '#/api/goods';
import type { GoodsCategoryApi } from '#/api/goods';
import type { GoodsBrandApi } from '#/api/goods';
import type { GoodsTagApi } from '#/api/goods';
import type { GoodsSpecApi } from '#/api/goods';

import type { FileInfo } from '#/components/upload';

import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';

import Sortable from 'sortablejs';
import { message } from 'ant-design-vue';

import RichTextEditor from '#/components/rich-text-editor/index.vue';
import Upload from '#/components/upload/index.vue';

import {
  batchCreateSpecValuesApi,
  createGoodsApi,
  createGoodsSpecApi,
  getAllGoodsBrandsApi,
  getAllGoodsCategoriesApi,
  getAllGoodsSpecsApi,
  getAllGoodsTagsApi,
  getGoodsInfoApi,
  updateGoodsApi,
} from '#/api/goods';

interface Props {
  visible: boolean;
  editData?: GoodsApi.GoodsItem | null;
}
interface Emits {
  (e: 'update:visible', value: boolean): void;
  (e: 'success'): void;
}

const props = withDefaults(defineProps<Props>(), { visible: false, editData: null });
const emit = defineEmits<Emits>();

const isEdit = computed(() => !!props.editData);

/* ---------- 全屏 ---------- */
const isFullscreen = ref(false);
const toggleFullscreen = () => { isFullscreen.value = !isFullscreen.value; };

/* ---------- 基本表单 ---------- */
const formData = reactive({
  name: '',
  subtitle: '',
  category_id: undefined as number | undefined,
  brand_id: undefined as number | undefined,
  unit: '件',
  price: 0,
  market_price: 0,
  stock: 0,
  main_image: undefined as FileInfo | string | undefined,
  main_video: undefined as FileInfo | string | undefined,
  images: [] as FileInfo[],
  description: '',
  sort: 0,
  status: 1,
  is_on_sale: 0,
  is_recommend: 0,
  is_new: 0,
  is_hot: 0,
  tag_ids: [] as number[],
});
const rules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  category_id: [{ required: true, message: '请选择分类', trigger: 'change' }],
};
const formRef = ref();
const loading = ref(false);
const activeTab = ref('basic');

/* ---------- 分类树 ---------- */
const categoryTreeData = ref<any[]>([]);
const buildTree = (list: GoodsCategoryApi.CategoryItem[], pid = 0): any[] =>
  list
    .filter((item) => item.pid === pid)
    .map((item) => ({ title: item.name, value: item.id, key: item.id, children: buildTree(list, item.id) }));

/* ---------- 品牌 / 标签 ---------- */
const brandOptions = ref<GoodsBrandApi.BrandItem[]>([]);
const tagOptions = ref<GoodsTagApi.TagItem[]>([]);

/* ---------- 规格 attrs 模型 ---------- */
const createLocalId = () => `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
interface AttrDetail { id: string; value: string; pic: FileInfo | string }
interface Attr { id: string; value: string; add_pic: 0 | 1; detail: AttrDetail[] }

const specType = ref<'single' | 'multi'>('single');
const attrs = ref<Attr[]>([]);
const canAddPic = computed(() => !attrs.value.some((a) => a.add_pic === 1));

/** 获取图片预览 URL（兼容 FileInfo 和字符串） */
const getPicPreviewUrl = (pic: FileInfo | string): string => {
  if (!pic) return '';
  if (typeof pic === 'object') return pic.full_url || pic.url || '';
  if (pic.startsWith('http')) return pic;
  return `${import.meta.env.VITE_GLOB_API_URL || ''}${pic}`;
};

/** 获取图片提交 URL */
const getPicUrl = (pic: FileInfo | string): string => {
  if (!pic) return '';
  if (typeof pic === 'object') return pic.url || '';
  return pic;
};

const handleAddSpec = () => {
  attrs.value.push({ id: createLocalId(), value: '', add_pic: 0, detail: [{ id: createLocalId(), value: '', pic: '' }] });
  nextTick(initValueDrag);
};

const handleRemoveSpec = (idx: number) => {
  attrs.value.splice(idx, 1);
  generateSkuCombinations();
  nextTick(() => { initSpecDrag(); initValueDrag(); });
};

const addSpecValue = (attrIdx: number) => {
  attrs.value[attrIdx]!.detail.push({ id: createLocalId(), value: '', pic: '' });
  nextTick(() => initValueDragAt(attrIdx));
};

const removeSpecValue = (attrIdx: number, detIdx: number) => {
  if (attrs.value[attrIdx]!.detail.length <= 1) {
    message.warning('至少保留一个规格值');
    return;
  }
  attrs.value[attrIdx]!.detail.splice(detIdx, 1);
  generateSkuCombinations();
};

const toggleAddPic = (e: boolean | 0 | 1, idx: number) => {
  if (e) {
    attrs.value.forEach((a, i) => { if (i !== idx) a.add_pic = 0; });
  }
  generateSkuCombinations();
};

/* ---------- 拖拽 (SortableJS) ---------- */
const specListRef = ref<HTMLElement | null>(null);
const valueListRefs = ref<(HTMLElement | null)[]>([]);
let specSortable: Sortable | null = null;
const valueSortables: Sortable[] = [];

const initSpecDrag = () => {
  if (!specListRef.value) return;
  specSortable?.destroy();
  specSortable = Sortable.create(specListRef.value, {
    handle: '.spec-drag-handle',
    draggable: '.spec-item',
    animation: 150,
    onEnd({ oldIndex, newIndex, item, from }) {
      if (oldIndex === newIndex) return;
      // SortableJS 已经移动了 DOM，先把它恢复原位，让 Vue 来统一渲染
      const children = [...from.children];
      if (oldIndex! < newIndex!) {
        from.insertBefore(item, children[oldIndex!]!);
      } else {
        from.insertBefore(item, children[oldIndex! + 1] ?? null);
      }
      const moved = attrs.value.splice(oldIndex!, 1)[0]!;
      attrs.value.splice(newIndex!, 0, moved);
      generateSkuCombinations();
      // 规格重排后无需重新初始化值拖拽：onEnd 使用动态索引查找，不依赖创建时的 attrIdx
    },
  });
};

const initValueDragAt = (attrIdx: number) => {
  const el = valueListRefs.value[attrIdx];
  if (!el) return;
  valueSortables[attrIdx]?.destroy();
  valueSortables[attrIdx] = Sortable.create(el, {
    handle: '.val-drag-handle',
    draggable: '.spec-val-item',
    animation: 150,
    onEnd({ oldIndex, newIndex, item, from }) {
      if (oldIndex === newIndex) return;
      // 运行时动态查找当前索引，规格重排后无需重建实例
      const currentIdx = valueListRefs.value.indexOf(el);
      if (currentIdx === -1) return;
      // SortableJS 已经移动了 DOM，先把它恢复原位，让 Vue 来统一渲染
      const children = [...from.children];
      if (oldIndex! < newIndex!) {
        from.insertBefore(item, children[oldIndex!]!);
      } else {
        from.insertBefore(item, children[oldIndex! + 1] ?? null);
      }
      const moved = attrs.value[currentIdx]!.detail.splice(oldIndex!, 1)[0]!;
      attrs.value[currentIdx]!.detail.splice(newIndex!, 0, moved);
      generateSkuCombinations();
    },
  });
};

const initValueDrag = () => {
  attrs.value.forEach((_, idx) => initValueDragAt(idx));
};

// 当多规格区域挂载时自动初始化拖拽（解决 v-else 条件渲染导致 specListRef 为 null 的时序问题）
watch(specListRef, (el) => {
  if (el) {
    nextTick(() => {
      initSpecDrag();
      initValueDrag();
    });
  }
});

/* ---------- SKU 表格 ---------- */
interface SkuRow {
  _isBatch?: true;
  spec_values: string;
  detail: Record<string, string>;
  price: number | undefined;
  market_price: number | undefined;
  stock: number | undefined;
  sku_code: string;
  image: FileInfo | string | undefined;
}

const skuRows = ref<SkuRow[]>([]);
const batchData = reactive<Record<string, string>>({});
const multiSpecDraft = ref<{ attrs: Attr[]; skuRows: SkuRow[] }>({ attrs: [], skuRows: [] });

const cloneAttrs = (source: Attr[]): Attr[] =>
  source.map((attr) => ({
    id: attr.id || createLocalId(),
    value: attr.value,
    add_pic: attr.add_pic,
    detail: attr.detail.map((det) => ({ id: det.id || createLocalId(), value: det.value, pic: det.pic })),
  }));

const cloneSkuRows = (source: SkuRow[]): SkuRow[] =>
  source.map((row) => ({
    _isBatch: row._isBatch,
    spec_values: row.spec_values,
    detail: { ...row.detail },
    price: row.price,
    market_price: row.market_price,
    stock: row.stock,
    sku_code: row.sku_code,
    image: row.image,
  }));

const saveMultiSpecDraft = () => {
  multiSpecDraft.value = {
    attrs: cloneAttrs(attrs.value),
    skuRows: cloneSkuRows(skuRows.value),
  };
};

const restoreMultiSpecDraft = () => {
  if (multiSpecDraft.value.attrs.length === 0 && multiSpecDraft.value.skuRows.length === 0) return;
  attrs.value = cloneAttrs(multiSpecDraft.value.attrs);
  skuRows.value = cloneSkuRows(multiSpecDraft.value.skuRows);
};

const tableData = computed<SkuRow[]>(() => {
  if (skuRows.value.length === 0) return [];
  const batch: SkuRow = {
    _isBatch: true,
    spec_values: '__batch__',
    detail: {},
    price: undefined,
    market_price: undefined,
    stock: undefined,
    sku_code: '',
    image: undefined,
  };
  return [batch, ...skuRows.value];
});

const skuColumns = computed(() => {
  const specCols = attrs.value.map((attr, idx) => {
    const col: any = {
      title: attr.value || `规格${idx + 1}`,
      dataIndex: `_spec_${idx}`,
      width: 110,
      _isSpecCol: true,
      _attrIdx: idx,
    };
    if (idx === 0) {
      col.customCell = (_record: SkuRow, rowIdx: number) => {
        if (_record._isBatch) return {};
        const dataIdx = rowIdx - 1;
        const span = spanMap.value.get(`${dataIdx}_0`);
        if (span === 0) return { rowspan: 0, colSpan: 0 };
        if (span !== undefined) return { rowspan: span };
        return {};
      };
    }
    return col;
  });
  return [
    ...specCols,
    { title: '规格图', dataIndex: 'image', width: 80 },
    { title: '售价 *', dataIndex: 'price', width: 120 },
    { title: '市场价', dataIndex: 'market_price', width: 120 },
    { title: '库存 *', dataIndex: 'stock', width: 110 },
    { title: 'SKU编码', dataIndex: 'sku_code', width: 140 },
    { title: '操作', dataIndex: '_action', width: 60 },
  ];
});

const spanMap = computed<Map<string, number>>(() => {
  const map = new Map<string, number>();
  if (attrs.value.length === 0) return map;
  const rows = skuRows.value;
  let i = 0;
  while (i < rows.length) {
    const key = rows[i]!.detail[attrs.value[0]!.value] ?? '';
    let len = 1;
    for (let j = i + 1; j < rows.length; j++) {
      if ((rows[j]!.detail[attrs.value[0]!.value] ?? '') === key) len++;
      else break;
    }
    map.set(`${i}_0`, len);
    for (let k = 1; k < len; k++) map.set(`${i + k}_0`, 0);
    i += len;
  }
  return map;
});

const generateSkuCombinations = () => {
  const validAttrs = attrs.value.filter((a) => a.value && a.detail.some((d) => d.value));
  if (validAttrs.length === 0) { skuRows.value = []; return; }

  const cartesian = (...arrays: any[][]): any[][] => {
    if (arrays.length === 0) return [[]];
    const [first, ...rest] = arrays;
    const restProduct = cartesian(...rest);
    return first!.flatMap((item) => restProduct.map((product) => [item, ...product]));
  };

  const valueArrays = validAttrs.map((attr) =>
    attr.detail.filter((d) => d.value).map((d) => ({ attrName: attr.value, value: d.value })),
  );
  const combos = cartesian(...valueArrays);

  const existingMap = new Map(skuRows.value.map((r) => [r.spec_values, r]));

  skuRows.value = combos.map((combo) => {
    const specValues = combo.map((c: any) => c.value).join(',');
    const existing = existingMap.get(specValues);
    if (existing) return existing;
    const detail: Record<string, string> = {};
    for (const c of combo) detail[c.attrName] = c.value;
    return {
      spec_values: specValues,
      detail,
      price: formData.price || undefined,
      market_price: formData.market_price || undefined,
      stock: formData.stock || undefined,
      sku_code: '',
      image: undefined,
    };
  });
};

const applyBatch = () => {
  for (const row of skuRows.value) {
    if (batchData['__price__']) row.price = Number(batchData['__price__']);
    if (batchData['__market_price__']) row.market_price = Number(batchData['__market_price__']);
    if (batchData['__stock__']) row.stock = Number(batchData['__stock__']);
    if (batchData['__sku_code__']) row.sku_code = batchData['__sku_code__'] as string;
  }
  message.success('批量设置成功');
};

const clearBatch = () => {
  Object.keys(batchData).forEach((k) => { batchData[k] = ''; });
};

/* ---------- 从规格库导入 ---------- */
const specLibVisible = ref(false);
const specLibLoading = ref(false);
const specLibList = ref<GoodsSpecApi.SpecItem[]>([]);
const selectedSpecIds = ref<number[]>([]);

const openSpecLib = async () => {
  specLibVisible.value = true;
  specLibLoading.value = true;
  selectedSpecIds.value = [];
  try {
    specLibList.value = await getAllGoodsSpecsApi();
  } catch {
    message.error('加载规格库失败');
  } finally {
    specLibLoading.value = false;
  }
};

const confirmSelectSpecs = () => {
  const selected = specLibList.value.filter((s) => selectedSpecIds.value.includes(s.id));
  let added = 0;
  for (const spec of selected) {
    if (attrs.value.some((a) => a.value === spec.name)) continue;
    const values = (spec.spec_values || spec.specValues || []).map((v) => ({ id: createLocalId(), value: v.value, pic: '' }));
    if (values.length === 0) values.push({ id: createLocalId(), value: '', pic: '' });
    attrs.value.push({ id: createLocalId(), value: spec.name, add_pic: 0, detail: values });
    added++;
  }
  if (added === 0 && selected.length > 0) {
    message.info('所选规格已存在，未重复添加');
  } else if (added > 0) {
    generateSkuCombinations();
    nextTick(() => { initSpecDrag(); initValueDrag(); });
    message.success(`已导入 ${added} 个规格`);
  }
  specLibVisible.value = false;
};

/* ---------- 另存为模板（保存到规格库） ---------- */
interface SaveTemplateItem { selected: boolean; name: string; values: string[] }
const saveTemplateVisible = ref(false);
const saveTemplateList = ref<SaveTemplateItem[]>([]);
const saveTemplateLoading = ref(false);

const openSaveTemplate = () => {
  const list: SaveTemplateItem[] = attrs.value
    .filter((a) => a.value.trim())
    .map((a) => ({
      selected: true,
      name: a.value,
      values: a.detail.filter((d) => d.value.trim()).map((d) => d.value),
    }));
  if (list.length === 0) {
    message.warning('请先添加并填写规格名称');
    return;
  }
  saveTemplateList.value = list;
  saveTemplateVisible.value = true;
};

const handleSaveTemplate = async () => {
  const toSave = saveTemplateList.value.filter((a) => a.selected && a.values.length > 0);
  if (toSave.length === 0) {
    message.warning('请至少选择一个有规格值的规格');
    return;
  }
  saveTemplateLoading.value = true;
  const errors: string[] = [];
  for (const item of toSave) {
    try {
      const res = await createGoodsSpecApi({ name: item.name });
      await batchCreateSpecValuesApi(res.id, item.values);
    } catch (e: any) {
      errors.push(`${item.name}：${e.message || '保存失败'}`);
    }
  }
  saveTemplateLoading.value = false;
  if (errors.length > 0) {
    message.warning(`部分保存失败 — ${errors.join('；')}`);
  } else {
    message.success(`已将 ${toSave.length} 个规格保存到规格库`);
    saveTemplateVisible.value = false;
  }
};

/* ---------- 加载选项 ---------- */
const loadOptions = async () => {
  try {
    const [categories, brands, tags] = await Promise.all([
      getAllGoodsCategoriesApi(),
      getAllGoodsBrandsApi(),
      getAllGoodsTagsApi(),
    ]);
    categoryTreeData.value = buildTree(categories);
    brandOptions.value = brands;
    tagOptions.value = tags;
  } catch { /* silent */ }
};

/* ---------- 重置 ---------- */
const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '', subtitle: '', category_id: undefined, brand_id: undefined, unit: '件',
    price: 0, market_price: 0, stock: 0, main_image: undefined, main_video: undefined, images: [],
    description: '', sort: 0, status: 1, is_on_sale: 0, is_recommend: 0, is_new: 0, is_hot: 0, tag_ids: [],
  });
  specType.value = 'single';
  attrs.value = [];
  skuRows.value = [];
  multiSpecDraft.value = { attrs: [], skuRows: [] };
  isFullscreen.value = false;
};

/* ---------- 监听 visible ---------- */
watch(() => props.visible, async (val) => {
  if (val) {
    resetForm();
    activeTab.value = 'basic';
    await loadOptions();
    if (props.editData) await loadEditData(props.editData.id);
  }
});

/* ---------- 编辑回填 ---------- */
const loadEditData = async (id: number) => {
  try {
    loading.value = true;
    const detail = await getGoodsInfoApi(id);
    Object.assign(formData, {
      name: detail.name || '',
      subtitle: detail.subtitle || '',
      category_id: detail.category_id || undefined,
      brand_id: detail.brand_id || undefined,
      unit: detail.unit || '件',
      price: detail.price || 0,
      market_price: detail.market_price || 0,
      stock: detail.stock || 0,
      main_image: detail.main_image || undefined,
      main_video: detail.main_video
        ? {
            url: detail.main_video,
            full_url: detail.main_video_full_url || detail.main_video,
            name: detail.main_video.split('/').pop() || '',
          }
        : undefined,
      images: (detail.images || []).map((img) => ({ url: img.url, name: img.url.split('/').pop() || '' })),
      description: detail.description || '',
      sort: detail.sort || 0,
      status: detail.status ?? 1,
      is_on_sale: detail.is_on_sale ?? 0,
      is_recommend: detail.is_recommend ?? 0,
      is_new: detail.is_new ?? 0,
      is_hot: detail.is_hot ?? 0,
      tag_ids: (detail.tags || []).map((t) => t.id),
    });

    if (detail.skus && detail.skus.length > 0) {
      specType.value = 'multi';
      const firstSku = detail.skus[0]!;
      const specValues = (firstSku.spec_values || '').split(',');
      const colCount = specValues.length;

      const newAttrs: Attr[] = Array.from({ length: colCount }, (_, i) => ({
        id: createLocalId(),
        value: `规格${i + 1}`,
        add_pic: 0,
        detail: [],
      }));

      const valueSetsByPos: Set<string>[] = Array.from({ length: colCount }, () => new Set());
      for (const sku of detail.skus) {
        const parts = (sku.spec_values || '').split(',');
        parts.forEach((v, i) => { if (v) valueSetsByPos[i]?.add(v); });
      }
      for (let i = 0; i < colCount; i++) {
        newAttrs[i]!.detail = [...(valueSetsByPos[i] || [])].map((v) => ({ id: createLocalId(), value: v, pic: '' }));
      }
      attrs.value = newAttrs;

      generateSkuCombinations();
      const skuMap = new Map(detail.skus.map((s) => [s.spec_values, s]));
      for (const row of skuRows.value) {
        const sku = skuMap.get(row.spec_values);
        if (sku) {
          row.price = sku.price;
          row.market_price = sku.market_price || 0;
          row.stock = sku.stock;
          row.sku_code = sku.sku_code || '';
          row.image = sku.image || undefined;
        }
      }
      saveMultiSpecDraft();
    } else {
      specType.value = 'single';
      multiSpecDraft.value = { attrs: [], skuRows: [] };
    }
  } catch {
    message.error('加载商品详情失败');
  } finally {
    loading.value = false;
  }
};

/* ---------- 提交 ---------- */
const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;

    const submitData: any = {
      ...formData,
      main_image: typeof formData.main_image === 'object' ? formData.main_image?.url || '' : formData.main_image || '',
      main_video: typeof formData.main_video === 'object' ? formData.main_video?.url || '' : formData.main_video || '',
      images: formData.images.map((img) => ({ url: typeof img === 'object' ? img.url : img })),
    };

    if (specType.value === 'multi' && skuRows.value.length > 0) {
      submitData.skus = skuRows.value.map((sku) => ({
        spec_values: sku.spec_values,
        price: sku.price,
        market_price: sku.market_price,
        stock: sku.stock,
        sku_code: sku.sku_code || '',
        image: typeof sku.image === 'object' ? sku.image?.url || '' : sku.image || '',
      }));
    } else {
      submitData.skus = undefined;
    }

    if (isEdit.value) {
      await updateGoodsApi(props.editData!.id, submitData);
      message.success('更新成功');
    } else {
      await createGoodsApi(submitData);
      message.success('创建成功');
    }
    emit('success');
    emit('update:visible', false);
  } catch (error: any) {
    if (!error.errorFields) {
      message.error(error.message || '操作失败');
    }
  } finally {
    loading.value = false;
  }
};

const handleCancel = () => emit('update:visible', false);

const handleSpecTypeChange = (val: 'single' | 'multi') => {
  if (val === 'single' && specType.value === 'multi') saveMultiSpecDraft();
  specType.value = val;
  if (val === 'multi') {
    restoreMultiSpecDraft();
    // specListRef watcher 会在多规格区域挂载后自动初始化拖拽
  }
};

onMounted(() => loadOptions());
</script>

<template>
  <a-modal
    :title="isEdit ? '编辑商品' : '新增商品'"
    :open="visible"
    :width="isFullscreen ? '100vw' : 1200"
    :wrap-class-name="isFullscreen ? 'goods-modal-fullscreen' : ''"
    :footer="null"
    :body-style="{ padding: 0, height: isFullscreen ? 'calc(100vh - 55px)' : '82vh', overflowY: 'hidden', display: 'flex', flexDirection: 'column' }"
    @cancel="handleCancel"
  >
    <!-- 标题右侧全屏按钮 -->
    <template #title>
      <div class="modal-title-bar">
        <span>{{ isEdit ? '编辑商品' : '新增商品' }}</span>
        <a-tooltip :title="isFullscreen ? '退出全屏' : '全屏编辑'">
          <a-button type="text" size="small" class="fullscreen-btn" @click="toggleFullscreen">
            <template #icon>
              <svg v-if="!isFullscreen" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
              <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>
            </template>
          </a-button>
        </a-tooltip>
      </div>
    </template>

    <a-spin :spinning="loading" class="modal-spin">
      <a-form
        ref="formRef"
        :model="formData"
        :rules="rules"
        :label-col="{ style: { width: '88px' } }"
        class="modal-form"
      >
        <!-- Tab 区域（可滚动） -->
        <a-tabs
          v-model:activeKey="activeTab"
          :tab-bar-style="{ padding: '0 24px', margin: 0, borderBottom: '1px solid #f0f0f0', background: '#fafafa', flexShrink: 0 }"
          class="modal-tabs"
        >
          <!-- ===== 基本信息 ===== -->
          <a-tab-pane key="basic" tab="基本信息">
            <div class="tab-body">
              <a-form-item label="商品名称" name="name">
                <a-input v-model:value="formData.name" placeholder="请输入商品名称（必填）" :maxlength="80" show-count allow-clear />
              </a-form-item>
              <a-form-item label="副标题">
                <a-input v-model:value="formData.subtitle" placeholder="商品副标题（选填）" allow-clear />
              </a-form-item>
              <a-form-item label="商品分类" name="category_id">
                <a-tree-select
                  v-model:value="formData.category_id"
                  :tree-data="categoryTreeData"
                  placeholder="请选择商品分类"
                  tree-default-expand-all
                  allow-clear
                  style="width: 300px"
                />
              </a-form-item>
              <a-form-item label="品牌">
                <a-select v-model:value="formData.brand_id" placeholder="请选择品牌" allow-clear style="width: 240px">
                  <a-select-option v-for="b in brandOptions" :key="b.id" :value="b.id">{{ b.name }}</a-select-option>
                </a-select>
              </a-form-item>
              <a-form-item label="单位">
                <a-input v-model:value="formData.unit" placeholder="件 / kg / 个" style="width: 140px" allow-clear />
              </a-form-item>
              <a-form-item label="商品主图">
                <div class="form-tip">建议尺寸 800×800，正方形</div>
                <Upload v-model:value="formData.main_image" type="image" module="goods" />
              </a-form-item>
              <a-form-item label="商品主视频">
                <div class="form-tip">选填，建议上传 MP4/MOV，适合详情页首屏展示</div>
                <Upload v-model:value="formData.main_video" type="video" module="goods" />
              </a-form-item>
              <a-form-item label="轮播图片">
                <div class="form-tip">最多10张，首图用于列表展示</div>
                <Upload v-model:value="formData.images" type="images" module="goods" :max-count="10" />
              </a-form-item>
              <a-form-item label="商品标签">
                <div v-if="tagOptions.length === 0" class="form-tip">暂无标签，请先在「商品标签」模块创建</div>
                <div v-else class="tag-list">
                  <span
                    v-for="tag in tagOptions"
                    :key="tag.id"
                    class="tag-chip"
                    :class="{ active: formData.tag_ids.includes(tag.id) }"
                    :style="formData.tag_ids.includes(tag.id) && tag.color ? { background: tag.color, borderColor: tag.color, color: '#fff' } : {}"
                    @click="formData.tag_ids.includes(tag.id)
                      ? (formData.tag_ids = formData.tag_ids.filter((id) => id !== tag.id))
                      : formData.tag_ids.push(tag.id)"
                  >
                    <span v-if="tag.color && !formData.tag_ids.includes(tag.id)" class="tag-dot" :style="{ background: tag.color }" />
                    {{ tag.name }}
                  </span>
                </div>
              </a-form-item>
              <a-form-item label="商品状态">
                <a-radio-group v-model:value="formData.status" button-style="solid">
                  <a-radio-button :value="1">启用</a-radio-button>
                  <a-radio-button :value="0">禁用</a-radio-button>
                </a-radio-group>
              </a-form-item>
              <a-form-item label="排序">
                <a-input-number v-model:value="formData.sort" :min="0" :max="9999" style="width: 120px" :controls="false" />
                <span class="form-tip ml8">数字越小越靠前</span>
              </a-form-item>
              <a-form-item label="标签设置">
                <div class="flag-row">
                  <div class="flag-cell">
                    <span class="flag-name">立即上架</span>
                    <a-switch v-model:checked="formData.is_on_sale" :checked-value="1" :un-checked-value="0" checked-children="上架" un-checked-children="下架" />
                  </div>
                  <div class="flag-cell">
                    <span class="flag-name">精品推荐</span>
                    <a-switch v-model:checked="formData.is_recommend" :checked-value="1" :un-checked-value="0" checked-children="是" un-checked-children="否" />
                  </div>
                  <div class="flag-cell">
                    <span class="flag-name">新品标签</span>
                    <a-switch v-model:checked="formData.is_new" :checked-value="1" :un-checked-value="0" checked-children="是" un-checked-children="否" />
                  </div>
                  <div class="flag-cell">
                    <span class="flag-name">热卖标签</span>
                    <a-switch v-model:checked="formData.is_hot" :checked-value="1" :un-checked-value="0" checked-children="是" un-checked-children="否" />
                  </div>
                </div>
              </a-form-item>
            </div>
          </a-tab-pane>

          <!-- ===== 规格库存 ===== -->
          <a-tab-pane key="spec" tab="规格库存">
            <div class="tab-body">
              <a-form-item label="规格类型">
                <a-radio-group :value="specType" button-style="solid" @change="(e: any) => handleSpecTypeChange(e.target.value)">
                  <a-radio-button value="single">单规格</a-radio-button>
                  <a-radio-button value="multi">多规格</a-radio-button>
                </a-radio-group>
              </a-form-item>

              <!-- 单规格 -->
              <template v-if="specType === 'single'">
                <a-form-item label="售价">
                  <a-input-number v-model:value="formData.price" :min="0" :precision="2" :controls="false" style="width:160px">
                    <template #prefix>¥</template>
                  </a-input-number>
                </a-form-item>
                <a-form-item label="市场价">
                  <a-input-number v-model:value="formData.market_price" :min="0" :precision="2" :controls="false" style="width:160px">
                    <template #prefix>¥</template>
                  </a-input-number>
                </a-form-item>
                <a-form-item label="库存">
                  <a-input-number v-model:value="formData.stock" :min="0" :controls="false" style="width:160px">
                    <template #suffix>件</template>
                  </a-input-number>
                </a-form-item>
              </template>

              <!-- 多规格 -->
              <template v-else>
                <a-form-item label="商品规格" :wrapper-col="{ span: 22 }">
                  <div class="spec-wrapper">
                    <!-- 规格维度列表（可拖拽） -->
                    <div ref="specListRef" class="spec-list">
                      <div
                        v-for="(attr, attrIdx) in attrs"
                        :key="attr.id"
                        class="spec-item"
                      >
                        <!-- 规格名行 -->
                        <div class="spec-name-row">
                          <span class="spec-drag-handle" title="拖拽排序">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="#bbb"><circle cx="3" cy="3" r="1.2"/><circle cx="9" cy="3" r="1.2"/><circle cx="3" cy="7" r="1.2"/><circle cx="9" cy="7" r="1.2"/><circle cx="3" cy="11" r="1.2"/><circle cx="9" cy="11" r="1.2"/></svg>
                          </span>
                          <a-input
                            v-model:value="attr.value"
                            placeholder="规格名称"
                            :maxlength="30"
                            show-count
                            class="spec-name-input"
                            @change="generateSkuCombinations"
                          />
                          <a-checkbox
                            :checked="attr.add_pic === 1"
                            :disabled="attr.add_pic === 0 && !canAddPic"
                            @change="(e: any) => { attr.add_pic = e.target.checked ? 1 : 0; toggleAddPic(e.target.checked, attrIdx); }"
                          >
                            添加规格图
                          </a-checkbox>
                          <a-tooltip title="只能同时为一个规格开启规格图，建议尺寸 800×800" placement="right">
                            <span class="icon-info">?</span>
                          </a-tooltip>
                          <a-button type="text" danger size="small" class="ml8" @click="handleRemoveSpec(attrIdx)">删除</a-button>
                        </div>

                        <!-- 规格值列表（可拖拽） -->
                        <div
                          :ref="(el) => { valueListRefs[attrIdx] = el as HTMLElement }"
                          class="spec-values-row"
                        >
                          <div
                            v-for="(det, detIdx) in attr.detail"
                            :key="det.id"
                            class="spec-val-item"
                            :class="{ 'has-pic': attr.add_pic === 1 }"
                          >
                            <span class="val-drag-handle" title="拖拽排序">
                              <svg width="10" height="10" viewBox="0 0 12 12" fill="#ccc"><circle cx="3" cy="3" r="1.2"/><circle cx="9" cy="3" r="1.2"/><circle cx="3" cy="7" r="1.2"/><circle cx="9" cy="7" r="1.2"/><circle cx="3" cy="11" r="1.2"/><circle cx="9" cy="11" r="1.2"/></svg>
                            </span>
                            <a-input
                              v-model:value="det.value"
                              placeholder="规格值"
                              :maxlength="30"
                              class="val-input"
                              @change="generateSkuCombinations"
                            />
                            <!-- 规格图：有图显示缩略图，无图显示上传按钮 -->
                            <template v-if="attr.add_pic">
                              <div v-if="det.pic" class="val-pic-thumb">
                                <img :src="getPicPreviewUrl(det.pic)" width="46" height="46" />
                                <span class="val-pic-del" @click.stop="det.pic = ''">×</span>
                              </div>
                              <div v-else class="val-pic-upload-wrap">
                                <Upload
                                  type="image"
                                  module="goods"
                                  :show-upload-list="false"
                                  class="val-pic-upload"
                                  @update:value="(v: FileInfo | undefined) => { det.pic = v ?? ''; }"
                                />
                              </div>
                            </template>
                            <span class="val-del" @click="removeSpecValue(attrIdx, detIdx)">×</span>
                          </div>
                          <span class="add-val-btn" @click="addSpecValue(attrIdx)">+ 添加规格值</span>
                        </div>
                      </div>
                    </div>

                    <!-- 底部操作按钮 -->
                    <div class="spec-actions">
                      <a-button v-if="attrs.length < 4" @click="handleAddSpec">添加新规格</a-button>
                      <a-button @click="openSpecLib">从规格库导入</a-button>
                      <a-button type="text" :disabled="attrs.length === 0" @click="openSaveTemplate">另存为模板</a-button>
                    </div>
                  </div>
                </a-form-item>

                <!-- SKU 表格 -->
                <a-form-item v-if="tableData.length > 0" label="商品属性" :wrapper-col="{ span: 22 }">
                  <a-table
                    :columns="(skuColumns as any[])"
                    :data-source="tableData"
                    :pagination="false"
                    :scroll="{ x: 900, y: 360 }"
                    size="small"
                    bordered
                    row-key="spec_values"
                    class="sku-table"
                  >
                    <template #bodyCell="{ column, record, index: rowIdx }">
                      <!-- ---- 批量行 ---- -->
                      <template v-if="(record as SkuRow)._isBatch">
                        <template v-if="(column as any)._isSpecCol">
                          <a-select
                            v-model:value="batchData[(column as any).title]"
                            :placeholder="`选择${(column as any).title}`"
                            size="small"
                            allow-clear
                            style="width:100%"
                          >
                            <a-select-option
                              v-for="det in attrs[(column as any)._attrIdx]?.detail || []"
                              :key="det.value"
                              :value="det.value"
                            >{{ det.value }}</a-select-option>
                          </a-select>
                        </template>
                        <template v-else-if="column.dataIndex === 'image'">
                          <Upload v-model:value="(batchData as any)['__image__']" type="image" module="goods" :show-upload-list="false" />
                        </template>
                        <template v-else-if="column.dataIndex === 'price'">
                          <a-input-number v-model:value="(batchData as any)['__price__']" placeholder="批量售价" :min="0" :precision="2" size="small" :controls="false" style="width:100%" />
                        </template>
                        <template v-else-if="column.dataIndex === 'market_price'">
                          <a-input-number v-model:value="(batchData as any)['__market_price__']" placeholder="批量市场价" :min="0" :precision="2" size="small" :controls="false" style="width:100%" />
                        </template>
                        <template v-else-if="column.dataIndex === 'stock'">
                          <a-input-number v-model:value="(batchData as any)['__stock__']" placeholder="批量库存" :min="0" size="small" :controls="false" style="width:100%" />
                        </template>
                        <template v-else-if="column.dataIndex === 'sku_code'">
                          <a-input v-model:value="batchData['__sku_code__']" placeholder="批量SKU编码" size="small" />
                        </template>
                        <template v-else-if="column.dataIndex === '_action'">
                          <a @click="applyBatch">批量修改</a>
                          <a-divider type="vertical" />
                          <a @click="clearBatch">清空</a>
                        </template>
                      </template>

                      <!-- ---- 数据行 ---- -->
                      <template v-else>
                        <template v-if="(column as any)._isSpecCol">
                          <span class="sku-spec-val">{{ (record as SkuRow).detail[(column as any).title] }}</span>
                        </template>
                        <template v-else-if="column.dataIndex === 'image'">
                          <Upload v-model:value="(record as SkuRow).image" type="image" module="goods" :show-upload-list="false" />
                        </template>
                        <template v-else-if="column.dataIndex === 'price'">
                          <a-input-number v-model:value="(record as SkuRow).price" :min="0" :precision="2" size="small" :controls="false" style="width:100%" />
                        </template>
                        <template v-else-if="column.dataIndex === 'market_price'">
                          <a-input-number v-model:value="(record as SkuRow).market_price" :min="0" :precision="2" size="small" :controls="false" style="width:100%" />
                        </template>
                        <template v-else-if="column.dataIndex === 'stock'">
                          <a-input-number v-model:value="(record as SkuRow).stock" :min="0" size="small" :controls="false" style="width:100%" />
                        </template>
                        <template v-else-if="column.dataIndex === 'sku_code'">
                          <a-input v-model:value="(record as SkuRow).sku_code" size="small" placeholder="选填" allow-clear />
                        </template>
                        <template v-else-if="column.dataIndex === '_action'">
                          <a-switch
                            v-model:checked="(record as any).is_show"
                            :checked-value="1"
                            :un-checked-value="0"
                            checked-children="显"
                            un-checked-children="隐"
                            size="small"
                          />
                        </template>
                      </template>
                    </template>
                  </a-table>
                </a-form-item>
              </template>
            </div>
          </a-tab-pane>

          <!-- ===== 商品详情 ===== -->
          <a-tab-pane key="detail" tab="商品详情">
            <div class="tab-body">
              <a-form-item label="商品描述" name="description" :wrapper-col="{ span: 22 }">
                <RichTextEditor
                  :height="420"
                  module="goods"
                  :model-value="formData.description"
                  placeholder="请输入商品描述..."
                  @update:model-value="(val: string) => { formData.description = val; }"
                />
              </a-form-item>
            </div>
          </a-tab-pane>
        </a-tabs>

        <!-- 底部操作栏（sticky） -->
        <div class="modal-footer">
          <a-space>
            <a-button @click="handleCancel">取 消</a-button>
            <a-button type="primary" :loading="loading" @click="handleSubmit">
              {{ isEdit ? '保存修改' : '立即创建' }}
            </a-button>
          </a-space>
        </div>
      </a-form>
    </a-spin>

    <!-- ===== 从规格库导入弹窗 ===== -->
    <a-modal
      v-model:open="specLibVisible"
      title="从规格库导入"
      :width="520"
      ok-text="确认导入"
      cancel-text="取消"
      @ok="confirmSelectSpecs"
    >
      <a-spin :spinning="specLibLoading">
        <div v-if="specLibList.length === 0 && !specLibLoading" class="empty-tip">
          规格库暂无数据，请先在「商品规格」模块创建规格
        </div>
        <a-checkbox-group v-else v-model:value="selectedSpecIds" class="spec-lib-list">
          <div v-for="spec in specLibList" :key="spec.id" class="spec-lib-item">
            <a-checkbox :value="spec.id">
              <span class="spec-lib-name">{{ spec.name }}</span>
            </a-checkbox>
            <div class="spec-lib-values">
              <span
                v-for="val in (spec.spec_values || spec.specValues || [])"
                :key="val.id"
                class="spec-lib-val-tag"
              >{{ val.value }}</span>
            </div>
          </div>
        </a-checkbox-group>
      </a-spin>
    </a-modal>

    <!-- ===== 另存为模板弹窗 ===== -->
    <a-modal
      v-model:open="saveTemplateVisible"
      title="另存为模板（保存到规格库）"
      :width="480"
      ok-text="保存"
      cancel-text="取消"
      :confirm-loading="saveTemplateLoading"
      @ok="handleSaveTemplate"
    >
      <div class="form-tip mb8">勾选需要保存的规格，保存后可在「从规格库导入」中复用</div>
      <div class="save-template-list">
        <div
          v-for="(item, idx) in saveTemplateList"
          :key="idx"
          class="save-template-item"
        >
          <a-checkbox v-model:checked="item.selected">
            <span class="spec-lib-name">{{ item.name }}</span>
          </a-checkbox>
          <div class="spec-lib-values">
            <span v-for="v in item.values" :key="v" class="spec-lib-val-tag">{{ v }}</span>
            <span v-if="item.values.length === 0" class="form-tip">（无规格值，不可保存）</span>
          </div>
        </div>
      </div>
    </a-modal>
  </a-modal>
</template>

<style scoped>
/* ===== 全屏按钮 ===== */
.modal-title-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-right: 8px;
}
.fullscreen-btn {
  color: #8c8c8c;
  display: flex;
  align-items: center;
  justify-content: center;
}
.fullscreen-btn:hover { color: #1677ff; }

/* ===== 整体布局（flex 撑满） ===== */
.modal-spin { flex: 1; overflow: hidden; display: flex; flex-direction: column; min-height: 0; }
.modal-form { flex: 1; overflow: hidden; display: flex; flex-direction: column; min-height: 0; }
.modal-tabs { flex: 1; overflow: hidden; display: flex; flex-direction: column; min-height: 0; }
.modal-tabs :deep(.ant-tabs-content-holder) { flex: 1; overflow-y: auto; min-height: 0; }
.modal-tabs :deep(.ant-tabs-content) { height: 100%; }
.modal-tabs :deep(.ant-tabs-tabpane) { height: 100%; }

/* ===== Tab 内容区域 ===== */
.tab-body {
  padding: 20px 24px 8px;
  display: flex;
  flex-direction: column;
}

/* ===== 辅助文字 ===== */
.form-tip { font-size: 12px; color: #8c8c8c; line-height: 1.4; }
.mt4 { margin-top: 4px; }
.ml8 { margin-left: 8px; }
.mb8 { margin-bottom: 8px; }

/* ===== 标签选择 ===== */
.tag-list { display: flex; flex-wrap: wrap; gap: 8px; }
.tag-chip {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 12px; border-radius: 20px; font-size: 13px;
  cursor: pointer; border: 1px solid #d9d9d9; color: #595959;
  background: #fff; user-select: none; transition: all 0.15s;
}
.tag-chip:hover { border-color: #1677ff; color: #1677ff; }
.tag-chip.active { border-color: #1677ff; background: #e6f4ff; color: #1677ff; }
.tag-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

/* ===== 标签设置行 ===== */
.flag-row { display: flex; border: 1px solid #e8edf2; border-radius: 6px; overflow: hidden; max-width: 520px; }
.flag-cell { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 14px 8px; border-right: 1px solid #e8edf2; background: #fafafa; }
.flag-cell:last-child { border-right: none; }
.flag-name { font-size: 13px; color: #595959; }

/* ===== 规格区域 ===== */
.spec-wrapper { display: flex; flex-direction: column; gap: 0; }
.spec-list { display: flex; flex-direction: column; gap: 12px; }
.spec-item { border: 1px solid #e8edf2; border-radius: 6px; overflow: hidden; background: #fff; }

.spec-name-row {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; background: #f7f9fc; border-bottom: 1px solid #e8edf2;
}

.spec-drag-handle, .val-drag-handle {
  cursor: grab; padding: 0 4px; color: #bbb; flex-shrink: 0;
}
.spec-drag-handle:active, .val-drag-handle:active { cursor: grabbing; }

.spec-name-input { width: 180px; }

.icon-info {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; border-radius: 50%; border: 1px solid #d9d9d9;
  font-size: 11px; color: #8c8c8c; cursor: help; flex-shrink: 0;
}

/* 规格值列表 */
.spec-values-row {
  display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 12px 14px;
}

.spec-val-item {
  display: inline-flex; align-items: center; gap: 4px;
  background: #fafafa; border: 1px solid #e8edf2; border-radius: 4px;
  padding: 2px 6px 2px 4px;
}

.spec-val-item.has-pic { padding: 4px 6px 4px 4px; align-items: flex-start; flex-direction: column; }
.spec-val-item.has-pic .val-drag-handle { align-self: center; }

.val-input { width: 100px; }

/* 规格图 */
.val-pic-thumb {
  position: relative; display: inline-block; margin: 2px 0;
}
.val-pic-thumb img {
  width: 46px; height: 46px; object-fit: cover; border-radius: 4px;
  border: 1px solid #e8edf2; display: block;
}
.val-pic-del {
  position: absolute; top: -6px; right: -6px;
  width: 16px; height: 16px; border-radius: 50%;
  background: rgba(0,0,0,.45); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; cursor: pointer; line-height: 1;
}
.val-pic-del:hover { background: #ff4d4f; }

.val-pic-upload-wrap :deep(.ant-upload) {
  width: 46px !important; height: 46px !important;
  font-size: 18px; border-radius: 4px;
}
.val-pic-upload-wrap :deep(.ant-upload-list) { display: none !important; }

.val-del {
  font-size: 16px; color: #bfbfbf; cursor: pointer;
  line-height: 1; transition: color 0.15s; margin-left: 2px;
  align-self: center;
}
.val-del:hover { color: #ff4d4f; }

.add-val-btn {
  color: #1677ff; font-size: 13px; cursor: pointer; padding: 2px 4px;
}
.add-val-btn:hover { opacity: 0.8; }

/* 底部按钮 */
.spec-actions { display: flex; gap: 8px; padding: 12px 0 4px; }

/* ===== SKU 表格 ===== */
.sku-table :deep(.ant-table-cell) {
  padding: 5px 8px !important; vertical-align: middle;
}
.sku-spec-val { font-weight: 500; color: #262626; }

/* ===== 底部操作栏（sticky） ===== */
.modal-footer {
  display: flex; justify-content: flex-end;
  padding: 14px 24px; border-top: 1px solid #f0f0f0; background: #fafafa;
  flex-shrink: 0;
}

/* ===== 规格库弹窗 ===== */
.spec-lib-list { display: flex; flex-direction: column; gap: 12px; max-height: 400px; overflow-y: auto; }
.spec-lib-item { display: flex; flex-direction: column; gap: 6px; padding: 10px 12px; background: #fafafa; border: 1px solid #f0f0f0; border-radius: 6px; }
.spec-lib-name { font-weight: 500; color: #262626; }
.spec-lib-values { display: flex; flex-wrap: wrap; gap: 4px; padding-left: 24px; }
.spec-lib-val-tag {
  display: inline-block; padding: 1px 8px; border-radius: 4px;
  font-size: 12px; color: #595959; background: #f0f0f0; border: 1px solid #e8edf2;
}
.empty-tip { color: #8c8c8c; text-align: center; padding: 24px 0; }

/* ===== 另存为模板弹窗 ===== */
.save-template-list { display: flex; flex-direction: column; gap: 8px; max-height: 360px; overflow-y: auto; }
.save-template-item { padding: 10px 12px; background: #fafafa; border: 1px solid #f0f0f0; border-radius: 6px; display: flex; flex-direction: column; gap: 6px; }
</style>

<!-- 全屏模式全局样式 -->
<style>
.goods-modal-fullscreen .ant-modal {
  top: 0 !important;
  margin: 0 auto !important;
  max-width: 100vw !important;
  padding-bottom: 0 !important;
}
.goods-modal-fullscreen .ant-modal-content {
  border-radius: 0 !important;
  height: 100vh;
}
.goods-modal-fullscreen .ant-modal-header {
  border-radius: 0 !important;
}
</style>
