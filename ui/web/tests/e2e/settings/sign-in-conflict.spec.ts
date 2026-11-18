import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test'
import { login, withFixtureRun, type FixtureUser } from '../live-updates/capacity-fixtures'
import { fixtureSongRow } from '../playback-destinations/session-tabs'

/**
 * A fresh page knows no preference version yet. These cases sign in on a fresh browser while the
 * account already has a listening session and saved preferences, and hold the preference's first
 * read back, so that the session's track always arrives before it. Preference writes are real in
 * every context: a write refused for its stale version is one way the conflict appears.
 */

type Family = 'layout' | 'player'

interface PreferenceExchange {
  family: Family
  method: string
  sentVersion: number | null
  status: number
  version: number | null
  currentVersion: number | null
}

const preferencePath = (family: Family) => `/api/user/${family}-preferences/`

/** Records every preference read and write the page makes, with the versions sent and answered. */
function recordPreferences(page: Page): PreferenceExchange[] {
  const exchanges: PreferenceExchange[] = []
  page.on('response', async (response) => {
    const match = /^\/api\/user\/(layout|player)-preferences\/$/.exec(new URL(response.url()).pathname)
    if (!match) return
    let sent: { version?: number } | null = null
    try { sent = response.request().postDataJSON() } catch { /* A read carries no body. */ }
    const body = await response.json().catch(() => null) as
      { data?: { version?: number }; error?: { details?: { currentVersion?: number } } } | null
    exchanges.push({
      family: match[1] as Family,
      method: response.request().method(),
      sentVersion: sent?.version ?? null,
      status: response.status(),
      version: body?.data?.version ?? null,
      currentVersion: body?.error?.details?.currentVersion ?? null,
    })
  })
  return exchanges
}

/** Holds the page's first read of one preference family for `ms`; later reads pass at once. */
async function holdFirstRead(context: BrowserContext, family: Family, ms: number) {
  let held = false
  await context.route(`**${preferencePath(family)}`, async (route) => {
    if (route.request().method() !== 'GET' || held) return route.fallback()
    held = true
    await new Promise((resolve) => setTimeout(resolve, ms))
    // The page may have given the read up meanwhile; nothing is left to answer then.
    await route.fallback().catch(() => undefined)
  })
}

async function openContext(browser: Browser, baseURL: string | undefined) {
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } })
  const page = await context.newPage()
  return { context, page, exchanges: recordPreferences(page) }
}

/**
 * Signs `user` in on a first browser, plays the fixture song and pauses it, then chooses the
 * Queue tab, so the account has a listening session and a saved layout whose tab is not Details.
 */
async function startSession(browser: Browser, baseURL: string | undefined, user: FixtureUser) {
  const first = await openContext(browser, baseURL)
  await login(first.page, user)
  await first.page.goto('/music/songs')
  await fixtureSongRow(first.page).dblclick()
  const footer = first.page.getByRole('region', { name: 'Playback', exact: true })
  await footer.getByRole('button', { name: 'Pause', exact: true }).click()
  await expect(footer.getByRole('button', { name: 'Play', exact: true })).toBeVisible()
  const queueSaved = first.page.waitForResponse((response) =>
    new URL(response.url()).pathname === preferencePath('layout') && response.request().method() === 'PUT'
    && response.status() === 200 && response.request().postDataJSON().payload.activeTab === 'queue')
  await first.page.locator('aside').last().getByRole('tab', { name: 'Queue' }).click()
  await queueSaved
  return { ...first, footer }
}

const conflictDialog = (page: Page) => page.getByRole('dialog', { name: 'Settings conflict' })

/** Waits until the held read and the writes that follow it have settled, then checks for the dialog. */
async function expectNoConflict(page: Page, exchanges: PreferenceExchange[], family: Family) {
  await expect.poll(() => exchanges.some((exchange) => exchange.family === family && exchange.method === 'GET'),
    { timeout: 20_000, message: `the held ${family} read to be answered` }).toBe(true)
  await page.waitForTimeout(2_000)
  await test.info().attach('preference-exchanges', { body: JSON.stringify(exchanges, null, 2), contentType: 'application/json' })
  await expect(conflictDialog(page)).toHaveCount(0)
  expect(exchanges.filter((exchange) => exchange.status === 409), 'no preference write was refused for its version').toEqual([])
}

test.describe('signing in on a fresh browser', () => {
  test.setTimeout(120_000)

  test('shows the playing track without a settings conflict when the layout read comes last', async ({ browser, baseURL }) => {
    await withFixtureRun(async (manifest) => {
      const first = await startSession(browser, baseURL, manifest.users[0])
      const second = await openContext(browser, baseURL)
      try {
        await holdFirstRead(second.context, 'layout', 3_000)
        await login(second.page, manifest.users[0])
        await expectNoConflict(second.page, second.exchanges, 'layout')

        // The saved layout is applied, and the playing track does not move it to the Details tab.
        const panel = second.page.locator('aside').last()
        await expect(panel.getByRole('tab', { name: 'Queue' })).toHaveAttribute('aria-selected', 'true')
        await panel.getByRole('tab', { name: 'Details' }).click()
        await expect(panel.getByText('Live song 00000', { exact: true })).toBeVisible()
      } finally {
        await second.context.close()
        await first.context.close()
      }
    })
  })

  test('applies the session volume without a settings conflict when the player read comes last', async ({ browser, baseURL }) => {
    await withFixtureRun(async (manifest) => {
      const first = await startSession(browser, baseURL, manifest.users[0])
      const second = await openContext(browser, baseURL)
      try {
        const volume = first.footer.getByRole('slider', { name: 'Volume', exact: true })
        const playerSaved = first.page.waitForResponse((response) =>
          new URL(response.url()).pathname === preferencePath('player') && response.request().method() === 'PUT'
          && response.status() === 200 && response.request().postDataJSON().payload.volume === 0.4)
        await volume.focus()
        await first.page.keyboard.press('Home')
        for (let step = 0; step < 40; step++) await first.page.keyboard.press('ArrowRight')
        await expect(volume).toHaveAttribute('aria-valuenow', '40')
        await playerSaved

        await holdFirstRead(second.context, 'player', 3_000)
        await login(second.page, manifest.users[0])
        await expectNoConflict(second.page, second.exchanges, 'player')
        await expect(second.page.getByRole('region', { name: 'Playback', exact: true })
          .getByRole('slider', { name: 'Volume', exact: true })).toHaveAttribute('aria-valuenow', '40')
      } finally {
        await second.context.close()
        await first.context.close()
      }
    })
  })

  test('reloads an album page without a settings conflict when the layout read comes last', async ({ browser, baseURL }) => {
    await withFixtureRun(async (manifest) => {
      const first = await startSession(browser, baseURL, manifest.users[0])
      try {
        await first.page.goto(`/music/albums/${manifest.album.publicId}`)
        await expect(first.page.getByRole('main')).toBeVisible()
        first.exchanges.length = 0
        await holdFirstRead(first.context, 'layout', 3_000)
        await first.page.reload()
        await expectNoConflict(first.page, first.exchanges, 'layout')
      } finally {
        await first.context.close()
      }
    })
  })
})
