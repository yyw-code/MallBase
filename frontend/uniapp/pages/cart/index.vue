<script setup>
import { ref, computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useCartStore } from '@/store/cart'
import { isLoggedIn } from '@/utils/auth'

const cartStore = useCartStore()

const list = computed(() => cartStore.list)
const loading = computed(() => cartStore.loading)
const allSelected = computed(() => cartStore.allSelected)
const selectedCount = computed(() => cartStore.selectedCount)
const totalPrice = computed(() => cartStore.totalPrice)
const isEmpty = computed(() => !loading.value && list.value.length === 0)

/** Valid (purchasable) items */
const validItems = computed(() => list.value.filter((i) => !i.is_invalid))

/** Invalid (expired/out-of-stock) items */
const invalidItems = computed(() => list.value.filter((i) => i.is_invalid))

/** Edit mode toggle */
const isEditing = ref(false)

function toggleEditMode() {
  isEditing.value = !isEditing.value
}

// ---------- lifecycle ----------

onShow(() => {
  if (isLoggedIn()) {
    cartStore.fetchList()
  }
})

// ---------- selection ----------

function onToggleItem(item) {
  if (item.is_invalid) return
  cartStore.toggleSelected([item.id], !item.selected)
}

function onToggleAll() {
  cartStore.toggleAll(!allSelected.value)
}

// ---------- quantity ----------

/** Debounce map to avoid rapid-fire API calls */
const quantityTimers = {}

function onQuantityChange(item, quantity) {
  clearTimeout(quantityTimers[item.id])
  quantityTimers[item.id] = setTimeout(() => {
    cartStore.updateQuantity(item.id, quantity)
  }, 300)
}

// ---------- delete ----------

function onDeleteItem(item) {
  uni.showModal({
    title: '提示',
    content: `确定删除「${item.goods_name}」吗？`,
    confirmColor: '#e5484d',
    success: (res) => {
      if (res.confirm) {
        cartStore.remove([item.id])
      }
    },
  })
}

function onDeleteSelected() {
  const ids = list.value.filter((i) => i.selected).map((i) => i.id)
  if (ids.length === 0) {
    uni.showToast({ title: '请先选择商品', icon: 'none' })
    return
  }
  uni.showModal({
    title: '提示',
    content: `确定删除已选的 ${ids.length} 件商品吗？`,
    confirmColor: '#e5484d',
    success: (res) => {
      if (res.confirm) {
        cartStore.remove(ids)
      }
    },
  })
}

function onClearInvalid() {
  const ids = invalidItems.value.map((i) => i.id)
  if (ids.length === 0) return
  uni.showModal({
    title: '提示',
    content: '确定清除全部失效商品吗？',
    confirmColor: '#e5484d',
    success: (res) => {
      if (res.confirm) {
        cartStore.remove(ids)
      }
    },
  })
}

function onLongPressItem(item) {
  uni.showActionSheet({
    itemList: ['删除该商品'],
    success: (res) => {
      if (res.tapIndex === 0) {
        onDeleteItem(item)
      }
    },
  })
}

// ---------- navigation ----------

function goShopping() {
  uni.switchTab({ url: '/pages/index/index' })
}

function goCheckout() {
  if (selectedCount.value === 0) return
  uni.navigateTo({ url: '/pages-sub/order/confirm?source=cart' })
}

function goGoodsDetail(item) {
  if (item.is_invalid) return
  uni.navigateTo({ url: `/pages-sub/goods/detail?id=${item.goods_id}` })
}
</script>

