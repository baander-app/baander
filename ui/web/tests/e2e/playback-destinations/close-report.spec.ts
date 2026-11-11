import { expect, test, type Page } from '@playwright/test'
import { FixtureClient, type FixtureManifest } from '../live-updates/capacity-fixtures'
import {
  attachExchanges, destinationControl, nativeAudio, observeRegistrationAnswers, openSessionTab, playFixtureSong, sessionSnapshot,
  type RegistrationAnswer, type SessionTab,
} from './session-tabs'

// A registration lapses 30 seconds after its last presence report. A release that arrives well
// inside that time cannot come from the lapse; with separate contexts it cannot come from a local
// recovery or from a registration of the same tab either, so it is the page's own close report.
const RELEASE_WITHIN_MS = 8_000
const footer = (page: Page) => page.getByRole('region', { name: 'Playback', exact: true })
const refusal = (page: Page) => page.getByText(/Previous playback is unconfirmed/)

/** Presses Play in `tab` once its own view of the session shows nothing held, and expects it to start. */
async function playAtOnce(tab: SessionTab, formerRunner: string) {
  // The tab decides from the snapshot it holds, not from the one a test reads over HTTP.
  await expect(destinationControl(tab.page)).toHaveAttribute('title', 'Choose playback destination')
  const pressed = Date.now()
  await footer(tab.page).getByRole('button', { name: 'Play', exact: true }).click()
  await expect.poll(async () => (await nativeAudio(tab.page)).paused, { timeout: 25_000 }).toBe(false)
  // The session names its new runner once that runner's start is acknowledged.
  await expect.poll(async () => (await sessionSnapshot(tab.page)).activeIncarnationId).not.toBeNull()
  expect((await sessionSnapshot(tab.page)).activeIncarnationId).not.toBe(formerRunner)
  await expect(refusal(tab.page)).toHaveCount(0)
  expect(tab.exchanges.filter(exchange => exchange.at >= pressed && (exchange.status ?? 0) >= 400)).toEqual([])
  expect(tab.requested('/api/session/override')).toBe(false)
  expect(tab.requested('/api/session/recover-local')).toBe(false)
}

/** What the session shows once it has been released from `runner`, or null while it still names it. */
async function released(observer: Page, runner: string) {
  const snapshot = await sessionSnapshot(observer)
  if (snapshot.activeIncarnationId !== null) return null
  return {
    desiredState: snapshot.desiredState, media: snapshot.media, position: snapshot.position,
    runnerStillListed: snapshot.destinations.some(destination => destination.incarnationId === runner && destination.available),
  }
}

