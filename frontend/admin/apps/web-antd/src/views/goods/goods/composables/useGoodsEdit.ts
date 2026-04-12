import type { GoodsBrandApi } from '#/api/goods';
import type { GoodsCategoryApi } from '#/api/goods';
import type { GoodsSpecApi } from '#/api/goods';
import type { GoodsSpecTemplateApi } from '#/api/goods';
import type { GoodsTagApi } from '#/api/goods';

import type { FileInfo } from '#/components/upload';

import { computed, nextTick, reactive, ref, type Ref } from 'vue';

import Sortable from 'sortablejs';
import { message } from 'ant-design-vue';

import {
  createGoodsApi,
  createGoodsSpecTemplateApi,
  getAllGoodsBrandsApi,
  getAllGoodsCategoriesApi,
  getAllGoodsSpecsApi,
  getAllGoodsSpecTemplatesApi,
  getAllGoodsTagsApi,
  getGoodsInfoApi,
  updateGoodsApi,
} from '#/api/goods';

export interface AttrDetail {
  value: string;
  pic: FileInfo | string;
}
export interface Attr {
  value: string;
  add_pic: 0 | 1;
  detail: AttrDetail[];
}
export interface SkuRow {
  _isBatch?: true;
  spec_values: string;
  detail: Record<string, string>;
  price: number | undefined;
  market_price: number | undefined;
  stock: number | undefined;
  sku_code: string;
  image: FileInfo | string | undefined;
  is_show?: 0 | 1;
}

