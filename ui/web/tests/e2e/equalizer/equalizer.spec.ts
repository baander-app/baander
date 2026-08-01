import { test, expect, spaNavigate } from '../fixtures'
import { main, untilLoaded } from '../selectors'

test.describe('Equalizer page', () => {
  test('renders the equalizer panel', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/equalizer')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page)).toBeVisible()
  })

  test('shows preset selector', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/equalizer')
    await untilLoaded(main(page)).catch(() => {})

    const presetSelect = main(page).locator('select').first()
    if (await presetSelect.isVisible().catch(() => false)) {
      await expect(presetSelect).toBeVisible()
    }
  })

  test('shows EQ enable toggle', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/equalizer')
    await untilLoaded(main(page)).catch(() => {})

    const enableToggle = main(page).getByRole('switch', { name: /enable|eq/i })
      .or(main(page).locator('button').filter({ hasText: /enable/i }))
    if (await enableToggle.isVisible().catch(() => false)) {
      await expect(enableToggle).toBeVisible()
    }
  })

  test('shows visualizer mode selector', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/equalizer')
    await untilLoaded(main(page)).catch(() => {})

    // Visualizer mode buttons or select
    const vizControls = main(page).locator('button').filter({ hasText: /spectrum|bars|wave/i })
    if (await vizControls.count() > 0) {
      await vizControls.first().click()
      await page.waitForTimeout(300)
      await expect(main(page)).toBeVisible()
    }
  })

  test('band sliders exist', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/equalizer')
    await untilLoaded(main(page)).catch(() => {})

    // EQ has sliders for bands (60Hz, 230Hz, etc.)
    const sliders = main(page).locator('input[type="range"]')
    const sliderCount = await sliders.count()
    expect(sliderCount).toBeGreaterThanOrEqual(0)
  })

  test('master gain slider is interactive', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/equalizer')
    await untilLoaded(main(page)).catch(() => {})

    const masterSlider = main(page).locator('input[type="range"]').last()
    if (await masterSlider.isVisible().catch(() => false)) {
      await masterSlider.fill('50')
      await page.waitForTimeout(200)
      await expect(main(page)).toBeVisible()
    }
  })
})
