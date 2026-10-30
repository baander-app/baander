import { test, expect, spaNavigate } from '../fixtures'
import { setTheme } from '../helpers/theme-utils'
import { expectContained, expectNoPageOverflow, expectReadable } from './helpers'

for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
  test(`admin library tabs remain readable before and after selection in ${mood}`, async ({ asAdmin: page }) => {
    await spaNavigate(page)('/admin/library')
    await setTheme(page, mood, 'violet')
    const tabs = page.getByRole('tablist')
    await expect(tabs.getByRole('tab')).toHaveCount(5)
    for (const tab of await tabs.getByRole('tab').all()) await expectReadable(tab)
    const libraries = tabs.getByRole('tab', { name: 'Libraries', exact: true })
    await libraries.focus()
    await page.keyboard.press('ArrowRight')
    await expect(tabs.getByRole('tab', { name: 'Metadata', exact: true })).toHaveAttribute('aria-selected', 'true')
    await expect(libraries).toHaveAttribute('aria-selected', 'false')
    for (const tab of await tabs.getByRole('tab').all()) await expectReadable(tab)
    await page.screenshot({ path: test.info().outputPath(`library-tabs-${mood}.png`) })
  })
}

test('contrast checks account for text and ancestor opacity', async ({ page }) => {
  await page.setContent('<div id="surface" style="background:white"><span id="text" style="color:black">Readable text</span></div>')
  const text = page.locator('#text')
  await expectReadable(text)
  await text.evaluate(element => { element.style.opacity = '0.1' })
  await expect(expectReadable(text)).rejects.toThrow()
  await text.evaluate(element => { element.style.opacity = '1' })
  await page.locator('#surface').evaluate(element => { element.style.opacity = '0.1' })
  await expect(expectReadable(text)).rejects.toThrow()
})