export function useGoodsEdit(editIdRef: Ref<number | undefined>) {
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
    images: [] as (FileInfo | string)[],
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
  const isFullscreen = ref(false);
  const toggleFullscreen = () => { isFullscreen.value = !isFullscreen.value; };
  const isEdit = computed(() => !!editIdRef.value);

  /* ---------- 分类 / 品牌 / 标签 ---------- */
  const categoryTreeData = ref<any[]>([]);
  const brandOptions = ref<GoodsBrandApi.BrandItem[]>([]);
  const tagOptions = ref<GoodsTagApi.TagItem[]>([]);
  const buildTree = (list: GoodsCategoryApi.CategoryItem[], pid = 0): any[] =>
    list.filter((item) => item.pid === pid)
      .map((item) => ({ title: item.name, value: item.id, key: item.id, children: buildTree(list, item.id) }));

  /* ---------- 规格 attrs ---------- */
  const specType = ref<'single' | 'multi'>('single');
  const attrs = ref<Attr[]>([]);
  const canAddPic = computed(() => !attrs.value.some((a) => a.add_pic === 1));

  const getPicPreviewUrl = (pic: FileInfo | string): string => {
    if (!pic) return '';
    if (typeof pic === 'object') return pic.full_url || pic.url || '';
    return pic;
  };
  const getPicUrl = (pic: FileInfo | string): string => {
    if (!pic) return '';
    if (typeof pic === 'object') return pic.url || '';
    return pic;
  };

  const handleAddSpec = () => {
    attrs.value.push({ value: '', add_pic: 0, detail: [{ value: '', pic: '' }] });
    nextTick(initValueDrag);
  };
  const handleRemoveSpec = (idx: number) => {
    attrs.value.splice(idx, 1);
    generateSkuCombinations();
  };
  const addSpecValue = (attrIdx: number) => {
    attrs.value[attrIdx]!.detail.push({ value: '', pic: '' });
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
    if (e) { attrs.value.forEach((a, i) => { if (i !== idx) a.add_pic = 0; }); }
    generateSkuCombinations();
  };

  /* ---------- 拖拽 ---------- */
  const specListRef = ref<HTMLElement | null>(null);
  const valueListRefs = ref<(HTMLElement | null)[]>([]);
  let specSortable: Sortable | null = null;
  const valueSortables: Sortable[] = [];

  const initSpecDrag = () => {
    if (!specListRef.value) return;
    specSortable?.destroy();
    specSortable = Sortable.create(specListRef.value, {
      handle: '.spec-drag-handle',
      animation: 150,
      onEnd({ oldIndex, newIndex }) {
        if (oldIndex === newIndex) return;
        const moved = attrs.value.splice(oldIndex!, 1)[0]!;
        attrs.value.splice(newIndex!, 0, moved);
        generateSkuCombinations();
      },
    });
  };
  const initValueDragAt = (attrIdx: number) => {
    const el = valueListRefs.value[attrIdx];
    if (!el) return;
    valueSortables[attrIdx]?.destroy();
    valueSortables[attrIdx] = Sortable.create(el, {
      handle: '.val-drag-handle',
      animation: 150,
      onEnd({ oldIndex, newIndex }) {
        if (oldIndex === newIndex) return;
        const moved = attrs.value[attrIdx]!.detail.splice(oldIndex!, 1)[0]!;
        attrs.value[attrIdx]!.detail.splice(newIndex!, 0, moved);
        generateSkuCombinations();
      },
    });
  };
  const initValueDrag = () => { attrs.value.forEach((_, idx) => initValueDragAt(idx)); };

  /* ---------- SKU 表格 ---------- */
  const skuRows = ref<SkuRow[]>([]);
  const multiSpecDraft = ref<{ attrs: Attr[]; skuRows: SkuRow[] }>({ attrs: [], skuRows: [] });

  const cloneAttrs = (source: Attr[]): Attr[] =>
    source.map((attr) => ({
      value: attr.value,
      add_pic: attr.add_pic,
      detail: attr.detail.map((det) => ({ value: det.value, pic: det.pic })),
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
      is_show: row.is_show,
    }));

  const saveMultiSpecDraft = () => {
    multiSpecDraft.value = {
      attrs: cloneAttrs(attrs.value),
      skuRows: cloneSkuRows(skuRows.value),
    };
  };

  const restoreMultiSpecDraft = () => {
    if (multiSpecDraft.value.attrs.length === 0 && multiSpecDraft.value.skuRows.length === 0) {
      return;
    }
    attrs.value = cloneAttrs(multiSpecDraft.value.attrs);
    skuRows.value = cloneSkuRows(multiSpecDraft.value.skuRows);
  };

  const batchData = reactive<Record<string, any>>({});
  const tableData = computed<SkuRow[]>(() => {
    if (skuRows.value.length === 0) return [];
    const batch: SkuRow = {
      _isBatch: true, spec_values: '__batch__', detail: {},
      price: undefined, market_price: undefined, stock: undefined, sku_code: '', image: undefined,
    };
    return [batch, ...skuRows.value];
  });
  const skuColumns = computed(() => {
    const specCols = attrs.value.map((attr, idx) => {
      const col: any = { title: attr.value || `规格${idx + 1}`, dataIndex: `_spec_${idx}`, width: 110, _isSpecCol: true, _attrIdx: idx };
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
      { title: '规格图', dataIndex: 'image', width: 68 },
      { title: '售价 *', dataIndex: 'price', width: 110 },
      { title: '市场价', dataIndex: 'market_price', width: 110 },
      { title: '库存 *', dataIndex: 'stock', width: 100 },
      { title: 'SKU编码', dataIndex: 'sku_code', width: 130 },
      { title: '操作', dataIndex: '_action', width: 55 },
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
        if ((rows[j]!.detail[attrs.value[0]!.value] ?? '') === key) len++; else break;
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
      return { spec_values: specValues, detail, price: formData.price || undefined, market_price: formData.market_price || undefined, stock: formData.stock || undefined, sku_code: '', image: undefined };
    });
  };
  const applyBatch = () => {
    for (const row of skuRows.value) {
      if (batchData['__price__']) row.price = Number(batchData['__price__']);
      if (batchData['__market_price__']) row.market_price = Number(batchData['__market_price__']);
      if (batchData['__stock__']) row.stock = Number(batchData['__stock__']);
      if (batchData['__sku_code__']) row.sku_code = String(batchData['__sku_code__']);
      if (batchData['__image__']) row.image = batchData['__image__'];
    }
    message.success('批量设置成功');
  };
  const clearBatch = () => { Object.keys(batchData).forEach((k) => { batchData[k] = ''; }); };

  /* ---------- 从规格库 / 模板导入 ---------- */
  const specLibVisible = ref(false);
  const specImportTab = ref<'spec' | 'template'>('spec');
  const specLibLoading = ref(false);
  const specLibList = ref<GoodsSpecApi.SpecItem[]>([]);
  const selectedSpecIds = ref<number[]>([]);
  const specTemplateList = ref<GoodsSpecTemplateApi.TemplateItem[]>([]);
  const selectedTemplateIds = ref<number[]>([]);

  const openSpecLib = async () => {
    specLibVisible.value = true;
    specImportTab.value = 'spec';
    specLibLoading.value = true;
    selectedSpecIds.value = [];
    selectedTemplateIds.value = [];
    try {
      const [specs, templates] = await Promise.all([getAllGoodsSpecsApi(), getAllGoodsSpecTemplatesApi()]);
      specLibList.value = specs;
      specTemplateList.value = templates;
    } catch { message.error('加载规格库失败'); }
    finally { specLibLoading.value = false; }
  };
  const confirmSelectSpecs = () => {
    let added = 0;
    if (specImportTab.value === 'spec') {
      const selected = specLibList.value.filter((s) => selectedSpecIds.value.includes(s.id));
      for (const spec of selected) {
        if (attrs.value.some((a) => a.value === spec.name)) continue;
        const values = (spec.spec_values || spec.specValues || []).map((v) => ({ value: v.value, pic: '' }));
        if (values.length === 0) values.push({ value: '', pic: '' });
        attrs.value.push({ value: spec.name, add_pic: 0, detail: values });
        added++;
      }
    } else {
      const selected = specTemplateList.value.filter((t) => selectedTemplateIds.value.includes(t.id));
      for (const tpl of selected) {
        for (const item of (tpl.detail || [])) {
          if (attrs.value.some((a) => a.value === item.spec_name)) continue;
          const values = (item.values || []).map((v) => ({ value: v, pic: '' }));
          if (values.length === 0) values.push({ value: '', pic: '' });
          attrs.value.push({ value: item.spec_name, add_pic: 0, detail: values });
          added++;
        }
      }
    }
    if (added === 0) { message.info('所选规格已全部存在，未重复添加'); }
    else {
      generateSkuCombinations();
      nextTick(() => { initSpecDrag(); initValueDrag(); });
      message.success(`已导入 ${added} 个规格`);
    }
    specLibVisible.value = false;
  };

  /* ---------- 另存为模板 ---------- */
  interface SaveTemplateItem { selected: boolean; name: string; values: string[] }
  const saveTemplateVisible = ref(false);
  const saveTemplateList = ref<SaveTemplateItem[]>([]);
  const saveTemplateLoading = ref(false);
  const saveTemplateName = ref('');

  const openSaveTemplate = () => {
    const list: SaveTemplateItem[] = attrs.value
      .filter((a) => a.value.trim())
      .map((a) => ({ selected: true, name: a.value, values: a.detail.filter((d) => d.value.trim()).map((d) => d.value) }));
    if (list.length === 0) { message.warning('请先添加并填写规格名称'); return; }
    saveTemplateList.value = list;
    saveTemplateName.value = '';
    saveTemplateVisible.value = true;
  };
  const handleSaveTemplate = async () => {
    if (!saveTemplateName.value.trim()) { message.warning('请输入模板名称'); return; }
    const detail = saveTemplateList.value
      .filter((a) => a.selected && a.values.length > 0)
      .map((a) => ({ spec_name: a.name, values: a.values }));
    if (detail.length === 0) { message.warning('请至少选择一个有规格值的规格'); return; }
    saveTemplateLoading.value = true;
    try {
      await createGoodsSpecTemplateApi({ name: saveTemplateName.value.trim(), detail });
      message.success('模板保存成功，可在规格模板管理中查看');
      saveTemplateVisible.value = false;
    } catch (e: any) {
      message.error(e.message || '保存失败');
    } finally {
      saveTemplateLoading.value = false;
    }
  };

  /* ---------- 加载选项 ---------- */
  const loadOptions = async () => {
    try {
      const [categories, brands, tags] = await Promise.all([
        getAllGoodsCategoriesApi(), getAllGoodsBrandsApi(), getAllGoodsTagsApi(),
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
    activeTab.value = 'basic';
  };

  /* ---------- 编辑回填 ---------- */
  const loadEditData = async (id: number) => {
    try {
      loading.value = true;
      const detail = await getGoodsInfoApi(id);
      Object.assign(formData, {
        name: detail.name || '', subtitle: detail.subtitle || '',
        category_id: detail.category_id || undefined, brand_id: detail.brand_id || undefined,
        unit: detail.unit || '件', price: detail.price || 0, market_price: detail.market_price || 0,
        stock: detail.stock || 0,
        main_image: detail.main_image
          ? {
              url: detail.main_image,
              full_url: detail.main_image_full_url || detail.main_image,
              name: detail.main_image.split('/').pop() || '',
            }
          : undefined,
        main_video: detail.main_video
          ? {
              url: detail.main_video,
              full_url: detail.main_video_full_url || detail.main_video,
              name: detail.main_video.split('/').pop() || '',
            }
          : undefined,
        images: (detail.images || []).map((img) => ({
          url: img.url,
          full_url: img.full_url || img.url,
          name: img.url.split('/').pop() || '',
        })),
        description: detail.description || '', sort: detail.sort || 0, status: detail.status ?? 1,
        is_on_sale: detail.is_on_sale ?? 0, is_recommend: detail.is_recommend ?? 0,
        is_new: detail.is_new ?? 0, is_hot: detail.is_hot ?? 0,
        tag_ids: (detail.tags || []).map((t) => t.id),
      });
      if (detail.skus && detail.skus.length > 0) {
        specType.value = 'multi';
        const colCount = (detail.skus[0]!.spec_values || '').split(',').length;
        const newAttrs: Attr[] = Array.from({ length: colCount }, (_, i) => ({ value: `规格${i + 1}`, add_pic: 0, detail: [] }));
        const valueSetsByPos: Set<string>[] = Array.from({ length: colCount }, () => new Set());
        for (const sku of detail.skus) {
          (sku.spec_values || '').split(',').forEach((v, i) => { if (v) valueSetsByPos[i]?.add(v); });
        }
        for (let i = 0; i < colCount; i++) {
          newAttrs[i]!.detail = [...(valueSetsByPos[i] || [])].map((v) => ({ value: v, pic: '' }));
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
            row.image = sku.image
              ? {
                  url: sku.image,
                  full_url: sku.image_full_url || sku.image,
                  name: sku.image.split('/').pop() || '',
                }
              : undefined;
          }
        }
        saveMultiSpecDraft();
      } else {
        specType.value = 'single';
        multiSpecDraft.value = { attrs: [], skuRows: [] };
      }
    } catch { message.error('加载商品详情失败'); }
    finally { loading.value = false; }
  };

  /* ---------- 提交 ---------- */
  const handleSubmit = async (onSuccess: () => void) => {
    try {
      await formRef.value?.validate();
      loading.value = true;
      const submitData: any = {
        ...formData,
        main_image: typeof formData.main_image === 'object' ? (formData.main_image as FileInfo)?.url || '' : formData.main_image || '',
        main_video: typeof formData.main_video === 'object' ? (formData.main_video as FileInfo)?.url || '' : formData.main_video || '',
        images: formData.images.map((img, index) => ({ url: typeof img === 'object' ? (img as FileInfo).url : img, sort: index })),
      };
      if (specType.value === 'multi' && skuRows.value.length > 0) {
        submitData.skus = skuRows.value.map((sku) => ({
          spec_values: sku.spec_values, price: sku.price, market_price: sku.market_price,
          stock: sku.stock, sku_code: sku.sku_code || '',
          image: typeof sku.image === 'object' ? (sku.image as FileInfo)?.url || '' : sku.image || '',
        }));
      } else {
        // 单规格时显式清空多规格 SKU，避免“切换后历史 SKU 残留”
        submitData.skus = [];
      }
      if (isEdit.value) { await updateGoodsApi(editIdRef.value!, submitData); message.success('更新成功'); }
      else { await createGoodsApi(submitData); message.success('创建成功'); }
      onSuccess();
    } catch (error: any) {
      if (!error.errorFields) message.error(error.message || '操作失败');
    } finally { loading.value = false; }
  };

  const handleSpecTypeChange = (val: 'single' | 'multi') => {
    if (val === 'single' && specType.value === 'multi') {
      saveMultiSpecDraft();
    }
    specType.value = val;
    if (val === 'single') {
      attrs.value = [];
      skuRows.value = [];
      return;
    }
    restoreMultiSpecDraft();
    nextTick(() => {
      initSpecDrag();
      initValueDrag();
    });
  };

  return {
    formData, rules, formRef, loading, activeTab, isFullscreen, isEdit,
    toggleFullscreen, categoryTreeData, brandOptions, tagOptions,
    specType, attrs, canAddPic, getPicPreviewUrl, getPicUrl,
    handleAddSpec, handleRemoveSpec, addSpecValue, removeSpecValue, toggleAddPic,
    specListRef, valueListRefs, initSpecDrag, initValueDrag,
    skuRows, batchData, tableData, skuColumns, spanMap,
    generateSkuCombinations, applyBatch, clearBatch,
    specLibVisible, specImportTab, specLibLoading, specLibList, selectedSpecIds,
    specTemplateList, selectedTemplateIds, openSpecLib, confirmSelectSpecs,
    saveTemplateVisible, saveTemplateList, saveTemplateLoading, saveTemplateName,
    openSaveTemplate, handleSaveTemplate,
    loadOptions, resetForm, loadEditData, handleSubmit, handleSpecTypeChange,
  };
}
