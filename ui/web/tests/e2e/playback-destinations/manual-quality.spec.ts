import { expect, test, type Page } from '@playwright/test'
import type { VideoPlaybackInformation } from '../../../src/features/catalog/hooks/use-video-playback-information'
import type {
  DeliveryRequest,
  DeliveryResource,
  PlaybackSnapshotResource,
} from '../../../src/shared/api-client/gen/endpoints'
import {
  authenticatedGet,
  FixtureClient,
  login,
  type FixtureManifest,
} from '../live-updates/capacity-fixtures'
import { loadedRuntimeModule } from './loaded-runtime-module'
import { VideoDiagnostics } from './video-diagnostics'

interface QualityManifest extends FixtureManifest {
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

type QualityRequest = DeliveryRequest & {
  qualityTier?: string | null
}

async function snapshot(page: Page) {
  const response = await authenticatedGet<{ data: PlaybackSnapshotResource }>(
    page,
    '/api/session/snapshot',
  )
  return response.data
}

for (const delivery of ['original', 'copy'] as const) {
  test(`${delivery} delivery changes decoded resolution and restores Auto while paused`, async ({
    browser,
    baseURL,
  }, testInfo) => {
    test.setTimeout(360_000)
    if (!baseURL) {
      throw new Error('An explicit isolated preview URL is required.')
    }

    const fixture = FixtureClient.start('tests/Fixtures/Playback/video-fixture.php')
    const run = `quality-${delivery}-${Date.now()}`
    const context = await browser.newContext({
      baseURL,
      ignoreHTTPSErrors: true,
      viewport: { width: 1280, height: 800 },
    })
    const page = await context.newPage()
    const diagnostics = new VideoDiagnostics()
    diagnostics.observe(page, delivery)
    const requests: QualityRequest[] = []
    const decisions: DeliveryResource[] = []
    const pending = new Set<Promise<void>>()
    const failures: unknown[] = []
    const viewportFits: {
      tier: string
      layout: string
      decodedWidth: number
      decodedHeight: number
      videoWidth: number
      videoHeight: number
      pictureWidth: number
      pictureHeight: number
    }[] = []
    let sourceStart = 0

    page.on('request', request => {
      if (new URL(request.url()).pathname === '/api/stream/delivery') {
        requests.push(request.postDataJSON() as QualityRequest)
      }
    })
    page.on('response', response => {
      const path = new URL(response.url()).pathname
      if (!response.ok() || !['/api/stream/delivery', '/api/stream/sign'].includes(path)) {
        return
      }
      const read = response.json().then(body => {
        if (path === '/api/stream/delivery') {
          const decision = body as DeliveryResource
          decisions.push(decision)
          if (decision.kind === 'original') sourceStart = 0
        } else {
          const signed = body as {
            startTime?: number
            data?: { startTime?: number }
          }
          const start = signed.data?.startTime ?? signed.startTime
          if (typeof start === 'number') sourceStart = start
        }
      }).catch(error => {
        failures.push(error)
      })
      pending.add(read)
      read.finally(() => pending.delete(read))
    })

    try {
      const manifest = (await fixture.request<QualityManifest>({
        op: 'create',
        run,
        delivery,
        quality: true,
      })).receipt
      const title = `Owned playback movie ${run}`
      await login(page, manifest.users[0]!)
      await page.goto(`/movies/${manifest.movie.publicId}`)
      await page.getByRole('heading', { name: title, exact: true })
        .locator('..').getByRole('button', { name: 'Play', exact: true }).click()
      const player = page.getByRole('dialog', { name: title, exact: true })
      await expect(player).toBeVisible()
      await expect.poll(() => decisions[0]?.kind, { timeout: 60_000 }).toBe(delivery)
      await expect.poll(() => page.locator('video').evaluate(video => (
        video instanceof HTMLVideoElement && video.readyState >= 2
          && !video.paused && video.videoHeight === 720
      )), { timeout: 60_000 }).toBe(true)

      const informationPath = `/api/videos/${manifest.video.publicId}/playback-information`
      const information = await authenticatedGet<{ data: VideoPlaybackInformation }>(
        page,
        informationPath,
      )
      const choices = information.data.qualityChoices.map(choice => choice.id)
      expect(choices).toEqual(expect.arrayContaining(['360p', '480p', '720p']))

      const execution = await loadedRuntimeModule(
        page,
        '/src/features/session/services/playback-execution.ts',
      )
      if (!execution) throw new Error('Playback execution has not loaded.')
      await page.evaluate(async modulePath => {
        const { requestPlayback } = await import(/* @vite-ignore */ modulePath)
        await requestPlayback({ action: 'pause' })
        await requestPlayback({ action: 'seek', position: 12 })
      }, execution)
      const owner = await snapshot(page)
      expect(owner.activeDestinationId).not.toBeNull()
      expect(owner.activeIncarnationId).not.toBeNull()
      const expectPausedResolution = async (height: number) => {
        await expect.poll(async () => {
          const state = await snapshot(page)
          return {
            position: state.position,
            desiredState: state.desiredState,
            activeDestinationId: state.activeDestinationId,
            activeIncarnationId: state.activeIncarnationId,
            media: state.media,
          }
        }).toEqual({
          position: 12,
          desiredState: 'paused',
          activeDestinationId: owner.activeDestinationId,
          activeIncarnationId: owner.activeIncarnationId,
          media: owner.media,
        })
        await expect.poll(() => page.locator('video').evaluate(video => {
          if (!(video instanceof HTMLVideoElement)) return null
          return {
            paused: video.paused,
            ready: video.readyState >= 2 && !video.seeking,
            height: video.videoHeight,
          }
        }), { timeout: 90_000 }).toEqual({ paused: true, ready: true, height })
        await expect.poll(async () => {
          const nativeTime = await page.locator('video').evaluate(video => (
            video instanceof HTMLVideoElement ? video.currentTime : null
          ))
          return nativeTime === null
            ? Number.POSITIVE_INFINITY
            : Math.abs(sourceStart + nativeTime - 12)
        }, { timeout: 60_000 }).toBeLessThan(0.2)
      }
      const expectViewportFit = async (tier: string, height: number) => {
        const requestCount = requests.length
        const measure = () => page.locator('video').evaluate(video => {
          if (!(video instanceof HTMLVideoElement)) {
            throw new Error('The persistent video element is missing.')
          }
          const host = video.parentElement
          const area = host?.parentElement
          const dialog = video.closest('[role="dialog"]')
          if (!host || !area || !dialog) {
            throw new Error('The video must remain mounted inside the cinema area.')
          }
          const videoBox = video.getBoundingClientRect()
          const hostBox = host.getBoundingClientRect()
          const areaBox = area.getBoundingClientRect()
          const dialogBox = dialog.getBoundingClientRect()
          const edgeDifference = (first: DOMRect, second: DOMRect) => Math.max(
            Math.abs(first.left - second.left),
            Math.abs(first.top - second.top),
            Math.abs(first.right - second.right),
            Math.abs(first.bottom - second.bottom),
          )
          const scale = Math.min(
            videoBox.width / video.videoWidth,
            videoBox.height / video.videoHeight,
          )
          return {
            decodedWidth: video.videoWidth,
            decodedHeight: video.videoHeight,
            videoWidth: videoBox.width,
            videoHeight: videoBox.height,
            pictureWidth: video.videoWidth * scale,
            pictureHeight: video.videoHeight * scale,
            objectFit: getComputedStyle(video).objectFit,
            hostDifference: edgeDifference(hostBox, areaBox),
            videoDifference: edgeDifference(videoBox, areaBox),
            areaDifference: edgeDifference(areaBox, dialogBox),
            viewportOverflow: Math.max(
              0,
              -areaBox.left,
              -areaBox.top,
              areaBox.right - innerWidth,
              areaBox.bottom - innerHeight,
            ),
          }
        })
        const expectLayout = async (layout: string) => {
          await expect.poll(async () => {
            const geometry = await measure()
            return Math.max(
              geometry.hostDifference,
              geometry.videoDifference,
              geometry.areaDifference,
              geometry.viewportOverflow,
            )
          }, { message: `${tier} video and host must fill the ${layout} cinema area` })
            .toBeLessThan(1)
          const geometry = await measure()
          expect(geometry.objectFit).toBe('contain')
          expect(geometry.decodedHeight).toBe(height)
          expect(geometry.decodedWidth / geometry.decodedHeight).toBeCloseTo(16 / 9, 2)
          expect(geometry.videoWidth).toBeGreaterThan(0)
          expect(geometry.videoHeight).toBeGreaterThan(0)
          expect(geometry.pictureWidth).toBeLessThanOrEqual(geometry.videoWidth + 1)
          expect(geometry.pictureHeight).toBeLessThanOrEqual(geometry.videoHeight + 1)
          expect(Math.min(
            Math.abs(geometry.pictureWidth - geometry.videoWidth),
            Math.abs(geometry.pictureHeight - geometry.videoHeight),
          )).toBeLessThan(1)
          const previousFit = viewportFits.find(fit => fit.layout === layout)
          if (previousFit) {
            expect(Math.abs(geometry.videoWidth - previousFit.videoWidth)).toBeLessThan(1)
            expect(Math.abs(geometry.videoHeight - previousFit.videoHeight)).toBeLessThan(1)
          }
          viewportFits.push({
            tier,
            layout,
            decodedWidth: geometry.decodedWidth,
            decodedHeight: geometry.decodedHeight,
            videoWidth: geometry.videoWidth,
            videoHeight: geometry.videoHeight,
            pictureWidth: geometry.pictureWidth,
            pictureHeight: geometry.pictureHeight,
          })
          await expectPausedResolution(height)
        }

        await expectLayout('desktop')
        await page.setViewportSize({ width: 390, height: 844 })
        await expectLayout('narrow portrait')
        await page.setViewportSize({ width: 1280, height: 800 })
        await player.focus()
        await player.press('f')
        await expect.poll(() => page.evaluate(() => (
          document.fullscreenElement === document.documentElement
        ))).toBe(true)
        try {
          await expectLayout('fullscreen')
        } finally {
          await page.evaluate(() => document.exitFullscreen())
        }
        await expect.poll(() => page.evaluate(() => document.fullscreenElement)).toBeNull()
        expect(requests).toHaveLength(requestCount)
      }
      await expectPausedResolution(720)
      await expectViewportFit('720p', 720)
      await player.getByRole('button', { name: 'Settings', exact: true }).click()
      const quality = player.getByRole('combobox', { name: 'Quality', exact: true })
      for (const [tier, height] of [['480p', 480], ['360p', 360]] as const) {
        const before = requests.length
        await quality.click()
        await page.getByRole('option', { name: tier, exact: true }).click()
        await expect.poll(() => requests.slice(before).some(request => (
          request.qualityTier === tier
        ))).toBe(true)
        await expectPausedResolution(height)
        await expect(quality).toContainText(tier)
        await expectViewportFit(tier, height)
        await expect(quality).toContainText(tier)
        const currentInformation = await authenticatedGet<{ data: VideoPlaybackInformation }>(
          page,
          informationPath,
        )
        expect(currentInformation.data.qualityChoices.map(choice => choice.id)).toEqual(choices)
      }
      const beforeAuto = requests.length
      const decisionsBeforeAuto = decisions.length
      await quality.click()
      await page.getByRole('option', { name: /^Auto/ }).click()
      await expect.poll(() => requests.slice(beforeAuto).some(request => (
        request.qualityTier == null
      ))).toBe(true)
      await expect.poll(() => decisions.slice(decisionsBeforeAuto).some(decision => (
        decision.kind === delivery
      )), { timeout: 60_000 }).toBe(true)
      await expectPausedResolution(720)
      await expect(quality).toContainText('Auto')
      await expectViewportFit('Auto', 720)
      await expect(quality).toContainText('Auto')
      expect(requests.every(request => request.requestId === requests[0]?.requestId))
        .toBe(true)
    } catch (error) {
      failures.push(error)
    } finally {
      await diagnostics.attach(testInfo, run).catch(error => failures.push(error))
      await context.close().catch(error => failures.push(error))
      await Promise.all(pending)
      await testInfo.attach('manual-quality-delivery', {
        body: JSON.stringify({ delivery, requests, decisions, viewportFits }, null, 2),
        contentType: 'application/json',
      }).catch(error => failures.push(error))
      try {
        const cleanup = (await fixture.request({ op: 'cleanup', run })).receipt
        await testInfo.attach('manual-quality-cleanup', {
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
      throw new AggregateError(failures, `${delivery} manual quality or cleanup failed.`)
    }
  })
}
