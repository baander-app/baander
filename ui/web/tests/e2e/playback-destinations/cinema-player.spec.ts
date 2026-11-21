import { expect, test, type Page } from '@playwright/test'
import type { VideoPlaybackInformation } from '../../../src/features/catalog/hooks/use-video-playback-information'
import {
  authenticatedGet,
  FixtureClient,
  login,
  type FixtureManifest,
} from '../live-updates/capacity-fixtures'
import { VideoDiagnostics } from './video-diagnostics'
import { loadedRuntimeModule } from './loaded-runtime-module'

interface CinemaManifest extends FixtureManifest {
  movie: {
    id: string
    publicId: string
  }
  video: {
    id: string
    publicId: string
    path: string
  }
}

async function playbackInformation(page: Page, path: string) {
  const response = await authenticatedGet<{
    data: VideoPlaybackInformation
  }>(page, path)
  return response.data
}

async function expectPausedAt(page: Page, position: number) {
  await expect.poll(async () => {
    const snapshot = await authenticatedGet<{
      data: {
        position: number
        desiredState: string
      }
    }>(page, '/api/session/snapshot')
    return snapshot.data
  }).toMatchObject({ position, desiredState: 'paused' })
  await expect.poll(() => page.locator('video').evaluateAll(elements =>
    elements.length > 0 && elements.every(element =>
      element instanceof HTMLVideoElement && element.paused,
    ),
  )).toBe(true)
}

