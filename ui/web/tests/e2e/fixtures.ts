import { test as base, expect } from '@playwright/test'
import type { Page } from '@playwright/test'

const CREDENTIALS = { email: 'admin@baander.test', password: 'admin' } as const

/** Log in through the real boundary; never manufacture authentication state. */
export const authenticate = async (page: Page) => {
  await page.goto('/login', { waitUntil: 'domcontentloaded' })
  await expect(page.getByRole('heading', { name: 'Bånder', exact: true })).toBeVisible()
  await page.getByLabel(/email/i).fill(CREDENTIALS.email)
  await page.getByLabel(/password/i).fill(CREDENTIALS.password)
  await page.getByRole('button', { name: /log in/i }).click()
  await expect(page).toHaveURL(/\/music$/)
  await expect(page.locator('main').getByRole('heading', { name: 'Home', exact: true, level: 1 })).toBeVisible()
  const sidebar = page.locator('aside').first()
  await expect(sidebar.getByRole('link', { name: 'Settings', exact: true }).or(
    sidebar.getByRole('button', { name: 'Expand navigation', exact: true }),
  )).toBeVisible()
}

/** React Router navigation preserves the in-memory authentication session. */
export const spaNavigate = (page: Page) => async (path: string) => {
  const link = page.locator(`a[href="${path}"]`).first()
  if (await link.isVisible()) {
    await link.click()
  } else {
    await page.evaluate((href) => {
      history.pushState(null, '', href)
      dispatchEvent(new PopStateEvent('popstate', { state: null }))
    }, path)
  }
  await expect(page).toHaveURL((url) => url.pathname + url.search === path)
  await expect(page.locator('main')).toBeVisible()
}

export const test = base.extend<{ asAdmin: Page }>({
  asAdmin: async ({ page }, providePage) => {
    await authenticate(page)
    await providePage(page)
  },
})

export { expect }
