<script setup>
import { computed, ref } from "vue";
import { onShow } from "@dcloudio/uni-app";
import { getBasicConfig, getPayMethods } from "@/api/config";
import { getPointsInfo } from "@/api/points/points";
import { getWalletInfo } from "@/api/user/wallet";
import config from "@/config/index";
import { useDecorateStore } from "@/store/decorate";
import { useUserStore } from "@/store/user";
import { openCustomerService } from "@/utils/customer-service";

const userStore = useUserStore();
const decorateStore = useDecorateStore();

const wallet = ref({
  balance: "0.00",
  total_recharge: "0.00",
  total_consume: "0.00",
});
const points = ref({
  balance_points: 0,
  total_income_points: 0,
  total_expense_points: 0,
});
const balancePaymentEnabled = ref(false);
const pointsEnabled = ref(true);
const profileIconPresets = [
  {
    type: "pay",
    text: "¥",
    keywords: [
      "pending_pay",
      "pay",
      "wallet",
      "待付款",
      "钱包",
      "ant-design:wallet-outlined",
    ],
  },
  {
    type: "ship",
    text: "发",
    keywords: [
      "pending_ship",
      "ship",
      "car",
      "待发货",
      "ant-design:car-outlined",
    ],
  },
  {
    type: "receive",
    text: "收",
    keywords: [
      "pending_receive",
      "receive",
      "inbox",
      "待收货",
      "ant-design:inbox-outlined",
    ],
  },
  {
    type: "refund",
    text: "退",
    keywords: [
      "refund",
      "reload",
      "退款",
      "售后",
      "ant-design:reload-outlined",
    ],
  },
  {
    type: "address",
    text: "址",
    keywords: [
      "address",
      "environment",
      "地址",
      "ant-design:environment-outlined",
    ],
  },
  {
    type: "settings",
    text: "设",
    keywords: [
      "settings",
      "setting",
      "theme",
      "skin",
      "系统",
      "设置",
      "主题",
      "ant-design:skin-outlined",
      "ant-design:setting-outlined",
    ],
  },
  {
    type: "service",
    text: "客",
    keywords: [
      "service",
      "customer",
      "客服",
      "ant-design:customer-service-outlined",
    ],
  },
];

const defaultProfileOrderImages = [
  "static/decorate/profile-order-pay.svg",
  "static/decorate/profile-order-ship.svg",
  "static/decorate/profile-order-receive.svg",
  "static/decorate/profile-order-refund.svg",
];

const defaultProfileServiceImages = [
  "static/decorate/profile-service-address.svg",
  "static/decorate/profile-service-settings.svg",
  "static/decorate/profile-service-support.svg",
];

function defaultProfileEntryImage(type, index) {
  if (type === "orderShortcut") {
    return defaultProfileOrderImages[index % defaultProfileOrderImages.length];
  }
  if (type === "serviceMenu") {
    return defaultProfileServiceImages[
      index % defaultProfileServiceImages.length
    ];
  }
  return "";
}

function isProfileEntryImageRemoved(item) {
  return item?.imageRemoved === true || item?.image_removed === true;
}

const logged = computed(() => userStore.isLoggedIn);
const nickname = computed(() => userStore.userInfo?.nickname || "");
const avatar = computed(
  () => userStore.userInfo?.avatar_full_url || userStore.userInfo?.avatar || "",
);
const bio = computed(() => userStore.userInfo?.bio || "还没有填写个性签名");
const mobile = computed(() => {
  const raw = userStore.userInfo?.mobile || "";
  if (!raw || raw.length < 7) return raw;
  return raw.slice(0, 3) + " **** " + raw.slice(-4);
});
const walletBalance = computed(() => formatAmount(wallet.value.balance));
const walletRecharge = computed(() =>
  formatAmount(wallet.value.total_recharge),
);
const walletConsume = computed(() => formatAmount(wallet.value.total_consume));
const pointsBalance = computed(() => Number(points.value.balance_points || 0));
const pointsIncome = computed(() =>
  Number(points.value.total_income_points || 0),
);
const pointsExpense = computed(() =>
  Number(points.value.total_expense_points || 0),
);

const profileModules = computed(() => {
  const modules = Array.isArray(decorateStore.profileModules)
    ? decorateStore.profileModules
    : [];
  return modules
    .filter((module) => module && typeof module === "object")
    .map((module, index) => ({
      ...module,
      id: module.id || module.key || `${module.type || "profile"}-${index}`,
      props: getModuleProps(module),
    }));
});

const profilePageStyle = computed(() => {
  const pageStyle = decorateStore.profilePageStyle || {};
  const paddingY = pageStyle.paddingY ?? pageStyle.padding_y;
  const paddingX = pageStyle.paddingX ?? pageStyle.padding_x ?? 28;
  const paddingTop =
    pageStyle.paddingTop ?? pageStyle.padding_top ?? paddingY ?? 10;
  const paddingRight =
    pageStyle.paddingRight ?? pageStyle.padding_right ?? paddingX;
  const paddingBottom =
    pageStyle.paddingBottom ?? pageStyle.padding_bottom ?? paddingY ?? 24;
  const paddingLeft =
    pageStyle.paddingLeft ?? pageStyle.padding_left ?? paddingX;

  return {
    ...pageBackgroundStyle(pageStyle),
    paddingBottom: toRpx(paddingBottom, 24),
    paddingLeft: toRpx(paddingLeft, 28),
    paddingRight: toRpx(paddingRight, 28),
    paddingTop: toRpx(paddingTop, 10),
  };
});

onShow(async () => {
  userStore.restoreToken();
  await decorateStore.fetchThemes({ force: true });
  await decorateStore.fetchMyThemePreference({ force: true });
  await fetchFeatureState();
  fetchPayMethodState();
  if (userStore.isLoggedIn) {
    userStore.fetchUserInfo();
    if (pointsEnabled.value) {
      fetchPoints();
    } else {
      resetPoints();
    }
    if (balancePaymentEnabled.value) {
      fetchWallet();
    }
  }
});

