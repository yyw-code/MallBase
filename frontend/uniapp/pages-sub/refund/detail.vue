<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { cancelRefund, getRefundDetail, submitRefundReturn } from '@/api/order/refund'
import config from '@/config/index'

const STATUS_CONFIG = {
  0: { label: '待审核', color: '#0d50d5', bg: 'rgba(13, 80, 213, 0.08)' },
  1: { label: '待退货', color: '#8a5a00', bg: 'rgba(224, 138, 0, 0.10)' },
  2: { label: '退款中', color: '#0d50d5', bg: 'rgba(13, 80, 213, 0.08)' },
  10: { label: '已完成', color: '#168a43', bg: 'rgba(22, 138, 67, 0.08)' },
  20: { label: '已拒绝', color: '#ba1a1a', bg: 'rgba(186, 26, 26, 0.08)' },
  90: { label: '已关闭', color: '#737686', bg: 'rgba(115, 118, 134, 0.08)' },
}

const refundId = ref('')
const detail = ref(null)
const loading = ref(true)
const cancelling = ref(false)
const submittingReturn = ref(false)
const returnCompany = ref('')
const returnTrackingNo = ref('')

onLoad((query) => {
  refundId.value = query?.id || ''
  if (refundId.value) {
    fetchDetail()
  } else {
    loading.value = false
  }
})

async function fetchDetail() {
  loading.value = true
  try {
    const data = await getRefundDetail(refundId.value)
    detail.value = data ?? null
    returnCompany.value = ''
    returnTrackingNo.value = ''
  } catch {
    detail.value = null
  } finally {
    loading.value = false
  }
}

const statusConfig = computed(() => {
  if (!detail.value) return STATUS_CONFIG[0]
  return STATUS_CONFIG[Number(detail.value.status)] || STATUS_CONFIG[0]
})

const statusTitle = computed(() => {
  if (!detail.value) return ''
  return detail.value.status_text || statusConfig.value.label
})

const statusHint = computed(() => {
  const data = detail.value
  if (!data) return ''
  const status = Number(data.status)
  const type = Number(data.type)
  if (status === 0 && Number(data.receive_status) === 0 && data.intercept_status_text) {
    if (data.intercept_status === 'exception') {
      return '商家已标记物流异常/丢件，正在核实处理，请等待审核结果'
    }
    if (['success', 'returned'].includes(data.intercept_status || '')) {
      return `商家已确认${data.intercept_status_text}，请等待退款审核结果`
    }
    return `商家审核中，物流拦截状态：${data.intercept_status_text}`
  }
  if (status === 0) return '商家正在审核您的售后申请'
  if (status === 1 && type === 1) return '商家已同意退货，请提交退货物流'
  if (status === 2) return '退款处理中，请等待到账'
  if (status === 10) return '退款已完成，请留意原支付账户'
  if (status === 20) return '商家已拒绝本次售后申请'
  if (status === 90) return '售后申请已关闭'
  return '售后状态已更新'
})

const orderItem = computed(() => detail.value?.order_item || {})
const orderInfo = computed(() => detail.value?.order || {})

const timelineSteps = computed(() => {
  const data = detail.value
  if (!data) return []
  const status = Number(data.status)
  const type = Number(data.type)

  if (status === 20) {
    return [
      { label: '提交申请', time: data.create_time, done: true },
      { label: '商家驳回', time: data.reviewed_at, rejected: true },
    ]
  }
  if (status === 90) {
    return [
      { label: '提交申请', time: data.create_time, done: true },
      { label: '申请关闭', time: data.canceled_at, done: true },
    ]
  }
  if (type === 1) {
    return [
      { label: '提交申请', time: data.create_time, done: true },
      { label: '商家同意退货', time: data.reviewed_at, done: status >= 1, active: status === 0 },
      { label: '买家寄回商品', time: data.return_shipped_at, done: !!data.return_tracking_no, active: status === 1 && !data.return_tracking_no },
      { label: '商家确认收货', time: data.return_received_at, done: !!data.return_received_at, active: status === 1 && !!data.return_tracking_no },
      { label: '退款完成', time: data.refunded_at, done: status === 10, active: status === 2 },
    ]
  }
  return [
    { label: '提交申请', time: data.create_time, done: true },
    { label: Number(data.receive_status) === 0 ? '物流拦截/商家审核' : '商家审核', time: data.reviewed_at, done: status >= 2 || status === 10, active: status === 0 },
    { label: '退款处理中', time: '', done: status === 10, active: status === 2 },
    { label: '退款完成', time: data.refunded_at, done: status === 10 },
  ]
})

