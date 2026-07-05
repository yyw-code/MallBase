<script setup>
import { useDecorateStore } from "@/store/decorate";
import { computed, ref } from "vue";
import { onLoad, onPullDownRefresh, onReachBottom } from "@dcloudio/uni-app";
import {
  getDistributionCommissions,
  getDistributionLogs,
} from "@/api/distribution/distribution";

const decorateStore = useDecorateStore();

const tabs = [
  { key: "commissions", label: "佣金订单" },
  { key: "logs", label: "佣金流水" },
];
const commissionStatuses = [
  { key: "", label: "全部" },
  { key: 10, label: "冻结" },
  { key: 20, label: "待结算" },
  { key: 30, label: "已结算" },
  { key: 80, label: "已扣回" },
];
const logDirections = [
  { key: "", label: "全部" },
  { key: "income", label: "收入" },
  { key: "expense", label: "支出" },
];

const activeTab = ref("commissions");
const activeStatus = ref("");
const activeDirection = ref("");
const list = ref([]);
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const finished = ref(false);

const filters = computed(() =>
  activeTab.value === "commissions" ? commissionStatuses : logDirections,
);

const activeFilter = computed(() =>
  activeTab.value === "commissions" ? activeStatus.value : activeDirection.value,
);

const groupedList = computed(() => {
  const groups = [];
  const map = {};
  list.value.forEach((item) => {
    const label = dateLabel(item.create_time);
    if (!map[label]) {
      map[label] = { label, items: [] };
      groups.push(map[label]);
    }
    map[label].items.push(item);
  });
  return groups;
});

onLoad((query) => {
  if (tabs.some((item) => item.key === query?.tab)) {
    activeTab.value = query.tab;
  }
  fetchRecords(true);
});

onPullDownRefresh(async () => {
  await fetchRecords(true);
  uni.stopPullDownRefresh();
});

onReachBottom(() => {
  if (!finished.value && !loading.value) fetchRecords(false);
});

