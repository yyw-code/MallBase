import { defineOverridesPreferences } from '@vben/preferences';

/**
 * @description 项目配置文件
 * 只需要覆盖项目中的一部分配置，不需要的配置不用覆盖，会自动使用默认配置
 * !!! 更改配置后请清空缓存，否则可能不生效
 *
 * ⚠️ 本项目补充约束：
 * - 版权信息由后端 SystemCopyright 分组下发（bootstrap 阶段 getAppMetaApi 覆盖到
 *   preferences.copyright），此处隐藏设置抽屉里的「版权」子面板，避免用户以为能前端改。
 * - 不考虑国际化 / 跨时区，关闭设置抽屉的「语言」「时区」部件。
 * - 保留「主题切换」「全屏」「通知」等纯前端个性化开关。
 */
export const overridesPreferences = defineOverridesPreferences({
  // overrides
  app: {
    name: import.meta.env.VITE_APP_TITLE,
    // accessMode: 'mixed',  // 混合模式
    // accessMode: 'frontend',  // 前端模式
    accessMode: 'backend', // 后端模式
    enableRefreshToken: true, // 启用双 token 机制
    // loginExpiredMode: 'modal', //
  },
  // 设置抽屉隐藏「版权」子面板（由后端统一下发，前端不再重复配置）
  copyright: {
    settingShow: false,
  },
  // 设置抽屉部件开关：关闭语言切换 / 时区部件
  widget: {
    languageToggle: false,
    timezone: false,
  },
});
