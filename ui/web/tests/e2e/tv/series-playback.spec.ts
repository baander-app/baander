import { expect, test, type Locator, type Page, type Request, type Response, type WebSocket } from '@playwright/test'
import type { TvEpisodeResource } from '../../../src/shared/api-client/gen/endpoints'
import { spaNavigate } from '../fixtures'
import { withVideoFixture, type VideoFixtureInspection, type VideoFixtureScope } from './fixtures'

function episodeRow(page: Page, name: string): Locator {
  return page.getByRole('tabpanel').getByRole('listitem').filter({
    has: page.getByRole('link', { name, exact: true })
  })
}

function progressResponse(page: Page, videoId: string, action: string): Promise<Response> {
  return page.waitForResponse(response => {
    if (new URL(response.url()).pathname !== `/api/activity/videos/${videoId}/progress`
      || response.request().method() !== 'POST') return false
    return response.request().postDataJSON()?.action === action
  })
}

async function recorded(response: Promise<Response>): Promise<void> {
  const accepted = await response
  expect(accepted.status()).toBe(200)
  expect(await accepted.json()).toEqual({ data: { recorded: true } })
}

async function playing(dialog: Locator, page: Page): Promise<void> {
  await expect(dialog).toBeVisible({ timeout: 25_000 })
  await expect(dialog.getByRole('button', { name: 'Pause', exact: true })).toBeEnabled()
  await expect(dialog.getByRole('slider', { name: 'Seek video' })).toBeEnabled()
  await expect.poll(() => page.locator('video').evaluateAll(elements => elements.some(element =>
    element instanceof HTMLVideoElement && element.readyState >= 3 && !element.paused && !element.seeking
  ))).toBe(true)
}

async function closePlayer(dialog: Locator, page: Page): Promise<void> {
  await dialog.getByRole('button', { name: 'Back', exact: true }).click()
  await expect(dialog).not.toBeVisible()
  await expect(page.locator('video')).toHaveCount(0)
}

function registrationAfterReload(page: Page) {
  interface Registration {
    activeIncarnationId: string | null
    transfer: unknown
  }
  const sockets = new Map<WebSocket, {
    sent: (event: { payload: string | Buffer }) => void
    received: (event: { payload: string | Buffer }) => void
  }>()
  let finish!: (value: Registration) => void
  let fail!: (reason: Error) => void
  const answer = new Promise<Registration>((resolve, reject) => {
    finish = resolve
    fail = reject
  })
  const dispose = () => {
    clearTimeout(timer)
    page.off('websocket', observe)
    for (const [socket, listeners] of sockets) {
      socket.off('framesent', listeners.sent)
      socket.off('framereceived', listeners.received)
    }
    sockets.clear()
  }
  const observe = (socket: WebSocket) => {
    let registered = false
    const sent = ({ payload }: { payload: string | Buffer }) => {
      if (typeof payload === 'string' && payload.includes('"session.endpoint"')) registered = true
    }
    const received = ({ payload }: { payload: string | Buffer }) => {
      if (!registered || typeof payload !== 'string') return
      let message: { type?: string; data?: Record<string, unknown> }
      try {
        message = JSON.parse(payload)
      } catch {
        return
      }
      if (message.type !== 'session.snapshot' || !message.data?.endpoint) return
      const active = message.data.activeIncarnationId
      if (active !== null && typeof active !== 'string') {
        dispose()
        fail(new Error('The endpoint registration answer omitted its active runner state.'))
        return
      }
      const value = { activeIncarnationId: active, transfer: message.data.transfer }
      dispose()
      finish(value)
    }
    sockets.set(socket, { sent, received })
    socket.on('framesent', sent)
    socket.on('framereceived', received)
  }
  const timer = setTimeout(() => {
    dispose()
    fail(new Error('The reloaded page did not receive its endpoint registration answer.'))
  }, 30_000)
  page.on('websocket', observe)
  return { answer, dispose }
}

