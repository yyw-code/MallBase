<script setup>
import { useDecorateStore } from "@/store/decorate";
import { computed, getCurrentInstance, reactive, ref } from "vue";
import { onLoad, onPullDownRefresh, onShow } from "@dcloudio/uni-app";
import {
  applyDistributionDistributor,
  bindDistributionInvite,
  getDistributionSummary,
} from "@/api/distribution/distribution";
import {
  appendDistributionParams,
  captureDistributionAttribution,
  clearDistributionAttribution,
  getDistributionAttribution,
  setDistributionInviteCode,
} from "@/utils/distribution-attribution";
import {
  DISTRIBUTION_POSTER_CANVAS_ID,
  DISTRIBUTION_POSTER_SIZE,
  drawDistributionPoster,
} from "@/utils/distribution-poster";

const decorateStore = useDecorateStore();
const instance = getCurrentInstance();

const loading = ref(false);
const inviteCode = ref("");
const posterImage = ref("");
const posterLoading = ref(false);
const posterSharePath = ref("");
const posterVisible = ref(false);
const summary = ref({
  available_commission: "0.00",
  debt_commission: "0.00",
  direct_user_count: 0,
  enabled: true,
  frozen_commission: "0.00",
  indirect_user_count: 0,
  invite_code: "",
  is_distributor: false,
  message: "",
  min_withdraw_amount: "0.00",
  order_count: 0,
  pending_withdraw: "0.00",
  qualification: {
    amount_open_paid: "0.00",
    amount_open_threshold: "0.00",
    apply_review_remark: "",
    apply_status: null,
    apply_status_text: "",
    can_apply: false,
    open_mode: "manual",
  },
  status: 0,
  withdrawn_commission: "0.00",
});
const applyForm = reactive({
  mobile: "",
  real_name: "",
  reason: "",
});

const isEnabled = computed(() => summary.value.enabled !== false);
const isDistributor = computed(
  () => summary.value.is_distributor === true && Number(summary.value.status) === 1,
);
const availableText = computed(() =>
  formatAmount(summary.value.available_commission),
);
const frozenText = computed(() => formatAmount(summary.value.frozen_commission));
const pendingText = computed(() => formatAmount(summary.value.pending_withdraw));
const withdrawnText = computed(() =>
  formatAmount(summary.value.withdrawn_commission),
);
const debtText = computed(() => formatAmount(summary.value.debt_commission));
const teamTotal = computed(
  () =>
    Number(summary.value.direct_user_count || 0) +
    Number(summary.value.indirect_user_count || 0),
);
const qualification = computed(() => summary.value.qualification || {});
const canApply = computed(() => qualification.value.can_apply === true);
const amountProgressText = computed(() => {
  const paid = formatAmount(qualification.value.amount_open_paid || 0);
  const threshold = formatAmount(qualification.value.amount_open_threshold || 0);
  return `已累计 ¥${paid} / ¥${threshold}`;
});

onLoad((query) => {
  const attribution = captureDistributionAttribution(query || {}, "/pages-sub/distribution/index");
  const stored = attribution || getDistributionAttribution();
  inviteCode.value = String(stored?.invite_code || query?.invite_code || query?.code || "");
});

onShow(() => {
  fetchSummary();
});

onPullDownRefresh(async () => {
  await fetchSummary();
  uni.stopPullDownRefresh();
});

async function fetchSummary() {
  loading.value = true;
  try {
    const data = await getDistributionSummary();
    summary.value = {
      ...summary.value,
      ...(data || {}),
    };
    if (summary.value.invite_code) {
      setDistributionInviteCode(summary.value.invite_code);
    }
  } catch {
    summary.value = {
      ...summary.value,
      is_distributor: false,
      message: "暂未开通分销员资格",
    };
  } finally {
    loading.value = false;
  }
}

function formatAmount(value) {
  return Number(value || 0).toFixed(2);
}

function copyInviteCode() {
  if (!summary.value.invite_code) return;
  uni.setClipboardData({ data: summary.value.invite_code });
}

async function submitInvite() {
  const code = inviteCode.value.trim();
  if (!code) {
    uni.showToast({ title: "请输入邀请码", icon: "none" });
    return;
  }
  const attribution = getDistributionAttribution();
  await bindDistributionInvite({
    invite_code: code,
    dist_page: attribution?.dist_page || "/pages-sub/distribution/index",
    dist_scene: attribution?.dist_scene || "manual",
    dist_target_id: attribution?.dist_target_id || 0,
    dist_target_type: attribution?.dist_target_type || "",
  });
  uni.showToast({ title: "绑定成功", icon: "success" });
  inviteCode.value = "";
  clearDistributionAttribution();
  await fetchSummary();
}

