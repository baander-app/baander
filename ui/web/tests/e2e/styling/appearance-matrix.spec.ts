import { test, expect } from '@playwright/test'
import { authenticate, spaNavigate } from '../fixtures'
import { ACCENTS, MOODS, setTheme } from '../helpers/theme-utils'
import { expectContained, expectNoPageOverflow, expectReadable } from './helpers'

for (const mood of MOODS) {
  for (const accent of ACCENTS) {
    test(`${mood}/${accent}: login, settings and form overlay`, async ({ page }, testInfo) => {
      await page.setViewportSize({ width: 1280, height: 900 })
      await page.goto('/login')
      const login = page.getByRole('button', { name: /log in/i })
      await expect(login).toBeVisible()
      await setTheme(page, mood, accent)
      await expectContained(page.getByRole('textbox', { name: /email/i }))
      await expectReadable(login)
      if (accent === 'violet') {
        await testInfo.attach('login', { body: await page.screenshot({ path: testInfo.outputPath('login.png') }), contentType: 'image/png' })
      }

      await authenticate(page)
      await spaNavigate(page)('/settings')
      await expect(page.getByRole('heading', { name: 'Settings', exact: true })).toBeVisible()
      await setTheme(page, mood, accent)
      for (const button of await page.getByRole('button', { name: /^Select .* theme$/ }).all()) {
        await expectReadable(button)
      }
      await expect(page.getByRole('button', { name: /^Select .* theme$/ })).toHaveCount(4)
      const changeEmail = page.getByRole('button', { name: 'Change', exact: true }).first()
      await expectReadable(changeEmail)
      await expectNoPageOverflow(page)
      if (accent === 'violet') {
        await page.getByRole('heading', { name: 'Appearance', exact: true }).scrollIntoViewIfNeeded()
        await testInfo.attach('settings', { body: await page.screenshot({ path: testInfo.outputPath('settings.png') }), contentType: 'image/png' })
      }
      await changeEmail.click()
      const dialog = page.getByRole('dialog', { name: 'Change email', exact: true })
      await expect(dialog).toBeVisible()
      await expectContained(dialog.getByRole('textbox', { name: 'New email', exact: true }))
      await expectReadable(dialog.getByRole('button', { name: 'Save', exact: true }))
      await expectReadable(dialog.getByRole('button', { name: 'Cancel', exact: true }))
      if (accent === 'violet') {
        await testInfo.attach('form-overlay', { body: await page.screenshot({ path: testInfo.outputPath('form-overlay.png') }), contentType: 'image/png' })
      }
      await page.keyboard.press('Escape')
      await expect(dialog).toBeHidden()
      await expect(changeEmail).toBeFocused()
      await expect(page.locator('html')).toHaveAttribute('data-theme', mood)
      await expect(page.locator('html')).toHaveAttribute('data-accent', accent)
    })
  }
}

test('reduced motion retains focused controls across live resize', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await authenticate(page)
  await spaNavigate(page)('/settings')
  await page.getByRole('button', { name: 'Change', exact: true }).first().click()
  const dialog = page.getByRole('dialog', { name: 'Change email', exact: true })
  const field = dialog.getByRole('textbox', { name: 'New email', exact: true })
  await field.focus()
  for (const width of [1280, 390, 1300, 768]) {
    await page.setViewportSize({ width, height: 600 })
    await expectContained(field)
    await expect(field).toBeFocused()
    const action = dialog.getByRole('button', { name: 'Cancel', exact: true })
    await action.scrollIntoViewIfNeeded()
    await expect(action).toBeInViewport()
    await expectNoPageOverflow(page)
  }
  const duration = await field.evaluate((element) => getComputedStyle(element).transitionDuration)
  expect(duration.split(',').every(value => parseFloat(value) <= 0.00001)).toBe(true)
})
