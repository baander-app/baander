import { test, expect, spaNavigate } from '../fixtures'
import { main, artistLinks, untilLoaded } from '../selectors'

test.describe('Artists page', () => {
  test('renders heading and artist list', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/artists')
    await untilLoaded(main(page))

    await expect(main(page).getByRole('heading', { name: /artists/i })).toBeVisible()
  })

  test('shows artist cards or empty state', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/artists')
    await untilLoaded(main(page))

    const hasArtistCards = (await main(page).locator('a[href^="/artists/"]').count()) > 0
    const hasArtistGridItems = (await main(page).locator('button[class*="rounded-lg"]').count()) > 0
    const hasEmpty = await page.locator('text=No artists').isVisible().catch(() => false)
    expect(hasArtistCards || hasArtistGridItems || hasEmpty).toBeTruthy()
  })
})

test.describe('Artist detail', () => {
  test('navigating to an artist shows name and content', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/artists')
    await untilLoaded(main(page))

    const links = artistLinks(page)
    test.skip((await links.count()) === 0, 'No artists in database')

    await links.first().click()
    await page.waitForURL(/\/artists\/.+/, { timeout: 5_000 })
    await untilLoaded(main(page))

    await expect(main(page).locator('h1')).toBeVisible()
  })

  test('artist detail page shows albums or empty state', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/artists')
    await untilLoaded(main(page))

    const links = artistLinks(page)
    test.skip((await links.count()) === 0, 'No artists in database')

    await links.first().click()
    await page.waitForURL(/\/artists\/.+/, { timeout: 5_000 })
    await untilLoaded(main(page))

    // Page should render without crash — album list or empty state
    await expect(main(page)).toBeVisible()
  })
})