async function submitApply() {
  await applyDistributionDistributor({
    mobile: applyForm.mobile,
    real_name: applyForm.real_name,
    reason: applyForm.reason,
  });
  uni.showToast({ title: "申请已提交", icon: "success" });
  applyForm.real_name = "";
  applyForm.mobile = "";
  applyForm.reason = "";
  await fetchSummary();
}

function copyShareLink(scene = "share_link") {
  if (!summary.value.invite_code) return;
  const path = buildSharePath(scene);
  uni.setClipboardData({ data: path });
}

function buildSharePath(scene = "share_link") {
  return appendDistributionParams("/pages/index/index", {
    dist_page: "/pages/index/index",
    dist_scene: scene,
    invite_code: summary.value.invite_code,
  });
}

async function openPoster() {
  if (!summary.value.invite_code) return;
  posterVisible.value = true;
  await createPoster();
}

async function createPoster() {
  if (posterLoading.value) return;
  posterLoading.value = true;
  posterSharePath.value = buildSharePath("poster");
  try {
    const ctx = uni.createCanvasContext(
      DISTRIBUTION_POSTER_CANVAS_ID,
      instance?.proxy,
    );
    drawDistributionPoster(ctx, {
      inviteCode: summary.value.invite_code,
      sharePath: posterSharePath.value,
      siteName: "MallBase",
    });
    await new Promise((resolve) => ctx.draw(false, resolve));
    posterImage.value = await canvasToPosterImage();
  } catch {
    uni.showToast({ title: "海报生成失败", icon: "none" });
  } finally {
    posterLoading.value = false;
  }
}

function canvasToPosterImage() {
  return new Promise((resolve, reject) => {
    uni.canvasToTempFilePath(
      {
        canvasId: DISTRIBUTION_POSTER_CANVAS_ID,
        destHeight: DISTRIBUTION_POSTER_SIZE.height * 2,
        destWidth: DISTRIBUTION_POSTER_SIZE.width * 2,
        fail: reject,
        height: DISTRIBUTION_POSTER_SIZE.height,
        success: (res) => resolve(res.tempFilePath),
        width: DISTRIBUTION_POSTER_SIZE.width,
      },
      instance?.proxy,
    );
  });
}

async function savePoster() {
  if (!posterImage.value) {
    await createPoster();
  }
  if (!posterImage.value) return;
  uni.saveImageToPhotosAlbum({
    fail: () => {
      uni.previewImage({ urls: [posterImage.value] });
    },
    filePath: posterImage.value,
    success: () => {
      uni.showToast({ title: "已保存海报", icon: "success" });
    },
  });
}

function previewPoster() {
  if (!posterImage.value) return;
  uni.previewImage({ urls: [posterImage.value] });
}

function closePoster() {
  posterVisible.value = false;
}

function goRecords() {
  if (!isDistributor.value) return;
  uni.navigateTo({ url: "/pages-sub/distribution/records" });
}

function goTeam() {
  if (!isDistributor.value) return;
  uni.navigateTo({ url: "/pages-sub/distribution/team" });
}