async function reloadWithReleasedRunner(page: Page): Promise<void> {
  const registration = registrationAfterReload(page)
  try {
    const [, answer] = await Promise.all([page.reload(), registration.answer])
    expect(answer.activeIncarnationId).toBeNull()
    expect(answer.transfer).toBeNull()
  } finally {
    registration.dispose()
  }
}

async function reloadWithEpisodes(page: Page, seriesId: string): Promise<TvEpisodeResource[]> {
  let navigationStartedAt = Number.POSITIVE_INFINITY
  const observeNavigation = (request: Request) => {
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
      navigationStartedAt = request.timing().startTime
    }
  }
  page.on('request', observeNavigation)
  try {
    const [payload] = await Promise.all([
      page.waitForResponse(response => response.request().method() === 'GET'
        && response.request().timing().startTime >= navigationStartedAt
        && new URL(response.url()).pathname === `/api/tv/series/${seriesId}/episodes`)
        .then(async response => {
          expect(response.status()).toBe(200)
          return await response.json() as { data: TvEpisodeResource[] }
        }),
      reloadWithReleasedRunner(page)
    ])
    return payload.data
  } finally {
    page.off('request', observeNavigation)
  }
}

async function spanResume(scope: VideoFixtureScope): Promise<number> {
  const state = await scope.inspect()
  const entries = state.member.resumes.filter(item => item.videoPublicId === scope.manifest.span.videoPublicId)
  expect(entries).toHaveLength(1)
  const position = entries[0].positionSeconds
  expect(position).not.toBeNull()
  if (position === null) throw new Error('The span file has no saved position.')
  return position
}

function unchangedSecondMember(state: VideoFixtureInspection): void {
  expect(state.secondMember.resumes).toEqual([])
  expect(state.secondMember.watchedEpisodePublicIds).toEqual([])
  expect(state.secondMember.watchedMoviePublicIds).toEqual([])
}

async function seekNearEnd(dialog: Locator, page: Page, videoId: string, duration: number): Promise<void> {
  const seek = dialog.getByRole('slider', { name: 'Seek video' })
  const track = seek.locator('xpath=ancestor::*[@data-slot="slider"][1]')
  const bounds = await track.boundingBox()
  if (!bounds) throw new Error('The player seek track has no visible bounds.')
  await Promise.all([
    recorded(progressResponse(page, videoId, 'seek')),
    track.click({ position: { x: bounds.width * 0.84, y: bounds.height / 2 } })
  ])
  const position = Number(await seek.getAttribute('aria-valuenow'))
  expect(position).toBeGreaterThan(duration * 0.75)
  expect(position).toBeLessThan(duration * 0.9)
}

