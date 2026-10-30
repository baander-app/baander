import type { Locator } from '@playwright/test'
import { test, expect, catalogAlbum } from './fixtures'
import { spaNavigate } from '../fixtures'
import { MOODS, setTheme } from '../helpers/theme-utils'
import { expectNoPageOverflow, expectReadable } from './helpers'

test.use({ serviceWorkers: 'block', launchOptions: { args: ['--mute-audio'] } })

test.beforeEach(async ({ page }) => {
  // Mode and theme sweeps stay local; preference synchronization has its own tests.
  await page.route('**/api/user/*-preferences/', async route => {
    if (route.request().method() !== 'PUT') return route.fallback()
    const { version } = route.request().postDataJSON() as { version: number }
    return route.fulfill({ json: { data: { version: version + 1 } } })
  })
})

function stereoSignal(quietToneHz?: number): Buffer {
  const rate = 48000
  const samples = rate * 120
  const wav = Buffer.alloc(44 + samples * 4)
  wav.write('RIFF', 0)
  wav.writeUInt32LE(wav.length - 8, 4)
  wav.write('WAVEfmt ', 8)
  wav.writeUInt32LE(16, 16)
  wav.writeUInt16LE(1, 20)
  wav.writeUInt16LE(2, 22)
  wav.writeUInt32LE(rate, 24)
  wav.writeUInt32LE(rate * 4, 28)
  wav.writeUInt16LE(4, 32)
  wav.writeUInt16LE(16, 34)
  wav.write('data', 36)
  wav.writeUInt32LE(samples * 4, 40)
  for (let i = 0; i < samples; i++) {
    const phase = 2 * Math.PI * i / rate
    const shared = 0.08 * Math.sin(phase * 60) + 0.05 * Math.sin(phase * 8000)
    const quiet = quietToneHz === undefined ? null : 0.001 * Math.sin(phase * quietToneHz)
    wav.writeInt16LE(Math.round(32767 * (quiet ?? (0.2 * Math.sin(phase * 440) + shared))), 44 + i * 4)
    wav.writeInt16LE(Math.round(32767 * (quiet ?? (0.1 * Math.sin(phase * 660) + shared))), 46 + i * 4)
  }
  return wav
}

/** Count marks in the actual screenshot, including WebGL's composited output. */
async function visibleMarks(canvas: Locator): Promise<number> {
  const png = await canvas.screenshot()
  return canvas.evaluate(async (_element, encoded) => {
    const image = new Image()
    image.src = `data:image/png;base64,${encoded}`
    await image.decode()
    const surface = document.createElement('canvas')
    surface.width = image.width
    surface.height = image.height
    const context = surface.getContext('2d')!
    context.drawImage(image, 0, 0)
    // Exclude labels and edges so text alone cannot pass a blank visualization.
    const pixels = context.getImageData(8, 32, image.width - 16, image.height - 40).data
    const colors = new Map<string, number>()
    for (let i = 0; i < pixels.length; i += 4) {
      const color = `${pixels[i]},${pixels[i + 1]},${pixels[i + 2]}`
      colors.set(color, (colors.get(color) ?? 0) + 1)
    }
    return pixels.length / 4 - Math.max(...colors.values())
  }, png.toString('base64'))
}

