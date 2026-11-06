import { defineConfig, devices } from '@playwright/test';
import { qualificationOrigin } from './security/network-policy.js';

const outputRoot = '../../output/playwright';
const baseURL = process.env.CPE_BROWSER_BASE_URL || 'http://127.0.0.1:8010';
qualificationOrigin(baseURL);

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
    baseURL,
    serviceWorkers: 'block',
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