const canCancel = computed(() => Number(detail.value?.status) === 0)

const canSubmitReturn = computed(() => {
  return detail.value
    && Number(detail.value.status) === 1
    && Number(detail.value.type) === 1
    && !detail.value.return_tracking_no
})

function normalizeImageUrl(url) {
  if (!url) return ''
  const value = String(url)
  if (/^(https?:)?\/\//.test(value) || value.startsWith('data:') || value.startsWith('blob:')) {
    return value.startsWith('//') ? `https:${value}` : value
  }
  if (value.startsWith('/') && config.baseUrl) {
    return `${config.baseUrl}${value}`
  }
  return value
}

function goodsImage() {
  const item = orderItem.value
  return normalizeImageUrl(
    item.goods_image_full_url
      || item.goods_image
      || detail.value?.goods_image_full_url
      || detail.value?.goods_image
      || '',
  )
}

function goodsName() {
  return orderItem.value.goods_name || detail.value?.goods_name || '商品信息'
}

function goodsSpec() {
  return orderItem.value.sku_spec_text || orderItem.value.sku_spec || detail.value?.sku_spec_text || detail.value?.sku_spec || ''
}

function goodsQuantity() {
  return Number(detail.value?.quantity || 1)
}

function formatPrice(val) {
  const num = Number(val)
  if (Number.isNaN(num)) return '0.00'
  return num.toFixed(2)
}

function typeText() {
  return detail.value?.type_text || (Number(detail.value?.type) === 1 ? '退货退款' : '仅退款')
}

function receiveText() {
  return detail.value?.receive_status_text || (Number(detail.value?.receive_status) === 1 ? '已收到货' : '未收到货')
}

function goOrderDetail() {
  const id = detail.value?.order_id || detail.value?.order?.id
  if (!id) return
  uni.navigateTo({ url: `/pages-sub/order/detail?id=${id}` })
}

function onCancelRefund() {
  if (cancelling.value) return
  uni.showModal({
    title: '提示',
    content: '确定要取消售后申请吗？',
    success: async (res) => {
      if (!res.confirm) return
      cancelling.value = true
      try {
        await cancelRefund(refundId.value)
        uni.showToast({ title: '已取消', icon: 'success' })
        fetchDetail()
      } catch {
        // handled by request interceptor
      } finally {
        cancelling.value = false
      }
    },
  })
}

async function onSubmitReturn() {
  if (submittingReturn.value) return
  if (!returnCompany.value.trim() || !returnTrackingNo.value.trim()) {
    uni.showToast({ title: '请填写物流公司和物流单号', icon: 'none' })
    return
  }
  submittingReturn.value = true
  try {
    await submitRefundReturn(refundId.value, {
      return_company: returnCompany.value.trim(),
      return_tracking_no: returnTrackingNo.value.trim(),
    })
    uni.showToast({ title: '退货物流已提交', icon: 'success' })
    fetchDetail()
  } catch {
    // handled by request interceptor
  } finally {
    submittingReturn.value = false
  }
}
</script>

