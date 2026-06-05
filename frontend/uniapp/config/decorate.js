import { DEFAULT_DARK_THEME, DEFAULT_LIGHT_THEME } from '@/config/theme'

export const DEFAULT_TABBAR_ITEMS = [
  {
    key: 'home',
    text: '首页',
    pagePath: '/pages/index/index',
    iconPath: '/static/images/tabbar/home.png',
    selectedIconPath: '/static/images/tabbar/home-active.png',
  },
  {
    key: 'category',
    text: '分类',
    pagePath: '/pages/category/index',
    iconPath: '/static/images/tabbar/category.png',
    selectedIconPath: '/static/images/tabbar/category-active.png',
  },
  {
    key: 'cart',
    text: '购物车',
    pagePath: '/pages/cart/index',
    iconPath: '/static/images/tabbar/cart.png',
    selectedIconPath: '/static/images/tabbar/cart-active.png',
  },
  {
    key: 'order',
    text: '订单',
    pagePath: '/pages/order/index',
    iconPath: '/static/images/tabbar/order.png',
    selectedIconPath: '/static/images/tabbar/order-active.png',
  },
  {
    key: 'profile',
    text: '我的',
    pagePath: '/pages/profile/index',
    iconPath: '/static/images/tabbar/profile.png',
    selectedIconPath: '/static/images/tabbar/profile-active.png',
  },
]

export const DEFAULT_HOME_MODULES = [
  {
    id: 'search-default',
    type: 'search',
    sort: 10,
    props: {
      placeholder: '搜索你心仪的商品...',
    },
  },
  {
    id: 'banner-default',
    type: 'banner',
    sort: 20,
    props: {
      height: 314,
      radius: 12,
      list: [],
    },
  },
  {
    id: 'nav-grid-default',
    type: 'navGrid',
    sort: 30,
    props: {
      columns: 5,
      items: [
        { label: '数码', url: '/pages-sub/goods/list?keyword=数码', icon: 'phone' },
        { label: '美妆', url: '/pages-sub/goods/list?keyword=美妆', icon: 'beauty' },
        { label: '服饰', url: '/pages-sub/goods/list?keyword=服饰', icon: 'shirt' },
        { label: '家居', url: '/pages-sub/goods/list?keyword=家居', icon: 'home' },
        { label: '美食', url: '/pages-sub/goods/list?keyword=美食', icon: 'food' },
      ],
    },
  },
  {
    id: 'must-buy-default',
    type: 'productGroup',
    sort: 40,
    props: {
      title: '今日必买',
      source: 'recommend',
      layout: 'scroll',
      limit: 8,
      moreText: '查看全部',
      moreUrl: '/pages-sub/goods/list?is_recommend=1',
    },
  },
  {
    id: 'guess-default',
    type: 'productGroup',
    sort: 50,
    props: {
      title: '猜你喜欢',
      source: 'filter',
      layout: 'grid',
      limit: 10,
      pageable: true,
    },
  },
]

export const DEFAULT_PROFILE_MODULES = [
  { id: 'profile-user', type: 'userCard', sort: 10, props: {} },
  { id: 'profile-wallet', type: 'wallet', sort: 20, props: {} },
  {
    id: 'profile-orders',
    type: 'orderShortcut',
    sort: 30,
    props: {
      title: '我的订单',
      items: [
        { key: 'pending_pay', label: '待付款', icon: '¥' },
        { key: 'paid', label: '待发货', icon: '✉' },
        { key: 'shipped', label: '待收货', icon: '→' },
        { key: 'refund', label: '退款售后', icon: '↩', path: '/pages-sub/refund/list' },
      ],
    },
  },
  {
    id: 'profile-service',
    type: 'serviceMenu',
    sort: 40,
    props: {
      items: [
        { key: 'address', label: '地址管理', path: '/pages-sub/address/list' },
        { key: 'wallet', label: '我的余额', path: '/pages-sub/wallet/index', requireBalanceEnabled: true },
        { key: 'favorite', label: '我的收藏', path: '' },
        { key: 'theme', label: '主题设置', action: 'theme' },
        { key: 'about', label: '关于我们', path: '' },
      ],
    },
  },
  { id: 'profile-logout', type: 'logout', sort: 50, props: {} },
]

export const DEFAULT_DECORATE_CONFIG = {
  home: {
    modules: DEFAULT_HOME_MODULES,
  },
  profile: {
    modules: DEFAULT_PROFILE_MODULES,
  },
  tabbar: {
    mode: 'native',
    schema: {
      items: DEFAULT_TABBAR_ITEMS,
    },
  },
  theme: {
    policy: {
      allow_user_select: 1,
      default_mode: 'system',
    },
    themes: {
      light: DEFAULT_LIGHT_THEME,
      dark: DEFAULT_DARK_THEME,
    },
  },
}
