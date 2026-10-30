import { expect, type Page, type Locator } from '@playwright/test'

/** Main content area rendered by AppShell. */
export const main = (page: Page): Locator =>
  page.locator('main')

/** Sidebar navigation. */
export const sidebar = (page: Page): Locator =>
  page.locator('aside').first()

/** Context panel (right sidebar). */
export const contextPanel = (page: Page): Locator =>
  page.locator('aside').last()

/** Wait for loading skeletons to disappear from a container. */
export const untilLoaded = async (container: Locator): Promise<void> => {
  await expect(container).toBeVisible()
  await expect(container.locator('[data-slot="skeleton"]')).toHaveCount(0)
}

/** Album grid items (grid view). */
export const albumCards = (page: Page): Locator =>
  main(page).locator('button[data-slot="context-menu-trigger"]')

/** Album list rows (table view). */
export const albumRows = (page: Page): Locator =>
  main(page).locator('tbody tr')

/** Artist links. */
export const artistLinks = (page: Page): Locator =>
  main(page).locator('a[href^="/music/artists/"]')

/** Now-playing bar at the bottom. */
export const nowPlayingBar = (page: Page): Locator =>
  page.locator('div')
    .filter({ has: page.getByRole('slider', { name: 'Volume', exact: true }) })
    .filter({ has: page.getByRole('button', { name: 'Next', exact: true }) })
    .last().locator('..')

/** Navigate to a sidebar page by clicking its NavLink. */
export const navigateViaSidebar = (page: Page) => async (label: string | RegExp) => {
  await sidebar(page).getByRole('link', { name: label }).click()
}