<template>
  <view class="page">
    <mb-navbar title="售后详情" />

    <view v-if="loading" class="loading-wrap">
      <mb-skeleton type="card" />
      <mb-skeleton type="avatar-lines" />
      <mb-skeleton type="lines" :count="4" />
    </view>

    <mb-empty-state
      v-else-if="!detail"
      text="售后记录不存在"
      action-text="返回"
      @action="() => uni.navigateBack()"
    />

    <template v-else>
      <view class="status-card" :style="{ background: statusConfig.bg }">
        <view class="status-card__main">
          <text class="status-card__title" :style="{ color: statusConfig.color }">{{ statusTitle }}</text>
          <text class="status-card__hint">{{ statusHint }}</text>
        </view>
        <view class="status-card__amount">
          <text class="status-card__amount-label">退款金额</text>
          <text class="status-card__amount-value">¥{{ formatPrice(detail.refund_amount || detail.amount) }}</text>
        </view>
      </view>

      <view class="card">
        <text class="card__title">处理进度</text>
        <view class="timeline">
          <view
            v-for="(step, idx) in timelineSteps"
            :key="idx"
            class="timeline__item"
          >
            <view class="timeline__rail">
              <view
                class="timeline__dot"
                :class="{
                  'timeline__dot--active': step.active,
                  'timeline__dot--done': step.done && !step.rejected,
                  'timeline__dot--rejected': step.rejected,
                }"
              />
              <view
                v-if="idx < timelineSteps.length - 1"
                class="timeline__line"
                :class="{ 'timeline__line--done': step.done && !step.rejected }"
              />
            </view>
            <view class="timeline__content">
              <text
                class="timeline__label"
                :class="{
                  'timeline__label--active': step.active,
                  'timeline__label--rejected': step.rejected,
                }"
              >{{ step.label }}</text>
              <text v-if="step.time" class="timeline__time">{{ step.time }}</text>
            </view>
          </view>
        </view>
      </view>

      <view class="card product-card">
        <text class="card__title">售后商品</text>
        <view class="product-row">
          <image
            v-if="goodsImage()"
            class="product-row__image"
            :src="goodsImage()"
            mode="aspectFill"
          />
          <view v-else class="product-row__image product-row__image--placeholder">
            <view class="product-row__placeholder-box" />
          </view>
          <view class="product-row__info">
            <text class="product-row__name">{{ goodsName() }}</text>
            <text v-if="goodsSpec()" class="product-row__spec">{{ goodsSpec() }}</text>
            <view class="product-row__bottom">
              <text class="product-row__type">{{ typeText() }}</text>
              <text class="product-row__qty">x{{ goodsQuantity() }}</text>
            </view>
          </view>
        </view>
      </view>

      <view class="card">
        <view class="section-title-row">
          <text class="card__title">售后信息</text>
          <text class="section-title-row__link" @tap="goOrderDetail">查看订单</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">售后单号</text>
          <text class="info-row__value">{{ detail.sn || '-' }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">订单编号</text>
          <text class="info-row__value">{{ orderInfo.sn || '-' }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">订单状态</text>
          <text class="info-row__value">{{ orderInfo.status_text || '-' }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">售后类型</text>
          <text class="info-row__value">{{ typeText() }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">收货状态</text>
          <text class="info-row__value">{{ receiveText() }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">申请原因</text>
          <text class="info-row__value">{{ detail.reason_text || detail.reason || '-' }}</text>
        </view>
        <view v-if="detail.remark" class="info-row info-row--column">
          <text class="info-row__label">买家说明</text>
          <text class="info-row__desc">{{ detail.remark }}</text>
        </view>
        <view v-if="detail.admin_remark" class="info-row info-row--column">
          <text class="info-row__label">商家备注</text>
          <text class="info-row__desc">{{ detail.admin_remark }}</text>
        </view>
      </view>

      <view v-if="Number(detail.type) === 0 && Number(detail.receive_status) === 0" class="card">
        <text class="card__title">物流拦截</text>
        <view class="info-row">
          <text class="info-row__label">拦截状态</text>
          <text class="info-row__value">{{ detail.intercept_status_text || '-' }}</text>
        </view>
        <view v-if="detail.intercept_note" class="info-row info-row--column">
          <text class="info-row__label">拦截备注</text>
          <text class="info-row__desc">{{ detail.intercept_note }}</text>
        </view>
      </view>

      <view v-if="Number(detail.type) === 1" class="card">
        <text class="card__title">退货信息</text>
        <view v-if="detail.return_receiver_address" class="return-address">
          <text class="return-address__name">
            {{ detail.return_receiver_name || '-' }} {{ detail.return_receiver_phone || '' }}
          </text>
          <text class="return-address__detail">{{ detail.return_receiver_address }}</text>
        </view>

        <template v-if="canSubmitReturn">
          <view class="form-block">
            <text class="form-block__label">物流公司</text>
            <input
              v-model="returnCompany"
              class="form-block__input"
              placeholder="请输入物流公司"
              placeholder-style="color: #9ca3af"
            />
          </view>
          <view class="form-block">
            <text class="form-block__label">物流单号</text>
            <input
              v-model="returnTrackingNo"
              class="form-block__input"
              placeholder="请输入物流单号"
              placeholder-style="color: #9ca3af"
            />
          </view>
          <view
            class="return-submit"
            :class="{ 'return-submit--disabled': submittingReturn }"
            @tap="onSubmitReturn"
          >
            <text class="return-submit__text">{{ submittingReturn ? '提交中...' : '提交退货物流' }}</text>
          </view>
        </template>
        <template v-else>
          <view class="info-row">
            <text class="info-row__label">物流公司</text>
            <text class="info-row__value">{{ detail.return_company || '-' }}</text>
          </view>
          <view class="info-row">
            <text class="info-row__label">物流单号</text>
            <text class="info-row__value">{{ detail.return_tracking_no || '-' }}</text>
          </view>
          <view class="info-row">
            <text class="info-row__label">寄出时间</text>
            <text class="info-row__value">{{ detail.return_shipped_at || '-' }}</text>
          </view>
          <view class="info-row">
            <text class="info-row__label">收货时间</text>
            <text class="info-row__value">{{ detail.return_received_at || '-' }}</text>
          </view>
        </template>
      </view>

      <view class="card">
        <text class="card__title">时间信息</text>
        <view class="info-row">
          <text class="info-row__label">申请时间</text>
          <text class="info-row__value">{{ detail.create_time || '-' }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">审核时间</text>
          <text class="info-row__value">{{ detail.reviewed_at || '-' }}</text>
        </view>
        <view class="info-row">
          <text class="info-row__label">完成时间</text>
          <text class="info-row__value">{{ detail.refunded_at || '-' }}</text>
        </view>
      </view>

      <view v-if="canCancel" class="bottom-spacer" />
      <view v-if="canCancel" class="action-bar">
        <view class="action-bar__inner">
          <view
            class="action-bar__btn"
            :class="{ 'action-bar__btn--disabled': cancelling }"
            @tap="onCancelRefund"
          >
            <text class="action-bar__btn-text">{{ cancelling ? '取消中...' : '取消售后' }}</text>
          </view>
        </view>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: #faf8ff;
  padding: 0 $mb-spacing-page $mb-spacing-xl;
}

.loading-wrap {
  padding-top: $mb-spacing-lg;
  display: flex;
  flex-direction: column;
  gap: $mb-spacing-md;
}

.status-card {
  display: flex;
  justify-content: space-between;
  gap: $mb-spacing-md;
  margin: $mb-spacing-md 0;
  padding: 24rpx;
  border-radius: 18rpx;
  border: 1rpx solid rgba(25, 27, 35, 0.06);
}

.status-card__main {
  flex: 1;
  min-width: 0;
}

.status-card__title {
  display: block;
  font-size: 36rpx;
  font-weight: 800;
  line-height: 1.2;
}

.status-card__hint {
  display: block;
  margin-top: 10rpx;
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
  line-height: 1.45;
}

.status-card__amount {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  justify-content: center;
}

.status-card__amount-label {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

.status-card__amount-value {
  margin-top: 6rpx;
  font-size: 38rpx;
  font-weight: 800;
  color: $mb-color-primary;
}

.card {
  background: $mb-color-bg;
  border-radius: 16rpx;
  padding: $mb-spacing-lg;
  margin-bottom: $mb-spacing-md;
  border: 1rpx solid rgba(25, 27, 35, 0.06);
}

.card__title {
  display: block;
  margin-bottom: $mb-spacing-md;
  font-size: $mb-font-md;
  font-weight: 700;
  color: $mb-color-text-title;
}

.section-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: $mb-spacing-sm;
}

.section-title-row .card__title {
  margin-bottom: 0;
}

.section-title-row__link {
  font-size: $mb-font-sm;
  font-weight: 600;
  color: $mb-color-primary;
}

.timeline {
  padding-top: 2rpx;
}

.timeline__item {
  display: flex;
  gap: $mb-spacing-md;
  min-height: 82rpx;
}

.timeline__rail {
  width: 28rpx;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.timeline__dot {
  width: 18rpx;
  height: 18rpx;
  border-radius: 50%;
  background: $mb-color-border;
}

.timeline__dot--done {
  background: $mb-color-success;
}

.timeline__dot--active {
  background: $mb-color-primary;
  border: 6rpx solid rgba(13, 80, 213, 0.14);
  box-sizing: content-box;
}

.timeline__dot--rejected {
  background: $mb-color-error;
}

.timeline__line {
  flex: 1;
  width: 2rpx;
  margin: 4rpx 0;
  background: $mb-color-border;
}

.timeline__line--done {
  background: $mb-color-success;
}

.timeline__content {
  flex: 1;
  min-width: 0;
  padding-bottom: $mb-spacing-md;
}

.timeline__label {
  display: block;
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  line-height: 1.35;
}

.timeline__label--active {
  color: $mb-color-primary;
  font-weight: 700;
}

.timeline__label--rejected {
  color: $mb-color-error;
  font-weight: 700;
}

.timeline__time {
  display: block;
  margin-top: 4rpx;
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

.product-row {
  display: flex;
  gap: 18rpx;
}

.product-row__image {
  flex-shrink: 0;
  width: 144rpx;
  height: 144rpx;
  border-radius: 10rpx;
  background: #f3f5f9;
}

.product-row__image--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.product-row__placeholder-box {
  width: 54rpx;
  height: 38rpx;
  border-radius: 8rpx;
  background: linear-gradient(135deg, rgba(13, 80, 213, 0.14), rgba(25, 27, 35, 0.06));
}

.product-row__info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 10rpx;
}

.product-row__name {
  font-size: 27rpx;
  font-weight: 600;
  color: $mb-color-text-title;
  line-height: 1.38;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.product-row__spec {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

.product-row__bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.product-row__type {
  padding: 5rpx 12rpx;
  border-radius: 999rpx;
  background: rgba(13, 80, 213, 0.08);
  font-size: $mb-font-xs;
  color: $mb-color-primary;
}

.product-row__qty {
  font-size: $mb-font-xs;
  color: $mb-color-text-tertiary;
}

.info-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-md;
  padding: 14rpx 0;
}

.info-row + .info-row {
  border-top: 1rpx solid rgba(25, 27, 35, 0.06);
}

.info-row--column {
  flex-direction: column;
  align-items: flex-start;
  gap: 8rpx;
}

.info-row__label {
  flex-shrink: 0;
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

.info-row__value {
  min-width: 0;
  flex: 1;
  font-size: $mb-font-sm;
  color: $mb-color-text-title;
  text-align: right;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.info-row__desc {
  font-size: $mb-font-sm;
  color: $mb-color-text-title;
  line-height: 1.55;
}

.return-address {
  padding: 16rpx;
  margin-bottom: $mb-spacing-md;
  border-radius: 14rpx;
  background: rgba(25, 27, 35, 0.03);
}

.return-address__name {
  display: block;
  font-size: $mb-font-md;
  font-weight: 700;
  color: $mb-color-text-title;
}

.return-address__detail {
  display: block;
  margin-top: 8rpx;
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
  line-height: 1.5;
}

.form-block {
  margin-top: $mb-spacing-md;
}

.form-block__label {
  display: block;
  margin-bottom: 10rpx;
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
}

.form-block__input {
  width: 100%;
  height: 78rpx;
  padding: 0 $mb-spacing-md;
  box-sizing: border-box;
  background: $mb-color-bg-secondary;
  border: 1rpx solid $mb-color-divider;
  border-radius: 14rpx;
  color: $mb-color-text;
  font-size: $mb-font-md;
}

.return-submit {
  height: $mb-btn-height-md;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: $mb-spacing-md;
  background: $mb-color-primary;
  border-radius: $mb-radius-full;
}

.return-submit--disabled {
  opacity: 0.6;
}

.return-submit__text {
  color: #fff;
  font-size: $mb-font-md;
  font-weight: 700;
}

.bottom-spacer {
  height: calc(140rpx + env(safe-area-inset-bottom));
}

.action-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  background: $mb-color-bg;
  box-shadow: $mb-shadow-bar;
}

.action-bar__inner {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding: $mb-spacing-sm $mb-spacing-page;
  padding-bottom: calc(#{$mb-spacing-sm} + env(safe-area-inset-bottom));
}

.action-bar__btn {
  min-width: 200rpx;
  height: 76rpx;
  padding: 0 $mb-spacing-lg;
  border-radius: $mb-radius-full;
  border: 2rpx solid rgba(13, 80, 213, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
}

.action-bar__btn--disabled {
  opacity: 0.5;
  pointer-events: none;
}

.action-bar__btn-text {
  font-size: $mb-font-md;
  font-weight: 700;
  color: $mb-color-primary;
}
</style>
