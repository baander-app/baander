import { defineConfig } from '@playwright/test'

const baseURL = process.env.E2E_BASE_URL
if (!baseURL) {
  throw new Error('E2E_BASE_URL must explicitly target an isolated preview or disposable application.')
}

const target = new URL(baseURL)
if (!['http:', 'https:'].includes(target.protocol) || target.username || target.password
  || target.hostname.toLowerCase().replace(/\.$/, '') === 'baander.test') {
  throw new Error('E2E_BASE_URL must target an isolated HTTP(S) application; baander.test is persistent.')
}

console.warn('WARNING: Playback tests can produce audible tones or media through your speakers/headphones. Lower or mute system output before running; tests may change player volume.')

export default defineConfig({
  testDir: '.',
  timeout: 45_000,
  expect: { timeout: 8_000, toHaveScreenshot: { maxDiffPixels: 100 } },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 1,
  workers: 1,
  snapshotDir: 'characterization/__snapshots__',
  reporter: [['html', { open: 'never' }], ['list']],

  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 30_000,
  },
})