<template>
  <view class="page">
    <!-- ========== Navbar ========== -->
    <mb-navbar title="购物车" :back="false" :accent-line="false">
      <template #right>
        <text
          v-if="!isEmpty"
          class="navbar-edit"
          @tap="toggleEditMode"
        >
          {{ isEditing ? '完成' : '编辑' }}
        </text>
      </template>
    </mb-navbar>

    <!-- ========== Empty State ========== -->
    <mb-empty-state
      v-if="isEmpty"
      icon="🛒"
      text="购物车是空的"
      actionText="去逛逛"
      paddingTop="360rpx"
      @action="goShopping"
    />

    <!-- ========== Loading Skeleton ========== -->
    <view v-if="loading && list.length === 0" class="skeleton-wrap">
      <view v-for="n in 3" :key="n" class="skeleton-card">
        <view class="skeleton-checkbox" />
        <view class="skeleton-image" />
        <view class="skeleton-info">
          <view class="skeleton-line skeleton-line--long" />
          <view class="skeleton-line skeleton-line--short" />
          <view class="skeleton-line skeleton-line--medium" />
        </view>
      </view>
    </view>

    <!-- ========== Cart List ========== -->
    <view v-if="!isEmpty && !loading" class="cart-list">
      <!-- Valid items -->
      <view
        v-for="item in validItems"
        :key="item.id"
        class="cart-card"
        @longpress="onLongPressItem(item)"
      >
        <view class="cart-card__body">
          <!-- Checkbox -->
          <view class="cart-card__checkbox" @tap="onToggleItem(item)">
            <view
              class="checkbox"
              :class="{ 'checkbox--checked': item.selected }"
            >
              <text v-if="item.selected" class="checkbox__tick">&#x2713;</text>
            </view>
          </view>

          <!-- Product image -->
          <view class="cart-card__image-wrap" @tap="goGoodsDetail(item)">
            <image
              class="cart-card__image"
              :src="item.goods_image"
              mode="aspectFill"
              lazy-load
            />
          </view>

          <!-- Product info -->
          <view class="cart-card__info">
            <text class="cart-card__name" @tap="goGoodsDetail(item)">
              {{ item.goods_name }}
            </text>
            <text v-if="item.sku_spec" class="cart-card__spec">
              {{ item.sku_spec }}
            </text>
            <view class="cart-card__bottom">
              <mb-price
                :value="item.unit_price"
                size="md"
                color="#131b2e"
              />
              <mb-quantity-stepper
                v-if="!isEditing"
                v-model="item.quantity"
                :max="item.stock"
                @change="onQuantityChange(item, $event)"
              />
              <view
                v-else
                class="cart-card__delete-btn"
                @tap="onDeleteItem(item)"
              >
                <text class="cart-card__delete-text">删除</text>
              </view>
            </view>
          </view>
        </view>
      </view>

      <!-- Invalid items section -->
      <view v-if="invalidItems.length > 0" class="invalid-section">
        <view class="invalid-section__header">
          <text class="invalid-section__title">失效商品</text>
          <text class="invalid-section__clear" @tap="onClearInvalid">清除</text>
        </view>
        <view
          v-for="item in invalidItems"
          :key="item.id"
          class="cart-card cart-card--invalid"
        >
          <view class="cart-card__body">
            <!-- Disabled checkbox placeholder -->
            <view class="cart-card__checkbox">
              <view class="checkbox checkbox--disabled" />
            </view>

            <!-- Product image with invalid badge -->
            <view class="cart-card__image-wrap">
              <image
                class="cart-card__image cart-card__image--faded"
                :src="item.goods_image"
                mode="aspectFill"
                lazy-load
              />
              <view class="cart-card__invalid-badge">
                <text class="cart-card__invalid-text">已失效</text>
              </view>
            </view>

            <!-- Product info -->
            <view class="cart-card__info">
              <text class="cart-card__name cart-card__name--faded">
                {{ item.goods_name }}
              </text>
              <text v-if="item.sku_spec" class="cart-card__spec">
                {{ item.sku_spec }}
              </text>
              <view class="cart-card__bottom">
                <mb-price :value="item.unit_price" size="md" color="#b0b0b0" />
                <view class="cart-card__delete-btn" @tap="onDeleteItem(item)">
                  <text class="cart-card__delete-text">删除</text>
                </view>
              </view>
            </view>
          </view>
        </view>
      </view>

      <!-- Bottom spacer so last item isn't hidden behind bottom bar -->
      <view class="cart-list__spacer" />
    </view>

    <!-- ========== Bottom Bar ========== -->
    <view v-if="!isEmpty" class="bottom-bar">
      <view class="bottom-bar__inner">
        <!-- Select all -->
        <view class="bottom-bar__select-all" @tap="onToggleAll">
          <view
            class="checkbox"
            :class="{ 'checkbox--checked': allSelected }"
          >
            <text v-if="allSelected" class="checkbox__tick">&#x2713;</text>
          </view>
          <text class="bottom-bar__select-label">全选</text>
        </view>

        <!-- Total price or delete selected (edit mode) -->
        <view v-if="!isEditing" class="bottom-bar__total">
          <text class="bottom-bar__total-label">合计</text>
          <mb-price :value="totalPrice" size="lg" color="#1b1b1b" />
        </view>
        <view v-else class="bottom-bar__total" />

        <!-- Checkout / Delete button -->
        <view
          v-if="!isEditing"
          class="bottom-bar__checkout"
          :class="{ 'bottom-bar__checkout--disabled': selectedCount === 0 }"
          @tap="goCheckout"
        >
          <text class="bottom-bar__checkout-text">
            结算{{ selectedCount > 0 ? `(${selectedCount})` : '' }}
          </text>
        </view>
        <view
          v-else
          class="bottom-bar__checkout bottom-bar__checkout--delete"
          :class="{ 'bottom-bar__checkout--disabled': selectedCount === 0 }"
          @tap="onDeleteSelected"
        >
          <text class="bottom-bar__checkout-text">
            删除{{ selectedCount > 0 ? `(${selectedCount})` : '' }}
          </text>
        </view>
      </view>

      <!-- Safe area -->
      <view class="bottom-bar__safe-area" />
    </view>
  </view>
