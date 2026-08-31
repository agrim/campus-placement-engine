import { defineConfig, devices } from '@playwright/test';

const outputRoot = '../../output/playwright';

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI
    ? [['line'], ['html', { outputFolder: `${outputRoot}/report`, open: 'never' }]]
    : [['list'], ['html', { outputFolder: `${outputRoot}/report`, open: 'never' }]],
  outputDir: `${outputRoot}/results`,
  use: {
    baseURL: process.env.CPE_BROWSER_BASE_URL || 'http://127.0.0.1:8010',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    reducedMotion: 'reduce',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
