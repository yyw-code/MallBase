<script lang="ts" setup>
import type { EchartsUIType } from '@vben/plugins/echarts';

import type { DistributionApi } from '#/api/distribution';

import { nextTick, onMounted, ref } from 'vue';

import { EchartsUI, useEcharts } from '@vben/plugins/echarts';
import { message } from 'ant-design-vue';

import {
  getDistributionOverviewApi,
  releaseDistributionDueApi,
} from '#/api/distribution';

defineOptions({ name: 'DistributionOverview' });

const loading = ref(false);
const defaultOverview = (): DistributionApi.Overview => ({
  available_commission: '0.00',
  commission_total: 0,
  distributor_total: 0,
  enabled_distributor_total: 0,
  frozen_commission: '0.00',
  pending_withdraw: '0.00',
  region_distribution: [],
  status_distribution: [],
  trend: {
    amount: [],
    labels: [],
    orders: [],
  },
});

const overview = ref<DistributionApi.Overview>(defaultOverview());
const trendChartRef = ref<EchartsUIType>();
const regionChartRef = ref<EchartsUIType>();
const statusChartRef = ref<EchartsUIType>();
const { renderEcharts: renderTrendChart } = useEcharts(trendChartRef);
const { renderEcharts: renderRegionChart } = useEcharts(regionChartRef);
const { renderEcharts: renderStatusChart } = useEcharts(statusChartRef);

const statCards = [
  { key: 'distributor_total', label: '分销员总数' },
  { key: 'enabled_distributor_total', label: '启用分销员' },
  { key: 'commission_total', label: '佣金记录' },
  { key: 'frozen_commission', label: '冻结佣金' },
  { key: 'available_commission', label: '可提现佣金' },
  { key: 'pending_withdraw', label: '提现中' },
];

function renderCharts() {
  const trend = overview.value.trend ?? defaultOverview().trend;
  const regions = overview.value.region_distribution ?? [];
  const statuses = overview.value.status_distribution ?? [];

  renderTrendChart({
    grid: {
      bottom: 28,
      left: 36,
      right: 36,
      top: 42,
    },
    legend: {
      data: ['佣金金额', '订单数'],
      top: 0,
    },
    series: [
      {
        areaStyle: {},
        data: trend.amount,
        itemStyle: {
          color: '#2563eb',
        },
        name: '佣金金额',
        smooth: true,
        type: 'line',
      },
      {
        data: trend.orders,
        itemStyle: {
          color: '#16a34a',
        },
        name: '订单数',
        smooth: true,
        type: 'line',
        yAxisIndex: 1,
      },
    ],
    tooltip: {
      trigger: 'axis',
    },
    xAxis: {
      boundaryGap: false,
      data: trend.labels,
      type: 'category',
    },
    yAxis: [
      {
        minInterval: 1,
        name: '佣金',
        type: 'value',
      },
      {
        minInterval: 1,
        name: '订单',
        type: 'value',
      },
    ],
  });

  renderRegionChart({
    grid: {
      bottom: 24,
      left: 72,
      right: 28,
      top: 18,
    },
    series: [
      {
        data: regions.map((item) => item.amount),
        itemStyle: {
          color: '#f97316',
        },
        name: '佣金金额',
        type: 'bar',
      },
    ],
    tooltip: {
      formatter(params: any) {
        const index = params?.dataIndex ?? 0;
        const item = regions[index];
        if (!item) return '';
        return `${item.name}<br/>佣金金额：${item.amount}<br/>订单数：${item.order_count}<br/>佣金记录：${item.commission_count}`;
      },
      trigger: 'item',
    },
    xAxis: {
      minInterval: 1,
      name: '佣金',
      type: 'value',
    },
    yAxis: {
      data: regions.map((item) => item.name),
      type: 'category',
    },
  });

  renderStatusChart({
    legend: {
      bottom: 0,
      left: 'center',
    },
    series: [
      {
        color: ['#2563eb', '#16a34a', '#f97316', '#dc2626', '#64748b'],
        data: statuses.length
          ? statuses.map((item) => ({ name: item.name, value: item.value }))
          : [{ name: '暂无佣金', value: 0 }],
        name: '佣金状态',
        radius: ['45%', '68%'],
        type: 'pie',
      },
    ],
    tooltip: {
      trigger: 'item',
    },
  });
}

async function loadData() {
  loading.value = true;
  try {
    const overviewData = await getDistributionOverviewApi();
    overview.value = overviewData;
    await nextTick();
    renderCharts();
  } finally {
    loading.value = false;
  }
}

async function releaseDue() {
  const result: any = await releaseDistributionDueApi();
  message.success(`已结算 ${result?.released ?? 0} 条到期佣金`);
  await loadData();
}

onMounted(() => {
  void loadData();
});
</script>

<template>
  <div class="p-4">
    <div class="mb-3 flex items-center justify-between gap-4">
      <h2 class="m-0 text-lg font-semibold">分销概览</h2>
      <div class="flex gap-2">
        <a-button @click="loadData">刷新</a-button>
        <a-button
          v-access:code="'SystemDistributionReleaseDue'"
          @click="releaseDue"
        >
          手动结算到期佣金
        </a-button>
      </div>
    </div>

    <a-spin :spinning="loading">
      <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <div
          v-for="card in statCards"
          :key="card.key"
          class="rounded-lg border bg-[hsl(var(--card))] p-4"
        >
          <div class="text-sm text-muted-foreground">{{ card.label }}</div>
          <div class="mt-2 text-2xl font-semibold">
            {{ (overview as any)[card.key] }}
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-3 xl:grid-cols-3">
        <div
          class="rounded-lg border bg-[hsl(var(--card))] p-4 xl:col-span-2"
        >
          <div class="mb-3 text-base font-medium">近 7 日佣金趋势</div>
          <div class="h-72">
            <EchartsUI ref="trendChartRef" class="size-full" />
          </div>
        </div>
        <div class="rounded-lg border bg-[hsl(var(--card))] p-4">
          <div class="mb-3 text-base font-medium">佣金状态占比</div>
          <div class="h-72">
            <EchartsUI ref="statusChartRef" class="size-full" />
          </div>
        </div>
      </div>

      <div class="mt-3 rounded-lg border bg-[hsl(var(--card))] p-4">
        <div class="mb-3 text-base font-medium">地区分布</div>
        <div class="h-80">
          <EchartsUI ref="regionChartRef" class="size-full" />
        </div>
      </div>
    </a-spin>
  </div>
</template>