test(
  'cinema media choices, previews and independent timing survive a paused seek',
  async ({ browser, baseURL }, testInfo) => {
    test.setTimeout(300_000)
    if (!baseURL) {
      throw new Error('An explicit isolated preview URL is required.')
    }

    const fixture = FixtureClient.start('tests/Fixtures/Playback/video-fixture.php')
    const run = `cinema-${Date.now()}`
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
    const page = await context.newPage()
    const diagnostics = new VideoDiagnostics()
    diagnostics.observe(page, 'cinema')
    const failures: unknown[] = []

    try {
      const manifest = (await fixture.request<CinemaManifest>({
        op: 'create',
        run,
        cinema: true,
      })).receipt
      const title = `Owned playback movie ${run}`
      let sourceStart: number | null = null
      page.on('response', async response => {
        if (
          !response.ok()
          || new URL(response.url()).pathname !== '/api/stream/sign'
        ) return
        try {
          const request = response.request().postDataJSON() as { path?: string }
          if (request.path !== `/api/transcode/${manifest.video.publicId}/master.m3u8`) {
            return
          }
          const body = await response.json() as {
            startTime?: number
            data?: { startTime?: number }
          }
          const start = body.data?.startTime ?? body.startTime
          if (typeof start === 'number') sourceStart = start
        } catch {
          // Closing the browser can interrupt an in-flight signing observation.
        }
      })
      const expectPaused = async (position: number) => {
        await expectPausedAt(page, position)
        await expect.poll(async () => {
          const nativeTime = await page.locator('video').evaluate(video => {
            if (
              !(video instanceof HTMLVideoElement)
              || video.seeking
              || video.readyState < 2
            ) return null
            return video.currentTime
          })
          return nativeTime === null || sourceStart === null
            ? Number.POSITIVE_INFINITY
            : Math.abs(sourceStart + nativeTime - position)
        }, { timeout: 60_000 }).toBeLessThan(0.2)
      }
      await login(page, manifest.users[0]!)
      await page.goto(`/movies/${manifest.movie.publicId}`)
      const launch = page.getByRole('heading', { name: title, exact: true })
        .locator('..').getByRole('button', { name: 'Play', exact: true })
      await launch.click()

      const player = page.getByRole('dialog', { name: title, exact: true })
      await expect(player).toBeVisible()
      await expect.poll(() => page.locator('video').evaluateAll(elements =>
        elements.some(element => element instanceof HTMLVideoElement
          && !element.paused && element.readyState >= 2),
      ), { timeout: 60_000 }).toBe(true)
      await expect(player).toHaveAttribute('data-idle', 'true')
      await player.press('k')
      await expect(player).toHaveAttribute('data-idle', 'false')
      await expect(player.getByRole('button', { name: 'Play', exact: true })).toBeVisible()

      const informationPath = `/api/videos/${manifest.video.publicId}/playback-information`
      const information = await playbackInformation(page, informationPath)
      expect(information.audioTracks.map(track => track.language))
        .toEqual(['eng', 'dan'])
      expect(information.subtitleTracks).toEqual([
        expect.objectContaining({ language: 'eng', isText: true }),
      ])
      expect(information.chapters).toEqual([
        expect.objectContaining({ title: 'Opening', start: 0 }),
        expect.objectContaining({ title: 'Middle', start: 10 }),
        expect.objectContaining({ title: 'Finale', start: 20 }),
      ])

      await player.getByRole('button', { name: 'Chapters', exact: true }).click()
      await player.getByRole('button', { name: /Middle/ }).click()
      await expectPaused(10)
      await expect(player.getByRole('slider', { name: 'Seek movie' }))
        .toHaveAttribute('aria-valuenow', '10')

      await expect.poll(async () => {
        const current = await playbackInformation(page, informationPath)
        return current.preview.state
      }, { timeout: 90_000 }).toBe('ready')
      const timeline = player.getByRole('slider', { name: 'Seek movie' })
      await timeline.hover({ position: { x: 5, y: 5 } })
      await expect(player.getByRole('img', { name: /Movie preview at/ }))
        .toBeVisible({ timeout: 15_000 })
      const previewResponse = await playbackInformation(page, informationPath)
      expect(previewResponse.preview.count).toBeGreaterThan(1)
      const clientModule = await loadedRuntimeModule(
        page,
        '/src/shared/api-client/gen/endpoints/index.ts',
      )
      expect(clientModule).not.toBeNull()
      const previewBytes = await page.evaluate(async ({ moduleUrl, videoId }) => {
        const client = await import(/* @vite-ignore */ moduleUrl)
        const blob: Blob = await client.getVideoPlaybackPreview(videoId)
        return {
          isBlob: blob instanceof Blob,
          type: blob.type,
          signature: Array.from(new Uint8Array(await blob.slice(0, 2).arrayBuffer())),
        }
      }, { moduleUrl: clientModule!, videoId: manifest.video.publicId })
      expect(previewBytes).toEqual({
        isBlob: true,
        type: 'image/jpeg',
        signature: [255, 216],
      })

      await player.getByRole('button', { name: 'Settings', exact: true }).click()
      await player.getByRole('combobox', { name: 'Audio track', exact: true }).click()
      await page.getByRole('option', { name: /Danish tone/ }).click()
      await expect(player.getByRole('combobox', { name: 'Audio track', exact: true }))
        .toContainText('Danish tone', { timeout: 60_000 })
      await expectPaused(10)
      await player.getByRole('combobox', { name: 'Subtitles', exact: true }).click()
      await page.getByRole('option', { name: /English captions/ }).click()
      await expect(player.getByRole('combobox', { name: 'Subtitles', exact: true }))
        .toContainText('English captions')
      await player.getByRole('combobox', { name: 'Quality', exact: true }).click()
      await page.getByRole('option', { name: /^\d+p$/ }).first().click()
      await expectPaused(10)
      await player.getByRole('button', { name: 'Audio timing later', exact: true }).click()
      await expect(player.getByLabel('Audio timing offset', { exact: true }))
        .toContainText('+100 ms')
      const middleCue = () => page.locator('video').evaluate(video => {
        if (!(video instanceof HTMLVideoElement)) return null
        for (const track of Array.from(video.textTracks)) {
          for (const cue of Array.from(track.cues ?? [])) {
            if (cue instanceof VTTCue && cue.text.includes('Middle caption')) {
              return { start: cue.startTime, end: cue.endTime }
            }
          }
        }
        return null
      })
      await expect.poll(middleCue).not.toBeNull()
      const baselineCue = (await middleCue())!
      await player.getByRole('button', { name: 'Subtitle timing earlier', exact: true }).click()
      await expect.poll(async () => (await middleCue())?.start)
        .toBeCloseTo(baselineCue.start - 0.1, 3)
      await expect.poll(async () => (await middleCue())?.end)
        .toBeCloseTo(baselineCue.end - 0.1, 3)
      await expect(player.getByLabel('Subtitle timing offset', { exact: true }))
        .toContainText('-100 ms')

      await timeline.focus()
      await timeline.press('ArrowRight')
      const soughtPosition = Number(await timeline.getAttribute('aria-valuenow'))
      expect(soughtPosition).toBe(11)
      await expectPaused(soughtPosition)
      await expect(player.getByRole('combobox', { name: 'Audio track', exact: true }))
        .toContainText('Danish tone')
      await expect(player.getByRole('combobox', { name: 'Subtitles', exact: true }))
        .toContainText('English captions')
      await expect(player.getByLabel('Audio timing offset', { exact: true }))
        .toContainText('+100 ms')
      await expect(player.getByLabel('Subtitle timing offset', { exact: true }))
        .toContainText('-100 ms')

      await player.getByRole('button', { name: 'Reset audio timing', exact: true }).click()
      await expect(player.getByLabel('Audio timing offset', { exact: true }))
        .toContainText('0 ms')
      await expect(player.getByLabel('Subtitle timing offset', { exact: true }))
        .toContainText('-100 ms')
      await player.getByRole('button', { name: 'Reset subtitle timing', exact: true }).click()
      await expect(player.getByLabel('Subtitle timing offset', { exact: true }))
        .toContainText('0 ms')
      await expectPaused(soughtPosition)

      await player.getByRole('button', { name: 'Close panel', exact: true }).click()
      await player.focus()
      await player.press('ArrowLeft')
      await expectPaused(1)
      await player.press('f')
      await expect.poll(() => page.evaluate(() =>
        document.fullscreenElement === document.documentElement,
      )).toBe(true)
      await page.evaluate(() => document.exitFullscreen())

      await page.setViewportSize({ width: 390, height: 844 })
      await expect(player.getByRole('button', { name: 'Settings', exact: true })).toBeInViewport()
      await expect(timeline).toBeInViewport()
      expect(await player.evaluate(element =>
        element.scrollWidth <= element.clientWidth,
      )).toBe(true)
      await page.screenshot({ path: testInfo.outputPath('cinema-narrow.png') })
      await testInfo.attach('cinema-narrow', {
        path: testInfo.outputPath('cinema-narrow.png'),
        contentType: 'image/png',
      })
      await player.getByRole('button', { name: 'Back', exact: true }).click()
      await expect(player).toBeHidden()
      await expect(launch).toBeFocused()
    } catch (error) {
      failures.push(error)
    } finally {
      await diagnostics.attach(testInfo, run).catch(error => failures.push(error))
      await context.close().catch(error => failures.push(error))
      try {
        const cleanup = (await fixture.request({ op: 'cleanup', run })).receipt
        await testInfo.attach('cinema-fixture-cleanup', {
          body: JSON.stringify(cleanup),
          contentType: 'application/json',
        })
        expect(cleanup.complete).toBe(true)
      } catch (error) {
        failures.push(error)
      }
      await fixture.close().catch(error => failures.push(error))
    }
    if (failures.length > 0) {
      throw new AggregateError(failures, 'Cinema playback or fixture cleanup failed.')
    }
  },
)
