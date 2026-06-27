<template>
  <view
    class="article-list"
    :class="[`theme-${decorateStore.resolvedThemeMode}`]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar title="文章列表" />

    <view class="article-list__filter">
      <scroll-view scroll-x class="article-list__category-scroll" :show-scrollbar="false">
        <view class="article-list__category-row">
          <view
            class="article-list__category"
            :class="{ 'article-list__category--active': !query.category_id }"
            @tap="selectCategory('')"
          >
            <text>全部</text>
          </view>
          <view
            v-for="item in categories"
            :key="item.id"
            class="article-list__category"
            :class="{ 'article-list__category--active': String(query.category_id) === String(item.id) }"
            @tap="selectCategory(item.id)"
          >
            <text>{{ item.name }}</text>
          </view>
        </view>
      </scroll-view>
    </view>

    <view v-if="initialLoading" class="article-list__skeleton">
      <mb-skeleton v-for="item in 4" :key="item" type="card" />
    </view>

    <mb-empty-state
      v-else-if="!initialLoading && articles.length === 0"
      icon=""
      text="暂无文章"
    />

    <view v-else class="article-list__content">
      <view
        v-for="item in articles"
        :key="item.id"
        class="article-card"
        @tap="goDetail(item.id)"
      >
        <image
          v-if="articleCover(item)"
          class="article-card__cover"
          :src="articleCover(item)"
          mode="aspectFill"
          lazy-load
        />
        <view v-else class="article-card__cover article-card__cover--empty">
          <text>文章</text>
        </view>
        <view class="article-card__body">
          <view class="article-card__header">
            <text class="article-card__title">{{ item.title }}</text>
            <text v-if="item.category_name" class="article-card__category">
              {{ item.category_name }}
            </text>
          </view>
          <text v-if="item.description" class="article-card__desc">
            {{ item.description }}
          </text>
          <view class="article-card__meta">
            <text>{{ formatDate(item.create_time || item.update_time) }}</text>
            <text>阅读 {{ Number(item.read_count || 0) }}</text>
          </view>
        </view>
      </view>

      <view class="article-list__bottom">
        <view v-if="loading" class="article-list__loading">
          <view class="article-list__spinner" />
          <text>加载中...</text>
        </view>
        <text v-else-if="noMore">没有更多了</text>
      </view>
    </view>
  </view>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useDecorateStore } from '@/store/decorate'
import { getArticleCategories, getArticleList } from '@/api/article/article'

const decorateStore = useDecorateStore()

const query = reactive({
  category_id: '',
  keyword: '',
})

const categories = ref([])
const articles = ref([])
const page = ref(1)
const limit = 10
const total = ref(0)
const loading = ref(false)
const initialLoading = ref(true)
const noMore = ref(false)

function normalizeOptions(options = {}) {
  query.category_id = options.category_id || ''
  query.keyword = options.keyword || ''
}

async function loadCategories() {
  try {
    categories.value = await getArticleCategories()
  } catch (error) {
    categories.value = []
  }
}

async function loadArticles(reset = false) {
  if (loading.value) return
  if (!reset && noMore.value) return

  loading.value = true
  if (reset) {
    page.value = 1
    noMore.value = false
  }

  try {
    const res = await getArticleList({
      category_id: query.category_id || undefined,
      keyword: query.keyword || undefined,
      limit,
      page: page.value,
    })
    const list = Array.isArray(res?.list) ? res.list : []
    total.value = Number(res?.total || 0)
    articles.value = reset ? list : articles.value.concat(list)
    noMore.value = articles.value.length >= total.value || list.length < limit
    page.value += 1
  } catch (error) {
    if (reset) {
      articles.value = []
      total.value = 0
    }
  } finally {
    loading.value = false
    initialLoading.value = false
    uni.stopPullDownRefresh()
  }
}

