import type { PlaywrightTestConfig } from '@playwright/test';

import { devices } from '@playwright/test';

const config: PlaywrightTestConfig = {
  expect: {
    timeout: 10_000,
  },
  forbidOnly: !!process.env.CI,
  outputDir: 'node_modules/.e2e-web/test-results',
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
      },
    },
  ],
  reporter: [
    ['list'],
    ['html', { outputFolder: 'node_modules/.e2e-web/report' }],
  ],
  retries: process.env.CI ? 2 : 0,
  testDir: './__tests__/e2e',
  timeout: 30_000,
  use: {
    actionTimeout: 10_000,
    baseURL: 'http://127.0.0.1:5666',
    headless: true,
    trace: 'retain-on-failure',
  },
  webServer: {
    command:
      'VITE_E2E=true VITE_NITRO_MOCK=false pnpm dev --host 127.0.0.1 --port 5666',
    port: 5666,
    reuseExistingServer: true,
  },
  workers: process.env.CI ? 1 : undefined,
};

export default config;
