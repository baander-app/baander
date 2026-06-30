import { test as base, expect } from '@playwright/test'
import type { Page } from '@playwright/test'

/**
 * Authenticate through the real login form and keep auth alive.
 *
 * The app holds DPoP key pairs and access tokens in zustand (in-memory only —
 * no localStorage, no cookies). A full page reload via page.goto() destroys
 * this state and leaves the user unauthenticated.
 *
 * Strategy: login once, then navigate exclusively via React Router
 * (clicking links / programmatic navigate) to avoid full reloads.
 */

const CREDENTIALS = { email: 'admin@baander.test', password: 'password' } as const

const authenticate = async (page: Page) => {
  for (let attempt = 0; attempt < 3; attempt++) {
    try {
      await page.goto('/login', { timeout: 30_000, waitUntil: 'domcontentloaded' })
      await page.waitForSelector('form', { timeout: 10_000 })
      break
    } catch {
      if (attempt === 2) throw new Error('Server unavailable after 3 attempts')
      await page.waitForTimeout(3_000)
    }
  }
  await page.getByLabel(/email/i).fill(CREDENTIALS.email)
  await page.getByLabel(/password/i).fill(CREDENTIALS.password)
  await page.getByRole('button', { name: /log in/i }).click()
  await page.waitForURL('/', { timeout: 15_000 })
  await page.waitForTimeout(500)
}

/**
 * Navigate to a path within the SPA using React Router.
 * Avoids full page reload that would destroy in-memory auth state.
 */
export const spaNavigate = (page: Page) => async (path: string) => {
  const link = page.locator(`nav a[href="${path}"]`)
  if (await link.isVisible().catch(() => false)) {
    await link.click()
    await page.waitForTimeout(300)
    return
  }

  await page.evaluate((href) => {
    const event = new PopStateEvent('popstate', { state: null })
    history.pushState(null, '', href)
    dispatchEvent(event)
  }, path)
  await page.waitForTimeout(500)
}

export const test = base.extend<{ asAdmin: Page }>({
  asAdmin: async ({ page }, use) => {
    await authenticate(page)
    await use(page)
  },
})

export { expect }
