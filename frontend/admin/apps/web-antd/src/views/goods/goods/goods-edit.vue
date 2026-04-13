<script lang="ts" setup>

import { computed, onMounted, watch } from 'vue';

import { useRoute, useRouter } from 'vue-router';

import RichTextEditor from '#/components/rich-text-editor/index.vue';
import Upload from '#/components/upload/index.vue';

import { type SkuRow, useGoodsEdit } from './composables/useGoodsEdit';

defineOptions({ name: 'GoodsEdit' });

const route = useRoute();
const router = useRouter();

const editId = computed(() => {
  const id = route.query.id;
  return id ? Number(id) : undefined;
});

const {
  formData, rules, formRef, loading, activeTab, isFullscreen, isEdit,
  toggleFullscreen, categoryTreeData, brandOptions, tagOptions,
  specType, attrs, canAddPic, getPicPreviewUrl, getSkuPreviewImage,
  handleAddSpec, handleRemoveSpec, addSpecValue, removeSpecValue, toggleAddPic, handleSpecValueImageChange,
  specListRef, valueListRefs,
  skuRows, batchData, batchFilters, matchedSkuRows, tableData, skuColumns,
  generateSkuCombinations, applyBatch, resetBatchEditor,
  specLibVisible, specImportTab, specLibLoading, specLibList, selectedSpecIds,
  specTemplateList, selectedTemplateIds, openSpecLib, confirmSelectSpecs,
  saveTemplateVisible, saveTemplateList, saveTemplateLoading, saveTemplateName,
  openSaveTemplate, handleSaveTemplate,
  loadOptions, resetForm, loadEditData, handleSubmit, handleSpecTypeChange,
} = useGoodsEdit(editId);

const handleCancel = () => router.back();
const onSubmit = () => handleSubmit(() => router.back());
const batchColumns = computed(() =>
  (skuColumns.value as any[]).map((column) => ({
    ...column,
    customCell: undefined,
  })),
);
const batchTableData = computed(() => [
  {
    spec_values: '__batch__',
    detail: {},
    price: undefined,
    market_price: undefined,
    stock: undefined,
    sku_code: '',
    image: undefined,
    is_show: 1,
  } as SkuRow,
]);

watch(editId, async (id) => {
  resetForm();
  await loadOptions();
  if (id) await loadEditData(id);
}, { immediate: true });

onMounted(() => {});
</script>