async function fetchRecords(reset) {
  if (loading.value) return;
  if (reset) {
    page.value = 1;
    finished.value = false;
  }

  loading.value = true;
  try {
    const params = {
      page: page.value,
      limit: 20,
    };
    const data =
      activeTab.value === "commissions"
        ? await getDistributionCommissions({
            ...params,
            status: activeStatus.value,
          })
        : await getDistributionLogs({
            ...params,
            direction: activeDirection.value,
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

function switchTab(tab) {
  if (activeTab.value === tab) return;
  activeTab.value = tab;
  list.value = [];
  fetchRecords(true);
}

function switchFilter(key) {
  if (activeTab.value === "commissions") {
    if (activeStatus.value === key) return;
    activeStatus.value = key;
  } else {
    if (activeDirection.value === key) return;
    activeDirection.value = key;
  }
  fetchRecords(true);
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

function formatAmount(value) {
  return Number(value || 0).toFixed(2);
}

function commissionTitle(item) {
  return item.order_sn ? `订单 ${item.order_sn}` : "订单佣金";
}

function commissionDesc(item) {
  const level = Number(item.relation_level || 0);
  return `${level === 2 ? "二级" : "一级"}佣金 · ${item.status_text || ""}`;
}

function logTitle(item) {
  return item.biz_type_text || item.remark || "佣金变动";
}

function logDesc(item) {
  return item.biz_id || item.create_time || "";
}

function logAmount(item) {
  const income = item.direction === "income";
  return {
    income,
    text: `${income ? "+" : "-"}¥${formatAmount(item.change_amount)}`,
  };
}
</script>

<template>
  <view
    class="page"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="佣金明细" bg-color="var(--color-bg, #ffffff)" />

    <view class="tabs">
      <view
        v-for="tab in tabs"
        :key="tab.key"
        class="tab"
        :class="{ 'tab--active': activeTab === tab.key }"
        @tap="switchTab(tab.key)"
      >
        <text class="tab__text">{{ tab.label }}</text>
      </view>
    </view>

    <view class="filter-row">
      <view
        v-for="item in filters"
        :key="item.key"
        class="filter-chip"
        :class="{ 'filter-chip--active': activeFilter === item.key }"
        @tap="switchFilter(item.key)"
      >
        <text class="filter-chip__text">{{ item.label }}</text>
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
              :class="{
                'record-row__icon--income':
                  activeTab === 'logs' ? logAmount(item).income : true,
              }"
            >
              <text class="record-row__icon-text">{{
                activeTab === "logs" && !logAmount(item).income ? "-" : "+"
              }}</text>
            </view>
            <view class="record-row__main">
              <text class="record-row__title">{{
                activeTab === "commissions"
                  ? commissionTitle(item)
                  : logTitle(item)
              }}</text>
              <text class="record-row__desc">{{
                activeTab === "commissions"
                  ? commissionDesc(item)
                  : logDesc(item)
              }}</text>
              <text
                v-if="activeTab === 'commissions' && item.release_time"
                class="record-row__desc"
              >
                释放时间 {{ item.release_time }}
              </text>
            </view>
            <view class="record-row__right">
              <text
                class="record-row__amount"
                :class="{
                  'record-row__amount--income':
                    activeTab === 'logs' ? logAmount(item).income : true,
                }"
              >
                {{
                  activeTab === "commissions"
                    ? `+¥${formatAmount(item.amount)}`
                    : logAmount(item).text
                }}
              </text>
              <text
                v-if="activeTab === 'logs' && item.after_amount !== undefined"
                class="record-row__balance"
              >
                余额 ¥{{ formatAmount(item.after_amount) }}
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
      <text class="empty__title">暂无记录</text>
    </view>

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
.filter-row,
.record-card,
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

.filter-row {
  display: flex;
  gap: $mb-spacing-sm;
  margin-top: $mb-spacing-sm;
  padding: 16rpx;
}

.filter-chip {
  padding: 12rpx 20rpx;
  background: var(--color-bg-surface, #f3f3fe);
  border-radius: $mb-radius-full;
}

.filter-chip--active {
  background: var(--color-primary-soft, rgba(13, 80, 213, 0.1));
}

.filter-chip__text {
  color: var(--color-text-secondary, #434654);
  font-size: $mb-font-xs;
}

.filter-chip--active .filter-chip__text {
  color: var(--color-primary, #0d50d5);
  font-weight: 700;
}

.record-list {
  margin-top: $mb-spacing-md;
}

.record-group {
  margin-bottom: $mb-spacing-md;
}

.record-group__date {
  display: block;
  margin: 0 0 12rpx 4rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-xs;
}

.record-card {
  overflow: hidden;
}

.record-row {
  display: flex;
  align-items: center;
  gap: $mb-spacing-sm;
  padding: 24rpx;
  border-bottom: 1rpx solid var(--color-divider, #f0f2f5);
}

.record-row:last-child {
  border-bottom: 0;
}

.record-row__icon {
  width: 56rpx;
  height: 56rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-danger, #ff4d4f);
  background: rgba(255, 77, 79, 0.1);
  border-radius: 50%;
}

.record-row__icon--income {
  color: var(--color-success, #34c759);
  background: var(--color-success-soft, rgba(52, 199, 89, 0.1));
}

.record-row__icon-text {
  font-size: $mb-font-md;
  font-weight: 700;
}

.record-row__main {
  flex: 1;
  min-width: 0;
}

.record-row__title {
  color: var(--color-text-title, #191b23);
  font-size: $mb-font-sm;
  font-weight: 700;
}

.record-row__desc,
.record-row__balance,
.load-state__text {
  display: block;
  margin-top: 6rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: $mb-font-xs;
}

.record-row__right {
  min-width: 150rpx;
  text-align: right;
}

.record-row__amount {
  color: var(--color-danger, #ff4d4f);
  font-size: $mb-font-sm;
  font-weight: 700;
}

.record-row__amount--income {
  color: var(--color-success, #34c759);
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
