<script lang="ts" setup>
import type { EchartsUIType } from '@vben/plugins/echarts';

import type { AnalyticsApi } from '#/api/analytics';

import { onMounted, ref, watch } from 'vue';

import { EchartsUI, useEcharts } from '@vben/plugins/echarts';

const props = defineProps<{
  data?: AnalyticsApi.MonthlyOrders;
}>();
const chartRef = ref<EchartsUIType>();
const { renderEcharts } = useEcharts(chartRef);

function render() {
  renderEcharts({
    grid: {
      bottom: 0,
      containLabel: true,
      left: '1%',
      right: '1%',
      top: '2%',
    },
    series: [
      {
        barMaxWidth: 80,
        data: props.data?.orders ?? [],
        itemStyle: {
          color: '#6366f1',
        },
        name: '支付订单',
        type: 'bar',
      },
    ],
    tooltip: {
      axisPointer: {
        lineStyle: {
          color: '#6366f1',
          width: 1,
        },
      },
      trigger: 'axis',
    },
    xAxis: {
      data: props.data?.labels ?? [],
      type: 'category',
    },
    yAxis: {
      minInterval: 1,
      splitNumber: 4,
      type: 'value',
    },
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