<template>
  <div class="goods-edit-page" :class="{ fullscreen: isFullscreen }">
    <!-- 页头 -->
    <div class="page-header">
      <div class="page-header-left">
        <a-button type="text" class="back-btn" @click="handleCancel">← 返回</a-button>
        <span class="page-title">{{ isEdit ? '编辑商品' : '新增商品' }}</span>
      </div>
      <div class="page-header-right">
        <a-tooltip :title="isFullscreen ? '退出全屏' : '全屏编辑'">
          <a-button type="text" class="fullscreen-btn" @click="toggleFullscreen">
            <template #icon>
              <svg v-if="!isFullscreen" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
              <svg v-else width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="10" y1="14" x2="3" y2="21"/><line x1="21" y1="3" x2="14" y2="10"/></svg>
            </template>
          </a-button>
        </a-tooltip>
        <a-button @click="handleCancel">取消</a-button>
        <a-button type="primary" :loading="loading" @click="onSubmit">{{ isEdit ? '保存修改' : '立即创建' }}</a-button>
      </div>
    </div>

    <!-- 内容区域 -->
    <div class="page-body">
      <a-spin :spinning="loading">
        <div class="page-content-min">
          <a-form ref="formRef" :model="formData" :rules="rules" :label-col="{ style: { width: '88px' } }">
            <a-tabs v-model:activeKey="activeTab" class="edit-tabs">
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
                  <a-tree-select v-model:value="formData.category_id" :tree-data="categoryTreeData" placeholder="请选择商品分类" tree-default-expand-all allow-clear style="width:300px" />
                </a-form-item>
                <a-form-item label="品牌">
                  <a-select v-model:value="formData.brand_id" placeholder="请选择品牌" allow-clear style="width:240px">
                    <a-select-option v-for="b in brandOptions" :key="b.id" :value="b.id">{{ b.name }}</a-select-option>
                  </a-select>
                </a-form-item>
                <a-form-item label="单位">
                  <a-input v-model:value="formData.unit" placeholder="件 / kg / 个" style="width:140px" allow-clear />
                </a-form-item>
                <a-form-item label="商品主图">
                  <div class="form-tip">建议尺寸 800×800，正方形</div>
                  <Upload v-model:value="formData.main_image" type="image" module="goods" />
                </a-form-item>
                <a-form-item label="主视频">
                  <div class="form-tip">可选，建议上传 9-30 秒商品展示短视频（MP4）</div>
                  <Upload v-model:value="formData.main_video" type="video" module="goods" />
                </a-form-item>
                <a-form-item label="轮播图片">
                  <div class="form-tip">最多10张，首图用于列表展示</div>
                  <Upload v-model:value="formData.images" type="images" module="goods" :max-count="10" />
                </a-form-item>
                <a-form-item label="商品标签">
                  <div v-if="tagOptions.length === 0" class="form-tip">暂无标签，请先在「商品标签」模块创建</div>
                  <div v-else class="tag-list">
                    <span v-for="tag in tagOptions" :key="tag.id" class="tag-chip" :class="{ active: formData.tag_ids.includes(tag.id) }"
                      :style="formData.tag_ids.includes(tag.id) && tag.color ? { background: tag.color, borderColor: tag.color, color: '#fff' } : {}"
                      @click="formData.tag_ids.includes(tag.id) ? (formData.tag_ids = formData.tag_ids.filter((id) => id !== tag.id)) : formData.tag_ids.push(tag.id)">
                      <span v-if="tag.color && !formData.tag_ids.includes(tag.id)" class="tag-dot" :style="{ background: tag.color }" />{{ tag.name }}
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
                  <a-input-number v-model:value="formData.sort" :min="0" :max="9999" style="width:120px" :controls="false" />
                  <span class="form-tip ml8">数字越小越靠前</span>
                </a-form-item>
                <a-form-item label="标签设置">
                  <div class="flag-row">
                    <div class="flag-cell"><span class="flag-name">立即上架</span><a-switch v-model:checked="formData.is_on_sale" :checked-value="1" :un-checked-value="0" checked-children="上架" un-checked-children="下架" /></div>
                    <div class="flag-cell"><span class="flag-name">精品推荐</span><a-switch v-model:checked="formData.is_recommend" :checked-value="1" :un-checked-value="0" checked-children="是" un-checked-children="否" /></div>
                    <div class="flag-cell"><span class="flag-name">新品标签</span><a-switch v-model:checked="formData.is_new" :checked-value="1" :un-checked-value="0" checked-children="是" un-checked-children="否" /></div>
                    <div class="flag-cell"><span class="flag-name">热卖标签</span><a-switch v-model:checked="formData.is_hot" :checked-value="1" :un-checked-value="0" checked-children="是" un-checked-children="否" /></div>
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
                <template v-if="specType === 'single'">
                  <a-form-item label="售价"><a-input-number v-model:value="formData.price" :min="0" :precision="2" :controls="false" style="width:160px"><template #prefix>¥</template></a-input-number></a-form-item>
                  <a-form-item label="市场价"><a-input-number v-model:value="formData.market_price" :min="0" :precision="2" :controls="false" style="width:160px"><template #prefix>¥</template></a-input-number></a-form-item>
                  <a-form-item label="库存"><a-input-number v-model:value="formData.stock" :min="0" :controls="false" style="width:160px"><template #suffix>件</template></a-input-number></a-form-item>
                </template>
                <template v-else>
                  <a-form-item label="商品规格" :wrapper-col="{ span: 22 }">
                    <div class="spec-wrapper spec-designer">
                      <div class="spec-designer-head">
                        <div>
                          <div class="spec-designer-title">规格设计器</div>
                          <div class="form-tip">规格组与规格值都支持拖拽排序，带图规格值可同步替换命中 SKU 图片</div>
                        </div>
                      </div>
                      <div ref="specListRef" class="spec-list">
                        <div v-for="(attr, attrIdx) in attrs" :key="attr.id" class="spec-item">
                          <div class="spec-name-row">
                            <div class="spec-name-main">
                              <span class="spec-drag-zone" title="拖拽排序">
                                <span class="spec-drag-handle"><svg width="12" height="12" viewBox="0 0 12 12" fill="#bbb"><circle cx="3" cy="3" r="1.2"/><circle cx="9" cy="3" r="1.2"/><circle cx="3" cy="7" r="1.2"/><circle cx="9" cy="7" r="1.2"/><circle cx="3" cy="11" r="1.2"/><circle cx="9" cy="11" r="1.2"/></svg></span>
                                <span class="spec-drag-label">拖拽排序</span>
                              </span>
                              <a-input v-model:value="attr.value" placeholder="规格名称" :maxlength="30" show-count class="spec-name-input" @change="generateSkuCombinations" />
                            </div>
                            <div class="spec-name-actions">
                              <a-checkbox :checked="attr.add_pic === 1" :disabled="attr.add_pic === 0 && !canAddPic" @change="(e: any) => { attr.add_pic = e.target.checked ? 1 : 0; toggleAddPic(e.target.checked, attrIdx); }">规格图</a-checkbox>
                              <a-tooltip title="只能同时为一个规格开启规格图" placement="right"><span class="icon-info">?</span></a-tooltip>
                              <a-button type="text" danger size="small" @click="handleRemoveSpec(attrIdx)">删除</a-button>
                            </div>
                          </div>
                          <div class="spec-item-meta">
                            <span>{{ attr.detail.length }} 个规格值</span>
                            <span v-if="attr.add_pic === 1">已开启规格图，图片可同步替换命中 SKU</span>
                            <span v-else>纯文字规格，规格值支持拖动调整顺序</span>
                          </div>
                          <div :ref="(el) => { valueListRefs[attrIdx] = el as HTMLElement }" class="spec-values-row">
                            <template v-if="attr.add_pic">
                              <div v-for="(det, detIdx) in attr.detail" :key="det.id" class="spec-val-item has-pic">
                                <div class="spec-val-media-top">
                                  <span class="val-drag-handle"><svg width="10" height="10" viewBox="0 0 12 12" fill="#ccc"><circle cx="3" cy="3" r="1.2"/><circle cx="9" cy="3" r="1.2"/><circle cx="3" cy="7" r="1.2"/><circle cx="9" cy="7" r="1.2"/><circle cx="3" cy="11" r="1.2"/><circle cx="9" cy="11" r="1.2"/></svg></span>
                                  <span class="val-del media-del" @click="removeSpecValue(attrIdx, detIdx)">×</span>
                                </div>
                                <div class="val-pic-cell media-card">
                                  <div v-if="det.pic" class="val-pic-thumb media-thumb">
                                    <img :src="getPicPreviewUrl(det.pic)" />
                                    <span class="val-pic-del" @click.stop="handleSpecValueImageChange(attrIdx, detIdx, '')">×</span>
                                  </div>
                                  <div v-else class="val-pic-upload-wrap media-thumb media-upload-wrap">
                                    <Upload type="image" module="goods" :show-upload-list="false"
                                      @update:value="(v) => handleSpecValueImageChange(attrIdx, detIdx, v)" />
                                  </div>
                                </div>
                                <a-input v-model:value="det.value" placeholder="规格值" :maxlength="30" class="val-input media-input" @change="generateSkuCombinations" />
                              </div>
                              <button type="button" class="add-val-card add-val-card-pic" @click="addSpecValue(attrIdx)">
                                <span class="add-val-prefix">+</span>
                                <span>添加带图规格值</span>
                              </button>
                            </template>
                            <template v-else>
                              <div v-for="(det, detIdx) in attr.detail" :key="det.id" class="spec-val-item">
                                <span class="val-drag-handle"><svg width="10" height="10" viewBox="0 0 12 12" fill="#ccc"><circle cx="3" cy="3" r="1.2"/><circle cx="9" cy="3" r="1.2"/><circle cx="3" cy="7" r="1.2"/><circle cx="9" cy="7" r="1.2"/><circle cx="3" cy="11" r="1.2"/><circle cx="9" cy="11" r="1.2"/></svg></span>
                                <a-input v-model:value="det.value" placeholder="规格值" :maxlength="30" class="val-input" @change="generateSkuCombinations" />
                                <span class="val-del" @click="removeSpecValue(attrIdx, detIdx)">×</span>
                              </div>
                              <button type="button" class="add-val-card" @click="addSpecValue(attrIdx)">
                                <span class="add-val-prefix">+</span>
                                <span>添加规格值</span>
                              </button>
                            </template>
                          </div>
                        </div>
                      </div>
                      <div class="spec-actions light-toolbar">
                        <button v-if="attrs.length < 4" type="button" class="light-tool-btn primary" @click="handleAddSpec">添加新规格</button>
                        <button type="button" class="light-tool-btn" @click="openSpecLib">从规格库导入</button>
                        <button type="button" class="light-tool-btn" :disabled="attrs.length === 0" @click="openSaveTemplate">另存为模板</button>
                      </div>
                    </div>
                  </a-form-item>
                  <a-form-item v-if="tableData.length > 0" label="商品属性" :wrapper-col="{ span: 22 }">
                    <div class="sku-panel">
                      <div class="sku-panel-head">
                        <div>
                          <div class="sku-panel-title">SKU 组合与批量设置</div>
                          <div class="form-tip">批量编辑可先按规格筛选，再对命中的 SKU 统一处理</div>
                        </div>
                        <div class="sku-panel-summary">已生成 {{ skuRows.length }} 个 SKU</div>
                      </div>
                      <div class="sku-batch-toolbar">
                        <div class="sku-batch-actions">
                          <div class="sku-hit-stat">
                            <span>命中 SKU</span>
                            <strong>{{ matchedSkuRows.length }}</strong>
                          </div>
                          <button type="button" class="light-tool-btn primary" @click="applyBatch">批量修改 / 快速清空</button>
                          <button type="button" class="light-tool-btn" @click="resetBatchEditor">重置</button>
                        </div>
                        <a-table
                          :columns="(batchColumns as any[])"
                          :data-source="batchTableData"
                          :pagination="false"
                          :scroll="{ x: 860 }"
                          size="small"
                          bordered
                          row-key="spec_values"
                          class="sku-table compact-table sku-batch-table"
                        >
                          <template #bodyCell="{ column }">
                            <template v-if="(column as any)._isSpecCol">
                              <a-select
                                v-model:value="batchFilters[(column as any).title]"
                                :placeholder="`${(column as any).title}：全部`"
                                size="small"
                                allow-clear
                                class="batch-cell-control"
                              >
                                <a-select-option
                                  v-for="attr in attrs.find((item) => (item.value || `规格${attrs.indexOf(item) + 1}`) === (column as any).title)?.detail.filter((item) => item.value) || []"
                                  :key="attr.id"
                                  :value="attr.value"
                                >
                                  {{ attr.value }}
                                </a-select-option>
                              </a-select>
                            </template>
                            <template v-else-if="column.dataIndex === 'image'">
                              <div class="batch-image-cell">
                                <div class="batch-image-editor" title="批量设置图片">
                                  <Upload v-model:value="(batchData as any)['__image__']" type="image" module="goods" :show-upload-list="false" />
                                </div>
                              </div>
                            </template>
                            <template v-else-if="column.dataIndex === 'price'">
                              <a-input-number v-model:value="(batchData as any)['__price__']" placeholder="批量售价" :min="0" :precision="2" size="small" :controls="false" class="batch-cell-control" />
                            </template>
                            <template v-else-if="column.dataIndex === 'market_price'">
                              <a-input-number v-model:value="(batchData as any)['__market_price__']" placeholder="批量市价" :min="0" :precision="2" size="small" :controls="false" class="batch-cell-control" />
                            </template>
                            <template v-else-if="column.dataIndex === 'stock'">
                              <a-input-number v-model:value="(batchData as any)['__stock__']" placeholder="批量库存" :min="0" size="small" :controls="false" class="batch-cell-control" />
                            </template>
                            <template v-else-if="column.dataIndex === 'sku_code'">
                              <a-input v-model:value="batchData['__sku_code__']" placeholder="批量SKU编码" size="small" class="batch-cell-control" />
                            </template>
                            <template v-else-if="column.dataIndex === '_action'">
                              <a-select v-model:value="(batchData as any)['__is_show__']" placeholder="显示状态" size="small" allow-clear class="batch-cell-control">
                                <a-select-option :value="1">批量显示</a-select-option>
                                <a-select-option :value="0">批量隐藏</a-select-option>
                              </a-select>
                            </template>
                          </template>
                        </a-table>
                      </div>
                    </div>
                    <a-table :columns="(skuColumns as any[])" :data-source="tableData" :pagination="false" :scroll="{ x: 860, y: 400 }" size="small" bordered row-key="spec_values" class="sku-table compact-table">
                      <template #bodyCell="{ column, record }">
                        <template v-if="(column as any)._isSpecCol"><span class="sku-spec-val">{{ (record as SkuRow).detail[(column as any).title] }}</span></template>
                        <template v-else-if="column.dataIndex === 'image'">
                          <div class="sku-img-cell">
                            <div v-if="getSkuPreviewImage(record as SkuRow)" class="sku-img-thumb">
                              <img :src="getPicPreviewUrl(getSkuPreviewImage(record as SkuRow)!)" />
                              <span v-if="(record as SkuRow).image" class="sku-img-del" @click.stop="(record as SkuRow).image = undefined">×</span>
                            </div>
                            <div v-else class="sku-upload-wrap">
                              <Upload type="image" module="goods" :show-upload-list="false"
                                @update:value="(v) => { if (v) (record as SkuRow).image = v; }" />
                            </div>
                          </div>
                        </template>
                        <template v-else-if="column.dataIndex === 'price'"><a-input-number v-model:value="(record as SkuRow).price" :min="0" :precision="2" size="small" :controls="false" style="width:100%" /></template>
                        <template v-else-if="column.dataIndex === 'market_price'"><a-input-number v-model:value="(record as SkuRow).market_price" :min="0" :precision="2" size="small" :controls="false" style="width:100%" /></template>
                        <template v-else-if="column.dataIndex === 'stock'"><a-input-number v-model:value="(record as SkuRow).stock" :min="0" size="small" :controls="false" style="width:100%" /></template>
                        <template v-else-if="column.dataIndex === 'sku_code'"><a-input v-model:value="(record as SkuRow).sku_code" size="small" placeholder="选填" allow-clear /></template>
                        <template v-else-if="column.dataIndex === '_action'">
                          <a-switch v-model:checked="(record as any).is_show" :checked-value="1" :un-checked-value="0" checked-children="显" un-checked-children="隐" size="small" />
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
                    :height="460"
                    module="goods"
                    :model-value="formData.description"
                    placeholder="请输入商品描述..."
                    @update:model-value="(val: string) => { formData.description = val; }"
                  />
                </a-form-item>
              </div>
            </a-tab-pane>
            </a-tabs>
          </a-form>
        </div>
      </a-spin>
    </div>

    <!-- 从规格库导入弹窗 -->
    <a-modal v-model:open="specLibVisible" title="导入规格" :width="540" ok-text="确认导入" cancel-text="取消" @ok="confirmSelectSpecs">
      <a-spin :spinning="specLibLoading">
        <a-tabs v-model:activeKey="specImportTab">
          <a-tab-pane key="spec" tab="规格库">
            <div v-if="specLibList.length === 0 && !specLibLoading" class="empty-tip">规格库暂无数据，请先在「商品规格」模块创建</div>
            <a-checkbox-group v-else v-model:value="selectedSpecIds" class="spec-lib-list">
              <div v-for="spec in specLibList" :key="spec.id" class="spec-lib-item">
                <a-checkbox :value="spec.id"><span class="spec-lib-name">{{ spec.name }}</span></a-checkbox>
                <div class="spec-lib-values">
                  <span v-for="val in (spec.spec_values || spec.specValues || [])" :key="val.id" class="spec-lib-val-tag">{{ val.value }}</span>
                </div>
              </div>
            </a-checkbox-group>
          </a-tab-pane>
          <a-tab-pane key="template" tab="规格模板">
            <div v-if="specTemplateList.length === 0 && !specLibLoading" class="empty-tip">暂无模板，可在「规格模板」模块创建</div>
            <a-checkbox-group v-else v-model:value="selectedTemplateIds" class="spec-lib-list">
              <div v-for="tpl in specTemplateList" :key="tpl.id" class="spec-lib-item">
                <a-checkbox :value="tpl.id"><span class="spec-lib-name">{{ tpl.name }}</span></a-checkbox>
                <div class="spec-lib-values">
                  <span v-for="item in (tpl.detail || [])" :key="item.spec_name" class="spec-lib-val-tag">{{ item.spec_name }}（{{ item.values.length }}个值）</span>
                </div>
              </div>
            </a-checkbox-group>
          </a-tab-pane>
        </a-tabs>
      </a-spin>
    </a-modal>

    <!-- 另存为模板弹窗 -->
    <a-modal v-model:open="saveTemplateVisible" title="另存为规格模板" :width="480" ok-text="保存模板" cancel-text="取消" :confirm-loading="saveTemplateLoading" @ok="handleSaveTemplate">
      <a-form-item label="模板名称" style="margin-bottom: 16px">
        <a-input v-model:value="saveTemplateName" placeholder="请输入模板名称（必填）" :maxlength="50" show-count allow-clear />
      </a-form-item>
      <div class="form-tip mb8">勾选要包含的规格（至少一个有规格值）</div>
      <div class="save-template-list">
        <div v-for="(item, idx) in saveTemplateList" :key="idx" class="save-template-item">
          <a-checkbox v-model:checked="item.selected"><span class="spec-lib-name">{{ item.name }}</span></a-checkbox>
          <div class="spec-lib-values">
            <span v-for="v in item.values" :key="v" class="spec-lib-val-tag">{{ v }}</span>
            <span v-if="item.values.length === 0" class="form-tip">（无规格值，不可保存）</span>
          </div>
        </div>
      </div>
    </a-modal>
  </div>
