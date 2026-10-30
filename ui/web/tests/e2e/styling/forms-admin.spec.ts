import { test, expect, installCatalogFixtures } from './fixtures'
import { spaNavigate, authenticate } from '../fixtures'
import { setTheme } from '../helpers/theme-utils'
import { expectContained, expectNoPageOverflow, expectReadable } from './helpers'

for (const width of [1280, 768, 390]) {
  test(`administration form stays usable at ${width}px`, async ({ emptyCatalog: page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 })
    await spaNavigate(page)('/admin/security?tab=users')
    await expect(page.getByRole('heading', { name: 'Security', exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'Create User', exact: true }).click()
    const dialog = page.getByRole('dialog', { name: 'Create User' })
    await expect(dialog).toBeVisible()
    const bounds = await dialog.boundingBox()
    expect(bounds!.x).toBeGreaterThanOrEqual(15)
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width - 15)
    for (const name of ['Email', 'Name', 'Password']) {
      const field = dialog.getByLabel(name, { exact: true })
      await expectContained(field)
      await field.focus()
      await expect(field).toBeFocused()
    }
    await dialog.getByLabel('Name', { exact: true }).fill('A long display name that must remain editable in a narrow administration dialog')
    await dialog.getByLabel('Email', { exact: true }).fill('invalid-email')
    await dialog.getByRole('button', { name: 'Create', exact: true }).click()
    expect(await dialog.getByLabel('Email', { exact: true }).evaluate((field: HTMLInputElement) => field.validity.typeMismatch)).toBe(true)
    await expectContained(dialog.getByRole('button', { name: 'Cancel', exact: true }))
    await expectReadable(dialog.getByRole('button', { name: 'Cancel', exact: true }))
    await expectNoPageOverflow(page)
    await page.screenshot({ path: testInfo.outputPath(`admin-form-${width}.png`) })
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click()
  })
}

test('preference conflict choices stay readable and keyboard accessible', async ({ emptyCatalog: page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 900 })
  await spaNavigate(page)('/settings')
  const toggle = page.getByRole('switch', { name: 'Volume normalization' })
  const original = await toggle.getAttribute('aria-checked') === 'true'
  let attemptedPayload: Record<string, unknown> = {}
  await page.route('**/api/user/audio-preferences/', async (route) => {
    if (route.request().method() === 'PUT') {
      attemptedPayload = route.request().postDataJSON().payload
      return route.fulfill({ status: 409, json: { error: { details: { currentVersion: 42 } } } })
    }
    return route.fulfill({ json: { data: { version: 42, payload: { ...attemptedPayload, normalizationEnabled: original } } } })
  })
  try {
    await toggle.click()
    const dialog = page.getByRole('dialog', { name: 'Settings conflict' })
    await expect(dialog).toBeVisible()
    for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
      await setTheme(page, mood, 'violet')
      for (const name of ['Keep my changes', 'Use server version']) {
        const choice = dialog.getByRole('button', { name, exact: true })
        await expectContained(choice)
        await expectReadable(choice)
        await choice.focus()
        await expect(choice).toBeFocused()
      }
      await expectReadable(dialog.getByText(/These settings were updated/))
    }
    await page.screenshot({ path: testInfo.outputPath('preference-conflict.png') })
    await dialog.getByRole('button', { name: 'Use server version' }).click()
    await expect(dialog).toBeHidden()
    await expect(toggle).toHaveAttribute('aria-checked', String(original))
    await expectNoPageOverflow(page)
  } finally {
    await page.unroute('**/api/user/audio-preferences/')
  }
})

for (const width of [390, 768, 1280]) {
  test(`public forms fit at ${width}px with visible validation`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 700 })
    for (const path of ['/register', '/forgot-password', '/reset-password#token=styling-invalid-token']) {
      await page.goto(path)
      await expect(page.locator('form')).toBeVisible()
      for (const field of await page.locator('form input').all()) await expectContained(field)
      for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
        await setTheme(page, mood, 'violet')
        for (const label of await page.locator('form label').all()) await expectReadable(label)
        await expectReadable(page.locator('form button[type="submit"]'))
      }
      await expectNoPageOverflow(page)
    }
    await page.goto('/register')
    await page.getByLabel('Email', { exact: true }).fill('styling@baander.app')
    await page.getByLabel('Password', { exact: true }).fill('a-long-password')
    await page.getByLabel('Confirm password', { exact: true }).fill('a-different-password')
    await page.locator('form button[type="submit"]').click()
    await expect(page.getByText(/passwords do not match/i)).toBeVisible()
    await expect(page.getByLabel('Confirm password', { exact: true })).toHaveAttribute('aria-invalid', 'true')
    await expectReadable(page.getByText(/passwords do not match/i))
    await page.screenshot({ path: testInfo.outputPath(`register-validation-${width}.png`) })
  })

  test(`admin routes retain navigation at ${width}px`, async ({ emptyCatalog: page }) => {
    await page.setViewportSize({ width, height: 900 })
    for (const [path, heading] of [['/admin', /overview|dashboard/i], ['/admin/library', 'Library'], ['/admin/security', 'Security'], ['/admin/settings', 'Settings']] as const) {
      await spaNavigate(page)(path)
      await expect(page.locator('main').getByRole('heading', { name: heading, exact: true, level: 1 })).toBeVisible()
      await expectContained(page.locator('aside').getByRole('link', { name: 'Security', exact: true }))
      await expectNoPageOverflow(page)
    }
  })
}

