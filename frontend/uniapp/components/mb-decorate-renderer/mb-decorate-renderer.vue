<template>
  <view class="decorate-renderer">
    <template v-for="module in modules" :key="module.id">
      <view
        v-if="module.type === 'search'"
        class="decorate-search"
        :style="moduleStyle(module)"
        @tap="openSearch(module)"
      >
        <view class="decorate-search__icon" />
        <text class="decorate-search__text">{{
          module.props.placeholder || '搜索商品'
        }}</text>
      </view>

      <view
        v-else-if="module.type === 'banner'"
        class="decorate-banner"
        :style="bannerStyle(module)"
      >
        <swiper
          v-if="getList(module).length > 0"
          class="decorate-banner__swiper"
          :autoplay="module.props.autoplay !== false"
          :interval="Number(module.props.interval || 4200)"
          :duration="Number(module.props.duration || 500)"
          :circular="true"
          :indicator-dots="getList(module).length > 1"
        >
          <swiper-item v-for="(item, index) in getList(module)" :key="index">
            <image
              class="decorate-banner__image"
              :src="getImage(item)"
              mode="aspectFill"
              @tap="openItem(item)"
            />
          </swiper-item>
        </swiper>
        <view v-else class="decorate-banner__fallback">
          <text class="decorate-banner__fallback-sub">{{
            module.props.subtitle || 'NEW ARRIVAL'
          }}</text>
          <text class="decorate-banner__fallback-title">{{
            module.props.title || '夏日好物限时满减'
          }}</text>
          <text class="decorate-banner__fallback-button">{{
            module.props.buttonText || '立即领取'
          }}</text>
        </view>
      </view>

      <view
        v-else-if="module.type === 'navGrid'"
        class="decorate-nav"
        :style="moduleStyle(module)"
      >
        <view
          v-for="item in getList(module)"
          :key="item.key || item.label || item.title"
          class="decorate-nav__item"
          :style="{ width: navItemWidth(module) }"
          @tap="openItem(item)"
        >
          <view class="decorate-nav__icon-wrap">
            <image
              v-if="getImage(item)"
              class="decorate-nav__image"
              :src="getImage(item)"
              mode="aspectFill"
            />
            <text v-else class="decorate-nav__icon">{{
              getFallbackIcon(item)
            }}</text>
          </view>
          <text class="decorate-nav__label">{{
            item.label || item.title || item.text
          }}</text>
        </view>
      </view>

      <view
        v-else-if="module.type === 'entryCard'"
        class="decorate-entry-card"
        :style="entryCardStyle(module)"
        @tap="openEntryCard(module)"
      >
        <view
          class="decorate-entry-card__icon"
          :style="entryCardIconStyle(module)"
        >
          <image
            v-if="entryCardIconImage(module)"
            class="decorate-entry-card__icon-image"
            :src="entryCardIconImage(module)"
            mode="aspectFill"
          />
          <text v-else class="decorate-entry-card__icon-text">{{
            entryCardFallbackIcon(module)
          }}</text>
        </view>
        <view class="decorate-entry-card__content">
          <text class="decorate-entry-card__title">{{
            module.props.title || '入口卡片'
          }}</text>
          <text class="decorate-entry-card__sub">{{
            module.props.subtitle ||
            module.props.sub_title ||
            module.props.path ||
            '点击查看'
          }}</text>
        </view>
        <view v-if="module.props.show_arrow !== false" class="decorate-arrow" />
      </view>

      <view
        v-else-if="module.type === 'imageCube'"
        class="decorate-cube"
        :class="[`decorate-cube--${Math.min(cubeItems(module).length, 4)}`]"
        :style="moduleStyle(module)"
      >
        <view
          v-for="(item, index) in cubeItems(module)"
          :key="index"
          class="decorate-cube__item"
          @tap="openItem(item)"
        >
          <image
            v-if="getImage(item)"
            class="decorate-cube__image"
            :src="getImage(item)"
            mode="aspectFill"
          />
          <view v-else class="decorate-cube__fallback">
            <text>{{ cubeItemTitle(item) }}</text>
          </view>
        </view>
      </view>

      <view
        v-else-if="module.type === 'title'"
        class="decorate-title"
        :style="moduleStyle(module)"
      >
        <text class="decorate-title__text" :style="titleTextStyle(module)">{{
          module.props.text || module.props.title || '标题'
        }}</text>
        <text
          v-if="module.props.subtitle || module.props.sub_title"
          class="decorate-title__sub"
          :style="titleSubStyle(module)"
          >{{ module.props.subtitle || module.props.sub_title }}</text
        >
        <view
          v-if="titleMoreText(module)"
          class="decorate-title__more"
          @tap="openTitleMore(module)"
        >
          <text class="decorate-title__more-text">{{
            titleMoreText(module)
          }}</text>
          <view class="decorate-arrow" />
        </view>
      </view>

      <view
        v-else-if="module.type === 'richText'"
        class="decorate-rich"
        :style="moduleStyle(module)"
      >
        <rich-text :nodes="module.props.content || module.props.html || ''" />
      </view>

      <view
        v-else-if="module.type === 'spacing'"
        class="decorate-spacing"
        :style="spacingStyle(module)"
      />

      <view
        v-else-if="module.type === 'divider'"
        class="decorate-divider"
        :style="moduleStyle(module)"
      />

      <view
        v-else-if="module.type === 'productGroup'"
        class="decorate-products"
        :style="moduleStyle(module)"
      >
        <view
          v-if="hasProductHead(module)"
          class="decorate-section-head"
        >
          <view>
            <text
              v-if="module.props.title"
              class="decorate-section-head__title"
              >{{ module.props.title }}</text
            >
            <text
              v-if="module.props.subtitle"
              class="decorate-section-head__sub"
              >{{ module.props.subtitle }}</text
            >
          </view>
          <view
            v-if="productMoreText(module)"
            class="decorate-section-head__more"
            @tap="openMore(module)"
          >
            <text class="decorate-section-head__more-text">{{
              productMoreText(module)
            }}</text>
            <view class="decorate-arrow" />
          </view>
        </view>

        <scroll-view
          v-if="module.props.layout === 'scroll'"
          scroll-x
          class="decorate-product-scroll"
          :show-scrollbar="false"
        >
          <view class="decorate-product-scroll__track">
            <view
              v-for="item in getProductState(module).list"
              :key="item.id"
              class="decorate-product-scroll__item"
            >
              <mb-product-card
                :goods="item"
                mode="grid"
                @tap="goGoodsDetail(item)"
              />
            </view>
          </view>
        </scroll-view>

        <view
          v-else
          class="decorate-product-grid"
          :class="`decorate-product-grid--${productLayout(module)}`"
        >
          <view
            v-for="item in getProductState(module).list"
            :key="item.id"
            class="decorate-product-grid__item"
            :class="`decorate-product-grid__item--${productLayout(module)}`"
          >
            <mb-product-card
              :goods="item"
              :mode="productCardMode(module)"
              @tap="goGoodsDetail(item)"
            />
          </view>
        </view>

        <view v-if="getProductState(module).loading" class="decorate-load">
          <text class="decorate-load__text">加载中...</text>
        </view>
        <view
          v-else-if="
            getProductState(module).loaded &&
            getProductState(module).list.length === 0
          "
          class="decorate-empty"
        >
          <text class="decorate-empty__text">{{
            module.props.emptyText || '暂无商品'
          }}</text>
        </view>
      </view>
    </template>
  </view>
