import { test, expect } from './audio-graph-harness'

test('native one-shot media capture recovers after partial failure and follows replacement pairs', async ({ page, origin }) => {
  await page.goto(origin)
  await page.waitForFunction(() => 'audioGraphFixture' in window)
  const result = await page.evaluate(() => (window as unknown as {
    audioGraphFixture: { connectionRecovery(): Promise<Record<string, boolean>> }
  }).audioGraphFixture.connectionRecovery())
  expect(result).toEqual({ rejected: true, activeAfterFailure: false, recovered: true, replacedB: true, replacedA: true, reused: true })
})
