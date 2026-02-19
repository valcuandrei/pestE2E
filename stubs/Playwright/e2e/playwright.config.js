import { storageStatePath } from '../pest-e2e/playwright.mjs';

export default {
  testDir: '.',
  outputDir: '/tmp/pest-e2e-test-results',
  globalSetup: './global-setup.mjs',
  reporter: [
    ['list'],
    ['json'],
  ],
  use: {
    baseURL: process.env.APP_URL || 'http://localhost',
    testIdAttribute: 'data-test',
    storageState: storageStatePath(),
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
