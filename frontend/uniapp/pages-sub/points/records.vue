<script setup>
import { useDecorateStore } from "@/store/decorate";
import { computed, ref } from "vue";
import { onLoad, onPullDownRefresh, onReachBottom } from "@dcloudio/uni-app";
import { getPointsLogs } from "@/api/points/points";
import { isPointsEnabled, leavePointsPage } from "@/utils/points-feature";

const decorateStore = useDecorateStore();

const tabs = [
  { key: "", label: "全部" },
  { key: "income", label: "收入" },
  { key: "expense", label: "支出" },
];
const ranges = [
  { key: "month", label: "本月" },
  { key: "three_months", label: "近三月" },
  { key: "custom", label: "全部" },
];

const activeType = ref("");
const activeBizType = ref("");
const activeRange = ref("month");
const list = ref([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const finished = ref(false);
const pointsEnabled = ref(true);

const groupedList = computed(() => {
  const groups = [];
  const map = {};
  list.value.forEach((item) => {
    const label = dateLabel(item.create_time || item.time);
    if (!map[label]) {
      map[label] = { label, items: [] };
      groups.push(map[label]);
    }
    map[label].items.push(item);
  });
  return groups;
});

onLoad(async (query) => {
  activeType.value = query?.type || "";
  activeBizType.value = query?.biz_type || "";
  if (ranges.some((item) => item.key === query?.range)) {
    activeRange.value = query.range;
  }
  if (await ensurePointsEnabled()) {
    fetchLogs(true);
  }
});

onPullDownRefresh(async () => {
  if (await ensurePointsEnabled()) {
    await fetchLogs(true);
  }
  uni.stopPullDownRefresh();
});

onReachBottom(() => {
  if (pointsEnabled.value && !finished.value && !loading.value) fetchLogs(false);
});

async function ensurePointsEnabled() {
  pointsEnabled.value = await isPointsEnabled();
  if (!pointsEnabled.value) {
    leavePointsPage();
    return false;
  }
  return true;
}

async function fetchLogs(reset) {
  if (!pointsEnabled.value) return;
  if (loading.value) return;
  if (reset) {
    page.value = 1;
    finished.value = false;
  }

  loading.value = true;
  try {
    const data = await getPointsLogs({
      page: page.value,
      limit: 20,
      type: activeType.value,
      biz_type: activeBizType.value,
      range: activeRange.value,
    });
    const rows = Array.isArray(data?.list) ? data.list : [];
    total.value = Number(data?.total || rows.length || 0);
    list.value = reset ? rows : list.value.concat(rows);
    finished.value = list.value.length >= total.value || rows.length === 0;
    if (!finished.value) page.value += 1;
  } catch {
    if (reset) list.value = [];
    finished.value = true;
  } finally {
    loading.value = false;
  }
}

function switchType(type) {
  if (activeType.value === type) return;
  activeType.value = type;
  fetchLogs(true);
}

function switchRange(range) {
  activeRange.value = range;
  fetchLogs(true);
}

function dateLabel(value) {
  if (!value) return "更早";
  const date = String(value).slice(0, 10);
  const now = new Date();
  const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  if (date === today) return "今天";
  const [, month, day] = date.split("-");
  return `${month}月${day}日`;
}

function pad(value) {
  return String(value).padStart(2, "0");
}

function signedPoints(item) {
  const value = Number(item.change_points || 0);
  const direction = String(item.direction || "");
  const isIncome = direction === "income";
  return {
    text: `${isIncome ? "+" : "-"}${Math.abs(value)}`,
    income: isIncome,
  };
}

function titleOf(item) {
  return item.title || item.biz_type_text || item.remark || "积分变动";
}

function descOf(item) {
  return item.biz_sn || item.order_sn || item.biz_id || item.create_time || "";
}

function releaseTimeOf(item) {
  if (
    item.biz_type !== "order_complete" ||
    item.account_type !== "frozen" ||
    !item.release_time
  ) {
    return "";
  }
  return `解冻时间 ${item.release_time}`;
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="积分明细" bg-color="var(--color-bg, #ffffff)" />

    <view class="filter-panel">
      <view class="tabs">
        <view
          v-for="tab in tabs"
          :key="tab.key"
          class="tab"
          :class="{ 'tab--active': activeType === tab.key }"
          @tap="switchType(tab.key)"
        >
          <text class="tab__text">{{ tab.label }}</text>
        </view>
      </view>

      <view class="filter-row">
        <view
          v-for="range in ranges"
          :key="range.key"
          class="filter-chip"
          :class="{ 'filter-chip--active': activeRange === range.key }"
          @tap="switchRange(range.key)"
        >
          <text class="filter-chip__text">{{ range.label }}</text>
        </view>
      </view>
    </view>

    <view v-if="groupedList.length" class="record-list">
      <view
        v-for="group in groupedList"
        :key="group.label"
        class="record-group"
      >
        <text class="record-group__date">{{ group.label }}</text>
        <view class="record-card">
          <view
            v-for="item in group.items"
            :key="item.id || item.create_time"
            class="record-row"
          >
            <view
              class="record-row__icon"
              :class="{ 'record-row__icon--income': signedPoints(item).income }"
            >
              <text class="record-row__icon-text">{{
                signedPoints(item).income ? "+" : "-"
              }}</text>
            </view>
            <view class="record-row__main">
              <text class="record-row__title">{{ titleOf(item) }}</text>
              <text class="record-row__desc">{{ descOf(item) }}</text>
              <text v-if="releaseTimeOf(item)" class="record-row__release">
                {{ releaseTimeOf(item) }}
              </text>
            </view>
            <view class="record-row__right">
              <text
                class="record-row__amount"
                :class="{
                  'record-row__amount--income': signedPoints(item).income,
                }"
              >
                {{ signedPoints(item).text }}
              </text>
              <text
                v-if="item.after_points !== undefined"
                class="record-row__balance"
              >
                余额 {{ item.after_points }}
              </text>
            </view>
          </view>
        </view>
      </view>

      <view class="load-state">
        <text class="load-state__text">{{
          finished ? "没有更多记录了" : "加载中..."
        }}</text>
      </view>
    </view>

    <view v-else class="empty">
      <view class="empty__points">
        <text class="empty__points-icon">P</text>
      </view>
      <text class="empty__title">暂无积分记录</text>
      <text class="empty__desc">换个时间范围试试</text>

      <view class="empty-filter">
        <view class="empty-filter__row">
          <text class="empty-filter__label">类型</text>
          <text class="empty-filter__value">
            {{ tabs.find((item) => item.key === activeType)?.label || "全部" }}
          </text>
        </view>
        <view class="empty-filter__row">
          <text class="empty-filter__label">时间</text>
          <text class="empty-filter__value">
            {{
              ranges.find((item) => item.key === activeRange)?.label || "本月"
            }}
          </text>
        </view>
      </view>
    </view>
    <mb-copyright-footer />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  padding: 0 $mb-spacing-page 48rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.filter-panel {
  margin-top: $mb-spacing-md;
  padding: 12rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.tabs,
.filter-row {
  display: flex;
  gap: 12rpx;
}

.tabs {
  padding: 6rpx;
  background: var(--color-bg-surface, #f8fafc);
  border-radius: $mb-radius-full;
}

.tab,
.filter-chip {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
}

.tab {
  flex: 1;
  height: 64rpx;
  background: transparent;
  border-color: transparent;
  border-radius: $mb-radius-full;
}

.tab--active {
  background: var(--color-primary, #0d50d5);
  border-color: var(--color-primary, #0d50d5);
}

.tab__text,
.filter-chip__text {
  color: var(--color-text, #111827);
  font-size: 26rpx;
  font-weight: 700;
}

.tab--active .tab__text,
.filter-chip--active .filter-chip__text {
  color: #ffffff;
}

.filter-row {
  margin: 14rpx 4rpx 0;
}

.filter-chip {
  height: 54rpx;
  padding: 0 26rpx;
  background: var(--color-bg-surface, #f8fafc);
  border-radius: $mb-radius-full;
}

.filter-chip--active {
  background: var(--color-primary, #0d50d5);
  border-color: var(--color-primary, #0d50d5);
}

.record-group {
  margin-top: 24rpx;
  margin-bottom: 22rpx;
}

.record-group__date {
  display: block;
  margin-bottom: 12rpx;
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.record-card {
  padding: 0 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.record-row {
  display: flex;
  align-items: center;
  gap: 18rpx;
  padding: 24rpx 0;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
}

.record-row:last-child {
  border-bottom: none;
}

.record-row__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 58rpx;
  height: 58rpx;
  background: #f97316;
  border-radius: 50%;
}

.record-row__icon--income {
  background: #16a34a;
}

.record-row__icon-text {
  color: #ffffff;
  font-size: 30rpx;
  font-weight: 800;
}

.record-row__main {
  flex: 1;
  min-width: 0;
}

.record-row__title {
  display: block;
  color: var(--color-text, #111827);
  font-size: 28rpx;
  font-weight: 700;
}

.record-row__desc,
.record-row__release,
.record-row__balance,
.load-state__text,
.empty__desc,
.empty-filter__label {
  color: var(--color-text-muted, #6b7280);
  font-size: 24rpx;
}

.record-row__desc {
  display: block;
  margin-top: 6rpx;
}

.record-row__release {
  display: inline-block;
  margin-top: 10rpx;
  padding: 5rpx 14rpx;
  color: var(--color-primary, #0d50d5);
  font-size: 22rpx;
  background: rgba(13, 80, 213, 0.12);
  border-radius: $mb-radius-full;
}

.record-row__right {
  text-align: right;
}

.record-row__amount {
  display: block;
  color: #f97316;
  font-size: 30rpx;
  font-weight: 800;
}

.record-row__amount--income {
  color: #16a34a;
}

.load-state {
  padding: 24rpx 0;
  text-align: center;
}

.empty {
  display: flex;
  align-items: center;
  flex-direction: column;
  margin-top: 90rpx;
  text-align: center;
}

.empty__points {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 96rpx;
  height: 96rpx;
  background: var(--color-primary, #0d50d5);
  border-radius: 50%;
}

.empty__points-icon {
  color: #ffffff;
  font-size: 42rpx;
  font-weight: 800;
}

.empty__title {
  margin-top: 24rpx;
  color: var(--color-text, #111827);
  font-size: 30rpx;
  font-weight: 700;
}

.empty__desc {
  margin-top: 8rpx;
}

.empty-filter {
  width: 100%;
  margin-top: 28rpx;
  padding: 24rpx 28rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
}

.empty-filter__row {
  display: flex;
  justify-content: space-between;
  padding: 10rpx 0;
}

.empty-filter__value {
  color: var(--color-text, #111827);
  font-size: 24rpx;
  font-weight: 700;
}
</style>
