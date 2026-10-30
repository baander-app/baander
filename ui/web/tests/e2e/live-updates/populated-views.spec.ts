import type { Page, Request } from '@playwright/test'
import { authenticatedGet } from './capacity-fixtures'
import { test, expect, navigate, paint } from './recovery-fixtures'

interface CatalogRead { page: number; cursor: string | null; start: number; end: number; count: number; status: number }
interface HttpSpan { path: string; start: number; end: number; status: number; failed: boolean }

function catalogReads(page: Page, path: string) {
  const reads: CatalogRead[] = []
  const http: HttpSpan[] = []
  const errors: string[] = []
  const albumsByYear = new Map<string, { publicId: string; title: string }[]>()
  const active = new Map<Request, number>()
  const networkActive = new Set<Request>()
  let maxInFlight = 0
  const matches = (request: Request) => {
    const url = new URL(request.url())
    if (url.pathname.replace(/\/$/, '') !== path.replace(/\/$/, '')) return false
    if (url.searchParams.get('limit') !== '100') return false
    return path !== '/api/albums' || (url.searchParams.get('sort') === 'year'
      && url.searchParams.get('order') === 'asc')
  }
  page.on('request', request => {
    if (!matches(request)) return
    active.set(request, Date.now())
    networkActive.add(request)
    maxInFlight = Math.max(maxInFlight, networkActive.size)
  })
  // Browser transfer completion ends concurrency before any body-reading RPC.
  page.on('requestfinished', request => { networkActive.delete(request) })
  page.on('response', async response => {
    const request = response.request()
    const start = active.get(request)
    if (start === undefined) return
    const url = new URL(request.url())
    http.push({ path: url.pathname + url.search, start, end: Date.now(), status: response.status(), failed: false })
    // Redirect hops are real HTTP work, but do not contain a logical catalog page.
    if (response.status() !== 200) {
      active.delete(request)
      return
    }
    try {
      const result = await response.json() as { data?: { publicId: string; title: string; year?: number }[] }
      const params = new URL(request.url()).searchParams
      if (path === '/api/albums') {
        if (params.get('page') === '1') albumsByYear.clear()
        for (const album of result.data ?? []) {
          const year = String(album.year ?? 'Unknown')
          const group = albumsByYear.get(year) ?? []
          group.push({ publicId: album.publicId, title: album.title })
          albumsByYear.set(year, group)
        }
      }
      reads.push({ page: Number(params.get('page') ?? 1), cursor: params.get('cursor'),
        start, end: Date.now(), count: result.data?.length ?? 0, status: response.status() })
    } catch (error) {
      errors.push(String(error))
    } finally { active.delete(request) }
  })
  page.on('requestfailed', request => {
    networkActive.delete(request)
    const start = active.get(request)
    if (start === undefined) return
    const url = new URL(request.url())
    http.push({ path: url.pathname + url.search, start, end: Date.now(), status: 0, failed: true })
    active.delete(request)
  })
  return { reads, http, errors, albumsByYear, get maxInFlight() { return maxInFlight },
    get pendingReads() { return active.size } }
}

async function drainCatalogSeed(run: import('./recovery-fixtures').RecoveryRun) {
  const samples: unknown[] = []
  run.evidence.push({ kind: 'catalog-seed-warmup', samples })
  await expect.poll(async () => {
    const { receipt } = await run.fixture.request<{ scopedInbox: { count: number }; globalInbox: unknown }>({ op: 'sample' })
    samples.push({ epochMs: Date.now(), ...receipt })
    return receipt.scopedInbox.count
  }, { timeout: 90_000, intervals: [250, 500, 1000] }).toBe(0)
}

async function listScroller(page: Page) {
  const row = page.getByRole('row').first()
  await expect(row).toBeVisible()
  return row.evaluateHandle(element => {
    let parent = element.parentElement
    while (parent && getComputedStyle(parent).overflowY !== 'auto') parent = parent.parentElement
    if (!parent) throw new Error('The rendered list has no scroll container')
    return parent
  })
}

