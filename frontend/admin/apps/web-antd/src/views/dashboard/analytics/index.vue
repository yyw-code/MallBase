<script lang="ts" setup>
import type { AnalysisOverviewItem } from '@vben/common-ui';
import type { TabOption } from '@vben/types';

import type { AnalyticsApi } from '#/api/analytics';

import { computed, markRaw, onMounted, ref } from 'vue';

import { useAccess } from '@vben/access';
import {
  AnalysisChartCard,
  AnalysisChartsTabs,
  AnalysisOverview,
} from '@vben/common-ui';
import {
  SvgBellIcon,
  SvgCakeIcon,
  SvgCardIcon,
  SvgDownloadIcon,
} from '@vben/icons';

import {
  getAnalyticsCardsApi,
  getAnalyticsHealthApi,
  getAnalyticsMonthlyOrdersApi,
  getAnalyticsOrderChannelsApi,
  getAnalyticsSalesStructureApi,
  getAnalyticsTrendApi,
} from '#/api/analytics';

import AnalyticsTrends from './analytics-trends.vue';
import AnalyticsVisitsData from './analytics-visits-data.vue';
import AnalyticsVisitsSales from './analytics-visits-sales.vue';
import AnalyticsVisitsSource from './analytics-visits-source.vue';
import AnalyticsVisits from './analytics-visits.vue';

const overviewItems = ref<AnalysisOverviewItem[]>([]);
const trend = ref<AnalyticsApi.Trend>();
const monthlyOrders = ref<AnalyticsApi.MonthlyOrders>();
const health = ref<AnalyticsApi.Health>();
const orderChannels = ref<AnalyticsApi.PieItem[]>();
const salesStructure = ref<AnalyticsApi.PieItem[]>();
const { hasAccessByCodes } = useAccess();

const defaultOverviewIcon = markRaw(SvgCardIcon);
const overviewIconMap: Record<string, AnalysisOverviewItem['icon']> = {
  gmv: defaultOverviewIcon,
  orders: markRaw(SvgCakeIcon),
  users: markRaw(SvgDownloadIcon),
  refunds: markRaw(SvgBellIcon),
};

const hasCards = computed(() => overviewItems.value.length > 0);
const hasTrend = computed(() => Boolean(trend.value));
const hasMonthlyOrders = computed(() => Boolean(monthlyOrders.value));
const hasHealth = computed(() => Boolean(health.value));
const hasOrderChannels = computed(() =>
  Boolean(orderChannels.value && orderChannels.value.length > 0),
);
const hasSalesStructure = computed(() =>
  Boolean(salesStructure.value && salesStructure.value.length > 0),
);
const hasAnyAnalyticsBlock = computed(() =>
  [
    hasCards.value,
    hasTrend.value,
    hasMonthlyOrders.value,
    hasHealth.value,
    hasOrderChannels.value,
    hasSalesStructure.value,
  ].some(Boolean),
);

const chartTabs = computed<TabOption[]>(() => {
  const tabs: TabOption[] = [];
  if (hasTrend.value) {
    tabs.push({ label: '交易趋势', value: 'trends' });
  }
  if (hasMonthlyOrders.value) {
    tabs.push({ label: '月度订单', value: 'visits' });
  }
  return tabs;
});

function isPermissionDenied(error: unknown): boolean {
  const response = (error as any)?.response;
  const code = (error as any)?.code;
  const message = (error as any)?.message || response?.data?.message;
  return (
    response?.status === 403 ||
    response?.data?.code === 403 ||
    code === 403 ||
    message === '没有权限访问该接口'
  );
}

function buildOverviewItems(
  cards: AnalyticsApi.Card[],
): AnalysisOverviewItem[] {
  return cards.map((item) => ({
    icon: overviewIconMap[item.key] ?? defaultOverviewIcon,
    title: item.title,
    totalTitle: item.total_title,
    totalValue: item.total_value,
    value: item.value,
  }));
}

async function loadData() {
  const loaders: Array<{
    code: string;
    key: string;
    request: () => Promise<any>;
  }> = [
    {
      code: 'SystemAnalyticsCards',
      key: 'cards',
      request: getAnalyticsCardsApi,
    },
    {
      code: 'SystemAnalyticsTrend',
      key: 'trend',
      request: getAnalyticsTrendApi,
    },
    {
      code: 'SystemAnalyticsMonthlyOrders',
      key: 'monthlyOrders',
      request: getAnalyticsMonthlyOrdersApi,
    },
    {
      code: 'SystemAnalyticsHealth',
      key: 'health',
      request: getAnalyticsHealthApi,
    },
    {
      code: 'SystemAnalyticsOrderChannels',
      key: 'orderChannels',
      request: getAnalyticsOrderChannelsApi,
    },
    {
      code: 'SystemAnalyticsSalesStructure',
      key: 'salesStructure',
      request: getAnalyticsSalesStructureApi,
    },
  ].filter((loader) => hasAccessByCodes([loader.code]));

  const result = await Promise.allSettled(
    loaders.map((loader) => loader.request()),
  );

  result.forEach((entry, index) => {
    const loader = loaders[index];
    if (!loader) {
      return;
    }

    if (entry.status === 'rejected') {
      if (!isPermissionDenied(entry.reason)) {
        console.error(
          `加载${loader.key}失败:`,
          entry.reason?.response?.data?.message ||
            entry.reason?.message ||
            entry.reason,
        );
      }
      return;
    }

    const data = entry.value;
    switch (loader.key) {
      case 'cards': {
        overviewItems.value = buildOverviewItems(data || []);

        break;
      }
      case 'health': {
        health.value = data;

        break;
      }
      case 'monthlyOrders': {
        monthlyOrders.value = data;

        break;
      }
      case 'orderChannels': {
        orderChannels.value = data;

        break;
      }
      case 'salesStructure': {
        salesStructure.value = data;

        break;
      }
      case 'trend': {
        trend.value = data;

        break;
      }
      // No default
    }
  });

  // 不再走聚合接口兜底，保证每个区块权限完全可控
}

onMounted(() => {
  void loadData();
});
</script>

<template>
  <div class="p-5">
    <div v-if="hasCards" class="mb-6">
      <AnalysisOverview :items="overviewItems" />
    </div>
    <div v-else-if="!hasAnyAnalyticsBlock">
      <a-empty description="暂无可访问的统计数据" />
    </div>

    <AnalysisChartsTabs v-if="chartTabs.length > 0" :tabs="chartTabs">
      <template #trends>
        <AnalyticsTrends :data="trend" />
      </template>
      <template #visits>
        <AnalyticsVisits :data="monthlyOrders" />
      </template>
    </AnalysisChartsTabs>

    <div class="mt-5 w-full lg:flex lg:space-x-4">
      <AnalysisChartCard
        v-if="hasHealth"
        class="mt-5 lg:mt-0 lg:w-1/3"
        title="运营健康度"
      >
        <AnalyticsVisitsData :data="health" />
      </AnalysisChartCard>
      <AnalysisChartCard
        v-if="hasOrderChannels"
        class="mt-5 lg:mt-0 lg:w-1/3"
        title="订单来源"
      >
        <AnalyticsVisitsSource :data="orderChannels" />
      </AnalysisChartCard>
      <AnalysisChartCard
        v-if="hasSalesStructure"
        class="mt-5 lg:mt-0 lg:w-1/3"
        title="商品结构"
      >
        <AnalyticsVisitsSales :data="salesStructure" />
      </AnalysisChartCard>
    </div>
  </div>
</template>
