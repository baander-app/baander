import { test, expect } from './playback-harness'
import type { PlaybackFixture } from './playback-fixture'
import type { Page } from '@playwright/test'

declare global { interface Window { playbackFixture: PlaybackFixture } }
const snapshot = (page: Page) => page.evaluate(() => window.playbackFixture.snapshot())
const setServer = (page: Page, state: 'up' | 'down') => page.evaluate(async (next) => {
  const response = await fetch(`/fixture/server?state=${next}`)
  if (!response.ok) throw new Error(`Fixture server refused ${next}`)
}, state)

test('a stream cut by a stopping server resumes at its position once the server answers again', async ({ page, origin }) => {
  test.setTimeout(60_000)
  await page.goto(origin)
  await page.waitForFunction(() => document.documentElement.dataset.ready === 'true')
  await setServer(page, 'up')
  await page.evaluate(() => window.playbackFixture.playLong())
  await expect.poll(async () => (await snapshot(page)).time, { timeout: 10_000 }).toBeGreaterThan(2)

  await setServer(page, 'down')
  // Chromium either reports the failed range retry as an error or waits silently (stall detection).
  await expect.poll(async () => (await snapshot(page)).recovery, { timeout: 20_000 }).toBe('reconnecting')
  const interrupted = await snapshot(page)
  expect(interrupted.track).toBe('long')
  expect(interrupted.playing).toBe(true)
  await page.waitForTimeout(1_500)
  expect((await snapshot(page)).time).toBe(interrupted.time)

  await setServer(page, 'up')
  await expect.poll(async () => (await snapshot(page)).recovery, { timeout: 20_000 }).toBe('none')
  await expect.poll(async () => {
    const state = await snapshot(page)
    return state.elements[state.active].paused
  }).toBe(false)
  const resumed = await snapshot(page)
  expect(resumed.track).toBe('long')
  expect(resumed.playing).toBe(true)
  expect(resumed.elements[resumed.active].error).toBeNull()
  expect(resumed.elements[resumed.active].time).toBeGreaterThanOrEqual(interrupted.time - 0.25)

  // One play record, and the clock never fell back to the start while the stream recovered.
  expect(await page.evaluate(() => window.playbackFixture.activity())).toEqual(['long'])
  const clock = await page.evaluate(() => window.playbackFixture.clock())
  expect(clock.slice(clock.findIndex(time => time > 1))).not.toContain(0)
})