</template>

<style lang="scss" scoped>
/* ===========================
   Page
   =========================== */
.page {
  min-height: 100vh;
  background-color: $mb-color-bg-secondary;
}

/* ===========================
   Navbar Edit Button
   =========================== */
.navbar-edit {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text;
}

/* ===========================
   Cart List
   =========================== */
.cart-list {
  padding: $mb-spacing-md $mb-spacing-md 0;
}

.cart-list__spacer {
  height: 200rpx;
}

/* ===========================
   Cart Card
   =========================== */
.cart-card {
  margin-bottom: $mb-spacing-md;
  border-radius: $mb-radius-lg;
  overflow: hidden;
}

.cart-card__body {
  display: flex;
  align-items: center;
  padding: $mb-spacing-md;
  background-color: $mb-color-bg;
  border-radius: $mb-radius-lg;
}

/* Checkbox */
.cart-card__checkbox {
  flex-shrink: 0;
  padding-right: $mb-spacing-sm;
}

.checkbox {
  width: 44rpx;
  height: 44rpx;
  border-radius: 50%;
  border: 3rpx solid $mb-color-border;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background-color 0.15s, border-color 0.15s;
}

.checkbox--checked {
  background-color: $mb-color-text;
  border-color: $mb-color-text;
}

.checkbox--disabled {
  background-color: $mb-color-divider;
  border-color: $mb-color-divider;
}

.checkbox__tick {
  font-size: 24rpx;
  color: $mb-color-text-inverse;
  font-weight: 700;
  line-height: 1;
}

/* Product image */
.cart-card__image-wrap {
  position: relative;
  flex-shrink: 0;
  width: 180rpx;
  height: 180rpx;
  border-radius: $mb-radius-md;
  overflow: hidden;
  background-color: #1a1a2e;
  margin-right: $mb-spacing-md;
}

.cart-card__image {
  width: 100%;
  height: 100%;
}

.cart-card__image--faded {
  opacity: 0.5;
}

/* Invalid badge */
.cart-card__invalid-badge {
  position: absolute;
  top: 0;
  left: 0;
  padding: 4rpx 16rpx;
  background-color: rgba(0, 0, 0, 0.55);
  border-radius: 0 0 $mb-radius-sm 0;
}

.cart-card__invalid-text {
  font-size: $mb-font-xs;
  color: $mb-color-text-inverse;
  font-weight: 500;
}

/* Product info */
.cart-card__info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  height: 180rpx;
}

.cart-card__name {
  font-size: $mb-font-md;
  font-weight: 500;
  color: $mb-color-text;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;
  text-overflow: ellipsis;
}

.cart-card__name--faded {
  color: $mb-color-text-tertiary;
}

