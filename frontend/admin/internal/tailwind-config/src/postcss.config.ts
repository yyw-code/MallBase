import config from '.';
import autoprefixer from 'autoprefixer';
import cssnano from 'cssnano';
import postcssAntdFixes from 'postcss-antd-fixes';
import postcssImport from 'postcss-import';
import postcssPresetEnv from 'postcss-preset-env';
import tailwindcss from 'tailwindcss';
import tailwindcssNesting from 'tailwindcss/nesting';

export default {
  plugins: [
    ...(process.env.NODE_ENV === 'production' ? [cssnano()] : []),
    // Specifying the config is not necessary in most cases, but it is included
    autoprefixer(),
    // 修复 element-plus 和 ant-design-vue 的样式和tailwindcss冲突问题
    postcssAntdFixes({ prefixes: ['ant', 'el'] }),
    postcssImport(),
    postcssPresetEnv(),
    tailwindcss({ config }),
    tailwindcssNesting(),
  ],
};
