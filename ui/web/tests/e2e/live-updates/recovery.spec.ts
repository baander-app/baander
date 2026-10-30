import { authenticatedGet } from './capacity-fixtures'
import { CapacityObserver } from './capacity-observers'
import { RESTART_QUALIFICATION_MS } from './managed-lifecycle'
import { test, expect, navigate, paint, holdResponse, interruptDelivery, observeReads } from './recovery-fixtures'

test.describe('Live recovery through the authenticated application', () => {
  test.setTimeout(180_000)

  test('two collaborators refresh while a denied identity receives no playlist content', async ({ run }) => {
    const first = await run.open(1)
    const second = await run.open(2)
    const denied = await run.open(3)
    const sameAccount = await run.open(1)
    const path = `/music/playlists/${run.manifest.playlist.publicId}`
    await Promise.all([first, second, denied].map(page => navigate(page, path)))
    await expect(denied.getByText('Playlist not found', { exact: true })).toBeVisible()
    const name = `Shared ${run.fixture.run}`
    await run.fixture.request({ op: 'change', target: 'playlist', value: name })
    for (const page of [first, second]) {
      await expect(page.getByRole('heading', { name, exact: true })).toBeVisible({ timeout: 20_000 })
      expect(await authenticatedGet<{ data: { name: string } }>(page,
        `/api/playlists/${run.manifest.playlist.publicId}`).then(result => result.data.name)).toBe(name)
      run.evidence.push(await paint(page))
    }
    await expect(denied.getByText(name, { exact: true })).toHaveCount(0)
    await denied.getByRole('button', { name: /unread notifications/ }).click()
    await first.getByRole('button', { name: /unread notifications/ }).click()
    await sameAccount.getByRole('button', { name: /unread notifications/ }).click()
    const round = await run.fixture.request({ op: 'ordinary' })
    const notification = round.receipt.users!.find(user => user.id === run.manifest.users[1]!.id)!
    await expect(first.getByText(notification.title, { exact: true })).toBeVisible({ timeout: 20_000 })
    await expect(sameAccount.getByText(notification.title, { exact: true })).toBeVisible({ timeout: 20_000 })
    await expect(denied.getByText(notification.title, { exact: true })).toHaveCount(0)
    await first.getByRole('button', { name: 'Mark all read', exact: true }).click()
    await expect(first.getByRole('button', { name: '0 unread notifications', exact: true })).toBeVisible()
    await expect(sameAccount.getByRole('button', { name: '0 unread notifications', exact: true })).toBeVisible()
    run.evidence.push({ kind: 'same-account-mark-read', ...await paint(sameAccount) })
  })

  test('delivery outage preserves readable state and same-tab record-play refreshes Recents', async ({ run }) => {
    const page = await run.open(1)
    await navigate(page, `/music/playlists/${run.manifest.playlist.publicId}`)
    const title = await page.locator('main h1').innerText()
    const restore = await interruptDelivery(page)
    try {
      await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible()
      await mutePlayer(page)
      await Promise.all([
        page.waitForResponse(response => response.url().includes('/api/activity/play')
          && response.request().method() === 'POST' && response.ok()),
        page.getByRole('button', { name: 'Play all', exact: true }).click(),
      ])
      const song = await authenticatedGet<{ data: { title: string } }>(page, `/api/songs/${run.manifest.song.publicId}`)
      await expect(page.getByRole('group', { name: 'Recent', exact: true }).getByText(song.data.title, { exact: true }))
        .toBeVisible({ timeout: 20_000 })
      run.evidence.push({ kind: 'same-tab-recents-without-delivery', ...await paint(page) })
      await run.fixture.request({ op: 'change', target: 'playlist', value: `Recovered ${run.fixture.run}` })
    } finally { await restore() }
    await expect(page.getByRole('heading', { name: `Recovered ${run.fixture.run}`, exact: true }))
      .toBeVisible({ timeout: 30_000 })
  })

  test('failed authoritative read retries without a second change hint', async ({ run }) => {
    const page = await run.open(1)
    await navigate(page, `/music/playlists/${run.manifest.playlist.publicId}`)
    await expect(page.locator('main h1')).toBeVisible()
    const reads = observeReads(page, `/api/playlists/${run.manifest.playlist.publicId}`)
    let failures = 0
    await page.route(`**/api/playlists/${run.manifest.playlist.publicId}`, async route => {
      if (failures++ === 0) await route.fulfill({ status: 503, json: { message: 'Controlled read outage' } })
      else await route.continue()
    })
    const name = `Retry ${run.fixture.run}`
    await run.fixture.request({ op: 'change', target: 'playlist', value: name })
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible({ timeout: 30_000 })
    expect(reads.some(read => read.status === 503)).toBe(true)
    expect(reads.some(read => read.status === 200)).toBe(true)
    run.evidence.push({ kind: 'single-hint-read-retry', reads, ...await paint(page) })
  })

  test('revocation during a held read and delivery outage purges the previous baseline', async ({ run }) => {
    let observer!: CapacityObserver
    const page = await run.open(1, async candidate => {
      observer = new CapacityObserver(candidate, run.manifest.users[1]!.id)
    })
    await expect.poll(() => observer.ready).toBe(true)
    await navigate(page, `/music/playlists/${run.manifest.playlist.publicId}`)
    const original = await page.locator('main h1').innerText()
    const held = await holdResponse(page, `**/api/playlists/${run.manifest.playlist.publicId}`)
    const restore = await interruptDelivery(page)
    try {
      await run.fixture.request({ op: 'change', target: 'playlist', value: `Pending ${run.fixture.run}` })
      await restore()
      await held.pending
      expect(held.status).toBe(200)
      const ownership = () => page.evaluate(async () => {
        const modulePath = '/src/shared/live/ownership.ts'
        const { captureOwnership } = await import(/* @vite-ignore */ modulePath) as {
          captureOwnership: () => { principal: string | null; generation: number }
        }
        return captureOwnership()
      })
      const ownerBefore = await ownership()
      const revoked = await run.fixture.request({ op: 'authority', target: 'playlist', userIndex: 1, grant: false })
      await expect.poll(async () => {
        const owner = await ownership()
        return owner.principal === ownerBefore.principal && owner.generation > ownerBefore.generation
      }, { timeout: 30_000 }).toBe(true)
      const ownerAfter = await ownership()
      await expect(page.getByRole('heading', { name: original, exact: true })).toHaveCount(0)
      const authorityObservedAt = Date.now()
      await page.evaluate(() => {
        const window_ = window as unknown as { __protectedHeadings: string[] }
        window_.__protectedHeadings = []
        const observe = () => {
          window_.__protectedHeadings.push(document.querySelector('main h1')?.textContent ?? '')
          if (window_.__protectedHeadings.length < 3000) requestAnimationFrame(observe)
        }
        requestAnimationFrame(observe)
      })
      const heldResponseReleasedAt = Date.now()
      await held.release()
      await paint(page)
      await expect(page.getByText('Playlist not found', { exact: true })).toBeVisible({ timeout: 35_000 })
      await expect(page.getByRole('heading', { name: original, exact: true })).toHaveCount(0)
      await expect(page.getByRole('heading', { name: `Pending ${run.fixture.run}`, exact: true })).toHaveCount(0)
      const headings = await page.evaluate(() => (window as unknown as { __protectedHeadings: string[] }).__protectedHeadings)
      expect(headings).not.toContain(`Pending ${run.fixture.run}`)
      run.evidence.push({ kind: 'revoked-pending-read', headings, revocation: revoked.receipt,
        ownerBefore, ownerAfter, authorityObservedAt, heldResponseReleasedAt, socketFrames: observer.socketFrames,
        ...await paint(page) })
    } finally {
      await held.release()
      await restore()
    }
  })

  test('an open socket reports stalled projection then converges after lock release', async ({ run }) => {
    const closed: number[] = []
    let observer!: CapacityObserver
    const page = await run.open(1, async candidate => {
      observer = new CapacityObserver(candidate, run.manifest.users[1]!.id)
      candidate.on('websocket', socket => {
        if (new URL(socket.url()).pathname === '/api/ws') socket.on('close', () => closed.push(Date.now()))
      })
    })
    await expect.poll(() => observer.ready).toBe(true)
    await observer.install()
    await page.getByRole('button', { name: /unread notifications/ }).click()
    await run.fixture.request({ op: 'projector', hold: true })
    try {
      await run.fixture.request({ op: 'ordinary' })
      await expect(page.getByRole('status').filter({ hasText: 'Updates unavailable' })).toBeVisible({ timeout: 45_000 })
      expect(closed).toEqual([])
      run.evidence.push({ kind: 'projection-stalled', sample: (await run.fixture.request({ op: 'sample' })).receipt })
    } finally { await run.fixture.request({ op: 'projector', hold: false }) }
    await observer.waitForCount(1, undefined, 30_000)
    await expect(page.getByRole('status').filter({ hasText: 'Updates unavailable' })).toHaveCount(0, { timeout: 30_000 })
    run.evidence.push({ kind: 'projection-recovered', observer: observer.snapshot() })
  })

  test('a dirty metadata draft and advancing owned audio survive unrelated refresh', async ({ run }) => {
    let observer!: CapacityObserver
    const page = await run.open(-1, async candidate => {
      observer = new CapacityObserver(candidate, run.manifest.observer.id)
    })
    await expect.poll(() => observer.ready).toBe(true)
    const song = await authenticatedGet<{ data: { title: string } }>(page, `/api/songs/${run.manifest.song.publicId}`)
    await navigate(page, `/music/playlists/${run.manifest.playlist.publicId}`)
    await mutePlayer(page)
    await Promise.all([
      page.waitForResponse(response => response.url().includes('/stream') && response.ok()),
      page.waitForResponse(response => response.url().includes('/api/activity/play')
        && response.request().method() === 'POST' && response.ok()),
      page.getByRole('button', { name: 'Play all', exact: true }).click(),
    ])
    await expect.poll(async () => (await audioState(page)).time).toBeGreaterThan(0.1)
    const before = await audioState(page)
    await navigate(page, '/music/browse')
    await page.getByRole('button', { name: 'List view', exact: true }).click()
    const row = page.getByRole('row').filter({ hasText: song.data.title }).first()
    await expect(row).toBeVisible()
    await row.click({ button: 'right' })
    await page.getByRole('menuitem', { name: /Get Info/ }).click()
    const editor = page.getByPlaceholder('Song title', { exact: true })
    await expect(editor).toHaveValue(song.data.title)
    const held = await holdResponse(page, `**/api/songs/${run.manifest.song.publicId}`, 'PATCH')
    try {
      await editor.fill(`Submitted ${run.fixture.run}`)
      await held.pending
      expect(held.status).toBeGreaterThanOrEqual(200)
      expect(held.status).toBeLessThan(300)
      const draft = `Unsaved newer ${run.fixture.run}`
      await editor.fill(draft)
      await page.getByRole('button', { name: /unread notifications/ }).click()
      const notification = await run.fixture.request({ op: 'observer-notification' })
      expect(notification.receipt.capturedRows).toBe(1)
      const expected = notification.receipt.users![0]!
      expect(expected.id).toBe(run.manifest.observer.id)
      await expect(page.getByRole('button', { name: `${expected.count} unread notifications`, exact: true })).toBeVisible()
      await expect(page.getByText(expected.title, { exact: true })).toBeVisible()
      await expect(editor).toHaveValue(draft)
      await expect.poll(async () => (await audioState(page)).time).toBeGreaterThan(before.time + 0.1)
      const after = await audioState(page)
      expect(after).toMatchObject({ src: before.src, track: run.manifest.song.publicId,
        paused: false, muted: true, sameElement: true, queue: before.queue, index: before.index })
      run.evidence.push({ kind: 'dirty-draft-and-real-playback', heldPatchStatus: held.status,
        before, after, ...await paint(page) })
      const readyBefore = observer.socketFrames.filter(frame => frame.type === 'live.ready').length
      const restore = await interruptDelivery(page)
      try {
        await expect(editor).toHaveValue(draft)
        expect(await audioState(page)).toMatchObject({ src: before.src, paused: false, sameElement: true,
          queue: before.queue, index: before.index })
      } finally { await restore() }
      await expect.poll(() => observer.socketFrames.filter(frame => frame.type === 'live.ready').length,
        { timeout: 15_000 }).toBeGreaterThan(readyBefore)
      await expect(editor).toHaveValue(draft)
      await expect.poll(async () => (await audioState(page)).time).toBeGreaterThan(after.time + 0.1)
      const reconnected = await audioState(page)
      expect(reconnected).toMatchObject({ src: before.src, track: run.manifest.song.publicId,
        paused: false, muted: true, sameElement: true, queue: before.queue, index: before.index })
      run.evidence.push({ kind: 'dirty-draft-and-playback-after-reconnect', before, after: reconnected,
        socketFrames: observer.socketFrames, ...await paint(page) })
    } finally { await held.release() }
  })

  test('a genuinely frozen browser page recovers committed changes on wake', async ({ run }) => {
    const page = await run.open(1)
    await navigate(page, `/music/playlists/${run.manifest.playlist.publicId}`)
    await expect(page.locator('main h1')).toBeVisible()
    const session = await page.context().newCDPSession(page)
    const frozenAt = Date.now()
    const name = `After browser wake ${run.fixture.run}`
    try {
      await session.send('Page.setWebLifecycleState', { state: 'frozen' })
      await run.fixture.request({ op: 'change', target: 'playlist', value: name })
      run.evidence.push({ kind: 'browser-frozen', frozenAt,
        sample: (await run.fixture.request({ op: 'sample' })).receipt })
    } finally {
      await session.send('Page.setWebLifecycleState', { state: 'active' })
      await session.detach()
    }
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible({ timeout: 35_000 })
    await expect(page.getByRole('status').filter({ hasText: 'Updates unavailable' })).toHaveCount(0, { timeout: 35_000 })
    const authoritative = await authenticatedGet<{ data: { name: string } }>(page,
      `/api/playlists/${run.manifest.playlist.publicId}`)
    expect(authoritative.data.name).toBe(name)
    const frame = await paint(page)
    expect(frame.visible).toBe(true)
    run.evidence.push({ kind: 'browser-wake', frozenAt, recoveredAt: Date.now(), frame })
  })

  test('an independent console producer updates warmed admin job and album reads', async ({ run }) => {
    const page = await run.open(-1)
    await navigate(page, '/admin?tab=jobs')
    const job = page.getByText('Live qualification', { exact: true }).locator('..')
    await expect(job).toBeVisible()
    const reads = observeReads(page, '/api/monitor/jobs')
    await run.fixture.request({ op: 'change', target: 'job', value: 'failed' })
    await expect(job.getByText('failed', { exact: true })).toBeVisible({ timeout: 20_000 })
    expect(reads.some(read => read.status === 200)).toBe(true)
    const album = await authenticatedGet<{ data: { title: string } }>(page,
      `/api/albums/${run.manifest.album.publicId}`)
    await navigate(page, `/music/albums/${run.manifest.album.publicId}`)
    await expect(page.locator('main').getByText(album.data.title, { exact: true }).first()).toBeVisible()
    const value = `Console album ${run.fixture.run}`
    await run.fixture.request({ op: 'change', target: 'album', value })
    await expect(page.locator('main').getByText(value, { exact: true }).first()).toBeVisible({ timeout: 20_000 })
    run.evidence.push({ kind: 'independent-fixture-console-producer', reads, ...await paint(page) })
    await navigate(page, '/admin/library')
    await expect(page.getByText(`Live ${run.fixture.run}`, { exact: true }).first()).toBeVisible()
    const libraryReads = observeReads(page, '/api/libraries')
    const renamed = `CLI library ${run.fixture.run}`
    await run.managed(['exec', '--', 'php', 'bin/console', 'app:library:update',
      run.manifest.library.publicId, `--name=${renamed}`, '--json', '--no-interaction'])
    await expect(page.locator('main').getByText(renamed, { exact: true }).first()).toBeVisible({ timeout: 20_000 })
    run.evidence.push({ kind: 'real-library-console-command', libraryReads, ...await paint(page) })
  })

  for (const lifecycle of ['reload', 'restart'] as const) {
    test(`mounted state recovers after a managed server ${lifecycle}`, async ({ run }) => {
      test.setTimeout(lifecycle === 'restart' ? RESTART_QUALIFICATION_MS : 360_000)
      let observer!: CapacityObserver
      const page = await run.open(1, async candidate => {
        observer = new CapacityObserver(candidate, run.manifest.users[1]!.id)
      })
      const admin = await run.open(-1)
      await expect.poll(() => observer.ready).toBe(true)
      await navigate(page, `/music/playlists/${run.manifest.playlist.publicId}`)
      await expect(page.locator('main h1')).toBeVisible()
      const identities = async () => {
        const stats = await authenticatedGet<{ data: { workers: { worker_id: number; process: { pid: number } }[];
          missing_workers: number[]; worker_errors: unknown[] } }>(admin, '/api/debug/stats')
        expect(stats.data.missing_workers).toEqual([])
        expect(stats.data.worker_errors).toEqual([])
        expect(stats.data.workers.length).toBeGreaterThan(0)
        return stats.data.workers.map(worker => ({ id: worker.worker_id, pid: worker.process.pid }))
      }
      const before = await identities()
      const readyBefore = observer.socketFrames.filter(frame => frame.type === 'live.ready').length
      const started = Date.now()
      run.evidence.push({ kind: `managed-${lifecycle}-before`, before, started,
        observer: observer.snapshot() })
      await run.managed(lifecycle === 'restart' ? ['restart'] : [
        'exec', '--', 'curl', '--fail', '--silent', '--show-error', '--request', 'PATCH',
        'http://127.0.0.1:9200/api/server',
      ])
      run.evidence.push({ kind: `managed-${lifecycle}-operation-complete`, epochMs: Date.now(),
        observer: observer.snapshot() })
      await expect.poll(() => observer.socketFrames.filter(frame => frame.type === 'live.ready').length,
        { timeout: 90_000 }).toBeGreaterThan(readyBefore)
      const after = await identities()
      if (lifecycle === 'reload') expect(after).not.toEqual(before)
      const name = `After ${lifecycle} ${run.fixture.run}`
      await run.fixture.request({ op: 'change', target: 'playlist', value: name })
      await expect(page.getByRole('heading', { name, exact: true })).toBeVisible({ timeout: 30_000 })
      run.evidence.push({ kind: `managed-${lifecycle}`, before, after, elapsedMs: Date.now() - started,
        socketFrames: observer.socketFrames, ...await paint(page) })
    })
  }
})

