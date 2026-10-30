import { test, expect, catalogAlbum, catalogSong } from '../styling/fixtures'
import { spaNavigate } from '../fixtures'
import { sidebar, main, contextPanel } from '../selectors'

test.describe('Sidebar', () => {
  test('renders sidebar with logo and search', async ({ asAdmin: page }) => {
    await expect(sidebar(page)).toBeVisible()
    await expect(sidebar(page).getByText('Bånder', { exact: true })).toBeVisible()
    await expect(sidebar(page).getByPlaceholder('Search...')).toBeVisible()
    await expect(sidebar(page).getByRole('link', { name: 'Home', exact: true })).toHaveAttribute('aria-current', 'page')
  })

  test('sidebar search navigates to search page', async ({ asAdmin: page }) => {
    const search = sidebar(page).getByPlaceholder('Search...')
    await search.fill('test query')
    await search.press('Enter')
    await expect(page).toHaveURL((url) => url.pathname === '/search' && url.searchParams.get('q') === 'test query' && url.searchParams.get('scope') === 'music')
    await expect(main(page).getByRole('heading', { name: 'Search', exact: true, level: 1 })).toBeVisible()
  })

  test('sidebar customize button opens editor', async ({ asAdmin: page }) => {
    await sidebar(page).getByRole('button', { name: 'Customize', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Customize Sidebar', exact: true })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Save changes', exact: true })).toBeVisible()
  })

  test('sidebar album link reaches the albums page', async ({ asAdmin: page }) => {
    await sidebar(page).getByRole('link', { name: 'Albums', exact: true }).click()
    await expect(page).toHaveURL(/\/music\/albums$/)
    await expect(main(page).getByRole('heading', { name: 'Albums', exact: true, level: 1 })).toBeVisible()
    await expect(sidebar(page).getByRole('link', { name: 'Albums', exact: true })).toHaveAttribute('aria-current', 'page')
    await expect(sidebar(page).getByRole('link', { name: 'Home', exact: true })).not.toHaveAttribute('aria-current', 'page')
  })

  test('admin destination is available to the admin account', async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await expect(main(page).getByRole('link', { name: 'Admin', exact: true })).toBeVisible()
  })
})

test.describe('Context Panel', () => {
  test('context panel exposes all tabs', async ({ asAdmin: page }) => {
    await expect(contextPanel(page)).toBeVisible()
    await expect(contextPanel(page).getByRole('tab')).toHaveCount(4)
    for (const name of ['Queue', 'Lyrics', 'Details', 'Edit']) {
      await expect(contextPanel(page).getByRole('tab', { name, exact: true })).toBeVisible()
    }
  })

  test('empty catalog shows the empty message', async ({ emptyCatalog: page }) => {
    await expect(main(page).getByText('Your library is empty', { exact: true })).toBeVisible()
  })

  test('selecting a populated album opens details and track controls', async ({ populatedCatalog: page }) => {
    await spaNavigate(page)('/music/albums')
    await expect(main(page).getByRole('heading', { name: 'Albums', exact: true, level: 1 })).toBeVisible()
    await main(page).getByRole('button', { name: new RegExp(catalogAlbum.title) }).click()
    await expect(contextPanel(page).getByText(catalogAlbum.title, { exact: true })).toBeVisible()
    await expect(contextPanel(page).getByText(catalogSong.title, { exact: true })).toBeVisible()
    await expect(contextPanel(page).getByRole('button', { name: 'Play', exact: true })).toBeEnabled()
  })
})

test('keyboard shortcuts help opens with ? key', async ({ asAdmin: page }) => {
  await main(page).getByRole('heading', { name: 'Home', exact: true, level: 1 }).click()
  await page.keyboard.press('Shift+?')
  await expect(page.getByRole('dialog', { name: 'Keyboard Shortcuts' })).toBeVisible()
})
