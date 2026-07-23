<script setup>
import { useAppStore } from "@/store/app";
import { useDecorateStore } from "@/store/decorate";
import { computed, getCurrentInstance, reactive, ref } from "vue";
import {
  onLoad,
  onPullDownRefresh,
  onShareAppMessage,
  onShareTimeline,
  onShow,
} from "@dcloudio/uni-app";
import {
  applyDistributionDistributor,
  bindDistributionInvite,
  getDistributionSummary,
  withdrawDistributionApply,
} from "@/api/distribution/distribution";
import { getUploadConfig, getUploadedAssetValue, uploadImage } from "@/api/upload";
import { chooseImageFiles } from "@/utils/image-picker";
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

const appStore = useAppStore();
const decorateStore = useDecorateStore();
const instance = getCurrentInstance();

const loading = ref(false);
const inviteCode = ref("");
const posterImage = ref("");
const posterLoading = ref(false);
const posterPreviewVisible = ref(false);
const posterSharePath = ref("");
const posterVisible = ref(false);
const applyDetailVisible = ref(false);
const applySubmitting = ref(false);
const applyProofImage = ref("");
const applyProofImageAsset = ref("");
const applyMobileError = ref("");
const applyWithdrawing = ref(false);
const uploadConfig = ref({
  accept_types: [],
  max_count: 1,
  max_size: 0,
  tips: [],
});
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
    latest_apply: null,
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
const latestApply = computed(() => qualification.value.latest_apply || null);
const applyStatus = computed(() => {
  const status = qualification.value.apply_status;
  return status === null || status === undefined || status === "" ? null : Number(status);
});
const hasPendingApply = computed(() => applyStatus.value === 0);
const hasRejectedApply = computed(() => applyStatus.value === 20);
const showApplyForm = computed(
  () => qualification.value.can_apply === true && !hasPendingApply.value,
);
const applyFormTitle = computed(() => (hasRejectedApply.value ? "修改申请" : "申请分销员"));
const applySubmitText = computed(() => {
  if (applySubmitting.value) return "提交中";
  return hasRejectedApply.value ? "重新提交" : "提交申请";
});
const uploadTipText = computed(() => {
  const tips = Array.isArray(uploadConfig.value.tips) ? uploadConfig.value.tips : [];
  return tips.length > 0 ? tips.join("，") : "选填，最多1张";
});
const amountProgressText = computed(() => {
  const paid = formatAmount(qualification.value.amount_open_paid || 0);
  const threshold = formatAmount(qualification.value.amount_open_threshold || 0);
  return `已累计 ¥${paid} / ¥${threshold}`;
});

