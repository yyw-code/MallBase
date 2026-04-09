<script lang="ts" setup>
import type { GoodsApi } from '#/api/goods';
import type { GoodsCategoryApi } from '#/api/goods';
import type { GoodsBrandApi } from '#/api/goods';
import type { GoodsSpecApi } from '#/api/goods';
import type { GoodsTagApi } from '#/api/goods';

import type { FileInfo } from '#/components/upload';

import { computed, onMounted, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

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

const props = withDefaults(defineProps<Props>(), {
  visible: false,
  editData: null,
});

const emit = defineEmits<Emits>();

const isEdit = computed(() => !!props.editData);

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
  price: [{ required: true, message: '请输入价格', trigger: 'blur' }],
  stock: [{ required: true, message: '请输入库存', trigger: 'blur' }],
};

const formRef = ref();
const loading = ref(false);
const activeTab = ref('basic');

/* ---------------- 分类树数据 ---------------- */
const categoryTreeData = ref<any[]>([]);

const buildTree = (list: GoodsCategoryApi.CategoryItem[], pid: number = 0): any[] => {
  return list
    .filter((item) => item.pid === pid)
    .map((item) => ({
      title: item.name,
      value: item.id,
      key: item.id,
      children: buildTree(list, item.id),
    }));
};

/* ---------------- 品牌选项 ---------------- */
const brandOptions = ref<GoodsBrandApi.BrandItem[]>([]);

/* ---------------- 规格选项 ---------------- */
const specOptions = ref<GoodsSpecApi.SpecItem[]>([]);
const selectedSpecIds = ref<number[]>([]);

/* ---------------- 标签选项 ---------------- */
const tagOptions = ref<GoodsTagApi.TagItem[]>([]);

/* ---------------- 新增规格相关 ---------------- */
const newSpecModalVisible = ref(false);
const newSpecForm = reactive({
  name: '',
  values: '',
});
const newSpecCreating = ref(false);

/** 打开新增规格弹窗 */
const openNewSpecModal = () => {
  newSpecForm.name = '';
  newSpecForm.values = '';
  newSpecModalVisible.value = true;
};

/** 提交新增规格 */
const handleCreateSpec = async () => {
  if (!newSpecForm.name.trim()) {
    message.warning('请输入规格名称');
    return;
  }
  const values = newSpecForm.values
    .split(/[,，、\n]/)
    .map((v) => v.trim())
    .filter(Boolean);
  if (values.length === 0) {
    message.warning('请输入至少一个规格值');
    return;
  }

  try {
    newSpecCreating.value = true;
    // 创建规格组
    const res = await createGoodsSpecApi({ name: newSpecForm.name.trim() });
    const specId = res.id;
    // 批量创建规格值
    await batchCreateSpecValuesApi(specId, values);
    message.success('规格创建成功');
    newSpecModalVisible.value = false;

    // 重新加载规格列表并自动选中新建的规格
    const specs = await getAllGoodsSpecsApi();
    specOptions.value = specs;
    if (!selectedSpecIds.value.includes(specId)) {
      selectedSpecIds.value = [...selectedSpecIds.value, specId];
      generateSkuCombinations();
    }
  } catch (error: any) {
    message.error(error.message || '创建规格失败');
  } finally {
    newSpecCreating.value = false;
  }
};

/* ---------------- SKU 组合 ---------------- */
interface SkuRow {
  spec_values: string;
  price: number;
  market_price: number;
  stock: number;
  sku_code: string;
  image: FileInfo | string | undefined;
}

const skuRows = ref<SkuRow[]>([]);

const generateSkuCombinations = () => {
  if (selectedSpecIds.value.length === 0) {
    skuRows.value = [];
    return;
  }

  const selectedSpecs = specOptions.value.filter((s) =>
    selectedSpecIds.value.includes(s.id),
  );

  // 获取每个规格的值列表
  const specValueGroups = selectedSpecs
    .map((spec) => {
      const values = spec.spec_values || [];
      return values.map((v) => ({ specName: spec.name, value: v.value }));
    })
    .filter((group) => group.length > 0);

  if (specValueGroups.length === 0) {
    skuRows.value = [];
    return;
  }

  // 计算笛卡尔积
  const cartesian = (...arrays: any[][]): any[][] => {
    if (arrays.length === 0) return [[]];
    const [first, ...rest] = arrays;
    const restProduct = cartesian(...rest);
    return first.flatMap((item) =>
      restProduct.map((product) => [item, ...product]),
    );
  };

  const combinations = cartesian(...specValueGroups);

  // 保留已有 SKU 数据
  const existingSkuMap = new Map<string, SkuRow>();
  for (const row of skuRows.value) {
    existingSkuMap.set(row.spec_values, row);
  }

  skuRows.value = combinations.map((combo) => {
    const specValuesStr = combo.map((c: any) => c.value).join(',');
    const existing = existingSkuMap.get(specValuesStr);
    return existing || {
      spec_values: specValuesStr,
      price: formData.price,
      market_price: formData.market_price,
      stock: formData.stock,
      sku_code: '',
      image: undefined,
    };
  });
};

