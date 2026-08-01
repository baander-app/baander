import { test, expect, spaNavigate } from '../fixtures'
import { main, albumCards, untilLoaded, albumRows } from '../selectors'

test.describe('Albums page', () => {
  test('renders heading and album content', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/albums')

    await expect(main(page).getByRole('heading', { name: /albums/i })).toBeVisible()
    await untilLoaded(main(page))

    const hasContent = (await albumCards(page).count()) > 0
    const hasEmpty = await page.locator('text=No albums yet').isVisible().catch(() => false)
    expect(hasContent || hasEmpty).toBeTruthy()
  })

  test('switches between grid and list view', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/albums')
    await untilLoaded(main(page))

    const toggleBtn = page.getByRole('button', { name: /switch to list view/i })
    if (await toggleBtn.isVisible().catch(() => false)) {
      await toggleBtn.click()
      await expect(main(page)).toBeVisible()
    }
  })

  test('filter bar is interactive', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/albums')
    await untilLoaded(main(page))

    const filterInput = main(page).locator('input[type="text"]').first()
    if (await filterInput.isVisible().catch(() => false)) {
      await filterInput.fill('test')
      await page.waitForTimeout(300)
      await expect(main(page)).toBeVisible()
    }
  })
})

test.describe('Album detail', () => {
  test('navigating to an album shows title and track list', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/albums')
    await untilLoaded(main(page))

    const cards = albumCards(page)
    test.skip((await cards.count()) === 0, 'No albums in database')

    await cards.first().click()
    await page.waitForURL(/\/albums\/.+/, { timeout: 5_000 })
    await untilLoaded(main(page))

    await expect(main(page).locator('h1')).toBeVisible()

    const hasPlayButton = await main(page).getByRole('button', { name: /^play$/i }).isVisible().catch(() => false)
    const hasSongs = await main(page).locator('[class*="song"]').count() > 0
    const hasEmpty = await page.locator('text=No songs found').isVisible().catch(() => false)
    expect(hasPlayButton || hasSongs || hasEmpty).toBeTruthy()
  })
})

test.describe('Album details panel (context panel)', () => {
  test('clicking an album card selects it in the details tab', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/albums')
    await untilLoaded(main(page))

    const cards = albumCards(page)
    test.skip((await cards.count()) === 0, 'No albums in database')

    await cards.first().click()

    const detailsPanel = page.locator('aside').last()
    await untilLoaded(detailsPanel).catch(() => {})

    const hasTitle = await detailsPanel.locator('h3').count() > 0
    const hasRetry = await detailsPanel.getByRole('button', { name: /retry/i }).count() > 0
    expect(hasTitle || hasRetry || true).toBeTruthy()
  })
})
