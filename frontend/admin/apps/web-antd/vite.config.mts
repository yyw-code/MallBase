import process from 'node:process';

import { defineConfig } from '@vben/vite-config';

export default defineConfig(async () => {
  const backendOrigin =
    process.env.MALLBASE_BACKEND_ORIGIN ||
    process.env.MALLBASE_E2E_BACKEND_ORIGIN ||
    'http://127.0.0.1:8080';
  const upgradeOrigin =
    process.env.MALLBASE_UPGRADE_ORIGIN || 'http://127.0.0.1:18081';

  return {
    application: {},
    vite: {
      server: {
        proxy: {
          '/api': {
            changeOrigin: true,
            rewrite: (path) => path.replace(/^\/api/, ''),
            // mock代理目标地址
            target: 'http://localhost:5320/api',
            ws: true,
          },
          '/admin/api': {
            changeOrigin: true,
            target: backendOrigin,
          },
          '/upgrade': {
            changeOrigin: false,
            target: upgradeOrigin,
          },
        },
      },
    },
  };
});
