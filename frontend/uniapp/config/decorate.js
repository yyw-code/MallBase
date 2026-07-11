import { DEFAULT_DARK_THEME, DEFAULT_LIGHT_THEME } from "@/config/theme";

export const DEFAULT_TABBAR_ITEMS = [
  {
    key: "home",
    text: "首页",
    pagePath: "/pages/index/index",
    iconPath: "/static/images/tabbar/home.png",
    selectedIconPath: "/static/images/tabbar/home-active.png",
  },
  {
    key: "category",
    text: "分类",
    pagePath: "/pages/category/index",
    iconPath: "/static/images/tabbar/category.png",
    selectedIconPath: "/static/images/tabbar/category-active.png",
  },
  {
    key: "cart",
    text: "购物车",
    pagePath: "/pages/cart/index",
    iconPath: "/static/images/tabbar/cart.png",
    selectedIconPath: "/static/images/tabbar/cart-active.png",
  },
  {
    key: "order",
    text: "订单",
    pagePath: "/pages/order/index",
    iconPath: "/static/images/tabbar/order.png",
    selectedIconPath: "/static/images/tabbar/order-active.png",
  },
  {
    key: "profile",
    text: "我的",
    pagePath: "/pages/profile/index",
    iconPath: "/static/images/tabbar/profile.png",
    selectedIconPath: "/static/images/tabbar/profile-active.png",
  },
];

export const DEFAULT_HOME_MODULES = [
  {
    id: "search-default",
    type: "search",
    sort: 10,
    props: {
      placeholder: "搜索商品、分类或品牌",
      target_path: "/pages-sub/goods/list",
      marginTop: 4,
      marginBottom: 8,
      paddingY: 12,
      paddingX: 20,
      radius: 36,
      widthPercent: 100,
    },
  },
  {
    id: "banner-default",
    type: "banner",
    sort: 20,
    props: {
      height: 314,
      radius: 12,
      list: [
        {
          image: "/static/decorate/decorate-banner-market.png",
          title: "夏日好物限时满减",
          url: "/pages-sub/goods/list?is_recommend=1",
        },
        {
          image: "/static/decorate/decorate-banner-member.png",
          title: "会员精选 每日上新",
          url: "/pages-sub/goods/list?sort=sales",
        },
      ],
    },
  },
  {
    id: "nav-grid-default",
    type: "navGrid",
    sort: 30,
    props: {
      columns: 3,
      marginTop: 4,
      marginBottom: 18,
      paddingY: 20,
      paddingX: 20,
      radius: 24,
      widthPercent: 100,
      items: [
        {
          label: "数码",
          url: "/pages-sub/goods/list?keyword=数码",
          image: "/static/decorate/decorate-nav-digital.png",
        },
        {
          label: "美妆",
          url: "/pages-sub/goods/list?keyword=美妆",
          image: "/static/decorate/decorate-nav-beauty.png",
        },
        {
          label: "服饰",
          url: "/pages-sub/goods/list?keyword=服饰",
          image: "/static/decorate/decorate-nav-fashion.png",
        },
        {
          label: "家居",
          url: "/pages-sub/goods/list?keyword=家居",
          image: "/static/decorate/decorate-nav-home.png",
        },
        {
          label: "美食",
          url: "/pages-sub/goods/list?keyword=美食",
          image: "/static/decorate/decorate-nav-food.png",
        },
      ],
    },
  },
  {
    id: "title-recommend-default",
    type: "title",
    sort: 35,
    props: {
      title: "人气推荐",
      sub_title: "严选好物正在热卖",
      more_text: "查看全部",
      more_path: "/pages-sub/goods/list?is_recommend=1",
      marginTop: 4,
      marginBottom: 8,
      paddingY: 4,
      paddingX: 30,
      title_font_size: 34,
      sub_font_size: 22,
      widthPercent: 100,
    },
  },
  {
    id: "must-buy-default",
    type: "productGroup",
    sort: 40,
    props: {
      title: "精选好物",
      subtitle: "精选好物实时更新",
      source: "recommend",
      layout: "grid",
      limit: 8,
      moreText: "查看全部",
      moreUrl: "/pages-sub/goods/list?is_recommend=1",
      marginTop: 4,
      marginBottom: 24,
      paddingY: 20,
      paddingX: 20,
      radius: 24,
      widthPercent: 100,
    },
  },
  {
    id: "guess-default",
    type: "productGroup",
    sort: 50,
    props: {
      title: "猜你喜欢",
      subtitle: "根据浏览偏好持续更新",
      source: "filter",
      layout: "grid",
      limit: 10,
      pageable: true,
      marginTop: 4,
      marginBottom: 24,
      paddingY: 20,
      paddingX: 20,
      radius: 24,
      widthPercent: 100,
    },
  },
];

const DEFAULT_PROFILE_STYLE = {
  background: "",
  backgroundColorEnd: "",
  backgroundColorStart: "",
  backgroundGradientDirection: "horizontal",
  backgroundMode: "color",
  background_image: "",
  borderColor: "",
  borderEnabled: true,
  borderStyle: "solid",
  borderWidth: 1,
  marginBottom: 0,
  marginLeft: 0,
  marginRight: 0,
  marginTop: 0,
  padding: 0,
  radius: 20,
  shadowEnabled: false,
  widthPercent: 100,
};

const DEFAULT_PROFILE_USER_STYLE = {
  ...DEFAULT_PROFILE_STYLE,
  paddingX: 28,
  paddingY: 28,
  radius: 0,
};

const DEFAULT_PROFILE_CARD_STYLE = {
  ...DEFAULT_PROFILE_STYLE,
  paddingX: 28,
  paddingY: 28,
};

