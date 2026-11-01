import { test, expect, authenticate } from '../fixtures'
import { setTheme } from '../helpers/theme-utils'
import { expectContained, expectNoPageOverflow, expectReadable } from '../styling/helpers'

for (const width of [390, 1280]) {
  test(`sidebar notifications remain reachable and readable at ${width}px`, async ({ page }) => {
    let isRead = false
    await page.context().route('**/api/notifications/**', async (route) => {
      const path = new URL(route.request().url()).pathname
      if (route.request().method() === 'PATCH' && path.endsWith('/read-all')) {
        isRead = true
        await route.fulfill({ status: 204 })
      } else if (path.endsWith('/unread-count')) {
        await route.fulfill({ json: { data: { count: isRead ? 0 : 1 } } })
      } else if (path === '/api/notifications/') {
        await route.fulfill({ json: {
          data: [{
            publicId: 'sidebarNotification01', category: 'media_changes',
            eventType: 'library.scan.completed', title: 'Music library scan finished',
            body: 'Your library is ready to browse.', parameters: null,
            isRead, createdAt: '2026-11-01T12:00:00Z',
          }],
          nextCursor: null,
        } })
      } else {
        await route.fallback()
      }
    })
    await page.setViewportSize({ width, height: 700 })
    await authenticate(page)
    const closeContext = page.getByRole('button', { name: 'Close context panel', exact: true })
    if (await closeContext.isVisible()) await closeContext.click()
    const sidebar = page.locator('aside').first()
    const bell = sidebar.getByRole('button', { name: '1 unread notifications', exact: true })
    await expect(bell).toBeVisible()
    await bell.focus()
    await page.keyboard.press('Enter')
    const dialog = page.getByRole('dialog', { name: 'Notifications', exact: true })
    await expect(dialog.getByText('Music library scan finished', { exact: true })).toBeVisible()

    for (const mood of ['dark', 'balanced']) {
      await setTheme(page, mood, 'violet')
      await expectContained(dialog)
      await expectReadable(dialog.getByText('Music library scan finished', { exact: true }))
      await expectNoPageOverflow(page)
      await page.screenshot({ path: test.info().outputPath(`notifications-${width}-${mood}.png`) })
    }

    await dialog.getByRole('button', { name: 'Mark all read', exact: true }).click()
    const readBell = sidebar.getByRole('button', { name: '0 unread notifications', exact: true })
    await expect(readBell).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(readBell).toBeFocused()

    if (width < 1024) {
      await sidebar.getByRole('button', { name: 'Expand navigation', exact: true }).click()
      await readBell.click()
      await expect(dialog).toBeVisible()
      await expectContained(dialog)
      await page.keyboard.press('Escape')
      await expect(readBell).toBeFocused()
    }
  })
}
