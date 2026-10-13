import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',
  timeout: 20_000,
  globalTimeout: 120_000,
  expect: { timeout: 5_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  reporter: [['list']],
  outputDir: '../../../test-results/browser-auth',
  use: { actionTimeout: 5_000, navigationTimeout: 5_000 },
  // WebKit runs only the scenarios tagged for it: the ones about what its storage gives back.
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' }, grepInvert: /@webkit/ },
    { name: 'webkit', use: { browserName: 'webkit' }, grep: /@webkit/ },
  ],
})
