<template>
  <view
    class="article-detail"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="文章详情" />

    <view v-if="loading" class="article-detail__loading">
      <mb-skeleton type="card" />
      <mb-skeleton type="card" />
    </view>

    <mb-empty-state
      v-else-if="!article.id"
      icon=""
      text="文章不存在或已下架"
    />

    <view v-else class="article-detail__body">
      <text class="article-detail__title">{{ article.title }}</text>
      <view class="article-detail__meta">
        <text v-if="article.category_name">{{ article.category_name }}</text>
        <text>{{ formatDate(article.create_time || article.update_time) }}</text>
        <text>阅读 {{ Number(article.read_count || 0) }}</text>
      </view>

      <image
        v-if="articleCover"
        class="article-detail__cover"
        :src="articleCover"
        mode="widthFix"
      />

      <text v-if="article.description" class="article-detail__desc">
        {{ article.description }}
      </text>

      <view class="article-detail__content">
        <rich-text
          v-if="contentNodes"
          :nodes="contentNodes"
          class="article-detail__rich-text"
        />
      </view>
    </view>
  </view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useDecorateStore } from '@/store/decorate'
import { getArticleDetail } from '@/api/article/article'
import { normalizeRichTextHtml } from '@/utils/rich-text'

const decorateStore = useDecorateStore()
const article = ref({})
const loading = ref(true)

const articleCover = computed(() => (
  article.value?.cover_full_url ||
  (typeof article.value?.cover === 'string' ? article.value.cover : '')
))
const contentNodes = computed(() => normalizeRichTextHtml(article.value?.content || ''))

async function loadDetail(id) {
  loading.value = true
  try {
    article.value = await getArticleDetail(id)
  } catch (error) {
    article.value = {}
  } finally {
    loading.value = false
  }
}

function formatDate(value) {
  if (!value) return ''
  return String(value).slice(0, 10)
}

onLoad((options) => {
  const id = Number(options?.id || 0)
  if (!id) {
    loading.value = false
    return
  }
  loadDetail(id)
})
</script>

<style lang="scss" scoped>
.article-detail {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.article-detail__loading,
.article-detail__body {
  padding: 22rpx $mb-spacing-page 48rpx;
}

.article-detail__body {
  background: var(--color-bg, #ffffff);
}

.article-detail__title {
  display: block;
  color: var(--color-text, #191b23);
  font-size: 42rpx;
  font-weight: 700;
  line-height: 1.35;
}

.article-detail__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
  margin-top: 18rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: 24rpx;
}

.article-detail__cover {
  width: 100%;
  margin-top: 28rpx;
  border-radius: $mb-radius-lg;
}

.article-detail__desc {
  display: block;
  padding: 22rpx;
  margin-top: 26rpx;
  border-left: 6rpx solid var(--color-primary, #0d50d5);
  border-radius: $mb-radius-md;
  background: var(--color-bg-surface, #f3f3fe);
  color: var(--color-text-secondary, #434654);
  font-size: 27rpx;
  line-height: 1.6;
}

.article-detail__content {
  margin-top: 30rpx;
  color: var(--color-text, #191b23);
  font-size: 30rpx;
  line-height: 1.75;
}

.article-detail__rich-text {
  width: 100%;
  max-width: 100%;
  overflow: hidden;
}
</style>