for (const width of [390, 768, 1280]) {
  test(`login fields fit and expose keyboard focus at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 600 })
    await page.goto('/login')
    const email = page.getByRole('textbox', { name: /email/i })
    const password = page.getByLabel(/^password$/i)
    await expectContained(email)
    await expectContained(password)
    await expectNoPageOverflow(page)
    await email.focus()
    await expect(email).toBeFocused()
    await expect(email).toHaveCSS('height', '32px')
    const focus = await email.evaluate((element) => {
      const style = getComputedStyle(element)
      return style.outlineStyle !== 'none' || style.boxShadow !== 'none'
    })
    expect(focus).toBe(true)
    await page.keyboard.press('Tab')
    await expect(password).toBeFocused()
  })
}

test('login action remains readable at rest, hover and press in every mood', async ({ page }) => {
  await page.goto('/login')
  const action = page.getByRole('button', { name: /log in/i })
  await expect(action).toBeVisible()
  for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
    await setTheme(page, mood, 'violet')
    await expectReadable(action)
    await action.hover()
    await page.waitForTimeout(150)
    await expectReadable(action)
    await page.mouse.down()
    await page.waitForTimeout(150)
    await expectReadable(action)
    await page.mouse.move(0, 0)
    await page.mouse.up()
  }
})

test('selected context tabs show an immediate visible indicator', async ({ asAdmin: page }) => {
  const panel = page.locator('aside').last()
  const queue = panel.getByRole('tab', { name: 'Queue', exact: true })
  const lyrics = panel.getByRole('tab', { name: 'Lyrics', exact: true })
  const initiallySelected = await panel.getByRole('tab', { selected: true }).textContent()
  await queue.click()
  await expect(queue).toHaveAttribute('aria-selected', 'true')
  expect(await queue.evaluate(element => getComputedStyle(element, '::after').opacity)).toBe('1')
  await lyrics.click()
  await expect(lyrics).toHaveAttribute('aria-selected', 'true')
  expect(await lyrics.evaluate(element => getComputedStyle(element, '::after').opacity)).toBe('1')
  expect(await queue.evaluate(element => getComputedStyle(element, '::after').opacity)).toBe('0')
  await expectReadable(lyrics)
  await panel.getByRole('tab', { name: initiallySelected!, exact: true }).click()
})

test('narrow shell controls explain their actions and restore focus', async ({ asAdmin: page }) => {
  const close = page.getByRole('button', { name: 'Close context panel', exact: true })
  await expect(close).toBeVisible()
  await page.setViewportSize({ width: 390, height: 600 })
  await expect(close).toBeHidden()
  const navigation = page.getByRole('button', { name: 'Expand navigation', exact: true })
  await navigation.hover()
  await expect(page.getByRole('tooltip', { name: 'Expand navigation', exact: true })).toBeVisible()
  await navigation.focus()
  await page.keyboard.press('q')
  await expect(close).toBeFocused()
  await close.hover()
  await expect(page.getByRole('tooltip', { name: 'Close context panel', exact: true })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(close).toBeHidden()
  await expect(navigation).toBeFocused()
})

for (const width of [390, 768, 1280]) {
  test(`library form fits a short viewport at ${width}px`, async ({ asAdmin: page }) => {
    await page.setViewportSize({ width, height: 600 })
    await spaNavigate(page)('/admin/library')
    await page.getByRole('button', { name: 'Add Library', exact: true }).click()
    const dialog = page.getByRole('dialog', { name: 'Add Library', exact: true })
    await expect(dialog.getByRole('textbox')).toHaveCount(3)
    await expect(dialog.getByRole('button', { name: 'Create', exact: true })).toBeDisabled()
    for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
      await setTheme(page, mood, 'violet')
      for (const field of await dialog.getByRole('textbox').all()) {
        await expectContained(field)
        await field.focus()
        await expect(field).toBeFocused()
      }
      await expectReadable(dialog.getByRole('button', { name: 'Cancel', exact: true }))
      await expectNoPageOverflow(page)
    }
    const cancel = dialog.getByRole('button', { name: 'Cancel', exact: true })
    await cancel.scrollIntoViewIfNeeded()
    await expect(cancel).toBeInViewport()
    await cancel.click()
    await expect(dialog).toBeHidden()
  })
}

test.describe('mocked catalog search', () => {
  // Block the stream worker here so WebKit can intercept the catalog fixtures.
  test.use({ serviceWorkers: 'block' })

  for (const width of [390, 768, 1280]) {
    test(`command palette fits and distinguishes keyboard selection at ${width}px`, async ({ asAdmin: page }) => {
      await page.context().route(/\/api\/(albums|songs)\/?\?/, route => route.fulfill({ json: { data: [] } }))
      await page.context().route(/\/api\/artists\/?\?/, route => route.fulfill({ json: { data: [
        { publicId: 'styling-artist-one', name: 'Styling Ensemble' },
        { publicId: 'styling-artist-two', name: 'Styling Orchestra' },
      ] } }))
      await page.setViewportSize({ width, height: 600 })
      await page.keyboard.press('Control+k')
      const dialog = page.getByRole('dialog', { name: 'Command Palette' })
      const input = dialog.getByRole('combobox')
      await expect(input).toBeFocused()
      await input.fill('Styling')
      const items = dialog.getByRole('option')
      await expect(items).toHaveCount(2)
      for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
        await setTheme(page, mood, 'violet')
        await expectContained(dialog)
        await expectContained(input)
        await expectReadable(input)
        await expect(input.locator('..')).toHaveCSS('outline-style', 'solid')
        await page.keyboard.press('ArrowDown')
        const selected = dialog.getByRole('option', { selected: true })
        await expect(selected).toHaveCount(1)
        await expect(selected).toHaveCSS('transition-duration', '0s')
        await expectReadable(selected)
        const backgrounds = await items.evaluateAll(elements => elements.map(element => getComputedStyle(element).backgroundColor))
        expect(new Set(backgrounds).size).toBe(2)
      }
      await page.keyboard.press('Escape')
      await expect(dialog).toBeHidden()
    })
  }
})