async function fetchFeatureState() {
  try {
    const data = await getBasicConfig();
    pointsEnabled.value = settingSwitchEnabled(data?.points_enabled, true);
  } catch {
    pointsEnabled.value = true;
  }
}

function settingSwitchEnabled(value, fallback = true) {
  if (value === undefined || value === null || value === "") return fallback;
  return ["1", "true", "on"].includes(String(value).toLowerCase());
}

async function fetchPayMethodState() {
  try {
    const methods = await getPayMethods();
    balancePaymentEnabled.value =
      Array.isArray(methods) &&
      methods.some((item) => Number(item?.code) === 3);
    if (userStore.isLoggedIn && balancePaymentEnabled.value) {
      fetchWallet();
    }
  } catch {
    balancePaymentEnabled.value = false;
  }
}

async function fetchWallet() {
  try {
    const data = await getWalletInfo();
    wallet.value = {
      ...wallet.value,
      ...(data || {}),
    };
  } catch {
    wallet.value = {
      balance: "0.00",
      total_recharge: "0.00",
      total_consume: "0.00",
    };
  }
}

async function fetchPoints() {
  if (!pointsEnabled.value) {
    resetPoints();
    return;
  }

  try {
    const data = await getPointsInfo();
    points.value = {
      ...points.value,
      ...(data || {}),
    };
  } catch {
    points.value = {
      balance_points: 0,
      total_income_points: 0,
      total_expense_points: 0,
    };
  }
}

function resetPoints() {
  points.value = {
    balance_points: 0,
    total_income_points: 0,
    total_expense_points: 0,
  };
}

function formatAmount(value) {
  return Number(value || 0).toFixed(2);
}

function getModuleProps(module) {
  const props = module?.props;
  return props && typeof props === "object" && !Array.isArray(props)
    ? props
    : {};
}

function moduleList(module) {
  const props = getModuleProps(module);
  const list = props.items || props.list || [];
  if (!Array.isArray(list)) return [];
  return list
    .filter((item) => item && typeof item === "object")
    .filter((item) => {
      if (item.requireBalanceEnabled) return balancePaymentEnabled.value;
      return (
        item.visible !== false &&
        item.enabled !== false &&
        decorateStore.isEntryAvailable(item)
      );
    })
    .map((item, index) => {
      const imageRemoved = isProfileEntryImageRemoved(item);
      const image = imageRemoved
        ? ""
        : item.image ||
          item.image_url ||
          item.imageUrl ||
          item.icon_image ||
          item.iconImage ||
          defaultProfileEntryImage(module.type, index);
      return {
        ...item,
        image,
        label: item.label || item.title || item.text || "",
        path: item.path || item.url || item.link || "",
      };
    });
}

function isThemeEntry(item) {
  return decorateStore.isThemeSelectorTarget(item);
}

function isCustomerServiceEntry(item) {
  const key = String(item?.key || item?.action || "").toLowerCase();
  const label = String(item?.label || item?.title || item?.text || "");
  return (
    key === "service" ||
    key === "customer" ||
    key === "customer_service" ||
    label.includes("客服")
  );
}

function showWalletBalance(module) {
  return module.props?.show_balance !== false;
}

function showUserMobile(module) {
  return module.props?.show_mobile !== false && Boolean(mobile.value);
}

function walletActions(module) {
  const props = module.props || {};
  return [
    showWalletBalance(module) && props.show_records !== false
      ? { key: "records", label: "余额明细", primary: false }
      : null,
    props.show_view_button !== false
      ? { key: "view", label: "去查看", primary: true }
      : null,
  ].filter(Boolean);
}

function tapWalletAction(action) {
  if (action.key === "records") {
    goWalletRecords();
    return;
  }
  goWallet();
}

function pointsActions(module) {
  if (!pointsEnabled.value) return [];
  const props = module.props || {};
  return [
    props.show_records !== false
      ? { key: "records", label: "积分明细", primary: false }
      : null,
    props.show_view_button !== false
      ? { key: "view", label: "去查看", primary: true }
      : null,
  ].filter(Boolean);
}

function tapPointsAction(action) {
  if (action.key === "records") {
    goPointsRecords();
    return;
  }
  goPoints();
}

function serviceMenuIsGrid(module) {
  return module.props?.display === "grid";
}

function serviceGridStyle(module) {
  const columns = Math.max(3, Math.min(Number(module.props?.columns || 4), 5));
  return {
    gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
  };
}

