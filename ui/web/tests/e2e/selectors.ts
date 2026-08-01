import type { Page, Locator } from '@playwright/test'

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
export const untilLoaded = (container: Locator): Promise<void> =>
  container.locator('[data-slot="skeleton"]').first().waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {})
    .then(() => container.locator('[class*="animate-pulse"]').first().waitFor({ state: 'hidden', timeout: 5_000 }).catch(() => {}))

/** Album grid items (grid view). */
export const albumCards = (page: Page): Locator =>
  main(page).locator('button[class*="rounded-lg"]').filter({ hasText: /.+/ })

/** Album list rows (table view). */
export const albumRows = (page: Page): Locator =>
  main(page).locator('tbody tr')

/** Artist links. */
export const artistLinks = (page: Page): Locator =>
  main(page).locator('a[href^="/artists/"]')

/** Now-playing bar at the bottom. */
export const nowPlayingBar = (page: Page): Locator =>
  page.locator('.border-t.bg-background').last()

/** Navigate to a sidebar page by clicking its NavLink. */
export const navigateViaSidebar = (page: Page) => async (label: string | RegExp) => {
  await sidebar(page).getByRole('link', { name: label }).click()
}
