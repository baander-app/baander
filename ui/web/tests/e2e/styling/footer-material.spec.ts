import type { Locator } from '@playwright/test'
import { test, expect, catalogAlbum } from './fixtures'
import { spaNavigate } from '../fixtures'
import { ACCENTS, MOODS, setTheme } from '../helpers/theme-utils'
import { expectContained, expectNoPageOverflow } from './helpers'

test.use({ serviceWorkers: 'block' })

/** Sample the painted vertical surface in its empty right padding, alongside each label/control. */
async function paintedContrast(display: Locator) {
  const screenshot = await display.screenshot({ scale: 'css' })
  return display.evaluate(async (element, encoded) => {
    const image = new Image()
    image.src = `data:image/png;base64,${encoded}`
    await image.decode()
    const canvas = document.createElement('canvas')
    canvas.width = image.width
    canvas.height = image.height
    const context = canvas.getContext('2d')!
    context.drawImage(image, 0, 0)
    const pixels = context.getImageData(0, 0, image.width, image.height).data
    const bounds = element.getBoundingClientRect()

    const luminance = (rgb: number[]) => rgb.map(channel => {
      const value = channel / 255
      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
    }).reduce((total, value, index) => total + value * [0.2126, 0.7152, 0.0722][index], 0)

    const labels = [...element.querySelectorAll('p, span')].filter(node =>
      [...node.childNodes].some(child => child.nodeType === Node.TEXT_NODE && child.textContent?.trim()),
    )
    const controls = [...element.querySelectorAll('[data-slot="slider-track"], [data-slot="slider-range"], [role="slider"]')]

    return [...labels, ...controls].map(node => {
      const isText = labels.includes(node)
      const style = getComputedStyle(node)
      const rect = node.getBoundingClientRect()
      const paint = isText ? style.color : style.backgroundColor
      context.clearRect(0, 0, 1, 1)
      context.fillStyle = paint
      context.fillRect(0, 0, 1, 1)
      const rgba = [...context.getImageData(0, 0, 1, 1).data]
      let opacity = rgba[3] / 255
      for (let ancestor: Element | null = node; ancestor; ancestor = ancestor.parentElement) {
        opacity *= Number(getComputedStyle(ancestor).opacity)
        if (ancestor === element) break
      }
      const ratios: number[] = []
      const x = image.width - 8
      for (let y = Math.max(8, Math.floor(rect.top - bounds.top));
        y < Math.min(image.height - 8, Math.ceil(rect.bottom - bounds.top)); y++) {
        const offset = (y * image.width + x) * 4
        const background = [...pixels.slice(offset, offset + 3)]
        const foreground = background.map((channel, index) => rgba[index] * opacity + channel * (1 - opacity))
        const a = luminance(foreground)
        const b = luminance(background)
        ratios.push((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05))
      }
      return {
        label: isText ? node.textContent : node.getAttribute('data-slot'),
        minimum: isText ? 4.5 : 3,
        contrast: ratios.length ? Math.min(...ratios) : 0,
      }
    })
  }, screenshot.toString('base64'))
}

test('painted centerpiece stays readable across every mood and accent', async ({ populatedCatalog: page }) => {
  test.setTimeout(120_000)
  await page.setViewportSize({ width: 1280, height: 800 })
  await spaNavigate(page)(`/music/albums/${catalogAlbum.publicId}`)
  await page.getByRole('button', { name: 'Play', exact: true }).first().click()
  const footer = page.getByRole('region', { name: 'Playback', exact: true })
  const display = footer.getByRole('group', { name: 'Now playing', exact: true })
  const seek = display.getByRole('slider', { name: 'Seek', exact: true })
  await expect(seek).toHaveAttribute('aria-valuemax', '30')
  await footer.getByRole('button', { name: 'Pause', exact: true }).click()

  for (const mood of MOODS) {
    for (const accent of ACCENTS) {
      await setTheme(page, mood, accent)
      const readings = await paintedContrast(display)
      expect(readings.length).toBeGreaterThanOrEqual(6)
      for (const reading of readings) {
        expect(reading.contrast, `${mood}/${accent}: ${reading.label}`).toBeGreaterThanOrEqual(reading.minimum)
      }
      if (mood === 'dark') {
        await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(0, 0, 0)')
        const colors = await display.evaluate(node => [
          getComputedStyle(node).backgroundColor,
          getComputedStyle(node.closest('section')!).backgroundColor,
          getComputedStyle(document.body).backgroundColor,
        ])
        expect(new Set(colors).size).toBe(3)
      }
      if (accent === 'violet') {
        await footer.screenshot({ path: test.info().outputPath(`material-${mood}.png`) })
      }
    }
  }
})