function selectCategory(categoryId) {
  if (String(query.category_id || '') === String(categoryId || '')) return
  query.category_id = categoryId ? String(categoryId) : ''
  initialLoading.value = true
  loadArticles(true)
}

function articleCover(item) {
  return item?.cover_full_url || (typeof item?.cover === 'string' ? item.cover : '')
}

function formatDate(value) {
  if (!value) return ''
  return String(value).slice(0, 10)
}

function goDetail(id) {
  if (!id) return
  uni.navigateTo({ url: `/pages-sub/article/detail?id=${id}` })
}

onLoad(async (options) => {
  normalizeOptions(options)
  await loadCategories()
  await loadArticles(true)
})

onPullDownRefresh(() => {
  loadCategories()
  loadArticles(true)
})

onReachBottom(() => {
  loadArticles(false)
})
</script>

<style lang="scss" scoped>
.article-list {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.article-list__filter {
  position: sticky;
  top: 0;
  z-index: 2;
  padding: 18rpx $mb-spacing-page 14rpx;
  background: var(--color-bg-secondary, #faf8ff);
}

.article-list__category-scroll {
  width: 100%;
  white-space: nowrap;
}

.article-list__category-row {
  display: inline-flex;
  gap: 16rpx;
}

.article-list__category {
  display: flex;
  align-items: center;
  height: 58rpx;
  padding: 0 28rpx;
  border: 1rpx solid var(--color-border, #e0e4e8);
  border-radius: 29rpx;
  background: var(--color-bg, #ffffff);
  color: var(--color-text-secondary, #434654);
  font-size: 25rpx;
}

.article-list__category--active {
  border-color: var(--color-primary, #0d50d5);
  background: var(--color-primary, #0d50d5);
  color: #ffffff;
  font-weight: 600;
}

.article-list__skeleton,
.article-list__content {
  padding: 8rpx $mb-spacing-page 32rpx;
}

.article-list__skeleton {
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.article-card {
  display: flex;
  gap: 22rpx;
  padding: 22rpx;
  margin-bottom: 18rpx;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: $mb-radius-lg;
  background: var(--color-bg, #ffffff);
}

.article-card__cover {
  flex-shrink: 0;
  width: 180rpx;
  height: 136rpx;
  border-radius: $mb-radius-md;
  background: var(--color-bg-surface, #f3f3fe);
}

.article-card__cover--empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-tertiary, #737686);
  font-size: 24rpx;
}

.article-card__body {
  display: flex;
  flex: 1;
  min-width: 0;
  flex-direction: column;
}

.article-card__header {
  display: flex;
  align-items: flex-start;
  gap: 12rpx;
}

.article-card__title {
  flex: 1;
  min-width: 0;
  color: var(--color-text, #191b23);
  font-size: 30rpx;
  font-weight: 600;
  line-height: 1.45;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.article-card__category {
  flex-shrink: 0;
  max-width: 140rpx;
  padding: 4rpx 12rpx;
  border-radius: 18rpx;
  background: var(--color-bg-surface, #f3f3fe);
  color: var(--color-primary, #0d50d5);
  font-size: 21rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.article-card__desc {
  margin-top: 10rpx;
  color: var(--color-text-secondary, #434654);
  font-size: 24rpx;
  line-height: 1.45;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.article-card__meta {
  display: flex;
  justify-content: space-between;
  gap: 16rpx;
  margin-top: auto;
  padding-top: 14rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: 22rpx;
}

.article-list__bottom {
  display: flex;
  justify-content: center;
  padding: 22rpx 0 36rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: 24rpx;
}

.article-list__loading {
  display: flex;
  align-items: center;
  gap: 12rpx;
}

.article-list__spinner {
  width: 24rpx;
  height: 24rpx;
  border: 3rpx solid var(--color-divider, #f0f2f5);
  border-top-color: var(--color-primary, #0d50d5);
  border-radius: 50%;
  animation: article-spin 0.8s linear infinite;
}

@keyframes article-spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
