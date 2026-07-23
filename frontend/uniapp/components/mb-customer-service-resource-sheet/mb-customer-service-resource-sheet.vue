<script setup>
import { computed, ref, watch } from 'vue'
import UniIcons from '@dcloudio/uni-ui/lib/uni-icons/uni-icons.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  action: { type: Object, default: null },
  query: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  loadingMore: { type: Boolean, default: false },
  hasMore: { type: Boolean, default: false },
  sendingToken: { type: String, default: '' },
})

const emit = defineEmits(['close', 'search', 'load-more', 'select', 'update:query'])
const selectedToken = ref('')

const selectedCandidate = computed(() => (
  props.items.find((candidate) => candidate?.candidateToken === selectedToken.value) || null
))
const resourceKind = computed(() => {
  const value = `${props.action?.resourceDefinitionCode || ''} ${props.action?.label || ''}`.toLowerCase()
  return /(order|订单)/.test(value) ? 'order' : 'product'
})
const resourceIcon = computed(() => (resourceKind.value === 'order' ? 'list' : 'shop'))

watch(
  () => [props.visible, props.action?.code],
  ([visible]) => {
    if (visible) selectedToken.value = ''
  },
)

watch(
  () => props.items,
  (items) => {
    if (
      selectedToken.value
      && !items.some((candidate) => candidate?.candidateToken === selectedToken.value)
    ) {
      selectedToken.value = ''
    }
  },
)

function updateQuery(event) {
  emit('update:query', String(event?.detail?.value || ''))
}

function submitSearch() {
  if (props.loading) return
  selectedToken.value = ''
  emit('search')
}

function selectCandidate(candidate) {
  if (!candidate?.candidateToken || props.sendingToken) return
  selectedToken.value = candidate.candidateToken
}

function confirmSelection() {
  if (!selectedCandidate.value || props.sendingToken) return
  emit('select', selectedCandidate.value)
}

function candidateImage(candidate) {
  const components = Array.isArray(candidate?.display?.canvas?.components)
    ? candidate.display.canvas.components
    : []
  const image = components.find((item) => (
    item?.type === 'image'
      && !item.hidden
      && typeof item.src === 'string'
      && /^https?:\/\//i.test(item.src)
  ))
  return image?.src || ''
}

function candidateFields(candidate) {
  const title = String(candidate?.display?.title || '').trim()
  return (Array.isArray(candidate?.display?.fields) ? candidate.display.fields : [])
    .filter((field) => field?.label && field?.value)
    .filter((field) => String(field.value).trim() !== title)
    .slice(0, 3)
}

function candidateFieldClass(field) {
  const value = `${field?.key || ''} ${field?.label || ''} ${field?.valueType || ''}`.toLowerCase()
  return /(price|amount|money|total|价格|金额|实付)/.test(value) ? 'is-primary' : ''
}
</script>