onLoad((query) => {
  const attribution = captureDistributionAttribution(query || {}, "/pages-sub/distribution/index");
  const stored = attribution || getDistributionAttribution();
  inviteCode.value = String(stored?.invite_code || query?.invite_code || query?.code || "");
  fetchUploadConfig();
  if (!appStore.siteConfig) {
    appStore.fetchBasicConfig();
  }
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
    fillRejectedApplyForm();
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

async function fetchUploadConfig() {
  try {
    const config = await getUploadConfig("image");
    uploadConfig.value = {
      accept_types: Array.isArray(config?.accept_types) ? config.accept_types : [],
      max_count: Number(config?.max_count || 1),
      max_size: Number(config?.max_size || 0),
      tips: Array.isArray(config?.tips) ? config.tips : [],
    };
  } catch {
    uploadConfig.value = {
      accept_types: [],
      max_count: 1,
      max_size: 0,
      tips: [],
    };
  }
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

async function chooseApplyProofImage() {
  const [image] = await chooseImageFiles({
    count: 1,
    sizeType: ["compressed"],
    sourceType: ["album", "camera"],
  });
  if (!image?.path) return;
  if (!isUploadFileSizeAllowed(image.size)) {
    uni.showToast({ title: uploadSizeLimitText(), icon: "none" });
    return;
  }
  applyProofImage.value = image.path;
  applyProofImageAsset.value = "";
}

function previewApplyProofImage() {
  if (!applyProofImage.value) return;
  uni.previewImage({
    current: applyProofImage.value,
    urls: [applyProofImage.value],
  });
}

function removeApplyProofImage() {
  applyProofImage.value = "";
  applyProofImageAsset.value = "";
}

function isValidMobile(value) {
  return /^1[3-9]\d{9}$/.test(String(value || "").trim());
}

function onApplyMobileInput(event) {
  const value = String(event?.detail?.value ?? applyForm.mobile ?? "");
  applyForm.mobile = value;
  if (value.length >= 11) {
    validateApplyMobile();
    return;
  }
  applyMobileError.value = "";
}

function validateApplyMobile() {
  const mobile = String(applyForm.mobile || "").trim();
  if (!mobile) {
    applyMobileError.value = "请输入联系电话";
    return false;
  }
  if (!isValidMobile(mobile)) {
    applyMobileError.value = "请输入正确的手机号";
    return false;
  }
  applyMobileError.value = "";
  return true;
}

function isUploadFileSizeAllowed(size) {
  const maxSizeMb = Number(uploadConfig.value.max_size || 0);
  if (maxSizeMb <= 0 || size <= 0) return true;
  return size <= maxSizeMb * 1024 * 1024;
}

function uploadSizeLimitText() {
  const maxSizeMb = Number(uploadConfig.value.max_size || 0);
  if (maxSizeMb <= 0) return "图片过大，请压缩后上传";
  return `图片最大支持 ${formatUploadSize(maxSizeMb)}MB`;
}

function formatUploadSize(value) {
  const size = Number(value || 0);
  if (!Number.isFinite(size)) return "0";
  return Number.isInteger(size) ? String(size) : size.toFixed(1).replace(/\.0$/, "");
}

async function uploadApplyProofImage() {
  if (!applyProofImage.value) return "";
  if (applyProofImageAsset.value) return applyProofImageAsset.value;
  const uploaded = await uploadImage(applyProofImage.value, { module: "distribution_apply" });
  const value = getUploadedAssetValue(uploaded);
  if (!value) {
    throw new Error("申请凭证上传失败");
  }
  return String(value);
}

async function submitApply() {
  if (applySubmitting.value) return;
  const realName = String(applyForm.real_name || "").trim();
  const mobile = String(applyForm.mobile || "").trim();
  if (!realName) {
    uni.showToast({ title: "请输入姓名", icon: "none" });
    return;
  }
  if (!validateApplyMobile()) {
    uni.showToast({ title: applyMobileError.value, icon: "none" });
    return;
  }

  applySubmitting.value = true;
  uni.showLoading({ title: "提交中", mask: true });
  try {
    const proofImage = await uploadApplyProofImage();
    await applyDistributionDistributor({
      mobile,
      proof_image: proofImage,
      real_name: realName,
      reason: String(applyForm.reason || "").trim(),
    });
    uni.showToast({ title: "申请已提交", icon: "success" });
    applyForm.real_name = "";
    applyForm.mobile = "";
    applyForm.reason = "";
    applyProofImage.value = "";
    applyProofImageAsset.value = "";
    await fetchSummary();
  } catch (error) {
    uni.showToast({
      title: error?.message || "提交失败，请重试",
      icon: "none",
    });
  } finally {
    uni.hideLoading();
    applySubmitting.value = false;
  }
}

function fillRejectedApplyForm() {
  if (!hasRejectedApply.value || applySubmitting.value) return;
  const apply = latestApply.value;
  if (!apply) return;
  if (applyForm.real_name || applyForm.mobile || applyForm.reason || applyProofImage.value) return;
  applyForm.real_name = String(apply.real_name || "");
  applyForm.mobile = String(apply.mobile || "");
  applyForm.reason = String(apply.reason || "");
  applyProofImage.value = String(apply.proof_image_full_url || "");
  applyProofImageAsset.value = String(apply.proof_image || "");
}

function openApplyDetail() {
  if (!latestApply.value) {
    uni.showToast({ title: "暂无申请详情", icon: "none" });
    return;
  }
  applyDetailVisible.value = true;
}

function closeApplyDetail() {
  applyDetailVisible.value = false;
}

function previewCurrentApplyProof() {
  const url = String(latestApply.value?.proof_image_full_url || "");
  if (!url) return;
  uni.previewImage({
    current: url,
    urls: [url],
  });
}

function confirmWithdrawApply() {
  return new Promise((resolve) => {
    uni.showModal({
      cancelText: "再想想",
      confirmText: "确认撤回",
      content: "撤回后可重新提交申请，确认撤回当前待审核申请吗？",
      title: "撤回申请",
      success: (res) => resolve(res.confirm === true),
    });
  });
}

async function withdrawApply() {
  if (applyWithdrawing.value) return;
  const shouldRestoreDetail = applyDetailVisible.value;
  applyDetailVisible.value = false;
  const confirmed = await confirmWithdrawApply();
  if (!confirmed) {
    if (shouldRestoreDetail) applyDetailVisible.value = true;
    return;
  }

  applyWithdrawing.value = true;
  uni.showLoading({ title: "撤回中", mask: true });
  try {
    await withdrawDistributionApply();
    uni.showToast({ title: "已撤回", icon: "success" });
    closeApplyDetail();
    await fetchSummary();
  } catch (error) {
    uni.showToast({
      title: error?.message || "撤回失败，请重试",
      icon: "none",
    });
  } finally {
    uni.hideLoading();
    applyWithdrawing.value = false;
  }
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
    if (!appStore.siteConfig) {
      await appStore.fetchBasicConfig();
    }
    const siteConfig = appStore.siteConfig || {};
    const coverImage = await loadPosterCoverImage(siteConfig.client_share_cover);
    const ctx = uni.createCanvasContext(
      DISTRIBUTION_POSTER_CANVAS_ID,
      instance?.proxy,
    );
    drawDistributionPoster(ctx, {
      coverImage,
      inviteCode: summary.value.invite_code,
      qrText: buildPosterQrText(posterSharePath.value),
      sharePath: posterSharePath.value,
      siteName: siteConfig.client_site_name || siteConfig.site_name || "",
      subtitle: siteConfig.client_share_desc || "",
      title: siteConfig.client_share_title || "分销邀请",
    });
    await new Promise((resolve) => ctx.draw(false, resolve));
    posterImage.value = await canvasToPosterImage();
  } catch {
    uni.showToast({ title: "海报生成失败", icon: "none" });
  } finally {
    posterLoading.value = false;
  }
}

function loadPosterCoverImage(src) {
  const value = String(src || "");
  if (!value) return "";

  return new Promise((resolve) => {
    uni.getImageInfo({
      src: value,
      fail: () => resolve(""),
      success: (res) => resolve(res.path || value),
    });
  });
}

function buildPosterQrText(path) {
  const value = String(path || "");
  if (!value) return "";

  // #ifdef H5
  if (typeof location !== "undefined") {
    const routePath = value.startsWith("/") ? value : `/${value}`;
    return `${location.origin}${location.pathname}#${routePath}`;
  }
  // #endif

  return value;
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

  // #ifdef H5
  downloadPosterImage(posterImage.value);
  return;
  // #endif

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

  // #ifdef H5
  posterPreviewVisible.value = true;
  return;
  // #endif

  uni.previewImage({ urls: [posterImage.value] });
}

function closePoster() {
  posterVisible.value = false;
  posterPreviewVisible.value = false;
}

function closePosterPreview() {
  posterPreviewVisible.value = false;
}

function downloadPosterImage(src) {
  if (typeof document === "undefined") {
    uni.showToast({ title: "请长按海报保存", icon: "none" });
    return;
  }

  try {
    const link = document.createElement("a");
    link.href = src;
    link.download = `distribution-poster-${summary.value.invite_code || Date.now()}.png`;
    link.style.display = "none";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    uni.showToast({ title: "已开始下载海报", icon: "success" });
  } catch {
    posterPreviewVisible.value = true;
    uni.showToast({ title: "请长按海报保存", icon: "none" });
  }
}

function buildNativeShareTarget(scene = "share_link") {
  const siteConfig = appStore.siteConfig || {};
  const title =
    siteConfig.client_share_title ||
    siteConfig.client_site_name ||
    siteConfig.site_name ||
    "分销邀请";
  const path = buildSharePath(scene);
  return {
    imageUrl: siteConfig.client_share_cover || "",
    path,
    query: path.split("?")[1] || "",
    title,
  };
}

onShareAppMessage(() => {
  const { imageUrl, path, title } = buildNativeShareTarget("share_link");
  return { imageUrl, path, title };
});

onShareTimeline(() => {
  const { imageUrl, query, title } = buildNativeShareTarget("share_link");
  return { imageUrl, query, title };
});

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
        <view class="invite-card__main">
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

    <view v-if="isEnabled && !isDistributor && hasPendingApply" class="apply-card apply-card--status">
      <text class="apply-card__title">申请已提交</text>
      <text class="apply-card__status-desc">
        当前状态：{{ qualification.apply_status_text || "待审核" }}
      </text>
      <text v-if="latestApply?.create_time" class="apply-card__status-desc">
        提交时间：{{ latestApply.create_time }}
      </text>
      <text v-if="qualification.apply_review_remark" class="apply-card__status-desc">
        审核备注：{{ qualification.apply_review_remark }}
      </text>
      <view class="apply-card__actions">
        <view class="apply-card__btn apply-card__btn--secondary" @tap="openApplyDetail">
          <text class="apply-card__btn-text">查看申请</text>
        </view>
        <view
          class="apply-card__btn apply-card__btn--danger"
          :class="{ 'apply-card__btn--disabled': applyWithdrawing }"
          @tap="withdrawApply"
        >
          <text class="apply-card__btn-text apply-card__btn-text--danger">
            {{ applyWithdrawing ? "撤回中" : "撤回申请" }}
          </text>
        </view>
      </view>
    </view>

    <view v-if="isEnabled && !isDistributor && showApplyForm" class="apply-card">
      <text class="apply-card__title">{{ applyFormTitle }}</text>
      <text v-if="hasRejectedApply && qualification.apply_review_remark" class="apply-card__status">
        驳回原因：{{ qualification.apply_review_remark }}
      </text>
      <input
        v-model="applyForm.real_name"
        class="apply-card__input"
        placeholder="姓名"
        placeholder-class="input-placeholder"
      />
      <input
        v-model="applyForm.mobile"
        class="apply-card__input"
        maxlength="11"
        placeholder="联系电话"
        placeholder-class="input-placeholder"
        type="number"
        @blur="validateApplyMobile"
        @input="onApplyMobileInput"
      />
      <text v-if="applyMobileError" class="apply-card__error">{{ applyMobileError }}</text>
      <textarea
        v-model="applyForm.reason"
        class="apply-card__textarea"
        placeholder="申请说明"
        placeholder-class="input-placeholder"
      />
      <view class="apply-card__proof">
        <view class="apply-card__proof-head">
          <text class="apply-card__proof-label">申请凭证</text>
          <text class="apply-card__proof-hint">{{ uploadTipText }}</text>
        </view>
        <view class="proof-uploader">
          <view v-if="applyProofImage" class="proof-uploader__item">
            <image
              class="proof-uploader__image"
              :src="applyProofImage"
              mode="aspectFill"
              @tap="previewApplyProofImage"
            />
            <view class="proof-uploader__remove" @tap.stop="removeApplyProofImage">
              <text class="proof-uploader__remove-text">×</text>
            </view>
          </view>
          <view v-else class="proof-uploader__add" @tap="chooseApplyProofImage">
            <text class="proof-uploader__add-icon">+</text>
            <text class="proof-uploader__add-text">上传图片</text>
          </view>
        </view>
      </view>
      <view
        class="apply-card__btn"
        :class="{ 'apply-card__btn--disabled': applySubmitting }"
        @tap="submitApply"
      >
        <text class="apply-card__btn-text">{{ applySubmitText }}</text>
      </view>
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

    <view
      v-if="
        isEnabled &&
        !isDistributor &&
        !showApplyForm &&
        !hasPendingApply &&
        qualification.open_mode !== 'amount'
      "
      class="empty-card"
    >
      <text class="empty-card__title">{{
        summary.message || "暂未开通分销员资格"
      }}</text>
    </view>

    <mb-copyright-footer />
    <mb-floating-action />
    <view v-if="applyDetailVisible" class="apply-detail-mask" @tap="closeApplyDetail">
      <view class="apply-detail-panel" @tap.stop>
        <view class="apply-detail-panel__header">
          <text class="apply-detail-panel__title">申请详情</text>
          <text class="apply-detail-panel__close" @tap="closeApplyDetail">关闭</text>
        </view>
        <view class="apply-detail-panel__body">
          <view class="apply-detail-row">
            <text class="apply-detail-row__label">当前状态</text>
            <text class="apply-detail-row__value">{{ latestApply?.status_text || "待审核" }}</text>
          </view>
          <view class="apply-detail-row">
            <text class="apply-detail-row__label">姓名</text>
            <text class="apply-detail-row__value">{{ latestApply?.real_name || "-" }}</text>
          </view>
          <view class="apply-detail-row">
            <text class="apply-detail-row__label">联系电话</text>
            <text class="apply-detail-row__value">{{ latestApply?.mobile || "-" }}</text>
          </view>
          <view class="apply-detail-row apply-detail-row--block">
            <text class="apply-detail-row__label">申请说明</text>
            <text class="apply-detail-row__value">{{ latestApply?.reason || "-" }}</text>
          </view>
          <view v-if="latestApply?.proof_image_full_url" class="apply-detail-proof">
            <text class="apply-detail-row__label">申请凭证</text>
            <image
              class="apply-detail-proof__image"
              :src="latestApply.proof_image_full_url"
              mode="aspectFill"
              @tap="previewCurrentApplyProof"
            />
          </view>
          <view class="apply-detail-row">
            <text class="apply-detail-row__label">提交时间</text>
            <text class="apply-detail-row__value">{{ latestApply?.create_time || "-" }}</text>
          </view>
          <view class="apply-detail-row">
            <text class="apply-detail-row__label">更新时间</text>
            <text class="apply-detail-row__value">{{ latestApply?.update_time || "-" }}</text>
          </view>
        </view>
        <view v-if="hasPendingApply" class="apply-detail-panel__actions">
          <view
            class="apply-detail-panel__btn apply-detail-panel__btn--danger"
            :class="{ 'apply-detail-panel__btn--disabled': applyWithdrawing }"
            @tap="withdrawApply"
          >
            <text class="apply-detail-panel__btn-text apply-detail-panel__btn-text--danger">
              {{ applyWithdrawing ? "撤回中" : "撤回申请" }}
            </text>
          </view>
        </view>
      </view>
    </view>
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
    <view v-if="posterPreviewVisible" class="poster-preview-mask" @tap="closePosterPreview">
      <image
        class="poster-preview-image"
        :src="posterImage"
        mode="widthFix"
        @tap.stop
      />
      <text class="poster-preview-close" @tap.stop="closePosterPreview">关闭</text>
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
  box-sizing: border-box;
  width: 100%;
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
  gap: 1rpx;
  margin-top: 28rpx;
  overflow: hidden;
  background: var(--color-divider, #f0f2f5);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-md;
}

.account-stat {
  flex: 1;
  box-sizing: border-box;
  padding: 20rpx;
  background: var(--color-bg, #ffffff);
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
  gap: 24rpx;
  padding: 28rpx;
}

.invite-card__main {
  min-width: 0;
  flex: 1;
}

.invite-card__code {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.invite-card__actions {
  width: 360rpx;
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12rpx;
  flex-shrink: 0;
}

.invite-card__btn,
.bind-card__btn,
.apply-card__btn {
  box-sizing: border-box;
  height: 64rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary, #0d50d5);
  border-radius: $mb-radius-full;
}

.bind-card__btn {
  width: 132rpx;
  flex-shrink: 0;
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
  background: var(--color-divider, #f0f2f5);
}

.metric-item {
  box-sizing: border-box;
  min-width: 0;
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  text-align: center;
}

.action-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: $mb-spacing-sm;
  padding: 20rpx;
}

.action-item {
  box-sizing: border-box;
  min-width: 0;
  height: 76rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-full;
}

.action-item--primary {
  background: var(--color-primary, #0d50d5);
}

.action-item__label,
.apply-card__btn-text,
.bind-card__btn-text,
.invite-card__btn-text {
  font-size: $mb-font-sm;
  font-weight: 600;
  line-height: 1;
  white-space: nowrap;
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
  box-sizing: border-box;
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

.apply-card__proof {
  margin-top: 20rpx;
}

.apply-card__proof-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $mb-spacing-sm;
}

.apply-card__proof-label {
  color: var(--color-text, #111827);
  font-size: $mb-font-sm;
  font-weight: 600;
}

.apply-card__proof-hint {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-xs;
}

.proof-uploader {
  display: flex;
  margin-top: 16rpx;
}

.proof-uploader__item,
.proof-uploader__add {
  position: relative;
  width: 148rpx;
  height: 148rpx;
  overflow: hidden;
  background: var(--color-bg-surface, #f3f3fe);
  border: 1rpx dashed var(--color-divider, #e5e7eb);
  border-radius: $mb-radius-md;
}

.proof-uploader__image {
  width: 100%;
  height: 100%;
}

.proof-uploader__add {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8rpx;
}

.proof-uploader__add-icon {
  color: var(--color-text-tertiary, #737686);
  font-size: 44rpx;
  line-height: 1;
}

.proof-uploader__add-text {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-xs;
}

.proof-uploader__remove {
  position: absolute;
  top: 8rpx;
  right: 8rpx;
  width: 34rpx;
  height: 34rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(25, 27, 35, 0.7);
  border-radius: $mb-radius-full;
}

.proof-uploader__remove-text {
  color: #ffffff;
  font-size: 22rpx;
  line-height: 1;
}

.apply-card__btn {
  width: 100%;
  margin-top: 20rpx;
}

.apply-card__actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: $mb-spacing-sm;
  margin-top: 22rpx;
}

.apply-card__actions .apply-card__btn {
  margin-top: 0;
}

.apply-card__btn--disabled {
  opacity: 0.7;
}

.apply-card__btn--secondary {
  background: var(--color-bg-surface, #f3f3fe);
}

.apply-card__btn--danger {
  background: rgba(255, 59, 48, 0.08);
}

.apply-card__btn--secondary .apply-card__btn-text {
  color: var(--color-primary, #0d50d5);
}

.apply-card__btn-text--danger {
  color: var(--color-danger, #ff3b30);
}

.apply-card__error {
  display: block;
  margin-top: 10rpx;
  color: var(--color-danger, #ff3b30);
  font-size: $mb-font-xs;
}

.apply-card__status,
.apply-card__status-desc,
.empty-card__desc {
  display: block;
  margin-top: 14rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.bind-card__row {
  gap: $mb-spacing-sm;
  margin-top: 22rpx;
  padding-right: 124rpx;
}

.bind-card__input {
  flex: 1;
  box-sizing: border-box;
  min-width: 0;
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

.apply-detail-mask,
.poster-mask,
.poster-preview-mask {
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

.poster-preview-mask {
  z-index: 1100;
  padding: 48rpx 24rpx;
  background: rgba(0, 0, 0, 0.88);
}

.poster-preview-image {
  width: 100%;
  max-width: 680rpx;
  border-radius: $mb-radius-lg;
}

.poster-preview-close {
  position: fixed;
  right: 32rpx;
  top: 32rpx;
  z-index: 1101;
  padding: 14rpx 24rpx;
  color: #ffffff;
  font-size: $mb-font-sm;
  background: rgba(255, 255, 255, 0.18);
  border-radius: $mb-radius-full;
}

.apply-detail-panel,
.poster-panel {
  box-sizing: border-box;
  width: 100%;
  max-width: 620rpx;
  padding: 28rpx;
  background: var(--color-bg, #ffffff);
  border-radius: $mb-radius-lg;
}

.apply-detail-panel__header,
.poster-panel__header,
.poster-panel__actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.apply-detail-panel__title,
.poster-panel__title {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-md;
  font-weight: 700;
}

.apply-detail-panel__close,
.poster-panel__close {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.apply-detail-panel__body {
  display: flex;
  flex-direction: column;
  gap: 18rpx;
  margin-top: 24rpx;
}

.apply-detail-row {
  display: flex;
  justify-content: space-between;
  gap: $mb-spacing-md;
}

.apply-detail-row--block {
  display: block;
}

.apply-detail-row__label {
  flex-shrink: 0;
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.apply-detail-row__value {
  min-width: 0;
  color: var(--color-text, #111827);
  font-size: $mb-font-sm;
  line-height: 1.6;
  text-align: right;
  word-break: break-all;
}

.apply-detail-row--block .apply-detail-row__label,
.apply-detail-row--block .apply-detail-row__value {
  display: block;
  text-align: left;
}

.apply-detail-row--block .apply-detail-row__value {
  margin-top: 8rpx;
}

.apply-detail-proof {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}

.apply-detail-proof__image {
  width: 180rpx;
  height: 180rpx;
  border-radius: $mb-radius-md;
}

.apply-detail-panel__actions {
  margin-top: 24rpx;
}

.apply-detail-panel__btn {
  height: 72rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 59, 48, 0.08);
  border-radius: $mb-radius-full;
}

.apply-detail-panel__btn--disabled {
  opacity: 0.7;
}

.apply-detail-panel__btn-text {
  font-size: $mb-font-sm;
  font-weight: 600;
}

.apply-detail-panel__btn-text--danger {
  color: var(--color-danger, #ff3b30);
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
  box-sizing: border-box;
  min-width: 0;
  height: 72rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-full;
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