test('all Equalizer modes render measured input and clear paused readings', async ({ populatedCatalog: page }, testInfo) => {
  test.setTimeout(180_000)
  await page.setViewportSize({ width: 1600, height: 1000 })
  const errors: string[] = []
  page.on('pageerror', error => errors.push(error.message))
  const signal = stereoSignal()
  await page.route('**/api/stream/track?*', route => route.fulfill({ contentType: 'audio/wav', body: signal }))
  await spaNavigate(page)(`/music/albums/${catalogAlbum.publicId}`)
  await page.getByRole('button', { name: 'Play', exact: true }).first().click()
  await expect(page.getByRole('button', { name: 'Pause', exact: true })).toBeVisible()
  await spaNavigate(page)('/equalizer')
  const analyzer = page.getByRole('region', { name: 'Input analyzer' })
  const select = async (mode: string) => {
    await analyzer.getByRole('button', { name: mode, exact: true }).click()
  }
  for (const mood of MOODS) {
    await setTheme(page, mood, 'white')
    for (const mode of ['Spectrum', 'Meters', 'Phase', 'Enhanced', 'Circular', 'Spectrogram', 'Particles']) {
      await select(mode)
      if (mode === 'Spectrum') {
        const spectrum = analyzer.getByRole('img', { name: 'Input spectrum with configured EQ response' })
        const bars = spectrum.locator(':scope > div')
        await expect.poll(() => bars.evaluateAll(elements => Math.max(...elements.map(el => el.getBoundingClientRect().height)))).toBeGreaterThan(50)
        await expect(spectrum.locator('svg path')).toHaveAttribute('d', /^M .+ C /)
        const overlay = await spectrum.locator('svg').boundingBox()
        const area = await spectrum.boundingBox()
        expect(overlay?.width).toBeCloseTo(area!.width, 0)
        expect(overlay?.height).toBeCloseTo(area!.height, 0)
      } else if (mode === 'Meters') {
        // sqrt(sum(amplitude²)/2): left −16.12 dBFS, right −20.25 dBFS.
        for (const [channel, expected] of [['Left', -16.12], ['Right', -20.25]] as const) {
          const meter = analyzer.getByRole('meter', { name: `${channel} input RMS` })
          await expect.poll(async () => Math.abs(Number(await meter.getAttribute('aria-valuenow')) - expected)).toBeLessThan(0.6)
        }
        await expect(analyzer.getByText('445 Hz', { exact: true })).toBeVisible()
      } else if (mode === 'Phase') {
        await expect(analyzer.getByLabel('Captured stereo samples')).toHaveAttribute('points', /\S+ \S+/)
      } else {
        if (mode === 'Particles') await expect(analyzer.locator('canvas[data-engine]')).toBeVisible()
        const canvas = analyzer.locator('canvas:visible')
        await expect(canvas).toHaveCount(1)
        await expect(canvas).toHaveCSS('opacity', '1')
        await expect.poll(() => visibleMarks(canvas)).toBeGreaterThan(100)
        await expect(analyzer.getByRole('status')).toHaveCount(0)
      }
      await expectReadable(analyzer.getByRole('button', { name: mode, exact: true }))
      await analyzer.screenshot({ path: testInfo.outputPath(`${mood}-${mode}.png`) })
    }
  }
  for (const width of [390, 1200]) {
    await page.setViewportSize({ width, height: 900 })
    for (const mode of ['Particles', 'Enhanced', 'Spectrogram', 'Circular']) await select(mode)
    await expect(analyzer.locator('canvas:visible')).toHaveCount(1)
    await expectNoPageOverflow(page)
  }
  await select('Meters')
  await page.getByRole('button', { name: 'Pause', exact: true }).click()
  await expect(analyzer.getByRole('meter', { name: 'Left input RMS' })).toHaveAttribute('aria-valuetext', '−∞ dBFS RMS')
  await expect(analyzer.getByText('Unavailable', { exact: true })).toBeVisible()
  await select('Phase')
  await expect(analyzer.getByLabel('Captured stereo samples')).toHaveCount(0)
  await select('Enhanced')
  await expect.poll(() => visibleMarks(analyzer.locator('canvas:visible'))).toBe(0)
  await page.getByRole('button', { name: 'Play', exact: true }).click()
  await expect.poll(() => visibleMarks(analyzer.locator('canvas:visible'))).toBeGreaterThan(100)
  expect(errors).toEqual([])
})

test('a quiet single tone remains visible between the old sampled bins', async ({ populatedCatalog: page }, testInfo) => {
  test.setTimeout(60_000)
  await page.setViewportSize({ width: 1200, height: 900 })
  const signal = stereoSignal(1008)
  await page.route('**/api/stream/track?*', route => route.fulfill({ contentType: 'audio/wav', body: signal }))
  await spaNavigate(page)(`/music/albums/${catalogAlbum.publicId}`)
  await page.getByRole('button', { name: 'Play', exact: true }).first().click()
  await expect(page.getByRole('button', { name: 'Pause', exact: true })).toBeVisible()
  await spaNavigate(page)('/equalizer')
  const analyzer = page.getByRole('region', { name: 'Input analyzer' })
  await analyzer.getByRole('button', { name: 'Meters', exact: true }).click()
  await expect.poll(async () => {
    const reading = await analyzer.getByRole('meter', { name: 'Left input RMS' }).getAttribute('aria-valuetext')
    return Number(reading?.split(' ')[0])
  }).toBeCloseTo(-63, 0)
  for (const mode of ['Enhanced', 'Circular', 'Spectrogram', 'Particles']) {
    await analyzer.getByRole('button', { name: mode, exact: true }).click()
    if (mode === 'Particles') await expect(analyzer.locator('canvas[data-engine]')).toBeVisible()
    // At this level the radial peak is about 14 CSS pixels long and 2.3 pixels wide.
    const minimumMarks = mode === 'Circular' ? 30 : 100
    await expect.poll(() => visibleMarks(analyzer.locator('canvas:visible'))).toBeGreaterThan(minimumMarks)
    await analyzer.screenshot({ path: testInfo.outputPath(`quiet-${mode}.png`) })
  }
})
