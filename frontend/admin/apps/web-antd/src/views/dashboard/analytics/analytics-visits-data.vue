<script lang="ts" setup>
import type { EchartsUIType } from '@vben/plugins/echarts';

import type { AnalyticsApi } from '#/api/analytics';

import { onMounted, ref, watch } from 'vue';

import { EchartsUI, useEcharts } from '@vben/plugins/echarts';

const props = defineProps<{
  data?: AnalyticsApi.Health;
}>();
const chartRef = ref<EchartsUIType>();
const { renderEcharts } = useEcharts(chartRef);

function render() {
  const indicators = props.data?.indicators ?? [
    '商品上新',
    '库存维护',
    '订单履约',
    '售后响应',
    '用户增长',
    '营销转化',
  ];

  renderEcharts({
    legend: {
      bottom: 0,
      data: ['本周', '上周'],
    },
    radar: {
      indicator: indicators.map((name) => ({ max: 100, name })),
      radius: '60%',
      splitNumber: 5,
    },
    series: [
      {
        areaStyle: {
          opacity: 1,
          shadowBlur: 0,
          shadowColor: 'rgba(0,0,0,.2)',
          shadowOffsetX: 0,
          shadowOffsetY: 10,
        },
        data: [
          {
            itemStyle: {
              color: '#8b5cf6',
            },
            name: '本周',
            value: props.data?.current ?? indicators.map(() => 0),
          },
          {
            itemStyle: {
              color: '#0ea5e9',
            },
            name: '上周',
            value: props.data?.previous ?? indicators.map(() => 0),
          },
        ],
        itemStyle: {
          borderRadius: 10,
          borderWidth: 2,
        },
        symbolSize: 0,
        type: 'radar',
      },
    ],
    tooltip: {},
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