<template>
  <view v-if="visible" class="resource-picker">
    <view class="resource-picker__safe-top" />
    <view class="resource-picker__header">
      <view
        class="resource-picker__back"
        hover-class="resource-picker__control--active"
        @tap="emit('close')"
      >
        <uni-icons type="back" size="27" color="#191b23" />
      </view>
      <view class="resource-picker__heading">
        <text class="resource-picker__title">选择{{ action?.label || '资源' }}</text>
      </view>
      <view class="resource-picker__header-placeholder" />
    </view>

    <view class="resource-picker__search-wrap">
      <view class="resource-picker__search">
        <uni-icons type="search" size="21" color="#737686" />
        <input
          class="resource-picker__search-input"
          :value="query"
          :placeholder="`搜索${action?.label || '资源'}`"
          confirm-type="search"
          :maxlength="80"
          @input="updateQuery"
          @confirm="submitSearch"
        />
        <view
          class="resource-picker__search-button"
          :class="{ 'is-disabled': loading }"
          hover-class="resource-picker__control--active"
          @tap="submitSearch"
        >
          <text>{{ loading ? '搜索中' : '搜索' }}</text>
        </view>
      </view>
    </view>

    <scroll-view class="resource-picker__list" scroll-y :show-scrollbar="false">
      <view v-if="loading && !items.length" class="resource-picker__state">
        <view class="resource-picker__spinner" />
        <text>正在加载{{ action?.label || '资源' }}…</text>
      </view>
      <view v-else-if="!items.length" class="resource-picker__state">
        <view class="resource-picker__state-icon">
          <uni-icons :type="resourceIcon" size="30" color="#8a8f9d" />
        </view>
        <text>没有找到可发送的{{ action?.label || '资源' }}</text>
        <text class="resource-picker__state-subtitle">可以换个关键词再试</text>
      </view>
      <view v-else class="resource-picker__items">
        <view
          v-for="candidate in items"
          :key="candidate.candidateToken"
          class="resource-candidate"
          :class="{
            'is-selected': selectedToken === candidate.candidateToken,
            'is-sending': sendingToken === candidate.candidateToken,
          }"
          hover-class="resource-candidate--active"
          @tap="selectCandidate(candidate)"
        >
          <image
            v-if="candidateImage(candidate)"
            class="resource-candidate__image"
            :src="candidateImage(candidate)"
            mode="aspectFill"
          />
          <view v-else class="resource-candidate__image resource-candidate__image--empty">
            <uni-icons :type="resourceIcon" size="29" color="#0d50d5" />
          </view>
          <view class="resource-candidate__content">
            <view class="resource-candidate__topline">
              <text class="resource-candidate__title">{{ candidate.display.title }}</text>
              <text v-if="candidate.display.status" class="resource-candidate__status">
                {{ candidate.display.status }}
              </text>
            </view>
            <text v-if="candidate.display.summary" class="resource-candidate__summary">
              {{ candidate.display.summary }}
            </text>
            <view v-if="candidateFields(candidate).length" class="resource-candidate__fields">
              <text
                v-for="field in candidateFields(candidate)"
                :key="field.key || field.label"
                class="resource-candidate__field"
                :class="candidateFieldClass(field)"
              >{{ field.label }} {{ field.value }}</text>
            </view>
          </view>
          <view class="resource-candidate__select">
            <uni-icons
              v-if="selectedToken === candidate.candidateToken"
              type="checkmarkempty"
              size="19"
              color="#ffffff"
            />
          </view>
        </view>

        <view
          v-if="hasMore"
          class="resource-picker__more"
          :class="{ 'is-disabled': loadingMore }"
          hover-class="resource-picker__control--active"
          @tap="emit('load-more')"
          >
            <text>{{ loadingMore ? '加载中…' : '加载更多' }}</text>
          </view>
          <view class="resource-picker__note">
            <uni-icons type="info" size="18" color="#8a8f9d" />
            <text>发送后，客服可查看该{{ action?.label || '资源' }}信息</text>
          </view>
        </view>
      </scroll-view>

    <view class="resource-picker__footer">
      <view class="resource-picker__selection">
        <text v-if="selectedCandidate" class="resource-picker__selection-value">
          已选择 <text class="resource-picker__selection-count">1</text> 件{{ action?.label || '资源' }}
        </text>
        <text v-else class="resource-picker__selection-value">请选择一项</text>
      </view>
      <view
        class="resource-picker__confirm"
        :class="{ 'is-disabled': !selectedCandidate || sendingToken }"
        hover-class="resource-picker__control--active"
        @tap="confirmSelection"
      >
        <text>{{ sendingToken ? '发送中' : '发送给客服' }}</text>
      </view>
    </view>
    <view class="resource-picker__safe-bottom" />
  </view>
</template>