.cart-card__spec {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
  background-color: $mb-color-bg-secondary;
  padding: 4rpx 14rpx;
  border-radius: $mb-radius-sm;
  align-self: flex-start;
  margin-top: $mb-spacing-xs;
  line-height: 1.5;
}

.cart-card__bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
}

/* Delete button inside card (edit mode / invalid) */
.cart-card__delete-btn {
  padding: 8rpx 24rpx;
  border-radius: $mb-radius-full;
  border: 2rpx solid $mb-color-border;
  background-color: transparent;
}

.cart-card__delete-text {
  font-size: $mb-font-sm;
  color: $mb-color-text-secondary;
  font-weight: 500;
}

/* ===========================
   Invalid Section
   =========================== */
.invalid-section {
  margin-top: $mb-spacing-lg;
}

.invalid-section__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 $mb-spacing-xs;
  margin-bottom: $mb-spacing-sm;
}

.invalid-section__title {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text-tertiary;
}

.invalid-section__clear {
  font-size: $mb-font-sm;
  color: $mb-color-text-tertiary;
}

/* ===========================
   Loading Skeleton
   =========================== */
.skeleton-wrap {
  padding: $mb-spacing-md;
}

.skeleton-card {
  display: flex;
  align-items: center;
  padding: $mb-spacing-md;
  background-color: $mb-color-bg;
  border-radius: $mb-radius-lg;
  margin-bottom: $mb-spacing-md;
}

.skeleton-checkbox {
  width: 44rpx;
  height: 44rpx;
  border-radius: 50%;
  background-color: $mb-color-divider;
  margin-right: $mb-spacing-sm;
  flex-shrink: 0;
}

.skeleton-image {
  width: 180rpx;
  height: 180rpx;
  border-radius: $mb-radius-md;
  background-color: $mb-color-divider;
  margin-right: $mb-spacing-md;
  flex-shrink: 0;
}

.skeleton-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}

.skeleton-line {
  height: 24rpx;
  border-radius: 12rpx;
  background: linear-gradient(
    90deg,
    $mb-color-divider 25%,
    $mb-color-bg-secondary 50%,
    $mb-color-divider 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
}

.skeleton-line--long {
  width: 90%;
}

.skeleton-line--short {
  width: 40%;
}

.skeleton-line--medium {
  width: 65%;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

/* ===========================
   Bottom Bar
   =========================== */
.bottom-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 100;
  background-color: $mb-color-bg;
  border-top: 1rpx solid rgba(0, 0, 0, 0.04);
}

.bottom-bar__inner {
  display: flex;
  align-items: center;
  height: 110rpx;
  padding: 0 $mb-spacing-md;
}

/* Select all */
.bottom-bar__select-all {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  padding-right: $mb-spacing-md;
}

.bottom-bar__select-label {
  font-size: $mb-font-md;
  color: $mb-color-text;
  margin-left: $mb-spacing-sm;
}

/* Total */
.bottom-bar__total {
  flex: 1;
  display: flex;
  align-items: baseline;
  justify-content: flex-end;
  padding-right: $mb-spacing-md;
  overflow: hidden;
}

.bottom-bar__total-label {
  font-size: $mb-font-md;
  color: $mb-color-text-secondary;
  flex-shrink: 0;
  margin-right: 4rpx;
}

/* Checkout button — black as in design v5 */
.bottom-bar__checkout {
  flex-shrink: 0;
  height: 76rpx;
  padding: 0 48rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: $mb-color-text;
  border-radius: $mb-radius-full;
  transition: opacity 0.15s;
}

.bottom-bar__checkout--delete {
  background-color: $mb-color-error;
}

.bottom-bar__checkout--disabled {
  opacity: 0.35;
  pointer-events: none;
}

.bottom-bar__checkout-text {
  font-size: $mb-font-md;
  font-weight: 600;
  color: $mb-color-text-inverse;
  white-space: nowrap;
}

/* Safe area bottom padding */
.bottom-bar__safe-area {
  padding-bottom: constant(safe-area-inset-bottom); /* iOS < 11.2 */
  padding-bottom: env(safe-area-inset-bottom);
}
</style>