</template>

<script setup>
import { reactive, watch } from 'vue';
import { getGoodsList } from '@/api/goods/goods';
import config from '@/config/index';
import { buildGoodsParams, openDecorateLink } from '@/utils/decorate';

const props = defineProps({
  modules: {
    type: Array,
    default: () => [],
  },
});

const productStates = reactive({});

watch(
  () => props.modules,
  () => {
    props.modules
      .filter((module) => module.type === 'productGroup')
      .forEach((module) => ensureProductState(module));
  },
  { immediate: true, deep: true },
);

function getList(module) {
  const value =
    module.props.list || module.props.items || module.props.images || [];
  return Array.isArray(value) ? value : [];
}

function moduleStyle(module) {
  const props = module.props || {};
  const style = [];
  const widthPercent = props.widthPercent ?? props.width_percent;
  const marginTop = props.marginTop ?? props.margin_top;
  const marginBottom = props.marginBottom ?? props.margin_bottom;
  if (widthPercent !== undefined) {
    const widthValue = Number(widthPercent);
    if (Number.isFinite(widthValue)) {
      const width = Math.max(50, Math.min(widthValue, 100));
      style.push(`width: ${width}%`);
      if (width < 100) style.push('margin-left: auto; margin-right: auto');
    }
  }
  if (marginTop !== undefined)
    style.push(`margin-top: ${Number(marginTop)}rpx`);
  if (marginBottom !== undefined)
    style.push(`margin-bottom: ${Number(marginBottom)}rpx`);
  if (props.background) style.push(`background: ${props.background}`);
  if (props.radius !== undefined)
    style.push(`border-radius: ${Number(props.radius)}rpx`);
  if (props.padding !== undefined)
    style.push(`padding: ${Number(props.padding)}rpx`);
  return style.join('; ');
}

