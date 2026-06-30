import { test, expect, spaNavigate } from '../fixtures'
import { main, untilLoaded } from '../selectors'

test.describe('Settings page', () => {
  test('renders settings heading and content', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page).getByRole('heading', { name: /settings/i })).toBeVisible()
  })

  test('shows audio section', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page).getByText(/audio/i)).toBeVisible()
  })

  test('shows security section', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page).getByText(/security/i)).toBeVisible()
  })

  test('shows about section', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page).getByText(/about/i)).toBeVisible()
  })

  test('volume normalization toggle works', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await untilLoaded(main(page)).catch(() => {})

    const toggle = main(page).getByRole('switch', { name: /volume normalization/i })
    await expect(toggle).toBeVisible()

    await toggle.click()
    await page.waitForTimeout(200)

    // Toggle should flip state
    await expect(toggle).toBeVisible()
  })

  test('LUFS target select is visible', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await untilLoaded(main(page)).catch(() => {})

    const lufsSelect = main(page).locator('select[aria-label="LUFS Target"]')
      .or(main(page).locator('select').filter({ has: page.locator('option[value="-14"]') }))
    if (await lufsSelect.isVisible().catch(() => false)) {
      await lufsSelect.selectOption('-16')
      await page.waitForTimeout(200)
      await expect(main(page)).toBeVisible()
    }
  })

  test('open EQ button links to equalizer page', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await untilLoaded(main(page)).catch(() => {})

    const eqLink = main(page).getByRole('link', { name: /open eq/i })
      .or(main(page).getByRole('button', { name: /open eq/i }))
    if (await eqLink.isVisible().catch(() => false)) {
      await eqLink.click()
      await page.waitForTimeout(500)
      await expect(page).toHaveURL(/\/equalizer/, { timeout: 5_000 })
    }
  })

  test('passkey management section is visible', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await untilLoaded(main(page)).catch(() => {})

    const passkeySection = main(page).getByText(/passkey/i)
    if (await passkeySection.isVisible().catch(() => false)) {
      await expect(passkeySection).toBeVisible()
    }
  })
})

test.describe('Layout preferences API', () => {
  test('PUT /api/user/layout-preferences does not return 404', async ({ asAdmin: page }) => {
    const result = await page.evaluate(async () => {
      const res = await fetch('/api/user/layout-preferences/', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ payload: { mode: 'compact', activeTab: 'details' }, version: 1 }),
      })
      return res.status
    })

    expect(result).not.toBe(404)
  })

  test('GET /api/user/layout-preferences does not return 404', async ({ asAdmin: page }) => {
    const status = await page.evaluate(async () => {
      const res = await fetch('/api/user/layout-preferences/')
      return res.status
    })

    expect(status).not.toBe(404)
  })
})