test.describe('Populated live views', () => {
  test.setTimeout(300_000)

  test('ten retained song pages keep their public-ID selection and scroll during refresh', async ({ run }) => {
    const catalog = await run.fixture.request({ op: 'catalog', albums: 10_000, songs: 1000 })
    await drainCatalogSeed(run)
    const delivery: { type: string; epochMs: number }[] = []
    const page = await run.open(1, async candidate => {
      candidate.on('websocket', socket => socket.on('framereceived', ({ payload }) => {
        try {
          const frame = JSON.parse(String(payload)) as { type?: string }
          if (frame.type?.startsWith('live.')) delivery.push({ type: frame.type, epochMs: Date.now() })
        } catch { /* Media frames are outside the live-update measurement. */ }
      }))
    })
    await navigate(page, '/music/browse')
    const observer = catalogReads(page, '/api/songs/')
    run.evidence.push({ kind: 'song-read-observer', observer, delivery })
    await page.getByRole('button', { name: 'List view', exact: true }).click()
    const scroll = await listScroller(page)
    const retainedSongs = () => scroll.evaluate(element => {
      const spacer = element.firstElementChild
      if (!(spacer instanceof HTMLElement)) throw new Error('The rendered song spacer is missing')
      return spacer.getBoundingClientRect().height / 32
    })
    const retainedCursors = () => [...new Set(observer.reads.map(read => read.cursor))]
    await expect.poll(retainedSongs).toBe(100)
    for (let loaded = 1; loaded < 10; loaded++) {
      await scroll.evaluate(element => { element.scrollTop = element.scrollHeight })
      await expect.poll(() => retainedCursors().length).toBe(loaded + 1)
      await expect.poll(retainedSongs).toBe((loaded + 1) * 100)
    }
    await expect.poll(() => observer.pendingReads).toBe(0)
    const initialReads = [...observer.reads]
    const initialCursors = retainedCursors()
    expect(initialCursors).toHaveLength(10)
    expect(initialReads.every(read => read.status === 200 && read.count === 100)).toBe(true)
    run.evidence.push({ kind: 'retained-song-pages-loaded', retainedPages: initialCursors.length,
      retainedSongs: await retainedSongs(), initialAttempts: initialReads.length,
      repeatedInitialAttempts: initialReads.length - initialCursors.length, reads: initialReads })
    await scroll.evaluate(element => { element.scrollTop = 16_000 })
    const candidates = page.getByRole('row')
    let visibleIndex = -1
    for (let index = 0; index < await candidates.count(); index++) {
      const box = await candidates.nth(index).boundingBox()
      const scrollBox = await scroll.evaluate(element => {
        const rect = element.getBoundingClientRect()
        return { top: rect.top, bottom: rect.bottom }
      })
      if (box && box.y >= scrollBox.top && box.y + box.height <= scrollBox.bottom) {
        visibleIndex = index
        break
      }
    }
    expect(visibleIndex).toBeGreaterThanOrEqual(0)
    const selected = candidates.nth(visibleIndex)
    await selected.click()
    const before = await page.evaluate(async () => {
      const modulePath = '/src/features/catalog/stores/selection-store.ts'
      const { useSelectionStore } = await import(/* @vite-ignore */ modulePath) as {
        useSelectionStore: { getState: () => { selectedId: string | null } }
      }
      return useSelectionStore.getState().selectedId
    })
    expect(before).toBeTruthy()
    const song = await authenticatedGet<{ data: { title: string } }>(page, `/api/songs/${before}`)
    const escapedTitle = song.data.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const retainedRow = page.getByRole('row').filter({
      has: page.getByText(new RegExp(`^${escapedTitle}(?: · Unavailable)?$`)),
    })
    await expect(retainedRow).toHaveCount(1)
    const position = await scroll.evaluate(element => element.scrollTop)
    await expect.poll(() => observer.pendingReads).toBe(0)
    const refreshStart = Date.now()
    const albumTitle = `List refresh ${run.fixture.run}`
    await run.fixture.request({ op: 'change', target: 'album', value: albumTitle })
    const refreshReads = () => observer.reads.filter(read => read.start >= refreshStart)
    await expect.poll(() => refreshReads().length, { timeout: 40_000 }).toBe(10)
    await expect.poll(() => observer.pendingReads).toBe(0)
    expect(refreshReads().map(read => read.cursor)).toEqual(initialCursors)
    expect(refreshReads().map(read => read.count)).toEqual(Array.from({ length: 10 }, () => 100))
    expect(await retainedSongs()).toBe(1000)
    expect(await scroll.evaluate(element => element.scrollTop)).toBe(position)
    await expect(retainedRow).toBeVisible()
    await expect(retainedRow).toHaveCSS('border-left-width', '2px')
    await expect(retainedRow.getByText(albumTitle, { exact: true })).toBeVisible()
    const after = await page.evaluate(async () => {
      const modulePath = '/src/features/catalog/stores/selection-store.ts'
      const { useSelectionStore } = await import(/* @vite-ignore */ modulePath) as {
        useSelectionStore: { getState: () => { selectedId: string | null } }
      }
      return useSelectionStore.getState().selectedId
    })
    expect(after).toBe(before)
    expect(observer.errors).toEqual([])
    const frame = await paint(page)
    expect(frame.visible).toBe(true)
    run.evidence.push({ kind: 'ten-retained-pages', catalog: catalog.receipt, reads: observer.reads,
      initialAttempts: initialReads.length, logicalRetainedPages: initialCursors.length,
      refreshAttempts: refreshReads().length, refreshReads: refreshReads(),
      maxInFlight: observer.maxInFlight, selection: before, scrollTop: position,
      refreshToPaintMs: Date.now() - refreshStart, frame })
  })

  test('the 10K Timeline walk stays serial and repeats after a hint during the walk', async ({ run }) => {
    await run.fixture.request({ op: 'catalog', albums: 10_000, songs: 1000 })
    await drainCatalogSeed(run)
    const page = await run.open(1)
    await navigate(page, '/music/browse')
    const observer = catalogReads(page, '/api/albums')
    run.evidence.push({ kind: 'timeline-read-observer', observer })
    let release: () => void = () => {}
    const held = new Promise<void>(resolve => { release = resolve })
    let paused = false
    await page.route(/\/api\/albums\/?\?/, async route => {
      const url = new URL(route.request().url())
      if (!paused && url.searchParams.get('page') === '25' && url.searchParams.get('sort') === 'year'
        && url.searchParams.get('order') === 'asc' && url.searchParams.get('limit') === '100') {
        paused = true
        await held
      }
      await route.continue()
    })
    const started = Date.now()
    await page.getByRole('button', { name: 'Timeline view', exact: true }).click()
    try {
      await expect.poll(() => ({ reachedPage25: paused,
        failedReads: observer.http.filter(span => span.failed || span.status >= 400),
      }), { timeout: 30_000, message: 'Timeline must reach its held page without failed collection reads' })
        .toEqual({ reachedPage25: true, failedReads: [] })
      await run.fixture.request({ op: 'change', target: 'album', value: `Timeline final ${run.fixture.run}` })
    } finally { release() }
    await expect(page.getByRole('list', { name: 'Albums by year', exact: true })).toBeVisible({ timeout: 90_000 })
    await expect.poll(() => observer.reads.length, { timeout: 90_000 }).toBe(200)
    expect(observer.maxInFlight).toBe(1)
    expect(observer.reads.map(read => read.page)).toEqual([
      ...Array.from({ length: 100 }, (_, index) => index + 1),
      ...Array.from({ length: 100 }, (_, index) => index + 1),
    ])
    expect(observer.reads.every(read => read.status === 200 && read.count === 100)).toBe(true)
    expect(observer.errors).toEqual([])
    expect(observer.http.every(span => !span.failed && [200, 301, 302, 307, 308].includes(span.status))).toBe(true)
    const list = page.getByRole('list', { name: 'Albums by year', exact: true })
    expect(await list.getByRole('list', { name: /^Albums from / })
      .locator(':scope > [role="listitem"]').count()).toBe(10_000)
    const authoritative = await authenticatedGet<{ data: { title: string } }>(page,
      `/api/albums/${run.manifest.album.publicId}`)
    expect(authoritative.data.title).toBe(`Timeline final ${run.fixture.run}`)
    const year = [...observer.albumsByYear.entries()].find(([, albums]) =>
      albums.some(album => album.publicId === run.manifest.album.publicId))
    expect(year).toBeTruthy()
    const index = year![1].findIndex(album => album.publicId === run.manifest.album.publicId)
    const thumbnail = list.getByRole('list', { name: `Albums from ${year![0]}`, exact: true })
      .getByRole('button').nth(index)
    await thumbnail.scrollIntoViewIfNeeded()
    await thumbnail.hover()
    await expect(page.getByRole('tooltip').getByText(authoritative.data.title, { exact: true })).toBeVisible()
    const frame = await paint(page)
    expect(frame.visible).toBe(true)
    run.evidence.push({ kind: 'timeline-full-walk', reads: observer.reads, maxInFlight: observer.maxInFlight,
      http: observer.http, httpHops: observer.http.length, logicalPages: observer.reads.length,
      initialWalkMs: observer.reads[99]!.end - observer.reads[0]!.start,
      finalWalkMs: observer.reads[199]!.end - observer.reads[100]!.start,
      hintAmplification: observer.reads.length / 100, completeToPaintMs: Date.now() - started, frame })
  })
})
