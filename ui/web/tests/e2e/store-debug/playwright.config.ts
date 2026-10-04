import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: '.', testMatch: '**/*.spec.ts', workers: 1, fullyParallel: false,
  timeout: 20_000, globalTimeout: 120_000, retries: 0,
  forbidOnly: !!process.env.CI, reporter: [['list']],
  outputDir: '../../../test-results/store-debug',
  use: { baseURL: 'https://debug.baander.app:5187', browserName: 'chromium', ignoreHTTPSErrors: true,
    launchOptions: { args: ['--host-resolver-rules=MAP debug.baander.app 127.0.0.1', '--no-proxy-server'] } },
  webServer: { command: 'yarn vite --config vite.config.ts',
    url: 'https://127.0.0.1:5187', ignoreHTTPSErrors: true, reuseExistingServer: false, timeout: 30_000 },
})