const handleSpecChange = () => {
  generateSkuCombinations();
};

/* ---------------- 加载选项数据 ---------------- */
const loadOptions = async () => {
  try {
    const [categories, brands, specs, tags] = await Promise.all([
      getAllGoodsCategoriesApi(),
      getAllGoodsBrandsApi(),
      getAllGoodsSpecsApi(),
      getAllGoodsTagsApi(),
    ]);

    categoryTreeData.value = buildTree(categories);
    brandOptions.value = brands;
    specOptions.value = specs;
    tagOptions.value = tags;
  } catch (error) {
    console.error('加载选项数据失败:', error);
  }
};

/* ---------------- 监听 visible 变化 ---------------- */
watch(
  () => props.visible,
  async (val) => {
    if (val) {
      resetForm();
      activeTab.value = 'basic';
      await loadOptions();

      if (props.editData) {
        // 编辑模式：加载完整数据（loadOptions 已完成，specOptions 可用）
        loadEditData(props.editData.id);
      }
    }
  },
);

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
      images: (detail.images || []).map((img) => ({
        url: img.url,
        name: img.url.split('/').pop() || '',
      })),
      description: detail.description || '',
      sort: detail.sort || 0,
      status: detail.status ?? 1,
      is_on_sale: detail.is_on_sale ?? 0,
      is_recommend: detail.is_recommend ?? 0,
      is_new: detail.is_new ?? 0,
      is_hot: detail.is_hot ?? 0,
      tag_ids: (detail.tags || []).map((t) => t.id),
    });

    // 回填 SKU 数据并恢复选中的规格
    if (detail.skus && detail.skus.length > 0) {
      skuRows.value = detail.skus.map((sku) => ({
        spec_values: sku.spec_values,
        price: sku.price,
        market_price: sku.market_price || 0,
        stock: sku.stock,
        sku_code: sku.sku_code || '',
        image: sku.image || undefined,
      }));

      // 从 SKU 的 spec_values 反推选中的规格
      const specValueSet = new Set<string>();
      for (const sku of detail.skus) {
        sku.spec_values.split(',').forEach((v) => specValueSet.add(v.trim()));
      }

      // 匹配规格选项
      const matchedSpecIds: number[] = [];
      for (const spec of specOptions.value) {
        const specValues = (spec.spec_values || []).map((v) => v.value);
        if (specValues.some((v) => specValueSet.has(v))) {
          matchedSpecIds.push(spec.id);
        }
      }
      selectedSpecIds.value = matchedSpecIds;
    }
  } catch (error) {
    console.error('加载商品详情失败:', error);
    message.error('加载商品详情失败');
  } finally {
    loading.value = false;
  }
};

/* ---------------- 重置表单 ---------------- */
const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    name: '',
    subtitle: '',
    category_id: undefined,
    brand_id: undefined,
    unit: '件',
    price: 0,
    market_price: 0,
    stock: 0,
    main_image: undefined,
    images: [],
    description: '',
    sort: 0,
    status: 1,
    is_on_sale: 0,
    is_recommend: 0,
    is_new: 0,
    is_hot: 0,
    tag_ids: [],
  });
  selectedSpecIds.value = [];
  skuRows.value = [];
};

