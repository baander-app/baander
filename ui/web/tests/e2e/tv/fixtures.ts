import { randomUUID } from 'node:crypto'
import type { Browser, Page } from '@playwright/test'
import { expect } from '@playwright/test'
import {
  FixtureClient,
  authenticatedGet,
  login,
  LoginAdmission,
  type FixtureUser,
} from '../live-updates/capacity-fixtures'

interface PublicTitle { publicId: string; name: string }
interface PublicLibrary extends PublicTitle { slug: string }
interface VideoFile { videoPublicId: string; placementPublicId: string }
interface ExtraFile extends VideoFile { name: string }
interface Season { publicId: string; number: number; ownedCount: number; totalCount: number }

export interface VideoFixtureManifest {
  run: string
  delivery: 'original' | 'encoded'
  durationSeconds: number
  library: PublicLibrary
  movieLibrary: PublicLibrary
  series: PublicTitle
  season: Season
  specials: Season
  episodes: Record<'first' | 'second' | 'special' | 'absolute' | 'daily', PublicTitle>
  span: VideoFile
  extra: ExtraFile
  movie: {
    publicId: string
    title: string
    videoPublicId: string
    alternateVideoPublicId: string
    extra: ExtraFile
  }
  review: {
    seriesPublicId: string
    placementPublicId: string
    originalTitle: string
    matchedTitle: string
    candidateId: string
    orderingId: 'aired'
  }
}

interface MemberState {
  resumes: Array<{ videoPublicId: string; positionSeconds: number | null; reportedAt: string; lastPlayedAt: string | null }>
  watchedEpisodePublicIds: string[]
  watchedMoviePublicIds: string[]
}

export interface VideoFixtureInspection {
  member: MemberState
  secondMember: MemberState
  review: { name: string; matchedProvider: string | null; matchedManually: boolean; ordering: string }
  placements: Array<{ publicId: string; episodePublicIds: string[]; extraKind: string | null; assigned: boolean; available: boolean }>
  providerOrder: string[]
}

export interface VideoFixtureScope {
  manifest: VideoFixtureManifest
  member: Page
  secondMember: Page
  admin: Page
  inspect(): Promise<VideoFixtureInspection>
}

interface CreatedFixture {
  manifest: VideoFixtureManifest
  users: Record<'member' | 'secondMember' | 'admin', FixtureUser>
}
interface Provider { id: string; name: string; enabled: boolean }

async function providers(page: Page): Promise<Provider[]> {
  const result = await authenticatedGet<{ data: Provider[] }>(page, '/api/admin/metadata/providers')
  return result.data.filter(provider => ['tvdb', 'tmdb', 'local'].includes(provider.id))
}

async function setProviders(page: Page, values: Provider[]): Promise<void> {
  await page.goto('/admin/library?tab=metadata', { waitUntil: 'domcontentloaded' })
  for (const provider of values) {
    const control = page.getByRole('switch', { name: provider.name, exact: true })
    await expect(control).toBeVisible()
    if (await control.isChecked() !== provider.enabled) {
      const changed = page.waitForResponse(response => new URL(response.url()).pathname === `/api/admin/metadata/providers/${provider.id}` && response.request().method() === 'PATCH')
      await control.click()
      expect((await changed).status()).toBe(200)
    }
    if (provider.enabled) await expect(control).toBeChecked()
    else await expect(control).not.toBeChecked()
  }
  const confirmed = await providers(page)
  for (const value of values) expect(confirmed.find(provider => provider.id === value.id)?.enabled).toBe(value.enabled)
}

export async function withVideoFixture<T>(
  browser: Browser,
  baseURL: string,
  options: { delivery: 'original' | 'encoded' },
  run: (scope: VideoFixtureScope) => Promise<T>,
): Promise<T> {
  const fixture = FixtureClient.start('tests/Fixtures/Playback/tv-fixture.php')
  const runId = `tv-${options.delivery}-${randomUUID().slice(0, 8)}`
  const contexts: Array<Awaited<ReturnType<Browser['newContext']>>> = []
  let admin: Page | undefined
  let originalProviders: Provider[] = []
  const failures: unknown[] = []
  let result!: T
  try {
    const created = (await fixture.request<CreatedFixture>({ op: 'create', run: runId, delivery: options.delivery })).receipt
    const pages: Partial<Record<'member' | 'secondMember' | 'admin', Page>> = {}
    const admission = new LoginAdmission(Date.now() + 180_000)
    for (const role of ['admin', 'member', 'secondMember'] as const) {
      const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true })
      contexts.push(context)
      const page = await context.newPage()
      await login(page, created.users[role], admission)
      pages[role] = page
    }
    admin = pages.admin!
    originalProviders = await providers(admin)
    expect(originalProviders.map(provider => provider.id).sort()).toEqual(['local', 'tmdb', 'tvdb'])
    await setProviders(admin, originalProviders.map(provider => ({ ...provider, enabled: provider.id === 'local' })))
    result = await run({
      manifest: created.manifest,
      member: pages.member!,
      secondMember: pages.secondMember!,
      admin,
      inspect: async () => (await fixture.request<VideoFixtureInspection>({ op: 'inspect', run: runId })).receipt,
    })
  } catch (error) {
    failures.push(error)
  } finally {
    for (const context of contexts) {
      if (admin && context === admin.context()) continue
      await context.close().catch(error => failures.push(error))
    }
    let quiescent = false
    try {
      await fixture.request({ op: 'quiesce', run: runId })
      quiescent = true
    } catch (error) {
      failures.push(error)
    }
    if (quiescent && admin && !admin.isClosed() && originalProviders.length) {
      await setProviders(admin, originalProviders).catch(error => failures.push(error))
    }
    try {
      expect((await fixture.request({ op: 'cleanup', run: runId })).receipt.complete).toBe(true)
    } catch (error) {
      failures.push(error)
    } finally {
      for (const context of contexts) await context.close().catch(error => failures.push(error))
      await fixture.close().catch(error => failures.push(error))
    }
  }
  if (failures.length === 1) throw failures[0]
  if (failures.length > 1) throw new AggregateError(failures, 'Video fixture failed; ownership retained where cleanup was incomplete.')
  return result
}
