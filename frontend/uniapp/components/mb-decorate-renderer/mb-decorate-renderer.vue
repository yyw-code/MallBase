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
        <text
          class="decorate-search__text"
          :style="textStyle(module, 'placeholder')"
          >{{ module.props.placeholder || "搜索商品" }}</text
        >
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
        <view
          v-else-if="!hasConfiguredList(module)"
          class="decorate-banner__fallback"
        >
          <text class="decorate-banner__fallback-sub">{{
            module.props.subtitle || "NEW ARRIVAL"
          }}</text>
          <text class="decorate-banner__fallback-title">{{
            module.props.title || "夏日好物限时满减"
          }}</text>
          <text class="decorate-banner__fallback-button">{{
            module.props.buttonText || "立即领取"
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
          <text
            class="decorate-nav__label"
            :style="textStyle(module, 'itemLabel')"
            >{{ item.label || item.title || item.text }}</text
          >
        </view>
      </view>

      <view
        v-else-if="module.type === 'entryCard'"
        class="decorate-entry-card"
        :style="entryCardStyle(module)"
        @tap="openEntryCard(module)"
      >
        <view
          v-if="entryCardIconImage(module)"
          class="decorate-entry-card__icon"
        >
          <image
            class="decorate-entry-card__icon-image"
            :src="entryCardIconImage(module)"
            mode="aspectFill"
          />
        </view>
        <view class="decorate-entry-card__content">
          <text
            class="decorate-entry-card__title"
            :style="textStyle(module, 'title')"
            >{{ module.props.title || "入口卡片" }}</text
          >
          <text
            class="decorate-entry-card__sub"
            :style="textStyle(module, 'subtitle')"
            >{{
              module.props.subtitle ||
              module.props.sub_title ||
              module.props.path ||
              "点击查看"
            }}</text
          >
        </view>
        <view v-if="module.props.show_arrow !== false" class="decorate-arrow" />
      </view>

      <view
        v-else-if="module.type === 'imageCube'"
        class="decorate-cube"
        :class="[`decorate-cube--${cubeDisplayLimit(module)}`]"
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
          <view v-else class="decorate-cube__fallback" />
          <view
            v-if="cubeItemTitle(item)"
            class="decorate-cube__title"
            :style="cubeTitleStyle(module)"
          >
            {{ cubeItemTitle(item) }}
          </view>
        </view>
      </view>

      <view
        v-else-if="module.type === 'title'"
        class="decorate-title"
        :style="moduleStyle(module)"
      >
        <text class="decorate-title__text" :style="titleTextStyle(module)">{{
          module.props.text || module.props.title || "标题"
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
          <text
            class="decorate-title__more-text"
            :style="textStyle(module, 'more')"
            >{{ titleMoreText(module) }}</text
          >
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
        :style="moduleStyle(module)"
      >
        <view class="decorate-spacing__inner" :style="spacingStyle(module)" />
      </view>

      <view
        v-else-if="module.type === 'divider'"
        class="decorate-divider-wrap"
        :style="moduleStyle(module)"
      >
        <view class="decorate-divider" :style="dividerStyle(module)" />
      </view>

      <view
        v-else-if="module.type === 'productGroup'"
        class="decorate-products"
        :style="moduleStyle(module)"
      >
        <view v-if="hasProductHead(module)" class="decorate-section-head">
          <view>
            <text
              v-if="module.props.title"
              class="decorate-section-head__title"
              :style="textStyle(module, 'title')"
              >{{ module.props.title }}</text
            >
            <text
              v-if="module.props.subtitle"
              class="decorate-section-head__sub"
              :style="textStyle(module, 'subtitle')"
              >{{ module.props.subtitle }}</text
            >
          </view>
          <view
            v-if="productMoreText(module)"
            class="decorate-section-head__more"
            @tap="openMore(module)"
          >
            <text
              class="decorate-section-head__more-text"
              :style="textStyle(module, 'more')"
              >{{ productMoreText(module) }}</text
            >
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
            module.props.emptyText || "暂无商品"
          }}</text>
        </view>
      </view>
    </template>
  </view>
</template>

<script setup>
import { reactive, watch } from "vue";
import { getGoodsList } from "@/api/goods/goods";
import { useDecorateStore } from "@/store/decorate";
import {
  buildGoodsParams,
  normalizeAssetPath,
  openDecorateLink,
} from "@/utils/decorate";

const props = defineProps({
  modules: {
    type: Array,
    default: () => [],
  },
});

const decorateStore = useDecorateStore();
const productStates = reactive({});

watch(
  () => props.modules,
  () => {
    props.modules
      .filter((module) => module.type === "productGroup")
      .forEach((module) => ensureProductState(module));
  },
  { immediate: true, deep: true },
);

function getList(module) {
  return getRawList(module).filter(
    (item) => itemVisible(item) && decorateStore.isEntryAvailable(item),
  );
}

function getRawList(module) {
  const value =
    module.props.list || module.props.items || module.props.images || [];
  return Array.isArray(value) ? value : [];
}

function hasConfiguredList(module) {
  return getRawList(module).length > 0;
}

function itemVisible(item) {
  return item?.enabled !== false && item?.visible !== false;
}

function styleColor(value) {
  return typeof value === "string" && value.trim() ? value.trim() : "";
}

function gradientDirection(value) {
  const map = {
    diagonalLeft: "135deg",
    diagonalRight: "45deg",
    horizontal: "90deg",
    vertical: "180deg",
  };
  return map[String(value || "horizontal")] || map.horizontal;
}

function gradientBackground(startValue, endValue, directionValue, bottomValue) {
  const start = styleColor(startValue);
  const end = styleColor(endValue) || start;
  const bottom = styleColor(bottomValue);
  if (!start && !bottom) return "";
  if (bottom && start) {
    return `linear-gradient(180deg, ${start} 0%, ${end} 68%, ${bottom} 100%)`;
  }
  if (!start) return bottom;
  if (!end || start.toLowerCase() === end.toLowerCase()) return start;
  return `linear-gradient(${gradientDirection(directionValue)}, ${start}, ${end})`;
}

function clampStyleNumber(value, fallback, min, max) {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return fallback;
  return Math.max(min, Math.min(numberValue, max));
}

function hexToRgba(value, opacity, fallback = "#0f172a") {
  const color = styleColor(value) || fallback;
  const alpha = clampStyleNumber(opacity, 14, 0, 100) / 100;
  const shortHex = color.match(/^#([\da-f])([\da-f])([\da-f])$/i);
  const fullHex = color.match(/^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i);
  const match = fullHex || shortHex;
  if (!match) return color;
  const red = Number.parseInt(
    fullHex ? match[1] : `${match[1]}${match[1]}`,
    16,
  );
  const green = Number.parseInt(
    fullHex ? match[2] : `${match[2]}${match[2]}`,
    16,
  );
  const blue = Number.parseInt(
    fullHex ? match[3] : `${match[3]}${match[3]}`,
    16,
  );
  return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
}

function moduleShadowStyle(props) {
  const shadowEnabled = props.shadowEnabled ?? props.shadow_enabled;
  if (shadowEnabled !== undefined && !styleBoolean(shadowEnabled)) {
    return "none";
  }
  if (!styleBoolean(shadowEnabled)) return "";
  const offsetX = clampStyleNumber(
    props.shadowOffsetX ?? props.shadow_offset_x,
    0,
    -80,
    80,
  );
  const offsetY = clampStyleNumber(
    props.shadowOffsetY ?? props.shadow_offset_y,
    12,
    -80,
    80,
  );
  const blur = clampStyleNumber(
    props.shadowBlur ?? props.shadow_blur,
    30,
    0,
    160,
  );
  const spread = clampStyleNumber(
    props.shadowSpread ?? props.shadow_spread,
    0,
    -80,
    80,
  );
  const color = hexToRgba(
    props.shadowColor ?? props.shadow_color,
    props.shadowOpacity ?? props.shadow_opacity,
  );
  return `${offsetX}rpx ${offsetY}rpx ${blur}rpx ${spread}rpx ${color}`;
}

function styleBoolean(value, fallback = false) {
  if (value === undefined || value === null || value === "") return fallback;
  if (typeof value === "boolean") return value;
  if (typeof value === "number") return value === 1;
  if (typeof value === "string") return ["1", "true"].includes(value);
  return Boolean(value);
}

function normalizeTextAlign(value) {
  const align = String(value || "");
  return ["center", "left", "right"].includes(align) ? align : "";
}

function normalizeFontWeight(value) {
  const weight = String(value || "");
  return ["400", "500", "600", "700", "800", "900"].includes(weight)
    ? weight
    : "";
}

function textStyleConfig(module, role) {
  const styles = module.props?.textStyles || module.props?.text_styles || {};
  const config = styles?.[role];
  return config && typeof config === "object" && !Array.isArray(config)
    ? config
    : {};
}

function textStyle(module, role) {
  const config = textStyleConfig(module, role);
  if (Object.keys(config).length === 0) return "";
  const style = [];
  const color = styleColor(config.color);
  if (color) style.push(`color: ${color}`);
  const fontSize = Number(config.fontSize ?? config.font_size);
  if (Number.isFinite(fontSize) && fontSize > 0) {
    style.push(`font-size: ${clampStyleNumber(fontSize, 24, 16, 80)}rpx`);
  }
  const fontWeight = normalizeFontWeight(
    config.fontWeight ?? config.font_weight,
  );
  if (fontWeight) style.push(`font-weight: ${fontWeight}`);
  if (
    config.fontStyle === "italic" ||
    config.font_style === "italic" ||
    styleBoolean(config.italic)
  ) {
    style.push("font-style: italic");
  }
  const textAlign = normalizeTextAlign(config.textAlign ?? config.text_align);
  if (textAlign) style.push(`text-align: ${textAlign}`);
  return style.join("; ");
}

function moduleStyle(module) {
  const props = module.props || {};
  const style = [];
  const widthPercent = props.widthPercent ?? props.width_percent;
  const marginTop = props.marginTop ?? props.margin_top;
  const marginBottom = props.marginBottom ?? props.margin_bottom;
  const marginLeft = props.marginLeft ?? props.margin_left;
  const marginRight = props.marginRight ?? props.margin_right;
  if (widthPercent !== undefined) {
    const widthValue = Number(widthPercent);
    if (Number.isFinite(widthValue)) {
      const width = Math.max(50, Math.min(widthValue, 100));
      style.push(`width: ${width}%`);
      if (width < 100) style.push("margin-left: auto; margin-right: auto");
    }
  }
  if (marginTop !== undefined)
    style.push(`margin-top: ${Number(marginTop)}rpx`);
  if (marginBottom !== undefined)
    style.push(`margin-bottom: ${Number(marginBottom)}rpx`);
  if (marginLeft !== undefined)
    style.push(`margin-left: ${Number(marginLeft)}rpx`);
  if (marginRight !== undefined)
    style.push(`margin-right: ${Number(marginRight)}rpx`);
  const componentBackground = gradientBackground(
    props.componentBackgroundStart || props.component_background_start,
    props.componentBackgroundEnd || props.component_background_end,
    props.backgroundGradientDirection || props.background_gradient_direction,
  );
  const backgroundImage = getImage(
    props.background_image || props.backgroundImage || "",
  );
  const backgroundMode =
    props.backgroundMode || props.background_mode || "color";
  const background = gradientBackground(
    props.backgroundColorStart ||
      props.background_color_start ||
      props.background,
    props.backgroundColorEnd || props.background_color_end,
    props.backgroundGradientDirection || props.background_gradient_direction,
    props.bottomBackground || props.bottom_background,
  );
  if (componentBackground) style.push(`background: ${componentBackground}`);
  if (backgroundMode === "image" && backgroundImage) {
    style.push(`background-image: url("${backgroundImage}")`);
    style.push("background-size: cover");
    style.push("background-position: center");
  } else if (background) {
    style.push(`background: ${background}`);
  }
  if (props.radius !== undefined)
    style.push(`border-radius: ${Number(props.radius)}rpx`);
  const textColor = styleColor(props.textColor || props.text_color);
  if (textColor) {
    style.push(`color: ${textColor}`);
    style.push(`--color-text: ${textColor}`);
    style.push(`--color-text-title: ${textColor}`);
    style.push(`--color-text-secondary: ${textColor}`);
    style.push(`--color-text-tertiary: ${textColor}`);
  }
  const borderEnabled = props.borderEnabled ?? props.border_enabled;
  if (borderEnabled !== undefined) {
    if (styleBoolean(borderEnabled, true)) {
      const borderWidth = Number(props.borderWidth ?? props.border_width ?? 1);
      const borderStyle = props.borderStyle || props.border_style || "solid";
      const borderColor =
        styleColor(props.borderColor || props.border_color) ||
        "var(--color-divider, #f0f2f5)";
      style.push(`border: ${borderWidth}rpx ${borderStyle} ${borderColor}`);
    } else {
      style.push("border: 0");
    }
  }
  const shadowEnabled = props.shadowEnabled ?? props.shadow_enabled;
  if (shadowEnabled !== undefined) {
    const boxShadow = moduleShadowStyle(props);
    if (boxShadow) style.push(`box-shadow: ${boxShadow}`);
  }
  const hasSidePadding =
    props.paddingTop !== undefined ||
    props.padding_top !== undefined ||
    props.paddingRight !== undefined ||
    props.padding_right !== undefined ||
    props.paddingBottom !== undefined ||
    props.padding_bottom !== undefined ||
    props.paddingLeft !== undefined ||
    props.padding_left !== undefined;
  if (hasSidePadding) {
    const padding = props.padding ?? 0;
    const paddingY = props.paddingY ?? props.padding_y ?? padding;
    const paddingX = props.paddingX ?? props.padding_x ?? padding;
    const paddingTop = props.paddingTop ?? props.padding_top ?? paddingY;
    const paddingRight = props.paddingRight ?? props.padding_right ?? paddingX;
    const paddingBottom =
      props.paddingBottom ?? props.padding_bottom ?? paddingY;
    const paddingLeft = props.paddingLeft ?? props.padding_left ?? paddingX;
    style.push(
      `padding: ${Number(paddingTop)}rpx ${Number(paddingRight)}rpx ${Number(
        paddingBottom,
      )}rpx ${Number(paddingLeft)}rpx`,
    );
  } else if (
    props.paddingY !== undefined ||
    props.padding_y !== undefined ||
    props.paddingX !== undefined ||
    props.padding_x !== undefined
  ) {
    const padding = props.padding ?? 0;
    const paddingY = props.paddingY ?? props.padding_y ?? padding;
    const paddingX = props.paddingX ?? props.padding_x ?? padding;
    style.push(`padding: ${Number(paddingY)}rpx ${Number(paddingX)}rpx`);
  } else if (props.padding !== undefined) {
    style.push(`padding: ${Number(props.padding)}rpx`);
  }
  return style.join("; ");
}

function entryCardStyle(module) {
  const style = [moduleStyle(module)];
  const backgroundImage = getImage(
    module.props?.background_image || module.props?.backgroundImage || "",
  );
  if (backgroundImage) {
    style.push(`background-image: url("${backgroundImage}")`);
    style.push("background-size: cover");
    style.push("background-position: center");
  }
  return style.filter(Boolean).join("; ");
}

function bannerStyle(module) {
  const height = Number(module.props.height || 314);
  const radius = Number(module.props.radius ?? 12);
  return `${moduleStyle(module)}; height: ${height}rpx; border-radius: ${radius}rpx`;
}

function spacingStyle(module) {
  const height = clampStyleNumber(module.props?.height, 32, 0, 300);
  return `height: ${height}rpx`;
}

function dividerStyle(module) {
  const height = clampStyleNumber(
    module.props?.height ??
      module.props?.lineHeight ??
      module.props?.line_height,
    1,
    1,
    20,
  );
  const color =
    styleColor(module.props?.color) || "var(--color-divider, #f0f2f5)";
  const lineStyle = module.props?.style === "dashed" ? "dashed" : "solid";
  return `border-top: ${height}rpx ${lineStyle} ${color}`;
}

function navItemWidth(module) {
  const columns = Number(module.props.columns || 5);
  return `${100 / Math.max(1, columns)}%`;
}

function getImage(item) {
  if (typeof item === "string") return normalizeAssetPath(item);
  if (!item || typeof item !== "object") return "";

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

  return "";
}

function getFallbackIcon(item) {
  const key = item?.icon || item?.key || "";
  const map = {
    phone: "数",
    beauty: "美",
    shirt: "衣",
    home: "家",
    food: "食",
  };
  return (
    map[key] || (item?.label || item?.title || item?.text || "项").slice(0, 1)
  );
}

function entryCardIconImage(module) {
  return getImage(module.props?.icon_image || module.props?.iconImage || "");
}

function cubeLayout(module) {
  const layout = String(module.props?.layout || "four");
  return ["four", "one", "two"].includes(layout) ? layout : "four";
}

function cubeDisplayLimit(module) {
  const map = {
    four: 4,
    one: 1,
    two: 2,
  };
  return map[cubeLayout(module)] || map.four;
}

function cubeItems(module) {
  const list = getList(module);
  const limit = cubeDisplayLimit(module);
  if (list.length > 0 || hasConfiguredList(module)) return list.slice(0, limit);
  const titles = module.props?.titles;
  if (Array.isArray(titles) && titles.length > 0) return titles.slice(0, limit);
  return ["精选榜单", "本周值得买", "会员专享", "新品榜"].slice(0, limit);
}

function cubeItemTitle(item) {
  if (typeof item === "string") return getImage(item) ? "" : item;
  return item?.title || item?.label || item?.text || item?.name || "";
}

function textAlignJustifyContent(value) {
  const align = normalizeTextAlign(value);
  if (align === "center") return "center";
  if (align === "right") return "flex-end";
  return "flex-start";
}

function cubeTitlePositionStyle(value) {
  const position = String(value || "bottom");
  const style = [
    "top: auto",
    "right: auto",
    "bottom: auto",
    "left: auto",
    "transform: none",
  ];
  const offset = "16rpx";
  if (position.includes("top")) {
    style.push(`top: ${offset}`);
  } else if (position.includes("center")) {
    style.push("top: 50%");
    style.push("transform: translateY(-50%)");
  } else {
    style.push(`bottom: ${offset}`);
  }
  if (position.endsWith("Left")) {
    style.push(`left: ${offset}`);
  } else if (position.endsWith("Right")) {
    style.push(`right: ${offset}`);
  } else {
    style.push("left: 50%");
    style.push(
      position.includes("center")
        ? "transform: translate(-50%, -50%)"
        : "transform: translateX(-50%)",
    );
  }
  return style;
}

function cubeTitleStyle(module) {
  const config = textStyleConfig(module, "itemLabel");
  const style = [textStyle(module, "itemLabel")].filter(Boolean);
  const mode = String(
    config.backgroundMode ?? config.background_mode ?? "color",
  );
  const backgroundImage = getImage(
    config.backgroundImage || config.background_image || "",
  );
  if (mode === "image" && backgroundImage) {
    style.push(`background-image: url("${backgroundImage}")`);
    style.push("background-position: center");
    style.push("background-size: cover");
  } else {
    const background = gradientBackground(
      config.backgroundColorStart ?? config.background_color_start,
      config.backgroundColorEnd ?? config.background_color_end,
      config.backgroundGradientDirection ??
        config.background_gradient_direction,
    );
    if (background) style.push(`background: ${background}`);
  }
  const height = Number(
    config.backgroundHeight ?? config.background_height ?? 26,
  );
  style.push(`height: ${clampStyleNumber(height, 26, 10, 100)}%`);
  const width = Number(
    config.backgroundWidth ?? config.background_width ?? 100,
  );
  style.push(`width: ${clampStyleNumber(width, 100, 20, 100)}%`);
  style.push("max-width: calc(100% - 32rpx)");
  style.push("max-height: calc(100% - 32rpx)");
  const radius = Number(
    config.backgroundRadius ?? config.background_radius ?? 12,
  );
  style.push(`border-radius: ${clampStyleNumber(radius, 12, 0, 80)}rpx`);
  style.push(
    ...cubeTitlePositionStyle(
      config.backgroundPosition ?? config.background_position,
    ),
  );
  const align =
    normalizeTextAlign(config.textAlign ?? config.text_align) || "center";
  style.push(`text-align: ${align}`);
  style.push(`justify-content: ${textAlignJustifyContent(align)}`);
  return style.join("; ");
}

function openEntryCard(module) {
  openRendererTarget(
    module.props?.path ||
      module.props?.target_path ||
      module.props?.link_url ||
      module.props?.url ||
      "",
  );
}

function titleMorePath(module) {
  return (
    module.props?.more_path ||
    module.props?.moreUrl ||
    module.props?.more_url ||
    ""
  );
}

function titleMoreText(module) {
  return titleMorePath(module)
    ? module.props?.more_text || module.props?.moreText || "查看全部"
    : "";
}

function titleAlign(module) {
  const align = module.props?.title_align || module.props?.titleAlign || "left";
  return ["center", "right"].includes(align) ? align : "left";
}

function clampNumber(value, fallback, min, max) {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return fallback;
  return Math.max(min, Math.min(numberValue, max));
}

function titleTextStyle(module) {
  const customStyle = textStyle(module, "title");
  if (customStyle) return customStyle;
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
    style.push("font-weight: 500");
  } else {
    style.push("font-weight: 800");
  }
  if (props.title_italic || props.titleItalic) style.push("font-style: italic");
  if (props.title_color || props.titleColor) {
    style.push(`color: ${props.title_color || props.titleColor}`);
  }
  return style.join("; ");
}

function titleSubStyle(module) {
  const customStyle = textStyle(module, "subtitle");
  if (customStyle) return customStyle;
  const props = module.props || {};
  const style = [`text-align: ${titleAlign(module)}`];
  const fontSize = clampNumber(
    props.sub_font_size || props.subFontSize,
    24,
    16,
    56,
  );
  style.push(`font-size: ${fontSize}rpx`);
  if (props.sub_bold || props.subBold) style.push("font-weight: 700");
  if (props.sub_italic || props.subItalic) style.push("font-style: italic");
  if (props.sub_color || props.subColor) {
    style.push(`color: ${props.sub_color || props.subColor}`);
  }
  return style.join("; ");
}

function openTitleMore(module) {
  openRendererTarget(titleMorePath(module));
}

function openItem(item) {
  if (typeof item === "string") return;
  openRendererTarget(item);
}

function openSearch(module) {
  openRendererTarget(
    module.props.url ||
      module.props.path ||
      module.props.target_path ||
      "/pages-sub/search/index",
  );
}

function productMorePath(module) {
  return (
    module.props.moreUrl ||
    module.props.more_url ||
    module.props.more_path ||
    module.props.morePath ||
    ""
  );
}

function productMoreText(module) {
  const value = module.props.moreText ?? module.props.more_text;
  if (value === false) return "";
  return value || "查看全部";
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
    openRendererTarget(morePath);
    return;
  }
  openRendererTarget("/pages-sub/goods/list");
}

async function openRendererTarget(target) {
  if (decorateStore.isThemeSelectorTarget(target)) {
    await decorateStore.openThemeSelector();
    return;
  }
  openDecorateLink(target);
}

function resolveGoodsId(goods) {
  if (typeof goods === "number" || typeof goods === "string") {
    return goods;
  }
  if (goods && typeof goods === "object") {
    return goods.id || goods.goods_id || "";
  }
  return "";
}

function goGoodsDetail(goods) {
  const id = resolveGoodsId(goods);
  if (!id || typeof id === "object") return;
  uni.navigateTo({ url: `/pages-sub/goods/detail?id=${id}` });
}

function productLayout(module) {
  const layout = module.props.layout || "grid";
  return ["grid", "large", "list"].includes(layout) ? layout : "grid";
}

function productCardMode(module) {
  return productLayout(module) === "list" ? "list" : "grid";
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
    .filter((module) => module.type === "productGroup")
    .forEach((module) => fetchProducts(module, true));
}

function loadMore() {
  props.modules
    .filter((module) => module.type === "productGroup" && module.props.pageable)
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
    content: "";
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
  min-height: 486rpx;
}

.decorate-cube--2,
.decorate-cube--4 {
  grid-template-columns: repeat(2, 1fr);
}

.decorate-cube__item {
  position: relative;
  min-height: 180rpx;
  border-radius: 12rpx;
  overflow: hidden;
  background: var(--color-bg-surface, #f3f3fe);
}

.decorate-cube--1 .decorate-cube__item {
  height: 486rpx;
  min-height: 486rpx;
}

.decorate-cube--2 .decorate-cube__item,
.decorate-cube--4 .decorate-cube__item {
  height: 252rpx;
  min-height: 252rpx;
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

.decorate-cube__title {
  position: absolute;
  bottom: 16rpx;
  display: flex;
  align-items: center;
  box-sizing: border-box;
  width: calc(100% - 32rpx);
  height: 26%;
  max-width: calc(100% - 32rpx);
  max-height: calc(100% - 32rpx);
  padding: 8rpx 12rpx;
  overflow: hidden;
  left: 50%;
  border-radius: 10rpx;
  background: rgba(255, 255, 255, 0.78);
  text-overflow: ellipsis;
  transform: translateX(-50%);
  white-space: nowrap;
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

.decorate-spacing,
.decorate-divider-wrap {
  box-sizing: border-box;
}

.decorate-spacing__inner {
  width: 100%;
}

.decorate-divider {
  width: 100%;
  height: 0;
  border-top: 1rpx solid var(--color-divider, #f0f2f5);
  background: transparent;
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