const DEFAULT_PROFILE_MENU_STYLE = {
  ...DEFAULT_PROFILE_STYLE,
  paddingX: 10,
  paddingY: 0,
};

export const DEFAULT_PROFILE_MODULES = [
  {
    id: "profile-user",
    type: "userCard",
    sort: 10,
    props: {
      ...DEFAULT_PROFILE_USER_STYLE,
      show_mobile: true,
    },
  },
  {
    id: "profile-member",
    type: "memberEntry",
    sort: 20,
    props: {
      ...DEFAULT_PROFILE_CARD_STYLE,
      title: "会员等级",
      show_discount: true,
      show_growth: true,
      show_progress: true,
    },
  },
  {
    id: "profile-orders",
    type: "orderShortcut",
    sort: 30,
    props: {
      ...DEFAULT_PROFILE_CARD_STYLE,
      title: "我的订单",
      display: "grid",
      items: [
        {
          key: "pending_pay",
          label: "待付款",
          image: "static/decorate/profile-order-pay.svg",
          path: "/pages-sub/order/list?status=10",
        },
        {
          key: "paid",
          label: "待发货",
          image: "static/decorate/profile-order-ship.svg",
          path: "/pages-sub/order/list?status=20",
        },
        {
          key: "shipped",
          label: "待收货",
          image: "static/decorate/profile-order-receive.svg",
          path: "/pages-sub/order/list?status=30",
        },
        {
          key: "refund",
          label: "退款售后",
          image: "static/decorate/profile-order-refund.svg",
          path: "/pages-sub/refund/list",
        },
      ],
    },
  },
  {
    id: "profile-wallet",
    type: "wallet",
    sort: 40,
    props: {
      ...DEFAULT_PROFILE_CARD_STYLE,
      title: "我的余额",
      show_balance: true,
      show_records: true,
      show_view_button: true,
    },
  },
  {
    id: "profile-points",
    type: "points",
    sort: 45,
    props: {
      ...DEFAULT_PROFILE_CARD_STYLE,
      title: "我的积分",
      show_records: true,
      show_view_button: true,
    },
  },
  {
    id: "profile-distribution",
    type: "distributionEntry",
    sort: 47,
    props: {
      ...DEFAULT_PROFILE_CARD_STYLE,
      title: "分销中心",
      show_commission: true,
      show_team: true,
      show_invite: true,
      show_withdraw_button: true,
      show_records: true,
    },
  },
  {
    id: "profile-service",
    type: "serviceMenu",
    sort: 50,
    props: {
      ...DEFAULT_PROFILE_MENU_STYLE,
      title: "我的服务",
      columns: 4,
      display: "list",
      items: [
        {
          key: "address",
          label: "地址管理",
          image: "static/decorate/profile-service-address.svg",
          path: "/pages-sub/address/list",
        },
        {
          key: "settings",
          label: "系统设置",
          image: "static/decorate/profile-service-settings.svg",
          path: "/pages-sub/user/settings",
        },
        {
          key: "service",
          label: "联系客服",
          image: "static/decorate/profile-service-support.svg",
          path: "",
        },
      ],
    },
  },
  { id: "profile-logout", type: "logout", sort: 50, props: {} },
];

export const DEFAULT_FLOATING_CONFIG = {
  enabled: true,
  hiddenPages: ["/pages-sub/user/login", "/pages-sub/user/agreement"],
  items: [
    {
      enabled: true,
      icon: "static/decorate/floating/service.png",
      id: "floating-service",
      text: "客服",
      type: "customerService",
    },
    {
      enabled: true,
      icon: "static/decorate/floating/cart.png",
      id: "floating-cart",
      path: "/pages/cart/index",
      text: "购物车",
      type: "page",
    },
    {
      enabled: true,
      icon: "static/decorate/floating/home.png",
      id: "floating-home",
      path: "/pages/index/index",
      text: "首页",
      type: "page",
    },
  ],
  mode: "expand",
  offsetBottom: 160,
  offsetX: 24,
  position: "right-bottom",
  singleItemId: "",
  style: {
    backgroundColor: "",
    color: "",
    radius: 44,
    shadowBlur: 30,
    shadowColor: "#0f172a",
    shadowEnabled: true,
    shadowOffsetX: 0,
    shadowOffsetY: 12,
    shadowOpacity: 14,
    shadowSpread: 0,
    size: 88,
  },
};

export const DEFAULT_DECORATE_CONFIG = {
  floating: DEFAULT_FLOATING_CONFIG,
  home: {
    modules: DEFAULT_HOME_MODULES,
    pageStyle: {
      backgroundColorEnd: "",
      backgroundColorStart: "",
      backgroundGradientDirection: "horizontal",
      backgroundMode: "color",
      background_image: "",
      padding: 14,
      paddingBottom: 0,
      paddingLeft: 28,
      paddingRight: 28,
      paddingTop: 0,
      paddingX: 28,
      paddingY: 0,
    },
  },
  profile: {
    modules: DEFAULT_PROFILE_MODULES,
    pageStyle: {
      paddingTop: 10,
      paddingX: 28,
    },
  },
  tabbar: {
    mode: "custom",
    schema: {
      items: DEFAULT_TABBAR_ITEMS,
    },
  },
  theme: {
    setting: {
      user_select_enabled: 1,
      admin_theme_mode: "system",
      admin_theme_id: null,
    },
    policy: {
      allow_user_select: 1,
      default_mode: "system",
      default_theme_id: null,
    },
    themes: {
      light: DEFAULT_LIGHT_THEME,
      dark: DEFAULT_DARK_THEME,
    },
  },
};