</template>

<style scoped>
/* ===== 页面容器 ===== */
.goods-edit-page { display: flex; flex-direction: column; height: 100%; background: hsl(var(--background-deep)); }
.goods-edit-page.fullscreen { position: fixed; inset: 0; z-index: 1000; background: hsl(var(--background-deep)); }

/* ===== 页头 ===== */
.page-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 20px; height: 52px; background: hsl(var(--card));
  border-bottom: 1px solid hsl(var(--border)); flex-shrink: 0; box-shadow: 0 1px 4px rgb(0 0 0 / 6%);
}
.page-header-left { display: flex; align-items: center; gap: 12px; }
.page-header-right { display: flex; align-items: center; gap: 8px; }
.back-btn { color: hsl(var(--muted-foreground)); font-size: 13px; }
.back-btn:hover { color: #1677ff; }
.page-title { font-size: 15px; font-weight: 600; color: hsl(var(--foreground)); }
.fullscreen-btn { display: flex; align-items: center; justify-content: center; color: hsl(var(--muted-foreground)); }
.fullscreen-btn:hover { color: #1677ff; }

/* ===== 内容区域 ===== */
.page-body { flex: 1; overflow: auto; padding: 16px; }
.page-content-min { min-width: 1320px; }
.edit-tabs { background: hsl(var(--card)); border-radius: 8px; min-width: 1320px; }
.edit-tabs :deep(.ant-tabs-nav) { padding: 0 20px; margin: 0; background: hsl(var(--popover)); border-radius: 8px 8px 0 0; }

/* ===== tab 内容区域 ===== */
.tab-body { padding: 20px 24px 16px; }

/* ===== 辅助文字 ===== */
.form-tip { font-size: 12px; color: hsl(var(--muted-foreground)); line-height: 1.4; }
.ml8 { margin-left: 8px; }
.mb8 { margin-bottom: 8px; }

/* ===== 标签选择 ===== */
.tag-list { display: flex; flex-wrap: wrap; gap: 8px; }
.tag-chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 20px; font-size: 13px; cursor: pointer; border: 1px solid hsl(var(--border)); color: hsl(var(--muted-foreground)); background: hsl(var(--card)); user-select: none; transition: all 0.15s; }
.tag-chip:hover { border-color: #1677ff; color: #1677ff; }
.tag-chip.active { border-color: #1677ff; background: hsl(var(--primary) / 0.15); color: #1677ff; }
.tag-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

/* ===== 标签设置行 ===== */
.flag-row { display: flex; border: 1px solid hsl(var(--border)); border-radius: 6px; overflow: hidden; max-width: 520px; }
.flag-cell { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 14px 8px; border-right: 1px solid hsl(var(--border)); background: hsl(var(--popover)); }
.flag-cell:last-child { border-right: none; }
.flag-name { font-size: 13px; color: hsl(var(--muted-foreground)); }

/* ===== 规格区域 ===== */
.spec-wrapper { display: flex; flex-direction: column; gap: 0; }
.spec-designer { border: 1px solid hsl(var(--border)); border-radius: 14px; background: hsl(var(--card)); overflow: hidden; }
.spec-designer-head { display: flex; justify-content: space-between; align-items: flex-start; padding: 12px 14px 8px; border-bottom: 1px solid hsl(var(--border)); background: hsl(var(--popover)); }
.spec-designer-title,
.sku-panel-title { font-size: 17px; font-weight: 700; color: hsl(var(--foreground)); margin-bottom: 2px; }
.spec-list { display: flex; flex-direction: column; gap: 8px; padding: 8px; }
.spec-item { border: 1px solid hsl(var(--border)); border-radius: 12px; overflow: hidden; background: hsl(var(--card)); }
.spec-name-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 7px 10px; background: hsl(var(--popover) / 0.9); border-bottom: 1px solid hsl(var(--border)); }
.spec-name-main { display: flex; align-items: center; gap: 8px; min-width: 0; }
.spec-name-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.spec-drag-zone { display: inline-flex; align-items: center; gap: 6px; min-width: 84px; height: 28px; padding: 0 8px; border-radius: 10px; background: hsl(var(--card)); border: 1px dashed hsl(var(--border)); cursor: grab; user-select: none; }
.spec-drag-zone:active { cursor: grabbing; }
.spec-drag-label { font-size: 12px; color: hsl(var(--muted-foreground)); }
.spec-drag-handle, .val-drag-handle { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; cursor: grab; padding: 0; color: #bbb; flex-shrink: 0; }
.spec-drag-handle:active, .val-drag-handle:active { cursor: grabbing; }
.spec-name-input { width: 240px; }
.icon-info { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 50%; border: 1px solid hsl(var(--border)); font-size: 11px; color: hsl(var(--muted-foreground)); cursor: help; flex-shrink: 0; }
.spec-item-meta { display: flex; align-items: center; gap: 10px; padding: 0 12px 6px; font-size: 12px; color: hsl(var(--muted-foreground)); }
.spec-values-row { display: flex; flex-wrap: wrap; align-items: stretch; gap: 8px; padding: 8px 10px 10px; border-top: 1px solid hsl(var(--border) / 0.7); }
.spec-val-item { display: inline-flex; align-items: center; justify-content: center; gap: 4px; background: hsl(var(--popover)); border: 1px solid hsl(var(--border)); border-radius: 12px; padding: 5px 8px; min-height: 46px; }
.spec-val-item.has-pic { width: 122px; padding: 6px; align-items: stretch; flex-direction: column; justify-content: flex-start; min-height: auto; }
.spec-val-media-top { display: flex; align-items: center; justify-content: space-between; line-height: 1; }
.val-input { width: 96px; }
.media-input { width: 100%; }

/* 规格图 */
.val-pic-cell { display: flex; flex-direction: column; gap: 2px; }
.media-card { width: 100%; }
.val-pic-thumb { position: relative; display: inline-block; }
.val-pic-thumb img { width: 46px; height: 46px; object-fit: cover; border-radius: 4px; border: 1px solid hsl(var(--border)); display: block; }
.media-thumb,
.media-thumb :deep(.ant-upload.ant-upload-select) { width: 100% !important; height: 64px !important; min-width: 100% !important; min-height: 64px !important; max-width: 100% !important; max-height: 64px !important; border-radius: 10px !important; }
.media-thumb img { width: 100%; height: 64px; object-fit: cover; border-radius: 10px; }
.val-pic-del { position: absolute; top: -6px; right: -6px; width: 16px; height: 16px; border-radius: 50%; background: rgba(0,0,0,.45); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; line-height: 1; }
.val-pic-del:hover { background: #ff4d4f; }
.val-pic-upload-wrap :deep(.ant-upload),
.val-pic-upload-wrap :deep(.ant-upload-wrapper) { width: 100% !important; height: 64px !important; min-width: 100% !important; min-height: 64px !important; }
.val-pic-upload-wrap :deep(.ant-upload-text) { display: none !important; }
.val-pic-upload-wrap :deep(.ant-upload-list) { display: none !important; }
.media-upload-wrap :deep(.ant-upload) { border-style: dashed !important; border-radius: 10px !important; overflow: hidden !important; }
.val-del { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; font-size: 16px; color: #bfbfbf; cursor: pointer; line-height: 1; transition: color 0.15s; margin-left: 2px; align-self: center; }
.val-del:hover { color: #ff4d4f; }
.media-del { align-self: auto; }
.add-val-card { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-width: 126px; min-height: 46px; border: 1px dashed hsl(var(--primary) / 0.28); border-radius: 12px; background: hsl(var(--primary) / 0.05); color: hsl(var(--foreground)); font-size: 13px; font-weight: 600; cursor: pointer; padding: 0 12px; }
.add-val-card-pic { min-height: 112px; flex-direction: column; gap: 4px; }
.add-val-prefix { font-size: 18px; line-height: 1; }
.add-val-card:hover { border-color: hsl(var(--primary)); color: hsl(var(--primary)); }
.spec-actions { display: flex; gap: 8px; padding: 0 10px 10px; }
.light-toolbar { flex-wrap: wrap; }
.light-tool-btn { height: 32px; padding: 0 13px; border-radius: 10px; border: 1px solid hsl(var(--border)); background: hsl(var(--card)); color: hsl(var(--foreground)); font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 2px rgb(0 0 0 / 3%); }
.light-tool-btn:hover { border-color: hsl(var(--primary)); color: hsl(var(--primary)); }
.light-tool-btn.primary { background: hsl(var(--primary) / 0.08); border-color: hsl(var(--primary) / 0.24); color: hsl(var(--primary)); }
.light-tool-btn:disabled { opacity: 0.55; cursor: not-allowed; }

/* ===== SKU 表格 ===== */
.sku-panel { margin-bottom: 10px; }
.sku-panel-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.sku-panel-summary { font-size: 13px; color: hsl(var(--muted-foreground)); padding-top: 4px; }
.sku-batch-toolbar { display: flex; flex-direction: column; align-items: stretch; gap: 10px; padding: 10px 12px; margin-bottom: 10px; border: 1px solid hsl(var(--border)); border-radius: 12px; background: hsl(var(--primary) / 0.05); overflow-x: auto; }
.sku-batch-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
.batch-cell-control { width: 100%; }
.batch-image-cell { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 36px; }
.batch-image-editor { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border: 1px solid hsl(var(--border)); border-radius: 3px; background: hsl(var(--card)); overflow: hidden; }
.batch-image-editor :deep(.ant-upload-wrapper),
.batch-image-editor :deep(.ant-upload.ant-upload-select),
.batch-image-editor :deep(.ant-upload.ant-upload-select-picture-card) { width: 100% !important; height: 100% !important; min-width: 100% !important; min-height: 100% !important; max-width: 100% !important; max-height: 100% !important; border-radius: 3px !important; margin: 0 !important; }
.batch-image-editor :deep(.ant-upload) { display: flex; align-items: center; justify-content: center; width: 100% !important; height: 100% !important; }
.batch-image-editor :deep(.ant-upload-list) { display: none !important; }
.sku-batch-table { min-width: max-content; }
.sku-batch-table :deep(.ant-table-tbody > tr > td) { background: hsl(var(--card)); }
.sku-hit-stat { display: flex; align-items: center; gap: 8px; padding: 0 10px; height: 32px; border-radius: 10px; background: hsl(var(--card)); color: hsl(var(--muted-foreground)); font-size: 12px; }
.sku-hit-stat strong { font-size: 15px; color: hsl(var(--foreground)); }
.sku-table :deep(.ant-table-cell) { padding: 4px 6px !important; vertical-align: middle; }
.compact-table :deep(.ant-table-thead > tr > th) { padding: 8px 9px !important; font-size: 12px; background: hsl(var(--popover)); }
.compact-table :deep(.ant-table-tbody > tr > td) { padding: 6px 9px !important; }
.sku-spec-val { font-weight: 500; color: hsl(var(--foreground)); }

/* SKU 规格图紧凑单元格 */
.sku-img-cell { display: flex; align-items: center; justify-content: center; }
.sku-img-cell .sku-img-thumb { position: relative; display: inline-block; }
.sku-img-cell .sku-img-thumb img { width: 36px; height: 36px; object-fit: cover; border-radius: 3px; border: 1px solid hsl(var(--border)); display: block; }
.sku-img-cell .sku-img-del { position: absolute; top: -5px; right: -5px; width: 14px; height: 14px; border-radius: 50%; background: rgba(0,0,0,.45); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 10px; cursor: pointer; }
.sku-img-cell .sku-img-del:hover { background: #ff4d4f; }
.sku-img-cell :deep(.ant-upload-list) { display: none !important; }
.sku-img-cell :deep(.ant-upload.ant-upload-select) { width: 36px !important; height: 36px !important; min-width: 36px !important; min-height: 36px !important; max-width: 36px !important; max-height: 36px !important; font-size: 14px !important; border-radius: 3px !important; overflow: hidden !important; }
.sku-img-cell :deep(.ant-upload-text) { display: none !important; }
.sku-img-cell :deep(.ant-upload-wrapper) { display: block; width: 36px; height: 36px; }

@media (max-width: 1280px) {
  .page-header,
  .spec-name-row {
    flex-wrap: wrap;
  }

  .sku-batch-actions {
    justify-content: flex-end;
  }
}

/* ===== 规格库弹窗 ===== */
.spec-lib-list { display: flex; flex-direction: column; gap: 10px; max-height: 380px; overflow-y: auto; width: 100%; }
.spec-lib-item { display: flex; flex-direction: column; gap: 6px; padding: 10px 12px; background: hsl(var(--popover)); border: 1px solid hsl(var(--border)); border-radius: 6px; }
.spec-lib-name { font-weight: 500; color: hsl(var(--foreground)); }
.spec-lib-values { display: flex; flex-wrap: wrap; gap: 4px; padding-left: 24px; }
.spec-lib-val-tag { display: inline-block; padding: 1px 8px; border-radius: 4px; font-size: 12px; color: hsl(var(--muted-foreground)); background: hsl(var(--popover)); border: 1px solid hsl(var(--border)); }
.empty-tip { color: hsl(var(--muted-foreground)); text-align: center; padding: 24px 0; }

/* ===== 另存为模板弹窗 ===== */
.save-template-list { display: flex; flex-direction: column; gap: 8px; max-height: 320px; overflow-y: auto; }
.save-template-item { padding: 10px 12px; background: hsl(var(--popover)); border: 1px solid hsl(var(--border)); border-radius: 6px; display: flex; flex-direction: column; gap: 6px; }

</style>
