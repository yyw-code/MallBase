import type { GoodsBrandApi } from '#/api/goods';
import type { GoodsCategoryApi } from '#/api/goods';
import type { GoodsApi } from '#/api/goods';
import type { GoodsSpecApi } from '#/api/goods';
import type { GoodsSpecTemplateApi } from '#/api/goods';
import type { GoodsTagApi } from '#/api/goods';

import type { FileInfo } from '#/components/upload';

import {
  computed,
  nextTick,
  onBeforeUnmount,
  reactive,
  ref,
  watch,
  type Ref,
} from 'vue';

import Sortable from 'sortablejs';
import { Modal, message } from 'ant-design-vue';

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
import {
  getFreightTemplateListApi,
  type FreightTemplateApi,
} from '#/api/setting/freight-template';

export interface AttrDetail {
  id: string;
  value: string;
  pic: FileInfo | string;
}
export interface Attr {
  id: string;
  value: string;
  add_pic: 0 | 1;
  detail: AttrDetail[];
}
export interface SkuRow {
  spec_values: string;
  detail: Record<string, string>;
  price: number | undefined;
  market_price: number | undefined;
  stock: number | undefined;
  sku_code: string;
  image: FileInfo | string | undefined;
  points_reward_mode?: GoodsApi.SkuPointsRewardMode;
  points_reward_ratio?: number;
  points_reward_fixed?: number;
  member_price?: number | undefined;
  description?: string;
  is_show?: 0 | 1;
}

const SPEC_TYPE_SINGLE = 1;
const SPEC_TYPE_MULTI = 2;
const DEFAULT_SINGLE_SKU_SPEC_VALUES = '';
type GoodsPointsRewardMode = Exclude<GoodsApi.GoodsPointsRewardMode, 'inherit'>;

const normalizeGoodsPointsRewardMode = (
  mode?: GoodsApi.GoodsPointsRewardMode,
): GoodsPointsRewardMode =>
  mode && mode !== 'inherit' ? mode : 'global';

