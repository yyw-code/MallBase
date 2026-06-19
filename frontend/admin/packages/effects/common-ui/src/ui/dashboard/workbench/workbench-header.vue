<script lang="ts" setup>
import { VbenAvatar } from '@vben-core/shadcn-ui';

interface Props {
  avatar?: string;
  stats?: {
    label: string;
    value: string | number;
  }[];
}

defineOptions({
  name: 'WorkbenchHeader',
});

const props = withDefaults(defineProps<Props>(), {
  avatar: '',
  stats: () => [],
});
</script>
<template>
  <div class="card-box p-4 py-6 lg:flex">
    <VbenAvatar :src="avatar" class="size-20" />
    <div
      v-if="$slots.title || $slots.description"
      class="flex flex-col justify-center md:ml-6 md:mt-0"
    >
      <h1 v-if="$slots.title" class="text-md font-semibold md:text-xl">
        <slot name="title"></slot>
      </h1>
      <span v-if="$slots.description" class="text-foreground/80 mt-1">
        <slot name="description"></slot>
      </span>
    </div>
    <div
      v-if="$slots.stats || props.stats.length > 0"
      class="mt-4 flex flex-1 justify-end md:mt-0"
    >
      <slot v-if="$slots.stats" name="stats"></slot>
      <template v-else>
        <div
          v-for="(item, index) in props.stats"
          :key="item.label"
          :class="[ 'flex flex-col justify-center text-right', index ? 'mx-12 md:mx-16' : '' ]"
        >
          <span class="text-foreground/80"> {{ item.label }} </span>
          <span class="text-2xl">{{ item.value }}</span>
        </div>
      </template>
    </div>
  </div>
</template>
