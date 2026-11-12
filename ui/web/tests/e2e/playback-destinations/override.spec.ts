import { expect, test } from '@playwright/test'
import { FixtureClient, type FixtureManifest } from '../live-updates/capacity-fixtures'
import {
  attachExchanges, destinationControl, fixtureSongRow, incarnationOf, nativeAudio, openSessionTab, playFixtureSong, sessionSnapshot,
  type SessionTab,
} from './session-tabs'

const consequence = 'Play here anyway ends its hold; if it is still playing, it continues until it reconnects.'

/** Waits until `tab` itself shows that the runner it knows is unavailable and has not confirmed a stop. */
async function held(tab: SessionTab, runner: string) {
  await expect.poll(async () => {
    const snapshot = await sessionSnapshot(tab.page)
    return snapshot.activeIncarnationId === runner
      && !snapshot.destinations.some(destination => destination.incarnationId === runner && destination.available)
  }, { timeout: 60_000, intervals: [1_000], message: 'the runner\'s registration to lapse while the session still names it' }).toBe(true)
  // The tab refuses and offers from the snapshot it holds itself.
  await expect(destinationControl(tab.page)).toHaveAttribute('title', 'Previous playback is unconfirmed.')
}

/** Opens the destination menu of `tab`, checks what it says about the hold, and presses "Play here anyway". */
async function playHereAnyway(tab: SessionTab, runnerName: string) {
  await destinationControl(tab.page).click()
  const menu = tab.page.getByRole('menu')
  // A destination that is no longer listed is spoken of as the previous one.
  const status = menu.getByRole('status').filter({ hasText: 'has not confirmed that it stopped' })
  await expect(status).toContainText(' is unavailable and has not confirmed that it stopped. ' + consequence)
  expect(await status.textContent()).toMatch(new RegExp(`^(${runnerName}|The previous destination) is unavailable`))
  const action = menu.getByRole('menuitem', { name: 'Play here anyway', exact: true })
  await expect(action).toBeEnabled()
  const answered = tab.page.waitForResponse(response => new URL(response.url()).pathname === '/api/session/override')
  await action.click()
  const answer = await answered
  expect(answer.status(), JSON.stringify(await answer.json().catch(() => null))).toBe(200)
  expect((await answer.json()).data.requestResult).toMatchObject({ outcome: 'overridden', rejectionReason: null })
  // An accepted override closes the menu; a refusal would have stayed in it.
  await expect(menu).toBeHidden()
}

