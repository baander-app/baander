import { test, expect, spaNavigate, authenticate } from '../fixtures'
import { main } from '../selectors'

test.describe('Settings page', () => {
  test.beforeEach(async ({ asAdmin: page }) => {
    await spaNavigate(page)('/settings')
    await expect(main(page).getByRole('heading', { name: 'Settings', exact: true, level: 1 })).toBeVisible()
  })

  test('shows audio, appearance, account, security, devices and about sections', async ({ asAdmin: page }) => {
    for (const name of ['Audio', 'Appearance', 'Account', 'Security', 'Devices', 'About']) {
      await expect(main(page).getByRole('heading', { name, exact: true })).toBeVisible()
    }
  })

  test('volume normalization toggles its checked state', async ({ asAdmin: page }) => {
    const toggle = main(page).getByRole('switch', { name: 'Volume normalization' })
    const initial = await toggle.getAttribute('aria-checked')
    expect(['true', 'false']).toContain(initial)
    try {
      await toggle.click()
      await expect(toggle).toHaveAttribute('aria-checked', initial === 'true' ? 'false' : 'true')
    } finally {
      if (await toggle.getAttribute('aria-checked') !== initial) await toggle.click()
    }
  })

  test('LUFS target uses the current select control', async ({ asAdmin: page }) => {
    const toggle = main(page).getByRole('switch', { name: 'Volume normalization' })
    const enabled = await toggle.getAttribute('aria-checked') === 'true'
    if (!enabled) await toggle.click()
    const select = main(page).getByRole('combobox', { name: 'Loudness target' })
    const original = await select.innerText()
    const custom = main(page).getByRole('textbox', { name: 'Custom target (LUFS)' })
    const originalCustom = await custom.isVisible() ? await custom.inputValue() : null
    try {
      await select.click()
      await page.getByRole('option', { name: 'Balanced (−16 LUFS)', exact: true }).click()
      await expect(select).toHaveText('Balanced (−16 LUFS)')
    } finally {
      await select.click()
      await page.getByRole('option', { name: original, exact: true }).click()
      if (originalCustom !== null) {
        await custom.fill(originalCustom)
        await custom.press('Enter')
      }
      if (!enabled) await toggle.click()
    }
  })

  test('open EQ reaches the equalizer', async ({ asAdmin: page }) => {
    await main(page).getByRole('link', { name: 'Open EQ', exact: true }).click()
    await expect(page).toHaveURL(/\/equalizer$/)
    await expect(main(page).getByRole('button', { name: 'Toggle audio system info', exact: true })).toBeVisible()
  })

  test('passkey management controls are available', async ({ asAdmin: page }) => {
    await expect(main(page).getByText('Passkeys', { exact: true })).toBeVisible()
    await expect(main(page).getByRole('button', { name: 'Add', exact: true })).toBeVisible()
  })
})

test('appearance choices persist through navigation and reload', async ({ page }) => {
  const reads = Promise.all(['theme-mood', 'accent-color'].map((endpoint) =>
    page.waitForResponse((response) => response.url().includes(`/api/user/${endpoint}/`) && response.request().method() === 'GET')))
  await authenticate(page)
  await reads
  await spaNavigate(page)('/settings')
  const original = await page.locator('html').evaluate((root) => ({
    mood: root.getAttribute('data-theme')!, accent: root.getAttribute('data-accent')!,
  }))
  const choose = async (kind: 'theme' | 'accent', value: string) => {
    const endpoint = kind === 'theme' ? 'theme-mood' : 'accent-color'
    const response = page.waitForResponse((result) => result.url().includes(`/api/user/${endpoint}/`) && result.request().method() === 'PUT')
    const label = kind === 'theme'
      ? `Select ${value[0].toUpperCase()}${value.slice(1)} theme`
      : `Select ${value} accent color`
    await page.getByRole('button', { name: label, exact: true }).click()
    expect((await response).ok()).toBe(true)
    await expect(page.getByRole('button', { name: label, exact: true })).toHaveAttribute('aria-pressed', 'true')
  }
  try {
    for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
      await choose('theme', mood)
      await expect(page.locator('html')).toHaveAttribute('data-theme', mood)
    }
    for (const accent of ['white', 'violet', 'amber']) {
      await choose('accent', accent)
      await expect(page.locator('html')).toHaveAttribute('data-accent', accent)
    }
    await spaNavigate(page)('/music')
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'balanced')
    await expect(page.locator('html')).toHaveAttribute('data-accent', 'amber')
    await page.reload()
    await expect(page.locator('main').getByRole('heading', { name: 'Home', exact: true, level: 1 })).toBeVisible()
    await spaNavigate(page)('/settings')
    await expect(page.getByRole('button', { name: 'Select Balanced theme' })).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByRole('button', { name: 'Select amber accent color' })).toHaveAttribute('aria-pressed', 'true')
  } finally {
    await spaNavigate(page)('/settings')
    await choose('theme', original.mood)
    await choose('accent', original.accent)
  }
})

test('failed appearance save retains local selection', async ({ asAdmin: page }) => {
  await spaNavigate(page)('/settings')
  const original = await page.locator('html').getAttribute('data-theme')
  const target = original === 'warm' ? 'cool' : 'warm'
  await page.route('**/api/user/theme-mood/', async (route) => route.request().method() === 'PUT'
    ? route.fulfill({ status: 503, json: { error: { message: 'Fixture unavailable' } } })
    : route.continue())
  try {
    await page.getByRole('button', { name: `Select ${target[0].toUpperCase()}${target.slice(1)} theme` }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', target)
    await spaNavigate(page)('/music')
    await expect(page.locator('html')).toHaveAttribute('data-theme', target)
  } finally {
    await spaNavigate(page)('/settings')
    await page.getByRole('button', { name: `Select ${original![0].toUpperCase()}${original!.slice(1)} theme` }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', original!)
    await page.unroute('**/api/user/theme-mood/')
  }
})
