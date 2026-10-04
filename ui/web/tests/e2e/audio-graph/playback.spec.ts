import { test, expect } from './playback-harness'
import type { MediaSnapshot, PlaybackFixture } from './playback-fixture'
import type { Page } from '@playwright/test'

declare global { interface Window { playbackFixture: PlaybackFixture } }
const snapshot = (page: Page) => page.evaluate(() => window.playbackFixture.snapshot())
async function start(page: Page, origin: string, crossfade = false) {
  await page.goto(origin)
  await page.waitForFunction(() => document.documentElement.dataset.ready === 'true')
  await page.evaluate(enabled => window.playbackFixture.start(enabled), crossfade)
  await expect.poll(async () => (await snapshot(page)).elements[0].paused).toBe(false)
}
async function track(page: Page, id: string) {
  await expect.poll(async () => (await snapshot(page)).track, { timeout: 12_000 }).toBe(id)
  await expect.poll(async () => {
    const state = await snapshot(page)
    return state.elements[state.active]?.paused
  }).toBe(false)
}
function expectActive(state: MediaSnapshot, id: string, index: number) {
  expect(state.active).toBe(index)
  expect(state.source).toBe(index === 0 ? 'A' : 'B')
  expect(state.elements[index].id).toBe(id)
  expect(state.elements[index].paused).toBe(false)
  expect(state.playing).toBe(true)
}

test('native gapless playback promotes preloaded elements A → B → A without reloading', async ({ page, origin }) => {
  await start(page, origin)
  await expect.poll(async () => (await snapshot(page)).elements[1].id).toBe('second')
  await track(page, 'second')
  const second = await snapshot(page)
  expectActive(second, 'second', 1)
  expect(second.elements[1].loads).toBe(1)
  expect(second.duration).toBeCloseTo(5, 2)
  await track(page, 'third')
  const third = await snapshot(page)
  expectActive(third, 'third', 0)
  expect(third.elements[0].loads).toBe(2)
  expect(third.duration).toBeCloseTo(6, 2)
})

test('native crossfade plays both elements before the outgoing ended event', async ({ page, origin }) => {
  await start(page, origin, true)
  await track(page, 'second')
  const overlap = await snapshot(page)
  expectActive(overlap, 'second', 1)
  expect(overlap.elements[0].paused).toBe(false)
  expect(overlap.elements[0].ended).toBe(false)
  expect(overlap.elements[0].time).toBeLessThan(4)
  expect(overlap.elements[0].time).toBeGreaterThan(2.5)
  expect(overlap.elements[1].time).toBeGreaterThanOrEqual(0)
  expect(overlap.events.filter(event => event.element === 0 && event.type === 'ended')).toHaveLength(0)
  expect(overlap.elements[1].loads).toBe(1)
  expect(overlap.elements.every(element => element.volume === 1 && !element.muted)).toBe(true)
  await expect.poll(async () => (await snapshot(page)).outputGain).toBeCloseTo(.6, 3)
  await page.evaluate(() => { window.playbackFixture.volume(37); window.playbackFixture.mute(true) })
  const muted = await snapshot(page)
  expect(muted.elements.every(element => element.volume === 1 && !element.muted)).toBe(true)
  await expect.poll(async () => (await snapshot(page)).outputGain).toBeCloseTo(0, 3)
  await page.evaluate(() => window.playbackFixture.mute(false))
  await expect.poll(async () => (await snapshot(page)).outputGain).toBeCloseTo(.37, 3)
  await page.evaluate(() => window.playbackFixture.volume(60))
  await expect.poll(async () => (await snapshot(page)).outputGain).toBeCloseTo(.6, 3)
  await expect.poll(async () => (await snapshot(page)).elements[0].paused).toBe(true)
})