function entryCardStyle(module) {
  const style = [moduleStyle(module)];
  const backgroundImage = getImage(
    module.props?.background_image || module.props?.backgroundImage || '',
  );
  if (backgroundImage) {
    style.push(`background-image: url("${backgroundImage}")`);
    style.push('background-size: cover');
    style.push('background-position: center');
  }
  return style.filter(Boolean).join('; ');
}

function entryCardIconStyle(module) {
  const props = module.props || {};
  const style = [];
  const color = props.icon_color || props.iconColor;
  const background = props.icon_background || props.iconBackground;
  if (color) style.push(`color: ${color}`);
  if (background) style.push(`background: ${background}`);
  return style.join('; ');
}

function bannerStyle(module) {
  const height = Number(module.props.height || 314);
  const radius = Number(module.props.radius ?? 12);
  return `${moduleStyle(module)}; height: ${height}rpx; border-radius: ${radius}rpx`;
}

function spacingStyle(module) {
  return `${moduleStyle(module)}; height: ${Number(module.props.height || 24)}rpx`;
}

function navItemWidth(module) {
  const columns = Number(module.props.columns || 5);
  return `${100 / Math.max(1, columns)}%`;
}

function getImage(item) {
  if (typeof item === 'string') return normalizeImageUrl(item);
  if (!item || typeof item !== 'object') return '';

  const candidates = [
    item.full_url,
    item.fullUrl,
    item.thumbUrl,
    item.thumb_url,
    item.response?.full_url,
    item.response?.fullUrl,
    item.response?.url,
    item.image,
    item.image_url,
    item.imageUrl,
    item.pic,
    item.src,
    item.cover,
    item.url,
  ];

  for (const value of candidates) {
    const image = getImage(value);
    if (image) return image;
  }

  return '';
}

function getFallbackIcon(item) {
  const key = item?.icon || item?.key || '';
  const map = {
    phone: '数',
    beauty: '美',
    shirt: '衣',
    home: '家',
    food: '食',
  };
  return (
    map[key] || (item?.label || item?.title || item?.text || '项').slice(0, 1)
  );
}

function entryCardIconImage(module) {
  return getImage(module.props?.icon_image || module.props?.iconImage || '');
}

function entryCardFallbackIcon(module) {
  return getFallbackIcon({
    icon: module.props?.icon,
    title: module.props?.title,
  });
}

function cubeItems(module) {
  const list = getList(module);
  if (list.length > 0) return list.slice(0, 4);
  const titles = module.props?.titles;
  if (Array.isArray(titles) && titles.length > 0) return titles.slice(0, 4);
  return ['精选榜单', '本周值得买', '会员专享', '新品榜'];
}

function cubeItemTitle(item) {
  if (typeof item === 'string') return item;
  return item?.title || item?.label || item?.text || '精选内容';
}

function openEntryCard(module) {
  openDecorateLink(
    module.props?.path ||
      module.props?.target_path ||
      module.props?.link_url ||
      module.props?.url ||
      '',
  );
}

function titleMorePath(module) {
  return (
    module.props?.more_path ||
    module.props?.moreUrl ||
    module.props?.more_url ||
    ''
  );
}

function titleMoreText(module) {
  return titleMorePath(module)
    ? module.props?.more_text || module.props?.moreText || '查看全部'
    : '';
}

function titleAlign(module) {
  const align = module.props?.title_align || module.props?.titleAlign || 'left';
  return ['center', 'right'].includes(align) ? align : 'left';
}

function clampNumber(value, fallback, min, max) {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return fallback;
  return Math.max(min, Math.min(numberValue, max));
}

