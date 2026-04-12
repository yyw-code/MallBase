<script lang="ts" setup>
import type { FileInfo } from '#/components/upload';

import { computed, nextTick, onMounted, watch } from 'vue';

import { useRoute, useRouter } from 'vue-router';

import RichTextEditor from '#/components/rich-text-editor/index.vue';
import Upload from '#/components/upload/index.vue';

import { type Attr, type SkuRow, useGoodsEdit } from './composables/useGoodsEdit';

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
  handleAddSpec, handleRemoveSpec, addSpecValue, removeSpecValue, toggleAddPic,
  specListRef, valueListRefs, initSpecDrag, initValueDrag,
  skuRows, batchData, tableData, skuColumns,
  generateSkuCombinations, applyBatch, clearBatch,
  specLibVisible, specImportTab, specLibLoading, specLibList, selectedSpecIds,
  specTemplateList, selectedTemplateIds, openSpecLib, confirmSelectSpecs,
  saveTemplateVisible, saveTemplateList, saveTemplateLoading, saveTemplateName,
  openSaveTemplate, handleSaveTemplate,
  loadOptions, resetForm, loadEditData, handleSubmit, handleSpecTypeChange,
} = useGoodsEdit(editId);

const handleCancel = () => router.back();
const onSubmit = () => handleSubmit(() => router.back());