function toRpx(value, fallback = 0) {
  const numberValue = Number(value ?? fallback);
  if (!Number.isFinite(numberValue)) return `${fallback}rpx`;
  return `${Math.max(0, numberValue)}rpx`;
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

function pageBackgroundStyle(pageStyle) {
  const style = {};
  const backgroundMode =
    pageStyle.backgroundMode || pageStyle.background_mode || "color";
  const backgroundImage = moduleBackgroundImage(pageStyle);
  if (backgroundMode === "image" && backgroundImage) {
    style.backgroundImage = `url("${backgroundImage}")`;
    style.backgroundSize = "cover";
    style.backgroundPosition = "center";
    return style;
  }

  const background = gradientBackground(
    pageStyle.backgroundColorStart || pageStyle.background_color_start,
    pageStyle.backgroundColorEnd || pageStyle.background_color_end,
    pageStyle.backgroundGradientDirection ||
      pageStyle.background_gradient_direction,
  );
  if (background) style.background = background;
  return style;
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

function normalizeHexColor(value) {
  const color = styleColor(value).toLowerCase();
  const shortHex = color.match(/^#([\da-f])([\da-f])([\da-f])$/i);
  if (shortHex) {
    return `#${shortHex[1]}${shortHex[1]}${shortHex[2]}${shortHex[2]}${shortHex[3]}${shortHex[3]}`;
  }
  return color;
}

function isDefaultProfileSurfaceColor(value) {
  return ["#ffffff", "#faf8ff", "#f3f3fe"].includes(normalizeHexColor(value));
}

function isDefaultProfileBorderColor(value) {
  return ["#e5e5e5", "#e0e4e8", "#f0f2f5"].includes(normalizeHexColor(value));
}

function shouldUseThemeModuleSurface(props, backgroundMode, backgroundImage) {
  if (backgroundMode !== "color" || backgroundImage) return false;
  const colors = [
    props.background,
    props.backgroundColorStart || props.background_color_start,
    props.backgroundColorEnd || props.background_color_end,
    props.bottomBackground || props.bottom_background,
  ]
    .map((item) => styleColor(item))
    .filter(Boolean);
  return (
    colors.length > 0 &&
    colors.every((item) => isDefaultProfileSurfaceColor(item))
  );
}

function shouldUseThemeModuleBorder(props) {
  const borderEnabled = props.borderEnabled ?? props.border_enabled;
  if (!styleBoolean(borderEnabled, true)) return false;
  const borderWidth = Number(props.borderWidth ?? props.border_width ?? 1);
  const borderStyle = String(
    props.borderStyle || props.border_style || "solid",
  );
  const borderColor = props.borderColor || props.border_color || "";
  return (
    borderWidth === 1 &&
    borderStyle === "dashed" &&
    isDefaultProfileBorderColor(borderColor)
  );
}

function normalizeFontWeight(value) {
  const weight = String(value || "");
  return ["400", "500", "600", "700", "800", "900"].includes(weight)
    ? weight
    : "";
}

function normalizeTextAlign(value) {
  const align = String(value || "");
  return ["center", "left", "right"].includes(align) ? align : "";
}

function textVisible() {
  return true;
}

function textStyle(module, role) {
  const props = getModuleProps(module);
  const textStyles = props.textStyles || props.text_styles || {};
  const styleConfig = textStyles && textStyles[role];
  if (!styleConfig || typeof styleConfig !== "object") return {};
  const style = {};
  const color = styleColor(styleConfig.color);
  if (color) style.color = color;
  const fontSize = Number(styleConfig.fontSize ?? styleConfig.font_size);
  if (Number.isFinite(fontSize) && fontSize > 0) {
    style.fontSize = `${Math.max(16, Math.min(Math.round(fontSize), 80))}rpx`;
  }
  const fontWeight = normalizeFontWeight(
    styleConfig.fontWeight ?? styleConfig.font_weight,
  );
  if (fontWeight) style.fontWeight = fontWeight;
  const fontStyle = String(
    styleConfig.fontStyle ?? styleConfig.font_style ?? "",
  );
  if (fontStyle === "italic" || styleBoolean(styleConfig.italic)) {
    style.fontStyle = "italic";
  }
  const textAlign = normalizeTextAlign(
    styleConfig.textAlign ?? styleConfig.text_align,
  );
  if (textAlign) style.textAlign = textAlign;
  return style;
}

function moduleBackgroundImage(props) {
  return normalizeProfileImageUrl(
    props.background_image || props.backgroundImage || "",
  );
}

function moduleOuterStyle(module) {
  const props = getModuleProps(module);
  const width = Math.max(50, Math.min(Number(props.widthPercent || 100), 100));
  const marginLeft = props.marginLeft ?? props.margin_left;
  const marginRight = props.marginRight ?? props.margin_right;
  const marginLeftValue =
    marginLeft === undefined ? 0 : Math.max(0, Number(marginLeft) || 0);
  const marginRightValue =
    marginRight === undefined ? 0 : Math.max(0, Number(marginRight) || 0);
  const horizontalMargin = marginLeftValue + marginRightValue;
  const style = {
    marginBottom: toRpx(props.marginBottom),
    marginTop: toRpx(props.marginTop),
    width:
      horizontalMargin > 0
        ? `calc(${width}% - ${horizontalMargin}rpx)`
        : `${width}%`,
  };
  if (width < 100) {
    style.marginLeft = "auto";
    style.marginRight = "auto";
  }
  if (marginLeft !== undefined) {
    style.marginLeft = `${marginLeftValue}rpx`;
  }
  if (marginRight !== undefined) {
    style.marginRight = `${marginRightValue}rpx`;
  }
  const componentBackground = gradientBackground(
    props.componentBackgroundStart || props.component_background_start,
    props.componentBackgroundEnd || props.component_background_end,
    props.backgroundGradientDirection || props.background_gradient_direction,
  );
  if (componentBackground) style.background = componentBackground;
  return style;
}

function moduleBoxStyle(module) {
  const props = getModuleProps(module);
  const style = {};
  const backgroundMode =
    props.backgroundMode || props.background_mode || "color";
  const backgroundImage = moduleBackgroundImage(props);
  const useThemeSurface = shouldUseThemeModuleSurface(
    props,
    backgroundMode,
    backgroundImage,
  );
  if (backgroundMode === "image" && backgroundImage) {
    style.backgroundImage = `url("${backgroundImage}")`;
    style.backgroundSize = "cover";
    style.backgroundPosition = "center";
    const fallback =
      styleColor(props.background) ||
      styleColor(props.bottomBackground || props.bottom_background);
    if (fallback) style.backgroundColor = fallback;
  } else if (!useThemeSurface) {
    const background = gradientBackground(
      props.backgroundColorStart ||
        props.background_color_start ||
        props.background,
      props.backgroundColorEnd || props.background_color_end,
      props.backgroundGradientDirection || props.background_gradient_direction,
      props.bottomBackground || props.bottom_background,
    );
    if (background) style.background = background;
  }
  if (props.radius !== undefined) {
    style.borderRadius = toRpx(props.radius);
  }
  const textColor = styleColor(props.textColor || props.text_color);
  if (textColor) {
    style.color = textColor;
    style["--color-text"] = textColor;
    style["--color-text-title"] = textColor;
    style["--color-text-secondary"] = textColor;
    style["--color-text-tertiary"] = textColor;
  }
  const borderEnabled = props.borderEnabled ?? props.border_enabled;
  if (borderEnabled !== undefined && !shouldUseThemeModuleBorder(props)) {
    if (styleBoolean(borderEnabled, true)) {
      const borderWidth = Number(props.borderWidth ?? props.border_width ?? 1);
      const borderStyle = props.borderStyle || props.border_style || "solid";
      const borderColor =
        styleColor(props.borderColor || props.border_color) ||
        "var(--color-divider, #f0f2f5)";
      style.border = `${borderWidth}rpx ${borderStyle} ${borderColor}`;
    } else {
      style.border = "0";
    }
  }
  const shadowEnabled = props.shadowEnabled ?? props.shadow_enabled;
  if (shadowEnabled !== undefined) {
    const boxShadow = moduleShadowStyle(props);
    if (boxShadow) style.boxShadow = boxShadow;
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
    style.padding = `${toRpx(paddingTop)} ${toRpx(paddingRight)} ${toRpx(
      paddingBottom,
    )} ${toRpx(paddingLeft)}`;
  } else if (props.paddingY !== undefined || props.paddingX !== undefined) {
    const padding = props.padding ?? 0;
    const paddingY = props.paddingY ?? props.padding_y ?? padding;
    const paddingX = props.paddingX ?? props.padding_x ?? padding;
    style.padding = `${toRpx(paddingY)} ${toRpx(paddingX)}`;
  } else if (props.padding !== undefined) {
    style.padding = toRpx(props.padding);
  }
  return style;
}

function profileModuleVisible(module) {
  if (module.type === "wallet") return balancePaymentEnabled.value;
  if (module.type === "pointsEntry") return pointsEnabled.value;
  if (module.type === "logout") return logged.value;
  return [
    "divider",
    "orderShortcut",
    "pointsEntry",
    "richText",
    "serviceMenu",
    "spacing",
    "title",
    "userCard",
  ].includes(module.type);
}

function getProfileIconPreset(item) {
  const source = [
    item?.key,
    item?.icon,
    item?.action,
    item?.label,
    item?.title,
    item?.text,
  ]
    .filter(Boolean)
    .join(" ")
    .toLowerCase();
  return profileIconPresets.find((preset) =>
    preset.keywords.some((keyword) => source.includes(keyword.toLowerCase())),
  );
}

function profileIconType(item) {
  return getProfileIconPreset(item)?.type || "default";
}

function profileIconText(item) {
  const presetText = getProfileIconPreset(item)?.text;
  if (presetText) return presetText;
  const label = item?.label || item?.title || item?.text || "";
  return label ? label.slice(0, 1) : "•";
}

function getProfileEntryImage(item) {
  if (isProfileEntryImageRemoved(item)) return "";
  return normalizeProfileImageUrl(
    item?.full_url ||
      item?.fullUrl ||
      item?.thumbUrl ||
      item?.thumb_url ||
      item?.response?.full_url ||
      item?.response?.fullUrl ||
      item?.response?.url ||
      item?.image_full_url ||
      item?.imageFullUrl ||
      item?.image ||
      item?.image_url ||
      item?.imageUrl ||
      "",
  );
}

function looksLikeProfileImageUrl(url) {
  if (!url || typeof url !== "string") return false;
  if (url.startsWith("/pages")) return false;
  if (url.startsWith("/static") || url.startsWith("static/")) return true;
  if (url.startsWith("/uploads") || url.startsWith("uploads/")) return true;
  if (/^https?:\/\//.test(url)) return true;
  if (/^(data:image|blob:)/.test(url)) return true;
  return /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(url);
}

function normalizeProfileImageUrl(url) {
  if (url && typeof url === "object") {
    return getProfileEntryImage(url);
  }
  if (!looksLikeProfileImageUrl(url)) return "";
  if (/^(https?:|data:image|blob:)/.test(url)) return url;

  const normalizedPath = url.startsWith("/") ? url : `/${url}`;
  return config.baseUrl ? `${config.baseUrl}${normalizedPath}` : normalizedPath;
}

function goLogin() {
  uni.navigateTo({ url: "/pages-sub/user/login" });
}

function goEditProfile() {
  if (userStore.isLoggedIn) {
    uni.navigateTo({ url: "/pages-sub/user/edit-profile" });
    return;
  }
  goLogin();
}

function goOrders(shortcut) {
  if (!userStore.isLoggedIn) {
    goLogin();
    return;
  }
  if (shortcut?.path) {
    uni.navigateTo({ url: shortcut.path });
    return;
  }
  if (shortcut?.key) {
    uni.setStorageSync("order_initial_tab", shortcut.key);
  }
  uni.switchTab({ url: "/pages/order/index" });
}

function goAllOrders() {
  if (!userStore.isLoggedIn) {
    goLogin();
    return;
  }
  uni.switchTab({ url: "/pages/order/index" });
}

function goWallet() {
  if (!userStore.isLoggedIn) {
    goLogin();
    return;
  }
  uni.navigateTo({ url: "/pages-sub/wallet/index" });
}

function goWalletRecords() {
  if (!userStore.isLoggedIn) {
    goLogin();
    return;
  }
  uni.navigateTo({ url: "/pages-sub/wallet/records" });
}

function goPoints() {
  if (!userStore.isLoggedIn) {
    goLogin();
    return;
  }
  if (!pointsEnabled.value) {
    uni.showToast({ title: "积分功能未开启", icon: "none" });
    return;
  }
  uni.navigateTo({ url: "/pages-sub/points/index" });
}

function goPointsRecords() {
  if (!userStore.isLoggedIn) {
    goLogin();
    return;
  }
  if (!pointsEnabled.value) {
    uni.showToast({ title: "积分功能未开启", icon: "none" });
    return;
  }
  uni.navigateTo({ url: "/pages-sub/points/records" });
}

async function callCustomerService() {
  await openCustomerService();
}

async function goCell(cell) {
  if (isThemeEntry(cell)) {
    await showThemeSelector();
    return;
  }
  if (isCustomerServiceEntry(cell) && !cell.path && !cell.url) {
    await callCustomerService();
    return;
  }
  if (!cell.path && !cell.url) {
    uni.showToast({ title: "即将开放", icon: "none" });
    return;
  }
  if (cell.auth !== false && !userStore.isLoggedIn) {
    goLogin();
    return;
  }
  uni.navigateTo({ url: cell.path || cell.url });
}

async function showThemeSelector() {
  await decorateStore.openThemeSelector();
}

function handleLogout() {
  uni.showModal({
    title: "提示",
    content: "确定退出登录吗？",
    success: async (res) => {
      if (res.confirm) {
        await userStore.logout();
        await decorateStore.fetchMyThemePreference({ force: true });
        uni.showToast({ title: "已退出登录", icon: "none" });
      }
    },
  });
}
</script>

<template>
  <view
    class="page"
    :class="[
      `theme-${decorateStore.resolvedThemeMode}`,
      { 'page--custom-tabbar': decorateStore.tabbarMode === 'custom' },
    ]"
    :style="decorateStore.themeStyle"
  >
    <mb-navbar
      title="MallBase"
      :back="false"
      bg-color="var(--color-bg, #ffffff)"
    />

    <view class="profile-modules" :style="profilePageStyle">
      <template v-for="module in profileModules" :key="module.id">
        <view
          v-if="profileModuleVisible(module)"
          class="profile-module-shell"
          :style="moduleOuterStyle(module)"
        >
          <view
            v-if="module.type === 'userCard'"
            class="profile-header"
            :style="moduleBoxStyle(module)"
          >
            <view
              v-if="logged"
              class="profile-header__body"
              @tap="goEditProfile"
            >
              <image
                v-if="avatar"
                class="profile-header__avatar"
                :src="avatar"
                mode="aspectFill"
              />
              <view
                v-else
                class="profile-header__avatar profile-header__avatar--placeholder"
              >
                <text class="profile-header__avatar-text">{{
                  (nickname || "M").slice(0, 1)
                }}</text>
              </view>
              <view class="profile-header__main">
                <text
                  v-if="textVisible(module, 'title')"
                  class="profile-header__nickname"
                  :style="textStyle(module, 'title')"
                  >{{ nickname || "MallBase 用户" }}</text
                >
                <text
                  v-if="
                    showUserMobile(module) && textVisible(module, 'subtitle')
                  "
                  class="profile-header__mobile"
                  :style="textStyle(module, 'subtitle')"
                  >{{ mobile }}</text
                >
                <text
                  v-if="textVisible(module, 'meta')"
                  class="profile-header__bio"
                  :style="textStyle(module, 'meta')"
                  >{{ bio }}</text
                >
                <view
                  class="profile-header__edit-btn"
                  @tap.stop="goEditProfile"
                >
                  <text class="profile-header__edit-text">资料编辑</text>
                </view>
              </view>
            </view>
            <view v-else class="profile-header__body" @tap="goLogin">
              <view
                class="profile-header__avatar profile-header__avatar--placeholder"
              >
                <text class="profile-header__avatar-text">M</text>
              </view>
              <view class="profile-header__main">
                <text
                  v-if="textVisible(module, 'title')"
                  class="profile-header__nickname"
                  :style="textStyle(module, 'title')"
                  >点击登录</text
                >
                <text
                  v-if="textVisible(module, 'subtitle')"
                  class="profile-header__mobile"
                  :style="textStyle(module, 'subtitle')"
                  >登录后享受更多服务</text
                >
                <text
                  v-if="textVisible(module, 'meta')"
                  class="profile-header__bio"
                  :style="textStyle(module, 'meta')"
                  >完善资料后可展示个性签名</text
                >
              </view>
            </view>
          </view>

          <view
            v-else-if="module.type === 'wallet' && balancePaymentEnabled"
            class="wallet-card"
            :style="moduleBoxStyle(module)"
            @tap="goWallet"
          >
            <view class="wallet-card__main">
              <text
                v-if="textVisible(module, 'title')"
                class="wallet-card__label"
                :style="textStyle(module, 'title')"
                >{{ module.props.title || "我的余额" }}</text
              >
              <view
                v-if="
                  showWalletBalance(module) && textVisible(module, 'amount')
                "
                class="wallet-card__amount"
              >
                <text
                  class="wallet-card__symbol"
                  :style="textStyle(module, 'amount')"
                  >¥</text
                >
                <text
                  class="wallet-card__value"
                  :style="textStyle(module, 'amount')"
                  >{{ logged ? walletBalance : "0.00" }}</text
                >
              </view>
              <view
                v-if="showWalletBalance(module) && textVisible(module, 'meta')"
                class="wallet-card__meta"
              >
                <text
                  class="wallet-card__meta-text"
                  :style="textStyle(module, 'meta')"
                  >累计充值 ¥{{ logged ? walletRecharge : "0.00" }}</text
                >
                <text
                  class="wallet-card__dot"
                  :style="textStyle(module, 'meta')"
                  >•</text
                >
                <text
                  class="wallet-card__meta-text"
                  :style="textStyle(module, 'meta')"
                  >累计消费 ¥{{ logged ? walletConsume : "0.00" }}</text
                >
              </view>
            </view>
            <view
              v-if="walletActions(module).length > 0"
              class="wallet-card__actions"
            >
              <view
                v-for="action in walletActions(module)"
                :key="action.key"
                class="wallet-card__action"
                :class="{ 'wallet-card__action--primary': action.primary }"
                @tap.stop="tapWalletAction(action)"
              >
                <text
                  class="wallet-card__action-text"
                  :class="{
                    'wallet-card__action-text--primary': action.primary,
                  }"
                  :style="
                    textStyle(
                      module,
                      action.primary ? 'primaryAction' : 'action',
                    )
                  "
                >
                  {{
                    textVisible(
                      module,
                      action.primary ? "primaryAction" : "action",
                    )
                      ? action.label
                      : ""
                  }}
                </text>
              </view>
            </view>
          </view>

          <view
            v-else-if="module.type === 'pointsEntry'"
            class="points-card"
            :style="moduleBoxStyle(module)"
            @tap="goPoints"
          >
            <view class="points-card__main">
              <text
                v-if="textVisible(module, 'title')"
                class="points-card__label"
                :style="textStyle(module, 'title')"
                >{{ module.props.title || "我的积分" }}</text
              >
              <view
                v-if="textVisible(module, 'amount')"
                class="points-card__amount"
              >
                <text
                  class="points-card__value"
                  :style="textStyle(module, 'amount')"
                  >{{ logged ? pointsBalance : 0 }}</text
                >
                <text
                  class="points-card__unit"
                  :style="textStyle(module, 'amount')"
                  >积分</text
                >
              </view>
              <view
                v-if="textVisible(module, 'meta')"
                class="points-card__meta"
              >
                <text
                  class="points-card__meta-text"
                  :style="textStyle(module, 'meta')"
                  >累计获得 {{ logged ? pointsIncome : 0 }}</text
                >
                <text
                  class="points-card__dot"
                  :style="textStyle(module, 'meta')"
                  >•</text
                >
                <text
                  class="points-card__meta-text"
                  :style="textStyle(module, 'meta')"
                  >累计使用 {{ logged ? pointsExpense : 0 }}</text
                >
              </view>
            </view>
            <view
              v-if="pointsActions(module).length > 0"
              class="points-card__actions"
            >
              <view
                v-for="action in pointsActions(module)"
                :key="action.key"
                class="points-card__action"
                :class="{ 'points-card__action--primary': action.primary }"
                @tap.stop="tapPointsAction(action)"
              >
                <text
                  class="points-card__action-text"
                  :class="{
                    'points-card__action-text--primary': action.primary,
                  }"
                  :style="
                    textStyle(
                      module,
                      action.primary ? 'primaryAction' : 'action',
                    )
                  "
                >
                  {{
                    textVisible(
                      module,
                      action.primary ? "primaryAction" : "action",
                    )
                      ? action.label
                      : ""
                  }}
                </text>
              </view>
            </view>
          </view>

          <view
            v-else-if="module.type === 'orderShortcut'"
            class="order-card"
            :style="moduleBoxStyle(module)"
          >
            <view class="order-card__title-row">
              <text
                v-if="textVisible(module, 'title')"
                class="order-card__title"
                :style="textStyle(module, 'title')"
                >{{ module.props.title || "我的订单" }}</text
              >
              <view
                v-if="textVisible(module, 'more')"
                class="order-card__all"
                @tap="goAllOrders"
              >
                <text
                  class="order-card__all-text"
                  :style="textStyle(module, 'more')"
                  >查看全部</text
                >
                <view class="arrow-icon arrow-icon--sm" />
              </view>
            </view>
            <view class="order-card__grid">
              <view
                v-for="item in moduleList(module)"
                :key="item.key || item.label"
                class="order-card__item"
                @tap="goOrders(item)"
              >
                <view
                  class="order-dot"
                  :class="`profile-icon--${profileIconType(item)}`"
                >
                  <image
                    v-if="getProfileEntryImage(item)"
                    class="order-dot__image"
                    :src="getProfileEntryImage(item)"
                    mode="aspectFill"
                  />
                  <text
                    v-else
                    class="order-dot__icon"
                    :style="textStyle(module, 'iconText')"
                    >{{ profileIconText(item) }}</text
                  >
                </view>
                <text
                  v-if="textVisible(module, 'itemLabel')"
                  class="order-card__label"
                  :style="textStyle(module, 'itemLabel')"
                  >{{ item.label }}</text
                >
              </view>
            </view>
          </view>

          <view
            v-else-if="module.type === 'serviceMenu'"
            class="cell-group"
            :style="moduleBoxStyle(module)"
          >
            <view
              v-if="module.props.title && textVisible(module, 'title')"
              class="cell-group__head"
            >
              <text
                class="cell-group__title"
                :style="textStyle(module, 'title')"
                >{{ module.props.title }}</text
              >
            </view>
            <view
              v-if="serviceMenuIsGrid(module)"
              class="service-grid"
              :style="serviceGridStyle(module)"
            >
              <view
                v-for="cell in moduleList(module)"
                :key="cell.key || cell.label"
                class="service-grid__item"
                @tap="goCell(cell)"
              >
                <view
                  class="cell__icon-wrap cell__icon-wrap--grid"
                  :class="`profile-icon--${profileIconType(cell)}`"
                >
                  <image
                    v-if="getProfileEntryImage(cell)"
                    class="cell__icon-image"
                    :src="getProfileEntryImage(cell)"
                    mode="aspectFill"
                  />
                  <text
                    v-else
                    class="cell__icon"
                    :style="textStyle(module, 'iconText')"
                    >{{ profileIconText(cell) }}</text
                  >
                </view>
                <text
                  v-if="textVisible(module, 'itemLabel')"
                  class="service-grid__label"
                  :style="textStyle(module, 'itemLabel')"
                  >{{ cell.label }}</text
                >
              </view>
            </view>
            <template v-else>
              <view
                v-for="(cell, ci) in moduleList(module)"
                :key="cell.key || cell.label"
                class="cell"
                :class="{ 'cell--last': ci === moduleList(module).length - 1 }"
                @tap="goCell(cell)"
              >
                <view
                  class="cell__icon-wrap"
                  :class="`profile-icon--${profileIconType(cell)}`"
                >
                  <image
                    v-if="getProfileEntryImage(cell)"
                    class="cell__icon-image"
                    :src="getProfileEntryImage(cell)"
                    mode="aspectFill"
                  />
                  <text
                    v-else
                    class="cell__icon"
                    :style="textStyle(module, 'iconText')"
                    >{{ profileIconText(cell) }}</text
                  >
                </view>
                <text
                  v-if="textVisible(module, 'itemLabel')"
                  class="cell__label"
                  :style="textStyle(module, 'itemLabel')"
                  >{{ cell.label }}</text
                >
                <view class="cell__spacer" />
                <view class="arrow-icon" />
              </view>
            </template>
          </view>

          <view
            v-else-if="module.type === 'title'"
            class="plain-title"
            :style="moduleBoxStyle(module)"
          >
            <text class="plain-title__text">{{
              module.props.text || module.props.title
            }}</text>
          </view>

          <view
            v-else-if="module.type === 'richText'"
            class="plain-rich"
            :style="moduleBoxStyle(module)"
          >
            <rich-text
              :nodes="module.props.content || module.props.html || ''"
            />
          </view>

          <view
            v-else-if="module.type === 'spacing'"
            :style="{ height: `${Number(module.props.height || 24)}rpx` }"
          />

          <view v-else-if="module.type === 'divider'" class="plain-divider" />

          <view
            v-else-if="module.type === 'logout' && logged"
            class="logout-wrap"
          >
            <view class="logout-btn" @tap="handleLogout">
              <text class="logout-btn__text">{{
                module.props.text || "退出登录"
              }}</text>
            </view>
          </view>
        </view>
      </template>
    </view>

    <view v-if="decorateStore.tabbarMode === 'custom'" class="bottom-spacer" />
    <mb-custom-tabbar current="/pages/profile/index" />
    <mb-floating-action />
  </view>
</template>

<style lang="scss" scoped>
.page {
  min-height: 100vh;
  background: var(--color-bg-secondary, #faf8ff);
}

.profile-modules {
  display: flex;
  flex-direction: column;
  gap: 24rpx;
  box-sizing: border-box;
  padding: 0 28rpx 24rpx;
}

.profile-module-shell {
  box-sizing: border-box;
}

.profile-header {
  padding: 28rpx 28rpx 36rpx;
  background: linear-gradient(
    180deg,
    rgba(13, 80, 213, 0.1) 0%,
    var(--color-bg-secondary, #faf8ff) 100%
  );
}

.profile-header__body {
  display: flex;
  align-items: center;
  gap: 24rpx;
}

.profile-header__avatar {
  width: 92rpx;
  height: 92rpx;
  border-radius: 999rpx;
  flex-shrink: 0;
  background: var(--color-bg-surface, #f3f3fe);
}

.profile-header__avatar--placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
}

.profile-header__avatar-text {
  font-size: 34rpx;
  font-weight: 800;
  color: var(--color-primary, #0d50d5);
}

.profile-header__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.profile-header__nickname {
  display: block;
  width: 100%;
  font-size: 30rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
  line-height: 1.3;
}

.profile-header__mobile {
  display: block;
  width: 100%;
  margin-top: 6rpx;
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.profile-header__bio {
  display: block;
  width: 100%;
  margin-top: 8rpx;
  font-size: 22rpx;
  color: var(--color-text-secondary, #434654);
  line-height: 1.5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.profile-header__edit-btn {
  margin-top: 12rpx;
  height: 48rpx;
  padding: 0 20rpx;
  border-radius: 999rpx;
  border: 1rpx solid var(--color-primary-border, rgba(13, 80, 213, 0.45));
  background: var(--color-primary-softer, rgba(13, 80, 213, 0.06));
  align-self: flex-start;
  display: flex;
  align-items: center;
}

.profile-header__edit-text {
  font-size: 22rpx;
  color: var(--color-primary, #0d50d5);
  font-weight: 600;
}

.wallet-card,
.points-card,
.order-card,
.cell-group,
.plain-rich {
  background: var(--color-bg, #ffffff);
  border-radius: 20rpx;
  border: 1rpx solid var(--color-divider, #f0f2f5);
}

.wallet-card {
  padding: 28rpx;
}

.points-card {
  padding: 28rpx;
}

.wallet-card__label {
  display: block;
  width: 100%;
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
}

.wallet-card__amount {
  display: flex;
  align-items: baseline;
  margin-top: 10rpx;
}

.wallet-card__symbol {
  font-size: 32rpx;
  color: var(--color-text-title, #191b23);
  font-weight: 700;
}

.wallet-card__value {
  margin-left: 4rpx;
  font-size: 52rpx;
  line-height: 1;
  color: var(--color-text-title, #191b23);
  font-weight: 800;
}

.wallet-card__meta,
.wallet-card__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12rpx;
}

.wallet-card__meta {
  margin-top: 16rpx;
}

.wallet-card__meta-text,
.wallet-card__dot {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
}

.wallet-card__actions {
  margin-top: 24rpx;
}

.wallet-card__action {
  flex: 1;
  min-height: 64rpx;
  padding: 12rpx 24rpx;
  box-sizing: border-box;
  border-radius: 999rpx;
  background: var(--color-bg-surface, #f3f3fe);
  display: flex;
  align-items: center;
  justify-content: center;
}

.wallet-card__action--primary {
  background: var(--color-primary, #0d50d5);
}

.wallet-card__action-text {
  font-size: 24rpx;
  line-height: 1.2;
  color: var(--color-text-secondary, #434654);
  font-weight: 600;
}

.wallet-card__action-text--primary {
  color: #ffffff;
}

.points-card__label {
  display: block;
  width: 100%;
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
}

.points-card__amount {
  display: flex;
  align-items: baseline;
  gap: 10rpx;
  margin-top: 10rpx;
}

.points-card__value {
  font-size: 52rpx;
  line-height: 1;
  color: var(--color-text-title, #191b23);
  font-weight: 800;
}

.points-card__unit {
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
  font-weight: 700;
}

.points-card__meta,
.points-card__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12rpx;
}

.points-card__meta {
  margin-top: 16rpx;
}

.points-card__meta-text,
.points-card__dot {
  font-size: 22rpx;
  color: var(--color-text-tertiary, #737686);
}

.points-card__actions {
  margin-top: 24rpx;
}

.points-card__action {
  flex: 1;
  min-height: 64rpx;
  padding: 12rpx 24rpx;
  box-sizing: border-box;
  border-radius: 999rpx;
  background: var(--color-bg-surface, #f3f3fe);
  display: flex;
  align-items: center;
  justify-content: center;
}

.points-card__action--primary {
  background: var(--color-primary, #0d50d5);
}

.points-card__action-text {
  font-size: 24rpx;
  line-height: 1.2;
  color: var(--color-text-secondary, #434654);
  font-weight: 600;
}

.points-card__action-text--primary {
  color: #ffffff;
}

.order-card {
  padding: 28rpx;
}

.order-card__title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 28rpx;
}

.order-card__title {
  flex: 1;
  min-width: 0;
  font-size: 30rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.order-card__all {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 6rpx;
}

.order-card__all-text {
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.order-card__grid {
  display: flex;
}

.order-card__item {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14rpx;
}

.order-dot {
  width: 80rpx;
  height: 80rpx;
  border-radius: 18rpx;
  background: var(--profile-icon-bg, rgba(13, 80, 213, 0.08));
  display: flex;
  align-items: center;
  justify-content: center;
}

.order-dot__icon {
  font-size: 32rpx;
  line-height: 1;
  color: var(--profile-icon-color, var(--color-primary, #0d50d5));
  font-weight: 700;
}

.order-dot__image,
.cell__icon-image {
  width: 100%;
  height: 100%;
  border-radius: inherit;
}

.order-card__label {
  display: block;
  width: 100%;
  text-align: center;
  font-size: 24rpx;
  line-height: 1.2;
  color: var(--color-text-secondary, #434654);
}

.cell-group {
  overflow: hidden;
}

.cell-group__head {
  padding: 28rpx 28rpx 8rpx;
}

.cell-group__title {
  display: block;
  width: 100%;
  font-size: 30rpx;
  font-weight: 700;
  color: var(--color-text-title, #191b23);
}

.service-grid {
  display: grid;
  gap: 20rpx 8rpx;
  padding: 24rpx 24rpx 28rpx;
}

.service-grid__item {
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12rpx;
}

.service-grid__label {
  display: block;
  max-width: 100%;
  width: 100%;
  overflow: hidden;
  font-size: 24rpx;
  color: var(--color-text-secondary, #434654);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cell {
  display: flex;
  align-items: center;
  padding: 28rpx;
  position: relative;

  &:not(.cell--last)::after {
    content: "";
    position: absolute;
    left: 96rpx;
    right: 28rpx;
    bottom: 0;
    height: 1rpx;
    background: var(--color-divider, #f0f2f5);
  }
}

.cell__icon-wrap {
  width: 52rpx;
  height: 52rpx;
  border-radius: 12rpx;
  background: var(--profile-icon-bg, rgba(13, 80, 213, 0.08));
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 24rpx;
}

.cell__icon-wrap--grid {
  width: 64rpx;
  height: 64rpx;
  margin-right: 0;
}

.cell__icon {
  font-size: 22rpx;
  line-height: 1;
  color: var(--profile-icon-color, var(--color-primary, #0d50d5));
  font-weight: 700;
}

.profile-icon--pay {
  --profile-icon-bg: rgba(13, 80, 213, 0.1);
  --profile-icon-color: #0d50d5;
}

.profile-icon--ship {
  --profile-icon-bg: rgba(0, 128, 96, 0.1);
  --profile-icon-color: #007a5a;
}

.profile-icon--receive {
  --profile-icon-bg: rgba(86, 77, 196, 0.1);
  --profile-icon-color: #564dc4;
}

.profile-icon--refund {
  --profile-icon-bg: rgba(204, 94, 36, 0.12);
  --profile-icon-color: #b8501d;
}

.profile-icon--address {
  --profile-icon-bg: rgba(0, 118, 196, 0.1);
  --profile-icon-color: #006fb8;
}

.profile-icon--favorite {
  --profile-icon-bg: rgba(213, 62, 107, 0.1);
  --profile-icon-color: #c42c62;
}

.profile-icon--theme {
  --profile-icon-bg: rgba(122, 89, 0, 0.12);
  --profile-icon-color: #805d00;
}

.profile-icon--service {
  --profile-icon-bg: rgba(0, 132, 135, 0.1);
  --profile-icon-color: #007f82;
}

.cell__label {
  min-width: 0;
  font-size: 28rpx;
  color: var(--color-text, #191b23);
  font-weight: 500;
}

.cell__spacer {
  flex: 1;
}

.cell__value {
  margin-right: 12rpx;
  font-size: 24rpx;
  color: var(--color-text-tertiary, #737686);
}

.arrow-icon {
  width: 16rpx;
  height: 16rpx;
  border-right: 3rpx solid var(--color-text-tertiary, #737686);
  border-bottom: 3rpx solid var(--color-text-tertiary, #737686);
  transform: rotate(-45deg);
  flex-shrink: 0;
}

.arrow-icon--sm {
  width: 12rpx;
  height: 12rpx;
  border-width: 2rpx;
}

.plain-title {
  margin: 0 28rpx;
}

.plain-title__text {
  font-size: 32rpx;
  font-weight: 800;
  color: var(--color-text-title, #191b23);
}

.plain-rich {
  padding: 24rpx;
  color: var(--color-text, #191b23);
}

.plain-divider {
  margin: 0 28rpx;
  height: 1rpx;
  background: var(--color-divider, #f0f2f5);
}

.logout-wrap {
  padding: 0 28rpx;
}

.logout-btn {
  height: 88rpx;
  border-radius: 16rpx;
  background: var(--color-bg, #ffffff);
  border: 1rpx solid var(--color-divider, #f0f2f5);
  display: flex;
  align-items: center;
  justify-content: center;
}

.logout-btn__text {
  font-size: 28rpx;
  color: var(--color-error, #ba1a1a);
  font-weight: 600;
}

.bottom-spacer {
  height: 144rpx;
}
</style>