test('promoted native media owns seek, pause, time updates and volume/mute', async ({ page, origin }) => {
  await start(page, origin)
  await track(page, 'second')
  await page.evaluate(() => {
    window.playbackFixture.volume(37)
    window.playbackFixture.mute(true)
    window.playbackFixture.pause()
  })
  await expect.poll(async () => (await snapshot(page)).elements[1].paused).toBe(true)
  await page.evaluate(() => window.playbackFixture.seek(1.25))
  const paused = await snapshot(page)
  expect(paused.active).toBe(1)
  expect(paused.elements[1].time).toBeCloseTo(1.25, 1)
  expect(paused.elements.every(element => element.volume === 1 && !element.muted)).toBe(true)
  await expect.poll(async () => (await snapshot(page)).outputGain).toBeCloseTo(0, 3)
  expect(paused.time).toBeCloseTo(1.25, 1)
  await page.evaluate(() => { window.playbackFixture.mute(false); window.playbackFixture.resume() })
  await expect.poll(async () => (await snapshot(page)).outputGain).toBeCloseTo(.37, 3)
  await expect.poll(async () => (await snapshot(page)).elements[1].paused).toBe(false)
  await expect.poll(async () => (await snapshot(page)).time).toBeGreaterThan(1.4)
  await page.evaluate(() => window.playbackFixture.domPause(1))
  await expect.poll(async () => (await snapshot(page)).playing).toBe(false)
  await page.evaluate(() => window.playbackFixture.domPlay(1))
  await expect.poll(async () => (await snapshot(page)).playing).toBe(true)
})

test('manual track selection interrupts overlap and prevents stale transition playback', async ({ page, origin }) => {
  await start(page, origin, true)
  await track(page, 'second')
  await page.evaluate(() => window.playbackFixture.manual())
  await track(page, 'third')
  await expect.poll(async () => {
    const state = await snapshot(page)
    return state.elements.filter((_, index) => index !== state.active).every(element => element.paused)
  }).toBe(true)
  // Let the old fade deadline expire: its callback must not stop or resurrect media.
  await page.waitForTimeout(1400)
  const selected = await snapshot(page)
  expect(selected.track).toBe('third')
  expect(selected.elements[selected.active].id).toBe('third')
  expect(selected.elements[selected.active].paused).toBe(false)
  expect(selected.playing).toBe(true)
  expect(selected.elements.filter((_, index) => index !== selected.active).every(element => element.paused)).toBe(true)
})


test('native pending play rejection cannot overwrite a newer manual selection', async ({ page, origin }) => {
  let releaseFirst!: () => void
  let releaseThird!: () => void
  let requestedFirst!: () => void
  const firstRequested = new Promise<void>(resolve => { requestedFirst = resolve })
  const firstGate = new Promise<void>(resolve => { releaseFirst = resolve })
  const thirdGate = new Promise<void>(resolve => { releaseThird = resolve })
  await page.route('**/api/stream/track?*', async route => {
    const id = new URL(route.request().url()).searchParams.get('id')
    if (id === 'first') { requestedFirst(); await firstGate }
    if (id === 'third') await thirdGate
    try { await route.continue() } catch { /* replacing src cancels the original native request */ }
  })
  try {
    await page.goto(origin)
    await page.waitForFunction(() => document.documentElement.dataset.ready === 'true')
    await page.evaluate(() => window.playbackFixture.start(false))
    await firstRequested
    await page.evaluate(() => window.playbackFixture.manual())
    // Native play() rejects the replaced pending source while the new HTTP response is held.
    await page.waitForTimeout(200)
    const waiting = await snapshot(page)
    expect(waiting.track).toBe('third')
    expect(waiting.playing).toBe(true)
    releaseThird()
    releaseFirst()
    await track(page, 'third')
    expect((await snapshot(page)).playing).toBe(true)
  } finally { releaseFirst(); releaseThird() }
})

for (const cancel of [false, true]) {
  test(`native offline fade anchors automation at current time${cancel ? ' and cancels obsolete ramps' : ''}`, async ({ page, origin }) => {
    await page.goto(origin)
    await page.waitForFunction(() => document.documentElement.dataset.ready === 'true')
    const signal = await page.evaluate(cancel => window.playbackFixture.renderFade(cancel), cancel)
    expect(signal.before[0]).toBeCloseTo(1, 1)
    expect(signal.before[1]).toBeCloseTo(0, 1)
    expect(signal.start[0]).toBeGreaterThan(.9)
    expect(signal.start[1]).toBeLessThan(.1)
    expect(signal.middle[0]).toBeGreaterThan(.45)
    expect(signal.middle[0]).toBeLessThan(.6)
    expect(signal.middle[1]).toBeGreaterThan(.4)
    expect(signal.middle[1]).toBeLessThan(.55)
    expect(signal.after[0]).toBeCloseTo(0, 1)
    expect(signal.after[1]).toBeCloseTo(1, 1)
    if (cancel) {
      expect(signal.cancelled[0]).toBeCloseTo(0, 1)
      expect(signal.cancelled[1]).toBeCloseTo(1, 1)
    }
  })
}
