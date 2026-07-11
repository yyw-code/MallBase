<script lang="ts" setup>
import { computed } from 'vue';

interface Props {
  companyName?: string;
  companySiteLink?: string;
  date?: string;
  icp?: string;
  icpLink?: string;
  psb?: string;
  psbLink?: string;
}

defineOptions({
  name: 'Copyright',
});

const props = withDefaults(defineProps<Props>(), {
  companyName: 'Vben Admin',
  companySiteLink: '',
  date: '2024',
  icp: '',
  icpLink: '',
  psb: '',
  psbLink: '',
});

const displayDate = computed(() => props.date.replace(/^©\s*/u, '').trim());
</script>

<template>
  <div class="text-md flex-center flex-wrap gap-x-2 gap-y-1">
    <span>Copyright © {{ displayDate }}</span>
    <a
      v-if="companyName"
      :href="companySiteLink || 'javascript:void(0)'"
      class="hover:text-primary-hover"
      target="_blank"
    >
      {{ companyName }}
    </a>
    <a
      v-if="icp"
      :href="icpLink || 'javascript:void(0)'"
      class="hover:text-primary-hover"
      target="_blank"
    >
      {{ icp }}
    </a>
    <a
      v-if="psb"
      :href="psbLink || 'javascript:void(0)'"
      class="hover:text-primary-hover"
      target="_blank"
    >
      {{ psb }}
    </a>
  </div>
</template>
