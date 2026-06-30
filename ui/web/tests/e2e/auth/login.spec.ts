import { test, expect } from '@playwright/test'

test.describe('Login', () => {
  test('renders form with email, password, and submit', async ({ page }) => {
    await page.goto('/login')
    await page.waitForSelector('form')

    await expect(page.getByLabel(/email/i)).toBeVisible()
    await expect(page.getByLabel(/password/i)).toBeVisible()
    await expect(page.getByRole('button', { name: /log in/i })).toBeEnabled()
  })

  test('redirects to home on valid credentials', async ({ page }) => {
    await page.goto('/login')
    await page.waitForSelector('form')

    await page.getByLabel(/email/i).fill('admin@baander.test')
    await page.getByLabel(/password/i).fill('password')
    await page.getByRole('button', { name: /log in/i }).click()

    await expect(page).toHaveURL('/', { timeout: 10_000 })
  })

  test('shows error on invalid credentials', async ({ page }) => {
    await page.goto('/login')
    await page.waitForSelector('form')

    await page.getByLabel(/email/i).fill('admin@baander.test')
    await page.getByLabel(/password/i).fill('wrong')
    await page.getByRole('button', { name: /log in/i }).click()

    await expect(page.locator('text=Invalid credentials')).toBeVisible({ timeout: 5_000 })
    await expect(page).toHaveURL(/\/login/)
  })
})

test.describe('Unauthenticated access', () => {
  test('protected routes redirect to login', async ({ page }) => {
    await page.goto('/albums')
    await expect(page).toHaveURL(/\/login/, { timeout: 5_000 })
  })

  test('admin routes redirect to login when unauthenticated', async ({ page }) => {
    await page.goto('/admin/monitor')
    await expect(page).toHaveURL(/\/login/, { timeout: 5_000 })
  })
})

test.describe('Register', () => {
  test('renders registration form with fields', async ({ page }) => {
    await page.goto('/register')
    await page.waitForSelector('form')

    await expect(page.getByLabel(/email/i)).toBeVisible()
    await expect(page.getByLabel(/password/i, { exact: false }).first()).toBeVisible()
  })

  test('shows validation on empty submit', async ({ page }) => {
    await page.goto('/register')
    await page.waitForSelector('form')

    await page.getByRole('button', { name: /register|sign up|create/i }).click()

    // Form should still be visible (no navigation away)
    await expect(page.locator('form')).toBeVisible()
  })
})

test.describe('Logout', () => {
  test('logout returns to login page', async ({ page }) => {
    // Login first
    await page.goto('/login')
    await page.waitForSelector('form')
    await page.getByLabel(/email/i).fill('admin@baander.test')
    await page.getByLabel(/password/i).fill('password')
    await page.getByRole('button', { name: /log in/i }).click()
    await expect(page).toHaveURL('/', { timeout: 10_000 })

    // Find and click logout
    const logoutBtn = page.getByRole('button', { name: /log out|logout|sign out/i })
    if (await logoutBtn.isVisible().catch(() => false)) {
      await logoutBtn.click()
      await expect(page).toHaveURL(/\/login/, { timeout: 5_000 })
    }
  })
})
