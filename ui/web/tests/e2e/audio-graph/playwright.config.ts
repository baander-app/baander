import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: '.', testMatch: '**/*.spec.ts', workers: 1, fullyParallel: false,
  timeout: 20_000, globalTimeout: 120_000, retries: 0,
  forbidOnly: !!process.env.CI, reporter: [['list']],
  outputDir: '../../../test-results/audio-graph',
})
