<script lang="ts" setup>
import type { GoodsApi } from '#/api/goods';
import type { PointsGoodsApi } from '#/api/points';

import { computed, reactive, ref, watch } from 'vue';

import { message } from 'ant-design-vue';

import { getGoodsInfoApi, getGoodsListApi } from '#/api/goods';
import { createPointsGoodsApi, updatePointsGoodsApi } from '#/api/points';

interface Props {
  editData?: null | PointsGoodsApi.GoodsItem;
  visible: boolean;
}

interface Emits {
  (e: 'success'): void;
  (e: 'update:visible', value: boolean): void;
}

const props = withDefaults(defineProps<Props>(), {
  editData: null,
  visible: false,
});

const emit = defineEmits<Emits>();

const formRef = ref();
const loading = ref(false);
const goodsLoading = ref(false);
const isEdit = computed(() => !!props.editData);

const goodsOptions = ref<GoodsApi.GoodsItem[]>([]);
const skuOptions = ref<GoodsApi.SkuItem[]>([]);

type FormState = Omit<PointsGoodsApi.SaveParams, 'goods_id' | 'sku_id'> & {
  goods_id?: number;
  sku_id?: number;
};

const formData = reactive<FormState>({
  goods_id: undefined,
  sku_id: undefined,
  points_price: 0,
  exchange_stock: 0,
  limit_per_user: 0,
  sort: 0,
  status: 1,
  remark: '',
});

const rules = {
  goods_id: [{ required: true, message: '请选择商品', trigger: 'change' }],
  points_price: [
    { required: true, message: '请输入兑换积分', trigger: 'blur' },
  ],
  sku_id: [{ required: true, message: '请选择规格', trigger: 'change' }],
};

const resetForm = () => {
  formRef.value?.resetFields();
  Object.assign(formData, {
    goods_id: undefined,
    sku_id: undefined,
    points_price: 0,
    exchange_stock: 0,
    limit_per_user: 0,
    sort: 0,
    status: 1,
    remark: '',
  });
  goodsOptions.value = [];
  skuOptions.value = [];
};

const selectedGoods = computed(() =>
  goodsOptions.value.find((item) => item.id === formData.goods_id),
);

const selectedSku = computed(() =>
  skuOptions.value.find((item) => item.id === formData.sku_id),
);

const displayImageUrl = (raw?: GoodsApi.MediaValue, fullUrl?: string) => {
  if (fullUrl) return fullUrl;
  if (typeof raw !== 'string') return '';
  return /^\d+$/.test(raw) ? '' : raw;
};

const selectedImageUrl = computed(() => {
  const sku = selectedSku.value;
  const goods = selectedGoods.value;
  return (
    displayImageUrl(sku?.image, sku?.image_full_url) ||
    displayImageUrl(goods?.main_image, goods?.main_image_full_url)
  );
});

const loadGoodsOptions = async (keyword = '') => {
  goodsLoading.value = true;
  try {
    const result = await getGoodsListApi({
      keyword,
      limit: 20,
      page: 1,
      view: 'on_sale',
    });
    goodsOptions.value = result.list || [];
  } catch (error) {
    console.error('加载商品失败:', error);
    message.error('加载商品失败');
  } finally {
    goodsLoading.value = false;
  }
};

const loadGoodsDetail = async (goodsId?: number, keepSkuId?: number) => {
  if (!goodsId) {
    skuOptions.value = [];
    formData.sku_id = undefined;
    return;
  }

  const detail = await getGoodsInfoApi(goodsId);
  const optionIndex = goodsOptions.value.findIndex((item) => item.id === goodsId);
  if (optionIndex >= 0) {
    goodsOptions.value[optionIndex] = {
      ...goodsOptions.value[optionIndex],
      ...detail,
    };
  } else {
    goodsOptions.value.unshift(detail);
  }
  skuOptions.value = detail.skus || [];
  if (!skuOptions.value.some((sku) => sku.id === keepSkuId)) {
    formData.sku_id = skuOptions.value[0]?.id;
    return;
  }
  formData.sku_id = keepSkuId;
};

const handleGoodsChange = async (value?: number) => {
  formData.goods_id = value;
  formData.sku_id = undefined;
  try {
    await loadGoodsDetail(value);
  } catch (error) {
    console.error('加载商品规格失败:', error);
    message.error('加载商品规格失败');
  }
};

watch(
  () => props.visible,
  async (visible) => {
    if (!visible) return;
    resetForm();
    await loadGoodsOptions();
    if (!props.editData) return;

    Object.assign(formData, {
      goods_id: props.editData.goods_id,
      sku_id: props.editData.sku_id,
      points_price: props.editData.points_price,
      exchange_stock: props.editData.exchange_stock,
      limit_per_user: props.editData.limit_per_user,
      sort: props.editData.sort,
      status: props.editData.status,
      remark: props.editData.remark || '',
    });

    if (
      props.editData.goods_id &&
      !goodsOptions.value.some((item) => item.id === props.editData!.goods_id)
    ) {
      goodsOptions.value.unshift({
        id: props.editData.goods_id,
        name: props.editData.goods_name || `商品 ${props.editData.goods_id}`,
      } as GoodsApi.GoodsItem);
    }
    await loadGoodsDetail(props.editData.goods_id, props.editData.sku_id);
  },
);

