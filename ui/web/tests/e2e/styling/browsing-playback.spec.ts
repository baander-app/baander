import { spaNavigate } from '../fixtures'
import { test, expect, catalogAlbum, catalogSong, catalogPlaylist } from './fixtures'
import { setTheme } from '../helpers/theme-utils'
import { expectContained, expectNoPageOverflow, expectReadable } from './helpers'

test.describe('footer radio player', () => {
  test.use({ serviceWorkers: 'block' })

  test('hides the context player until radio stops', async ({ populatedCatalog: page }) => {
    await page.setViewportSize({ width: 1280, height: 800 })
    const stream = new URL(`/api/stream/track?id=${catalogSong.publicId}`, page.url()).href
    const station = {
      publicId: 'stylingRadio000000001', name: 'Styling Radio', country: 'DK',
      language: 'Danish', genres: [], tags: [], logo: null,
      streams: [{ url: stream, format: 'wav', bitrate: 128, reliability: 1 }],
    }
    await page.route('**/api/radio/**', async (route) => {
      const path = new URL(route.request().url()).pathname
      if (path === '/api/radio/stations') return route.fulfill({ json: { data: [station] } })
      if (path.startsWith('/api/radio/session')) return route.fulfill({ json: { data: null } })
      return route.fulfill({ json: { data: [] } })
    })
    await spaNavigate(page)('/radio')
    await page.getByRole('tab', { name: 'Stations', exact: true }).click()
    await page.getByPlaceholder('Search stations...').fill(station.name)
    await expect(page.getByText('Nothing playing', { exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'Play station', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Stop radio', exact: true })).toBeVisible()
    await expect(page.getByText('Nothing playing', { exact: true })).toHaveCount(0)
    await expect(page.getByRole('tab', { name: 'Queue', exact: true })).toBeVisible()
    await page.screenshot({ path: test.info().outputPath('radio-player-placement.png') })
    await page.getByRole('button', { name: 'Stop radio', exact: true }).click()
    await expect(page.getByText('Nothing playing', { exact: true })).toBeVisible()
  })
})

test.describe('footer volume handle', () => {
  test.use({ serviceWorkers: 'block' })

  for (const width of [390, 1280]) {
    test(`remains visible and draggable across themes at ${width}px`, async ({ populatedCatalog: page }) => {
      await page.setViewportSize({ width, height: 800 })
      await spaNavigate(page)(`/music/albums/${catalogAlbum.publicId}`)
      await page.getByRole('button', { name: 'Play', exact: true }).first().click()
      const volume = page.getByRole('slider', { name: 'Volume', exact: true }).first()
      await expect(volume).toBeVisible()
      await page.screenshot({ path: test.info().outputPath('volume-initial.png') })
      const handle = await volume.boundingBox()
      expect(Math.round(handle!.height)).toBeGreaterThanOrEqual(12)
      expect(Math.round(handle!.width)).toBeGreaterThanOrEqual(12)

      for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
        await setTheme(page, mood, 'violet')
        await volume.focus()
        await page.keyboard.press('Home')
        await expect(volume).toHaveAttribute('aria-valuenow', '0')
        const start = (await volume.boundingBox())!
        await page.keyboard.press('End')
        await expect(volume).toHaveAttribute('aria-valuenow', '100')
        const end = (await volume.boundingBox())!
        expect(end.x - start.x).toBeGreaterThan(40)
        await page.mouse.move(end.x + end.width / 2, end.y + end.height / 2)
        await page.mouse.down()
        await page.mouse.move((start.x + end.x + end.width) / 2, end.y + end.height / 2, { steps: 5 })
        await page.mouse.up()
        const value = Number(await volume.getAttribute('aria-valuenow'))
        expect(value).toBeGreaterThan(35)
        expect(value).toBeLessThan(65)
        await expectNoPageOverflow(page)
        await page.screenshot({ path: test.info().outputPath(`volume-${mood}.png`) })
      }
      const mute = page.getByRole('button', { name: 'Mute', exact: true }).first()
      await mute.click()
      await expect(volume).toHaveAttribute('aria-valuenow', '0')
      await volume.focus()
      await page.keyboard.press('ArrowRight')
      await expect(volume).toHaveAttribute('aria-valuenow', '1')
      await expect(mute).toBeVisible()
    })
  }
})

for (const width of [390, 768, 1280]) {
  test(`long catalog titles and sidebar controls fit at ${width}px`, async ({ populatedCatalog: page }) => {
    await page.setViewportSize({ width, height: 800 })
    await spaNavigate(page)(`/music/albums/${catalogAlbum.publicId}`)
    await expect(page).toHaveURL(`/music/albums/${catalogAlbum.publicId}`)
    const title = page.getByText(catalogAlbum.title, { exact: true }).first()
    const song = page.getByText(catalogSong.title, { exact: true }).first()
    await expect(title).toBeVisible()
    await expect(song).toBeVisible()
    await expectContained(title)
    await expectContained(page.getByRole('button', { name: 'Play', exact: true }).first())
    await expectNoPageOverflow(page)
    await page.screenshot({ path: test.info().outputPath(`populated-${width}.png`) })
  })
}

for (const width of [390, 768, 1280]) {
  test(`populated track menus and active playback remain readable at ${width}px`, async ({ populatedCatalog: page }) => {
    await page.setViewportSize({ width, height: 800 })
    await spaNavigate(page)(`/music/albums/${catalogAlbum.publicId}`)
    const song = page.getByText(catalogSong.title, { exact: true }).first()
    await expect(song).toBeVisible()
    await song.click({ button: 'right' })
    const menu = page.getByRole('menu')
    await expect(menu).toBeVisible()
    await menu.getByRole('menuitem').first().focus()
    await expectReadable(menu.getByRole('menuitem').first())
    await page.keyboard.press('Escape')

    await page.getByRole('button', { name: 'Play', exact: true }).first().click()
    const pause = page.getByRole('button', { name: 'Pause', exact: true }).first()
    await expect(pause).toBeVisible()
    await page.screenshot({ path: test.info().outputPath('player-placement.png') })
    await expect(page.getByRole('button', { name: 'Pause', exact: true })).toHaveCount(1)
    await expect(page.getByRole('slider', { name: 'Seek', exact: true })).toHaveCount(1)
    await expect(page.getByRole('tab', { name: 'Queue', exact: true })).toBeVisible()
    const seek = page.getByRole('slider', { name: 'Seek', exact: true }).first()
    await expect(seek).toBeVisible()
    await expect.poll(async () => Number(await seek.getAttribute('aria-valuenow'))).toBeGreaterThan(0)
    const beforeSeek = Number(await seek.getAttribute('aria-valuenow'))
    await pause.focus()
    await page.keyboard.press('Shift+ArrowRight')
    await expect.poll(async () => Number(await seek.getAttribute('aria-valuenow'))).toBeGreaterThan(beforeSeek + 8)
    for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
      await setTheme(page, mood, 'violet')
      await expectReadable(song)
      const indicator = song.locator('..').locator('svg').first()
      await expectReadable(indicator, 3)
      await expect(indicator).toHaveCSS('fill', await indicator.evaluate((element) => getComputedStyle(element).color))
      await expectReadable(pause, 3)
      await expectContained(seek)
      await expectNoPageOverflow(page)
    }
    if (width === 390 || width === 1280) await page.screenshot({ path: test.info().outputPath('active-playback.png') })
    const volume = page.getByRole('slider', { name: 'Volume', exact: true }).first()
    await expect(volume).toBeVisible()
    await volume.focus()
    await page.keyboard.press('Home')
    await expect(volume).toHaveAttribute('aria-valuenow', '0')
    await pause.click()
    await expect(page.getByRole('button', { name: 'Pause', exact: true })).toHaveCount(0)
    await expect(page.getByRole('slider', { name: 'Seek', exact: true })).toHaveCount(1)
    await page.keyboard.press('q')
    await page.getByRole('tab', { name: 'Queue', exact: true }).click()
    await expect(page.getByText(catalogSong.title, { exact: true }).last()).toBeVisible()
    if (width < 1024) {
      await page.keyboard.press('Escape')
      await expect(page.getByRole('button', { name: 'Close context panel' })).toHaveCount(0)
    }
  })
}

for (const width of [390, 768, 1280]) {
  test(`equalizer controls fit at ${width}px`, async ({ emptyCatalog: page }) => {
    await page.setViewportSize({ width, height: 800 })
    await spaNavigate(page)('/equalizer')
    const info = page.getByRole('button', { name: 'Toggle audio system info' })
    await expect(info).toBeVisible()
    for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
      await setTheme(page, mood, 'violet')
      await expectContained(info)
      await expectReadable(info, 3)
      await expectNoPageOverflow(page)
    }
    if (width === 768) await page.screenshot({ path: test.info().outputPath('equalizer.png') })
  })
}

