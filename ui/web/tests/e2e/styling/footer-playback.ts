import { test as base, expect, type BrowserContext, type Page } from '@playwright/test'
import { FixtureClient, type FixtureManifest } from '../live-updates/capacity-fixtures'
import { fixtureSongRow, openSessionTab, type SessionTab } from '../playback-destinations/session-tabs'

interface FooterPlayback {
  /** Prepares the browser context, for example with routes, before the fixture user signs in. */
  contextSetup: { prepare: (context: BrowserContext) => Promise<void> | void }
  /** A signed-in page whose footer shows the fixture song, paused. */
  pausedFooter: Page
}

/**
 * The listening session accepts only songs the server knows, so the footer cannot show a track
 * from the catalog GET fixtures. This plays the server fixture's own song as a fixture user in a
 * context of its own, pauses it, and removes the fixture afterwards.
 */
export const test = base.extend<FooterPlayback>({
  contextSetup: [{ prepare: () => {} }, { option: true }],
  pausedFooter: async ({ browser, baseURL, contextSetup }, providePage) => {
    const fixture = FixtureClient.start()
    let tab: SessionTab | undefined
    try {
      const manifest = (await fixture.request<FixtureManifest>({ op: 'create' })).receipt
      let prepared: Promise<void> | void
      tab = await openSessionTab(browser, baseURL, manifest.users[0], 'Styling browser', (context) => {
        prepared = contextSetup.prepare(context)
      })
      await prepared
      await tab.page.goto('/music/songs')
      await fixtureSongRow(tab.page).dblclick()
      const footer = tab.page.getByRole('region', { name: 'Playback', exact: true })
      // The seek slider is disabled with a range of one until the track's duration is known.
      await expect(footer.getByRole('slider', { name: 'Seek', exact: true })).not.toHaveAttribute('aria-valuemax', '1', { timeout: 25_000 })
      await footer.getByRole('button', { name: 'Pause', exact: true }).click()
      await expect(footer.getByRole('button', { name: 'Play', exact: true })).toBeVisible()
      await providePage(tab.page)
    } finally {
      await tab?.context.close()
      try {
        if (fixture.run) expect((await fixture.request({ op: 'cleanup' })).receipt.complete).toBe(true)
      } finally {
        await fixture.close()
      }
    }
  },
})

export { expect }
