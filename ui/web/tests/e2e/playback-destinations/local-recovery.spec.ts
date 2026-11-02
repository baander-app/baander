import { expect, test, type Page } from '@playwright/test'
import type { PlaybackSnapshotResource } from '@/shared/api-client/gen/endpoints'
import { authenticatedGet, FixtureClient, login, type FixtureManifest } from '../live-updates/capacity-fixtures'

async function snapshot(page: Page) {
  return (await authenticatedGet<{ data: PlaybackSnapshotResource }>(page, '/api/session/snapshot')).data
}

async function nativePlayback(page: Page) {
  return page.evaluate(async () => {
    const path = '/src/features/player/stores/player-store.ts'
    const { usePlayerStore } = await import(/* @vite-ignore */ path)
    const state = usePlayerStore.getState()
    return {
      paused: state.audioElement?.paused ?? true,
      position: state.audioElement?.currentTime ?? 0,
      nativeDuration: state.audioElement?.duration ?? 0,
      storeDuration: state.duration,
      durationLabel: document.querySelector('[aria-label="Seek"]')?.closest('[data-slot="slider"]')?.parentElement?.lastElementChild?.textContent,
      queue: state.queue.map((track: { publicId: string }) => track.publicId),
    }
  })
}

test('local music recovers paused after reload, tab close and renderer destruction', async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(240_000)
  const fixture = FixtureClient.start()
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
  const recoveries: { status: number; outcome?: string }[] = []
  context.on('response', async response => {
    if (new URL(response.url()).pathname !== '/api/session/recover-local') return
    try {
      const body = await response.json()
      recoveries.push({ status: response.status(), outcome: body.data?.recoveryResult?.outcome })
    } catch { /* A destroyed renderer may no longer expose its response body. */ }
  })
  try {
    const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
    let page = await context.newPage()
    await login(page, manifest.users[0])
    await page.goto('/music/songs')
    await page.getByText('Live song 00000', { exact: true }).dblclick()
    await expect.poll(async () => (await nativePlayback(page)).position, { timeout: 25_000 }).toBeGreaterThan(2)
    expect(await nativePlayback(page)).toMatchObject({ nativeDuration: 60, storeDuration: 60, durationLabel: '1:00' })
    const queue = (await nativePlayback(page)).queue
    const media = (await snapshot(page)).media

    const originalTab = await page.evaluate(() => sessionStorage.getItem('baander-playback-tab'))
    const originalRunner = (await snapshot(page)).activeIncarnationId
    const [copiedTab] = await Promise.all([
      page.waitForEvent('popup'),
      page.evaluate(() => { window.open('/music/songs', '_blank') }),
    ])
    await expect.poll(async () => (await snapshot(copiedTab)).destinations.filter(destination => destination.available).length).toBe(2)
    expect(await copiedTab.evaluate(() => sessionStorage.getItem('baander-playback-tab'))).not.toBe(originalTab)
    expect((await snapshot(copiedTab)).activeIncarnationId).toBe(originalRunner)
    expect((await nativePlayback(copiedTab)).paused).toBe(true)
    expect((await nativePlayback(page)).paused).toBe(false)
    await copiedTab.close()

    for (const lifecycle of ['reload', 'close', 'crash'] as const) {
      const oldIncarnation = (await snapshot(page)).activeIncarnationId
      expect(oldIncarnation).toBeTruthy()
      const previousRecoveryCount = recoveries.length
      if (lifecycle === 'reload') await page.reload()
      else if (lifecycle === 'close') {
        await page.close()
        page = await context.newPage()
        await page.goto('/music/songs')
      } else {
        await test.step('Reopen after renderer destruction', async () => {
          const cdp = await context.newCDPSession(page)
          const crashed = page.waitForEvent('crash')
          // The destroyed renderer may never answer commands; closing its page releases the session.
          cdp.send('Page.crash').catch(() => {})
          await crashed
          await page.close()
          page = await context.newPage()
          await page.goto('/music/songs')
        }, { timeout: 15_000 })
      }
      await expect.poll(async () => (await snapshot(page)).activeIncarnationId, { timeout: 25_000 }).toBeNull()
      await expect.poll(() => recoveries.length).toBeGreaterThan(previousRecoveryCount)
      expect(recoveries.at(-1)).toEqual({ status: 200, outcome: 'recovered' })
      const retained = await snapshot(page)
      expect(retained.media).toEqual(media)
      expect(retained.desiredState).toBe('paused')
      await expect.poll(async () => (await nativePlayback(page)).queue).toEqual(queue)
      expect((await nativePlayback(page)).paused).toBe(true)
      await page.getByRole('region', { name: 'Playback', exact: true })
        .getByRole('button', { name: 'Play', exact: true }).click()
      await expect.poll(async () => (await nativePlayback(page)).paused, { timeout: 25_000 }).toBe(false)
      await expect.poll(async () => (await snapshot(page)).activeIncarnationId).not.toBeNull()
      expect((await snapshot(page)).activeIncarnationId).not.toBe(oldIncarnation)
      await expect.poll(async () => (await nativePlayback(page)).position).toBeGreaterThan(1)
      expect(await nativePlayback(page)).toMatchObject({ nativeDuration: 60, storeDuration: 60, durationLabel: '1:00' })
    }
  } finally {
    await testInfo.attach('local-recovery-results', { body: JSON.stringify(recoveries), contentType: 'application/json' })
    await context.close()
    try {
      if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
    } finally { await fixture.close() }
  }
})