watch(editId, async (id) => {
  resetForm();
  await loadOptions();
  if (id) await loadEditData(id);
  await nextTick();
  initSpecDrag();
  initValueDrag();
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
                    <div class="spec-wrapper">
                      <div ref="specListRef" class="spec-list">
                        <div v-for="(attr, attrIdx) in attrs" :key="attrIdx" class="spec-item">
                          <div class="spec-name-row">
                            <span class="spec-drag-handle" title="拖拽排序"><svg width="12" height="12" viewBox="0 0 12 12" fill="#bbb"><circle cx="3" cy="3" r="1.2"/><circle cx="9" cy="3" r="1.2"/><circle cx="3" cy="7" r="1.2"/><circle cx="9" cy="7" r="1.2"/><circle cx="3" cy="11" r="1.2"/><circle cx="9" cy="11" r="1.2"/></svg></span>
                            <a-input v-model:value="attr.value" placeholder="规格名称" :maxlength="30" show-count class="spec-name-input" @change="generateSkuCombinations" />
                            <a-checkbox :checked="attr.add_pic === 1" :disabled="attr.add_pic === 0 && !canAddPic" @change="(e: any) => { attr.add_pic = e.target.checked ? 1 : 0; toggleAddPic(e.target.checked, attrIdx); }">添加规格图</a-checkbox>
                            <a-tooltip title="只能同时为一个规格开启规格图" placement="right"><span class="icon-info">?</span></a-tooltip>
                            <a-button type="text" danger size="small" class="ml8" @click="handleRemoveSpec(attrIdx)">删除</a-button>
                          </div>
                          <div :ref="(el) => { valueListRefs[attrIdx] = el as HTMLElement }" class="spec-values-row">
                            <div v-for="(det, detIdx) in attr.detail" :key="detIdx" class="spec-val-item" :class="{ 'has-pic': attr.add_pic === 1 }">
                              <span class="val-drag-handle"><svg width="10" height="10" viewBox="0 0 12 12" fill="#ccc"><circle cx="3" cy="3" r="1.2"/><circle cx="9" cy="3" r="1.2"/><circle cx="3" cy="7" r="1.2"/><circle cx="9" cy="7" r="1.2"/><circle cx="3" cy="11" r="1.2"/><circle cx="9" cy="11" r="1.2"/></svg></span>
                              <a-input v-model:value="det.value" placeholder="规格值" :maxlength="30" class="val-input" @change="generateSkuCombinations" />
                              <!-- 规格图：CSS show/hide 避免 Upload 卸载竞态 -->
                              <template v-if="attr.add_pic">
                                <div class="val-pic-cell">
                                  <div class="val-pic-thumb" :style="{ display: det.pic ? 'inline-block' : 'none' }">
                                    <img :src="getPicPreviewUrl(det.pic)" width="46" height="46" />
                                    <span class="val-pic-del" @click.stop="attrs[attrIdx]!.detail[detIdx]!.pic = ''">×</span>
                                  </div>
                                  <div class="val-pic-upload-wrap" :style="{ display: det.pic ? 'none' : 'inline-block' }">
                                    <Upload type="image" module="goods" :show-upload-list="false" class="val-pic-upload"
                                      @update:value="(v: FileInfo | undefined) => { if (v) attrs[attrIdx]!.detail[detIdx]!.pic = v; }" />
                                  </div>
                                </div>
                              </template>
                              <span class="val-del" @click="removeSpecValue(attrIdx, detIdx)">×</span>
                            </div>
                            <span class="add-val-btn" @click="addSpecValue(attrIdx)">+ 添加规格值</span>
                          </div>
                        </div>
                      </div>
                      <div class="spec-actions">
                        <a-button v-if="attrs.length < 4" @click="handleAddSpec">添加新规格</a-button>
                        <a-button @click="openSpecLib">从规格库导入</a-button>
                        <a-button type="text" :disabled="attrs.length === 0" @click="openSaveTemplate">另存为模板</a-button>
                      </div>
                    </div>
                  </a-form-item>
                  <a-form-item v-if="tableData.length > 0" label="商品属性" :wrapper-col="{ span: 22 }">
                    <a-table :columns="(skuColumns as any[])" :data-source="tableData" :pagination="false" :scroll="{ x: 860, y: 400 }" size="small" bordered row-key="spec_values" class="sku-table">
                      <template #bodyCell="{ column, record, index: rowIdx }">
                        <template v-if="(record as SkuRow)._isBatch">
                          <template v-if="(column as any)._isSpecCol">
                            <a-select v-model:value="batchData[(column as any).title]" :placeholder="`选 ${(column as any).title}`" size="small" allow-clear style="width:100%">
                              <a-select-option v-for="det in attrs[(column as any)._attrIdx]?.detail || []" :key="det.value" :value="det.value">{{ det.value }}</a-select-option>
                            </a-select>
                          </template>
                          <template v-else-if="column.dataIndex === 'image'">
                            <div class="sku-img-cell">
                              <Upload v-model:value="(batchData as any)['__image__']" type="image" module="goods" :show-upload-list="false" />
                            </div>
                          </template>
                          <template v-else-if="column.dataIndex === 'price'"><a-input-number v-model:value="(batchData as any)['__price__']" placeholder="批量售价" :min="0" :precision="2" size="small" :controls="false" style="width:100%" /></template>
                          <template v-else-if="column.dataIndex === 'market_price'"><a-input-number v-model:value="(batchData as any)['__market_price__']" placeholder="批量市价" :min="0" :precision="2" size="small" :controls="false" style="width:100%" /></template>
                          <template v-else-if="column.dataIndex === 'stock'"><a-input-number v-model:value="(batchData as any)['__stock__']" placeholder="批量库存" :min="0" size="small" :controls="false" style="width:100%" /></template>
                          <template v-else-if="column.dataIndex === 'sku_code'"><a-input v-model:value="batchData['__sku_code__']" placeholder="批量SKU" size="small" /></template>
                          <template v-else-if="column.dataIndex === '_action'"><a @click="applyBatch">批量修改</a><a-divider type="vertical" /><a @click="clearBatch">清空</a></template>
                        </template>
                        <template v-else>
                          <template v-if="(column as any)._isSpecCol"><span class="sku-spec-val">{{ (record as SkuRow).detail[(column as any).title] }}</span></template>
                          <template v-else-if="column.dataIndex === 'image'">
                            <div class="sku-img-cell">
                              <div v-if="getSkuPreviewImage(record as SkuRow)" class="sku-img-thumb">
                                <img :src="getPicPreviewUrl(getSkuPreviewImage(record as SkuRow)!)" />
                                <span v-if="(record as SkuRow).image" class="sku-img-del" @click.stop="(record as SkuRow).image = undefined">×</span>
                              </div>
                              <div v-else class="sku-upload-wrap">
                                <Upload type="image" module="goods" :show-upload-list="false"
                                  @update:value="(v: FileInfo | undefined) => { if (v) (record as SkuRow).image = v; }" />
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
.page-body { flex: 1; overflow-y: auto; padding: 16px; }
.edit-tabs { background: hsl(var(--card)); border-radius: 8px; }
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
.spec-list { display: flex; flex-direction: column; gap: 12px; }
.spec-item { border: 1px solid hsl(var(--border)); border-radius: 6px; overflow: hidden; background: hsl(var(--card)); }
.spec-name-row { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: hsl(var(--popover)); border-bottom: 1px solid hsl(var(--border)); }
.spec-drag-handle, .val-drag-handle { cursor: grab; padding: 0 4px; color: #bbb; flex-shrink: 0; }
.spec-drag-handle:active, .val-drag-handle:active { cursor: grabbing; }
.spec-name-input { width: 180px; }
.icon-info { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 50%; border: 1px solid hsl(var(--border)); font-size: 11px; color: hsl(var(--muted-foreground)); cursor: help; flex-shrink: 0; }
.spec-values-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 12px 14px; }
.spec-val-item { display: inline-flex; align-items: center; gap: 4px; background: hsl(var(--popover)); border: 1px solid hsl(var(--border)); border-radius: 4px; padding: 2px 6px 2px 4px; }
.spec-val-item.has-pic { padding: 4px 6px 4px 4px; align-items: flex-start; flex-direction: column; }
.spec-val-item.has-pic .val-drag-handle { align-self: center; }
.val-input { width: 100px; }

/* 规格图 */
.val-pic-cell { display: flex; flex-direction: column; gap: 2px; }
.val-pic-thumb { position: relative; display: inline-block; }
.val-pic-thumb img { width: 46px; height: 46px; object-fit: cover; border-radius: 4px; border: 1px solid hsl(var(--border)); display: block; }
.val-pic-del { position: absolute; top: -6px; right: -6px; width: 16px; height: 16px; border-radius: 50%; background: rgba(0,0,0,.45); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; line-height: 1; }
.val-pic-del:hover { background: #ff4d4f; }
.val-pic-upload-wrap :deep(.ant-upload) { width: 46px !important; height: 46px !important; min-width: 46px !important; min-height: 46px !important; font-size: 18px; border-radius: 4px; overflow: hidden !important; }
.val-pic-upload-wrap :deep(.ant-upload-text) { display: none !important; }
.val-pic-upload-wrap :deep(.ant-upload-list) { display: none !important; }
.val-del { font-size: 16px; color: #bfbfbf; cursor: pointer; line-height: 1; transition: color 0.15s; margin-left: 2px; align-self: center; }
.val-del:hover { color: #ff4d4f; }
.add-val-btn { color: #1677ff; font-size: 13px; cursor: pointer; padding: 2px 4px; }
.add-val-btn:hover { opacity: 0.8; }
.spec-actions { display: flex; gap: 8px; padding: 12px 0 4px; }

/* ===== SKU 表格 ===== */
.sku-table :deep(.ant-table-cell) { padding: 4px 6px !important; vertical-align: middle; }
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
