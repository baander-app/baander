import { expect, test } from '@playwright/test'
import type { DeliveryRequest, DeliveryResource } from '../../../src/shared/api-client/gen/endpoints'
import { FixtureClient, login, type FixtureManifest } from '../live-updates/capacity-fixtures'
import { loadedRuntimeModule } from './loaded-runtime-module'
import { VideoDiagnostics } from './video-diagnostics'

interface VideoManifest extends FixtureManifest {
  movie: { id: string; publicId: string }
  video: { id: string; publicId: string; path: string }
}

for (const delivery of ['original', 'copy'] as const) {
  test(`${delivery} video plays, seeks and retains its client across reload`, async ({ browser, baseURL }, testInfo) => {
    test.setTimeout(240_000)
    if (!baseURL) throw new Error('An explicit isolated preview URL is required.')
    const fixture = FixtureClient.start('tests/Fixtures/Playback/video-fixture.php')
    const run = `delivery-${delivery}-${Date.now()}`
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
    const page = await context.newPage()
    const diagnostics = new VideoDiagnostics()
    diagnostics.observe(page, delivery)
    const requests: DeliveryRequest[] = []
    const decisions: DeliveryResource[] = []
    const failures: unknown[] = []
    const pending = new Set<Promise<void>>()
    page.on('request', request => {
      if (new URL(request.url()).pathname === '/api/stream/delivery') requests.push(request.postDataJSON() as DeliveryRequest)
    })
    page.on('response', response => {
      if (new URL(response.url()).pathname !== '/api/stream/delivery' || !response.ok()) return
      const read = response.json().then(value => { decisions.push(value as DeliveryResource) })
        .catch(error => { failures.push(error) })
      pending.add(read)
      read.finally(() => pending.delete(read))
    })
    try {
      const manifest = (await fixture.request<VideoManifest>({ op: 'create', run, delivery })).receipt
      await login(page, manifest.users[0]!)
      await page.goto(`/movies/${manifest.movie.publicId}`)
      const [deliveryResponse] = await Promise.all([
        page.waitForResponse(response => new URL(response.url()).pathname === '/api/stream/delivery'),
        page.getByRole('heading', { name: `Owned playback movie ${run}`, exact: true })
          .locator('..').getByRole('button', { name: 'Play', exact: true }).click(),
      ])
      expect(deliveryResponse.status()).toBe(200)
      await expect.poll(() => decisions[0]?.kind, { timeout: 60_000 }).toBe(delivery)
      await expect.poll(() => page.locator('video').evaluateAll(elements => elements.some(video =>
        video instanceof HTMLVideoElement && !video.paused && video.readyState >= 2)), { timeout: 60_000 }).toBe(true)
      const clientId = requests[0]?.requestId
      expect(clientId).toMatch(/^[0-9a-f-]{36}$/)
      expect(decisions[0]?.requestId).toBe(clientId)

      const execution = await loadedRuntimeModule(page, '/src/features/session/services/playback-execution.ts')
      if (!execution) throw new Error('Playback execution has not loaded.')
      await page.evaluate(async modulePath => {
        const { requestPlayback } = await import(/* @vite-ignore */ modulePath)
        await requestPlayback({ action: 'pause' })
        await requestPlayback({ action: 'seek', position: 12 })
      }, execution)
      await expect(page.getByRole('slider', { name: 'Seek movie' })).toHaveAttribute('aria-valuenow', '12')
      const inspected = (await fixture.request({ op: 'inspect', run })).receipt
      expect(inspected.jobs).toHaveLength(delivery === 'original' ? 0 : 1)

      const beforeReload = requests.length
      const recovery = page.waitForResponse(response =>
        new URL(response.url()).pathname === '/api/session/recover-local' && response.ok())
      await page.reload()
      await recovery
      await page.getByRole('heading', { name: `Owned playback movie ${run}`, exact: true })
        .locator('..').getByRole('button', { name: 'Play', exact: true }).click()
      await page.getByRole('slider', { name: 'Seek movie' }).locator('xpath=ancestor::div[1]')
        .getByRole('button', { name: 'Play', exact: true }).click()
      await expect.poll(() => requests.length, { timeout: 60_000 }).toBeGreaterThan(beforeReload)
      expect(requests.slice(beforeReload).every(request => request.requestId === clientId)).toBe(true)
      await expect.poll(() => page.locator('video').evaluateAll(elements => elements.some(video =>
        video instanceof HTMLVideoElement && video.readyState >= 2)), { timeout: 60_000 }).toBe(true)
    } catch (error) {
      failures.push(error)
    } finally {
      await diagnostics.attach(testInfo, run).catch(error => { failures.push(error) })
      await context.close().catch(error => { failures.push(error) })
      await Promise.all(pending)
      await testInfo.attach('delivery-playback', {
        body: JSON.stringify({ delivery, requests, decisions }), contentType: 'application/json',
      }).catch(error => { failures.push(error) })
      try {
        const cleanup = (await fixture.request({ op: 'cleanup', run })).receipt
        expect(cleanup.complete).toBe(true)
      } catch (error) { failures.push(error) }
      await fixture.close().catch(error => { failures.push(error) })
    }
    if (failures.length) throw new AggregateError(failures, `${delivery} playback or cleanup failed`)
  })
}