function goWithdraw() {
  if (!isDistributor.value) return;
  uni.navigateTo({ url: "/pages-sub/distribution/withdraw" });
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="分销中心" bg-color="var(--color-bg, #ffffff)" />

    <view v-if="isEnabled" class="account-card">
      <view class="account-card__top">
        <text class="account-card__label">可提现佣金</text>
        <text class="account-card__status">{{
          loading ? "同步中" : isDistributor ? "账户正常" : "未开通"
        }}</text>
      </view>
      <view class="account-card__amount">
        <text class="account-card__symbol">¥</text>
        <text class="account-card__value">{{ availableText }}</text>
      </view>
      <view class="account-card__stats">
        <view class="account-stat">
          <text class="account-stat__label">冻结佣金</text>
          <text class="account-stat__value">¥{{ frozenText }}</text>
        </view>
        <view class="account-stat">
          <text class="account-stat__label">提现中</text>
          <text class="account-stat__value">¥{{ pendingText }}</text>
        </view>
      </view>
    </view>

    <view v-else class="empty-card">
      <text class="empty-card__title">分销功能未开启</text>
    </view>

    <view v-if="isEnabled && isDistributor" class="section">
      <view class="invite-card">
        <view>
          <text class="invite-card__label">我的邀请码</text>
          <text class="invite-card__code">{{ summary.invite_code }}</text>
        </view>
        <view class="invite-card__actions">
          <view class="invite-card__btn" @tap="copyInviteCode">
            <text class="invite-card__btn-text">邀请码</text>
          </view>
          <view class="invite-card__btn" @tap="copyShareLink('share_link')">
            <text class="invite-card__btn-text">链接</text>
          </view>
          <view class="invite-card__btn" @tap="openPoster">
            <text class="invite-card__btn-text">海报</text>
          </view>
        </view>
      </view>

      <view class="metrics-grid">
        <view class="metric-item" @tap="goTeam">
          <text class="metric-item__value">{{ teamTotal }}</text>
          <text class="metric-item__label">团队人数</text>
        </view>
        <view class="metric-item" @tap="goRecords">
          <text class="metric-item__value">{{ summary.order_count || 0 }}</text>
          <text class="metric-item__label">计佣订单</text>
        </view>
        <view class="metric-item">
          <text class="metric-item__value">¥{{ withdrawnText }}</text>
          <text class="metric-item__label">已提现</text>
        </view>
        <view class="metric-item">
          <text class="metric-item__value">¥{{ debtText }}</text>
          <text class="metric-item__label">待扣回</text>
        </view>
      </view>

      <view class="action-grid">
        <view class="action-item action-item--primary" @tap="goWithdraw">
          <text class="action-item__label">提现</text>
        </view>
        <view class="action-item" @tap="goRecords">
          <text class="action-item__label">佣金明细</text>
        </view>
        <view class="action-item" @tap="goTeam">
          <text class="action-item__label">我的团队</text>
        </view>
      </view>
    </view>

    <view v-if="isEnabled && !isDistributor && canApply" class="apply-card">
      <text class="apply-card__title">申请分销员</text>
      <input
        v-model="applyForm.real_name"
        class="apply-card__input"
        placeholder="姓名"
        placeholder-class="input-placeholder"
      />
      <input
        v-model="applyForm.mobile"
        class="apply-card__input"
        placeholder="联系电话"
        placeholder-class="input-placeholder"
      />
      <textarea
        v-model="applyForm.reason"
        class="apply-card__textarea"
        placeholder="申请说明"
        placeholder-class="input-placeholder"
      />
      <view class="apply-card__btn" @tap="submitApply">
        <text class="apply-card__btn-text">提交申请</text>
      </view>
      <text v-if="qualification.apply_status_text" class="apply-card__status">
        {{ qualification.apply_status_text }}
        {{ qualification.apply_review_remark ? `：${qualification.apply_review_remark}` : "" }}
      </text>
    </view>

    <view
      v-if="isEnabled && !isDistributor && qualification.open_mode === 'amount'"
      class="empty-card"
    >
      <text class="empty-card__title">满额后自动开通分销员</text>
      <text class="empty-card__desc">{{ amountProgressText }}</text>
    </view>

    <view v-if="isEnabled" class="bind-card">
      <text class="bind-card__title">绑定邀请码</text>
      <view class="bind-card__row">
        <input
          v-model="inviteCode"
          class="bind-card__input"
          placeholder="请输入邀请码"
          placeholder-class="input-placeholder"
        />
        <view class="bind-card__btn" @tap="submitInvite">
          <text class="bind-card__btn-text">绑定</text>
        </view>
      </view>
    </view>

    <view v-if="isEnabled && !isDistributor" class="empty-card">
      <text class="empty-card__title">{{
        summary.message || "暂未开通分销员资格"
      }}</text>
    </view>

    <mb-copyright-footer />
    <mb-floating-action />
    <view v-if="posterVisible" class="poster-mask" @tap="closePoster">
      <view class="poster-panel" @tap.stop>
        <view class="poster-panel__header">
          <text class="poster-panel__title">邀请海报</text>
          <text class="poster-panel__close" @tap="closePoster">关闭</text>
        </view>
        <view class="poster-panel__body">
          <image
            v-if="posterImage"
            class="poster-panel__image"
            :src="posterImage"
            mode="widthFix"
          />
          <view v-else class="poster-panel__loading">
            <text class="poster-panel__loading-text">{{
              posterLoading ? "生成中" : "暂无海报"
            }}</text>
          </view>
        </view>
        <view class="poster-panel__actions">
          <view class="poster-panel__btn" @tap="previewPoster">
            <text class="poster-panel__btn-text">预览</text>
          </view>
          <view class="poster-panel__btn poster-panel__btn--primary" @tap="savePoster">
            <text class="poster-panel__btn-text poster-panel__btn-text--primary">保存</text>
          </view>
        </view>
      </view>
    </view>
    <canvas
      canvas-id="distributionPosterCanvas"
      id="distributionPosterCanvas"
      class="poster-canvas"
      :style="{
        height: `${DISTRIBUTION_POSTER_SIZE.height}px`,
        width: `${DISTRIBUTION_POSTER_SIZE.width}px`,
      }"
    />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 56rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.account-card,
