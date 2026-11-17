import type { BrowserContext, Locator, Page } from '@playwright/test'
import { test, expect } from './footer-playback'
import { MOODS, setTheme } from '../helpers/theme-utils'
import { contrastRatio, expectContained, expectNoPageOverflow, luminance, paintedPixels, textColor } from './helpers'

const notification = {
  publicId: 'wellNotification00001', category: 'media_changes',
  eventType: 'library.scan.completed', title: 'Music library scan finished',
  body: 'Your library is ready to browse.', parameters: null,
  isRead: false, createdAt: '2026-11-01T12:00:00Z',
}

/** One unread notification, so the pane shows its header action, a title and a timestamp. */
async function routeNotifications(context: BrowserContext) {
  await context.route('**/api/notifications/**', async (route) => {
    const path = new URL(route.request().url()).pathname
    if (route.request().method() !== 'GET') return route.fallback()
    if (path.endsWith('/unread-count')) return route.fulfill({ json: { data: { count: 1 } } })
    if (path === '/api/notifications/') return route.fulfill({ json: { data: [notification], nextCursor: null } })
    return route.fallback()
  })
}

test.use({ contextSetup: { prepare: routeNotifications } })

const bellOf = (page: Page) => page.locator('aside').first().getByRole('button', { name: '1 unread notifications', exact: true })
const paneOf = (page: Page) => page.getByRole('dialog', { name: 'Notifications', exact: true })

async function closeContextPanel(page: Page) {
  const closeContext = page.getByRole('button', { name: 'Close context panel', exact: true })
  if (await closeContext.isVisible()) await closeContext.click()
}

/** Opens the pane and waits until its fade has finished. */
async function openPane(page: Page) {
  await bellOf(page).click()
  await expect(paneOf(page)).toBeVisible()
  await paneOf(page).evaluate((node) => Promise.all(node.getAnimations().map((animation) => animation.finished)))
  return paneOf(page)
}

const box = async (locator: Locator) => (await locator.boundingBox())!

for (const width of [390, 1280]) {
  test(`the open pane shows the well in every mood at ${width}px`, async ({ pausedFooter: page }) => {
    test.setTimeout(120_000)
    await page.setViewportSize({ width, height: 700 })
    await closeContextPanel(page)
    const pane = await openPane(page)

    for (const mood of MOODS) {
      await setTheme(page, mood, 'violet')
      const bounds = await box(pane)
      // A column clear of the rounded corners and above the header label's glyphs.
      const x = bounds.x + 24
      const bottom = Math.round(bounds.y + bounds.height)
      const [edge, face, lip, below] = await paintedPixels(page, [
        { x, y: bounds.y + 1 }, { x, y: bounds.y + 6 }, { x, y: bottom }, { x, y: bottom + 3 },
      ])
      expect(luminance(edge), `${mood}: the inset upper edge is darker than the face`).toBeLessThan(luminance(face))
      expect(luminance(lip), `${mood}: the lip below the pane is lighter than what is under it`).toBeGreaterThan(luminance(below))
      await expectContained(pane)
      await expectNoPageOverflow(page)
    }
  })
}

test('every label in the open pane stays readable over the well in every mood', async ({ pausedFooter: page }) => {
  test.setTimeout(120_000)
  await page.setViewportSize({ width: 1280, height: 700 })
  const pane = await openPane(page)
  const labels = {
    header: pane.getByText('Notifications', { exact: true }),
    action: pane.getByRole('button', { name: 'Mark all read', exact: true }),
    title: pane.getByText(notification.title, { exact: true }),
    timestamp: pane.getByText(/ago$|^just now$/),
  }

  for (const mood of MOODS) {
    await setTheme(page, mood, 'violet')
    const bounds = await box(pane)
    for (const [name, label] of Object.entries(labels)) {
      const text = await box(label)
      // The header's labels sit at its two ends, the item's text ends before its right padding.
      const x = name === 'header' || name === 'action' ? bounds.x + bounds.width / 2 : bounds.x + bounds.width - 6
      const [background] = await paintedPixels(page, [{ x, y: text.y + text.height / 2 }])
      expect(contrastRatio(await textColor(label), background), `${mood}: ${name}`).toBeGreaterThanOrEqual(4.5)
    }
  }
})

test('the pane fades in and out over the handoff time', async ({ pausedFooter: page }) => {
  await page.setViewportSize({ width: 1280, height: 700 })
  await page.evaluate(() => {
    const starts: { name: string; duration: string; easing: string }[] = []
    Object.assign(window, { paneAnimationStarts: starts })
    document.addEventListener('animationstart', (event) => {
      if (!(event.target instanceof Element) || event.target.getAttribute('aria-label') !== 'Notifications') return
      const style = getComputedStyle(event.target)
      starts.push({ name: event.animationName, duration: style.animationDuration, easing: style.animationTimingFunction })
    }, true)
  })

  await openPane(page)
  await page.keyboard.press('Escape')
  await expect(paneOf(page)).toBeHidden()

  const starts = await page.evaluate(() => (window as unknown as { paneAnimationStarts: unknown }).paneAnimationStarts)
  expect(starts).toEqual([
    { name: 'fadeIn', duration: '0.12s', easing: 'ease-out' },
    { name: 'fadeOut', duration: '0.12s', easing: 'ease-out' },
  ])
})

for (const navigation of ['collapsed', 'expanded'] as const) {
  test(`Escape in the pane returns focus to the bell at 390px with the navigation ${navigation}`, async ({ pausedFooter: page }) => {
    await page.setViewportSize({ width: 390, height: 700 })
    await closeContextPanel(page)
    const sidebar = page.locator('aside').first()
    if (navigation === 'expanded') await sidebar.getByRole('button', { name: 'Expand navigation', exact: true }).click()
    await expect(sidebar).toHaveAttribute('data-collapsed', String(navigation === 'collapsed'))

    await bellOf(page).click()
    await expect(paneOf(page)).toBeVisible()
    await expect(paneOf(page).getByRole('button', { name: 'Mark all read', exact: true })).toBeFocused()
    await page.keyboard.press('Escape')

    await expect(paneOf(page)).toBeHidden()
    await expect(bellOf(page)).toBeFocused()
    await expect(sidebar).toHaveAttribute('data-collapsed', String(navigation === 'collapsed'))
  })
}