test('settings switch and select retain keyboard access in a short viewport', async ({ emptyCatalog: page }) => {
  await page.setViewportSize({ width: 390, height: 520 })
  await spaNavigate(page)('/settings')
  const toggle = page.getByRole('switch', { name: 'Volume normalization' })
  const original = await toggle.getAttribute('aria-checked')
  // Keep this interaction local to avoid changing audio preferences on the server.
  await page.route('**/api/user/audio-preferences/', async (route) => route.fulfill({ status: 503, json: { error: { message: 'Fixture unavailable' } } }))
  try {
    if (original === 'true') await toggle.click()
    const select = page.getByRole('combobox').filter({ hasText: /LUFS/ })
    await expect(select).toBeDisabled()
    await toggle.focus()
    await page.keyboard.press('Space')
    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    await expect(select).toBeEnabled()
    await select.focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('listbox')).toBeVisible()
    await expectContained(page.getByRole('listbox'))
    await page.keyboard.press('Escape')
    await expect(select).toBeFocused()
    await expectNoPageOverflow(page)
  } finally {
    if (await toggle.getAttribute('aria-checked') !== original) await toggle.click()
    await page.waitForTimeout(600)
    await page.unroute('**/api/user/audio-preferences/')
  }
})

test('configuration statuses remain readable with long component names', async ({ emptyCatalog: page }) => {
  await page.setViewportSize({ width: 390, height: 800 })
  await page.route('**/api/debug/config-check', async (route) => route.fulfill({ json: { data: {
    summary: { errors: 1, warnings: 1, passed: 1 },
    results: [
      { component: 'env.styling.healthy', status: 'healthy', responseTimeMs: 1, details: { severity: 'ok', message: 'Ready' } },
      { component: 'env.styling.warning.with.a.very.long.component.name', status: 'not_available', responseTimeMs: 1, details: { severity: 'warning', message: 'Needs attention' } },
      { component: 'env.styling.error', status: 'unhealthy', responseTimeMs: 1, details: { severity: 'error', message: 'Unavailable' } },
    ],
  } } }))
  await spaNavigate(page)('/admin/settings?tab=config-health')
  await expect(page.getByRole('heading', { name: 'Configuration', exact: true })).toBeVisible()
  for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
    await setTheme(page, mood, 'violet')
    for (const label of ['OK', 'WARN', 'FAIL', 'Ready', 'Needs attention', 'Unavailable']) {
      await expectReadable(page.getByText(label, { exact: true }))
    }
    await expectNoPageOverflow(page)
  }
})

test('notification popouts fit narrow screens across appearances', async ({ page }, testInfo) => {
  await installCatalogFixtures(page, 'empty')
  await page.setViewportSize({ width: 390, height: 800 })
  await page.route('**/api/user/layout-preferences/', async (route) => route.request().method() === 'PUT'
    ? route.fulfill({ status: 503, json: { error: { message: 'Fixture unavailable' } } })
    : route.continue())
  await page.route('**/api/notifications**', async (route) => {
    if (new URL(route.request().url()).pathname.endsWith('unread-count')) {
      return route.fulfill({ json: { data: { count: 1 } } })
    }
    return route.fulfill({ json: { data: [{
      publicId: 'styling-notification', category: 'admin_operations', eventType: 'admin_operations.completed',
      title: 'A long administration notification title that remains readable in its narrow popout',
      body: null, parameters: null, isRead: false, createdAt: '2026-01-01T00:00:00Z',
    }], nextCursor: null } })
  })
  await authenticate(page)
  await page.keyboard.press('q')
  const appBell = page.getByRole('button', { name: /unread notifications/ })
  await appBell.click()
  await expect(page.getByText('Notifications', { exact: true })).toBeVisible()
  for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
    await setTheme(page, mood, 'violet')
    const bounds = await page.getByText('Notifications', { exact: true }).locator('../..').boundingBox()
    expect(bounds!.x).toBeGreaterThanOrEqual(0)
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(390)
    await expectReadable(page.getByText('A long administration notification title that remains readable in its narrow popout', { exact: true }))
    await expectReadable(page.getByRole('button', { name: 'Mark all read', exact: true }))
    await expectNoPageOverflow(page)
  }
  await page.screenshot({ path: testInfo.outputPath('notification-popout.png') })
  await appBell.click()
  await page.getByRole('button', { name: 'Close context panel' }).click()
  await spaNavigate(page)('/admin')
  await page.getByRole('button', { name: /^Notifications/ }).click()
  await expect(page.getByText('Admin Notifications', { exact: true })).toBeVisible()
  const adminPopout = page.getByText('Admin Notifications', { exact: true }).locator('../..')
  const bounds = await adminPopout.boundingBox()
  expect(bounds!.x).toBeGreaterThanOrEqual(0)
  expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(390)
  await expectReadable(adminPopout.getByText('A long administration notification title that remains readable in its narrow popout', { exact: true }))
})
