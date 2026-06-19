<script lang="ts" setup>
import type { EchartsUIType } from '@vben/plugins/echarts';

import type { AnalyticsApi } from '#/api/analytics';

import { onMounted, ref, watch } from 'vue';

import { EchartsUI, useEcharts } from '@vben/plugins/echarts';

const props = defineProps<{
  data?: AnalyticsApi.Trend;
}>();
const chartRef = ref<EchartsUIType>();
const { renderEcharts } = useEcharts(chartRef);

function render() {
  const labels = props.data?.labels ?? [];
  const amount = props.data?.amount ?? [];
  const orders = props.data?.orders ?? [];

  renderEcharts({
    grid: {
      bottom: 0,
      containLabel: true,
      left: '1%',
      right: '1%',
      top: '8%',
    },
    legend: {
      data: ['成交额', '支付订单'],
      top: 0,
    },
    series: [
      {
        areaStyle: {},
        data: amount,
        itemStyle: {
          color: '#3b82f6',
        },
        name: '成交额',
        smooth: true,
        type: 'line',
      },
      {
        areaStyle: {},
        data: orders,
        itemStyle: {
          color: '#16a34a',
        },
        name: '支付订单',
        smooth: true,
        type: 'line',
        yAxisIndex: 1,
      },
    ],
    tooltip: {
      axisPointer: {
        lineStyle: {
          color: '#16a34a',
          width: 1,
        },
      },
      trigger: 'axis',
    },
    xAxis: {
      axisTick: {
        show: false,
      },
      boundaryGap: false,
      data: labels,
      splitLine: {
        lineStyle: {
          type: 'solid',
          width: 1,
        },
        show: true,
      },
      type: 'category',
    },
    yAxis: [
      {
        axisTick: {
          show: false,
        },
        minInterval: 1,
        name: '成交额',
        splitArea: {
          show: true,
        },
        splitNumber: 4,
        type: 'value',
      },
      {
        axisTick: {
          show: false,
        },
        minInterval: 1,
        name: '订单',
        splitNumber: 4,
        type: 'value',
      },
    ],
  });
}

onMounted(() => {
  render();
});

watch(
  () => props.data,
  () => render(),
  { deep: true },
);
</script>

<template>
  <EchartsUI ref="chartRef" />
</template>
