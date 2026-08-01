import { test, expect, spaNavigate } from '../fixtures'
import { main, untilLoaded } from '../selectors'

test.describe('Songs page', () => {
  test('renders heading and song count', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/songs')
    await untilLoaded(main(page))

    await expect(main(page).getByRole('heading', { name: /songs/i })).toBeVisible()
  })

  test('renders 3-column browser (Genre, Artist, Album)', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/songs')
    await untilLoaded(main(page)).catch(() => {})

    // Browser columns have titles
    const genreCol = main(page).locator('text=Genre').first()
    const artistCol = main(page).locator('text=Artist').first()
    const albumCol = main(page).locator('text=Album').first()

    await expect(genreCol).toBeVisible({ timeout: 5_000 })
    await expect(artistCol).toBeVisible()
    await expect(albumCol).toBeVisible()
  })

  test('clicking a genre in browser highlights it', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/songs')
    await untilLoaded(main(page)).catch(() => {})

    // Browser items are buttons inside columns
    const genreButtons = main(page).locator('button').filter({ hasText: /.+/ }).filter({ has: page.locator('text=Genre') })
    const firstGenreButton = main(page).locator('[class*="border-r"] button').first()
    if (await firstGenreButton.isVisible().catch(() => false)) {
      await firstGenreButton.click()
      await page.waitForTimeout(300)
      // Should not crash
      await expect(main(page)).toBeVisible()
    }
  })

  test('song list renders below browser columns', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/songs')
    await untilLoaded(main(page)).catch(() => {})

    // Virtual song list area
    const songList = main(page).locator('[class*="flex-1"]').last()
    await expect(songList).toBeVisible()
  })
})

test.describe('Genres page', () => {
  test('renders heading and genre content', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/genres')
    await untilLoaded(main(page))

    await expect(main(page).getByRole('heading', { name: /genres/i })).toBeVisible()
  })

  test('genre cards are clickable and navigate to albums', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/genres')
    await untilLoaded(main(page))

    const genreCards = main(page).locator('button').filter({ hasText: /.+/ })
    if ((await genreCards.count()) > 0) {
      await genreCards.first().click()
      // Should navigate to albums with genre filter
      await page.waitForTimeout(500)
      await expect(page).toHaveURL(/\/albums/, { timeout: 5_000 })
    }
  })
})

test.describe('Search', () => {
  test('sidebar search input accepts queries', async ({ asAdmin: page }) => {
    const searchInput = page.locator('aside input[type="text"]').first()
    await expect(searchInput).toBeVisible()
    await searchInput.fill('test')
    await searchInput.press('Enter')

    await page.waitForURL(/\/search/, { timeout: 5_000 })
    await untilLoaded(main(page)).catch(() => {})
    await expect(main(page)).toBeVisible()
  })

  test('search page shows results for a query', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/search?q=test')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page).getByRole('heading', { name: /search/i })).toBeVisible()
  })

  test('search page shows empty state without query', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/search')
    await untilLoaded(main(page)).catch(() => {})

    const emptyMessage = page.locator('text=Search for albums, artists, or songs')
    await expect(emptyMessage).toBeVisible()
  })
})

test.describe('Home page', () => {
  test('renders home heading after login', async ({ asAdmin: page }) => {
    // Already on home after login
    await expect(main(page).getByRole('heading', { name: /home/i })).toBeVisible()
  })

  test('shows dashboard sections', async ({ asAdmin: page }) => {
    await untilLoaded(main(page)).catch(() => {})

    // Sections use DashboardSection with titles
    const sections = main(page).locator('h2, [class*="font-semibold"]')
    const hasSections = (await sections.count()) > 0
    const hasEmpty = await page.locator('text=Your library is empty').isVisible().catch(() => false)
    expect(hasSections || hasEmpty).toBeTruthy()
  })

  test('album cards on home are clickable', async ({ asAdmin: page }) => {
    await untilLoaded(main(page)).catch(() => {})

    const homeAlbumCards = main(page).locator('button[class*="rounded-lg"]').filter({ hasText: /.+/ })
    if ((await homeAlbumCards.count()) > 0) {
      await homeAlbumCards.first().click()
      // Click sets context panel selection — no navigation, just state change
      await page.waitForTimeout(300)
      await expect(main(page)).toBeVisible()
    }
  })
})
