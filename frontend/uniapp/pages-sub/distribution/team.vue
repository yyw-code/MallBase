<script setup>
import { useDecorateStore } from "@/store/decorate";
import { computed, ref } from "vue";
import { onPullDownRefresh, onReachBottom } from "@dcloudio/uni-app";
import { getDistributionTeam } from "@/api/distribution/distribution";

const decorateStore = useDecorateStore();

const levels = [
  { key: 1, label: "一级团队" },
  { key: 2, label: "二级团队" },
];

const activeLevel = ref(1);
const list = ref([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const finished = ref(false);
const featureClosed = ref(false);

const totalText = computed(() => Number(total.value || 0));

fetchTeam(true);

onPullDownRefresh(async () => {
  await fetchTeam(true);
  uni.stopPullDownRefresh();
});

onReachBottom(() => {
  if (!featureClosed.value && !finished.value && !loading.value) {
    fetchTeam(false);
  }
});

async function fetchTeam(reset) {
  if (loading.value) return;
  if (reset) {
    page.value = 1;
    finished.value = false;
  }

  loading.value = true;
  try {
    const data = await getDistributionTeam({
      level: activeLevel.value,
      page: page.value,
      limit: 20,
    });
    const rows = Array.isArray(data?.list) ? data.list : [];
    featureClosed.value = false;
    total.value = Number(data?.total || rows.length || 0);
    list.value = reset ? rows : list.value.concat(rows);
    finished.value = list.value.length >= total.value || rows.length === 0;
    if (!finished.value) page.value += 1;
  } catch (error) {
    featureClosed.value = isDistributionClosedError(error);
    if (reset) list.value = [];
    finished.value = true;
  } finally {
    loading.value = false;
  }
}

function isDistributionClosedError(error) {
  return String(error?.message || "").includes("分销功能未开启");
}

function switchLevel(level) {
  if (featureClosed.value) return;
  if (activeLevel.value === level) return;
  activeLevel.value = level;
  fetchTeam(true);
}

function userName(item) {
  return item.user?.nickname || item.user?.mobile || `用户 ${item.user_id}`;
}

function userMobile(item) {
  return item.user?.mobile || "";
}

function avatarText(item) {
  const name = userName(item);
  return String(name || "用").slice(0, 1);
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="我的团队" bg-color="var(--color-bg, #ffffff)" />

    <view v-if="featureClosed" class="empty">
      <text class="empty__title">分销功能未开启</text>
    </view>

    <view v-if="!featureClosed" class="tabs">
      <view
        v-for="item in levels"
        :key="item.key"
        class="tab"
        :class="{ 'tab--active': activeLevel === item.key }"
        @tap="switchLevel(item.key)"
      >
        <text class="tab__text">{{ item.label }}</text>
      </view>
    </view>

    <view v-if="!featureClosed" class="summary-card">
      <text class="summary-card__label">{{
        activeLevel === 1 ? "一级团队人数" : "二级团队人数"
      }}</text>
      <text class="summary-card__value">{{ totalText }}</text>
    </view>

    <view v-if="!featureClosed && list.length" class="team-list">
      <view
        v-for="item in list"
        :key="item.id || item.user_id"
        class="team-row"
      >
        <image
          v-if="item.user?.avatar"
          class="team-row__avatar"
          :src="item.user.avatar"
          mode="aspectFill"
        />
        <view v-else class="team-row__avatar team-row__avatar--text">
          <text class="team-row__avatar-text">{{ avatarText(item) }}</text>
        </view>
        <view class="team-row__main">
          <text class="team-row__name">{{ userName(item) }}</text>
          <text v-if="userMobile(item)" class="team-row__desc">{{
            userMobile(item)
          }}</text>
        </view>
        <text class="team-row__time">{{ item.create_time }}</text>
      </view>

      <view class="load-state">
        <text class="load-state__text">{{
          finished ? "没有更多成员了" : "加载中..."
        }}</text>
      </view>
    </view>

    <view v-else-if="!featureClosed" class="empty">
      <text class="empty__title">暂无团队成员</text>
    </view>

    <mb-copyright-footer />
    <mb-floating-action />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 56rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.tabs,
.summary-card,
.team-list,
.empty {
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.tabs {
  display: flex;
  margin-top: $mb-spacing-md;
  padding: 6rpx;
}

.tab {
  flex: 1;
  height: 64rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: $mb-radius-md;
}

.tab--active {
  background: var(--color-primary, #0d50d5);
}

.tab__text {
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-sm;
  font-weight: 600;
}

.tab--active .tab__text {
  color: #ffffff;
}

.summary-card {
  margin-top: $mb-spacing-md;
  padding: 32rpx;
}

.summary-card__label {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}

.summary-card__value {
  display: block;
  margin-top: 8rpx;
  color: var(--color-text-title, #191b23);
  font-size: 52rpx;
  font-weight: 700;
}

.team-list {
  margin-top: $mb-spacing-md;
  overflow: hidden;
}

.team-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  padding: 24rpx;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
}

.team-row:last-child {
  border-bottom: 0;
}

.team-row__avatar {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
}

.team-row__avatar--text {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.1));
}

.team-row__avatar-text {
  color: var(--color-primary, #0d50d5);
  font-size: $mb-font-md;
  font-weight: 700;
}

.team-row__main {
  flex: 1;
  min-width: 0;
}

.team-row__name {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-sm;
  font-weight: 700;
}

.team-row__desc,
.team-row__time,
.load-state__text {
  display: block;
  margin-top: 6rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-xs;
}

.team-row__time {
  max-width: 180rpx;
  text-align: right;
}

.load-state {
  padding: 20rpx 0;
  text-align: center;
}

.empty {
  margin-top: $mb-spacing-md;
  padding: 64rpx 24rpx;
  text-align: center;
}

.empty__title {
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-sm;
}
</style>