export function useGoodsEdit(editIdRef: Ref<number | undefined>) {
  const createLocalId = () =>
    `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

  const createAttrDetail = (
    value = '',
    pic: FileInfo | string = '',
  ): AttrDetail => ({
    id: createLocalId(),
    value,
    pic,
  });

  const createAttr = (
    value = '',
    addPic: 0 | 1 = 0,
    detail: AttrDetail[] = [createAttrDetail()],
  ): Attr => ({
    id: createLocalId(),
    value,
    add_pic: addPic,
    detail,
  });

  /* ---------- 基本表单 ---------- */
  const formData = reactive({
    name: '',
    subtitle: '',
    category_id: undefined as number | undefined,
    brand_id: undefined as number | undefined,
    freight_template_id: undefined as number | undefined,
    unit: '件',
    price: 0,
    market_price: 0,
    stock: 0,
    main_image: undefined as FileInfo | string | undefined,
    main_video: undefined as FileInfo | string | undefined,
    images: [] as (FileInfo | string)[],
    description: '',
    sku_detail_enabled: 0 as 0 | 1,
    sort: 0,
    status: 1,
    is_on_sale: 0,
    is_recommend: 0,
    is_new: 0,
    is_hot: 0,
    points_reward_mode: 'global' as GoodsPointsRewardMode,
    points_reward_ratio: 0,
    points_reward_fixed: 0,
    member_benefit_mode: 'global' as GoodsApi.MemberBenefitMode,
    member_price: undefined as number | undefined,
    sku_points_reward_mode: 'inherit' as GoodsApi.SkuPointsRewardMode,
    sku_points_reward_ratio: 0,
    sku_points_reward_fixed: 0,
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
  const toggleFullscreen = () => {
    isFullscreen.value = !isFullscreen.value;
  };
  const isEdit = computed(() => !!editIdRef.value);

  /* ---------- 分类 / 品牌 / 标签 ---------- */
  const categoryTreeData = ref<any[]>([]);
  const brandOptions = ref<GoodsBrandApi.BrandItem[]>([]);
  const freightTemplateOptions = ref<FreightTemplateApi.TemplateItem[]>([]);
  const tagOptions = ref<GoodsTagApi.TagItem[]>([]);
  const buildTree = (list: GoodsCategoryApi.CategoryItem[], pid = 0): any[] =>
    list
      .filter((item) => item.pid === pid)
      .map((item) => ({
        title: item.name,
        value: item.id,
        key: item.id,
        children: buildTree(list, item.id),
      }));

  /* ---------- 规格 attrs ---------- */
  const specType = ref<'single' | 'multi'>('single');
  const attrs = ref<Attr[]>([]);
  const canAddPic = computed(() => !attrs.value.some((a) => a.add_pic === 1));

  const getPicPreviewUrl = (pic: FileInfo | string): string => {
    if (!pic) return '';
    if (typeof pic === 'object') return pic.full_url || pic.url || '';
    return pic;
  };
  const isAssetIdValue = (value: unknown): boolean =>
    typeof value === 'number' ||
    (typeof value === 'string' && /^\d+$/.test(value));

  const toMediaSubmitValue = (
    media: FileInfo | string | undefined,
  ): GoodsApi.MediaValue | '' => {
    if (!media) return '';
    if (typeof media === 'object') {
      if (media.asset_id && media.asset_id > 0) return media.asset_id;
      if (isAssetIdValue(media.url)) return Number(media.url);
      return media.url || '';
    }
    if (isAssetIdValue(media)) return Number(media);
    return media;
  };

  const toMediaFileInfo = (
    value: unknown,
    fullUrl?: string,
  ): FileInfo | undefined => {
    const mediaValue = toMediaSubmitValue(
      value === undefined || value === null ? undefined : String(value),
    );
    if (mediaValue === '') return undefined;

    const rawValue = String(mediaValue);
    const nameSource = fullUrl || rawValue;
    return {
      url: rawValue,
      asset_id: typeof mediaValue === 'number' ? mediaValue : undefined,
      full_url: fullUrl || '',
      name: fileNameFromValue(nameSource) || `asset-${rawValue}`,
    };
  };

  const getPicUrl = (pic: FileInfo | string): GoodsApi.MediaValue | '' => {
    if (!pic) return '';
    return toMediaSubmitValue(pic);
  };
  const fileNameFromValue = (value: unknown) =>
    String(value || '')
      .split('/')
      .pop() || '';

  const getSpecImageByRow = (row: SkuRow): FileInfo | string | undefined => {
    const attrWithPic = attrs.value.find((attr) => attr.add_pic === 1);
    if (!attrWithPic || !attrWithPic.value) {
      return undefined;
    }

    const detailValue = row.detail[attrWithPic.value];
    if (!detailValue) {
      return undefined;
    }

    return (
      attrWithPic.detail.find((detail) => detail.value === detailValue)?.pic ||
      undefined
    );
  };

  const getSkuPreviewImage = (row: SkuRow): FileInfo | string | undefined => {
    return row.image || getSpecImageByRow(row);
  };

  const getSkuSubmitImage = (row: SkuRow): GoodsApi.MediaValue | '' => {
    const image = row.image || getSpecImageByRow(row);
    return getPicUrl(image || '');
  };

  const buildSingleSkuPayload = () => ({
    spec_values: DEFAULT_SINGLE_SKU_SPEC_VALUES,
    price: formData.price,
    market_price: formData.market_price,
    stock: formData.stock,
    sku_code: '',
    image: toMediaSubmitValue(formData.main_image),
    points_reward_mode:
      formData.points_reward_mode === 'sku'
        ? formData.sku_points_reward_mode
        : ('inherit' as const),
    points_reward_ratio: formData.sku_points_reward_ratio || 0,
    points_reward_fixed: formData.sku_points_reward_fixed || 0,
    member_price: formData.member_price ?? null,
    description: '',
    status: formData.status ?? 1,
  });

  const updateMatchedSkuImages = (
    specName: string,
    specValue: string,
    nextPic: FileInfo | string | undefined,
  ) => {
    for (const row of skuRows.value) {
      if (row.detail[specName] === specValue) {
        row.image = nextPic;
      }
    }
  };

  const confirmSpecImageSync = (
    matchedCount: number,
    overriddenCount: number,
  ) =>
    new Promise<boolean>((resolve) => {
      let settled = false;
      const finish = (result: boolean) => {
        if (settled) {
          return;
        }
        settled = true;
        resolve(result);
      };
      let content = `可以同步修改下方 ${matchedCount} 个命中 SKU 的规格图片，确定要替换吗？`;
      if (overriddenCount > 0) {
        content += ` 其中 ${overriddenCount} 个 SKU 已手动设置过图片，确认后也会一起覆盖。`;
      }
      Modal.confirm({
        title: '提示',
        content,
        okText: '替换',
        cancelText: '暂不',
        onOk: () => finish(true),
        onCancel: () => finish(false),
        afterClose: () => finish(false),
      });
    });

  const handleSpecValueImageChange = async (
    attrIdx: number,
    detIdx: number,
    nextPic?: FileInfo | string,
  ) => {
    const attr = attrs.value[attrIdx];
    const detail = attr?.detail[detIdx];
    if (!attr || !detail) {
      return;
    }

    const nextValue = nextPic || '';
    const matchedRows = skuRows.value.filter(
      (row) => row.detail[attr.value] === detail.value,
    );
    detail.pic = nextValue;

    if (!nextValue || matchedRows.length === 0) {
      if (!nextValue) {
        updateMatchedSkuImages(attr.value, detail.value, undefined);
      }
      return;
    }

    const overriddenCount = matchedRows.filter((row) => !!row.image).length;
    const confirmed = await confirmSpecImageSync(
      matchedRows.length,
      overriddenCount,
    );
    if (confirmed) {
      updateMatchedSkuImages(attr.value, detail.value, nextValue);
      message.success('已同步替换命中 SKU 图片');
    }
  };

  const handleAddSpec = () => {
    attrs.value.push(createAttr());
    nextTick(() => {
      initSpecDrag();
      initValueDrag();
    });
  };
  const handleRemoveSpec = (idx: number) => {
    attrs.value.splice(idx, 1);
    generateSkuCombinations();
    nextTick(() => {
      initSpecDrag();
      initValueDrag();
    });
  };
  const addSpecValue = (attrIdx: number) => {
    attrs.value[attrIdx]!.detail.push(createAttrDetail());
    nextTick(() => initValueDragAt(attrIdx));
  };
  const removeSpecValue = (attrIdx: number, detIdx: number) => {
    if (attrs.value[attrIdx]!.detail.length <= 1) {
      message.warning('至少保留一个规格值');
      return;
    }
    attrs.value[attrIdx]!.detail.splice(detIdx, 1);
    generateSkuCombinations();
    nextTick(() => initValueDragAt(attrIdx));
  };
  const toggleAddPic = (e: boolean | 0 | 1, idx: number) => {
    if (e) {
      attrs.value.forEach((a, i) => {
        if (i !== idx) a.add_pic = 0;
      });
    }
    generateSkuCombinations();
    nextTick(initValueDrag);
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
      handle: '.spec-drag-zone',
      draggable: '.spec-item',
      animation: 150,
      filter:
        'input,textarea,button,.spec-name-actions,.ant-checkbox-wrapper,.ant-input,.ant-input-affix-wrapper,.ant-input-number,.ant-select,.ant-select-selector',
      preventOnFilter: false,
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

  // 当 specListRef 挂载时自动初始化拖拽（解决 v-else 条件渲染时序问题）
  watch(specListRef, (el) => {
    if (el) {
      nextTick(() => {
        initSpecDrag();
        initValueDrag();
      });
    }
  });

  onBeforeUnmount(() => {
    specSortable?.destroy();
    specSortable = null;
    valueSortables.forEach((s) => s?.destroy());
    valueSortables.length = 0;
  });

  /* ---------- SKU 表格 ---------- */
  const skuRows = ref<SkuRow[]>([]);
  const multiSpecDraft = ref<{ attrs: Attr[]; skuRows: SkuRow[] }>({
    attrs: [],
    skuRows: [],
  });

  const cloneAttrs = (source: Attr[]): Attr[] =>
    source.map((attr) => ({
      id: attr.id || createLocalId(),
      value: attr.value,
      add_pic: attr.add_pic,
      detail: attr.detail.map((det) => ({
        id: det.id || createLocalId(),
        value: det.value,
        pic: det.pic,
      })),
    }));

  const cloneSkuRows = (source: SkuRow[]): SkuRow[] =>
    source.map((row) => ({
      spec_values: row.spec_values,
      detail: { ...row.detail },
      price: row.price,
      market_price: row.market_price,
      stock: row.stock,
      sku_code: row.sku_code,
      image: row.image,
      points_reward_mode: row.points_reward_mode,
      points_reward_ratio: row.points_reward_ratio,
      points_reward_fixed: row.points_reward_fixed,
      member_price: row.member_price,
      description: row.description,
      is_show: row.is_show,
    }));

  const saveMultiSpecDraft = () => {
    multiSpecDraft.value = {
      attrs: cloneAttrs(attrs.value),
      skuRows: cloneSkuRows(skuRows.value),
    };
  };

  const restoreMultiSpecDraft = () => {
    if (
      multiSpecDraft.value.attrs.length === 0 &&
      multiSpecDraft.value.skuRows.length === 0
    ) {
      return;
    }
    attrs.value = cloneAttrs(multiSpecDraft.value.attrs);
    skuRows.value = cloneSkuRows(multiSpecDraft.value.skuRows);
  };

  const inferSpecImagesFromSkus = (
    sourceSkus: Array<Record<string, any>>,
    targetAttrs: Attr[],
  ) => {
    let picAttrIndex = -1;
    let picMap: Record<string, FileInfo> = {};

    targetAttrs.forEach((_attr, attrIdx) => {
      const currentMap: Record<string, FileInfo> = {};
      let hasImage = false;
      let isConsistent = true;

      for (const sku of sourceSkus) {
        const parts = String(sku.spec_values || '').split(',');
        const value = parts[attrIdx] || '';
        const nextImage = toMediaFileInfo(sku.image, sku.image_full_url);
        if (!value || !nextImage) {
          continue;
        }

        hasImage = true;
        const existing = currentMap[value];
        if (existing && existing.url !== nextImage.url) {
          isConsistent = false;
          break;
        }
        currentMap[value] = nextImage;
      }

      if (isConsistent && hasImage && Object.keys(currentMap).length > 0) {
        picAttrIndex = attrIdx;
        picMap = currentMap;
      }
    });

    if (picAttrIndex < 0) {
      return;
    }

    targetAttrs.forEach((attr, attrIdx) => {
      attr.add_pic = attrIdx === picAttrIndex ? 1 : 0;
      attr.detail.forEach((detail) => {
        detail.pic = attrIdx === picAttrIndex ? picMap[detail.value] || '' : '';
      });
    });
  };

  const buildSpecMetaPayload = (): GoodsApi.SpecMetaItem[] =>
    attrs.value
      .filter((attr) => attr.value.trim())
      .map((attr) => ({
        name: attr.value.trim(),
        add_pic: attr.add_pic,
        values: attr.detail
          .filter((detail) => detail.value.trim())
          .map((detail) => ({
            value: detail.value.trim(),
            pic: getPicUrl(detail.pic || ''),
          })),
      }));

  const batchData = reactive<Record<string, any>>({});
  const batchFilters = reactive<Record<string, string | undefined>>({});
  const tableData = computed<SkuRow[]>(() => skuRows.value);
  const matchedSkuRows = computed(() =>
    skuRows.value.filter((row) =>
      attrs.value.every((attr, idx) => {
        const title = attr.value || `规格${idx + 1}`;
        const filterValue = batchFilters[title];
        return !filterValue || row.detail[title] === filterValue;
      }),
    ),
  );
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
          const dataIdx = rowIdx;
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
      { title: '积分模式', dataIndex: 'points_reward_mode', width: 120 },
      { title: '每元积分', dataIndex: 'points_reward_ratio', width: 100 },
      { title: '每件积分', dataIndex: 'points_reward_fixed', width: 100 },
      { title: '会员价', dataIndex: 'member_price', width: 110 },
      { title: '规格详情', dataIndex: 'description', width: 90 },
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
    const validAttrs = attrs.value.filter(
      (a) => a.value && a.detail.some((d) => d.value),
    );
    if (validAttrs.length === 0) {
      skuRows.value = [];
      return;
    }
    const cartesian = (...arrays: any[][]): any[][] => {
      if (arrays.length === 0) return [[]];
      const [first, ...rest] = arrays;
      const restProduct = cartesian(...rest);
      return first!.flatMap((item) =>
        restProduct.map((product) => [item, ...product]),
      );
    };
    const valueArrays = validAttrs.map((attr) =>
      attr.detail
        .filter((d) => d.value)
        .map((d) => ({ attrName: attr.value, value: d.value })),
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
        points_reward_mode: 'inherit',
        points_reward_ratio: 0,
        points_reward_fixed: 0,
        member_price: undefined,
        description: '',
        is_show: 1,
      };
    });
  };
  const applyBatch = () => {
    const targets = matchedSkuRows.value;
    if (targets.length === 0) {
      message.warning('当前筛选条件下没有可批量修改的 SKU');
      return;
    }
    for (const row of targets) {
      if (
        batchData['__price__'] !== undefined &&
        batchData['__price__'] !== null &&
        batchData['__price__'] !== ''
      )
        row.price = Number(batchData['__price__']);
      if (
        batchData['__market_price__'] !== undefined &&
        batchData['__market_price__'] !== null &&
        batchData['__market_price__'] !== ''
      )
        row.market_price = Number(batchData['__market_price__']);
      if (
        batchData['__stock__'] !== undefined &&
        batchData['__stock__'] !== null &&
        batchData['__stock__'] !== ''
      )
        row.stock = Number(batchData['__stock__']);
      if (batchData['__sku_code__'])
        row.sku_code = String(batchData['__sku_code__']);
      if (batchData['__image__']) row.image = batchData['__image__'];
      if (batchData['__points_reward_mode__'])
        row.points_reward_mode = batchData['__points_reward_mode__'];
      if (
        batchData['__points_reward_ratio__'] !== undefined &&
        batchData['__points_reward_ratio__'] !== null &&
        batchData['__points_reward_ratio__'] !== ''
      )
        row.points_reward_ratio = Number(batchData['__points_reward_ratio__']);
      if (
        batchData['__points_reward_fixed__'] !== undefined &&
        batchData['__points_reward_fixed__'] !== null &&
        batchData['__points_reward_fixed__'] !== ''
      )
        row.points_reward_fixed = Number(batchData['__points_reward_fixed__']);
      if (
        batchData['__member_price__'] !== undefined &&
        batchData['__member_price__'] !== null &&
        batchData['__member_price__'] !== ''
      )
        row.member_price = Number(batchData['__member_price__']);
      if (
        batchData['__is_show__'] !== undefined &&
        batchData['__is_show__'] !== null &&
        batchData['__is_show__'] !== ''
      ) {
        row.is_show = Number(batchData['__is_show__']) as 0 | 1;
      }
    }
    message.success(`已批量修改 ${targets.length} 个 SKU`);
  };
  const clearBatch = () => {
    Object.keys(batchData).forEach((k) => {
      batchData[k] = '';
    });
  };
  const clearBatchFilters = () => {
    Object.keys(batchFilters).forEach((k) => {
      batchFilters[k] = undefined;
    });
  };
  const resetBatchEditor = () => {
    clearBatch();
    clearBatchFilters();
  };

  const validateUniqueSkuCodes = () => {
    const codeMap = new Map<string, number>();

    for (const [index, sku] of skuRows.value.entries()) {
      const code = String(sku.sku_code || '').trim();
      if (!code) {
        continue;
      }

      if (codeMap.has(code)) {
        throw new Error(`SKU编码重复：${code}`);
      }

      codeMap.set(code, index);
    }
  };

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
      const [specs, templates] = await Promise.all([
        getAllGoodsSpecsApi(),
        getAllGoodsSpecTemplatesApi(),
      ]);
      specLibList.value = specs;
      specTemplateList.value = templates;
    } catch {
      message.error('加载规格库失败');
    } finally {
      specLibLoading.value = false;
    }
  };
  const confirmSelectSpecs = () => {
    let added = 0;
    if (specImportTab.value === 'spec') {
      const selected = specLibList.value.filter((s) =>
        selectedSpecIds.value.includes(s.id),
      );
      for (const spec of selected) {
        if (attrs.value.some((a) => a.value === spec.name)) continue;
        const values = (spec.spec_values || spec.specValues || []).map((v) =>
          createAttrDetail(v.value),
        );
        if (values.length === 0) values.push(createAttrDetail());
        attrs.value.push(createAttr(spec.name, 0, values));
        added++;
      }
    } else {
      const selected = specTemplateList.value.filter((t) =>
        selectedTemplateIds.value.includes(t.id),
      );
      for (const tpl of selected) {
        for (const item of tpl.detail || []) {
          if (attrs.value.some((a) => a.value === item.spec_name)) continue;
          const values = (item.values || []).map((v) => createAttrDetail(v));
          if (values.length === 0) values.push(createAttrDetail());
          attrs.value.push(createAttr(item.spec_name, 0, values));
          added++;
        }
      }
    }
    if (added === 0) {
      message.info('所选规格已全部存在，未重复添加');
    } else {
      generateSkuCombinations();
      nextTick(() => {
        initSpecDrag();
        initValueDrag();
      });
      message.success(`已导入 ${added} 个规格`);
    }
    specLibVisible.value = false;
  };

  /* ---------- 另存为模板 ---------- */
  interface SaveTemplateItem {
    selected: boolean;
    name: string;
    values: string[];
  }
  const saveTemplateVisible = ref(false);
  const saveTemplateList = ref<SaveTemplateItem[]>([]);
  const saveTemplateLoading = ref(false);
  const saveTemplateName = ref('');

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
    saveTemplateName.value = '';
    saveTemplateVisible.value = true;
  };
  const handleSaveTemplate = async () => {
    if (!saveTemplateName.value.trim()) {
      message.warning('请输入模板名称');
      return;
    }
    const detail = saveTemplateList.value
      .filter((a) => a.selected && a.values.length > 0)
      .map((a) => ({ spec_name: a.name, values: a.values }));
    if (detail.length === 0) {
      message.warning('请至少选择一个有规格值的规格');
      return;
    }
    saveTemplateLoading.value = true;
    try {
      await createGoodsSpecTemplateApi({
        name: saveTemplateName.value.trim(),
        detail,
      });
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
        getAllGoodsCategoriesApi(),
        getAllGoodsBrandsApi(),
        getAllGoodsTagsApi(),
      ]);
      categoryTreeData.value = buildTree(categories);
      brandOptions.value = brands;
      tagOptions.value = tags;
    } catch {
      /* silent */
    }
    // 运费模板独立加载：其失败不应阻塞分类 / 品牌 / 标签等核心选项
    try {
      const freightTemplates = await getFreightTemplateListApi({
        status: 1,
        limit: 200,
      });
      freightTemplateOptions.value = freightTemplates.list || [];
    } catch {
      /* silent */
    }
  };

  /* ---------- 重置 ---------- */
  const resetForm = () => {
    formRef.value?.resetFields();
    Object.assign(formData, {
      name: '',
      subtitle: '',
      category_id: undefined,
      brand_id: undefined,
      freight_template_id: undefined,
      unit: '件',
      price: 0,
      market_price: 0,
      stock: 0,
      main_image: undefined,
      main_video: undefined,
      images: [],
      description: '',
      sku_detail_enabled: 0,
      sort: 0,
      status: 1,
      is_on_sale: 0,
      is_recommend: 0,
      is_new: 0,
      is_hot: 0,
      points_reward_mode: 'global',
      points_reward_ratio: 0,
      points_reward_fixed: 0,
      member_benefit_mode: 'global',
      member_price: undefined,
      sku_points_reward_mode: 'inherit',
      sku_points_reward_ratio: 0,
      sku_points_reward_fixed: 0,
      tag_ids: [],
    });
    specType.value = 'single';
    attrs.value = [];
    skuRows.value = [];
    multiSpecDraft.value = { attrs: [], skuRows: [] };
    clearBatchFilters();
    clearBatch();
    isFullscreen.value = false;
    activeTab.value = 'basic';
  };

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
        freight_template_id: detail.freight_template_id ?? undefined,
        unit: detail.unit || '件',
        price: detail.price || 0,
        market_price: detail.market_price || 0,
        stock: detail.stock || 0,
        main_image: toMediaFileInfo(
          detail.main_image,
          detail.main_image_full_url,
        ),
        main_video: toMediaFileInfo(
          detail.main_video,
          detail.main_video_full_url,
        ),
        images: (detail.images || [])
          .map((img) => toMediaFileInfo(img.url, img.full_url))
          .filter((img): img is FileInfo => !!img),
        description: detail.description || '',
        sku_detail_enabled: Number(detail.sku_detail_enabled || 0) as 0 | 1,
        sort: detail.sort || 0,
        status: detail.status ?? 1,
        is_on_sale: detail.is_on_sale ?? 0,
        is_recommend: detail.is_recommend ?? 0,
        is_new: detail.is_new ?? 0,
        is_hot: detail.is_hot ?? 0,
        points_reward_mode: normalizeGoodsPointsRewardMode(
          detail.points_reward_mode,
        ),
        points_reward_ratio: detail.points_reward_ratio ?? 0,
        points_reward_fixed: detail.points_reward_fixed ?? 0,
        member_benefit_mode: detail.member_benefit_mode || 'global',
        tag_ids: (detail.tags || []).map((t) => t.id),
      });
      if ((detail.spec_type ?? SPEC_TYPE_SINGLE) === SPEC_TYPE_MULTI) {
        specType.value = 'multi';
        const detailSkus = Array.isArray(detail.skus) ? detail.skus : [];
        let newAttrs: Attr[] = [];
        if (Array.isArray(detail.spec_meta) && detail.spec_meta.length > 0) {
          newAttrs = detail.spec_meta.map((item) =>
            createAttr(
              item.name || '',
              item.add_pic ?? 0,
              (item.values || []).map((value) =>
                createAttrDetail(
                  value.value || '',
                  toMediaFileInfo(value.pic, value.pic_full_url) || '',
                ),
              ),
            ),
          );
        } else {
          const colCount = (detailSkus[0]?.spec_values || '')
            .split(',')
            .filter(Boolean).length;
          newAttrs = Array.from({ length: colCount }, (_, i) =>
            createAttr(`规格${i + 1}`, 0, []),
          );
          const valueSetsByPos: Set<string>[] = Array.from(
            { length: colCount },
            () => new Set(),
          );
          for (const sku of detailSkus) {
            (sku.spec_values || '').split(',').forEach((v, i) => {
              if (v) valueSetsByPos[i]?.add(v);
            });
          }
          for (let i = 0; i < colCount; i++) {
            newAttrs[i]!.detail = [...(valueSetsByPos[i] || [])].map((v) =>
              createAttrDetail(v),
            );
          }
          inferSpecImagesFromSkus(detailSkus, newAttrs);
        }
        attrs.value = newAttrs;
        generateSkuCombinations();
        const skuMap = new Map(detailSkus.map((s) => [s.spec_values, s]));
        for (const row of skuRows.value) {
          const sku = skuMap.get(row.spec_values);
          if (sku) {
            row.price = sku.price;
            row.market_price = sku.market_price || 0;
            row.stock = sku.stock;
            row.sku_code = sku.sku_code || '';
            row.points_reward_mode = sku.points_reward_mode || 'inherit';
            row.points_reward_ratio = sku.points_reward_ratio ?? 0;
            row.points_reward_fixed = sku.points_reward_fixed ?? 0;
            row.member_price =
              sku.member_price === null || sku.member_price === undefined
                ? undefined
                : Number(sku.member_price);
            row.description = sku.description || '';
            row.is_show = Number(sku.status ?? 1) as 0 | 1;
            row.image = toMediaFileInfo(sku.image, sku.image_full_url);
          }
        }
        saveMultiSpecDraft();
      } else {
        specType.value = 'single';
        const defaultSku = Array.isArray(detail.skus)
          ? detail.skus[0]
          : undefined;
        formData.price = defaultSku?.price ?? detail.price ?? 0;
        formData.market_price =
          defaultSku?.market_price ?? detail.market_price ?? 0;
        formData.stock = defaultSku?.stock ?? detail.stock ?? 0;
        formData.member_price =
          defaultSku?.member_price === null ||
          defaultSku?.member_price === undefined
            ? undefined
            : Number(defaultSku.member_price);
        formData.sku_points_reward_mode =
          defaultSku?.points_reward_mode || 'inherit';
        formData.sku_points_reward_ratio =
          defaultSku?.points_reward_ratio ?? 0;
        formData.sku_points_reward_fixed =
          defaultSku?.points_reward_fixed ?? 0;
        multiSpecDraft.value = { attrs: [], skuRows: [] };
      }
    } catch {
      message.error('加载商品详情失败');
    } finally {
      loading.value = false;
    }
  };

  /* ---------- 提交 ---------- */
  const handleSubmit = async (onSuccess: () => void) => {
    try {
      await formRef.value?.validate();
      loading.value = true;
      const submitData: any = {
        ...formData,
        main_image: toMediaSubmitValue(formData.main_image),
        main_video: toMediaSubmitValue(formData.main_video),
        images: formData.images
          .map((img: FileInfo | string) => toMediaSubmitValue(img))
          .filter((img) => img !== ''),
      };
      delete submitData.member_price;
      delete submitData.sku_points_reward_mode;
      delete submitData.sku_points_reward_ratio;
      delete submitData.sku_points_reward_fixed;
      if (specType.value === 'multi' && skuRows.value.length > 0) {
        submitData.spec_type = SPEC_TYPE_MULTI;
        validateUniqueSkuCodes();
        submitData.spec_meta = buildSpecMetaPayload();
        submitData.skus = skuRows.value.map((sku) => ({
          spec_values: sku.spec_values,
          price: sku.price,
          market_price: sku.market_price,
          stock: sku.stock,
          sku_code: sku.sku_code || '',
          image: getSkuSubmitImage(sku),
          points_reward_mode: sku.points_reward_mode || 'inherit',
          points_reward_ratio: sku.points_reward_ratio || 0,
          points_reward_fixed: sku.points_reward_fixed || 0,
          member_price: sku.member_price ?? null,
          description: sku.description || '',
          status: sku.is_show ?? 1,
        }));
      } else {
        submitData.spec_type = SPEC_TYPE_SINGLE;
        submitData.sku_detail_enabled = 0;
        submitData.spec_meta = [];
        submitData.skus = [buildSingleSkuPayload()];
      }
      if (isEdit.value) {
        await updateGoodsApi(editIdRef.value!, submitData);
        message.success('更新成功');
      } else {
        await createGoodsApi(submitData);
        message.success('创建成功');
      }
      onSuccess();
    } catch (error: any) {
      if (!error.errorFields) message.error(error.message || '操作失败');
    } finally {
      loading.value = false;
    }
  };

  const handleSpecTypeChange = (val: 'single' | 'multi') => {
    if (val === 'single' && specType.value === 'multi') {
      saveMultiSpecDraft();
    }
    specType.value = val;
    if (val === 'single') {
      formData.sku_detail_enabled = 0;
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
    formData,
    rules,
    formRef,
    loading,
    activeTab,
    isFullscreen,
    isEdit,
    toggleFullscreen,
    categoryTreeData,
    brandOptions,
    freightTemplateOptions,
    tagOptions,
    specType,
    attrs,
    canAddPic,
    getPicPreviewUrl,
    getPicUrl,
    getSkuPreviewImage,
    handleAddSpec,
    handleRemoveSpec,
    addSpecValue,
    removeSpecValue,
    toggleAddPic,
    handleSpecValueImageChange,
    specListRef,
    valueListRefs,
    initSpecDrag,
    initValueDrag,
    skuRows,
    batchData,
    batchFilters,
    matchedSkuRows,
    tableData,
    skuColumns,
    spanMap,
    generateSkuCombinations,
    applyBatch,
    clearBatch,
    clearBatchFilters,
    resetBatchEditor,
    specLibVisible,
    specImportTab,
    specLibLoading,
    specLibList,
    selectedSpecIds,
    specTemplateList,
    selectedTemplateIds,
    openSpecLib,
    confirmSelectSpecs,
    saveTemplateVisible,
    saveTemplateList,
    saveTemplateLoading,
    saveTemplateName,
    openSaveTemplate,
    handleSaveTemplate,
    loadOptions,
    resetForm,
    loadEditData,
    handleSubmit,
    handleSpecTypeChange,
  };
}
