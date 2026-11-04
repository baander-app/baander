import { defineConfig } from '@playwright/test'

/** Chromium and Firefox resolve the fixture host to the loopback; WebKit has no such setting, so the spec routes its requests. */
const FIXTURE_HOST = 'typography.baander.app'

export default defineConfig({
  testDir: '.', testMatch: '**/*.spec.ts', workers: 1, fullyParallel: false,
  timeout: 60_000, globalTimeout: 600_000, retries: 0,
  forbidOnly: !!process.env.CI, reporter: [['list']],
  outputDir: '../../../test-results/typography',
  use: { baseURL: `https://${FIXTURE_HOST}:5188`, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } },
  projects: [
    { name: 'chromium', use: { browserName: 'chromium',
      launchOptions: { args: [`--host-resolver-rules=MAP ${FIXTURE_HOST} 127.0.0.1`, '--no-proxy-server'] } } },
    { name: 'firefox', use: { browserName: 'firefox',
      launchOptions: { firefoxUserPrefs: { 'network.dns.localDomains': FIXTURE_HOST } } } },
    { name: 'webkit', use: { browserName: 'webkit' } },
  ],
  webServer: { command: 'yarn vite --config vite.config.ts',
    url: 'https://127.0.0.1:5188', ignoreHTTPSErrors: true, reuseExistingServer: false, timeout: 60_000 },
})