const handleSubmit = async () => {
  try {
    await formRef.value?.validate();
    loading.value = true;
    const payload: PointsGoodsApi.SaveParams = {
      goods_id: Number(formData.goods_id || 0),
      sku_id: Number(formData.sku_id || 0),
      points_price: Number(formData.points_price || 0),
      exchange_stock: Number(formData.exchange_stock || 0),
      limit_per_user: Number(formData.limit_per_user || 0),
      sort: Number(formData.sort || 0),
      status: Number(formData.status ?? 1),
      remark: formData.remark || '',
    };

    if (isEdit.value) {
      await updatePointsGoodsApi(props.editData!.id, payload);
      message.success('更新成功');
    } else {
      await createPointsGoodsApi(payload);
      message.success('创建成功');
    }

    emit('success');
    emit('update:visible', false);
  } catch (error: any) {
    if (!error?.errorFields) {
      message.error(error?.message || '操作失败');
    }
  } finally {
    loading.value = false;
  }
};

const handleCancel = () => {
  emit('update:visible', false);
};
</script>

<template>
  <a-modal
    :confirm-loading="loading"
    :open="visible"
    :title="isEdit ? '编辑积分商品' : '新增积分商品'"
    width="720px"
    @cancel="handleCancel"
    @ok="handleSubmit"
  >
    <a-form
      ref="formRef"
      class="pt-4"
      :label-col="{ style: { width: '100px' } }"
      :model="formData"
      :rules="rules"
    >
      <a-form-item
        extra="先选择普通商品，再选择具体 SKU 作为积分商城兑换对象。"
        label="兑换商品"
        name="goods_id"
      >
        <a-select
          v-model:value="formData.goods_id"
          :filter-option="false"
          :loading="goodsLoading"
          allow-clear
          show-search
          placeholder="搜索并选择已上架商品"
          @change="handleGoodsChange"
          @search="(value: string) => loadGoodsOptions(value)"
        >
          <a-select-option
            v-for="item in goodsOptions"
            :key="item.id"
            :value="item.id"
          >
            {{ item.name }}（ID: {{ item.id }}）
          </a-select-option>
        </a-select>
      </a-form-item>

      <a-form-item
        extra="兑换会扣减该 SKU 库存，兑换库存不能超过实际可售能力。"
        label="兑换规格"
        name="sku_id"
      >
        <a-select
          v-model:value="formData.sku_id"
          :disabled="!formData.goods_id"
          placeholder="请选择规格"
        >
          <a-select-option
            v-for="sku in skuOptions"
            :key="sku.id"
            :value="sku.id"
          >
            {{ sku.spec_values || '默认规格' }} / 售价 {{ sku.price }} / 库存
            {{ sku.stock }}
          </a-select-option>
        </a-select>
      </a-form-item>

      <a-form-item v-if="selectedGoods" label="已选商品">
        <div class="flex items-center gap-3 rounded-md border p-3">
          <img
            v-if="selectedImageUrl"
            :src="selectedImageUrl"
            class="h-14 w-14 rounded object-cover"
          />
          <div
            v-else
            class="flex h-14 w-14 items-center justify-center rounded bg-muted text-xs text-muted-foreground"
          >
            暂无图
          </div>
          <div class="min-w-0 flex-1">
            <div class="truncate font-medium">
              {{ selectedGoods.name }}
            </div>
            <div class="mt-1 text-xs text-muted-foreground">
              SKU：{{ selectedSku?.spec_values || '默认规格' }} / 售价
              {{ selectedSku?.price || selectedGoods.price || '-' }} / 库存
              {{ selectedSku?.stock ?? selectedGoods.stock ?? 0 }}
            </div>
          </div>
        </div>
      </a-form-item>

      <a-form-item label="兑换积分" name="points_price">
        <a-input-number
          v-model:value="formData.points_price"
          class="w-full"
          :min="1"
          :precision="0"
          placeholder="单件兑换所需积分"
        />
      </a-form-item>

      <a-form-item
        extra="积分商城最多可兑换数量，实际可兑还会受 SKU 库存限制。"
        label="兑换库存"
        name="exchange_stock"
      >
        <a-input-number
          v-model:value="formData.exchange_stock"
          class="w-full"
          :min="0"
          :precision="0"
          placeholder="可用于积分兑换的库存"
        />
      </a-form-item>

      <a-form-item label="每人限兑" name="limit_per_user">
        <a-input-number
          v-model:value="formData.limit_per_user"
          class="w-full"
          :min="0"
          :precision="0"
          placeholder="0 表示不限制"
        />
      </a-form-item>

      <a-form-item label="排序" name="sort">
        <a-input-number
          v-model:value="formData.sort"
          class="w-full"
          :precision="0"
        />
      </a-form-item>

      <a-form-item label="状态" name="status">
        <a-radio-group v-model:value="formData.status">
          <a-radio :value="1">启用</a-radio>
          <a-radio :value="0">禁用</a-radio>
        </a-radio-group>
      </a-form-item>

      <a-form-item label="备注" name="remark">
        <a-textarea
          v-model:value="formData.remark"
          :maxlength="255"
          :rows="3"
          allow-clear
          show-count
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>
