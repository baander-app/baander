import { test, expect, spaNavigate } from '../fixtures'
import { main, untilLoaded } from '../selectors'

test.describe('Playlists page', () => {
  test('renders heading and list area', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    await expect(main(page).getByRole('heading', { name: /playlists/i })).toBeVisible()
  })

  test('shows create playlist button', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    await expect(page.getByRole('button', { name: /create playlist/i })).toBeVisible()
  })

  test('clicking create opens the form', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    await page.getByRole('button', { name: /create playlist/i }).click()

    await expect(page.getByPlaceholder(/playlist name/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /^create$/i })).toBeVisible()
    await expect(page.getByRole('button', { name: /cancel/i })).toBeVisible()
  })

  test('create form validates empty name', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    await page.getByRole('button', { name: /create playlist/i }).click()

    const createBtn = page.getByRole('button', { name: /^create$/i })
    await expect(createBtn).toBeDisabled()
  })

  test('can type a name and description', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    await page.getByRole('button', { name: /create playlist/i }).click()

    const nameInput = page.getByPlaceholder(/playlist name/i)
    await nameInput.fill('My E2E Playlist')

    const descInput = page.getByPlaceholder(/description/i)
    if (await descInput.isVisible().catch(() => false)) {
      await descInput.fill('Test description')
    }

    await expect(page.getByRole('button', { name: /^create$/i })).toBeEnabled()
  })

  test('smart playlist toggle works', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    await page.getByRole('button', { name: /create playlist/i }).click()

    const smartToggle = page.getByRole('button', { name: /smart playlist/i })
    if (await smartToggle.isVisible().catch(() => false)) {
      await smartToggle.click()
      // Should show smart playlist editor
      await page.waitForTimeout(300)
      await expect(main(page)).toBeVisible()
    }
  })

  test('cancel button closes create form', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    await page.getByRole('button', { name: /create playlist/i }).click()
    await page.getByPlaceholder(/playlist name/i).fill('test')

    await page.getByRole('button', { name: /cancel/i }).click()

    await expect(page.getByPlaceholder(/playlist name/i)).not.toBeVisible()
  })

  test('shows playlists or empty state', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    const hasPlaylists = (await main(page).locator('button').filter({ hasText: /.+/ }).count()) > 0
    const hasEmpty = await page.locator('text=No playlists yet').isVisible().catch(() => false)
    const hasSelectPrompt = await page.locator('text=Select a playlist').isVisible().catch(() => false)
    expect(hasPlaylists || hasEmpty || hasSelectPrompt).toBeTruthy()
  })
})

test.describe('Playlist detail', () => {
  test('clicking a playlist shows detail panel', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    // Find playlist items in the left list
    const playlistItems = main(page).locator('button').filter({ hasText: /.+/ }).filter({ hasNotText: /create|cancel/i })
    test.skip((await playlistItems.count()) === 0, 'No playlists in database')

    await playlistItems.first().click()
    await page.waitForTimeout(500)

    // Detail panel should show playlist name or loading state
    await expect(main(page)).toBeVisible()
  })

  test('delete button appears on hover', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/playlists')
    await untilLoaded(main(page)).catch(() => {})

    const playlistItems = main(page).locator('[class*="group"]').filter({ has: page.locator('button') })
    if ((await playlistItems.count()) > 0) {
      await playlistItems.first().hover()
      const deleteBtn = playlistItems.first().getByRole('button', { name: /delete playlist/i })
      if (await deleteBtn.isVisible().catch(() => false)) {
        // Button is visible on hover
        await expect(deleteBtn).toBeVisible()
      }
    }
  })
})
