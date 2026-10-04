import { test, expect } from './audio-graph-harness'

test('native normalization runs without the equalizer and preserves volume, mute, and rebuild gain ownership', async ({ page, origin }) => {
  await page.goto(origin + '/real/')
  await page.waitForFunction(() => 'audioGraphFixture' in window)
  type Reading = { rms: number; gainDb: number }
  const result = await page.evaluate(() => (window as unknown as {
    audioGraphFixture: { normalization(): Promise<{
      baseline: Reading; normalized: Reading; quiet: Reading; muted: Reading;
      unmuted: Reading; rebuilt: Reading; disabled: Reading; resetGain: number
    }> }
  }).audioGraphFixture.normalization())
  expect(result.baseline.rms).toBeCloseTo(0.2 / Math.sqrt(2), 3)
  expect(result.normalized.gainDb).toBeLessThan(-1)
  expect(result.normalized.rms / result.baseline.rms).toBeCloseTo(10 ** (result.normalized.gainDb / 20), 2)
  for (const reading of [result.quiet, result.unmuted, result.rebuilt]) {
    expect(reading.rms / result.normalized.rms).toBeCloseTo(0.25, 2)
    expect(reading.gainDb).toBeCloseTo(result.normalized.gainDb, 2)
  }
  expect(result.muted.rms).toBeLessThan(0.000001)
  expect(result.muted.gainDb).toBeCloseTo(result.normalized.gainDb, 2)
  expect(result.disabled.gainDb).toBe(0)
  expect(result.disabled.rms / result.baseline.rms).toBeCloseTo(0.25, 2)
  expect(result.resetGain).toBe(0)
})
