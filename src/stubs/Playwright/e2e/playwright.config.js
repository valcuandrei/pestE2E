import { storageStatePath } from '../pest-e2e/playwright.mjs';

export default {
  testDir: '.',
  outputDir: `.pest-e2e/${process.env.PEST_E2E_RUN_ID || 'default'}/playwright-output`,
  globalSetup: './global-setup.mjs',
  reporter: [
    ['list'],
    ['json'],
  ],
  use: {
    baseURL: process.env.APP_URL || 'http://localhost',
    testIdAttribute: 'data-test',
    storageState: storageStatePath(),
    connectOptions: process.env.PEST_E2E_WARM_WS_ENDPOINT
      ? { wsEndpoint: process.env.PEST_E2E_WARM_WS_ENDPOINT }
      : undefined,
  },
  projects: [
    {
      name: 'chromium',
      use: {
        browserName: 'chromium',
        headless: true,
      },
    },
  ],
};