.apply-card,
.bind-card,
.empty-card,
.invite-card,
.metrics-grid,
.action-grid {
  margin-top: $mb-spacing-md;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.account-card {
  padding: 32rpx;
}

.account-card__top,
.account-card__stats,
.invite-card,
.bind-card__row,
.action-grid {
  display: flex;
  align-items: center;
}

.account-card__top {
  justify-content: space-between;
}

.account-card__label,
.bind-card__title,
.empty-card__title,
.invite-card__label {
  color: var(--color-text, #111827);
  font-size: $mb-font-md;
  font-weight: 700;
}

.account-card__status {
  padding: 6rpx 14rpx;
  color: var(--color-success, #34c759);
  font-size: $mb-font-xs;
  background: var(--color-success-soft, rgba(52, 199, 89, 0.1));
  border-radius: $mb-radius-full;
}

.account-card__amount {
  display: flex;
  align-items: baseline;
  margin-top: 20rpx;
}

.account-card__symbol {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-xl;
  font-weight: 700;
}

.account-card__value {
  margin-left: 6rpx;
  color: var(--color-text-title, #191b23);
  font-size: 72rpx;
  font-weight: 700;
  line-height: 1;
}

.account-card__stats {
  gap: $mb-spacing-md;
  margin-top: 28rpx;
}

.account-stat {
  flex: 1;
  padding: 20rpx;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-md;
}

.account-stat__label,
.metric-item__label {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.account-stat__value,
.metric-item__value,
.invite-card__code {
  display: block;
  margin-top: 8rpx;
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-md;
  font-weight: 700;
}

.section {
  margin-top: $mb-spacing-md;
}

.invite-card {
  justify-content: space-between;
  padding: 28rpx;
}

.invite-card__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 12rpx;
  max-width: 390rpx;
}

.invite-card__btn,
.bind-card__btn,
.apply-card__btn {
  min-width: 116rpx;
  height: 64rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary, #0d50d5);
  border-radius: $mb-radius-md;
}

.invite-card__btn-text,
.bind-card__btn-text,
.apply-card__btn-text,
.action-item--primary .action-item__label {
  color: #ffffff;
}

.metrics-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rpx;
  overflow: hidden;
}

.metric-item {
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
}

.action-grid {
  gap: $mb-spacing-sm;
  padding: 20rpx;
}

.action-item {
  flex: 1;
  height: 76rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-md;
}

.action-item--primary {
  background: var(--color-primary, #0d50d5);
}

.action-item__label,
.bind-card__btn-text,
.invite-card__btn-text {
  font-size: $mb-font-sm;
  font-weight: 600;
}

.bind-card {
  padding: 28rpx;
}

.apply-card {
  padding: 28rpx;
}

.apply-card__title {
  color: var(--color-text, #111827);
  font-size: $mb-font-md;
  font-weight: 700;
}

.apply-card__input,
.apply-card__textarea {
  width: 100%;
  margin-top: 20rpx;
  padding: 0 20rpx;
  color: var(--color-text, #111827);
  font-size: $mb-font-sm;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-md;
}

.apply-card__input {
  height: 72rpx;
}

.apply-card__textarea {
  height: 150rpx;
  padding-top: 18rpx;
}

.apply-card__btn {
  width: 100%;
  margin-top: 20rpx;
}

.apply-card__status,
.empty-card__desc {
  display: block;
  margin-top: 14rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.bind-card__row {
  gap: $mb-spacing-sm;
  margin-top: 22rpx;
}

.bind-card__input {
  flex: 1;
  height: 72rpx;
  padding: 0 20rpx;
  color: var(--color-text, #111827);
  font-size: $mb-font-sm;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-md;
}

.input-placeholder {
  color: var(--color-text-tertiary, #737686);
}

.empty-card {
  padding: 40rpx 28rpx;
  text-align: center;
}

.poster-mask {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48rpx;
  background: rgba(0, 0, 0, 0.48);
}

.poster-panel {
  width: 100%;
  max-width: 620rpx;
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-lg;
}

.poster-panel__header,
.poster-panel__actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.poster-panel__title {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-md;
  font-weight: 700;
}

.poster-panel__close {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.poster-panel__body {
  min-height: 620rpx;
  margin-top: 24rpx;
  overflow: hidden;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-md;
}

.poster-panel__image {
  width: 100%;
}

.poster-panel__loading {
  height: 620rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}

.poster-panel__loading-text {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.poster-panel__actions {
  gap: $mb-spacing-sm;
  margin-top: 24rpx;
}

.poster-panel__btn {
  flex: 1;
  height: 72rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-md;
}

.poster-panel__btn--primary {
  background: var(--color-primary, #0d50d5);
}

.poster-panel__btn-text {
  color: var(--color-text, #111827);
  font-size: $mb-font-sm;
  font-weight: 600;
}

.poster-panel__btn-text--primary {
  color: #ffffff;
}

.poster-canvas {
  position: fixed;
  left: -9999px;
  top: -9999px;
  pointer-events: none;
}
</style>