async function mutePlayer(page: import('@playwright/test').Page) {
  await page.evaluate(async () => {
    const modulePath = '/src/features/player/stores/player-store.ts'
    const { usePlayerStore } = await import(/* @vite-ignore */ modulePath) as {
      usePlayerStore: { getState: () => { muted: boolean; toggleMute: () => void } }
    }
    const player = usePlayerStore.getState()
    if (!player.muted) player.toggleMute()
  })
}

async function audioState(page: import('@playwright/test').Page) {
  return page.evaluate(async () => {
    const modulePath = '/src/features/player/stores/player-store.ts'
    const { usePlayerStore } = await import(/* @vite-ignore */ modulePath) as {
      usePlayerStore: { getState: () => { audioElement: HTMLAudioElement | null;
        currentTrack: { publicId: string } | null; queue: { publicId: string }[]; currentIndex: number; muted: boolean } }
    }
    const player = usePlayerStore.getState()
    const element = player.audioElement
    const recorded = window as unknown as { __qualificationAudio?: HTMLAudioElement }
    recorded.__qualificationAudio ??= element ?? undefined
    return { src: element?.src, time: element?.currentTime ?? 0, paused: element?.paused,
      muted: player.muted, nativeMuted: element?.muted, track: player.currentTrack?.publicId, index: player.currentIndex,
      queue: player.queue.map(track => track.publicId), sameElement: element === recorded.__qualificationAudio }
  })
}