test.describe('Play here anyway', () => {
  test('a tab refused by a runner that stopped reporting takes the session over and plays the track it was refused', async ({ browser, baseURL }, testInfo) => {
    test.setTimeout(240_000)
    const fixture = FixtureClient.start()
    const tabs: SessionTab[] = []
    const receipt: Record<string, unknown> = {}
    try {
      const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
      const lost = await openSessionTab(browser, baseURL, manifest.users[0], 'Lost browser')
      tabs.push(lost)
      const taking = await openSessionTab(browser, baseURL, manifest.users[0], 'Taking browser')
      tabs.push(taking)
      await taking.page.goto('/music/songs')
      await expect.poll(async () => (await sessionSnapshot(taking.page)).destinations.filter(destination => destination.available).length).toBe(2)
      const runner = await playFixtureSong(lost)

      // From here on the runner reports nothing, as a browser that was killed would, while its
      // page stays alive and keeps playing: its registration lapses in real time.
      const stoppedReporting = Date.now()
      await lost.page.route(url => url.pathname === '/api/session/observe', route => route.abort())
      await held(taking, runner)
      receipt.lapsedAfterMs = Date.now() - stoppedReporting
      expect((await nativeAudio(lost.page)).paused, 'the lost browser must still be playing when the other one is refused').toBe(false)

      const controlsBefore = taking.exchanges.filter(exchange => exchange.path === '/api/session/control').length
      await fixtureSongRow(taking.page).dblclick()
      await expect(taking.page.getByText('Previous playback is unconfirmed. Stop it in its original browser, or choose Play here anyway in the playback destination menu.').first()).toBeVisible()
      // The refusal is the tab's own: nothing was asked of the session.
      expect(taking.exchanges.filter(exchange => exchange.path === '/api/session/control')).toHaveLength(controlsBefore)
      expect((await nativeAudio(taking.page)).paused).toBe(true)

      const overridden = Date.now()
      await playHereAnyway(taking, 'Lost browser')

      // The refused track starts without another press.
      await expect.poll(async () => (await nativeAudio(taking.page)).paused, { timeout: 25_000 }).toBe(false)
      const own = await incarnationOf(taking.page, 'Taking browser')
      await expect.poll(async () => (await sessionSnapshot(taking.page)).activeIncarnationId).toBe(own)
      expect(own).not.toBe(runner)
      expect(taking.exchanges.filter(exchange => exchange.path === '/api/session/override' && exchange.status !== undefined)).toHaveLength(1)

      // The overridden browser is still connected: the first snapshot that shows it the session
      // moved on silences it, well before its minute-long song could end by itself.
      await expect.poll(async () => (await nativeAudio(lost.page)).paused, { timeout: 10_000 }).toBe(true)
      const silenced = await nativeAudio(lost.page)
      receipt.silencedAfterMs = Date.now() - overridden
      receipt.silencedAt = silenced
      expect(silenced.ended).toBe(false)
      expect(silenced.time).toBeLessThan(silenced.duration - 3)

      // It stays silent while the other one plays on.
      const before = (await nativeAudio(taking.page)).time
      await expect.poll(async () => (await nativeAudio(taking.page)).time, { timeout: 15_000 }).toBeGreaterThan(before + 3)
      expect(await nativeAudio(lost.page)).toMatchObject({ paused: true, time: silenced.time })
      expect((await nativeAudio(taking.page)).paused).toBe(false)
      expect((await sessionSnapshot(taking.page)).activeIncarnationId).toBe(own)
    } finally {
      await attachExchanges(testInfo, tabs, receipt)
      await Promise.all(tabs.map(tab => tab.context.close()))
      try {
        if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
      } finally { await fixture.close() }
    }
  })

  test('a browser that was only offline is silent once it is back, and the run records what silenced it', async ({ browser, baseURL }, testInfo) => {
    test.setTimeout(300_000)
    const fixture = FixtureClient.start()
    const tabs: SessionTab[] = []
    const receipt: Record<string, unknown> = { engine: browser.browserType().name() }
    try {
      const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
      const offline = await openSessionTab(browser, baseURL, manifest.users[0], 'Offline browser')
      tabs.push(offline)
      const taking = await openSessionTab(browser, baseURL, manifest.users[0], 'Taking browser')
      tabs.push(taking)
      await taking.page.goto('/music/songs')
      await expect.poll(async () => (await sessionSnapshot(taking.page)).destinations.filter(destination => destination.available).length).toBe(2)
      const socketsClosed: number[] = []
      offline.page.on('websocket', socket => { socket.on('close', () => { socketsClosed.push(Date.now()) }) })
      const runner = await playFixtureSong(offline)
      await offline.page.evaluate(async () => {
        const path = '/src/features/player/stores/player-store.ts'
        const { usePlayerStore } = await import(/* @vite-ignore */ path)
        const audio = usePlayerStore.getState().audioElement as HTMLAudioElement
        // The lapse, the override and the return take about as long as the minute-long song. A
        // looping element never ends or pauses by itself, so only the page can silence it.
        audio.loop = true
        audio.addEventListener('pause', () => { document.documentElement.dataset.offlinePausedAt ??= String(Date.now()) })
        audio.addEventListener('ended', () => { document.documentElement.dataset.offlineEndedAt ??= String(Date.now()) })
      })

      const wentOffline = Date.now()
      await offline.context.setOffline(true)
      await held(taking, runner)
      receipt.lapsedAfterMs = Date.now() - wentOffline
      receipt.playingWhenRefused = !(await nativeAudio(offline.page)).paused

      // The tab shows the runner's playback, so its player offers Pause, not Play. The song is
      // selected again instead, and the tab refuses it itself.
      await fixtureSongRow(taking.page).dblclick()
      await expect(taking.page.getByText(/Previous playback is unconfirmed/).first()).toBeVisible()
      const overridden = Date.now()
      await playHereAnyway(taking, 'Offline browser')
      await expect.poll(async () => (await nativeAudio(taking.page)).paused, { timeout: 25_000 }).toBe(false)
      const own = await incarnationOf(taking.page, 'Taking browser')
      await expect.poll(async () => (await sessionSnapshot(taking.page)).activeIncarnationId).toBe(own)
      receipt.playingRightAfterOverride = !(await nativeAudio(offline.page)).paused

      const backOnline = Date.now()
      await offline.context.setOffline(false)
      // Once it is back it is silent within a presence interval and the registration deadline. What
      // silenced it, and how long it had played on, goes into the receipt.
      await expect.poll(async () => (await nativeAudio(offline.page)).paused, { timeout: 30_000, intervals: [500] }).toBe(true)
      expect((await nativeAudio(offline.page)).ended).toBe(false)
      await expect.poll(async () => offline.page.evaluate(() => navigator.onLine)).toBe(true)
      expect((await sessionSnapshot(taking.page)).activeIncarnationId).toBe(own)

      const marks = await offline.page.evaluate(() => ({ ...document.documentElement.dataset }))
      const pausedAt = Number(marks.offlinePausedAt)
      const endedAt = marks.offlineEndedAt ? Number(marks.offlineEndedAt) : null
      const socketClosedAt = socketsClosed.find(at => at >= wentOffline) ?? null
      receipt.socketClosedAfterOfflineMs = socketClosedAt === null ? null : socketClosedAt - wentOffline
      receipt.playedOnAfterOfflineMs = pausedAt - wentOffline
      receipt.playedOnAfterOverrideMs = pausedAt - overridden
      receipt.pausedAfterBackOnlineMs = pausedAt - backOnline
      receipt.closeReportAfterReconnect = offline.exchanges.some(exchange => exchange.path === '/api/session/close' && exchange.at >= backOnline)
      receipt.silencedBy = endedAt !== null && endedAt <= pausedAt + 50 ? 'the song ended by itself'
        : socketClosedAt !== null && socketClosedAt <= pausedAt + 50 ? 'its socket closed and the page stopped its players'
          : pausedAt >= backOnline ? 'a snapshot after it was back showed it that it lost the session'
            : 'unattributed'
      testInfo.annotations.push({ type: 'offline browser', description: JSON.stringify(receipt) })
      expect(['the song ended by itself', 'unattributed'], 'what silenced the offline browser').not.toContain(receipt.silencedBy)
    } finally {
      await attachExchanges(testInfo, tabs, receipt)
      await Promise.all(tabs.map(tab => tab.context.close()))
      try {
        if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
      } finally { await fixture.close() }
    }
  })
})