function titleTextStyle(module) {
  const props = module.props || {};
  const style = [`text-align: ${titleAlign(module)}`];
  const fontSize = clampNumber(
    props.title_font_size || props.titleFontSize,
    32,
    18,
    72,
  );
  style.push(`font-size: ${fontSize}rpx`);
  if (props.title_bold === false || props.titleBold === false) {
    style.push('font-weight: 500');
  } else {
    style.push('font-weight: 800');
  }
  if (props.title_italic || props.titleItalic) style.push('font-style: italic');
  if (props.title_color || props.titleColor) {
    style.push(`color: ${props.title_color || props.titleColor}`);
  }
  return style.join('; ');
}

function titleSubStyle(module) {
  const props = module.props || {};
  const style = [`text-align: ${titleAlign(module)}`];
  const fontSize = clampNumber(
    props.sub_font_size || props.subFontSize,
    24,
    16,
    56,
  );
  style.push(`font-size: ${fontSize}rpx`);
  if (props.sub_bold || props.subBold) style.push('font-weight: 700');
  if (props.sub_italic || props.subItalic) style.push('font-style: italic');
  if (props.sub_color || props.subColor) {
    style.push(`color: ${props.sub_color || props.subColor}`);
  }
  return style.join('; ');
}

function openTitleMore(module) {
  openDecorateLink(titleMorePath(module));
}

function openItem(item) {
  if (typeof item === 'string') return;
  openDecorateLink(item);
}