for (const delivery of ['original', 'encoded'] as const) {
  test(`series playback resumes and keeps member state on ${delivery} delivery`, { tag: '@firefox' }, async ({ browser, baseURL }) => {
    test.setTimeout(240_000)
    if (!baseURL) throw new Error('An isolated E2E_BASE_URL is required.')
    await withVideoFixture(browser, baseURL, { delivery }, async scope => {
      const { manifest, member, secondMember } = scope
      unchangedSecondMember(await scope.inspect())
      await spaNavigate(member)(`/tv/shows?library=${manifest.library.publicId}`)
      await member.getByRole('link', { name: manifest.series.name, exact: true }).click()
      await expect(member.getByRole('heading', { name: manifest.series.name, level: 1, exact: true })).toBeVisible()
      const tabs = member.getByRole('tablist', { name: 'Seasons' }).getByRole('tab')
      await expect(member.getByRole('tab', { name: `Season ${manifest.season.number}`, exact: true })).toBeVisible()
      await expect(tabs.first()).toHaveText(`Season ${manifest.season.number}`)
      await expect(tabs.last()).toHaveText('Specials')
      await expect(member.getByRole('tabpanel').getByText(
        `${manifest.season.ownedCount} of ${manifest.season.totalCount} episodes`, { exact: true }
      )).toBeVisible()

      const [negotiated] = await Promise.all([
        member.waitForResponse(response => response.request().method() === 'POST'
          && new URL(response.url()).pathname === '/api/stream/delivery'
          && response.request().postDataJSON()?.id === manifest.span.videoPublicId),
        episodeRow(member, manifest.episodes.first.name)
          .getByRole('button', { name: `Play ${manifest.episodes.first.name}`, exact: true }).click()
      ])
      expect((await negotiated.json()).kind).toBe(delivery)
      let dialog = member.getByRole('dialog', { name: manifest.episodes.first.name, exact: true })
      await playing(dialog, member)
      await Promise.all([
        recorded(progressResponse(member, manifest.span.videoPublicId, 'seek')),
        dialog.getByRole('button', { name: 'Forward 10 seconds (→)', exact: true }).click()
      ])
      await Promise.all([
        recorded(progressResponse(member, manifest.span.videoPublicId, 'pause')),
        dialog.getByRole('button', { name: 'Pause', exact: true }).click()
      ])
      const position = await spanResume(scope)
      expect(position).toBeGreaterThan(manifest.durationSeconds * 0.02)
      expect(position).toBeLessThan(manifest.durationSeconds * 0.9)
      expect((await scope.inspect()).member.watchedEpisodePublicIds).toEqual([])
      await closePlayer(dialog, member)

      const rows = await reloadWithEpisodes(member, manifest.series.publicId)
      for (const episode of [manifest.episodes.first, manifest.episodes.second]) {
        const row = episodeRow(member, episode.name)
        await expect(row.getByRole('button', { name: `Resume ${episode.name}`, exact: true })).toBeVisible()
        const data = rows.find(item => item.publicId === episode.publicId)
        expect(data?.defaultVideoPublicId).toBe(manifest.span.videoPublicId)
        expect(data?.videos.find(video => video.publicId === manifest.span.videoPublicId)?.positionSeconds).toBe(position)
      }
      const percentage = `${Math.round(position * 100 / manifest.durationSeconds)}%`
      await expect(episodeRow(member, manifest.episodes.first.name).getByText(/^\d+%$/)).toHaveText(percentage)
      await expect(episodeRow(member, manifest.episodes.second.name).getByText(/^\d+%$/)).toHaveText(percentage)
      await episodeRow(member, manifest.episodes.first.name)
        .getByRole('button', { name: `Resume ${manifest.episodes.first.name}`, exact: true }).click()
      dialog = member.getByRole('dialog', { name: manifest.episodes.first.name, exact: true })
      await playing(dialog, member)
      const cursor = Number(await dialog.getByRole('slider', { name: 'Seek video' }).getAttribute('aria-valuenow'))
      expect(cursor).toBeGreaterThanOrEqual(position - 1)
      expect(cursor).toBeLessThan(position + 4)
      await dialog.getByRole('button', { name: 'Start Over', exact: true }).focus()
      await Promise.all([
        recorded(progressResponse(member, manifest.span.videoPublicId, 'seek')),
        member.keyboard.press('Enter')
      ])
      await expect(dialog.getByRole('button', { name: 'Start Over', exact: true })).toHaveCount(0)
      await Promise.all([
        recorded(progressResponse(member, manifest.span.videoPublicId, 'pause')),
        dialog.getByRole('button', { name: 'Pause', exact: true }).click()
      ])
      await seekNearEnd(dialog, member, manifest.span.videoPublicId, manifest.durationSeconds)
      expect((await scope.inspect()).member.watchedEpisodePublicIds).toEqual([])
      await Promise.all([
        recorded(progressResponse(member, manifest.span.videoPublicId, 'end')),
        dialog.getByRole('button', { name: 'Play', exact: true }).click()
      ])
      const spanIds = [manifest.episodes.first.publicId, manifest.episodes.second.publicId].sort()
      await expect.poll(async () => (await scope.inspect()).member.watchedEpisodePublicIds.sort()).toEqual(spanIds)
      const completed = await scope.inspect()
      expect(completed.member.resumes.find(item => item.videoPublicId === manifest.span.videoPublicId)?.positionSeconds).toBeNull()
      unchangedSecondMember(completed)
      await closePlayer(dialog, member)
      await reloadWithReleasedRunner(member)
      for (const episode of [manifest.episodes.first, manifest.episodes.second]) {
        const row = episodeRow(member, episode.name)
        await expect(row.getByText('Watched', { exact: true })).toBeVisible()
        await expect(row.getByRole('button', { name: `Play ${episode.name}`, exact: true })).toBeVisible()
      }

      await member.getByRole('region', { name: 'Extras', exact: true })
        .getByRole('button', { name: `Play ${manifest.extra.name}`, exact: true }).click()
      dialog = member.getByRole('dialog', { name: manifest.extra.name, exact: true })
      await playing(dialog, member)
      await Promise.all([
        recorded(progressResponse(member, manifest.extra.videoPublicId, 'pause')),
        dialog.getByRole('button', { name: 'Pause', exact: true }).click()
      ])
      expect((await scope.inspect()).member.watchedEpisodePublicIds.sort()).toEqual(spanIds)
      await closePlayer(dialog, member)

      await member.getByRole('tab', { name: 'Specials', exact: true }).click()
      await expect(episodeRow(member, manifest.episodes.special.name).getByText('Watched', { exact: true })).toHaveCount(0)
      await member.getByRole('button', { name: 'Options for Specials', exact: true }).click()
      const [mark] = await Promise.all([
        member.waitForResponse(response => response.request().method() === 'PUT'
          && new URL(response.url()).pathname === `/api/activity/watched/season/${manifest.specials.publicId}`),
        member.getByRole('menuitem', { name: 'Mark as watched', exact: true }).click()
      ])
      expect(mark.status()).toBe(200)
      await member.reload()
      await expect(episodeRow(member, manifest.episodes.special.name).getByText('Watched', { exact: true })).toBeVisible()
      unchangedSecondMember(await scope.inspect())
      await spaNavigate(secondMember)(`/tv/series/${manifest.series.publicId}`)
      for (const episode of [manifest.episodes.first, manifest.episodes.second]) {
        const row = episodeRow(secondMember, episode.name)
        await expect(row.getByRole('button', { name: `Play ${episode.name}`, exact: true })).toBeVisible()
        await expect(row.getByText('Watched', { exact: true })).toHaveCount(0)
      }

      await spaNavigate(member)(`/movies/${manifest.movie.publicId}`)
      await expect(member.getByRole('img', { name: 'TMDB', exact: true })).toBeVisible()
      await expect(member.getByText('This product uses the TMDB API but is not endorsed or certified by TMDB.', { exact: true })).toBeVisible()
      const [movieDelivery] = await Promise.all([
        member.waitForResponse(response => response.request().method() === 'POST'
          && new URL(response.url()).pathname === '/api/stream/delivery'
          && response.request().postDataJSON()?.id === manifest.movie.alternateVideoPublicId),
        member.getByRole('button', { name: 'Play', exact: true }).click()
      ])
      expect((await movieDelivery.json()).kind).toBe(delivery)
      dialog = member.getByRole('dialog', { name: manifest.movie.title, exact: true })
      await playing(dialog, member)
      expect(Number(await dialog.getByRole('slider', { name: 'Seek video' }).getAttribute('aria-valuenow'))).toBeGreaterThanOrEqual(7)
      await Promise.all([
        recorded(progressResponse(member, manifest.movie.alternateVideoPublicId, 'pause')),
        dialog.getByRole('button', { name: 'Pause', exact: true }).click()
      ])
      await closePlayer(dialog, member)
      const final = await scope.inspect()
      expect(final.member.resumes.find(item => item.videoPublicId === manifest.movie.videoPublicId)).toBeUndefined()
      unchangedSecondMember(final)
    })
  })
}