test('paused local music resumes through fresh registration after its presence expires', async ({ browser, baseURL }, testInfo) => {
  test.setTimeout(120_000)
  const fixture = FixtureClient.start()
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
  const rejectedControls: { path: string; status: number }[] = []
  try {
    const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
    const page = await context.newPage()
    page.on('response', response => {
      const path = new URL(response.url()).pathname
      if ((path === '/api/session/control' || path === '/api/session/transfer') && response.status() >= 400) {
        rejectedControls.push({ path, status: response.status() })
      }
    })
    await login(page, manifest.users[0])
    await page.goto('/music/songs')
    await page.getByText('Live song 00000', { exact: true }).dblclick()
    await expect.poll(async () => (await nativePlayback(page)).position, { timeout: 25_000 }).toBeGreaterThan(2)
    const footer = page.getByRole('region', { name: 'Playback', exact: true })
    await footer.getByRole('button', { name: 'Pause', exact: true }).click()
    await expect.poll(async () => (await snapshot(page)).observedState).toBe('paused')
    expect((await nativePlayback(page)).paused).toBe(true)
    const paused = await snapshot(page)
    const queue = (await nativePlayback(page)).queue
    expect(paused.activeIncarnationId).not.toBeNull()

    const observationUrl = (url: URL) => url.pathname === '/api/session/observe'
    await page.route(observationUrl, route => route.abort())
    await test.step('Expire the paused browser presence while retaining its media', async () => {
      await expect.poll(async () => (await snapshot(page)).activeIncarnationId, {
        timeout: 45_000, intervals: [1_000],
      }).toBeNull()
    })
    await page.unroute(observationUrl)
    const retained = await snapshot(page)
    expect(retained.media).toEqual(paused.media)
    expect(retained.queue).toEqual(paused.queue)
    expect(retained.desiredState).toBe('paused')
    expect(retained.position).toBeCloseTo(paused.position, 1)

    await footer.getByRole('button', { name: 'Play', exact: true }).click()
    await expect.poll(async () => (await nativePlayback(page)).paused, { timeout: 25_000 }).toBe(false)
    await expect.poll(async () => (await snapshot(page)).activeIncarnationId).not.toBeNull()
    const resumed = await snapshot(page)
    expect(resumed.activeIncarnationId).not.toBe(paused.activeIncarnationId)
    expect(resumed.media).toEqual(paused.media)
    expect((await nativePlayback(page)).queue).toEqual(queue)
    await expect.poll(async () => (await nativePlayback(page)).position).toBeGreaterThan(paused.position + 1)
    expect(await nativePlayback(page)).toMatchObject({ nativeDuration: 60, storeDuration: 60, durationLabel: '1:00' })
    expect(rejectedControls).toEqual([])
  } finally {
    await testInfo.attach('rejected-playback-controls', { body: JSON.stringify(rejectedControls), contentType: 'application/json' })
    await context.close()
    try {
      if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
    } finally { await fixture.close() }
  }
})