function looksLikeImageUrl(url) {
  if (!url || typeof url !== 'string') return false;
  if (url.startsWith('/pages')) return false;
  if (url.startsWith('/static')) return true;
  if (url.startsWith('/uploads')) return true;
  if (url.startsWith('uploads/')) return true;
  if (url.startsWith('static/')) return true;
  if (/^https?:\/\//.test(url)) return true;
  if (/^(data:image|blob:)/.test(url)) return true;
  return /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(url);
}

function normalizeImageUrl(url) {
  if (!looksLikeImageUrl(url)) return '';
  if (/^(https?:|data:image|blob:)/.test(url)) return url;
  if (url.startsWith('/static') || url.startsWith('static/')) return url;

  const normalizedPath = url.startsWith('/') ? url : `/${url}`;
  return config.baseUrl ? `${config.baseUrl}${normalizedPath}` : normalizedPath;
}

function openSearch(module) {
  openDecorateLink(
    module.props.url ||
      module.props.path ||
      module.props.target_path ||
      '/pages-sub/search/index',
  );
}

function productMorePath(module) {
  return (
    module.props.moreUrl ||
    module.props.more_url ||
    module.props.more_path ||
    module.props.morePath ||
    ''
  );
}

function productMoreText(module) {
  const value = module.props.moreText ?? module.props.more_text;
  if (value === false) return '';
  return value || '查看全部';
}

function hasProductHead(module) {
  return Boolean(
    module.props.title ||
      module.props.subtitle ||
      productMoreText(module) ||
      productMorePath(module),
  );
}

function openMore(module) {
  const morePath = productMorePath(module);
  if (morePath) {
    openDecorateLink(morePath);
    return;
  }
  openDecorateLink('/pages-sub/goods/list');
}

function resolveGoodsId(goods) {
  if (typeof goods === 'number' || typeof goods === 'string') {
    return goods;
  }
  if (goods && typeof goods === 'object') {
    return goods.id || goods.goods_id || '';
  }
  return '';
}

function goGoodsDetail(goods) {
  const id = resolveGoodsId(goods);
  if (!id || typeof id === 'object') return;
  uni.navigateTo({ url: `/pages-sub/goods/detail?id=${id}` });
}

function productLayout(module) {
  const layout = module.props.layout || 'grid';
  return ['grid', 'large', 'list'].includes(layout) ? layout : 'grid';
}

function productCardMode(module) {
  return productLayout(module) === 'list' ? 'list' : 'grid';
}

function getProductState(module) {
  return ensureProductState(module);
}

function ensureProductState(module) {
  if (!productStates[module.id]) {
    productStates[module.id] = {
      list: [],
      page: 1,
      loading: false,
      loaded: false,
      noMore: false,
    };
    fetchProducts(module, true);
  }
  return productStates[module.id];
}

async function fetchProducts(module, reset = false) {
  const state = ensureProductStateOnly(module);
  if (state.loading) return;
  if (!reset && state.noMore) return;

  state.loading = true;
  if (reset) {
    state.page = 1;
    state.noMore = false;
  }

  try {
    const data = await getGoodsList(buildGoodsParams(module.props, state.page));
    const list = Array.isArray(data?.list)
      ? data.list
      : Array.isArray(data)
        ? data
        : [];
    state.list = reset ? list : [...state.list, ...list];
    state.loaded = true;
    const limit = Number(module.props.limit || module.props.page_size || 10);
    if (list.length < limit) {
      state.noMore = true;
    } else {
      state.page += 1;
    }
  } catch (_) {
    if (reset) state.list = [];
    state.loaded = true;
  } finally {
    state.loading = false;
  }
}

function ensureProductStateOnly(module) {
  if (!productStates[module.id]) {
    productStates[module.id] = {
      list: [],
      page: 1,
      loading: false,
      loaded: false,
      noMore: false,
    };
  }
  return productStates[module.id];
}

function refresh() {
  props.modules
    .filter((module) => module.type === 'productGroup')
    .forEach((module) => fetchProducts(module, true));
}

function loadMore() {
  props.modules
    .filter((module) => module.type === 'productGroup' && module.props.pageable)
    .forEach((module) => fetchProducts(module, false));
}

defineExpose({ refresh, loadMore });
</script>

<style lang="scss" scoped>
.decorate-renderer {
  display: flex;
  flex-direction: column;
  gap: 28rpx;
}

.decorate-search {
  height: 72rpx;
  padding: 0 28rpx;
  border-radius: 20rpx;
  background: var(--color-bg-surface, #f3f3fe);
  display: flex;
  align-items: center;
  box-sizing: border-box;
}

.decorate-search__icon {
  width: 22rpx;
  height: 22rpx;
  border: 4rpx solid var(--color-text-tertiary, #737686);
  border-radius: 50%;
  position: relative;

  &::after {
    content: '';
    position: absolute;
    right: -10rpx;
    bottom: -6rpx;
    width: 13rpx;
    height: 4rpx;
    border-radius: 4rpx;
    background: var(--color-text-tertiary, #737686);
    transform: rotate(45deg);
  }
}

.decorate-search__text {
  margin-left: 20rpx;
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.decorate-banner {
  overflow: hidden;
  background: var(--color-bg-surface, #f3f3fe);
}

.decorate-banner__swiper,
.decorate-banner__image,
.decorate-banner__fallback {
  width: 100%;
  height: 100%;
}

.decorate-banner__fallback {
  display: flex;
  height: 100%;
  box-sizing: border-box;
  flex-direction: column;
  justify-content: center;
  padding: 44rpx;
  color: #ffffff;
  background:
    radial-gradient(
      circle at 80% 22%,
      rgba(255, 255, 255, 0.26),
      transparent 24%
    ),
    linear-gradient(
      135deg,
      var(--color-primary, #0d50d5) 0%,
      var(--color-primary-light, #386bef) 44%,
      var(--color-price, #ff5a1f) 100%
    );
}

.decorate-banner__fallback-sub {
  font-size: 24rpx;
  line-height: 1.2;
  opacity: 0.82;
}

.decorate-banner__fallback-title {
  margin-top: 12rpx;
  font-size: 40rpx;
  font-weight: 800;
  line-height: 1.2;
}

.decorate-banner__fallback-button {
  align-self: flex-start;
  margin-top: 24rpx;
  padding: 10rpx 24rpx;
  border-radius: 999rpx;
  background: rgba(255, 255, 255, 0.22);
  font-size: 24rpx;
  font-weight: 700;
}

.decorate-nav {
  display: flex;
  flex-wrap: wrap;
  row-gap: 24rpx;
  padding: 4rpx 0;
}

.decorate-nav__item {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.decorate-nav__icon-wrap {
  width: 76rpx;
  height: 76rpx;
  border-radius: 28rpx;
  background: rgba(13, 80, 213, 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.decorate-nav__image {
  width: 100%;
  height: 100%;
}

.decorate-nav__icon {
  font-size: 28rpx;
  font-weight: 700;
  color: var(--color-primary, #0d50d5);
}

.decorate-nav__label {
  margin-top: 14rpx;
  font-size: 22rpx;
  font-weight: 700;
  color: var(--color-text-secondary, #434654);
}

.decorate-entry-card {
  display: flex;
  align-items: center;
  gap: 20rpx;
  min-height: 112rpx;
  padding: 22rpx 24rpx;
  box-sizing: border-box;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 24rpx;
  background: var(--color-bg, #ffffff);
}

.decorate-entry-card__icon {
  display: flex;
  width: 72rpx;
  height: 72rpx;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: 24rpx;
  background: rgba(13, 80, 213, 0.08);
  color: var(--color-primary, #0d50d5);
}

.decorate-entry-card__icon-image {
  width: 100%;
  height: 100%;
}

.decorate-entry-card__icon-text {
  font-size: 30rpx;
  font-weight: 800;
}

.decorate-entry-card__content {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  gap: 6rpx;
}

.decorate-entry-card__title {
  overflow: hidden;
  color: var(--color-text-title, #191b23);
  font-size: 28rpx;
  font-weight: 800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.decorate-entry-card__sub {
  overflow: hidden;
  color: var(--color-text-tertiary, #737686);
  font-size: 22rpx;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.decorate-cube {
  display: grid;
  gap: 12rpx;
  min-height: 180rpx;
}

.decorate-cube--1 {
  grid-template-columns: 1fr;
}

.decorate-cube--2,
.decorate-cube--3,
.decorate-cube--4 {
  grid-template-columns: repeat(2, 1fr);
}

.decorate-cube__item {
  min-height: 180rpx;
  border-radius: 12rpx;
  overflow: hidden;
  background: var(--color-bg-surface, #f3f3fe);
}

.decorate-cube__image,
.decorate-cube__fallback {
  width: 100%;
  height: 100%;
}

.decorate-cube__fallback {
  display: flex;
  width: 100%;
  height: 100%;
  box-sizing: border-box;
  align-items: flex-end;
  padding: 24rpx;
  background:
    radial-gradient(
      circle at 75% 30%,
      rgba(13, 80, 213, 0.18),
      transparent 36%
    ),
    var(--color-bg-surface, #f3f3fe);
  color: var(--color-text-title, #191b23);
  font-size: 24rpx;
  font-weight: 700;
}

.decorate-title {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  column-gap: 20rpx;
  row-gap: 6rpx;
}

.decorate-title__text,
.decorate-section-head__title {
  display: block;
  min-width: 0;
  font-size: 32rpx;
  font-weight: 800;
  color: var(--color-text-title, #191b23);
}

.decorate-title__sub,
.decorate-section-head__sub {
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.decorate-title__sub {
  display: block;
  grid-column: 1;
  min-width: 0;
  margin-top: 6rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.decorate-title__more {
  display: flex;
  grid-column: 2;
  grid-row: 1 / span 2;
  align-items: center;
  gap: 8rpx;
}

.decorate-title__more-text {
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.decorate-rich {
  padding: 24rpx;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 24rpx;
  background: var(--color-bg, #ffffff);
  color: var(--color-text, #191b23);
}

.decorate-divider {
  height: 1rpx;
  background: var(--color-divider, #f0f2f5);
}

.decorate-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20rpx;
  margin-bottom: 22rpx;
}

.decorate-section-head > view:first-child {
  min-width: 0;
  flex: 1;
}

.decorate-section-head__title,
.decorate-section-head__sub {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.decorate-section-head__more {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 8rpx;
}

.decorate-section-head__more-text {
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.decorate-arrow {
  width: 12rpx;
  height: 12rpx;
  border-right: 3rpx solid var(--color-text-tertiary, #737686);
  border-bottom: 3rpx solid var(--color-text-tertiary, #737686);
  transform: rotate(-45deg);
}

.decorate-product-scroll {
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  overflow: hidden;
  white-space: nowrap;
}

.decorate-product-scroll__track {
  display: inline-flex;
  flex-direction: row;
  max-width: none;
}

.decorate-product-scroll__item {
  width: 210rpx;
  box-sizing: border-box;
  flex: 0 0 210rpx;
  margin-right: 20rpx;
}

.decorate-product-scroll__item:last-child {
  margin-right: 0;
}

.decorate-product-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20rpx;
  min-width: 0;
}

.decorate-product-grid__item {
  min-width: 0;
}

.decorate-products {
  box-sizing: border-box;
  max-width: 100%;
  overflow: hidden;
  padding: 28rpx;
  border: 1rpx solid var(--color-divider, #f0f2f5);
  border-radius: 24rpx;
  background: var(--color-bg, #ffffff);
}

.decorate-product-grid--large,
.decorate-product-grid--list {
  grid-template-columns: 1fr;
}

.decorate-product-grid__item--large {
  width: 100%;
}

.decorate-load,
.decorate-empty {
  padding: 24rpx 0;
  text-align: center;
}

.decorate-load__text,
.decorate-empty__text {
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}
</style>