for (const width of [390, 768, 1280]) {
  test(`recessed surface preserves contained controls and focus at ${width}px`, async ({ populatedCatalog: page }) => {
    await page.setViewportSize({ width, height: 800 })
    await spaNavigate(page)(`/music/albums/${catalogAlbum.publicId}`)
    await page.getByRole('button', { name: 'Play', exact: true }).first().click()
    const footer = page.getByRole('region', { name: 'Playback', exact: true })
    const display = footer.getByRole('group', { name: 'Now playing', exact: true })
    const seek = display.getByRole('slider', { name: 'Seek', exact: true })
    await expect(seek).toHaveAttribute('aria-valuemax', '30')
    await footer.getByRole('button', { name: 'Pause', exact: true }).click()

    const geometry = await footer.evaluate(node => {
      const box = (element: Element) => {
        const rect = element.getBoundingClientRect()
        return { top: rect.top, bottom: rect.bottom, height: rect.height, center: (rect.top + rect.bottom) / 2 }
      }
      const display = node.querySelector('[aria-label="Now playing"]')!
      const groups = [...node.lastElementChild!.children].map(box)
      const top = Math.min(...groups.map(group => group.top))
      const bottom = Math.max(...groups.map(group => group.bottom))
      return {
        footer: box(node),
        contentCenter: (top + bottom) / 2,
        groups,
        display: box(display),
        displayContents: [...display.children].map(box),
      }
    })
    expect(geometry.footer.height).toBeLessThanOrEqual(width < 768 ? 146 : 98)
    expect(Math.abs(geometry.contentCenter - geometry.footer.center)).toBeLessThanOrEqual(1)
    if (width >= 768) {
      for (const group of geometry.groups) {
        expect(Math.abs(group.center - geometry.footer.center)).toBeLessThanOrEqual(1)
      }
    }
    for (const content of geometry.displayContents) {
      expect(Math.abs(content.center - geometry.display.center)).toBeLessThanOrEqual(1)
    }

    for (const mood of ['dark', 'balanced']) {
      await setTheme(page, mood, 'violet')
      await expectNoPageOverflow(page)
      await expectContained(display.locator('p').first())
      await expectContained(seek)
      const volume = footer.getByRole('slider', { name: 'Volume', exact: true })
      await expectContained(volume)
      await footer.screenshot({ path: test.info().outputPath(`material-${width}-${mood}-rest.png`) })
      if (width >= 768) {
        const screenshot = await footer.screenshot({ scale: 'css' })
        const paintedGaps = await footer.evaluate(async (_node, encoded) => {
          const image = new Image()
          image.src = `data:image/png;base64,${encoded}`
          await image.decode()
          const canvas = document.createElement('canvas')
          canvas.width = image.width
          canvas.height = image.height
          const context = canvas.getContext('2d')!
          context.drawImage(image, 0, 0)
          const background = context.getImageData(1, Math.floor(image.height / 2), 1, 1).data
          const center = context.getImageData(Math.floor(image.width / 2), 0, 1, image.height).data
          const rows = []
          // Ignore the footer border; include the centerpiece's painted lower lip.
          for (let y = 1; y < image.height; y++) {
            if ([0, 1, 2].some(channel => center[y * 4 + channel] !== background[channel])) rows.push(y)
          }
          return { top: rows[0], bottom: image.height - 1 - rows[rows.length - 1] }
        }, screenshot.toString('base64'))
        expect(Math.abs(paintedGaps.top - paintedGaps.bottom)).toBeLessThanOrEqual(1)
      }
      await footer.getByRole('button', { name: 'Next', exact: true }).focus()
      await page.keyboard.press('Tab')
      await page.keyboard.press('ArrowRight')
      await expect(seek).toBeFocused()
      await expect(seek).toHaveCSS('outline-style', 'solid')
      await expect(seek).toHaveCSS('outline-width', '2px')
      const focusFits = await seek.evaluate(node => {
        const control = node.getBoundingClientRect()
        const surface = node.closest('[aria-label="Now playing"]')!.getBoundingClientRect()
        const style = getComputedStyle(node)
        const extent = parseFloat(style.outlineWidth) + parseFloat(style.outlineOffset)
        return control.left - extent >= surface.left && control.right + extent <= surface.right
          && control.top - extent >= surface.top && control.bottom + extent <= surface.bottom
      })
      expect(focusFits).toBe(true)
      await footer.screenshot({ path: test.info().outputPath(`material-${width}-${mood}-focus.png`) })
      await seek.blur()
    }
  })
}
