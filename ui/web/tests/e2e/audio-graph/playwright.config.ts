import { defineConfig } from '@playwright/test'

console.warn('WARNING: Playback tests can produce audible tones or media through your speakers/headphones. Lower or mute system output before running; tests may change player volume.')

export default defineConfig({
  testDir: '.', testMatch: '**/*.spec.ts', workers: 1, fullyParallel: false,
  // The whole suite takes about two and a half minutes; each test keeps its own 20 s limit.
  timeout: 20_000, globalTimeout: 300_000, retries: 0,
  forbidOnly: !!process.env.CI, reporter: [['list']],
  outputDir: '../../../test-results/audio-graph',
})