/* ---------------- 提交表单 ---------------- */
const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;

    const submitData = {
      ...formData,
      main_image: typeof formData.main_image === 'object' ? formData.main_image?.url || '' : formData.main_image || '',
      images: formData.images.map((img, index) => ({
        url: typeof img === 'object' ? img.url : img,
        sort: index,
      })),
      skus: skuRows.value.length > 0
        ? skuRows.value.map((sku) => ({
            ...sku,
            image: typeof sku.image === 'object' ? sku.image?.url || '' : sku.image || '',
          }))
        : undefined,
    };

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
    if (error.errorFields) {
      return;
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

onMounted(() => {
  loadOptions();
});
</script>

<template>
  <a-modal
    :title="isEdit ? '编辑商品' : '新增商品'"
    :open="visible"
    :confirm-loading="loading"
    :width="1000"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <a-form
      ref="formRef"
      :model="formData"
      :rules="rules"
      :label-col="{ style: { width: '100px' } }"
      class="pt-4"
    >
      <a-tabs v-model:activeKey="activeTab">
        <!-- ==================== 基本信息 ==================== -->
        <a-tab-pane key="basic" tab="基本信息">
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item label="商品名称" name="name">
                <a-input
                  v-model:value="formData.name"
                  placeholder="请输入商品名称"
                  allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item label="副标题" name="subtitle">
                <a-input
                  v-model:value="formData.subtitle"
                  placeholder="请输入副标题"
                  allow-clear
                />
              </a-form-item>
            </a-col>
          </a-row>

          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item label="分类" name="category_id">
                <a-tree-select
                  v-model:value="formData.category_id"
                  :tree-data="categoryTreeData"
                  placeholder="请选择分类"
                  tree-default-expand-all
                  allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item label="品牌" name="brand_id">
                <a-select
                  v-model:value="formData.brand_id"
                  placeholder="请选择品牌"
                  allow-clear
                >
                  <a-select-option
                    v-for="brand in brandOptions"
                    :key="brand.id"
                    :value="brand.id"
                  >
                    {{ brand.name }}
                  </a-select-option>
                </a-select>
              </a-form-item>
            </a-col>
          </a-row>

          <a-row :gutter="16">
            <a-col :span="8">
              <a-form-item label="单位" name="unit">
                <a-input
                  v-model:value="formData.unit"
                  placeholder="如：件、kg"
                  allow-clear
                />
              </a-form-item>
            </a-col>
            <a-col :span="8">
              <a-form-item label="排序" name="sort">
                <a-input-number
                  v-model:value="formData.sort"
                  :min="0"
                  :max="9999"
                  placeholder="数字越小越靠前"
                  class="w-full"
                />
              </a-form-item>
            </a-col>
            <a-col :span="8">
              <a-form-item label="状态" name="status">
                <a-radio-group v-model:value="formData.status">
                  <a-radio :value="1">启用</a-radio>
                  <a-radio :value="0">禁用</a-radio>
                </a-radio-group>
              </a-form-item>
            </a-col>
          </a-row>

          <a-row :gutter="16">
            <a-col :span="6">
              <a-form-item label="上架" name="is_on_sale">
                <a-switch
                  v-model:checked="formData.is_on_sale"
                  :checked-value="1"
                  :un-checked-value="0"
                />
              </a-form-item>
            </a-col>
            <a-col :span="6">
              <a-form-item label="推荐" name="is_recommend">
                <a-switch
                  v-model:checked="formData.is_recommend"
                  :checked-value="1"
                  :un-checked-value="0"
                />
              </a-form-item>
            </a-col>
            <a-col :span="6">
              <a-form-item label="新品" name="is_new">
                <a-switch
                  v-model:checked="formData.is_new"
                  :checked-value="1"
                  :un-checked-value="0"
                />
              </a-form-item>
            </a-col>
            <a-col :span="6">
              <a-form-item label="热卖" name="is_hot">
                <a-switch
                  v-model:checked="formData.is_hot"
                  :checked-value="1"
                  :un-checked-value="0"
                />
              </a-form-item>
            </a-col>
          </a-row>

          <!-- 商品描述整合到基本信息 -->
          <a-form-item label="商品描述" name="description" :label-col="{ style: { width: '100px' } }">
            <a-textarea
              v-model:value="formData.description"
              placeholder="请输入商品详情描述"
              :rows="4"
              allow-clear
            />
          </a-form-item>
        </a-tab-pane>

        <!-- ==================== 规格编辑 ==================== -->
        <a-tab-pane key="spec" tab="规格编辑">
          <!-- 无规格时的默认价格库存 -->
          <div v-if="selectedSpecIds.length === 0" class="mb-4">
            <a-alert
              message="未选择规格，以下为商品的默认价格和库存。选择规格后将按规格设置价格库存。"
              type="info"
              show-icon
              class="mb-4"
            />
            <a-row :gutter="16">
              <a-col :span="8">
                <a-form-item label="价格" name="price">
                  <a-input-number
                    v-model:value="formData.price"
                    :min="0"
                    :precision="2"
                    placeholder="请输入价格"
                    class="w-full"
                  />
                </a-form-item>
              </a-col>
              <a-col :span="8">
                <a-form-item label="市场价" name="market_price">
                  <a-input-number
                    v-model:value="formData.market_price"
                    :min="0"
                    :precision="2"
                    placeholder="请输入市场价"
                    class="w-full"
                  />
                </a-form-item>
              </a-col>
              <a-col :span="8">
                <a-form-item label="库存" name="stock">
                  <a-input-number
                    v-model:value="formData.stock"
                    :min="0"
                    placeholder="请输入库存"
                    class="w-full"
                  />
                </a-form-item>
              </a-col>
            </a-row>
          </div>

          <!-- 规格选择区域 -->
          <a-form-item label="选择规格">
            <div class="flex items-start gap-2">
              <a-checkbox-group
                v-model:value="selectedSpecIds"
                @change="handleSpecChange"
                class="flex-1"
              >
                <a-row :gutter="[8, 8]">
                  <a-col
                    v-for="spec in specOptions"
                    :key="spec.id"
                  >
                    <a-checkbox :value="spec.id">
                      {{ spec.name }}
                      <span class="text-gray-400 text-xs">
                        （{{
                          (spec.spec_values || [])
                            .map((v) => v.value)
                            .join('、')
                        }}）
                      </span>
                    </a-checkbox>
                  </a-col>
                </a-row>
              </a-checkbox-group>
              <a-button type="dashed" size="small" @click="openNewSpecModal">
                + 新增规格
              </a-button>
            </div>
          </a-form-item>

          <!-- SKU 组合表格（含价格、库存、图片） -->
          <div v-if="skuRows.length > 0">
            <a-divider orientation="left" style="font-size: 14px;">
              SKU 组合列表（{{ skuRows.length }} 个）
            </a-divider>
            <a-table
              :columns="[
                { title: '规格组合', dataIndex: 'spec_values', width: 140, fixed: 'left' },
                { title: '价格', dataIndex: 'price', width: 120 },
                { title: '市场价', dataIndex: 'market_price', width: 120 },
                { title: '库存', dataIndex: 'stock', width: 100 },
                { title: 'SKU编码', dataIndex: 'sku_code', width: 140 },
                { title: '规格图片', dataIndex: 'image', width: 160 },
              ]"
              :data-source="skuRows"
              :pagination="false"
              :scroll="{ x: 780 }"
              size="small"
              row-key="spec_values"
              bordered
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.dataIndex === 'price'">
                  <a-input-number
                    v-model:value="record.price"
                    :min="0"
                    :precision="2"
                    size="small"
                    style="width: 100%"
                  />
                </template>
                <template v-else-if="column.dataIndex === 'market_price'">
                  <a-input-number
                    v-model:value="record.market_price"
                    :min="0"
                    :precision="2"
                    size="small"
                    style="width: 100%"
                  />
                </template>
                <template v-else-if="column.dataIndex === 'stock'">
                  <a-input-number
                    v-model:value="record.stock"
                    :min="0"
                    size="small"
                    style="width: 100%"
                  />
                </template>
                <template v-else-if="column.dataIndex === 'sku_code'">
                  <a-input
                    v-model:value="record.sku_code"
                    size="small"
                    placeholder="SKU编码"
                    allow-clear
                  />
                </template>
                <template v-else-if="column.dataIndex === 'image'">
                  <Upload
                    v-model:value="record.image"
                    type="image"
                    module="goods"
                    style="width: 100%"
                  />
                </template>
              </template>
            </a-table>
          </div>
          <div v-else-if="selectedSpecIds.length > 0" class="text-gray-400 py-4 text-center">
            所选规格暂无规格值，请先在规格管理中添加规格值
          </div>
        </a-tab-pane>

        <!-- ==================== 其它 ==================== -->
        <a-tab-pane key="other" tab="其它">
          <a-form-item label="主图" name="main_image">
            <Upload
              v-model:value="formData.main_image"
              type="image"
              module="goods"
            />
          </a-form-item>

          <a-form-item label="商品图片">
            <Upload
              v-model:value="formData.images"
              type="images"
              module="goods"
              :max-count="10"
            />
          </a-form-item>

          <a-form-item label="商品标签" name="tag_ids">
            <a-select
              v-model:value="formData.tag_ids"
              mode="multiple"
              placeholder="请选择标签"
              allow-clear
            >
              <a-select-option
                v-for="tag in tagOptions"
                :key="tag.id"
                :value="tag.id"
              >
                <a-tag v-if="tag.color" :color="tag.color">{{ tag.name }}</a-tag>
                <span v-else>{{ tag.name }}</span>
              </a-select-option>
            </a-select>
          </a-form-item>
        </a-tab-pane>
      </a-tabs>
    </a-form>

    <!-- ==================== 新增规格弹窗 ==================== -->
    <a-modal
      v-model:open="newSpecModalVisible"
      title="新增规格"
      :confirm-loading="newSpecCreating"
      :width="460"
      @ok="handleCreateSpec"
    >
      <a-form :label-col="{ style: { width: '80px' } }" class="pt-4">
        <a-form-item label="规格名称" required>
          <a-input
            v-model:value="newSpecForm.name"
            placeholder="如：颜色、尺码、版本"
            allow-clear
          />
        </a-form-item>
        <a-form-item label="规格值" required>
          <a-textarea
            v-model:value="newSpecForm.values"
            placeholder="多个规格值用逗号分隔，如：红色,蓝色,黑色"
            :rows="3"
            allow-clear
          />
        </a-form-item>
      </a-form>
    </a-modal>
  </a-modal>
</template>
