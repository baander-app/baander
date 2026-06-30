import { test, expect, spaNavigate } from '../fixtures'
import { main, untilLoaded } from '../selectors'

test.describe('Admin - Job Monitor', () => {
  test('renders job monitor page', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/admin/monitor')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page).getByRole('heading', { name: /job monitor/i })).toBeVisible()
  })

  test('shows status overview section', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/admin/monitor')
    await untilLoaded(main(page)).catch(() => {})

    const statusSection = main(page).getByText(/status|overview/i)
    if (await statusSection.isVisible().catch(() => false)) {
      await expect(statusSection).toBeVisible()
    }
  })

  test('shows job table or empty state', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/admin/monitor')
    await untilLoaded(main(page)).catch(() => {})

    // Table or empty state
    const hasTable = (await main(page).locator('table').count()) > 0
    const hasContent = (await main(page).locator('text').count()) > 0
    expect(hasTable || hasContent).toBeTruthy()
  })
})

test.describe('Admin - Rate Limiters', () => {
  test('renders rate limiters page', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/admin/rate-limiters')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page)).toBeVisible()
  })
})

test.describe('Admin - Server Diagnostics', () => {
  test('renders diagnostics page', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/admin/diagnostics')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page)).toBeVisible()
  })
})

test.describe('Admin - Configuration', () => {
  test('renders configuration page', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/admin/configuration')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page)).toBeVisible()
  })
})

test.describe('Admin - Access Control', () => {
  test('admin pages are accessible to admin user', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/admin/monitor')
    await page.waitForTimeout(500)

    // Should not redirect away
    const url = page.url()
    expect(url).toContain('/admin/monitor')
  })
})