<style scoped>
.resource-picker {
  position: fixed;
  z-index: 1200;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--color-bg-secondary, #faf8ff);
  color: var(--color-text, #191b23);
}

.resource-picker__safe-top {
  height: env(safe-area-inset-top);
  flex-shrink: 0;
  background: var(--color-bg, #ffffff);
}

.resource-picker__header {
  display: flex;
  min-height: 104rpx;
  flex-shrink: 0;
  align-items: center;
  border-bottom: 1rpx solid var(--color-divider, #eef0f4);
  background: var(--color-bg, #ffffff);
}

.resource-picker__back,
.resource-picker__header-placeholder {
  display: flex;
  width: 104rpx;
  height: 104rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
}

.resource-picker__heading {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  align-items: center;
  gap: 2rpx;
}

.resource-picker__title {
  overflow: hidden;
  max-width: 100%;
  color: var(--color-text, #191b23);
  font-size: 31rpx;
  font-weight: 650;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.resource-picker__search-wrap {
  flex-shrink: 0;
  padding: 20rpx 24rpx 14rpx;
  background: var(--color-bg, #ffffff);
}

.resource-picker__search {
  display: flex;
  min-height: 80rpx;
  align-items: center;
  gap: 12rpx;
  padding-left: 22rpx;
  border: 1rpx solid var(--color-divider, rgba(148, 163, 184, 0.24));
  border-radius: 40rpx;
  background: var(--color-bg-secondary, #f5f7fb);
  box-sizing: border-box;
}

.resource-picker__search-input {
  height: 80rpx;
  min-width: 0;
  flex: 1;
  color: var(--color-text, #191b23);
  font-size: 26rpx;
}

.resource-picker__search-button {
  display: flex;
  min-width: 96rpx;
  height: 64rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  margin-right: 8rpx;
  border-radius: 32rpx;
  background: var(--color-primary, #0d50d5);
  color: #ffffff;
  font-size: 23rpx;
  font-weight: 600;
}

.resource-picker__search-button.is-disabled,
.resource-picker__confirm.is-disabled {
  opacity: 0.45;
}

.resource-picker__list {
  height: 0;
  min-height: 0;
  flex: 1;
}

.resource-picker__state {
  display: flex;
  min-height: 500rpx;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 18rpx;
  padding: 40rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: 25rpx;
  text-align: center;
  box-sizing: border-box;
}

.resource-picker__state-icon {
  display: flex;
  width: 96rpx;
  height: 96rpx;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--color-bg, #ffffff);
}

.resource-picker__state-subtitle {
  font-size: 22rpx;
}

.resource-picker__spinner {
  width: 42rpx;
  height: 42rpx;
  border: 4rpx solid rgba(13, 80, 213, 0.12);
  border-top-color: var(--color-primary, #0d50d5);
  border-radius: 50%;
  animation: resource-picker-spin 720ms linear infinite;
}

.resource-picker__items {
  padding: 20rpx 24rpx 30rpx;
}

.resource-candidate {
  display: flex;
  min-height: 156rpx;
  align-items: center;
  gap: 18rpx;
  padding: 18rpx;
  margin-bottom: 18rpx;
  border: 2rpx solid transparent;
  border-radius: 22rpx;
  background: var(--color-bg, #ffffff);
  box-shadow: 0 8rpx 24rpx rgba(15, 23, 42, 0.05);
  box-sizing: border-box;
}

.resource-candidate.is-selected {
  border-color: var(--color-primary, #0d50d5);
  background: rgba(13, 80, 213, 0.035);
}

.resource-candidate--active,
.resource-candidate.is-sending {
  opacity: 0.72;
}

.resource-candidate__image {
  display: flex;
  width: 116rpx;
  height: 116rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 16rpx;
  background: var(--color-bg-secondary, #f5f7fb);
}

.resource-candidate__image--empty {
  border: 1rpx solid rgba(13, 80, 213, 0.1);
  background: rgba(13, 80, 213, 0.06);
}

.resource-candidate__content {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 8rpx;
}

.resource-candidate__topline {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 10rpx;
}

.resource-candidate__title {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  color: var(--color-text, #191b23);
  font-size: 27rpx;
  font-weight: 650;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.resource-candidate__status {
  flex-shrink: 0;
  padding: 4rpx 10rpx;
  border-radius: 999rpx;
  background: rgba(13, 80, 213, 0.08);
  color: var(--color-primary, #0d50d5);
  font-size: 20rpx;
}

.resource-candidate__summary {
  display: -webkit-box;
  overflow: hidden;
  color: var(--color-text-secondary, #596273);
  font-size: 23rpx;
  line-height: 1.4;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.resource-candidate__fields {
  display: flex;
  flex-wrap: wrap;
  gap: 7rpx 14rpx;
}

.resource-candidate__field {
  color: var(--color-text-tertiary, #737686);
  font-size: 21rpx;
}

.resource-candidate__field.is-primary {
  color: var(--color-primary, #0d50d5);
  font-size: 24rpx;
  font-weight: 650;
}

.resource-candidate__select {
  display: flex;
  width: 44rpx;
  height: 44rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border: 2rpx solid var(--color-divider, #d8dce5);
  border-radius: 50%;
  box-sizing: border-box;
}

.is-selected .resource-candidate__select {
  border-color: var(--color-primary, #0d50d5);
  background: var(--color-primary, #0d50d5);
}

.resource-picker__more {
  display: flex;
  height: 88rpx;
  align-items: center;
  justify-content: center;
  color: var(--color-primary, #0d50d5);
  font-size: 24rpx;
}

.resource-picker__more.is-disabled {
  opacity: 0.5;
}

.resource-picker__note {
  display: flex;
  min-height: 76rpx;
  align-items: center;
  gap: 10rpx;
  padding: 0 12rpx;
  color: var(--color-text-tertiary, #737686);
  font-size: 22rpx;
  box-sizing: border-box;
}

.resource-picker__footer {
  display: flex;
  min-height: 116rpx;
  flex-shrink: 0;
  align-items: center;
  gap: 20rpx;
  padding: 14rpx 24rpx;
  border-top: 1rpx solid var(--color-divider, #eef0f4);
  background: var(--color-bg, #ffffff);
  box-sizing: border-box;
}

.resource-picker__selection {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 4rpx;
}

.resource-picker__selection-value {
  overflow: hidden;
  color: var(--color-text, #191b23);
  font-size: 24rpx;
  font-weight: 600;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.resource-picker__selection-count {
  color: var(--color-primary, #0d50d5);
  font-size: 31rpx;
  font-weight: 700;
}

.resource-picker__confirm {
  display: flex;
  min-width: 230rpx;
  height: 80rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 40rpx;
  background: var(--color-primary, #0d50d5);
  color: #ffffff;
  font-size: 25rpx;
  font-weight: 650;
}

.resource-picker__safe-bottom {
  height: env(safe-area-inset-bottom);
  flex-shrink: 0;
  background: var(--color-bg, #ffffff);
}

.resource-picker__control--active {
  opacity: 0.72;
}

@keyframes resource-picker-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