for (const width of [390, 768, 1280, 1920]) {
  test(`browsing routes across moods at ${width}px`, async ({ populatedCatalog: page }) => {
    test.setTimeout(120_000)
    await page.setViewportSize({ width, height: 900 })
    const routes = [
      { path: '/music', label: 'Home' },
      { path: '/music/albums', label: 'Albums' },
      { path: '/music/songs', label: 'Genre' },
      { path: '/music/playlists', label: 'Playlists' },
      { path: '/search?q=styling', label: 'Search' },
    ]
    for (const route of routes) {
      await spaNavigate(page)(route.path)
      const identity = page.getByText(route.label, { exact: true }).last()
      await expect(identity).toBeVisible()
      for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
        await setTheme(page, mood, 'violet')
        await expectReadable(identity)
        await expectNoPageOverflow(page)
        if (width < 768) await page.getByRole('button', { name: 'Expand navigation' }).click()
        const search = page.getByPlaceholder('Search...', { exact: true })
        await expectContained(search)
        if (width < 768) await page.getByRole('button', { name: 'Collapse navigation' }).click()
      }
    }
    await setTheme(page, 'balanced', 'white')
    await expectReadable(page.getByRole('heading', { name: 'Search', exact: true }))
  })
}

for (const width of [390, 1280]) {
  test(`other media across moods at ${width}px`, async ({ emptyCatalog: page }) => {
    test.setTimeout(120_000)
    await page.setViewportSize({ width, height: 900 })
    for (const [route, title] of [
      ['/movies', 'Movies'], ['/tv', 'TV Shows'], ['/podcasts', 'Podcasts'],
      ['/concerts', 'Concerts'], ['/ebooks', 'Ebooks'],
    ]) {
      await spaNavigate(page)(route)
      const identity = page.getByRole('heading', { name: title, exact: true })
      await expect(identity).toBeVisible()
      for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
        await setTheme(page, mood, 'violet')
        await expectReadable(identity)
        await expectNoPageOverflow(page)
      }
    }
  })
}

test('populated playlist preserves readable selection and long names', async ({ populatedCatalog: page }) => {
  await spaNavigate(page)('/music/playlists')
  const playlist = page.getByRole('link', { name: catalogPlaylist.name })
  await expectContained(playlist)
  await playlist.click()
  await expect(page.getByRole('heading', { name: catalogPlaylist.name })).toBeVisible()
  const song = page.getByText(catalogSong.title, { exact: true }).first()
  await expect(song).toBeVisible()
  for (const mood of ['dark', 'warm', 'cool', 'balanced']) {
    await setTheme(page, mood, 'violet')
    await expectReadable(song)
    await expectNoPageOverflow(page)
  }
})
