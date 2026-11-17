import type { BrowserContext, Locator, Page } from '@playwright/test'
import { test, expect } from './footer-playback'
import { MOODS, setTheme } from '../helpers/theme-utils'
import { contrastRatio, expectContained, expectNoPageOverflow, luminance, paintedPixels, textColor } from './helpers'

// Two hours old at every run: a notification 30 days old shows a date, which the timestamp
// locator below would not match.
const notification = {
  publicId: 'wellNotification00001', category: 'media_changes',
  eventType: 'library.scan.completed', title: 'Music library scan finished',
  body: 'Your library is ready to browse.', parameters: null,
  isRead: false, createdAt: new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(),
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

/**
 * Closes the context panel, which shows the playing track. Call it before narrowing the
 * viewport: a narrow layout removes the panel by itself, possibly between the check and the click.
 */
async function closeContextPanel(page: Page) {
  const closeContext = page.getByRole('button', { name: 'Close context panel', exact: true })
  if (await closeContext.isVisible()) await closeContext.click()
  await expect(closeContext).toBeHidden()
}

/** Waits until the element's running animations and transitions have finished. */
const settled = (surface: Locator) =>
  surface.evaluate((node) => Promise.all(node.getAnimations().map((animation) => animation.finished)))

/** Opens the pane and waits until its fade has finished. */
async function openPane(page: Page) {
  await bellOf(page).click()
  await expect(paneOf(page)).toBeVisible()
  await settled(paneOf(page))
  return paneOf(page)
}

const box = async (locator: Locator) => (await locator.boundingBox())!

const displayOf = (page: Page) => page.getByRole('region', { name: 'Playback', exact: true })
  .getByRole('group', { name: 'Now playing', exact: true })

/**
 * What the footer display paints in its empty right padding: how many levels its face spans
 * from top to bottom, and whether a row at its lower edge is lighter than both the face and the
 * footer beside it, which only the lip is. A flat face can still span one level, because the
 * browser dithers the gradient it is painted with. The display can end between pixels, so the
 * lip is looked for across the rows around that edge.
 */
async function displayShading(page: Page) {
  const bounds = await box(displayOf(page))
  const x = bounds.x + bounds.width - 8
  const top = Math.ceil(bounds.y) + 3
  const bottom = Math.floor(bounds.y + bounds.height)
  const face = Array.from({ length: bottom - 3 - top }, (_, index) => ({ x, y: top + index }))
  // The rounded corner lifts the lip slightly at this column.
  const edge = [-2, -1, 0, 1, 2].map((offset) => ({ x, y: bottom + offset }))
  const pixels = await paintedPixels(page, [...face, ...edge, { x: bounds.x + bounds.width + 4, y: bottom + 2 }])
  const facePixels = pixels.slice(0, face.length)
  const edgePixels = pixels.slice(face.length, face.length + edge.length)
  const footer = pixels[pixels.length - 1]
  const lightestSurface = Math.max(luminance(footer), luminance(facePixels[Math.floor(facePixels.length / 2)]))
  const channelSpans = [0, 1, 2].map((channel) => {
    const levels = facePixels.map((pixel) => pixel[channel])
    return Math.max(...levels) - Math.min(...levels)
  })
  return {
    faceSpan: Math.max(...channelSpans),
    lip: edgePixels.some((pixel) => luminance(pixel) > lightestSurface + 0.0005),
  }
}

for (const width of [390, 1280]) {
  test(`the open pane shows the well in every mood at ${width}px`, async ({ pausedFooter: page }) => {
    test.setTimeout(120_000)
    await closeContextPanel(page)
    await page.setViewportSize({ width, height: 700 })
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
    timestamp: pane.getByText('2h ago', { exact: true }),
  }

  for (const mood of MOODS) {
    await setTheme(page, mood, 'violet')
    const bounds = await box(pane)
    const marks = await Promise.all(Object.entries(labels).map(async ([name, label]) => {
      const text = await box(label)
      // The header's labels sit at its two ends, the item's text ends before its right padding.
      const x = name === 'header' || name === 'action' ? bounds.x + bounds.width / 2 : bounds.x + bounds.width - 6
      return { name, color: await textColor(label), behind: { x, y: text.y + text.height / 2 } }
    }))
    const backgrounds = await paintedPixels(page, marks.map((mark) => mark.behind))
    marks.forEach((mark, index) => {
      expect(contrastRatio(mark.color, backgrounds[index]), `${mood}: ${mark.name}`).toBeGreaterThanOrEqual(4.5)
    })
  }
})

for (const width of [390, 1280]) {
  test(`the footer display yields the well to the open pane and takes it back at ${width}px`, async ({ pausedFooter: page }) => {
    test.setTimeout(180_000)
    await closeContextPanel(page)
    await page.setViewportSize({ width, height: 700 })
    const display = displayOf(page)

    for (const mood of MOODS) {
      await setTheme(page, mood, 'violet')
      const atRest = await displayShading(page)
      expect(atRest.faceSpan, `${mood}: the face is shaded at rest`).toBeGreaterThanOrEqual(3)
      expect(atRest.lip, `${mood}: the lip shows at rest`).toBe(true)

      await openPane(page)
      await expect(display).toHaveAttribute('data-well', 'yielded')
      await settled(display)
      const yielded = await displayShading(page)
      expect(yielded.faceSpan, `${mood}: the face is flat while the pane is open`).toBeLessThanOrEqual(1)
      expect(yielded.lip, `${mood}: no lip while the pane is open`).toBe(false)

      await page.keyboard.press('Escape')
      await expect(paneOf(page)).toBeHidden()
      await expect(display).toHaveAttribute('data-well', 'held')
      await settled(display)
      expect(await displayShading(page), `${mood}: the well is back`).toEqual(atRest)
    }
  })
}

test('the footer display stays readable while it is flat', async ({ pausedFooter: page }) => {
  test.setTimeout(120_000)
  await page.setViewportSize({ width: 1280, height: 700 })
  const display = displayOf(page)
  await openPane(page)
  await settled(display)

  for (const mood of MOODS) {
    await setTheme(page, mood, 'violet')
    const bounds = await box(display)
    const marks = await display.evaluate((element) => {
      const resolve = (value: string) => {
        const canvas = document.createElement('canvas')
        canvas.width = canvas.height = 1
        const context = canvas.getContext('2d')!
        context.fillStyle = value
        context.fillRect(0, 0, 1, 1)
        return [...context.getImageData(0, 0, 1, 1).data.slice(0, 3)]
      }
      const labels = [...element.querySelectorAll('p, span')].filter((node) =>
        [...node.childNodes].some((child) => child.nodeType === Node.TEXT_NODE && child.textContent?.trim()))
      const controls = [...element.querySelectorAll('[data-slot="slider-track"], [data-slot="slider-range"]')]
      return [...labels, ...controls].map((node) => {
        const rect = node.getBoundingClientRect()
        const text = labels.includes(node)
        return {
          name: text ? node.textContent : node.getAttribute('data-slot'),
          minimum: text ? 4.5 : 3,
          color: resolve(text ? getComputedStyle(node).color : getComputedStyle(node).backgroundColor),
          y: rect.top + rect.height / 2,
        }
      })
    })
    expect(marks.length).toBeGreaterThanOrEqual(5)
    const backgrounds = await paintedPixels(page, marks.map((mark) => ({ x: bounds.x + bounds.width - 6, y: mark.y })))
    marks.forEach((mark, index) => {
      expect(contrastRatio(mark.color, backgrounds[index]), `${mood}: ${mark.name}`).toBeGreaterThanOrEqual(mark.minimum)
    })
  }
})

test('the pane and the footer display change over the same handoff time', async ({ pausedFooter: page }) => {
  await page.setViewportSize({ width: 1280, height: 700 })
  await page.evaluate(() => {
    const starts: { name: string; duration: string; easing: string }[] = []
    Object.assign(window, { paneAnimationStarts: starts })
    document.addEventListener('animationstart', (event) => {
      if (!(event.target instanceof Element) || event.target.getAttribute('aria-label') !== 'Notifications') return
      const style = getComputedStyle(event.target)
      starts.push({ name: event.animationName, duration: style.animationDuration, easing: style.animationTimingFunction })
    }, true)
    const runs: { property: string; duration: string; easing: string }[] = []
    Object.assign(window, { displayTransitionRuns: runs })
    document.addEventListener('transitionrun', (event) => {
      if (!(event.target instanceof Element) || event.target.getAttribute('aria-label') !== 'Now playing') return
      const style = getComputedStyle(event.target)
      runs.push({ property: event.propertyName, duration: style.transitionDuration, easing: style.transitionTimingFunction })
    }, true)
  })

  await openPane(page)
  await settled(displayOf(page))
  await page.keyboard.press('Escape')
  await expect(paneOf(page)).toBeHidden()
  await settled(displayOf(page))

  const recorded = await page.evaluate(() => {
    const record = window as unknown as { paneAnimationStarts: unknown; displayTransitionRuns: unknown }
    return { pane: record.paneAnimationStarts, display: record.displayTransitionRuns }
  })
  expect(recorded.pane).toEqual([
    { name: 'fadeIn', duration: '0.12s', easing: 'ease-out' },
    { name: 'fadeOut', duration: '0.12s', easing: 'ease-out' },
  ])
  // Only the well's presence moves on the display, once to yield and once to take the well back.
  expect(recorded.display).toEqual([
    { property: '--well-presence', duration: '0.12s', easing: 'ease-out' },
    { property: '--well-presence', duration: '0.12s', easing: 'ease-out' },
  ])
})

test('the handoff is immediate when reduced motion is requested', async ({ pausedFooter: page }) => {
  await page.setViewportSize({ width: 1280, height: 700 })
  await page.emulateMedia({ reducedMotion: 'reduce' })
  const pane = await openPane(page)

  const seconds = (duration: string) => Number.parseFloat(duration)
  expect(seconds(await pane.evaluate((node) => getComputedStyle(node).animationDuration))).toBeLessThanOrEqual(0.00001)
  expect(seconds(await displayOf(page).evaluate((node) => getComputedStyle(node).transitionDuration))).toBeLessThanOrEqual(0.00001)
  await expect(displayOf(page)).toHaveAttribute('data-well', 'yielded')

  await page.keyboard.press('Escape')
  await expect(pane).toBeHidden()
  await expect(bellOf(page)).toBeFocused()
  await expect(displayOf(page)).toHaveAttribute('data-well', 'held')
  const restored = await displayShading(page)
  expect(restored.faceSpan, 'the face is shaded again').toBeGreaterThanOrEqual(3)
  expect(restored.lip, 'the lip is back').toBe(true)
})

for (const navigation of ['collapsed', 'expanded'] as const) {
  test(`Escape in the pane returns focus to the bell at 390px with the navigation ${navigation}`, async ({ pausedFooter: page }) => {
    await closeContextPanel(page)
    await page.setViewportSize({ width: 390, height: 700 })
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
