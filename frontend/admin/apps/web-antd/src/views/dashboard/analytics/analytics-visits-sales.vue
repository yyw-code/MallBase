<script lang="ts" setup>
import type { EchartsUIType } from '@vben/plugins/echarts';

import type { AnalyticsApi } from '#/api/analytics';

import { computed, onMounted, ref, watch } from 'vue';

import { EchartsUI, useEcharts } from '@vben/plugins/echarts';

const props = defineProps<{
  data?: AnalyticsApi.PieItem[];
}>();
const chartRef = ref<EchartsUIType>();
const { renderEcharts } = useEcharts(chartRef);

const chartData = computed(() =>
  props.data?.length ? props.data : [{ name: '暂无商品', value: 0 }],
);

function render() {
  renderEcharts({
    series: [
      {
        animationDelay() {
          return Math.random() * 400;
        },
        animationEasing: 'exponentialInOut',
        animationType: 'scale',
        center: ['50%', '50%'],
        color: ['#2563eb', '#16a34a', '#f97316', '#dc2626'],
        data: [...chartData.value].toSorted((a, b) => {
          return a.value - b.value;
        }),
        name: '商品结构',
        radius: '80%',
        roseType: 'radius',
        type: 'pie',
      },
    ],

    tooltip: {
      trigger: 'item',
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