test.describe('a page that goes away reports its close', () => {
  test('a tab left by navigation releases the session at once, and another tab plays without an override', async ({ browser, baseURL }, testInfo) => {
    test.setTimeout(180_000)
    const fixture = FixtureClient.start()
    const tabs: SessionTab[] = []
    const receipt: Record<string, unknown> = {}
    try {
      const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
      const leaving = await openSessionTab(browser, baseURL, manifest.users[0], 'Leaving browser')
      tabs.push(leaving)
      const staying = await openSessionTab(browser, baseURL, manifest.users[0], 'Staying browser')
      tabs.push(staying)
      await expect.poll(async () => (await sessionSnapshot(staying.page)).destinations.filter(destination => destination.available).length).toBe(2)
      const runner = await playFixtureSong(leaving)
      const media = (await sessionSnapshot(staying.page)).media
      await expect(destinationControl(staying.page)).toHaveAttribute('title', 'Playing on Leaving browser')

      // Leaving the document dispatches pagehide by definition. No page of the application follows
      // in this context, so nothing registers for the tab again and nothing recovers it locally.
      const left = Date.now()
      await leaving.page.goto('about:blank')

      await expect.poll(async () => (await released(staying.page, runner)) !== null, {
        timeout: RELEASE_WITHIN_MS, message: 'the session to be released by the close report, long before the registration can lapse',
      }).toBe(true)
      receipt.releasedAfterMs = Date.now() - left
      receipt.closeReportSeenByDriver = leaving.requested('/api/session/close')
      const after = (await released(staying.page, runner))!
      expect(after).toMatchObject({ desiredState: 'paused', media, runnerStillListed: false })
      expect(after.position).toBeGreaterThan(1)

      await playAtOnce(staying, runner)
      expect(leaving.requested('/api/session/override')).toBe(false)
      expect(leaving.requested('/api/session/recover-local')).toBe(false)
    } finally {
      await attachExchanges(testInfo, tabs, receipt)
      await Promise.all(tabs.map(tab => tab.context.close()))
      try {
        if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
      } finally { await fixture.close() }
    }
  })

  test('a closed tab either reports its close or leaves the override as the way back, and the run records which', async ({ browser, baseURL }, testInfo) => {
    test.setTimeout(240_000)
    const fixture = FixtureClient.start()
    const tabs: SessionTab[] = []
    const receipt: Record<string, unknown> = {}
    try {
      const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
      const closing = await openSessionTab(browser, baseURL, manifest.users[0], 'Closing browser')
      tabs.push(closing)
      const staying = await openSessionTab(browser, baseURL, manifest.users[0], 'Staying browser')
      tabs.push(staying)
      await expect.poll(async () => (await sessionSnapshot(staying.page)).destinations.filter(destination => destination.available).length).toBe(2)
      const runner = await playFixtureSong(closing)
      await expect(destinationControl(staying.page)).toHaveAttribute('title', 'Playing on Closing browser')

      // Whether closing a page dispatches pagehide is the engine's and the driver's business. The
      // page is closed the way a test closes it, and what followed is recorded.
      const closed = Date.now()
      await closing.page.close()
      let reported = false
      await expect.poll(async () => {
        reported = (await released(staying.page, runner)) !== null
        return reported
      }, { timeout: RELEASE_WITHIN_MS }).toBe(true).catch(() => {})
      receipt.engine = browser.browserType().name()
      receipt.pagehideReportedTheClose = reported
      receipt.releasedAfterMs = reported ? Date.now() - closed : null
      receipt.closeReportSeenByDriver = closing.requested('/api/session/close')
      testInfo.annotations.push({ type: 'page.close()', description: reported ? 'dispatched pagehide: the close report released the session' : 'sent no close report: the session stayed held' })

      if (reported) {
        await playAtOnce(staying, runner)
      } else {
        // Without a report this is the crash case: the next tab is held until the registration
        // has lapsed, and then the destination menu offers the way back.
        await expect(destinationControl(staying.page)).toHaveAttribute('title', 'Previous playback is unconfirmed.', { timeout: 60_000 })
        await destinationControl(staying.page).click()
        await expect(staying.page.getByRole('menuitem', { name: 'Play here anyway', exact: true })).toBeEnabled()
        expect((await sessionSnapshot(staying.page)).activeIncarnationId).toBe(runner)
      }
    } finally {
      await attachExchanges(testInfo, tabs, receipt)
      await Promise.all(tabs.map(tab => tab.context.close()))
      try {
        if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
      } finally { await fixture.close() }
    }
  })

  test('a browser opened after the tab went away registers into a released session and plays at once', async ({ browser, baseURL }, testInfo) => {
    test.setTimeout(180_000)
    const fixture = FixtureClient.start()
    const tabs: SessionTab[] = []
    const receipt: Record<string, unknown> = {}
    try {
      const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
      const leaving = await openSessionTab(browser, baseURL, manifest.users[0], 'Leaving browser')
      tabs.push(leaving)
      const runner = await playFixtureSong(leaving)
      const media = (await sessionSnapshot(leaving.page)).media
      // The report leaves with this navigation. The browser opened next signs in and loads the
      // application first, seconds later, so its registration finds what the report left.
      await leaving.page.goto('about:blank')

      // The new context has storage of its own, so no local recovery record can release the
      // runner there, and its tab identity is new, so its registration releases nothing either.
      let answers: RegistrationAnswer[] = []
      const arriving = await openSessionTab(browser, baseURL, manifest.users[0], 'Arriving browser', context => {
        answers = observeRegistrationAnswers(context)
      })
      tabs.push(arriving)

      await expect.poll(() => answers.length, { message: 'the arriving browser to receive an answer to its registration' }).toBeGreaterThan(0)
      receipt.registrationAnswers = answers
      // Every answer this browser ever received to a registration already showed the session released.
      for (const answer of answers) expect(answer).toMatchObject({ activeIncarnationId: null, desiredState: 'paused', media })

      await playAtOnce(arriving, runner)
    } finally {
      await attachExchanges(testInfo, tabs, receipt)
      await Promise.all(tabs.map(tab => tab.context.close()))
      try {
        if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
      } finally { await fixture.close() }
    }
  })
})
