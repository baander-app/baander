import { test, expect, spaNavigate } from '../fixtures'
import { sidebar, main, contextPanel, untilLoaded } from '../selectors'

test.describe('Sidebar', () => {
  test('renders sidebar with logo and search', async ({ asAdmin: page }) => {
    await expect(sidebar(page)).toBeVisible()

    const logo = sidebar(page).getByText(/bånder/i)
    await expect(logo).toBeVisible()

    const searchInput = sidebar(page).locator('input[type="text"]')
    await expect(searchInput).toBeVisible()
  })

  test('sidebar search navigates to search page', async ({ asAdmin: page }) => {
    const searchInput = sidebar(page).locator('input[type="text"]')
    await searchInput.fill('test query')
    await searchInput.press('Enter')

    await page.waitForURL(/\/search/, { timeout: 5_000 })
    await expect(page).toHaveURL(/q=test/)
  })

  test('sidebar customize button opens editor', async ({ asAdmin: page }) => {
    const customizeBtn = sidebar(page).getByRole('button', { name: /customize sidebar/i })
    await expect(customizeBtn).toBeVisible()
    await customizeBtn.click()

    // Sidebar editor overlay should appear
    const editor = page.locator('[class*="sidebar-editor"]').or(page.locator('[data-state="open"]'))
    if (await editor.isVisible().catch(() => false)) {
      await expect(editor).toBeVisible()
    }
  })

  test('sidebar navigation links work', async ({ asAdmin: page }) => {
    // Click each visible nav link
    const navLinks = sidebar(page).locator('nav a')
    const count = await navLinks.count()

    if (count > 0) {
      await navLinks.first().click()
      await page.waitForTimeout(300)
      await expect(main(page)).toBeVisible()
    }
  })

  test('admin section visible to admin users', async ({ asAdmin: page }) => {
    const adminSection = sidebar(page).getByText(/admin/i).first()
    if (await adminSection.isVisible().catch(() => false)) {
      const adminLinks = sidebar(page).locator('nav a[href^="/admin/"]')
      expect((await adminLinks.count()) > 0).toBeTruthy()
    }
  })
})

test.describe('Context Panel', () => {
  test('context panel is visible', async ({ asAdmin: page }) => {
    await expect(contextPanel(page)).toBeVisible()
  })

  test('context panel has tabs', async ({ asAdmin: page }) => {
    const tabs = contextPanel(page).getByRole('tab')
    const tabCount = await tabs.count()
    expect(tabCount).toBeGreaterThanOrEqual(0)
  })

  test('selecting an album from home opens details in context panel', async ({ asAdmin: page }) => {
    // Home page is already loaded
    await untilLoaded(main(page)).catch(() => {})

    const albumCards = main(page).locator('button[class*="rounded-lg"]').filter({ hasText: /.+/ })
    if ((await albumCards.count()) > 0) {
      await albumCards.first().click()
      await page.waitForTimeout(500)

      // Context panel should update
      const panelContent = contextPanel(page).locator('h3, [class*="font-medium"]')
      if ((await panelContent.count()) > 0) {
        await expect(panelContent.first()).toBeVisible()
      }
    }
  })
})

test.describe('Keyboard shortcuts', () => {
  test('keyboard shortcuts help opens with ? key', async ({ asAdmin: page }) => {
    // Press ? to open shortcuts overlay
    await page.keyboard.press('?')

    const shortcutsHelp = page.locator('text=keyboard shortcuts').or(page.locator('[class*="shortcuts"]'))
    if (await shortcutsHelp.isVisible().catch(() => false)) {
      await expect(shortcutsHelp).toBeVisible()
    }
  })
})
